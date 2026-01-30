<?php
/**
 * Weather Dashboard
 *
 * Web dashboard showing current weather data and 24h temperature chart.
 * Tab 2: WMO Icon Overview
 *
 * Modified: 2026-01-28 - Initial creation
 * Modified: 2026-01-29 - Added indoor humidity, pressure, dewpoint display
 * Modified: 2026-01-29 - Added weather icons and German condition names
 * Modified: 2026-01-29 - Added icon mappings for WMO 4, 10, 11, 68, 69
 * Modified: 2026-01-30 - Fixed WMO 55 icon mapping (was freezing, should be normal drizzle)
 * Modified: 2026-01-30 - Added WMO Icon Overview tab with wi-class mappings
 * Modified: 2026-01-30 - Added Feedback tab (mobile-optimized) and Analyse tab
 */

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/config.php';

/**
 * Get current weather data
 */
function getCurrentData($pdo) {
    $sql = "SELECT * FROM weather_readings ORDER BY timestamp DESC LIMIT 1";
    $stmt = $pdo->query($sql);
    return $stmt->fetch();
}

/**
 * Get 24h history for chart
 */
function getHistoryData($pdo) {
    $sql = "SELECT timestamp, temp_c, temp1_c, temp2_c
            FROM weather_readings
            WHERE timestamp > NOW() - INTERVAL '24 hours'
            ORDER BY timestamp ASC";
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll();
}

/**
 * Get database stats (count and size)
 */
function getDbStats($pdo) {
    // Row count
    $sql = "SELECT COUNT(*) as count FROM weather_readings";
    $stmt = $pdo->query($sql);
    $row = $stmt->fetch();
    $count = intval($row['count']);

    // Database size in MB
    $sql = "SELECT pg_database_size('weather') as size_bytes";
    $stmt = $pdo->query($sql);
    $row = $stmt->fetch();
    $sizeMb = round(intval($row['size_bytes']) / 1024 / 1024, 1);

    return ['count' => $count, 'size_mb' => $sizeMb];
}

/**
 * Format timestamp to German format
 */
function formatTimestamp($timestamp) {
    $dt = new DateTime($timestamp);
    return $dt->format('d.n.Y, H:i');
}

/**
 * Format number with German notation (comma as decimal, dot as thousands)
 */
function formatDE($value, $decimals = 1) {
    return number_format($value, $decimals, ',', '.');
}

/**
 * Complete WMO mapping with wi-class and PNG filenames
 * Format: [wmo_code => [name_en, name_de, wi_day, wi_night, png_day, png_night, derivable]]
 */
$wmoMapping = [
    // Derivable from sensors (used)
    0  => ['Clear Sky', 'Klar', 'wi-day-sunny', 'wi-night-clear', 'wsymbol_0001_sunny.png', 'wsymbol_0008_clear_sky_night.png', true],
    1  => ['Mainly Clear', 'Überwiegend klar', 'wi-day-sunny-overcast', 'wi-night-partly-cloudy', 'wsymbol_0002_sunny_intervals.png', 'wsymbol_0041_partly_cloudy_night.png', true],
    2  => ['Partly Cloudy', 'Teilweise bewölkt', 'wi-day-cloudy', 'wi-night-cloudy', 'wsymbol_0043_mostly_cloudy.png', 'wsymbol_0042_cloudy_night.png', true],
    3  => ['Overcast', 'Bedeckt', 'wi-day-cloudy-high', 'wi-night-cloudy-high', 'wsymbol_0003_white_cloud.png', 'wsymbol_0042_cloudy_night.png', true],
    4  => ['Haze', 'Dunst', 'wi-day-haze', 'wi-mist-night', 'wsymbol_0005_hazy_sun.png', 'wsymbol_0063_mist_night.png', true],
    10 => ['Mist', 'Feuchter Dunst', 'wi-mist', 'wi-mist-night', 'wsymbol_0006_mist.png', 'wsymbol_0063_mist_night.png', true],
    11 => ['Shallow Fog', 'Flacher Bodennebel', 'wi-fog', 'wi-fog-night', 'wsymbol_0007_fog.png', 'wsymbol_0064_fog_night.png', true],
    45 => ['Fog', 'Nebel', 'wi-day-fog', 'wi-night-fog', 'wsymbol_0007_fog.png', 'wsymbol_0064_fog_night.png', true],
    48 => ['Depositing Rime Fog', 'Reifnebel', 'wi-freezing-fog', 'wi-freezing-fog-night', 'wsymbol_0047_freezing_fog.png', 'wsymbol_0065_freezing_fog_night.png', true],
    51 => ['Light Drizzle', 'Leichter Nieselregen', 'wi-drizzle', 'wi-drizzle-night', 'wsymbol_0048_drizzle.png', 'wsymbol_0066_drizzle_night.png', true],
    53 => ['Moderate Drizzle', 'Nieselregen', 'wi-heavy-drizzle', 'wi-heavy-drizzle-night', 'wsymbol_0081_heavy_drizzle.png', 'wsymbol_0082_heavy_drizzle_night.png', true],
    55 => ['Dense Drizzle', 'Starker Nieselregen', 'wi-heavy-drizzle', 'wi-heavy-drizzle-night', 'wsymbol_0081_heavy_drizzle.png', 'wsymbol_0082_heavy_drizzle_night.png', false], // Not actively used (same icon as 53)
    56 => ['Light Freezing Drizzle', 'Gefrierender Niesel', 'wi-freezing-drizzle', 'wi-freezing-drizzle-night', 'wsymbol_0049_freezing_drizzle.png', 'wsymbol_0067_freezing_drizzle_night.png', true],
    57 => ['Dense Freezing Drizzle', 'Starker gefr. Niesel', 'wi-heavy-freezing-drizzle', 'wi-heavy-freezing-drizzle-night', 'wsymbol_0083_heavy_freezing_drizzle.png', 'wsymbol_0084_heavy_freezing_drizzle_night.png', true],
    61 => ['Slight Rain', 'Leichter Regen', 'wi-day-rain-mix', 'wi-night-rain-mix', 'wsymbol_0009_light_rain_showers.png', 'wsymbol_0025_light_rain_showers_night.png', true],
    63 => ['Moderate Rain', 'Regen', 'wi-day-rain', 'wi-night-rain', 'wsymbol_0010_heavy_rain_showers.png', 'wsymbol_0026_heavy_rain_showers_night.png', true],
    65 => ['Heavy Rain', 'Starkregen', 'wi-extreme-rain', 'wi-extreme-rain-night', 'wsymbol_0051_extreme_rain.png', 'wsymbol_0069_extreme_rain_night.png', true],
    66 => ['Light Freezing Rain', 'Gefrierender Regen', 'wi-freezing-rain', 'wi-freezing-rain-night', 'wsymbol_0050_freezing_rain.png', 'wsymbol_0068_freezing_rain_night.png', true],
    67 => ['Heavy Freezing Rain', 'Starker gefr. Regen', 'wi-freezing-rain', 'wi-freezing-rain-night', 'wsymbol_0050_freezing_rain.png', 'wsymbol_0068_freezing_rain_night.png', true],
    68 => ['Slight Sleet', 'Leichter Schneeregen', 'wi-day-sleet', 'wi-night-sleet', 'wsymbol_0013_sleet_showers.png', 'wsymbol_0029_sleet_showers_night.png', true],
    69 => ['Heavy Sleet', 'Schneeregen', 'wi-day-sleet-storm', 'wi-night-sleet-storm', 'wsymbol_0087_heavy_sleet_showers.png', 'wsymbol_0088_heavy_sleet_showers_night.png', true],
    71 => ['Slight Snow', 'Leichter Schneefall', 'wi-day-snow', 'wi-night-snow', 'wsymbol_0011_light_snow_showers.png', 'wsymbol_0027_light_snow_showers_night.png', true],
    73 => ['Moderate Snow', 'Schneefall', 'wi-day-snow', 'wi-night-snow', 'wsymbol_0011_light_snow_showers.png', 'wsymbol_0027_light_snow_showers_night.png', true],
    75 => ['Heavy Snow', 'Starker Schneefall', 'wi-day-snow-wind', 'wi-night-snow-wind', 'wsymbol_0053_blowing_snow.png', 'wsymbol_0028_heavy_snow_showers_night.png', true],
    77 => ['Snow Grains', 'Schneegriesel', 'wi-day-snow', 'wi-night-snow', 'wsymbol_0011_light_snow_showers.png', 'wsymbol_0027_light_snow_showers_night.png', true],
    // Not derivable (no sensor data available)
    80 => ['Slight Rain Showers', 'Leichte Schauer', 'wi-day-showers', 'wi-night-showers', 'wsymbol_0017_cloudy_with_light_rain.png', 'wsymbol_0025_light_rain_showers_night.png', false],
    81 => ['Moderate Rain Showers', 'Regenschauer', 'wi-day-storm-showers', 'wi-night-storm-showers', 'wsymbol_0018_cloudy_with_heavy_rain.png', 'wsymbol_0026_heavy_rain_showers_night.png', false],
    82 => ['Violent Rain Showers', 'Heftige Schauer', 'wi-extreme-rain', 'wi-extreme-rain-night', 'wsymbol_0051_extreme_rain.png', 'wsymbol_0069_extreme_rain_night.png', false],
    85 => ['Slight Snow Showers', 'Leichte Schneeschauer', 'wi-day-snow', 'wi-night-snow', 'wsymbol_0011_light_snow_showers.png', 'wsymbol_0027_light_snow_showers_night.png', false],
    86 => ['Heavy Snow Showers', 'Schneeschauer', 'wi-day-snow-wind', 'wi-night-snow-wind', 'wsymbol_0053_blowing_snow.png', 'wsymbol_0028_heavy_snow_showers_night.png', false],
    95 => ['Thunderstorm', 'Gewitter', 'wi-day-thunderstorm', 'wi-night-thunderstorm', 'wsymbol_0024_thunderstorms.png', 'wsymbol_0032_thundery_showers_night.png', false],
    96 => ['Thunderstorm + Slight Hail', 'Gewitter mit Hagel', 'wi-thunderstorms-with-hail', 'wi-thunderstorms-with-hail-night', 'wsymbol_0059_thunderstorms_with_hail.png', 'wsymbol_0077_thunderstorms_with_hail_night.png', false],
    99 => ['Thunderstorm + Heavy Hail', 'Gewitter mit Hagel', 'wi-thunderstorms-with-hail', 'wi-thunderstorms-with-hail-night', 'wsymbol_0059_thunderstorms_with_hail.png', 'wsymbol_0077_thunderstorms_with_hail_night.png', false],
];

