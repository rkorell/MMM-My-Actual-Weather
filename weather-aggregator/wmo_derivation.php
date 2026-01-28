<?php
/**
 * WMO Weather Code Derivation
 *
 * Derives WMO weather codes from PWS and CloudWatcher sensor data.
 * Based on WMO 4677 present weather codes.
 *
 * Modified: 2026-01-28 - Initial creation
 */

require_once __DIR__ . '/config.php';

/**
 * Derive WMO weather code from sensor data.
 *
 * Decision tree:
 * 1. Precipitation? (PWS rain rate > 0 OR CloudWatcher rain sensor)
 *    - Yes + Temp < 0°C  → Freezing Rain/Drizzle (56-57, 66-67)
 *    - Yes + Temp < 2°C  → Snow (71-75)
 *    - Yes + Temp >= 2°C → Rain/Drizzle (51-55, 61-65)
 *      - Intensity: light/moderate/heavy
 *
 * 2. Fog? (no precipitation)
 *    - Spread < 2.5°C AND Humidity > 95% AND Delta < 10°C → Fog (45)
 *
 * 3. Cloud cover (no precipitation, no fog)
 *    - Delta = temp_c - sky_temp_c
 *    - Delta > 25 → Clear (0)
 *    - Delta > 20 → Mainly Clear (1)
 *    - Delta > 15 → Partly Cloudy (2)
 *    - Delta <= 15 → Overcast (3)
 *
 * @param array $pws PWS data (temp_c, humidity, dewpoint_c, precip_rate_mm)
 * @param array $cw  CloudWatcher data (sky_temp_c, is_raining, rain_freq)
 * @return array ['wmo_code' => int, 'condition' => string, 'delta_c' => float]
 */
function derive_wmo_code($pws, $cw) {
    $result = [
        'wmo_code' => null,
        'condition' => null,
        'delta_c' => null,
    ];

    // Need at least temperature
    if (!isset($pws['temp_c']) || $pws['temp_c'] === null) {
        return $result;
    }

    $temp = $pws['temp_c'];
    $humidity = $pws['humidity'] ?? null;
    $dewpoint = $pws['dewpoint_c'] ?? null;
    $precip_rate = $pws['precip_rate_mm'] ?? 0;

    $sky_temp = $cw['sky_temp_c'] ?? null;
    $cw_is_raining = $cw['is_raining'] ?? false;

    // Calculate delta if we have sky temperature
    if ($sky_temp !== null) {
        $result['delta_c'] = round($temp - $sky_temp, 1);
    }

    // Calculate spread (temp - dewpoint) for fog detection
    $spread = null;
    if ($dewpoint !== null) {
        $spread = $temp - $dewpoint;
    }

    // 1. Check for precipitation
    $is_precipitating = ($precip_rate > 0) || $cw_is_raining;

    if ($is_precipitating) {
        // Determine precipitation type based on temperature
        if ($temp < FREEZING_TEMP_MAX) {
            // Freezing precipitation
            if ($precip_rate < RAIN_LIGHT_MAX) {
                $result['wmo_code'] = 56;  // Freezing drizzle, light
                $result['condition'] = 'freezing_drizzle_light';
            } else {
                $result['wmo_code'] = 66;  // Freezing rain, light
                $result['condition'] = 'freezing_rain_light';
            }
        } elseif ($temp < SNOW_TEMP_MAX) {
            // Snow
            if ($precip_rate < RAIN_LIGHT_MAX) {
                $result['wmo_code'] = 71;  // Snow, slight
                $result['condition'] = 'snow_slight';
            } elseif ($precip_rate < RAIN_MODERATE_MAX) {
                $result['wmo_code'] = 73;  // Snow, moderate
                $result['condition'] = 'snow_moderate';
            } else {
                $result['wmo_code'] = 75;  // Snow, heavy
                $result['condition'] = 'snow_heavy';
            }
        } else {
            // Rain or drizzle
            if ($precip_rate < RAIN_LIGHT_MAX) {
                // Light - could be drizzle
                if ($precip_rate < 0.5) {
                    $result['wmo_code'] = 51;  // Drizzle, light
                    $result['condition'] = 'drizzle_light';
                } else {
                    $result['wmo_code'] = 61;  // Rain, slight
                    $result['condition'] = 'rain_slight';
                }
            } elseif ($precip_rate < RAIN_MODERATE_MAX) {
                $result['wmo_code'] = 63;  // Rain, moderate
                $result['condition'] = 'rain_moderate';
            } else {
                $result['wmo_code'] = 65;  // Rain, heavy
                $result['condition'] = 'rain_heavy';
            }
        }
        return $result;
    }

    // 2. Check for fog (no precipitation)
    if ($spread !== null && $humidity !== null && $result['delta_c'] !== null) {
        if ($spread < FOG_SPREAD_MAX &&
            $humidity > FOG_HUMIDITY_MIN &&
            $result['delta_c'] < FOG_DELTA_MAX) {
            $result['wmo_code'] = 45;  // Fog
            $result['condition'] = 'fog';
            return $result;
        }
    }

    // 3. Cloud cover based on delta (no precipitation, no fog)
    if ($result['delta_c'] !== null) {
        $delta = $result['delta_c'];

        if ($delta > THRESHOLD_CLEAR) {
            $result['wmo_code'] = 0;  // Clear sky
            $result['condition'] = 'clear';
        } elseif ($delta > THRESHOLD_MAINLY_CLEAR) {
            $result['wmo_code'] = 1;  // Mainly clear
            $result['condition'] = 'mainly_clear';
        } elseif ($delta > THRESHOLD_PARTLY_CLOUDY) {
            $result['wmo_code'] = 2;  // Partly cloudy
            $result['condition'] = 'partly_cloudy';
        } else {
            $result['wmo_code'] = 3;  // Overcast
            $result['condition'] = 'overcast';
        }
    }

    return $result;
}

/**
 * Get condition name for WMO code.
 *
 * @param int $wmo_code WMO weather code
 * @return string|null Condition name or null if unknown
 */
function get_condition_name($wmo_code) {
    if ($wmo_code === null) {
        return null;
    }
    return WMO_CONDITIONS[$wmo_code] ?? null;
}
