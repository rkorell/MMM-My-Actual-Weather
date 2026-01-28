"""
CloudWatcher Web Service
Modified: 2026-01-25 15:30 - Initial creation
Modified: 2026-01-28 19:00 - Updated for new sensor config (no ambient temp)

Flask web server providing:
- HTML dashboard at /
- JSON API at /api/data (for MagicMirror/Weather-Aggregator)
- Raw debug data at /api/raw

Note: This unit has no ambient temperature sensor.
Ambient temp must be provided externally (e.g., from PWS).
Cloud condition calculation should be done in the Weather-Aggregator.
"""

import threading
import time
import logging
from datetime import datetime, timezone
from typing import Dict, Optional

from flask import Flask, jsonify, render_template

import config

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
}
start_time = datetime.now(timezone.utc)

# Reader instance (initialized in main)
reader = None
USE_DUMMY = False  # Set to True for testing without hardware


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
    """Background thread that periodically reads sensor data."""
    global reader

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

    # Get device info once
    try:
        data_cache['device_info'] = reader.read_device_info()
        logger.info(f"Device info: {data_cache['device_info']}")
    except Exception as e:
        logger.warning(f"Could not read device info: {e}")

    # Main reading loop
    while True:
        try:
            data = reader.read_all()

            if data:
                data_cache['timestamp'] = datetime.now(timezone.utc)
                data_cache['data'] = data
                data_cache['error'] = None
                logger.debug(f"Read data: sky={data.get('sky_temp_c')}°C, rain={data.get('rain_freq')}")
            else:
                data_cache['error'] = 'No data received'
                logger.warning("No data received from sensor")

        except Exception as e:
            data_cache['error'] = str(e)
            logger.error(f"Error reading sensor: {e}")

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

    response = {
        'timestamp': data_cache['timestamp'].isoformat() if data_cache['timestamp'] else None,
        'sky_temp_c': data.get('sky_temp_c'),
        'rain_freq': data.get('rain_freq'),
        'is_raining': data.get('is_raining'),
        'is_wet': data.get('is_wet'),
        'light_sensor_raw': data.get('light_sensor_raw'),
        'mpsas': data.get('mpsas'),
        'is_daylight': data.get('is_daylight'),
        'uptime_s': int((datetime.now(timezone.utc) - start_time).total_seconds()),
        'quality': get_data_quality(),
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
        'uptime_s': int((datetime.now(timezone.utc) - start_time).total_seconds()),
        'config': {
            'serial_port': config.SERIAL_PORT,
            'baudrate': config.BAUDRATE,
            'read_interval': config.READ_INTERVAL,
            'thresholds': config.THRESHOLDS,
            'rain_threshold': config.RAIN_THRESHOLD,
            'wet_threshold': config.WET_THRESHOLD,
            'mpsas_daylight_threshold': config.MPSAS_DAYLIGHT_THRESHOLD,
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
