<?php
/**
 * PWS Receiver - Weather Aggregator
 *
 * Receives PWS push data (Wunderground protocol), fetches CloudWatcher data,
 * derives WMO weather code, and stores everything to PostgreSQL database.
 *
 * Modified: 2026-01-28 - Initial creation (Phase 1)
 * Modified: 2026-01-28 - Added CloudWatcher API + WMO derivation (Phase 2)
 * Modified: 2026-01-28 - Fixed: Removed ID check, corrected rainratein parameter name
 * Modified: 2026-01-29 - Added dewpoint calculation, pressure from baromabsin, humidity1/humidity2
 */

// Error reporting for development (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/wmo_derivation.php';

/**
 * Convert Fahrenheit to Celsius
 */
function fahrenheitToCelsius($f) {
    if ($f === null || $f === '') return null;
    return round(($f - 32) * 5 / 9, 2);
}

/**
 * Convert mph to m/s
 */
function mphToMs($mph) {
    if ($mph === null || $mph === '') return null;
    return round($mph * 0.44704, 2);
}

/**
 * Convert inches to mm
 */
function inchesToMm($inches) {
    if ($inches === null || $inches === '') return null;
    return round($inches * 25.4, 2);
}

/**
 * Convert inHg to hPa
 */
function inhgToHpa($inhg) {
    if ($inhg === null || $inhg === '') return null;
    return round($inhg * 33.8639, 1);
}

/**
 * Calculate dewpoint using Magnus formula
 */
function calculateDewpoint($tempC, $humidity) {
    if ($tempC === null || $humidity === null || $humidity <= 0) {
        return null;
    }
    $a = 17.625;
    $b = 243.04;
    $alpha = log($humidity / 100) + ($a * $tempC) / ($b + $tempC);
    return round($b * $alpha / ($a - $alpha), 2);
}

/**
 * Calculate relative pressure (reduced to sea level) using barometric formula
 */
function calculateRelativePressure($absInHg, $tempC) {
    if ($absInHg === null || $tempC === null) {
        return null;
    }
    // Convert to hPa
    $pAbs = $absInHg * 33.8639;

    // Constants
    $g0 = 9.80665;  // Gravitational acceleration
    $R = 287.05;    // Gas constant for dry air
    $a = 0.0065;    // Temperature gradient (K/m)

    // Temperature in Kelvin
    $TK = $tempC + 273.15;

    // Barometric height formula
    $pRel = $pAbs * exp(($g0 * STATION_HEIGHT) / ($R * ($TK + ($a * STATION_HEIGHT) / 2)));

    return round($pRel, 1);
}

/**
 * Get optional GET parameter
 */
function getParam($name, $default = null) {
    return isset($_GET[$name]) && $_GET[$name] !== '' ? $_GET[$name] : $default;
}

/**
 * Log message to error log
 */
function logMessage($message) {
    error_log("[weather-aggregator] " . $message);
}

/**
 * Convert value to PostgreSQL boolean string or null
 */
function toBool($val) {
    if ($val === null || $val === '') return null;
    return $val ? 't' : 'f';
}

/**
 * Fetch CloudWatcher data from API
 *
 * @return array CloudWatcher data or empty array on failure
 */
function fetchCloudWatcherData() {
    $url = CLOUDWATCHER_API_URL;
    $timeout = CLOUDWATCHER_TIMEOUT;

    $context = stream_context_create([
        'http' => [
            'timeout' => $timeout,
            'ignore_errors' => true,
        ]
    ]);

    $response = @file_get_contents($url, false, $context);

    if ($response === false) {
        logMessage("CloudWatcher API unreachable: $url");
        return [];
    }

    $data = json_decode($response, true);

    if (!$data || !isset($data['sky_temp_c'])) {
        logMessage("CloudWatcher API invalid response");
        return [];
    }

    logMessage("CloudWatcher data: sky_temp={$data['sky_temp_c']}°C, rain_freq={$data['rain_freq']}");

    return [
        'sky_temp_c' => $data['sky_temp_c'],
        'rain_freq' => $data['rain_freq'] ?? null,
        'mpsas' => $data['mpsas'] ?? null,
        'is_raining' => $data['is_raining'] ?? false,
        'is_daylight' => $data['is_daylight'] ?? null,
    ];
}