/**
 * Legacy mappings for backward compatibility
 */
$wmoToIcon = [];
foreach ($wmoMapping as $code => $data) {
    $wmoToIcon[$code] = [$data[4], $data[5]];
}

/**
 * Condition string to German translation
 */
$conditionDE = [
    'clear' => 'Klar',
    'mainly_clear' => 'Überwiegend klar',
    'partly_cloudy' => 'Teilweise bewölkt',
    'overcast' => 'Bedeckt',
    'haze' => 'Dunst',
    'mist' => 'Feuchter Dunst',
    'shallow_fog' => 'Flacher Bodennebel',
    'fog' => 'Nebel',
    'depositing_rime_fog' => 'Reifnebel',
    'drizzle_light' => 'Leichter Nieselregen',
    'drizzle_moderate' => 'Nieselregen',
    'drizzle_dense' => 'Starker Nieselregen',
    'freezing_drizzle_light' => 'Gefrierender Niesel',
    'freezing_drizzle_dense' => 'Starker gefr. Niesel',
    'rain_slight' => 'Leichter Regen',
    'rain_moderate' => 'Regen',
    'rain_heavy' => 'Starkregen',
    'freezing_rain_light' => 'Gefrierender Regen',
    'freezing_rain_heavy' => 'Starker gefr. Regen',
    'sleet_light' => 'Leichter Schneeregen',
    'sleet_heavy' => 'Schneeregen',
    'snow_slight' => 'Leichter Schneefall',
    'snow_moderate' => 'Schneefall',
    'snow_heavy' => 'Starker Schneefall',
    'snow_grains' => 'Schneegriesel',
    'rain_showers_slight' => 'Leichte Schauer',
    'rain_showers_moderate' => 'Regenschauer',
    'rain_showers_violent' => 'Heftige Schauer',
    'snow_showers_slight' => 'Leichte Schneeschauer',
    'snow_showers_heavy' => 'Schneeschauer',
];

/**
 * Get weather icon filename for WMO code
 */
function getWeatherIcon($wmoCode, $isDaylight, $wmoToIcon) {
    if (isset($wmoToIcon[$wmoCode])) {
        return $isDaylight ? $wmoToIcon[$wmoCode][0] : $wmoToIcon[$wmoCode][1];
    }
    return 'wsymbol_0999_unknown.png';
}

/**
 * Get German condition name
 */
function getConditionDE($condition, $conditionDE) {
    return $conditionDE[$condition] ?? ucfirst(str_replace('_', ' ', $condition));
}

// Fetch data
$current = getCurrentData($pdo);
$history = getHistoryData($pdo);
$dbStats = getDbStats($pdo);

