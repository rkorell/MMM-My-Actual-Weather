"""
CloudWatcher Web Service
Modified: 2026-01-25 15:30 - Initial creation
Modified: 2026-01-28 19:00 - Updated for new sensor config (no ambient temp)
Modified: 2026-02-03 14:00 - Added heater_pwm to API response
Modified: 2026-02-04 19:20 - Added rain_sensor_temp_c for heater control feedback
Modified: 2026-02-04 20:55 - Added heater control with ESP ambient temperature
Modified: 2026-02-04 21:00 - BUGFIX: Impulse heating only when WET
Modified: 2026-02-05 - Fetch both ESP sensors (shadow + sun)

Flask web server providing:
- HTML dashboard at /
- JSON API at /api/data (for MagicMirror/Weather-Aggregator)
- Raw debug data at /api/raw

Heater control:
- Fetches ambient temperature from ESP sensor (Temp2IoT)
- Regulates rain sensor heater to prevent condensation
- Uses manufacturer/INDI default parameters
"""

import threading
import time
import logging
import urllib.request
import json
from datetime import datetime, timezone
from typing import Dict, Optional

from flask import Flask, jsonify, render_template

import config
from heating_controller import HeatingController

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

# Flask app
app = Flask(__name__)

# Global data cache (thread-safe via GIL for simple operations)
data_cache: Dict = {
    'timestamp': None,
    'data': None,
    'raw_samples': None,
    'device_info': None,
    'error': None,
    'heater_status': None,
    'esp_temp_shadow': None,  # ESP ambient temp (shadow sensor) - used for heater control
    'esp_temp_sun': None,     # ESP ambient temp (sun sensor)
}
start_time = datetime.now(timezone.utc)

# Reader instance (initialized in main)
reader = None
heater_controller = None
USE_DUMMY = False  # Set to True for testing without hardware


def fetch_esp_temps() -> tuple[Optional[float], Optional[float]]:
    """
    Fetch ambient temperatures from ESP sensor (Temp2IoT).

    Returns:
        Tuple of (shadow_temp, sun_temp) in °C, or (None, None) if unavailable
    """
    try:
        req = urllib.request.Request(config.ESP_URL)
        with urllib.request.urlopen(req, timeout=config.ESP_TIMEOUT) as response:
            data = json.loads(response.read().decode('utf-8'))

        shadow_temp = None
        sun_temp = None

        # Find both sensors in the response
        for sensor in data.get('sensors', []):
            name = sensor.get('name')
            if name == config.ESP_SENSOR_NAME_SHADOW:
                shadow_temp = float(sensor.get('value'))
            elif name == config.ESP_SENSOR_NAME_SUN:
                sun_temp = float(sensor.get('value'))

        if shadow_temp is None:
            logger.warning(f"Sensor '{config.ESP_SENSOR_NAME_SHADOW}' not found in ESP response")
        if sun_temp is None:
            logger.warning(f"Sensor '{config.ESP_SENSOR_NAME_SUN}' not found in ESP response")

        return shadow_temp, sun_temp

    except urllib.error.URLError as e:
        logger.warning(f"ESP fetch failed: {e}")
        return None, None
    except (json.JSONDecodeError, KeyError, TypeError, ValueError) as e:
        logger.warning(f"ESP parse error: {e}")
        return None, None


def get_data_quality() -> str:
    """Determine data quality based on age."""
    if data_cache['error']:
        return 'error'
    if data_cache['timestamp'] is None:
        return 'error'

    age = (datetime.now(timezone.utc) - data_cache['timestamp']).total_seconds()
    if age > config.STALE_THRESHOLD:
        return 'stale'
    return 'ok'


