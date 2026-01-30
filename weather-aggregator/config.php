<?php
/**
 * Weather Aggregator Configuration
 *
 * Contains URLs, thresholds and constants.
 * NO credentials here - those go in db_connect.php
 *
 * Modified: 2026-01-28 - Initial creation
 * Modified: 2026-01-29 - Added thresholds for WMO 04, 10, 11, 48, 57, 67, 68, 77
 * Modified: 2026-01-30 - Drizzle thresholds: DRIZZLE_LIGHT_MAX, DRIZZLE_MAX, FREEZING_DRIZZLE_DENSE
 */

// Station location
define('STATION_HEIGHT', 416);  // Meters above sea level (Müllenborn)

// CloudWatcher API
define('CLOUDWATCHER_API_URL', 'http://172.23.56.60:5000/api/data');
define('CLOUDWATCHER_TIMEOUT', 5); // seconds

// WMO Code derivation thresholds

// Cloud condition thresholds (delta = ambient - sky temperature)
define('THRESHOLD_CLEAR', 25);         // delta > 25 = clear (WMO 0)
define('THRESHOLD_MAINLY_CLEAR', 18);  // delta > 18 = mainly clear (WMO 1)
define('THRESHOLD_PARTLY_CLOUDY', 8);  // delta > 8 = partly cloudy (WMO 2)
// delta <= 8 = overcast (WMO 3)

// Rain/Drizzle intensity thresholds (mm/h)
define('DRIZZLE_LIGHT_MAX', 0.2);    // < 0.2 = drizzle light (WMO 51)
define('DRIZZLE_MAX', 1.0);          // < 1.0 = drizzle moderate (WMO 53), >= 1.0 = rain
define('FREEZING_DRIZZLE_DENSE', 0.5); // >= 0.5 = freezing drizzle dense (WMO 57)
define('RAIN_LIGHT_MAX', 2.5);       // < 2.5 = light rain
define('RAIN_MODERATE_MAX', 7.5);    // < 7.5 = moderate, >= 7.5 = heavy

// Fog detection (strict thresholds)
define('FOG_SPREAD_MAX', 1.0);     // temp - dewpoint < 1.0
define('FOG_HUMIDITY_MIN', 97);    // humidity > 97%
define('FOG_DELTA_MAX', 5);        // delta < 5 (low clouds/fog)
define('FOG_SPREAD_VETO', 3.0);    // spread > 3.0 = cannot be fog

// Mist detection (WMO 10) - less strict than fog
define('MIST_SPREAD_MAX', 2.0);    // temp - dewpoint < 2.0
define('MIST_HUMIDITY_MIN', 90);   // humidity > 90%
define('MIST_HUMIDITY_MAX', 97);   // humidity < 97% (else fog)

// Shallow fog (WMO 11)
define('SHALLOW_FOG_WIND_MAX', 1.0); // wind < 1 m/s

// Haze detection (WMO 04)
define('HAZE_HUMIDITY_MAX', 60);   // humidity < 60%
define('HAZE_DELTA_MIN', 15);      // delta > 15 (no thick clouds)

// Snow/Sleet temperature thresholds
define('SNOW_TEMP_MAX', 1.0);      // temp < 1 = snow
define('SLEET_TEMP_MIN', 1.0);     // temp >= 1 = sleet possible
define('SLEET_TEMP_MAX', 3.0);     // temp < 3 = sleet (snow/rain mix)
define('FREEZING_TEMP_MAX', 0.5);  // temp < 0.5 = freezing rain/drizzle
define('SNOW_GRAINS_TEMP', -2.0);  // temp < -2 = snow grains possible

// Dashboard settings
define('SENSOR1_NAME', 'Therapie');
define('SENSOR2_NAME', 'WoZi');

// WMO condition names
define('WMO_CONDITIONS', [
    0 => 'clear',
    1 => 'mainly_clear',
    2 => 'partly_cloudy',
    3 => 'overcast',
    4 => 'haze',
    10 => 'mist',
    11 => 'shallow_fog',
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
    68 => 'sleet_light',
    69 => 'sleet_heavy',
    71 => 'snow_slight',
    73 => 'snow_moderate',
    75 => 'snow_heavy',
    77 => 'snow_grains',
    80 => 'rain_showers_slight',
    81 => 'rain_showers_moderate',
    82 => 'rain_showers_violent',
    85 => 'snow_showers_slight',
    86 => 'snow_showers_heavy',
]);
