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
 *           (16:00 UTC = 02:00 AEST / 03:00 AEDT — runs daily, script checks day-of-month)
 *
 * Settings are controlled from CE Admin:
 *   Settings → Plugins → Registrars → Synergy Wholesale
 *   - Auto Pricing Sync:  Yes / No
 *   - Pricing Sync Day:   Day of month (1–28)
 *   - Pricing Margin:     Percentage markup over SW cost price
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
$syncDay    = (int) ($setting('plugin_synergywholesale_Pricing Sync Day') ?: 1);
$margin     = (float) ($setting('plugin_synergywholesale_Pricing Margin') ?: 10);
$resellerID = (int) $setting('plugin_synergywholesale_Reseller ID');
$apiKey     = (string) $setting('plugin_synergywholesale_API Key');

// ---------------------------------------------------------------------------
// Check enabled + day-of-month
// ---------------------------------------------------------------------------

if ($enabled !== '1') {
    $log('Auto pricing sync is disabled in CE settings — exiting');
    exit(0);
}

$today = (int) date('j');
if ($today !== $syncDay) {
    $log("Not sync day (today={$today}, configured={$syncDay}) — exiting");
    exit(0);
}

$log("Sync day matched (day {$syncDay}), running with {$margin}% margin");

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
                $changed = true;
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

$log("Done. Updated: {$updated} | Unchanged: {$noChange} | TLD not in CE: {$notFound} | Errors: {$errors}");
$db->close();