def background_reader():
    """Background thread that periodically reads sensor data and controls heater."""
    global reader, heater_controller

    logger.info("Background reader thread started")

    # Initialize reader
    if USE_DUMMY:
        from cloudwatcher_reader import DummyCloudWatcherReader
        reader = DummyCloudWatcherReader()
    else:
        from cloudwatcher_reader import CloudWatcherReader
        try:
            reader = CloudWatcherReader()
        except Exception as e:
            logger.error(f"Failed to initialize reader: {e}")
            logger.info("Falling back to dummy reader")
            from cloudwatcher_reader import DummyCloudWatcherReader
            reader = DummyCloudWatcherReader()

    # Initialize heater controller if enabled
    if config.HEATER_ENABLED:
        heater_controller = HeatingController(
            min_delta=config.HEATER_MIN_DELTA,
            max_delta=config.HEATER_MAX_DELTA,
            impulse_temp=config.HEATER_IMPULSE_TEMP,
            impulse_duration=config.HEATER_IMPULSE_DURATION,
            impulse_cycle=config.HEATER_IMPULSE_CYCLE,
        )
        logger.info("Heater controller initialized")
    else:
        logger.info("Heater control disabled in config")

    # Get device info once
    try:
        data_cache['device_info'] = reader.read_device_info()
        logger.info(f"Device info: {data_cache['device_info']}")
    except Exception as e:
        logger.warning(f"Could not read device info: {e}")

    # Main reading and control loop
    while True:
        try:
            # 1. Read all sensor data
            data = reader.read_all()

            if data:
                data_cache['timestamp'] = datetime.now(timezone.utc)
                data_cache['data'] = data
                data_cache['error'] = None
                logger.debug(f"Read data: sky={data.get('sky_temp_c')}°C, rain={data.get('rain_freq')}")

                # 2. Heater control (if enabled and data available)
                if heater_controller and 'rain_sensor_temp_c' in data:
                    # Fetch ambient temperatures from ESP
                    shadow_temp, sun_temp = fetch_esp_temps()
                    data_cache['esp_temp_shadow'] = shadow_temp
                    data_cache['esp_temp_sun'] = sun_temp

                    if shadow_temp is not None:
                        # Calculate and set PWM (using shadow sensor for heater control)
                        pwm, reason = heater_controller.calculate_pwm(
                            sensor_temp=data['rain_sensor_temp_c'],
                            ambient_temp=shadow_temp,
                            rain_freq=data.get('rain_freq'),
                            wet_threshold=config.WET_THRESHOLD,
                        )

                        # Send PWM to device
                        if reader.set_pwm(pwm):
                            logger.debug(f"Heater PWM={pwm}, reason={reason}")
                        else:
                            logger.warning(f"Failed to set PWM to {pwm}")

                        # Update cache with heater status
                        data_cache['heater_status'] = heater_controller.get_status()
                    else:
                        logger.debug("No ESP shadow temp available, skipping heater control")
            else:
                data_cache['error'] = 'No data received'
                logger.warning("No data received from sensor")

        except Exception as e:
            data_cache['error'] = str(e)
            logger.error(f"Error in main loop: {e}")

        time.sleep(config.READ_INTERVAL)


@app.route('/')
def dashboard():
    """Render HTML dashboard."""
    data = data_cache['data'] or {}

    # Format timestamp
    timestamp_str = ''
    if data_cache['timestamp']:
        timestamp_str = data_cache['timestamp'].strftime('%Y-%m-%d %H:%M:%S UTC')

    # Calculate uptime
    uptime = datetime.now(timezone.utc) - start_time
    uptime_str = str(uptime).split('.')[0]  # Remove microseconds

    # Rain status
    rain_status = 'Unbekannt'
    if 'is_raining' in data:
        if data['is_raining']:
            rain_status = 'Regen'
        elif data.get('is_wet'):
            rain_status = 'Feucht'
        else:
            rain_status = 'Trocken'

    # Light status
    light_status = 'Unbekannt'
    if 'is_daylight' in data:
        light_status = 'Tag' if data['is_daylight'] else 'Nacht'

    # MPSAS display
    mpsas_str = '--'
    if 'mpsas' in data:
        mpsas_str = f"{data['mpsas']:.2f}"

    return render_template('dashboard.html',
        sky_temp=data.get('sky_temp_c', '--'),
        ambient_temp='n/a (PWS)',  # Not available from this unit
        delta='n/a',  # Calculated in aggregator
        condition='n/a',  # Calculated in aggregator
        condition_icon='☁',
        rain_freq=data.get('rain_freq', '--'),
        rain_status=rain_status,
        ldr=mpsas_str,  # Using MPSAS instead of LDR
        ldr_label='MPSAS',  # Label for display
        light_status=light_status,
        timestamp=timestamp_str,
        uptime=uptime_str,
        quality=get_data_quality(),
        device_name=data_cache.get('device_info', {}).get('name', 'Unknown'),
        firmware=data_cache.get('device_info', {}).get('firmware', 'Unknown'),
    )


