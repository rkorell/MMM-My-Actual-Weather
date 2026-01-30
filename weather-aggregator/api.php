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
 *   ?action=feedback (POST) - Save feedback for current reading
 *   ?action=feedback_stats  - Feedback statistics and recommendations
 *   ?action=wmo_list        - WMO codes sorted by proximity to current
 *   ?action=apply_recommendations (POST) - Apply threshold recommendations to config
 *
 * Modified: 2026-01-28 - Initial creation (Phase 3)
 * Modified: 2026-01-29 - Added humidity1, humidity2 to current endpoint
 * Modified: 2026-01-30 - Added cloudwatcher_online flag for fallback detection
 * Modified: 2026-01-30 - Added feedback endpoints (feedback, feedback_stats, wmo_list)
 * Modified: 2026-01-30 - Added apply_recommendations endpoint
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

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
        'humidity1' => $row['humidity1'] !== null ? intval($row['humidity1']) : null,
        'humidity2' => $row['humidity2'] !== null ? intval($row['humidity2']) : null,
        'sky_temp_c' => $row['sky_temp_c'] !== null ? floatval($row['sky_temp_c']) : null,
        'delta_c' => $row['delta_c'] !== null ? floatval($row['delta_c']) : null,
        'rain_freq' => $row['rain_freq'] !== null ? intval($row['rain_freq']) : null,
        'mpsas' => $row['mpsas'] !== null ? floatval($row['mpsas']) : null,
        'wmo_code' => $row['wmo_code'] !== null ? intval($row['wmo_code']) : null,
        'condition' => $row['condition'],
        'is_raining' => $row['cw_is_raining'] === 't' || $row['cw_is_raining'] === true,
        'is_daylight' => $row['cw_is_daylight'] === 't' || $row['cw_is_daylight'] === true,
        'cloudwatcher_online' => $row['sky_temp_c'] !== null,  // CW offline if no sky_temp
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

/**
 * Define WMO code groups for semantic proximity sorting
 */
function getWmoGroups() {
    return [
        'cloud' => [0, 1, 2, 3],           // Cloud cover
        'visibility' => [4, 10, 11],       // Haze, mist
        'fog' => [45, 48],                 // Fog variants
        'drizzle' => [51, 53, 55],         // Drizzle
        'freezing_drizzle' => [56, 57],    // Freezing drizzle
        'rain' => [61, 63, 65],            // Rain
        'freezing_rain' => [66, 67],       // Freezing rain
        'sleet' => [68, 69],               // Sleet
        'snow' => [71, 73, 75, 77],        // Snow
        'showers' => [80, 81, 82, 85, 86], // Showers
    ];
}

/**
 * Get WMO codes sorted by semantic proximity to current code
 */
