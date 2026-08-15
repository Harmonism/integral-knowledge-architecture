<?php
/**
 * One-shot backfill: seed timezone on existing Telegram users who completed the
 * Harmonic Profile Assessment before the timezone-capture wiring (Decision #602).
 *
 * For each user with an empty timezone who has an `assessment_results` profile
 * note, extract the 2-letter country code stored in the note body and map it to
 * an IANA default via the same country→IANA table used by /timezone. When the
 * user's Telegram account is linked to a website member account, propagate the
 * seeded timezone to the website's clients.timezone as well.
 *
 * Safety:
 *   - Only writes when the current timezone is empty — never overwrites.
 *   - Skips multi-zone countries (US/CA/RU/AU/BR/…) the map deliberately omits.
 *   - Validates every candidate through DateTimeZone() before writing.
 *
 * Run once from the server:
 *   php backfill-timezones.php
 *
 * Preview without writing:
 *   php backfill-timezones.php --dry-run
 */

set_time_limit(300);
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/companion-errors.log');

require_once __DIR__ . '/telegram-memory.php';

$configPath = '/home/SERVER_USER/.telegram-config.php';
if (!file_exists($configPath)) {
    echo "Config not found at {$configPath}\n";
    exit(1);
}
require_once $configPath;

$dryRun = in_array('--dry-run', $argv ?? []);
$verbose = in_array('--verbose', $argv ?? []) || in_array('-v', $argv ?? []);

$dbPath = __DIR__ . '/companion-data.db';
$memory = new CompanionMemory($dbPath);
$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Website DB (optional — only needed when a Telegram user is linked).
$webDbPath = realpath(__DIR__ . '/../website-companion-data.db') ?: null;
$webDb = null;
if ($webDbPath && file_exists($webDbPath)) {
    try {
        $webDb = new PDO('sqlite:' . $webDbPath);
        $webDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        try { $webDb->exec('ALTER TABLE clients ADD COLUMN timezone TEXT DEFAULT ""'); } catch (PDOException $e) {}
    } catch (\Exception $e) {
        echo "warn: could not open website DB at {$webDbPath}: " . $e->getMessage() . "\n";
        $webDb = null;
    }
} else {
    echo "warn: website DB not found at " . __DIR__ . "/../website-companion-data.db (propagation skipped)\n";
}

/**
 * ISO-3166 alpha-2 → IANA default. Mirrors countryCodeToDefaultTimezone() in
 * telegram-bot.php. Duplicated here to keep this script self-contained — including
 * the bot file would trigger its webhook top-level code path.
 *
 * Multi-timezone countries (US, CA, RU, AU, BR, KI, FM) are deliberately absent:
 * guessing would be wrong in half the cases. Those users run /timezone.
 */
