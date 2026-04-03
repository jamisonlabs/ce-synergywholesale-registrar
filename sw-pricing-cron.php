<?php
/**
 * Synergy Wholesale Auto Pricing Sync — CE Cron Script
 *
 * Reads Auto Pricing Sync settings from the CE registrar plugin,
 * calls the Synergy Wholesale SOAP API, and updates package pricing
 * in the CE database for all matching domain products.
 *
 * Install:  Copy to the CE root directory alongside config.php.
 * Cron:     0 16 * * * /usr/local/bin/ea-php84 /home/clubhost/public_html/clientexec/sw-pricing-cron.php >> /var/log/sw-pricing-sync.log 2>&1
 *           (16:00 UTC = 02:00 AEST / 03:00 AEDT — runs daily, script checks interval)
 *
 * Settings are controlled from CE Admin:
 *   Settings → Plugins → Registrars → Synergy Wholesale
 *   - Auto Pricing Sync:      Yes / No
 *   - Pricing Sync Interval:  Number (e.g. 1)
 *   - Pricing Sync Period:    day / week / month / year
 *   - Pricing Margin:         Percentage markup over SW cost price
 *
 * @version 1.0.0
 * @author  Jamison Labs <hello@jamisonlabs.com>
 * @link    https://github.com/jamisonlabs/ce-synergywholesale-registrar
 */

define('CE_ROOT', __DIR__);
require_once CE_ROOT . '/config.php';

// ---------------------------------------------------------------------------
// Logging
// ---------------------------------------------------------------------------

$log = function (string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
};

$log('SW Pricing Sync starting');

// ---------------------------------------------------------------------------
// Database connection
// ---------------------------------------------------------------------------

$db = new mysqli($hostname, $dbuser, $dbpass, $database);
if ($db->connect_error) {
    $log('DB connect failed: ' . $db->connect_error);
    exit(1);
}
$db->set_charset('utf8mb4');

// ---------------------------------------------------------------------------
// Read plugin settings
// ---------------------------------------------------------------------------

$setting = function (string $name) use ($db): ?string {
    $stmt = $db->prepare('SELECT value FROM setting WHERE name = ? LIMIT 1');
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? $row['value'] : null;
};

$enabled    = $setting('plugin_synergywholesale_Auto Pricing Sync');
$interval   = max(1, (int) ($setting('plugin_synergywholesale_Pricing Sync Interval') ?: 1));
$period     = strtolower(trim((string) ($setting('plugin_synergywholesale_Pricing Sync Period') ?: 'month')));
$margin     = (float) ($setting('plugin_synergywholesale_Pricing Margin') ?: 10);
$resellerID = (int) $setting('plugin_synergywholesale_Reseller ID');
$apiKey     = (string) $setting('plugin_synergywholesale_API Key');
$lastRun    = (int) ($setting('plugin_synergywholesale_Pricing Sync Last Run') ?: 0);

// ---------------------------------------------------------------------------
// Check enabled
// ---------------------------------------------------------------------------

if ($enabled !== '1') {
    $log('Auto pricing sync is disabled in CE settings — exiting');
    exit(0);
}

// ---------------------------------------------------------------------------
// Calculate interval in seconds and check if due
// ---------------------------------------------------------------------------

$periodSeconds = match ($period) {
    'day'   => 86400,
    'week'  => 604800,
    'month' => 2592000,   // 30 days
    'year'  => 31536000,  // 365 days
    default => 2592000,
};

$intervalSeconds = $interval * $periodSeconds;
$nextRun         = $lastRun + $intervalSeconds;
$now             = time();

if ($now < $nextRun) {
    $lastRunStr = $lastRun ? date('Y-m-d H:i:s', $lastRun) : 'never';
    $nextRunStr = date('Y-m-d H:i:s', $nextRun);
    $log("Not due yet. Last run: {$lastRunStr} | Next run: {$nextRunStr} — exiting");
    exit(0);
}

$log("Sync due (every {$interval} {$period}(s), margin {$margin}%) — running");

// ---------------------------------------------------------------------------
// Call Synergy Wholesale API
// ---------------------------------------------------------------------------

try {
    $client   = new SoapClient('https://api.synergywholesale.com/?wsdl', ['exceptions' => true]);
    $response = $client->getDomainPricing([
        'resellerID' => $resellerID,
        'apiKey'     => $apiKey,
    ]);
} catch (Exception $e) {
    $log('SW API exception: ' . $e->getMessage());
    exit(1);
}

if ($response->status !== 'OK') {
    $log('SW API error: ' . $response->status);
    exit(1);
}

$log('SW API responded OK — processing pricing');

// ---------------------------------------------------------------------------
// Update CE package pricing
// ---------------------------------------------------------------------------

$multiplier = 1 + ($margin / 100);
$updated    = 0;
$noChange   = 0;
$notFound   = 0;
$errors     = 0;

foreach ($response->pricing as $item) {
    $tld     = ltrim((string) $item->extension, '.');
    $swReg   = (float) $item->register;
    $swRenew = !empty($item->renew)    ? (float) $item->renew    : $swReg;
    $swXfer  = !empty($item->transfer) ? (float) $item->transfer : $swReg;

    // Find the CE domain product for this TLD
    $stmt = $db->prepare('SELECT id, pricing FROM package WHERE planid = 2 AND planname = ? LIMIT 1');
    $stmt->bind_param('s', $tld);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        $notFound++;
        continue;
    }

    $pricing = @unserialize($row['pricing']);
    if (!is_array($pricing) || !isset($pricing['pricedata'])) {
        $log("WARN: Unreadable pricing blob for TLD={$tld} (package id={$row['id']})");
        $errors++;
        continue;
    }

    $changed = false;

    foreach ($pricing['pricedata'] as &$cycles) {
        foreach ($cycles as $periodId => &$cycle) {
            if (!is_array($cycle) || !isset($cycle['period'])) {
                continue;
            }
            $months = (int) $cycle['period'];
            if ($months <= 0) {
                continue;
            }
            $years = $months / 12;

            $newReg   = number_format($swReg   * $multiplier * $years, 2, '.', '');
            $newRenew = number_format($swRenew * $multiplier * $years, 2, '.', '');
            $newXfer  = number_format($swXfer  * $multiplier * $years, 2, '.', '');

            if ($cycle['price'] !== $newReg || $cycle['renew'] !== $newRenew || $cycle['transfer'] !== $newXfer) {
                $cycle['price']    = $newReg;
                $cycle['renew']    = $newRenew;
                $cycle['transfer'] = $newXfer;
                $changed           = true;
            }
        }
        unset($cycle);
    }
    unset($cycles);

    if (!$changed) {
        $noChange++;
        continue;
    }

    $newPricing = serialize($pricing);
    $stmt = $db->prepare('UPDATE package SET pricing = ? WHERE id = ?');
    $stmt->bind_param('si', $newPricing, $row['id']);
    if ($stmt->execute()) {
        $updated++;
    } else {
        $log("ERROR updating TLD={$tld} (package id={$row['id']}): " . $stmt->error);
        $errors++;
    }
    $stmt->close();
}

// ---------------------------------------------------------------------------
// Record last run timestamp
// ---------------------------------------------------------------------------

$ts   = (string) $now;
$name = 'plugin_synergywholesale_Pricing Sync Last Run';
$stmt = $db->prepare('INSERT INTO setting (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?');
$stmt->bind_param('sss', $name, $ts, $ts);
$stmt->execute();
$stmt->close();

$log("Done. Updated: {$updated} | Unchanged: {$noChange} | TLD not in CE: {$notFound} | Errors: {$errors}");
$db->close();