function getWmoListByProximity($currentWmo) {
    $groups = getWmoGroups();

    // Find which group current WMO belongs to
    $currentGroup = null;
    foreach ($groups as $groupName => $codes) {
        if (in_array($currentWmo, $codes)) {
            $currentGroup = $groupName;
            break;
        }
    }

    // Define group similarity (closer groups first)
    $groupSimilarity = [
        'cloud' => ['cloud', 'visibility', 'fog', 'drizzle', 'rain', 'freezing_drizzle', 'freezing_rain', 'sleet', 'snow', 'showers'],
        'visibility' => ['visibility', 'fog', 'cloud', 'drizzle', 'rain', 'freezing_drizzle', 'freezing_rain', 'sleet', 'snow', 'showers'],
        'fog' => ['fog', 'visibility', 'cloud', 'drizzle', 'freezing_drizzle', 'rain', 'freezing_rain', 'sleet', 'snow', 'showers'],
        'drizzle' => ['drizzle', 'rain', 'freezing_drizzle', 'freezing_rain', 'sleet', 'snow', 'showers', 'cloud', 'visibility', 'fog'],
        'freezing_drizzle' => ['freezing_drizzle', 'drizzle', 'freezing_rain', 'rain', 'sleet', 'snow', 'showers', 'fog', 'cloud', 'visibility'],
        'rain' => ['rain', 'drizzle', 'freezing_rain', 'freezing_drizzle', 'showers', 'sleet', 'snow', 'cloud', 'visibility', 'fog'],
        'freezing_rain' => ['freezing_rain', 'rain', 'freezing_drizzle', 'drizzle', 'sleet', 'snow', 'showers', 'fog', 'cloud', 'visibility'],
        'sleet' => ['sleet', 'snow', 'rain', 'freezing_rain', 'drizzle', 'freezing_drizzle', 'showers', 'cloud', 'visibility', 'fog'],
        'snow' => ['snow', 'sleet', 'freezing_rain', 'freezing_drizzle', 'rain', 'drizzle', 'showers', 'fog', 'cloud', 'visibility'],
        'showers' => ['showers', 'rain', 'drizzle', 'sleet', 'snow', 'freezing_rain', 'freezing_drizzle', 'cloud', 'visibility', 'fog'],
    ];

    // Build sorted list
    $sortedCodes = [];
    $groupOrder = $groupSimilarity[$currentGroup] ?? array_keys($groups);

    foreach ($groupOrder as $groupName) {
        if (isset($groups[$groupName])) {
            foreach ($groups[$groupName] as $code) {
                // Put current code first in its group
                if ($code == $currentWmo) {
                    array_unshift($sortedCodes, $code);
                } else {
                    $sortedCodes[] = $code;
                }
            }
        }
    }

    // Build result with names
    $result = [];
    foreach ($sortedCodes as $code) {
        $result[] = [
            'code' => $code,
            'condition' => WMO_CONDITIONS[$code] ?? 'unknown',
            'is_current' => ($code == $currentWmo),
        ];
    }

    return $result;
}

/**
 * Get WMO codes sorted by proximity (API endpoint)
 */
function getWmoList($pdo) {
    // Get current WMO code
    $sql = "SELECT wmo_code FROM weather_readings ORDER BY timestamp DESC LIMIT 1";
    $stmt = $pdo->query($sql);
    $row = $stmt->fetch();

    $currentWmo = $row ? intval($row['wmo_code']) : 0;

    return [
        'current_wmo' => $currentWmo,
        'wmo_codes' => getWmoListByProximity($currentWmo),
    ];
}

/**
 * Save feedback for current reading (POST)
 */
function saveFeedback($pdo) {
    // Get POST data
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        // Try form data
        $input = $_POST;
    }

    $feedback = isset($input['feedback']) ? filter_var($input['feedback'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
    $correctWmo = isset($input['correct_wmo']) ? intval($input['correct_wmo']) : null;
    $comment = isset($input['comment']) ? trim($input['comment']) : null;

    if ($feedback === null) {
        return ['error' => 'feedback parameter required (true/false)'];
    }

    // If feedback is false, correct_wmo is required
    if ($feedback === false && $correctWmo === null) {
        return ['error' => 'correct_wmo required when feedback is false'];
    }

    // Update the most recent record
    $sql = "UPDATE weather_readings
            SET feedback = :feedback,
                feedback_correct_wmo = :correct_wmo,
                feedback_comment = :comment
            WHERE id = (SELECT id FROM weather_readings ORDER BY timestamp DESC LIMIT 1)
            RETURNING id, timestamp, wmo_code";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':feedback' => $feedback ? 't' : 'f',
        ':correct_wmo' => $feedback ? null : $correctWmo,
        ':comment' => $comment,
    ]);

    $row = $stmt->fetch();

    if (!$row) {
        return ['error' => 'no data to update'];
    }

    return [
        'success' => true,
        'updated_id' => intval($row['id']),
        'timestamp' => (new DateTime($row['timestamp']))->format('c'),
        'original_wmo' => intval($row['wmo_code']),
        'feedback' => $feedback,
        'correct_wmo' => $correctWmo,
        'comment' => $comment,
    ];
}

/**
 * Get feedback statistics and recommendations
 */
