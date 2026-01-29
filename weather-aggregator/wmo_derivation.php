<?php
/**
 * WMO Weather Code Derivation
 *
 * Derives WMO weather codes from PWS and CloudWatcher sensor data.
 * Based on WMO 4677 present weather codes.
 *
 * Modified: 2026-01-28 - Initial creation
 * Modified: 2026-01-29 - Extended with WMO 04, 10, 11, 48, 57, 67, 68, 77
 *                        Stricter fog thresholds, finer cloud thresholds
 */

require_once __DIR__ . '/config.php';

/**
 * Derive WMO weather code from sensor data.
 *
 * Decision tree (priority order):
 * 1. Precipitation (highest priority)
 *    - Freezing drizzle/rain (temp < 0.5°C)
 *    - Snow (temp < 1°C)
 *    - Sleet/Schneeregen (temp 1-3°C)
 *    - Rain/Drizzle (temp >= 3°C)
 *
 * 2. Fog/Mist (no precipitation)
 *    - Rime fog (fog + temp < 0)
 *    - Fog (spread < 1, humidity > 97%, delta < 5)
 *    - Shallow fog (temp <= dewpoint, wind < 1)
 *    - Mist (spread < 2, humidity 90-97%)
 *
 * 3. Haze (no precipitation, no fog)
 *    - Haze (humidity < 60%, delta > 15)
 *
 * 4. Cloud cover (fallback)
 *    - Clear/Mainly clear/Partly cloudy/Overcast based on delta
 *
 * @param array $pws PWS data (temp_c, humidity, dewpoint_c, precip_rate_mm, wind_speed_ms)
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
    $wind_speed = $pws['wind_speed_ms'] ?? null;

    $sky_temp = $cw['sky_temp_c'] ?? null;
    $cw_is_raining = $cw['is_raining'] ?? false;

    // Calculate delta if we have sky temperature
    if ($sky_temp !== null) {
        $result['delta_c'] = round($temp - $sky_temp, 1);
    }

    // Calculate spread (temp - dewpoint) for fog/mist detection
    $spread = null;
    if ($dewpoint !== null) {
        $spread = $temp - $dewpoint;
    }

    // ========================================
    // 1. PRECIPITATION (highest priority)
    // ========================================
    $is_precipitating = ($precip_rate > 0) || $cw_is_raining;

    if ($is_precipitating) {
        return derive_precipitation_code($temp, $humidity, $precip_rate, $cw_is_raining, $result);
    }

    // ========================================
    // 2. FOG / MIST (no precipitation)
    // ========================================
    $fog_result = derive_fog_mist_code($temp, $humidity, $dewpoint, $spread, $wind_speed, $result);
    if ($fog_result !== null) {
        return $fog_result;
    }

    // ========================================
    // 3. HAZE (no precipitation, no fog)
    // ========================================
    if ($humidity !== null && $result['delta_c'] !== null) {
        if ($humidity < HAZE_HUMIDITY_MAX && $result['delta_c'] > HAZE_DELTA_MIN) {
            $result['wmo_code'] = 4;
            $result['condition'] = 'haze';
            return $result;
        }
    }

    // ========================================
    // 4. CLOUD COVER (fallback)
    // ========================================
    if ($result['delta_c'] !== null) {
        $delta = $result['delta_c'];

        if ($delta > THRESHOLD_CLEAR) {
            $result['wmo_code'] = 0;
            $result['condition'] = 'clear';
        } elseif ($delta > THRESHOLD_MAINLY_CLEAR) {
            $result['wmo_code'] = 1;
            $result['condition'] = 'mainly_clear';
        } elseif ($delta > THRESHOLD_PARTLY_CLOUDY) {
            $result['wmo_code'] = 2;
            $result['condition'] = 'partly_cloudy';
        } else {
            $result['wmo_code'] = 3;
            $result['condition'] = 'overcast';
        }
    }

    return $result;
}

/**
 * Derive precipitation WMO code
 */
