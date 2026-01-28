<?php
/**
 * Weather Aggregator API
 *
 * JSON API for MagicMirror and other clients.
 *
 * Endpoints:
 *   ?action=current  - Latest weather data with WMO code
 *   ?action=history  - Historical data (&hours=24 for last 24h)
 *   ?action=raw      - Debug: last raw database row
 *   ?action=status   - System status (last update, DB stats)
 *
 * Modified: 2026-01-28 - Initial creation (Phase 3)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/config.php';

/**
 * Send JSON response and exit
 */
function jsonResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Send error response
 */
function errorResponse($message, $code = 400) {
    jsonResponse(['error' => $message], $code);
}

/**
 * Get current (latest) weather data
 */
function getCurrentWeather($pdo) {
    $sql = "SELECT * FROM weather_readings ORDER BY timestamp DESC LIMIT 1";
    $stmt = $pdo->query($sql);
    $row = $stmt->fetch();

    if (!$row) {
        return ['error' => 'no data available'];
    }

    // Calculate data age
    $timestamp = new DateTime($row['timestamp']);
    $now = new DateTime();
    $age_seconds = $now->getTimestamp() - $timestamp->getTimestamp();

    return [
        'timestamp' => $timestamp->format('c'),
        'temp_c' => floatval($row['temp_c']),
        'humidity' => floatval($row['humidity']),
        'dewpoint_c' => $row['dewpoint_c'] !== null ? floatval($row['dewpoint_c']) : null,
        'pressure_hpa' => $row['pressure_hpa'] !== null ? floatval($row['pressure_hpa']) : null,
        'wind_speed_ms' => $row['wind_speed_ms'] !== null ? floatval($row['wind_speed_ms']) : null,
        'wind_dir_deg' => $row['wind_dir_deg'] !== null ? intval($row['wind_dir_deg']) : null,
        'wind_gust_ms' => $row['wind_gust_ms'] !== null ? floatval($row['wind_gust_ms']) : null,
        'precip_rate_mm' => $row['precip_rate_mm'] !== null ? floatval($row['precip_rate_mm']) : null,
        'precip_today_mm' => $row['precip_today_mm'] !== null ? floatval($row['precip_today_mm']) : null,
        'uv_index' => $row['uv_index'] !== null ? floatval($row['uv_index']) : null,
        'solar_radiation' => $row['solar_radiation'] !== null ? floatval($row['solar_radiation']) : null,
        'temp1_c' => $row['temp1_c'] !== null ? floatval($row['temp1_c']) : null,
        'temp2_c' => $row['temp2_c'] !== null ? floatval($row['temp2_c']) : null,
        'sky_temp_c' => $row['sky_temp_c'] !== null ? floatval($row['sky_temp_c']) : null,
        'delta_c' => $row['delta_c'] !== null ? floatval($row['delta_c']) : null,
        'rain_freq' => $row['rain_freq'] !== null ? intval($row['rain_freq']) : null,
        'mpsas' => $row['mpsas'] !== null ? floatval($row['mpsas']) : null,
        'wmo_code' => $row['wmo_code'] !== null ? intval($row['wmo_code']) : null,
        'condition' => $row['condition'],
        'is_raining' => $row['cw_is_raining'] === 't' || $row['cw_is_raining'] === true,
        'is_daylight' => $row['cw_is_daylight'] === 't' || $row['cw_is_daylight'] === true,
        'data_age_s' => $age_seconds,
    ];
}

/**
 * Get historical weather data
 */