function getFeedbackStats($pdo) {
    // Total counts
    $sql = "SELECT
                COUNT(*) as total_readings,
                COUNT(feedback) as total_feedback,
                SUM(CASE WHEN feedback = true THEN 1 ELSE 0 END) as correct_count,
                SUM(CASE WHEN feedback = false THEN 1 ELSE 0 END) as wrong_count
            FROM weather_readings";
    $stmt = $pdo->query($sql);
    $counts = $stmt->fetch();

    // Error breakdown by WMO code (original vs corrected)
    $sql = "SELECT
                wmo_code as original_wmo,
                feedback_correct_wmo as corrected_wmo,
                COUNT(*) as error_count,
                AVG(temp_c) as avg_temp,
                AVG(humidity) as avg_humidity,
                AVG(delta_c) as avg_delta,
                AVG(precip_rate_mm) as avg_precip_rate,
                MIN(timestamp) as first_occurrence,
                MAX(timestamp) as last_occurrence
            FROM weather_readings
            WHERE feedback = false
            GROUP BY wmo_code, feedback_correct_wmo
            ORDER BY error_count DESC";
    $stmt = $pdo->query($sql);
    $errors = $stmt->fetchAll();

    $errorAnalysis = [];
    foreach ($errors as $row) {
        $errorAnalysis[] = [
            'original_wmo' => intval($row['original_wmo']),
            'original_condition' => WMO_CONDITIONS[intval($row['original_wmo'])] ?? 'unknown',
            'corrected_wmo' => intval($row['corrected_wmo']),
            'corrected_condition' => WMO_CONDITIONS[intval($row['corrected_wmo'])] ?? 'unknown',
            'error_count' => intval($row['error_count']),
            'avg_temp' => round(floatval($row['avg_temp']), 1),
            'avg_humidity' => round(floatval($row['avg_humidity']), 0),
            'avg_delta' => $row['avg_delta'] !== null ? round(floatval($row['avg_delta']), 1) : null,
            'avg_precip_rate' => $row['avg_precip_rate'] !== null ? round(floatval($row['avg_precip_rate']), 2) : null,
            'first_occurrence' => (new DateTime($row['first_occurrence']))->format('c'),
            'last_occurrence' => (new DateTime($row['last_occurrence']))->format('c'),
        ];
    }

    // Generate recommendations based on error patterns
    $recommendations = generateRecommendations($errorAnalysis);

    // Recent feedback entries (last 20)
    $sql = "SELECT id, timestamp, wmo_code, condition, feedback, feedback_correct_wmo, feedback_comment,
                   temp_c, humidity, delta_c, precip_rate_mm
            FROM weather_readings
            WHERE feedback IS NOT NULL
            ORDER BY timestamp DESC
            LIMIT 20";
    $stmt = $pdo->query($sql);
    $recentFeedback = [];
    foreach ($stmt->fetchAll() as $row) {
        $recentFeedback[] = [
            'id' => intval($row['id']),
            'timestamp' => (new DateTime($row['timestamp']))->format('c'),
            'wmo_code' => intval($row['wmo_code']),
            'condition' => $row['condition'],
            'feedback' => $row['feedback'] === 't' || $row['feedback'] === true,
            'correct_wmo' => $row['feedback_correct_wmo'] !== null ? intval($row['feedback_correct_wmo']) : null,
            'comment' => $row['feedback_comment'],
            'temp_c' => round(floatval($row['temp_c']), 1),
            'humidity' => intval($row['humidity']),
            'delta_c' => $row['delta_c'] !== null ? round(floatval($row['delta_c']), 1) : null,
            'precip_rate_mm' => $row['precip_rate_mm'] !== null ? round(floatval($row['precip_rate_mm']), 2) : null,
        ];
    }

    return [
        'summary' => [
            'total_readings' => intval($counts['total_readings']),
            'total_feedback' => intval($counts['total_feedback']),
            'correct_count' => intval($counts['correct_count']),
            'wrong_count' => intval($counts['wrong_count']),
            'accuracy_percent' => $counts['total_feedback'] > 0
                ? round(100 * intval($counts['correct_count']) / intval($counts['total_feedback']), 1)
                : null,
        ],
        'error_analysis' => $errorAnalysis,
        'recommendations' => $recommendations,
        'recent_feedback' => $recentFeedback,
        'min_feedback_for_recommendation' => MIN_FEEDBACK_FOR_RECOMMENDATION,
    ];
}

/**
 * Generate threshold recommendations based on error patterns
 */