$countryToIana = [
    // North Africa / Middle East
    'MA' => 'Africa/Casablanca', 'DZ' => 'Africa/Algiers', 'TN' => 'Africa/Tunis',
    'LY' => 'Africa/Tripoli', 'EG' => 'Africa/Cairo', 'SD' => 'Africa/Khartoum',
    'IL' => 'Asia/Jerusalem', 'PS' => 'Asia/Hebron', 'JO' => 'Asia/Amman',
    'LB' => 'Asia/Beirut', 'SY' => 'Asia/Damascus', 'IQ' => 'Asia/Baghdad',
    'IR' => 'Asia/Tehran', 'SA' => 'Asia/Riyadh', 'AE' => 'Asia/Dubai',
    'QA' => 'Asia/Qatar', 'KW' => 'Asia/Kuwait', 'BH' => 'Asia/Bahrain',
    'OM' => 'Asia/Muscat', 'YE' => 'Asia/Aden', 'TR' => 'Europe/Istanbul',
    // Sub-Saharan Africa
    'NG' => 'Africa/Lagos', 'KE' => 'Africa/Nairobi', 'ZA' => 'Africa/Johannesburg',
    'ET' => 'Africa/Addis_Ababa', 'TZ' => 'Africa/Dar_es_Salaam', 'UG' => 'Africa/Kampala',
    'GH' => 'Africa/Accra', 'CI' => 'Africa/Abidjan', 'SN' => 'Africa/Dakar',
    'MR' => 'Africa/Nouakchott', 'ML' => 'Africa/Bamako', 'BF' => 'Africa/Ouagadougou',
    'CM' => 'Africa/Douala', 'RW' => 'Africa/Kigali', 'ZW' => 'Africa/Harare',
    'ZM' => 'Africa/Lusaka', 'MZ' => 'Africa/Maputo', 'MG' => 'Indian/Antananarivo',
    // Europe
    'GB' => 'Europe/London', 'IE' => 'Europe/Dublin', 'FR' => 'Europe/Paris',
    'DE' => 'Europe/Berlin', 'ES' => 'Europe/Madrid', 'PT' => 'Europe/Lisbon',
    'IT' => 'Europe/Rome', 'NL' => 'Europe/Amsterdam', 'BE' => 'Europe/Brussels',
    'CH' => 'Europe/Zurich', 'AT' => 'Europe/Vienna', 'LU' => 'Europe/Luxembourg',
    'MC' => 'Europe/Monaco', 'PL' => 'Europe/Warsaw', 'CZ' => 'Europe/Prague',
    'SK' => 'Europe/Bratislava', 'HU' => 'Europe/Budapest', 'RO' => 'Europe/Bucharest',
    'BG' => 'Europe/Sofia', 'HR' => 'Europe/Zagreb', 'SI' => 'Europe/Ljubljana',
    'RS' => 'Europe/Belgrade', 'BA' => 'Europe/Sarajevo', 'MK' => 'Europe/Skopje',
    'AL' => 'Europe/Tirane', 'ME' => 'Europe/Podgorica', 'GR' => 'Europe/Athens',
    'CY' => 'Asia/Nicosia', 'MT' => 'Europe/Malta', 'SE' => 'Europe/Stockholm',
    'NO' => 'Europe/Oslo', 'DK' => 'Europe/Copenhagen', 'FI' => 'Europe/Helsinki',
    'IS' => 'Atlantic/Reykjavik', 'EE' => 'Europe/Tallinn', 'LV' => 'Europe/Riga',
    'LT' => 'Europe/Vilnius', 'UA' => 'Europe/Kyiv', 'BY' => 'Europe/Minsk',
    'MD' => 'Europe/Chisinau',
    // Asia
    'CN' => 'Asia/Shanghai', 'JP' => 'Asia/Tokyo', 'KR' => 'Asia/Seoul',
    'KP' => 'Asia/Pyongyang', 'TW' => 'Asia/Taipei', 'HK' => 'Asia/Hong_Kong',
    'MO' => 'Asia/Macau', 'IN' => 'Asia/Kolkata', 'PK' => 'Asia/Karachi',
    'BD' => 'Asia/Dhaka', 'NP' => 'Asia/Kathmandu', 'LK' => 'Asia/Colombo',
    'MV' => 'Indian/Maldives', 'BT' => 'Asia/Thimphu', 'MM' => 'Asia/Yangon',
    'TH' => 'Asia/Bangkok', 'VN' => 'Asia/Ho_Chi_Minh', 'KH' => 'Asia/Phnom_Penh',
    'LA' => 'Asia/Vientiane', 'MY' => 'Asia/Kuala_Lumpur', 'SG' => 'Asia/Singapore',
    'BN' => 'Asia/Brunei', 'PH' => 'Asia/Manila', 'ID' => 'Asia/Jakarta',
    'TL' => 'Asia/Dili', 'AF' => 'Asia/Kabul', 'AM' => 'Asia/Yerevan',
    'AZ' => 'Asia/Baku', 'GE' => 'Asia/Tbilisi', 'KZ' => 'Asia/Almaty',
    'UZ' => 'Asia/Tashkent', 'TM' => 'Asia/Ashgabat', 'KG' => 'Asia/Bishkek',
    'TJ' => 'Asia/Dushanbe', 'MN' => 'Asia/Ulaanbaatar',
    // Latin America
    'MX' => 'America/Mexico_City', 'GT' => 'America/Guatemala', 'BZ' => 'America/Belize',
    'SV' => 'America/El_Salvador', 'HN' => 'America/Tegucigalpa', 'NI' => 'America/Managua',
    'CR' => 'America/Costa_Rica', 'PA' => 'America/Panama', 'CU' => 'America/Havana',
    'DO' => 'America/Santo_Domingo', 'HT' => 'America/Port-au-Prince', 'JM' => 'America/Jamaica',
    'PR' => 'America/Puerto_Rico', 'CO' => 'America/Bogota', 'VE' => 'America/Caracas',
    'EC' => 'America/Guayaquil', 'PE' => 'America/Lima', 'BO' => 'America/La_Paz',
    'PY' => 'America/Asuncion', 'UY' => 'America/Montevideo',
    'AR' => 'America/Argentina/Buenos_Aires', 'CL' => 'America/Santiago',
    'GY' => 'America/Guyana', 'SR' => 'America/Paramaribo',
    // Oceania
    'NZ' => 'Pacific/Auckland', 'FJ' => 'Pacific/Fiji', 'PG' => 'Pacific/Port_Moresby',
];

