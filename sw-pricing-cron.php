<?php
/**
 * Synergy Wholesale Auto Pricing Sync — CE Cron Script
 *
 * Reads Auto Pricing Sync settings from the CE registrar plugin,
 * calls the Synergy Wholesale SOAP API, and updates package pricing
 * in the CE database for all matching domain products.
 *
 * When "Apply SW Specials" is enabled the script runs daily regardless
 * of the configured sync interval; it applies active sale prices where
 * available and falls back to standard prices for TLDs with no live sale.
 * Once a sale window ends the next daily run automatically restores the
 * standard price, so specials never linger past their expiry date.
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
 *   - Apply SW Specials:      Yes / No
 *
 * @version 1.0.1
 * @author  Jamison Labs <hello@jamisonlabs.com>
 * @link    https://github.com/jamisonlabs/ce-synergywholesale-registrar
 */

define('CE_ROOT', __DIR__);
require_once CE_ROOT . '/config.php';

// ---------------------------------------------------------------------------
// Logging helpers
// ---------------------------------------------------------------------------

$logLines = [];   // collected for last-status storage

$log = function (string $level, string $msg) use (&$logLines): void {
    $line = '[' . date('Y-m-d H:i:s') . '] [' . $level . '] ' . $msg;
    echo $line . PHP_EOL;
    $logLines[] = $line;
};

$info  = fn(string $m) => $log('INFO',  $m);
$warn  = fn(string $m) => $log('WARN',  $m);
$error = fn(string $m) => $log('ERROR', $m);
$sale  = fn(string $m) => $log('SALE',  $m);

$info('SW Pricing Sync starting');

// ---------------------------------------------------------------------------
// Database connection
// ---------------------------------------------------------------------------

$db = new mysqli($hostname, $dbuser, $dbpass, $database);
if ($db->connect_error) {
    $error('DB connect failed: ' . $db->connect_error);
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

$saveSetting = function (string $name, string $value) use ($db): void {
    $stmt = $db->prepare('INSERT INTO setting (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?');
    $stmt->bind_param('sss', $name, $value, $value);
    $stmt->execute();
    $stmt->close();
};

$enabled      = $setting('plugin_synergywholesale_Auto Pricing Sync');
$interval     = max(1, (int) ($setting('plugin_synergywholesale_Pricing Sync Interval') ?: 1));
$period       = strtolower(trim((string) ($setting('plugin_synergywholesale_Pricing Sync Period') ?: 'month')));
$margin       = (float) ($setting('plugin_synergywholesale_Pricing Margin') ?: 10);
$resellerID   = (int) $setting('plugin_synergywholesale_Reseller ID');
$apiKey       = (string) $setting('plugin_synergywholesale_API Key');
$lastRun      = (int) ($setting('plugin_synergywholesale_Pricing Sync Last Run') ?: 0);
$applySpecials = $setting('plugin_synergywholesale_Apply SW Specials') === '1';

// ---------------------------------------------------------------------------
// Check enabled
// ---------------------------------------------------------------------------

if ($enabled !== '1') {
    $info('Auto pricing sync is disabled in CE settings — exiting');
    exit(0);
}

// ---------------------------------------------------------------------------
// Determine whether this run is due
//
// When "Apply SW Specials" is on, the script must run every day so that
// sale prices are applied as soon as a sale starts and removed as soon
// as it ends.  The configured interval is still honoured for the standard
// (non-special) pricing pass — we just don't skip the daily run entirely.
// ---------------------------------------------------------------------------

$periodSeconds = match ($period) {
    'day'   => 86400,
    'week'  => 604800,
    'month' => 2592000,   // 30 days
    'year'  => 31536000,  // 365 days
    default => 2592000,
};

$intervalSeconds  = $interval * $periodSeconds;
$nextRun          = $lastRun + $intervalSeconds;
$now              = time();
$intervalDue      = $now >= $nextRun;
$specialsRunToday = false;   // will be set true if we do a specials-only pass

if (!$intervalDue && !$applySpecials) {
    $lastRunStr = $lastRun ? date('Y-m-d H:i:s', $lastRun) : 'never';
    $nextRunStr = date('Y-m-d H:i:s', $nextRun);
    $info("Not due yet. Last run: {$lastRunStr} | Next run: {$nextRunStr} — exiting");
    exit(0);
}

if ($intervalDue) {
    $info("Full sync due (every {$interval} {$period}(s), margin {$margin}%)" . ($applySpecials ? ' — specials enabled' : '') . ' — running');
} else {
    $info("Specials daily check (full sync not yet due) — running");
    $specialsRunToday = true;
}

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
    $error('SW API exception: ' . $e->getMessage());
    exit(1);
}