function generateRecommendations($errorAnalysis) {
    $recommendations = [];

    foreach ($errorAnalysis as $error) {
        // Skip if not enough errors for recommendation
        if ($error['error_count'] < MIN_FEEDBACK_FOR_RECOMMENDATION) {
            continue;
        }

        $original = $error['original_wmo'];
        $corrected = $error['corrected_wmo'];
        $avgDelta = $error['avg_delta'];
        $avgPrecip = $error['avg_precip_rate'];
        $avgHumidity = $error['avg_humidity'];

        // Cloud cover thresholds (0-3)
        if (in_array($original, [0, 1, 2, 3]) && in_array($corrected, [0, 1, 2, 3])) {
            if ($original < $corrected && $avgDelta !== null) {
                // Detected clearer than actual (e.g., detected 0, was 2)
                // Suggest lowering threshold
                $param = getCloudThresholdParam($original);
                if ($param) {
                    $currentValue = constant($param);
                    $suggestedValue = round($avgDelta + 2, 0); // Add margin
                    if ($suggestedValue > $currentValue) {
                        $recommendations[] = [
                            'id' => "cloud_{$original}_to_{$corrected}",
                            'type' => 'threshold',
                            'parameter' => $param,
                            'current_value' => $currentValue,
                            'suggested_value' => $suggestedValue,
                            'reason' => "Detected {$error['original_condition']} but was {$error['corrected_condition']} (avg delta: {$avgDelta}°C)",
                            'error_count' => $error['error_count'],
                        ];
                    }
                }
            } elseif ($original > $corrected && $avgDelta !== null) {
                // Detected cloudier than actual (e.g., detected 3, was 1)
                // Suggest raising threshold
                $param = getCloudThresholdParam($corrected);
                if ($param) {
                    $currentValue = constant($param);
                    $suggestedValue = round($avgDelta - 2, 0); // Subtract margin
                    if ($suggestedValue < $currentValue) {
                        $recommendations[] = [
                            'id' => "cloud_{$original}_to_{$corrected}",
                            'type' => 'threshold',
                            'parameter' => $param,
                            'current_value' => $currentValue,
                            'suggested_value' => max(1, $suggestedValue),
                            'reason' => "Detected {$error['original_condition']} but was {$error['corrected_condition']} (avg delta: {$avgDelta}°C)",
                            'error_count' => $error['error_count'],
                        ];
                    }
                }
            }
        }

        // Rain/Drizzle intensity thresholds
        if (in_array($original, [51, 53, 55, 61, 63, 65]) && in_array($corrected, [51, 53, 55, 61, 63, 65])) {
            if ($avgPrecip !== null) {
                $rec = generatePrecipRecommendation($original, $corrected, $avgPrecip, $error);
                if ($rec) {
                    $recommendations[] = $rec;
                }
            }
        }

        // Fog vs mist vs cloud
        if (in_array($original, [3, 10, 11, 45, 48]) && in_array($corrected, [3, 10, 11, 45, 48])) {
            $rec = generateFogMistRecommendation($original, $corrected, $avgHumidity, $avgDelta, $error);
            if ($rec) {
                $recommendations[] = $rec;
            }
        }
    }

    return $recommendations;
}

/**
 * Get cloud threshold parameter name for WMO code
 */
function getCloudThresholdParam($wmoCode) {
    switch ($wmoCode) {
        case 0: return 'THRESHOLD_CLEAR';
        case 1: return 'THRESHOLD_MAINLY_CLEAR';
        case 2: return 'THRESHOLD_PARTLY_CLOUDY';
        default: return null;
    }
}

/**
 * Generate precipitation intensity recommendation
 */