// ---------- 1. Find users with empty timezone ----------

$stmt = $db->prepare("
    SELECT telegram_id, first_name, username, message_count
    FROM users
    WHERE timezone IS NULL OR timezone = ''
    ORDER BY telegram_id ASC
");
$stmt->execute();
$candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

$mode = $dryRun ? 'DRY RUN — no writes' : 'LIVE — writing to DB';
echo "\n==== Timezone backfill ({$mode}) ====\n";
echo "candidates (empty timezone): " . count($candidates) . "\n\n";

$counts = [
    'seeded_telegram' => 0,
    'propagated_website' => 0,
    'skipped_no_assessment' => 0,
    'skipped_no_country_in_note' => 0,
    'skipped_multi_zone_country' => 0,
    'skipped_invalid_tz' => 0,
    'skipped_website_not_linked' => 0,
    'skipped_website_already_set' => 0,
];

foreach ($candidates as $row) {
    $tid = (int) $row['telegram_id'];
    $name = trim(($row['first_name'] ?? '') . (isset($row['username']) && $row['username'] ? " (@{$row['username']})" : ''));
    $label = $name !== '' ? $name : "tg:{$tid}";

    // 2. Find the assessment_results profile note
    $notes = $memory->getProfileNotes($tid);
    $assessmentNote = null;
    foreach ($notes as $n) {
        if (($n['category'] ?? '') === 'assessment_results') {
            $assessmentNote = $n;
            break;
        }
    }
    if (!$assessmentNote) {
        $counts['skipped_no_assessment']++;
        if ($verbose) echo "  skip  {$label} — no assessment_results note\n";
        continue;
    }

    // 3. Extract country code from "country: XX" in observation text
    $observation = (string) ($assessmentNote['observation'] ?? '');
    if (!preg_match('/country:\s*([A-Z]{2})\b/i', $observation, $m)) {
        $counts['skipped_no_country_in_note']++;
        if ($verbose) echo "  skip  {$label} — no country code in note body\n";
        continue;
    }
    $cc = strtoupper($m[1]);

    // 4. Map to IANA
    if (!isset($countryToIana[$cc])) {
        $counts['skipped_multi_zone_country']++;
        echo "  skip  {$label} — {$cc} is multi-zone (user must run /timezone)\n";
        continue;
    }
    $candidateTz = $countryToIana[$cc];

    // 5. Validate
    try {
        new DateTimeZone($candidateTz);
    } catch (\Exception $e) {
        $counts['skipped_invalid_tz']++;
        echo "  skip  {$label} — invalid IANA '{$candidateTz}' for {$cc}\n";
        continue;
    }

    // 6. Seed Telegram users.timezone
    echo "  seed  {$label} — {$cc} → {$candidateTz}";
    if (!$dryRun) {
        $memory->setTimezone($tid, $candidateTz);
    }
    $counts['seeded_telegram']++;

    // 7. Propagate to website if linked
    $linkedEmail = $memory->isAccountLinked($tid) ? $memory->getLinkedEmail($tid) : null;
    if (!$linkedEmail) {
        $counts['skipped_website_not_linked']++;
        echo "\n";
        continue;
    }
    $linkedEmail = strtolower(trim($linkedEmail));
    if ($webDb === null) {
        echo " | linked but no website DB\n";
        continue;
    }
    try {
        $wStmt = $webDb->prepare('SELECT client_id, COALESCE(timezone, "") AS tz FROM clients WHERE email = ? ORDER BY message_count DESC LIMIT 1');
        $wStmt->execute([$linkedEmail]);
        $wRow = $wStmt->fetch(PDO::FETCH_ASSOC);
        if (!$wRow) {
            echo " | linked={$linkedEmail}, no website client row yet (skipped)\n";
            continue;
        }
        if ($wRow['tz'] !== '') {
            $counts['skipped_website_already_set']++;
            echo " | website already set ({$wRow['tz']})\n";
            continue;
        }
        if (!$dryRun) {
            $webDb->prepare('UPDATE clients SET timezone = ? WHERE client_id = ?')
                ->execute([$candidateTz, $wRow['client_id']]);
        }
        $counts['propagated_website']++;
        echo " | + website client_id={$wRow['client_id']}\n";
    } catch (\Throwable $e) {
        echo " | website propagation failed: " . $e->getMessage() . "\n";
    }
}

echo "\n==== Summary ====\n";
foreach ($counts as $k => $v) {
    printf("  %-30s %d\n", $k, $v);
}
echo "\n" . ($dryRun ? "Dry run complete. Re-run without --dry-run to apply." : "Backfill complete.") . "\n";
