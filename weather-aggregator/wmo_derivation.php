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
 * Modified: 2026-01-30 - Drizzle thresholds: light < 0.2, moderate 0.2-1.0, rain >= 1.0 mm/h
 * Modified: 2026-01-30 - Snow/Freezing logic restructured: snow priority over freezing at low temps
 *                        WMO 11 (shallow fog) now checked before WMO 45 (fog)
 * Modified: 2026-01-30 - AP 52: SHALLOW_FOG_HUMIDITY_MIN constant instead of hardcoded 95
 */

require_once __DIR__ . '/config.php';

/**
 * Derive WMO weather code from sensor data.
 *
 * Decision tree (priority order):
 * 1. Precipitation (highest priority)
 *    - temp < -2°C: certainly snow (too cold for liquid)
 *    - temp -2°C to 0°C: primarily snow, freezing rain at high rates
 *    - temp 0°C to 0.5°C: freezing drizzle/rain
 *    - temp 0.5°C to 1.5°C: snow
 *    - temp 1.5°C to 3°C: sleet (snow/rain mix)
 *    - temp >= 3°C: rain/drizzle
 *
 * 2. Fog/Mist (no precipitation)
 *    - Rime fog (fog + temp < 0)
 *    - Shallow fog (temp <= dewpoint, wind < 1) - checked before fog!
 *    - Fog (spread < 1, humidity > 97%, delta < 5)
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
 *
 * Temperature zones (restructured for correct snow/freezing priority):
 *   temp < -2°C        = certainly snow (too cold for liquid precipitation)
 *   temp -2°C to 0°C   = primarily snow, but freezing rain possible at high rates
 *   temp 0°C to 0.5°C  = freezing drizzle/rain zone
 *   temp 0.5°C to 1.5°C = snow zone
 *   temp 1.5°C to 3°C  = sleet (snow/rain mix)
 *   temp >= 3°C        = rain/drizzle
 *
 * Rate thresholds:
 *   < 0.2 mm/h = drizzle light (WMO 51/56)
 *   0.2 - 1.0 mm/h = drizzle moderate (WMO 53) or dense freezing (WMO 57)
 *   >= 1.0 mm/h = rain (WMO 61+)
 */
function derive_precipitation_code($temp, $humidity, $precip_rate, $cw_is_raining, $result) {
    // Determine if it's drizzle (CW detects but PWS barely registers)
    $is_light_drizzle = $cw_is_raining && ($precip_rate < DRIZZLE_LIGHT_MAX);

    // ========================================
    // SNOW ZONE (temp < 1.5°C)
    // ========================================
    if ($temp < SNOW_TEMP_MAX) {

        // Zone 1: Certainly snow (temp < -2°C) - too cold for liquid precipitation
        if ($temp < SNOW_CERTAIN_TEMP) {
            return derive_snow_code($temp, $precip_rate, $result);
        }

        // Zone 2: Primarily snow (-2°C to 0°C), but freezing rain possible at high rates
        if ($temp >= SNOW_CERTAIN_TEMP && $temp < 0) {
            // High rate AND temp > -1°C → freezing rain (typical for freezing rain events)
            if ($precip_rate >= RAIN_LIGHT_MAX && $temp > FREEZING_RAIN_TEMP) {
                return derive_freezing_rain_code($precip_rate, $result);
            }
            // Otherwise: snow
            return derive_snow_code($temp, $precip_rate, $result);
        }

        // Zone 3: Freezing drizzle/rain zone (0°C to 0.5°C)
        if ($temp >= 0 && $temp < FREEZING_TEMP_MAX) {
            if ($precip_rate >= DRIZZLE_MAX) {
                // Rate >= 1.0 mm/h → freezing rain
                return derive_freezing_rain_code($precip_rate, $result);
            } else {
                // Rate < 1.0 mm/h → freezing drizzle
                return derive_freezing_drizzle_code($precip_rate, $result);
            }
        }

        // Zone 4: Snow zone (0.5°C to 1.5°C)
        return derive_snow_code($temp, $precip_rate, $result);
    }

    // ========================================
    // SLEET ZONE (temp 1.5°C to 3°C)
    // ========================================
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

    // ========================================
    // RAIN/DRIZZLE ZONE (temp >= 3°C)
    // ========================================
    if ($is_light_drizzle || $precip_rate < DRIZZLE_LIGHT_MAX) {
        // Drizzle light: CW detects but PWS barely registers, or rate < 0.2 mm/h
        $result['wmo_code'] = 51;
        $result['condition'] = 'drizzle_light';
    } elseif ($precip_rate < DRIZZLE_MAX) {
        // Drizzle moderate: 0.2 - 1.0 mm/h
        $result['wmo_code'] = 53;
        $result['condition'] = 'drizzle_moderate';
    } elseif ($precip_rate < RAIN_LIGHT_MAX) {
        // Rain slight: 1.0 - 2.5 mm/h
        $result['wmo_code'] = 61;
        $result['condition'] = 'rain_slight';
    } elseif ($precip_rate < RAIN_MODERATE_MAX) {
        // Rain moderate: 2.5 - 7.5 mm/h
        $result['wmo_code'] = 63;
        $result['condition'] = 'rain_moderate';
    } else {
        // Rain heavy: >= 7.5 mm/h
        $result['wmo_code'] = 65;
        $result['condition'] = 'rain_heavy';
    }

    return $result;
}