@app.route('/api/data')
def api_data():
    """
    Return JSON data for Weather-Aggregator integration.

    Note: ambient_temp_c is NOT provided - must come from PWS.
    Cloud condition should be calculated in the aggregator using:
    delta = pws_ambient_temp - sky_temp_c
    """
    data = data_cache['data'] or {}
    heater = data_cache.get('heater_status') or {}

    response = {
        'timestamp': data_cache['timestamp'].isoformat() if data_cache['timestamp'] else None,
        'sky_temp_c': data.get('sky_temp_c'),
        'rain_freq': data.get('rain_freq'),
        'is_raining': data.get('is_raining'),
        'is_wet': data.get('is_wet'),
        'heater_pwm': data.get('heater_pwm'),
        'rain_sensor_temp_c': data.get('rain_sensor_temp_c'),
        'light_sensor_raw': data.get('light_sensor_raw'),
        'mpsas': data.get('mpsas'),
        'is_daylight': data.get('is_daylight'),
        'uptime_s': int((datetime.now(timezone.utc) - start_time).total_seconds()),
        'quality': get_data_quality(),
        # ESP ambient temperatures
        'esp_temp_shadow_c': data_cache.get('esp_temp_shadow'),
        'esp_temp_sun_c': data_cache.get('esp_temp_sun'),
        # Heater control info
        'heater_control': {
            'enabled': config.HEATER_ENABLED,
            'ambient_temp_c': data_cache.get('esp_temp_shadow'),  # Shadow sensor used for control
            'target_pwm': heater.get('pwm'),
            'reason': heater.get('reason'),
        } if config.HEATER_ENABLED else None,
    }

    return jsonify(response)


@app.route('/api/raw')
def api_raw():
    """Return raw debug data."""
    return jsonify({
        'timestamp': data_cache['timestamp'].isoformat() if data_cache['timestamp'] else None,
        'data': data_cache['data'],
        'device_info': data_cache['device_info'],
        'error': data_cache['error'],
        'heater_status': data_cache.get('heater_status'),
        'esp_temp_shadow': data_cache.get('esp_temp_shadow'),
        'esp_temp_sun': data_cache.get('esp_temp_sun'),
        'uptime_s': int((datetime.now(timezone.utc) - start_time).total_seconds()),
        'config': {
            'serial_port': config.SERIAL_PORT,
            'baudrate': config.BAUDRATE,
            'read_interval': config.READ_INTERVAL,
            'thresholds': config.THRESHOLDS,
            'rain_threshold': config.RAIN_THRESHOLD,
            'wet_threshold': config.WET_THRESHOLD,
            'mpsas_daylight_threshold': config.MPSAS_DAYLIGHT_THRESHOLD,
            'heater_enabled': config.HEATER_ENABLED,
            'esp_url': config.ESP_URL,
            'esp_sensor_shadow': config.ESP_SENSOR_NAME_SHADOW,
            'esp_sensor_sun': config.ESP_SENSOR_NAME_SUN,
        }
    })


@app.route('/api/health')
def api_health():
    """Health check endpoint."""
    return jsonify({
        'status': 'ok' if get_data_quality() != 'error' else 'degraded',
        'quality': get_data_quality(),
        'uptime_s': int((datetime.now(timezone.utc) - start_time).total_seconds()),
    })


@app.route('/api/heater')
def api_heater():
    """Return heater control status for monitoring."""
    if not config.HEATER_ENABLED:
        return jsonify({
            'enabled': False,
            'message': 'Heater control disabled in config',
        })

    heater = data_cache.get('heater_status') or {}
    data = data_cache.get('data') or {}

    return jsonify({
        'enabled': True,
        'timestamp': data_cache['timestamp'].isoformat() if data_cache['timestamp'] else None,
        'esp_temp_shadow_c': data_cache.get('esp_temp_shadow'),
        'esp_temp_sun_c': data_cache.get('esp_temp_sun'),
        'sensor_temp_c': heater.get('sensor_temp'),
        'delta_c': heater.get('delta'),
        'target_pwm': heater.get('pwm'),
        'actual_pwm': data.get('heater_pwm'),
        'reason': heater.get('reason'),
        'in_impulse': heater.get('in_impulse'),
        'config': heater.get('config'),
    })


def main():
    global USE_DUMMY

    import sys

    # Check for --dummy flag
    if '--dummy' in sys.argv:
        USE_DUMMY = True
        logger.info("Running in dummy mode (no hardware)")

    # Start background reader thread
    reader_thread = threading.Thread(target=background_reader, daemon=True)
    reader_thread.start()

    # Give reader time to initialize
    time.sleep(2)

    # Start Flask server
    logger.info(f"Starting web server on {config.WEB_HOST}:{config.WEB_PORT}")
    app.run(host=config.WEB_HOST, port=config.WEB_PORT, debug=False, threaded=True)


if __name__ == '__main__':
    main()