function generatePrecipRecommendation($original, $corrected, $avgPrecip, $error) {
    // Drizzle light vs moderate boundary (DRIZZLE_LIGHT_MAX = 0.2)
    if (($original == 51 && $corrected == 53) || ($original == 53 && $corrected == 51)) {
        $currentValue = DRIZZLE_LIGHT_MAX;
        $suggestedValue = $original == 51 ? round($avgPrecip - 0.05, 2) : round($avgPrecip + 0.05, 2);
        return [
            'id' => "precip_{$original}_to_{$corrected}",
            'type' => 'threshold',
            'parameter' => 'DRIZZLE_LIGHT_MAX',
            'current_value' => $currentValue,
            'suggested_value' => max(0.05, $suggestedValue),
            'reason' => "Detected {$error['original_condition']} but was {$error['corrected_condition']} (avg rate: {$avgPrecip} mm/h)",
            'error_count' => $error['error_count'],
        ];
    }

    // Drizzle vs rain boundary (DRIZZLE_MAX = 1.0)
    if (($original == 53 && $corrected == 61) || ($original == 61 && $corrected == 53)) {
        $currentValue = DRIZZLE_MAX;
        $suggestedValue = $original == 53 ? round($avgPrecip - 0.1, 1) : round($avgPrecip + 0.1, 1);
        return [
            'id' => "precip_{$original}_to_{$corrected}",
            'type' => 'threshold',
            'parameter' => 'DRIZZLE_MAX',
            'current_value' => $currentValue,
            'suggested_value' => max(0.3, $suggestedValue),
            'reason' => "Detected {$error['original_condition']} but was {$error['corrected_condition']} (avg rate: {$avgPrecip} mm/h)",
            'error_count' => $error['error_count'],
        ];
    }

    // Rain slight vs moderate (RAIN_LIGHT_MAX = 2.5)
    if (($original == 61 && $corrected == 63) || ($original == 63 && $corrected == 61)) {
        $currentValue = RAIN_LIGHT_MAX;
        $suggestedValue = $original == 61 ? round($avgPrecip - 0.2, 1) : round($avgPrecip + 0.2, 1);
        return [
            'id' => "precip_{$original}_to_{$corrected}",
            'type' => 'threshold',
            'parameter' => 'RAIN_LIGHT_MAX',
            'current_value' => $currentValue,
            'suggested_value' => max(1.5, $suggestedValue),
            'reason' => "Detected {$error['original_condition']} but was {$error['corrected_condition']} (avg rate: {$avgPrecip} mm/h)",
            'error_count' => $error['error_count'],
        ];
    }

    return null;
}

/**
 * Generate fog/mist recommendation
 */
function generateFogMistRecommendation($original, $corrected, $avgHumidity, $avgDelta, $error) {
    // Fog vs mist humidity boundary
    if (($original == 45 && $corrected == 10) || ($original == 10 && $corrected == 45)) {
        $currentValue = FOG_HUMIDITY_MIN;
        $suggestedValue = $original == 45 ? round($avgHumidity + 1, 0) : round($avgHumidity - 1, 0);
        return [
            'id' => "fog_mist_{$original}_to_{$corrected}",
            'type' => 'threshold',
            'parameter' => 'FOG_HUMIDITY_MIN',
            'current_value' => $currentValue,
            'suggested_value' => min(99, max(90, $suggestedValue)),
            'reason' => "Detected {$error['original_condition']} but was {$error['corrected_condition']} (avg humidity: {$avgHumidity}%)",
            'error_count' => $error['error_count'],
        ];
    }

    // Overcast vs fog delta boundary
    if (($original == 3 && $corrected == 45) || ($original == 45 && $corrected == 3)) {
        if ($avgDelta !== null) {
            $currentValue = FOG_DELTA_MAX;
            $suggestedValue = $original == 45 ? round($avgDelta - 1, 0) : round($avgDelta + 1, 0);
            return [
                'id' => "fog_cloud_{$original}_to_{$corrected}",
                'type' => 'threshold',
                'parameter' => 'FOG_DELTA_MAX',
                'current_value' => $currentValue,
                'suggested_value' => max(2, min(10, $suggestedValue)),
                'reason' => "Detected {$error['original_condition']} but was {$error['corrected_condition']} (avg delta: {$avgDelta}°C)",
                'error_count' => $error['error_count'],
            ];
        }
    }

    return null;
}

/**
 * Apply threshold recommendations to config.php
 */
