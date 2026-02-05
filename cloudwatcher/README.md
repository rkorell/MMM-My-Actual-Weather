# CloudWatcher Service

**Stand: 05.02.2026**

Web service for AAG CloudWatcher IR sky temperature sensor with integrated rain sensor heater control.

## Related Documentation

- [MMM-My-Actual-Weather README](../README.md) - Main module documentation
- [Heater Control Documentation](InterfaceDocu/Heizungssteuerung.md) - Detailed heater algorithm (German)
- [CloudWatcher Project Documentation](cloudwatcher-projekt-dokumentation.md) - Planning and setup (German)
- [WMO Code Derivation](../weather-aggregator/docs/WMO_Ableitungsmoeglichkeiten.md) - How CloudWatcher data is used

## Overview

Reads cloud cover and rain sensor data from CloudWatcher sensor via RS232 and provides:
- Web dashboard at `http://<ip>:5000/`
- JSON API at `http://<ip>:5000/api/data` (for Weather-Aggregator integration)
- Heater status at `http://<ip>:5000/api/heater` (monitoring)
- Debug endpoint at `http://<ip>:5000/api/raw`

**Important:** This service runs on a dedicated Raspberry Pi (172.23.56.60), NOT on the MagicMirror Pi.

## Architecture

```
┌─────────────────┐     ┌──────────────────┐     ┌─────────────────┐
│ ESP Temp Sensor │────▶│ CloudWatcher Pi  │────▶│ Weather-Aggregator│
│ 172.23.56.150   │HTTP │ 172.23.56.60     │HTTP │ 172.23.56.25    │
│ (Schatten+Sonne)│     │ cloudwatcher_    │     │ pws_receiver.php│
└─────────────────┘     │ service.py       │     └────────┬────────┘
                        │   │                             │
┌─────────────────┐     │   ├─ Read sensors              │ MQTT
│ CloudWatcher HW │◀───▶│   ├─ Control heater           ▼
│ RS232/USB       │     │   └─ Serve API        ┌─────────────────┐
└─────────────────┘     └──────────────────────▶│ MagicMirror     │
                                                │ 172.23.56.157   │
                                                └─────────────────┘
```

**ESP Temp2IoT Sensors:**
- **Schatten (Shadow):** Shaded sensor - used for heater control (more accurate ambient temp)
- **Sonne (Sun):** Sun-exposed sensor - for comparison/debugging

## Rain Sensor Heater Control

The rain sensor has an integrated heater to prevent condensation and dry the sensor after rain.

**Important:** The heater is NOT controlled by the CloudWatcher hardware itself - external software must manage the PWM. This service implements the manufacturer's algorithm.

### Control Strategy

1. **Normal operation (dry sensor):** Proportional control keeps sensor 4-8°C above ambient
2. **Wet sensor detected:** Impulse heating (full power for 60s) to dry quickly
3. **Temperature lookup:** PWM base value from manufacturer's Variations table

### Configuration (config.py)

```python
# Heater control settings (INDI/manufacturer defaults)
HEATER_ENABLED = True
HEATER_MIN_DELTA = 4.0      # Minimum temp difference sensor-ambient (°C)
HEATER_MAX_DELTA = 8.0      # Maximum temp difference sensor-ambient (°C)
HEATER_IMPULSE_TEMP = 10.0  # Ambient threshold (unused, kept for compatibility)
HEATER_IMPULSE_DURATION = 60   # Impulse duration (seconds)
HEATER_IMPULSE_CYCLE = 600     # Impulse cycle period (seconds)

# ESP Temperature Sensor (Temp2IoT)
ESP_URL = "http://172.23.56.150/api"
ESP_SENSOR_NAME_SHADOW = "Schatten"  # Shaded sensor - used for heater control
ESP_SENSOR_NAME_SUN = "Sonne"        # Sun-exposed sensor
```

### Monitoring

Check heater status via API:

```bash
curl http://172.23.56.60:5000/api/heater | jq
```

Response:
```json
{
  "enabled": true,
  "esp_temp_shadow_c": 2.6,
  "esp_temp_sun_c": 5.8,
  "sensor_temp_c": 10.6,
  "delta_c": 8.0,
  "target_pwm": 100,
  "actual_pwm": 100,
  "reason": "proportional (delta=8.0°C, factor=0.50)",
  "in_impulse": false
}
```

## Hardware Setup

1. Connect CloudWatcher to USB-RS232 adapter
2. Connect adapter to Raspberry Pi USB port
3. Device appears as `/dev/ttyUSB0` (check with `ls /dev/ttyUSB*`)

## Configuration

Edit `config.py` to adjust:
- Serial port and baudrate
- Cloud condition thresholds
- Polling interval (default: 10s for heater control)
- Heater parameters

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

## API Endpoints

### GET /api/data

Returns JSON for Weather-Aggregator:

```json
{
  "timestamp": "2026-02-04T19:30:00+00:00",
  "sky_temp_c": -18.45,
  "rain_freq": 2340,
  "is_wet": false,
  "is_raining": false,
  "heater_pwm": 100,
  "rain_sensor_temp_c": 10.5,
  "mpsas": 18.5,
  "is_daylight": false,
  "quality": "ok",
  "esp_temp_shadow_c": 2.5,
  "esp_temp_sun_c": 5.8,
  "heater_control": {
    "enabled": true,
    "ambient_temp_c": 2.5,
    "target_pwm": 100,
    "reason": "proportional"
  }
}
```

### GET /api/heater

Returns detailed heater control status (see Monitoring section above).

### GET /api/raw

Returns raw debug data including all configuration.

### GET /api/health

Returns service health status.

### Rain Sensor Fields

| Field | Description |
|-------|-------------|
| `rain_freq` | Capacitive sensor frequency: >2100 (dry), 1700-2100 (wet), <1700 (raining) |
| `heater_pwm` | Heater PWM 0-1023 (actual value from device) |
| `rain_sensor_temp_c` | Rain sensor NTC temperature (heater control feedback) |
| `is_wet` | True when rain_freq < 2100 |
| `is_raining` | True when rain_freq < 1700 |

### Cloud Conditions

Note: Cloud condition is calculated by Weather-Aggregator, not this service.

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

### Heater not working

1. Check ESP sensor availability: `curl http://172.23.56.150/api`
2. Verify HEATER_ENABLED=True in config.py
3. Check logs: `sudo journalctl -u cloudwatcher -f`

### Sensor overheating (delta > 15°C)

This indicates a bug in heater control. Check:
1. Is impulse heating active when sensor is dry? (bug)
2. Is the control loop running too frequently?

Normal delta should be 4-8°C when dry.

## Files

| File | Description |
|------|-------------|
| cloudwatcher_service.py | Main Flask application with heater control |
| cloudwatcher_reader.py | RS232 communication module |
| heating_controller.py | Heater control algorithm |
| config.py | Configuration settings |
| templates/dashboard.html | Web dashboard template |
| cloudwatcher.service | Systemd service file |

## Deployment

Files are maintained locally on MagicMirrorPi5 and deployed via scp:

```bash
# From MagicMirrorPi5
cd ~/MagicMirror/modules/MMM-My-Actual-Weather/cloudwatcher
scp *.py pi@172.23.56.60:/home/pi/cloudwatcher/

# Restart service
ssh pi@172.23.56.60 "sudo systemctl restart cloudwatcher"
```

## Author

Dr. Ralf Korell <r@korell.org>
