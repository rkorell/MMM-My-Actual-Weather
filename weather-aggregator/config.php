<?php
/**
 * Weather Aggregator Configuration
 *
 * Contains URLs, thresholds and constants.
 * NO credentials here - those go in db_connect.php
 *
 * Modified: 2026-01-28 - Initial creation
 */

// Station location
define('STATION_HEIGHT', 416);  // Meters above sea level (Müllenborn)

// CloudWatcher API
define('CLOUDWATCHER_API_URL', 'http://172.23.56.60:5000/api/data');
define('CLOUDWATCHER_TIMEOUT', 5); // seconds

// WMO Code derivation thresholds

// Cloud condition thresholds (delta = ambient - sky temperature)
define('THRESHOLD_CLEAR', 25);         // delta > 25 = clear (WMO 0)
define('THRESHOLD_MAINLY_CLEAR', 20);  // delta > 20 = mainly clear (WMO 1)
define('THRESHOLD_PARTLY_CLOUDY', 15); // delta > 15 = partly cloudy (WMO 2)
// delta <= 15 = overcast (WMO 3)

// Rain intensity thresholds (mm/h)
define('RAIN_LIGHT_MAX', 2.5);    // < 2.5 = light
define('RAIN_MODERATE_MAX', 7.5); // < 7.5 = moderate, >= 7.5 = heavy

// Fog detection
define('FOG_SPREAD_MAX', 2.5);     // temp - dewpoint < 2.5
define('FOG_HUMIDITY_MIN', 95);    // humidity > 95%
define('FOG_DELTA_MAX', 10);       // delta < 10 (low clouds/fog)

// Snow temperature threshold
define('SNOW_TEMP_MAX', 2.0);      // temp < 2 = snow instead of rain
define('FREEZING_TEMP_MAX', 0.0);  // temp < 0 = freezing rain/drizzle

// Dashboard settings
define('SENSOR1_NAME', 'Therapie');
define('SENSOR2_NAME', 'WoZi');

// WMO condition names
define('WMO_CONDITIONS', [
    0 => 'clear',
    1 => 'mainly_clear',
    2 => 'partly_cloudy',
    3 => 'overcast',
    45 => 'fog',
    48 => 'depositing_rime_fog',
    51 => 'drizzle_light',
    53 => 'drizzle_moderate',
    55 => 'drizzle_dense',
    56 => 'freezing_drizzle_light',
    57 => 'freezing_drizzle_dense',
    61 => 'rain_slight',
    63 => 'rain_moderate',
    65 => 'rain_heavy',
    66 => 'freezing_rain_light',
    67 => 'freezing_rain_heavy',
    71 => 'snow_slight',
    73 => 'snow_moderate',
    75 => 'snow_heavy',
    80 => 'rain_showers_slight',
    81 => 'rain_showers_moderate',
    82 => 'rain_showers_violent',
    85 => 'snow_showers_slight',
    86 => 'snow_showers_heavy',
]);