function applyRecommendations() {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || !isset($input['recommendations']) || !is_array($input['recommendations'])) {
        return ['error' => 'recommendations array required'];
    }

    $recommendations = $input['recommendations'];
    if (empty($recommendations)) {
        return ['error' => 'no recommendations to apply'];
    }

    // Allowed parameters that can be modified
    $allowedParams = [
        'THRESHOLD_CLEAR', 'THRESHOLD_MAINLY_CLEAR', 'THRESHOLD_PARTLY_CLOUDY',
        'DRIZZLE_LIGHT_MAX', 'DRIZZLE_MAX', 'FREEZING_DRIZZLE_DENSE',
        'RAIN_LIGHT_MAX', 'RAIN_MODERATE_MAX',
        'FOG_SPREAD_MAX', 'FOG_HUMIDITY_MIN', 'FOG_DELTA_MAX', 'FOG_SPREAD_VETO',
        'MIST_SPREAD_MAX', 'MIST_HUMIDITY_MIN', 'MIST_HUMIDITY_MAX',
        'SNOW_TEMP_MAX', 'SLEET_TEMP_MIN', 'SLEET_TEMP_MAX', 'FREEZING_TEMP_MAX',
        'HAZE_HUMIDITY_MAX', 'HAZE_DELTA_MIN',
    ];

    $configFile = __DIR__ . '/config.php';

    // Read current config
    $content = file_get_contents($configFile);
    if ($content === false) {
        return ['error' => 'could not read config.php'];
    }

    // Create backup
    $backupFile = $configFile . '.backup.' . date('Ymd_His');
    if (!copy($configFile, $backupFile)) {
        return ['error' => 'could not create backup'];
    }

    $applied = [];
    $errors = [];

    foreach ($recommendations as $rec) {
        $param = $rec['parameter'] ?? null;
        $value = $rec['value'] ?? null;

        if (!$param || $value === null) {
            $errors[] = "Invalid recommendation: missing parameter or value";
            continue;
        }

        if (!in_array($param, $allowedParams)) {
            $errors[] = "Parameter not allowed: $param";
            continue;
        }

        // Pattern to match define('PARAM', value);
        $pattern = "/define\s*\(\s*['\"]" . preg_quote($param, '/') . "['\"]\s*,\s*[^)]+\)/";

        // Determine value format (int or float)
        $formattedValue = is_float($value) ? number_format($value, 1, '.', '') : intval($value);

        $replacement = "define('$param', $formattedValue)";

        $newContent = preg_replace($pattern, $replacement, $content, 1, $count);

        if ($count > 0) {
            $content = $newContent;
            $applied[] = ['parameter' => $param, 'value' => $value];
        } else {
            $errors[] = "Parameter not found in config: $param";
        }
    }

    // Write updated config
    if (!empty($applied)) {
        // Update modification comment
        $today = date('Y-m-d');
        $modLine = " * Modified: $today - Threshold adjustment via feedback recommendations";

        // Check if today's modification line already exists
        if (strpos($content, "Modified: $today") === false) {
            // Add new modification line before closing comment
            $content = preg_replace('/(\s*\*\/\s*\n\s*\/\/ Station location)/', "\n$modLine\n */\n\n// Station location", $content);
        }

        if (file_put_contents($configFile, $content) === false) {
            return ['error' => 'could not write config.php', 'backup' => $backupFile];
        }
    }

    return [
        'success' => true,
        'applied' => $applied,
        'errors' => $errors,
        'backup' => $backupFile,
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

        case 'feedback':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                errorResponse('POST method required', 405);
            }
            jsonResponse(saveFeedback($pdo));
            break;

        case 'feedback_stats':
            jsonResponse(getFeedbackStats($pdo));
            break;

        case 'wmo_list':
            jsonResponse(getWmoList($pdo));
            break;

        case 'apply_recommendations':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                errorResponse('POST method required', 405);
            }
            jsonResponse(applyRecommendations());
            break;

        default:
            errorResponse("Unknown action: $action. Valid actions: current, history, raw, status, feedback, feedback_stats, wmo_list, apply_recommendations");
    }

} catch (PDOException $e) {
    error_log("[weather-api] Database error: " . $e->getMessage());
    errorResponse('Database error', 500);
} catch (Exception $e) {
    error_log("[weather-api] Error: " . $e->getMessage());
    errorResponse($e->getMessage(), 500);
}