/**
 * Derive snow WMO code based on rate and temperature
 */
function derive_snow_code($temp, $precip_rate, $result) {
    if ($precip_rate < DRIZZLE_LIGHT_MAX && $temp < SNOW_GRAINS_TEMP) {
        // Snow grains (very light < 0.2 mm/h, very cold < -2°C)
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

/**
 * Derive freezing rain WMO code based on rate
 */
function derive_freezing_rain_code($precip_rate, $result) {
    if ($precip_rate >= RAIN_LIGHT_MAX) {
        $result['wmo_code'] = 67;  // Freezing rain, heavy
        $result['condition'] = 'freezing_rain_heavy';
    } else {
        $result['wmo_code'] = 66;  // Freezing rain, light
        $result['condition'] = 'freezing_rain_light';
    }
    return $result;
}

/**
 * Derive freezing drizzle WMO code based on rate
 */
function derive_freezing_drizzle_code($precip_rate, $result) {
    if ($precip_rate >= FREEZING_DRIZZLE_DENSE) {
        $result['wmo_code'] = 57;  // Freezing drizzle, dense (>= 0.5 mm/h)
        $result['condition'] = 'freezing_drizzle_dense';
    } else {
        $result['wmo_code'] = 56;  // Freezing drizzle, light (< 0.5 mm/h)
        $result['condition'] = 'freezing_drizzle_light';
    }
    return $result;
}

/**
 * Derive fog/mist WMO code
 * Returns null if no fog/mist condition detected
 *
 * Priority order (most specific first):
 *   1. WMO 48 - Depositing rime fog (fog + freezing)
 *   2. WMO 11 - Shallow fog (windstill, temp <= dewpoint) - MORE SPECIFIC
 *   3. WMO 45 - Fog (strict thresholds)
 *   4. WMO 10 - Mist (less strict)
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

    // Shallow fog (WMO 11): temp at or below dewpoint, windstill
    // MUST be checked BEFORE WMO 45 - it's a more specific condition
    // (spread <= 0 also satisfies spread < 1.0, so fog would match first otherwise)
    if ($dewpoint !== null && $temp <= $dewpoint &&
        $wind_speed !== null && $wind_speed < SHALLOW_FOG_WIND_MAX &&
        $humidity > SHALLOW_FOG_HUMIDITY_MIN) {
        $result['wmo_code'] = 11;
        $result['condition'] = 'shallow_fog';
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