function derive_precipitation_code($temp, $humidity, $precip_rate, $cw_is_raining, $result) {
    // Determine if it's drizzle (very light, CW detects but PWS barely registers)
    $is_drizzle = $cw_is_raining && ($precip_rate < DRIZZLE_MAX);

    // Freezing precipitation (temp < 0.5°C)
    if ($temp < FREEZING_TEMP_MAX) {
        if ($is_drizzle || $precip_rate < DRIZZLE_MAX) {
            // Freezing drizzle
            if ($precip_rate < DRIZZLE_MAX && $humidity !== null && $humidity > 95) {
                $result['wmo_code'] = 57;  // Freezing drizzle, dense
                $result['condition'] = 'freezing_drizzle_dense';
            } else {
                $result['wmo_code'] = 56;  // Freezing drizzle, light
                $result['condition'] = 'freezing_drizzle_light';
            }
        } else {
            // Freezing rain
            if ($precip_rate >= RAIN_LIGHT_MAX) {
                $result['wmo_code'] = 67;  // Freezing rain, heavy
                $result['condition'] = 'freezing_rain_heavy';
            } else {
                $result['wmo_code'] = 66;  // Freezing rain, light
                $result['condition'] = 'freezing_rain_light';
            }
        }
        return $result;
    }

    // Snow (temp < 1°C)
    if ($temp < SNOW_TEMP_MAX) {
        if ($precip_rate < DRIZZLE_MAX && $temp < SNOW_GRAINS_TEMP) {
            // Snow grains (very light, very cold)
            $result['wmo_code'] = 77;
            $result['condition'] = 'snow_grains';
        } elseif ($precip_rate < RAIN_LIGHT_MAX) {
            $result['wmo_code'] = 71;  // Snow, slight
            $result['condition'] = 'snow_slight';
        } elseif ($precip_rate < RAIN_MODERATE_MAX) {
            $result['wmo_code'] = 73;  // Snow, moderate
            $result['condition'] = 'snow_moderate';
        } else {
            $result['wmo_code'] = 75;  // Snow, heavy
            $result['condition'] = 'snow_heavy';
        }
        return $result;
    }

    // Sleet / Schneeregen (temp 1-3°C)
    if ($temp >= SLEET_TEMP_MIN && $temp < SLEET_TEMP_MAX) {
        if ($precip_rate < RAIN_LIGHT_MAX) {
            $result['wmo_code'] = 68;  // Sleet, light
            $result['condition'] = 'sleet_light';
        } else {
            $result['wmo_code'] = 69;  // Sleet, heavy
            $result['condition'] = 'sleet_heavy';
        }
        return $result;
    }

    // Rain or drizzle (temp >= 3°C)
    if ($is_drizzle) {
        $result['wmo_code'] = 51;  // Drizzle, light
        $result['condition'] = 'drizzle_light';
    } elseif ($precip_rate < DRIZZLE_MAX) {
        $result['wmo_code'] = 51;  // Drizzle, light
        $result['condition'] = 'drizzle_light';
    } elseif ($precip_rate < RAIN_LIGHT_MAX) {
        $result['wmo_code'] = 61;  // Rain, slight
        $result['condition'] = 'rain_slight';
    } elseif ($precip_rate < RAIN_MODERATE_MAX) {
        $result['wmo_code'] = 63;  // Rain, moderate
        $result['condition'] = 'rain_moderate';
    } else {
        $result['wmo_code'] = 65;  // Rain, heavy
        $result['condition'] = 'rain_heavy';
    }

    return $result;
}

/**
 * Derive fog/mist WMO code
 * Returns null if no fog/mist condition detected
 */
function derive_fog_mist_code($temp, $humidity, $dewpoint, $spread, $wind_speed, $result) {
    // Need humidity and spread for fog detection
    if ($humidity === null || $spread === null) {
        return null;
    }

    // VETO: If spread > 3.0, it cannot be fog (low cloud instead)
    if ($spread > FOG_SPREAD_VETO) {
        return null;
    }

    // Depositing rime fog (WMO 48): Fog conditions + freezing
    if ($spread < FOG_SPREAD_MAX &&
        $humidity > FOG_HUMIDITY_MIN &&
        $result['delta_c'] !== null && $result['delta_c'] < FOG_DELTA_MAX &&
        $temp < 0) {
        $result['wmo_code'] = 48;
        $result['condition'] = 'depositing_rime_fog';
        return $result;
    }

    // Fog (WMO 45): Strict thresholds
    if ($spread < FOG_SPREAD_MAX &&
        $humidity > FOG_HUMIDITY_MIN &&
        $result['delta_c'] !== null && $result['delta_c'] < FOG_DELTA_MAX) {
        $result['wmo_code'] = 45;
        $result['condition'] = 'fog';
        return $result;
    }

    // Shallow fog (WMO 11): temp at or below dewpoint, windstill
    if ($dewpoint !== null && $temp <= $dewpoint &&
        $wind_speed !== null && $wind_speed < SHALLOW_FOG_WIND_MAX &&
        $humidity > 95) {
        $result['wmo_code'] = 11;
        $result['condition'] = 'shallow_fog';
        return $result;
    }

    // Mist (WMO 10): Less strict than fog
    if ($spread < MIST_SPREAD_MAX &&
        $humidity >= MIST_HUMIDITY_MIN &&
        $humidity <= MIST_HUMIDITY_MAX) {
        $result['wmo_code'] = 10;
        $result['condition'] = 'mist';
        return $result;
    }

    return null;
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
