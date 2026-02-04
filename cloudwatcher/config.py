# CloudWatcher Configuration
# Modified: 2026-01-25 15:30 - Initial creation
# Modified: 2026-01-28 19:00 - Updated for Rain Sensor Type C, added MPSAS thresholds
# Modified: 2026-02-03 14:00 - Adjusted WET_THRESHOLD from 2000 to 2100 (RTS2 calibration)
# Modified: 2026-02-04 20:50 - Added heater control config, reduced READ_INTERVAL to 10s

# Serial port settings
SERIAL_PORT = "/dev/ttyUSB0"
BAUDRATE = 9600

# Web server settings
WEB_HOST = "0.0.0.0"
WEB_PORT = 5000

# Polling interval (seconds) - also used for heater control loop
READ_INTERVAL = 10

# ESP Temperature Sensor (Temp2IoT)
# Used for ambient temperature for heater control
ESP_URL = "http://172.23.56.150/api"
ESP_SENSOR_NAME = "Schatten"  # Sensor name in ESP response
ESP_TIMEOUT = 5  # seconds

# Heater control settings (INDI/manufacturer defaults)
HEATER_ENABLED = True
HEATER_MIN_DELTA = 4.0      # Minimum temp difference sensor-ambient (°C)
HEATER_MAX_DELTA = 8.0      # Maximum temp difference sensor-ambient (°C)
HEATER_IMPULSE_TEMP = 10.0  # Below this ambient temp, use impulse heating (°C)
HEATER_IMPULSE_DURATION = 60   # Impulse duration (seconds)
HEATER_IMPULSE_CYCLE = 600     # Impulse cycle period (seconds)

# Cloud condition thresholds (delta = ambient - sky temperature)
# Note: ambient_temp must come from external source (PWS), not from CloudWatcher
# These are initial values and may need calibration for the location
THRESHOLDS = {
    "clear": 25,          # delta > 25°C = clear sky
    "mostly_clear": 20,   # delta > 20°C = mostly clear
    "partly_cloudy": 15,  # delta > 15°C = partly cloudy
    "mostly_cloudy": 10,  # delta > 10°C = mostly cloudy
    "cloudy": 5,          # delta > 5°C = cloudy
    # delta <= 5°C = overcast
}

# Rain sensor thresholds (Type C - no calibration required)
# Higher frequency = drier sensor
RAIN_THRESHOLD = 1700   # Below this = raining
WET_THRESHOLD = 2100    # Below this = wet/damp (but not necessarily raining)
# Note: Dry = freq > 2100, Wet = 1700-2100, Rain = freq < 1700

# MPSAS (Sky Quality) thresholds
# Higher MPSAS = darker sky
# Typical values: 5-10 (daylight), 17-18 (city night), 21-22 (dark site)
MPSAS_DAYLIGHT_THRESHOLD = 10  # Below this = daylight

# Data quality settings
STALE_THRESHOLD = 300  # seconds - data older than this is marked "stale"