function getHistoryWeather($pdo, $hours = 24) {
    $hours = min(max(intval($hours), 1), 168); // 1-168 hours (1 week max)

    $sql = "SELECT timestamp, temp_c, humidity, pressure_hpa,
                   wind_speed_ms, precip_rate_mm, sky_temp_c,
                   delta_c, wmo_code, condition
            FROM weather_readings
            WHERE timestamp > NOW() - INTERVAL '{$hours} hours'
            ORDER BY timestamp ASC";

    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll();

    $data = [];
    foreach ($rows as $row) {
        $data[] = [
            'timestamp' => (new DateTime($row['timestamp']))->format('c'),
            'temp_c' => floatval($row['temp_c']),
            'humidity' => floatval($row['humidity']),
            'pressure_hpa' => $row['pressure_hpa'] !== null ? floatval($row['pressure_hpa']) : null,
            'wind_speed_ms' => $row['wind_speed_ms'] !== null ? floatval($row['wind_speed_ms']) : null,
            'precip_rate_mm' => $row['precip_rate_mm'] !== null ? floatval($row['precip_rate_mm']) : null,
            'sky_temp_c' => $row['sky_temp_c'] !== null ? floatval($row['sky_temp_c']) : null,
            'delta_c' => $row['delta_c'] !== null ? floatval($row['delta_c']) : null,
            'wmo_code' => $row['wmo_code'] !== null ? intval($row['wmo_code']) : null,
            'condition' => $row['condition'],
        ];
    }

    return [
        'hours' => $hours,
        'count' => count($data),
        'data' => $data,
    ];
}

/**
 * Get raw debug data (last entry)
 */
function getRawData($pdo) {
    $sql = "SELECT * FROM weather_readings ORDER BY timestamp DESC LIMIT 1";
    $stmt = $pdo->query($sql);
    $row = $stmt->fetch();

    return [
        'raw_row' => $row,
        'cloudwatcher_url' => CLOUDWATCHER_API_URL,
        'thresholds' => [
            'clear' => THRESHOLD_CLEAR,
            'mainly_clear' => THRESHOLD_MAINLY_CLEAR,
            'partly_cloudy' => THRESHOLD_PARTLY_CLOUDY,
            'rain_light_max' => RAIN_LIGHT_MAX,
            'rain_moderate_max' => RAIN_MODERATE_MAX,
        ],
    ];
}

/**
 * Get system status
 */
function getStatus($pdo) {
    // Last update time
    $sql = "SELECT timestamp FROM weather_readings ORDER BY timestamp DESC LIMIT 1";
    $stmt = $pdo->query($sql);
    $lastRow = $stmt->fetch();

    $lastUpdate = null;
    $dataAge = null;
    if ($lastRow) {
        $timestamp = new DateTime($lastRow['timestamp']);
        $lastUpdate = $timestamp->format('c');
        $dataAge = (new DateTime())->getTimestamp() - $timestamp->getTimestamp();
    }

    // Row count
    $sql = "SELECT COUNT(*) as count FROM weather_readings";
    $stmt = $pdo->query($sql);
    $countRow = $stmt->fetch();
    $rowCount = intval($countRow['count']);

    // Oldest entry
    $sql = "SELECT timestamp FROM weather_readings ORDER BY timestamp ASC LIMIT 1";
    $stmt = $pdo->query($sql);
    $oldestRow = $stmt->fetch();
    $oldestEntry = $oldestRow ? (new DateTime($oldestRow['timestamp']))->format('c') : null;

    // Database size estimate (rough)
    $dbSizeKb = round($rowCount * 0.1, 1); // ~100 bytes per row estimate

    return [
        'status' => $dataAge !== null && $dataAge < 300 ? 'ok' : 'stale',
        'last_update' => $lastUpdate,
        'data_age_s' => $dataAge,
        'row_count' => $rowCount,
        'oldest_entry' => $oldestEntry,
        'db_size_kb' => $dbSizeKb,
        'cloudwatcher_url' => CLOUDWATCHER_API_URL,
    ];
}

// Main execution
try {
    $action = $_GET['action'] ?? 'current';

    switch ($action) {
        case 'current':
            jsonResponse(getCurrentWeather($pdo));
            break;

        case 'history':
            $hours = $_GET['hours'] ?? 24;
            jsonResponse(getHistoryWeather($pdo, $hours));
            break;

        case 'raw':
            jsonResponse(getRawData($pdo));
            break;

        case 'status':
            jsonResponse(getStatus($pdo));
            break;

        default:
            errorResponse("Unknown action: $action. Valid actions: current, history, raw, status");
    }

} catch (PDOException $e) {
    error_log("[weather-api] Database error: " . $e->getMessage());
    errorResponse('Database error', 500);
} catch (Exception $e) {
    error_log("[weather-api] Error: " . $e->getMessage());
    errorResponse($e->getMessage(), 500);
}