// Calculate data age
$dataAge = null;
$isOnline = false;
if ($current) {
    $lastUpdate = new DateTime($current['timestamp']);
    $now = new DateTime();
    $dataAge = $now->getTimestamp() - $lastUpdate->getTimestamp();
    $isOnline = $dataAge < 300; // Online if data < 5 min old
}

// Prepare chart data
$chartLabels = [];
$chartOutside = [];
$chartSensor1 = [];
$chartSensor2 = [];
foreach ($history as $row) {
    $dt = new DateTime($row['timestamp']);
    $chartLabels[] = $dt->format('H:i');
    $chartOutside[] = $row['temp_c'];
    $chartSensor1[] = $row['temp1_c'];
    $chartSensor2[] = $row['temp2_c'];
}

// Current tab
$activeTab = $_GET['tab'] ?? 'weather';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Weather Dashboard</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --bg-primary: #1a1a2e;
            --bg-card: #16213e;
            --bg-card-hover: #1f2b47;
            --text-primary: #eee;
            --text-secondary: #aaa;
            --accent: #00bceb;
            --accent-dim: #007a99;
            --success: #4ade80;
            --warning: #fbbf24;
            --danger: #f87171;
            --chart-outside: #ff6384;
            --chart-sensor1: #36a2eb;
            --chart-sensor2: #ffce56;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 2px solid var(--accent);
        }

        .header-left h1 {
            font-size: 1.6rem;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .header-meta {
            font-size: 0.85rem;
            color: var(--text-secondary);
        }

        .header-logo {
            flex-shrink: 0;
        }

        .header-logo img {
            opacity: 0.9;
            transition: opacity 0.2s;
        }

        .header-logo img:hover {
            opacity: 1;
        }

        .header-right {
            text-align: right;
        }

        .header-timestamp {
            font-size: 0.85rem;
            color: var(--text-secondary);
            margin-top: 6px;
        }

        .refresh-info {
            color: var(--text-secondary);
            opacity: 0.7;
        }

        .status {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 8px;
            font-size: 0.9rem;
        }

        .status-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: var(--danger);
        }

        .status-dot.online {
            background: var(--success);
            box-shadow: 0 0 8px var(--success);
        }

        /* Tab Navigation */
        .tab-nav {
            display: flex;
            gap: 4px;
            margin-bottom: 24px;
            border-bottom: 1px solid var(--bg-card);
            padding-bottom: 0;
        }

        .tab-nav a {
            padding: 12px 24px;
            text-decoration: none;
            color: var(--text-secondary);
            background: var(--bg-card);
            border-radius: 8px 8px 0 0;
            transition: all 0.2s;
        }

        .tab-nav a:hover {
            background: var(--bg-card-hover);
            color: var(--text-primary);
        }

        .tab-nav a.active {
            background: var(--accent);
            color: var(--bg-primary);
            font-weight: 600;
        }

        .card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .card {
            background: var(--bg-card);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            transition: background 0.2s;
        }

        .card:hover {
            background: var(--bg-card-hover);
        }

        .card-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 4px;
        }

        .card-value.temp {
            color: var(--chart-outside);
        }

        .card-value.sky {
            color: var(--accent);
        }

        .card-value.sensor1 {
            color: var(--chart-sensor1);
        }

        .card-value.sensor2 {
            color: var(--chart-sensor2);
        }

        .card-label {
            font-size: 0.85rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .section-title {
            font-size: 1rem;
            color: var(--text-secondary);
            margin-bottom: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .chart-container {
            background: var(--bg-card);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
        }

        .chart-container h2 {
            font-size: 1rem;
            color: var(--text-secondary);
            margin-bottom: 16px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .chart-wrapper {
            position: relative;
            height: 300px;
        }

        footer {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            font-size: 0.85rem;
            color: var(--text-secondary);
            padding-top: 16px;
            border-top: 1px solid var(--bg-card);
        }

        .condition-card {
            grid-column: span 2;
        }

        .bool-yes {
            color: var(--success);
        }

        .bool-no {
            color: var(--text-secondary);
        }

        /* Icon Overview Table */
        .icon-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 32px;
            background: var(--bg-card);
            border-radius: 12px;
            overflow: hidden;
        }

        .icon-table th {
            background: var(--accent-dim);
            color: var(--text-primary);
            padding: 12px 16px;
            text-align: left;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
        }

        .icon-table td {
            padding: 12px 16px;
            border-bottom: 1px solid var(--bg-primary);
            vertical-align: top;
        }

        .icon-table tr:last-child td {
            border-bottom: none;
        }

        .icon-table tr:hover td {
            background: var(--bg-card-hover);
        }

        .wmo-code {
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--accent);
        }

        .wmo-name {
            font-size: 0.95rem;
            color: var(--text-primary);
            margin-top: 4px;
        }

        .wi-class {
            font-family: 'Courier New', monospace;
            font-size: 0.95rem;
            color: var(--text-secondary);
            margin-top: 6px;
        }

        .icon-cell {
            text-align: center;
        }

        .icon-cell img {
            width: 64px;
            height: 64px;
        }

        .icon-filename {
            font-family: 'Courier New', monospace;
            font-size: 0.85rem;
            color: var(--text-secondary);
            margin-top: 6px;
            word-break: break-all;
        }

        .not-derivable {
            /* No opacity - icons should be clearly visible */
        }

        .derivable-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.7rem;
            text-transform: uppercase;
            margin-top: 6px;
        }

        .derivable-badge.yes {
            background: var(--success);
            color: var(--bg-primary);
        }

        .derivable-badge.no {
            background: var(--text-secondary);
            color: var(--bg-primary);
        }

        /* Feedback Tab Styles */
        .feedback-container {
            max-width: 500px;
            margin: 0 auto;
            padding: 20px;
        }

        .feedback-current {
            background: var(--bg-card);
            border-radius: 12px;
            padding: 24px;
            text-align: center;
            margin-bottom: 24px;
        }

        .feedback-current img {
            width: 96px;
            height: 96px;
            margin-bottom: 12px;
        }

        .feedback-current .condition-name {
            font-size: 1.4rem;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .feedback-current .wmo-info {
            font-size: 1rem;
            color: var(--text-secondary);
        }

        .feedback-buttons {
            display: flex;
            gap: 16px;
            margin-bottom: 24px;
        }

        .feedback-btn {
            flex: 1;
            padding: 24px;
            border: none;
            border-radius: 12px;
            font-size: 1.4rem;
            font-weight: 700;
            cursor: pointer;
            transition: transform 0.1s, box-shadow 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
        }

        .feedback-btn:active {
            transform: scale(0.98);
        }

        .feedback-btn.correct {
            background: var(--success);
            color: #000;
        }

        .feedback-btn.correct:hover {
            box-shadow: 0 0 20px rgba(74, 222, 128, 0.4);
        }

        .feedback-btn.wrong {
            background: var(--danger);
            color: #fff;
        }

        .feedback-btn.wrong:hover {
            box-shadow: 0 0 20px rgba(248, 113, 113, 0.4);
        }

        .feedback-btn svg {
            width: 32px;
            height: 32px;
        }

        .correction-panel {
            background: var(--bg-card);
            border-radius: 12px;
            padding: 24px;
            display: none;
        }

        .correction-panel.visible {
            display: block;
        }

        .correction-panel h3 {
            font-size: 1rem;
            margin-bottom: 16px;
            color: var(--text-secondary);
        }

        .correction-select {
            width: 100%;
            padding: 16px;
            font-size: 1.1rem;
            border: 2px solid var(--accent-dim);
            border-radius: 8px;
            background: var(--bg-primary);
            color: var(--text-primary);
            margin-bottom: 16px;
            cursor: pointer;
        }

        .correction-select:focus {
            outline: none;
            border-color: var(--accent);
        }

        .correction-comment {
            width: 100%;
            padding: 12px;
            font-size: 1rem;
            border: 1px solid var(--bg-card-hover);
            border-radius: 8px;
            background: var(--bg-primary);
            color: var(--text-primary);
            resize: vertical;
            min-height: 80px;
            margin-bottom: 16px;
        }

        .submit-btn {
            width: 100%;
            padding: 16px;
            font-size: 1.1rem;
            font-weight: 600;
            border: none;
            border-radius: 8px;
            background: var(--accent);
            color: var(--bg-primary);
            cursor: pointer;
            transition: background 0.2s;
        }

        .submit-btn:hover {
            background: var(--accent-dim);
            color: var(--text-primary);
        }

        .submit-btn:disabled {
            background: var(--text-secondary);
            cursor: not-allowed;
        }

        .feedback-message {
            padding: 16px;
            border-radius: 8px;
            margin-top: 16px;
            text-align: center;
            display: none;
        }

        .feedback-message.success {
            display: block;
            background: rgba(74, 222, 128, 0.2);
            color: var(--success);
        }

        .feedback-message.error {
            display: block;
            background: rgba(248, 113, 113, 0.2);
            color: var(--danger);
        }

        /* Analysis Tab Styles */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: var(--bg-card);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--accent);
        }

        .stat-value.good {
            color: var(--success);
        }

        .stat-value.bad {
            color: var(--danger);
        }

        .stat-label {
            font-size: 0.85rem;
            color: var(--text-secondary);
            margin-top: 4px;
        }

        .error-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
            background: var(--bg-card);
            border-radius: 12px;
            overflow: hidden;
        }

        .error-table th {
            background: var(--accent-dim);
            color: var(--text-primary);
            padding: 12px;
            text-align: left;
            font-size: 0.85rem;
        }

        .error-table td {
            padding: 12px;
            border-bottom: 1px solid var(--bg-primary);
            font-size: 0.9rem;
        }

        .error-table tr:last-child td {
            border-bottom: none;
        }

        .recommendation-list {
            background: var(--bg-card);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
        }

        .recommendation-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid var(--bg-primary);
        }

        .recommendation-item:last-child {
            border-bottom: none;
        }

        .recommendation-checkbox {
            width: 20px;
            height: 20px;
            margin-top: 2px;
            cursor: pointer;
        }

        .recommendation-content {
            flex: 1;
        }

        .recommendation-param {
            font-family: 'Courier New', monospace;
            color: var(--accent);
            font-size: 0.95rem;
        }

        .recommendation-values {
            font-size: 0.9rem;
            color: var(--text-secondary);
            margin-top: 4px;
        }

        .recommendation-reason {
            font-size: 0.85rem;
            color: var(--text-secondary);
            margin-top: 4px;
            font-style: italic;
        }

        .apply-btn {
            padding: 16px 32px;
            font-size: 1rem;
            font-weight: 600;
            border: none;
            border-radius: 8px;
            background: var(--success);
            color: #000;
            cursor: pointer;
            transition: background 0.2s;
        }

        .apply-btn:hover {
            background: #22c55e;
        }

        .apply-btn:disabled {
            background: var(--text-secondary);
            cursor: not-allowed;
        }

        .no-data {
            text-align: center;
            padding: 40px;
            color: var(--text-secondary);
        }

        @media (max-width: 600px) {
            body {
                padding: 12px;
            }

            header {
                flex-direction: column;
                gap: 8px;
            }

            .header-left, .header-right {
                text-align: center;
                width: 100%;
            }

            .header-logo {
                order: -1;
                margin-bottom: 8px;
            }

            .header-logo img {
                width: 64px;
                height: auto;
            }

            .header-left h1 {
                font-size: 1.2rem;
            }

            .header-meta {
                font-size: 0.8rem;
            }

            .status {
                justify-content: center;
            }

            .tab-nav {
                flex-wrap: wrap;
            }

            .tab-nav a {
                padding: 10px 12px;
                font-size: 0.85rem;
            }

            .card-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 10px;
            }

            .card {
                padding: 14px 10px;
            }

            .card-value {
                font-size: 1.4rem;
            }

            .card-label {
                font-size: 0.75rem;
            }

            .condition-card {
                grid-column: span 3;
            }

            .chart-wrapper {
                height: 250px;
            }

            footer {
                flex-direction: column;
                text-align: center;
            }

            .icon-table th,
            .icon-table td {
                padding: 8px;
            }

            .icon-cell img {
                width: 48px;
                height: 48px;
            }

            .icon-filename {
                font-size: 0.75rem;
            }

            /* Feedback mobile optimizations */
            .feedback-container {
                padding: 10px;
            }

            .feedback-current {
                padding: 16px;
            }

            .feedback-current img {
                width: 72px;
                height: 72px;
            }

            .feedback-current .condition-name {
                font-size: 1.2rem;
            }

            .feedback-btn {
                padding: 20px 16px;
                font-size: 1.2rem;
            }

            .feedback-btn svg {
                width: 28px;
                height: 28px;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .stat-value {
                font-size: 1.5rem;
            }

            .error-table {
                font-size: 0.8rem;
            }

            .error-table th,
            .error-table td {
                padding: 8px 6px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div class="header-left">
                <h1>Wetter-Cockpit Müllenborn</h1>
                <div class="header-meta">© Dr. Ralf Korell</div>
            </div>
            <div class="header-logo">
                <img src="icons/rk_wolf.png" alt="RK Logo" width="80" height="52">
            </div>
            <div class="header-right">
                <div class="status">
                    <span class="status-dot <?= $isOnline ? 'online' : '' ?>"></span>
                    <span><?= $isOnline ? 'Online' : 'Offline' ?></span>
                </div>
                <div class="header-timestamp">
                    Stand: <?= $current ? (new DateTime($current['timestamp']))->format('d.m.y H:i') : '—' ?>
                    <span class="refresh-info">(Refresh: 60s)</span>
                </div>
            </div>
        </header>

        <!-- Tab Navigation -->
        <nav class="tab-nav">
            <a href="?tab=weather" class="<?= $activeTab === 'weather' ? 'active' : '' ?>">Wetter</a>
            <a href="?tab=feedback" class="<?= $activeTab === 'feedback' ? 'active' : '' ?>">Feedback</a>
            <a href="?tab=analyse" class="<?= $activeTab === 'analyse' ? 'active' : '' ?>">Analyse</a>
            <a href="?tab=icons" class="<?= $activeTab === 'icons' ? 'active' : '' ?>">WMO Icons</a>
        </nav>

        <?php if ($activeTab === 'weather'): ?>
        <!-- ========== WEATHER TAB ========== -->

        <?php if ($current): ?>

        <!-- Temperature Row -->
        <div class="section-title">Temperatur</div>
        <div class="card-grid">
            <div class="card">
                <div class="card-value temp"><?= formatDE($current['temp_c'], 1) ?>°C</div>
                <div class="card-label">Außen</div>
            </div>
            <div class="card">
                <div class="card-value sky"><?= $current['sky_temp_c'] !== null ? formatDE($current['sky_temp_c'], 1) . '°C' : '—' ?></div>
                <div class="card-label">Sky Temp</div>
            </div>
            <div class="card">
                <div class="card-value"><?= $current['delta_c'] !== null ? formatDE($current['delta_c'], 1) . '°C' : '—' ?></div>
                <div class="card-label">Delta</div>
            </div>
        </div>

        <!-- Weather Row -->
        <div class="card-grid">
            <div class="card">
                <div class="card-value"><?= formatDE($current['humidity'], 0) ?>%</div>
                <div class="card-label">Luftfeuchte</div>
            </div>
            <div class="card">
                <div class="card-value"><?= $current['dewpoint_c'] !== null ? formatDE($current['dewpoint_c'], 1) . '°C' : '—' ?></div>
                <div class="card-label">Taupunkt</div>
            </div>
            <div class="card">
                <div class="card-value"><?= $current['pressure_hpa'] !== null ? formatDE($current['pressure_hpa'], 0) . ' hPa' : '—' ?></div>
                <div class="card-label">Luftdruck</div>
            </div>
            <div class="card">
                <div class="card-value"><?= $current['wind_speed_ms'] !== null ? formatDE($current['wind_speed_ms'] * 3.6, 1) . ' km/h' : '—' ?></div>
                <div class="card-label">Wind</div>
            </div>
            <div class="card">
                <div class="card-value"><?= $current['precip_today_mm'] !== null ? formatDE($current['precip_today_mm'], 1) . ' mm' : '—' ?></div>
                <div class="card-label">Niederschlag</div>
            </div>
        </div>

        <!-- Condition with Icon -->
        <?php
            $isDaylight = $current['cw_is_daylight'] === 't' || $current['cw_is_daylight'] === true;
            $iconFile = getWeatherIcon($current['wmo_code'], $isDaylight, $wmoToIcon);
            $conditionText = getConditionDE($current['condition'] ?? '', $conditionDE);
        ?>
        <div class="card-grid">
            <div class="card condition-card">
                <table style="margin: 0 auto; border-spacing: 0;">
                    <tr>
                        <td style="vertical-align: middle;">
                            <img src="icons/<?= $iconFile ?>" width="128" height="128" alt="<?= $conditionText ?>">
                        </td>
                        <td style="vertical-align: middle; padding-left: 20px; text-align: left;">
                            <div class="card-value"><?= $conditionText ?></div>
                            <div class="card-label">WMO <?= $current['wmo_code'] ?? '—' ?></div>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Sensors -->
        <div class="section-title">Sensoren (Indoor)</div>
        <div class="card-grid">
            <div class="card">
                <div class="card-value sensor1"><?= $current['temp1_c'] !== null ? formatDE($current['temp1_c'], 1) . '°C' : '—' ?></div>
                <div class="card-label"><?= SENSOR1_NAME ?> Temp</div>
            </div>
            <div class="card">
                <div class="card-value sensor1"><?= $current['humidity1'] !== null ? $current['humidity1'] . '%' : '—' ?></div>
                <div class="card-label"><?= SENSOR1_NAME ?> Feuchte</div>
            </div>
            <div class="card">
                <div class="card-value sensor2"><?= $current['temp2_c'] !== null ? formatDE($current['temp2_c'], 1) . '°C' : '—' ?></div>
                <div class="card-label"><?= SENSOR2_NAME ?> Temp</div>
            </div>
            <div class="card">
                <div class="card-value sensor2"><?= $current['humidity2'] !== null ? $current['humidity2'] . '%' : '—' ?></div>
                <div class="card-label"><?= SENSOR2_NAME ?> Feuchte</div>
            </div>
        </div>

        <!-- CloudWatcher -->
        <div class="section-title">CloudWatcher</div>
        <div class="card-grid">
            <div class="card">
                <div class="card-value"><?= $current['rain_freq'] !== null ? $current['rain_freq'] : '—' ?></div>
                <div class="card-label">Rain Freq</div>
            </div>
            <div class="card">
                <div class="card-value"><?= $current['mpsas'] !== null ? formatDE($current['mpsas'], 2) : '—' ?></div>
                <div class="card-label">MPSAS</div>
            </div>
            <div class="card">
                <div class="card-value <?= ($current['cw_is_raining'] === 't' || $current['cw_is_raining'] === true) ? 'bool-yes' : 'bool-no' ?>">
                    <?= ($current['cw_is_raining'] === 't' || $current['cw_is_raining'] === true) ? 'Ja' : 'Nein' ?>
                </div>
                <div class="card-label">Regen?</div>
            </div>
            <div class="card">
                <div class="card-value <?= ($current['cw_is_daylight'] === 't' || $current['cw_is_daylight'] === true) ? 'bool-yes' : 'bool-no' ?>">
                    <?= ($current['cw_is_daylight'] === 't' || $current['cw_is_daylight'] === true) ? 'Tag' : 'Nacht' ?>
                </div>
                <div class="card-label">Tageslicht</div>
            </div>
        </div>

        <!-- Chart: Outside Temperature -->
        <div class="chart-container">
            <h2>Außentemperatur (24h)</h2>
            <div class="chart-wrapper">
                <canvas id="outsideChart"></canvas>
            </div>
        </div>

        <!-- Chart: Indoor Sensors -->
        <div class="chart-container">
            <h2>Innentemperaturen (24h)</h2>
            <div class="chart-wrapper">
                <canvas id="indoorChart"></canvas>
            </div>
        </div>

        <?php else: ?>
        <div class="card">
            <div class="card-value">Keine Daten</div>
            <div class="card-label">Warte auf erste Messung...</div>
        </div>
        <?php endif; ?>

        <?php elseif ($activeTab === 'feedback'): ?>
        <!-- ========== FEEDBACK TAB ========== -->

        <?php if ($current): ?>
        <?php
            $isDaylight = $current['cw_is_daylight'] === 't' || $current['cw_is_daylight'] === true;
            $iconFile = getWeatherIcon($current['wmo_code'], $isDaylight, $wmoToIcon);
            $conditionText = getConditionDE($current['condition'] ?? '', $conditionDE);
            $currentWmo = $current['wmo_code'] ?? 0;
            $hasFeedback = $current['feedback'] !== null;
            $feedbackCorrect = $current['feedback'] === 't' || $current['feedback'] === true;
        ?>

        <div class="feedback-container">
            <!-- Current Condition Display -->
            <div class="feedback-current">
                <img src="icons/<?= $iconFile ?>" alt="<?= $conditionText ?>">
                <div class="condition-name"><?= $conditionText ?></div>
                <div class="wmo-info">WMO <?= $currentWmo ?> &bull; <?= formatDE($current['temp_c'], 1) ?>°C &bull; <?= formatDE($current['humidity'], 0) ?>%</div>
            </div>

            <?php if ($hasFeedback): ?>
            <!-- Already has feedback -->
            <div class="feedback-already" style="background: var(--bg-card); border-radius: 12px; padding: 24px; text-align: center;">
                <div style="font-size: 1.2rem; margin-bottom: 8px;">
                    <?php if ($feedbackCorrect): ?>
                        <span style="color: var(--success);">✓ Bereits als korrekt markiert</span>
                    <?php else: ?>
                        <span style="color: var(--warning);">✓ Korrektur bereits erfasst</span>
                        <?php if ($current['feedback_correct_wmo']): ?>
                            <br><small style="color: var(--text-secondary);">→ WMO <?= $current['feedback_correct_wmo'] ?></small>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <div style="color: var(--text-secondary); font-size: 0.9rem;">
                    Warte auf nächste Messung...
                </div>
            </div>
            <?php else: ?>
            <!-- Feedback Buttons -->
            <div class="feedback-buttons">
                <button type="button" class="feedback-btn correct" onclick="submitCorrect()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                        <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                    OK
                </button>
                <button type="button" class="feedback-btn wrong" onclick="showCorrection()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                    Falsch
                </button>
            </div>

            <!-- Correction Panel (hidden until "Falsch" clicked) -->
            <div id="correctionPanel" class="correction-panel">
                <h3>Was ist der richtige Zustand?</h3>
                <select id="correctWmo" class="correction-select">
                    <option value="">-- Bitte wählen --</option>
                    <!-- Options populated by JavaScript -->
                </select>
                <textarea id="feedbackComment" class="correction-comment" placeholder="Optionaler Kommentar..."></textarea>
                <button type="button" class="submit-btn" onclick="submitWrong()" id="submitBtn" disabled>
                    Hinzulernen
                </button>
            </div>

            <!-- Feedback Messages (hidden by default) -->
            <div id="feedbackSuccess" class="feedback-message">
                ✓ Feedback gespeichert!
            </div>
            <div id="feedbackError" class="feedback-message">
                ✗ Fehler beim Speichern
            </div>
            <?php endif; ?>
        </div>

        <script>
        // Current WMO code from PHP
        const currentWmo = <?= $currentWmo ?>;
        const hasFeedback = <?= $hasFeedback ? 'true' : 'false' ?>;

        // Only run feedback JS if no feedback yet
        if (!hasFeedback) {
            // Populate dropdown with proximity-sorted WMO codes
            async function loadWmoList() {
                try {
                    const response = await fetch('api.php?action=wmo_list');
                    const data = await response.json();

                    const select = document.getElementById('correctWmo');
                    if (select) {
                        data.wmo_codes.forEach(item => {
                            if (!item.is_current) {
                                const option = document.createElement('option');
                                option.value = item.code;
                                option.textContent = `WMO ${item.code}: ${item.condition.replace(/_/g, ' ')}`;
                                select.appendChild(option);
                            }
                        });
                    }
                } catch (error) {
                    console.error('Error loading WMO list:', error);
                }
            }

            // Enable submit button when WMO selected
            const correctWmoSelect = document.getElementById('correctWmo');
            if (correctWmoSelect) {
                correctWmoSelect.addEventListener('change', function() {
                    document.getElementById('submitBtn').disabled = !this.value;
                });
            }

            // Load WMO list on page load
            loadWmoList();
        }

        function showCorrection() {
            const panel = document.getElementById('correctionPanel');
            if (panel) panel.classList.add('visible');
            hideMessages();
        }

        function hideMessages() {
            const success = document.getElementById('feedbackSuccess');
            const error = document.getElementById('feedbackError');
            if (success) {
                success.classList.remove('success');
                success.style.display = 'none';
            }
            if (error) {
                error.classList.remove('error');
                error.style.display = 'none';
            }
        }

        function showSuccess() {
            hideMessages();
            const el = document.getElementById('feedbackSuccess');
            if (el) {
                el.style.display = 'block';
                el.classList.add('success');
            }
            // Reload page after delay to show "already submitted" state
            setTimeout(() => location.reload(), 1500);
        }

        function showError() {
            hideMessages();
            const el = document.getElementById('feedbackError');
            if (el) {
                el.style.display = 'block';
                el.classList.add('error');
            }
        }

        async function submitCorrect() {
            try {
                const response = await fetch('api.php?action=feedback', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({feedback: true})
                });
                const data = await response.json();

                if (data.success) {
                    showSuccess();
                    const panel = document.getElementById('correctionPanel');
                    if (panel) panel.classList.remove('visible');
                } else {
                    showError();
                }
            } catch (error) {
                console.error('Error:', error);
                showError();
            }
        }

        async function submitWrong() {
            const correctWmoEl = document.getElementById('correctWmo');
            const commentEl = document.getElementById('feedbackComment');
            if (!correctWmoEl) return;

            const correctWmo = correctWmoEl.value;
            const comment = commentEl ? commentEl.value : '';

            if (!correctWmo) return;

            try {
                const response = await fetch('api.php?action=feedback', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        feedback: false,
                        correct_wmo: parseInt(correctWmo),
                        comment: comment || null
                    })
                });
                const data = await response.json();

                if (data.success) {
                    showSuccess();
                } else {
                    showError();
                }
            } catch (error) {
                console.error('Error:', error);
                showError();
            }
        }
        </script>

        <?php else: ?>
        <div class="no-data">Keine Wetterdaten verfügbar</div>
        <?php endif; ?>

        <?php elseif ($activeTab === 'analyse'): ?>
        <!-- ========== ANALYSE TAB ========== -->

        <div id="analyseContent">
            <div class="no-data">Lade Analyse-Daten...</div>
        </div>

        <script>
        async function loadAnalyseData() {
            try {
                const response = await fetch('api.php?action=feedback_stats');
                const data = await response.json();

                renderAnalyse(data);
            } catch (error) {
                console.error('Error loading analyse data:', error);
                document.getElementById('analyseContent').innerHTML =
                    '<div class="no-data">Fehler beim Laden der Analyse-Daten</div>';
            }
        }

        function renderAnalyse(data) {
            const container = document.getElementById('analyseContent');
            const s = data.summary;

            let html = `
                <div class="section-title">Übersicht</div>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-value">${s.total_readings.toLocaleString('de-DE')}</div>
                        <div class="stat-label">Messungen</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value">${s.total_feedback}</div>
                        <div class="stat-label">Feedbacks</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value good">${s.correct_count}</div>
                        <div class="stat-label">Richtig</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value bad">${s.wrong_count}</div>
                        <div class="stat-label">Falsch</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value ${s.accuracy_percent >= 80 ? 'good' : s.accuracy_percent >= 50 ? '' : 'bad'}">
                            ${s.accuracy_percent !== null ? s.accuracy_percent.toFixed(1).replace('.', ',') + '%' : '—'}
                        </div>
                        <div class="stat-label">Genauigkeit</div>
                    </div>
                </div>
            `;

            // Error Analysis Table
            if (data.error_analysis && data.error_analysis.length > 0) {
                html += `
                    <div class="section-title">Fehleranalyse</div>
                    <table class="error-table">
                        <thead>
                            <tr>
                                <th>Erkannt</th>
                                <th>Korrekt</th>
                                <th>Anzahl</th>
                                <th>Ø Temp</th>
                                <th>Ø Delta</th>
                            </tr>
                        </thead>
                        <tbody>
                `;
                data.error_analysis.forEach(e => {
                    html += `
                        <tr>
                            <td>WMO ${e.original_wmo}<br><small>${e.original_condition.replace(/_/g, ' ')}</small></td>
                            <td>WMO ${e.corrected_wmo}<br><small>${e.corrected_condition.replace(/_/g, ' ')}</small></td>
                            <td>${e.error_count}</td>
                            <td>${e.avg_temp.toFixed(1).replace('.', ',')}°C</td>
                            <td>${e.avg_delta !== null ? e.avg_delta.toFixed(1).replace('.', ',') + '°C' : '—'}</td>
                        </tr>
                    `;
                });
                html += '</tbody></table>';
            }

            // Recommendations
            if (data.recommendations && data.recommendations.length > 0) {
                html += `
                    <div class="section-title">Empfehlungen (min. ${data.min_feedback_for_recommendation} Fehler)</div>
                    <div class="recommendation-list">
                `;
                data.recommendations.forEach((r, i) => {
                    html += `
                        <div class="recommendation-item">
                            <input type="checkbox" class="recommendation-checkbox" id="rec_${i}" data-id="${r.id}" data-param="${r.parameter}" data-value="${r.suggested_value}">
                            <div class="recommendation-content">
                                <div class="recommendation-param">${r.parameter}</div>
                                <div class="recommendation-values">${r.current_value} → <strong>${r.suggested_value}</strong></div>
                                <div class="recommendation-reason">${r.reason} (${r.error_count}×)</div>
                            </div>
                        </div>
                    `;
                });
                html += `
                    </div>
                    <button class="apply-btn" onclick="applyRecommendations()" id="applyBtn" disabled>
                        Ausgewählte Empfehlungen anwenden
                    </button>
                    <div id="applyMessage" class="feedback-message"></div>
                `;
            } else if (s.wrong_count > 0) {
                html += `<div class="no-data">Noch keine Empfehlungen (min. ${data.min_feedback_for_recommendation} gleiche Fehler nötig)</div>`;
            }

            // Recent Feedback
            if (data.recent_feedback && data.recent_feedback.length > 0) {
                html += `
                    <div class="section-title" style="margin-top: 24px;">Letzte Feedbacks</div>
                    <table class="error-table">
                        <thead>
                            <tr>
                                <th>Zeit</th>
                                <th>WMO</th>
                                <th>Feedback</th>
                                <th>Korrektur</th>
                            </tr>
                        </thead>
                        <tbody>
                `;
                data.recent_feedback.forEach(f => {
                    const time = new Date(f.timestamp).toLocaleString('de-DE', {
                        day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit'
                    });
                    html += `
                        <tr>
                            <td>${time}</td>
                            <td>${f.wmo_code} (${f.condition.replace(/_/g, ' ')})</td>
                            <td class="${f.feedback ? 'bool-yes' : 'bool-no'}">${f.feedback ? '✓' : '✗'}</td>
                            <td>${f.correct_wmo !== null ? f.correct_wmo : '—'}</td>
                        </tr>
                    `;
                });
                html += '</tbody></table>';
            }

            container.innerHTML = html;

            // Add checkbox listeners
            document.querySelectorAll('.recommendation-checkbox').forEach(cb => {
                cb.addEventListener('change', updateApplyButton);
            });
        }

        function updateApplyButton() {
            const checked = document.querySelectorAll('.recommendation-checkbox:checked').length;
            const btn = document.getElementById('applyBtn');
            if (btn) btn.disabled = checked === 0;
        }

        async function applyRecommendations() {
            const selected = [];
            document.querySelectorAll('.recommendation-checkbox:checked').forEach(cb => {
                selected.push({
                    parameter: cb.dataset.param,
                    value: parseFloat(cb.dataset.value)
                });
            });

            if (selected.length === 0) return;

            const msgEl = document.getElementById('applyMessage');
            const btn = document.getElementById('applyBtn');

            // Confirm before applying
            const paramList = selected.map(s => `${s.parameter} = ${s.value}`).join('\n');
            if (!confirm(`Folgende Schwellwerte ändern?\n\n${paramList}`)) {
                return;
            }

            btn.disabled = true;
            btn.textContent = 'Wird angewendet...';

            try {
                const response = await fetch('api.php?action=apply_recommendations', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({recommendations: selected})
                });
                const data = await response.json();

                if (data.success) {
                    msgEl.textContent = `✓ ${data.applied.length} Empfehlung(en) angewendet. Backup: ${data.backup}`;
                    msgEl.className = 'feedback-message success';
                    msgEl.style.display = 'block';

                    // Uncheck applied checkboxes
                    document.querySelectorAll('.recommendation-checkbox:checked').forEach(cb => {
                        cb.checked = false;
                    });

                    // Reload analysis after short delay
                    setTimeout(() => loadAnalyseData(), 2000);
                } else {
                    msgEl.textContent = `✗ Fehler: ${data.error}`;
                    msgEl.className = 'feedback-message error';
                    msgEl.style.display = 'block';
                }
            } catch (error) {
                console.error('Error:', error);
                msgEl.textContent = '✗ Fehler beim Anwenden';
                msgEl.className = 'feedback-message error';
                msgEl.style.display = 'block';
            }

            btn.disabled = false;
            btn.textContent = 'Ausgewählte Empfehlungen anwenden';
            updateApplyButton();
        }

        // Load data on page load
        loadAnalyseData();
        </script>

        <?php elseif ($activeTab === 'icons'): ?>
        <!-- ========== ICONS TAB ========== -->

        <div class="section-title">Aktiv genutzte WMO Codes (aus Sensordaten ableitbar)</div>
        <table class="icon-table">
            <thead>
                <tr>
                    <th style="width: 35%;">WMO Code / Name / wi-Klasse</th>
                    <th style="width: 32.5%;">Tag</th>
                    <th style="width: 32.5%;">Nacht</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($wmoMapping as $code => $data): ?>
                <?php if ($data[6]): // derivable ?>
                <tr>
                    <td>
                        <div class="wmo-code"><?= $code ?></div>
                        <div class="wmo-name"><?= htmlspecialchars($data[1]) ?></div>
                        <div class="wi-class"><?= htmlspecialchars($data[2]) ?> / <?= htmlspecialchars($data[3]) ?></div>
                    </td>
                    <td class="icon-cell">
                        <img src="icons/<?= htmlspecialchars($data[4]) ?>" alt="<?= htmlspecialchars($data[0]) ?>">
                        <div class="icon-filename"><?= htmlspecialchars($data[4]) ?></div>
                    </td>
                    <td class="icon-cell">
                        <img src="icons/<?= htmlspecialchars($data[5]) ?>" alt="<?= htmlspecialchars($data[0]) ?> Night">
                        <div class="icon-filename"><?= htmlspecialchars($data[5]) ?></div>
                    </td>
                </tr>
                <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="section-title">Nicht ableitbare WMO Codes (kein Sensor verfügbar)</div>
        <table class="icon-table">
            <thead>
                <tr>
                    <th style="width: 35%;">WMO Code / Name / wi-Klasse</th>
                    <th style="width: 32.5%;">Tag</th>
                    <th style="width: 32.5%;">Nacht</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($wmoMapping as $code => $data): ?>
                <?php if (!$data[6]): // not derivable ?>
                <tr class="not-derivable">
                    <td>
                        <div class="wmo-code"><?= $code ?></div>
                        <div class="wmo-name"><?= htmlspecialchars($data[1]) ?></div>
                        <div class="wi-class"><?= htmlspecialchars($data[2]) ?> / <?= htmlspecialchars($data[3]) ?></div>
                    </td>
                    <td class="icon-cell">
                        <img src="icons/<?= htmlspecialchars($data[4]) ?>" alt="<?= htmlspecialchars($data[0]) ?>">
                        <div class="icon-filename"><?= htmlspecialchars($data[4]) ?></div>
                    </td>
                    <td class="icon-cell">
                        <img src="icons/<?= htmlspecialchars($data[5]) ?>" alt="<?= htmlspecialchars($data[0]) ?> Night">
                        <div class="icon-filename"><?= htmlspecialchars($data[5]) ?></div>
                    </td>
                </tr>
                <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php endif; ?>

        <footer>
            <span><?= number_format($dbStats['count'], 0, ',', '.') ?> Datensätze</span>
            <span>DB: <?= number_format($dbStats['size_mb'], 1, ',', '.') ?> MB</span>
        </footer>
    </div>

    <?php if ($activeTab === 'weather'): ?>
    <script>
        // Common chart options
        const commonOptions = {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                intersect: false,
                mode: 'index'
            },
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        color: '#aaa',
                        usePointStyle: true,
                        padding: 20
                    }
                },
                tooltip: {
                    backgroundColor: '#16213e',
                    titleColor: '#eee',
                    bodyColor: '#eee',
                    borderColor: '#00bceb',
                    borderWidth: 1,
                    callbacks: {
                        label: function(context) {
                            return context.dataset.label + ': ' + (context.parsed.y !== null ? context.parsed.y.toFixed(1).replace('.', ',') + '°C' : '—');
                        }
                    }
                }
            },
            scales: {
                x: {
                    ticks: {
                        color: '#aaa',
                        maxTicksLimit: 12
                    },
                    grid: {
                        color: 'rgba(255,255,255,0.1)'
                    }
                },
                y: {
                    ticks: {
                        color: '#aaa',
                        callback: function(value) {
                            return value.toString().replace('.', ',') + '°C';
                        }
                    },
                    grid: {
                        color: 'rgba(255,255,255,0.1)'
                    }
                }
            }
        };

        // Outside temperature chart
        const outsideCtx = document.getElementById('outsideChart');
        if (outsideCtx) {
            new Chart(outsideCtx, {
                type: 'line',
                data: {
                    labels: <?= json_encode($chartLabels) ?>,
                    datasets: [{
                        label: 'Außen',
                        data: <?= json_encode($chartOutside) ?>,
                        borderColor: '#ff6384',
                        backgroundColor: 'rgba(255, 99, 132, 0.1)',
                        borderWidth: 2,
                        tension: 0.3,
                        fill: true,
                        pointRadius: 0,
                        pointHitRadius: 10
                    }]
                },
                options: commonOptions
            });
        }

        // Indoor sensors chart
        const indoorCtx = document.getElementById('indoorChart');
        if (indoorCtx) {
            new Chart(indoorCtx, {
                type: 'line',
                data: {
                    labels: <?= json_encode($chartLabels) ?>,
                    datasets: [
                        {
                            label: '<?= SENSOR1_NAME ?>',
                            data: <?= json_encode($chartSensor1) ?>,
                            borderColor: '#36a2eb',
                            backgroundColor: 'rgba(54, 162, 235, 0.1)',
                            borderWidth: 2,
                            tension: 0.3,
                            fill: false,
                            pointRadius: 0,
                            pointHitRadius: 10
                        },
                        {
                            label: '<?= SENSOR2_NAME ?>',
                            data: <?= json_encode($chartSensor2) ?>,
                            borderColor: '#ffce56',
                            backgroundColor: 'rgba(255, 206, 86, 0.1)',
                            borderWidth: 2,
                            tension: 0.3,
                            fill: false,
                            pointRadius: 0,
                            pointHitRadius: 10
                        }
                    ]
                },
                options: commonOptions
            });
        }

        // Auto-refresh every 60 seconds
        setTimeout(function() {
            location.reload();
        }, 60000);
    </script>
    <?php endif; ?>
</body>
</html>
