"""
CloudWatcher Web Service
Modified: 2026-01-25 15:30 - Initial creation

Flask web server providing:
- HTML dashboard at /
- JSON API at /api/data (for MagicMirror)
- Raw debug data at /api/raw

Runs background thread for periodic sensor readings.
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


def get_cloud_condition(delta: float, ambient_temp: float) -> str:
    """
    Determine cloud condition based on delta (ambient - sky temperature).
    Applies temperature correction for extreme ambient temperatures.
    """
    correction = 0
    if ambient_temp < config.TEMP_CORRECTION['cold_threshold']:
        correction = config.TEMP_CORRECTION['cold_correction']
    elif ambient_temp > config.TEMP_CORRECTION['hot_threshold']:
        correction = config.TEMP_CORRECTION['hot_correction']
    
    adjusted_delta = delta - correction
    
    if adjusted_delta > config.THRESHOLDS['clear']:
        return 'clear'
    elif adjusted_delta > config.THRESHOLDS['mostly_clear']:
        return 'mostly_clear'
    elif adjusted_delta > config.THRESHOLDS['partly_cloudy']:
        return 'partly_cloudy'
    elif adjusted_delta > config.THRESHOLDS['mostly_cloudy']:
        return 'mostly_cloudy'
    elif adjusted_delta > config.THRESHOLDS['cloudy']:
        return 'cloudy'
    else:
        return 'overcast'


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
                logger.debug(f"Read data: delta={data.get('delta_c')}°C")
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
    
    # Calculate derived values for display
    condition = None
    condition_icon = '?'
    if data.get('delta_c') is not None and data.get('ambient_temp_c') is not None:
        condition = get_cloud_condition(data['delta_c'], data['ambient_temp_c'])
        condition_icons = {
            'clear': '☀',
            'mostly_clear': '🌤',
            'partly_cloudy': '⛅',
            'mostly_cloudy': '🌥',
            'cloudy': '☁',
            'overcast': '☁',
        }
        condition_icon = condition_icons.get(condition, '?')
    
    # Format timestamp
    timestamp_str = ''
    if data_cache['timestamp']:
        timestamp_str = data_cache['timestamp'].strftime('%Y-%m-%d %H:%M:%S UTC')
    
    # Calculate uptime
    uptime = datetime.now(timezone.utc) - start_time
    uptime_str = str(uptime).split('.')[0]  # Remove microseconds
    
    # Rain and light status
    rain_status = 'Unknown'
    if 'is_raining' in data:
        rain_status = 'Raining' if data['is_raining'] else 'Dry'
    
    light_status = 'Unknown'
    if 'is_daylight' in data:
        light_status = 'Daylight' if data['is_daylight'] else 'Night'
    
    return render_template('dashboard.html',
        sky_temp=data.get('sky_temp_c', '--'),
        ambient_temp=data.get('ambient_temp_c', '--'),
        delta=data.get('delta_c', '--'),
        condition=condition or 'Unknown',
        condition_icon=condition_icon,
        rain_freq=data.get('rain_freq', '--'),
        rain_status=rain_status,
        ldr=data.get('ldr_kohm', '--'),
        light_status=light_status,
        timestamp=timestamp_str,
        uptime=uptime_str,
        quality=get_data_quality(),
        device_name=data_cache.get('device_info', {}).get('name', 'Unknown'),
        firmware=data_cache.get('device_info', {}).get('firmware', 'Unknown'),
    )


@app.route('/api/data')
def api_data():
    """Return JSON data for MagicMirror integration."""
    data = data_cache['data'] or {}
    
    response = {
        'timestamp': data_cache['timestamp'].isoformat() if data_cache['timestamp'] else None,
        'sky_temp_c': data.get('sky_temp_c'),
        'ambient_temp_c': data.get('ambient_temp_c'),
        'delta_c': data.get('delta_c'),
        'rain_freq': data.get('rain_freq'),
        'ldr_kohm': data.get('ldr_kohm'),
        'is_daylight': data.get('is_daylight'),
        'is_raining': data.get('is_raining'),
        'uptime_s': int((datetime.now(timezone.utc) - start_time).total_seconds()),
        'quality': get_data_quality(),
    }
    
    # Add cloud condition if data available
    if data.get('delta_c') is not None and data.get('ambient_temp_c') is not None:
        response['cloud_condition'] = get_cloud_condition(data['delta_c'], data['ambient_temp_c'])
    
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
