# CloudWatcher Service

Web service for AAG CloudWatcher IR sky temperature sensor.

## Overview

Reads cloud cover data from CloudWatcher sensor via RS232 and provides:
- Web dashboard at `http://<ip>:5000/`
- JSON API at `http://<ip>:5000/api/data` (for MagicMirror integration)
- Debug endpoint at `http://<ip>:5000/api/raw`

## Hardware Setup

1. Connect CloudWatcher to USB-RS232 adapter
2. Connect adapter to Raspberry Pi USB port
3. Device appears as `/dev/ttyUSB0` (check with `ls /dev/ttyUSB*`)

## Configuration

Edit `config.py` to adjust:
- Serial port and baudrate
- Cloud condition thresholds
- Polling interval

## Running

### Manual (for testing)

```bash
# With hardware
python3 cloudwatcher_service.py

# Without hardware (dummy data)
python3 cloudwatcher_service.py --dummy
```

### As systemd service

```bash
# Install service
sudo cp cloudwatcher.service /etc/systemd/system/
sudo systemctl daemon-reload

# Enable auto-start
sudo systemctl enable cloudwatcher

# Start service
sudo systemctl start cloudwatcher

# Check status
sudo systemctl status cloudwatcher

# View logs
sudo journalctl -u cloudwatcher -f
```

## API

### GET /api/data

Returns JSON for MagicMirror:

```json
{
  "timestamp": "2026-01-25T15:30:00+00:00",
  "sky_temp_c": -18.45,
  "ambient_temp_c": 2.30,
  "delta_c": 20.75,
  "cloud_condition": "mostly_clear",
  "rain_freq": 2340,
  "ldr_kohm": 125.3,
  "is_daylight": false,
  "is_raining": false,
  "uptime_s": 3600,
  "quality": "ok"
}
```

### Cloud Conditions

Based on delta (ambient - sky temperature):

| Delta | Condition |
|-------|-----------|
| > 25°C | clear |
| 20-25°C | mostly_clear |
| 15-20°C | partly_cloudy |
| 10-15°C | mostly_cloudy |
| 5-10°C | cloudy |
| < 5°C | overcast |

## Troubleshooting

### No data / Connection error

1. Check USB adapter: `ls /dev/ttyUSB*`
2. Check permissions: user must be in `dialout` group
3. Try different baudrate (9600 or 19200)

### Inaccurate cloud readings

Adjust thresholds in `config.py`. Values may need calibration for location and season.

## Files

| File | Description |
|------|-------------|
| cloudwatcher_service.py | Main Flask application |
| cloudwatcher_reader.py | RS232 communication module |
| config.py | Configuration settings |
| templates/dashboard.html | Web dashboard template |
| cloudwatcher.service | Systemd service file |

## Author

Dr. Ralf Korell <r@korell.org>