if ($response->status !== 'OK') {
    $error('SW API error: ' . $response->status);
    exit(1);
}

$info('SW API responded OK — processing pricing');

// ---------------------------------------------------------------------------
// Update CE package pricing
// ---------------------------------------------------------------------------

$multiplier  = 1 + ($margin / 100);
$updated     = 0;
$noChange    = 0;
$notFound    = 0;
$errors      = 0;
$specialsApplied = 0;

foreach ($response->pricing as $item) {
    $tld     = ltrim((string) $item->extension, '.');
    $swReg   = (float) $item->register;
    $swRenew = !empty($item->renew)    ? (float) $item->renew    : $swReg;
    $swXfer  = !empty($item->transfer) ? (float) $item->transfer : $swReg;

    // ---- Specials (Option B) -----------------------------------------------
    // When Apply SW Specials is on, check for an active sale window.
    // Sale prices replace standard prices for this TLD during the sale period.
    // Once the sale ends the standard prices are used again automatically.
    $usingSale = false;
    if ($applySpecials && !empty($item->sale)) {
        $saleObj = $item->sale;

        // Parse sale window dates (format: YYYY-MM-DD)
        $startStr = !empty($saleObj->start_sale_date) ? (string) $saleObj->start_sale_date : '';
        $endStr   = !empty($saleObj->end_sale_date)   ? (string) $saleObj->end_sale_date   : '';

        if ($startStr && $endStr) {
            $startTs = strtotime($startStr);
            $endTs   = strtotime($endStr . ' 23:59:59');

            if ($startTs !== false && $endTs !== false && $now >= $startTs && $now <= $endTs) {
                // Prefer explicit sale fields; fall back to standard if absent
                $saleReg   = isset($saleObj->register)   && (float) $saleObj->register   > 0
                             ? (float) $saleObj->register
                             : (isset($saleObj->register_1_year) && (float) $saleObj->register_1_year > 0
                                ? (float) $saleObj->register_1_year
                                : $swReg);
                $saleRenew = isset($saleObj->renew)      && (float) $saleObj->renew       > 0
                             ? (float) $saleObj->renew    : $saleReg;
                $saleXfer  = isset($saleObj->transfer)   && (float) $saleObj->transfer    > 0
                             ? (float) $saleObj->transfer : $saleReg;

                $swReg     = $saleReg;
                $swRenew   = $saleRenew;
                $swXfer    = $saleXfer;
                $usingSale = true;
                $sale("Active sale for .{$tld} ({$startStr} – {$endStr}): reg={$saleReg}, renew={$saleRenew}, xfer={$saleXfer}");
            }
        }
    }

    // When specials-only run and this TLD has no active sale, skip it —
    // we do not want to overwrite prices on TLDs that haven't changed.
    if ($specialsRunToday && !$usingSale) {
        continue;
    }

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
        $warn("Unreadable pricing blob for TLD={$tld} (package id={$row['id']})");
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
        if ($usingSale) {
            $specialsApplied++;
        }
    } else {
        $error("Updating TLD={$tld} (package id={$row['id']}): " . $stmt->error);
        $errors++;
    }
    $stmt->close();
}

// ---------------------------------------------------------------------------
// Record last run timestamp (only update for full interval runs)
// ---------------------------------------------------------------------------

if ($intervalDue) {
    $saveSetting('plugin_synergywholesale_Pricing Sync Last Run', (string) $now);
}

// ---------------------------------------------------------------------------
// Store last sync status in CE settings (queryable from CE admin)
// ---------------------------------------------------------------------------

$runType   = $specialsRunToday ? 'specials-check' : 'full-sync';
$statusMsg = sprintf(
    '%s | %s | Updated: %d | Unchanged: %d | Specials applied: %d | TLD not in CE: %d | Errors: %d',
    $errors > 0 ? 'ERROR' : 'OK',
    date('Y-m-d H:i:s', $now),
    $updated,
    $noChange,
    $specialsApplied,
    $notFound,
    $errors
);

$saveSetting('plugin_synergywholesale_Pricing Sync Last Status', $statusMsg);

$summary = "Done ({$runType}). Updated: {$updated} | Unchanged: {$noChange} | Specials applied: {$specialsApplied} | TLD not in CE: {$notFound} | Errors: {$errors}";
if ($errors > 0) {
    $error($summary);
} else {
    $info($summary);
}

$db->close();
