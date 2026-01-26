# CloudWatcher Configuration
# Modified: 2026-01-25 15:30 - Initial creation

# Serial port settings
SERIAL_PORT = "/dev/ttyUSB0"
BAUDRATE = 9600  # Try 19200 if 9600 doesn't work

# Web server settings
WEB_HOST = "0.0.0.0"
WEB_PORT = 5000

# Polling interval (seconds)
READ_INTERVAL = 30

# Cloud condition thresholds (delta = ambient - sky temperature)
# These are initial values and may need calibration for the location
THRESHOLDS = {
    "clear": 25,          # delta > 25°C = clear sky
    "mostly_clear": 20,   # delta > 20°C = mostly clear
    "partly_cloudy": 15,  # delta > 15°C = partly cloudy
    "mostly_cloudy": 10,  # delta > 10°C = mostly cloudy
    "cloudy": 5,          # delta > 5°C = cloudy
    # delta <= 5°C = overcast
}

# Temperature correction for extreme ambient temperatures
TEMP_CORRECTION = {
    "cold_threshold": -10,  # Below this: subtract from thresholds
    "cold_correction": -3,
    "hot_threshold": 25,    # Above this: add to thresholds
    "hot_correction": 3,
}

# Rain sensor threshold (frequency)
RAIN_THRESHOLD = 2000  # Below this = rain detected

# LDR threshold for daylight detection (kOhm)
LDR_DAYLIGHT_THRESHOLD = 50  # Below this = daylight

# NTC calibration values for ambient temperature calculation
NTC_PULLUP_RESISTANCE = 9.9   # kOhm
NTC_RES_AT_25 = 10.0          # kOhm
NTC_BETA = 3811

# LDR pullup resistance
LDR_PULLUP_RESISTANCE = 56    # kOhm

# Data quality settings
STALE_THRESHOLD = 300  # seconds - data older than this is marked "stale"