// Main execution
try {
    // Log PWS push (no ID check - just process incoming data)
    $stationType = getParam('stationtype', 'unknown');
    logMessage("PWS push received (type: $stationType)");

    // Parse and convert PWS data
    $temp_c = fahrenheitToCelsius(getParam('tempf'));
    $humidity = floatval(getParam('humidity'));

    $pws = [
        'temp_c' => $temp_c,
        'humidity' => $humidity,
        'dewpoint_c' => calculateDewpoint($temp_c, $humidity),
        'pressure_hpa' => calculateRelativePressure(getParam('baromabsin'), $temp_c),
        'wind_speed_ms' => mphToMs(getParam('windspeedmph')),
        'wind_dir_deg' => intval(getParam('winddir', 0)),
        'wind_gust_ms' => mphToMs(getParam('windgustmph')),
        'precip_rate_mm' => inchesToMm(getParam('rainratein')),
        'precip_today_mm' => inchesToMm(getParam('dailyrainin')),
        'uv_index' => floatval(getParam('UV')),
        'solar_radiation' => floatval(getParam('solarradiation')),
        'temp1_c' => fahrenheitToCelsius(getParam('temp1f')),
        'temp2_c' => fahrenheitToCelsius(getParam('temp2f')),
        'humidity1' => intval(getParam('humidity1')),
        'humidity2' => intval(getParam('humidity2')),
    ];

    // Fetch CloudWatcher data
    $cw = fetchCloudWatcherData();
    if (empty($cw)) {
        // Fallback: empty CloudWatcher data
        $cw = [
            'sky_temp_c' => null,
            'rain_freq' => null,
            'mpsas' => null,
            'is_raining' => null,
            'is_daylight' => null,
        ];
    }

    // Derive WMO code from combined sensor data
    $wmo_result = derive_wmo_code($pws, $cw);
    $delta = $wmo_result['delta_c'];
    $wmo_code = $wmo_result['wmo_code'];
    $condition = $wmo_result['condition'];

    // Insert into database
    $sql = "INSERT INTO weather_readings (
        temp_c, humidity, dewpoint_c, pressure_hpa,
        wind_speed_ms, wind_dir_deg, wind_gust_ms,
        precip_rate_mm, precip_today_mm,
        uv_index, solar_radiation, temp1_c, temp2_c,
        humidity1, humidity2,
        sky_temp_c, rain_freq, mpsas, cw_is_raining, cw_is_daylight,
        delta_c, wmo_code, condition
    ) VALUES (
        :temp_c, :humidity, :dewpoint_c, :pressure_hpa,
        :wind_speed_ms, :wind_dir_deg, :wind_gust_ms,
        :precip_rate_mm, :precip_today_mm,
        :uv_index, :solar_radiation, :temp1_c, :temp2_c,
        :humidity1, :humidity2,
        :sky_temp_c, :rain_freq, :mpsas, :cw_is_raining, :cw_is_daylight,
        :delta_c, :wmo_code, :condition
    )";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':temp_c' => $pws['temp_c'],
        ':humidity' => $pws['humidity'],
        ':dewpoint_c' => $pws['dewpoint_c'],
        ':pressure_hpa' => $pws['pressure_hpa'],
        ':wind_speed_ms' => $pws['wind_speed_ms'],
        ':wind_dir_deg' => $pws['wind_dir_deg'],
        ':wind_gust_ms' => $pws['wind_gust_ms'],
        ':precip_rate_mm' => $pws['precip_rate_mm'],
        ':precip_today_mm' => $pws['precip_today_mm'],
        ':uv_index' => $pws['uv_index'],
        ':solar_radiation' => $pws['solar_radiation'],
        ':temp1_c' => $pws['temp1_c'],
        ':temp2_c' => $pws['temp2_c'],
        ':humidity1' => $pws['humidity1'] ?: null,
        ':humidity2' => $pws['humidity2'] ?: null,
        ':sky_temp_c' => $cw['sky_temp_c'],
        ':rain_freq' => $cw['rain_freq'],
        ':mpsas' => $cw['mpsas'],
        ':cw_is_raining' => toBool($cw['is_raining'] ?? null),
        ':cw_is_daylight' => toBool($cw['is_daylight'] ?? null),
        ':delta_c' => $delta,
        ':wmo_code' => $wmo_code,
        ':condition' => $condition,
    ]);

    logMessage("Data stored: temp={$pws['temp_c']}°C, delta={$delta}°C, wmo={$wmo_code} ({$condition})");

    // Success response (expected by PWS)
    echo "success";

} catch (PDOException $e) {
    logMessage("Database error: " . $e->getMessage());
    http_response_code(500);
    echo "error: database";
} catch (Exception $e) {
    logMessage("Error: " . $e->getMessage());
    http_response_code(500);
    echo "error: " . $e->getMessage();
}
