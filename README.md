# MMM-My-Actual-Weather

MagicMirror² module for current weather data from your own Personal Weather Station (PWS) with API fallback.

## Features

- **PWS Push Reception**: HTTP server receives data directly from your weather station (e.g., Ecowitt, Ambient Weather)
- **API Fallback**: Automatic switch to Wunderground API when PWS is unavailable
- **State Machine**: Clean coordination between PWS and API data sources
- **Additional Sensors**: Support for up to 2 additional temperature sensors
- **Temperature Color Gradient**: Temperature-dependent color display (configurable)
- **Day/Night Icons**: Automatic weather icon adjustment based on sunrise/sunset
- **Multilingual**: German and English

## Screenshot

![MMM-My-Actual-Weather](img/MyActualWeather.png)

## Layout

The module uses a simple 2-row table layout:

```
┌─────────────┬──────────────────────┐
│   WEATHER   │  Wind Info           │
│    ICON     │  TEMPERATURE         │
├─────────────┼──────────────────────┤
│   (empty)   │  Sensor 1            │
│             │  Sensor 2            │
│             │  Precipitation       │
└─────────────┴──────────────────────┘
```

- **Row 1**: Weather icon (left) next to wind info and temperature (right)
- **Row 2**: Sensor data and precipitation (right-aligned)
- Wind icon: `wi-strong-wind` from Weather Icons font

## Installation

```bash
cd ~/MagicMirror/modules
git clone https://github.com/rkorell/MMM-My-Actual-Weather.git
cd MMM-My-Actual-Weather
npm install
```

## Configuration

Add the following to your `config/config.js`:

```javascript
{
    module: "MMM-My-Actual-Weather",
    position: "top_right",
    config: {
        // Wunderground API (required)
        stationId: "YOUR_STATION_ID",
        apiKey: "YOUR_API_KEY",

        // Location for weather icon provider (required)
        latitude: 50.242,
        longitude: 6.603,

        // PWS Push Server
        pwsPushPort: 8000,           // HTTP server port (0 = disabled)
        pwsPushInterval: 60,         // Expected push interval in seconds

        // Additional Sensors
        showSensor1: true,
        showSensor2: true,
        sensor1Name: "Living Room",
        sensor2Name: "Office"
    }
}
```

## All Configuration Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| **API Settings** |
| `stationId` | String | - | Wunderground Station ID (required) |
| `apiKey` | String | - | Wunderground API Key (required) |
| `baseURL` | String | `https://api.weather.com/...` | Wunderground API URL |
| `openMeteoUrl` | String | `https://api.open-meteo.com/...` | Open-Meteo API URL |
| `latitude` | Number | `null` | Latitude (required) |
| `longitude` | Number | `null` | Longitude (required) |
| `units` | String | `"m"` | Units: `"m"` (metric), `"e"` (imperial) |
| `updateInterval` | Number | `300000` | Update interval in ms (5 min) |
| **Weather Icon Provider** |
| `weatherProvider` | String | `"openmeteo"` | Icon provider: `"openmeteo"` or `"wunderground"` |
| `wundergroundIconApiKey` | String | `null` | Separate API key for WUnderground icons (uses `apiKey` if not set) |
| **PWS Push Server** |
| `pwsPushPort` | Number | `8000` | HTTP server port (0 = disabled) |
| `pwsPushInterval` | Number | `60` | Expected push interval (seconds) |
| `pwsPushFallbackTimeout` | Number | `180` | Timeout for API fallback (seconds) |
| **Sensors** |
| `showSensor1` | Boolean | `false` | Show sensor 1 |
| `showSensor2` | Boolean | `false` | Show sensor 2 |
| `sensor1Name` | String | `"WoZi"` | Display name for sensor 1 |
| `sensor2Name` | String | `"Therapie"` | Display name for sensor 2 |
| `sensorTextColor` | String | `"lightgray"` | Text color for sensors |
| **Display** |
| `decimalPlacesTemp` | Number | `1` | Decimal places for temperature |
| `decimalPlacesPrecip` | Number | `1` | Decimal places for precipitation |
| `windColor` | String | `"white"` | Color for wind display |
| `precipitationColor` | String | `"white"` | Color for precipitation |
| `temperatureColor` | String | `"white"` | Temperature color (when `tempSensitive: false`) |
| `tempSensitive` | Boolean | `true` | Temperature-dependent coloring |
| `showDataSource` | Boolean | `true` | Show timestamp for PWS data |
| `animationSpeed` | Number | `1000` | Animation speed (ms) |
| `lang` | String | `config.language` | Language (de/en) |

## Temperature Color Gradient

The color gradient can be customized:

```javascript
tempColorGradient: [
    { temp: -17, color: "Dodgerblue" },
    { temp: -8, color: "Blue" },
    { temp: 2, color: "LightBlue" },
    { temp: 8, color: "Yellow" },
    { temp: 15, color: "Gold" },
    { temp: 18, color: "Orange" },
    { temp: 25, color: "Darkorange" },
    { temp: 28, color: "Orangered" },
    { temp: 32, color: "Red" }
]
```

## PWS Configuration

Configure your weather station to send data via HTTP POST to the MagicMirror:

- **URL**: `http://<MagicMirror-IP>:8000/data/report/`
- **Method**: POST
- **Format**: URL-encoded (standard for Ecowitt/Wunderground-compatible stations)

### Supported Fields

| Field | Description |
|-------|-------------|
| `tempf` | Outdoor temperature (°F) |
| `windspeedmph` | Wind speed (mph) |
| `winddir` | Wind direction (degrees) |
| `dailyrainin` | Daily precipitation (inches) |
| `temp1f` | Sensor 1 temperature (°F) |
| `humidity1` | Sensor 1 humidity (%) |
| `temp2f` | Sensor 2 temperature (°F) |
| `humidity2` | Sensor 2 humidity (%) |
| `dateutc` | Timestamp |

## State Machine

The module uses a state machine for data source coordination:

```
INITIALIZING → (PWS push received) → PWS_ACTIVE
     ↓ (3 sec timeout)
WAITING_FOR_PWS → (PWS push received) → PWS_ACTIVE
     ↓ (3x push interval timeout)
API_ONLY → (60 min recheck) → INITIALIZING
```

- **INITIALIZING**: Waiting for first PWS push (max 3 seconds)
- **PWS_ACTIVE**: PWS delivers data, API only for icons
- **WAITING_FOR_PWS**: Display API data, wait for PWS
- **API_ONLY**: API data only, periodic recheck

## Dependencies

- `node-fetch` - HTTP requests

## Architecture

### Data Sources

| Field | PWS Push | PWS API v2 | WUnderground v3 | Open-Meteo |
|-------|----------|------------|-----------------|------------|
| Temperature | ✅ tempf (°F) | ✅ metric.temp | ✅ temperature | ✅ temperature |
| Wind Speed | ✅ windspeedmph | ✅ metric.windSpeed | ✅ windSpeed | ✅ windspeed |
| Wind Direction | ✅ winddir (°) | ✅ winddir (°) | ✅ windDirectionCardinal | ✅ winddirection |
| Precipitation | ✅ dailyrainin | ✅ precipTotal | ✅ precip24Hour | ❌ |
| Day/Night | ❌ | ❌ | ✅ dayOrNight | ✅ is_day |
| Weather Icon | ❌ | ❌ | ✅ iconCode | ✅ weathercode |

### Local Calculations

| Calculation | Purpose |
|-------------|---------|
| `fahrenheitToCelsius()` | PWS Push sends °F → convert to °C |
| `mphToMs()` | PWS Push sends mph → convert to m/s |
| `inchesToMm()` | PWS Push sends inches → convert to mm |
| `getWindDirection()` | Convert degrees → cardinal direction (N, NE, etc.) |

### Data Flow

```
PWS Push (:8000)                    PWS API v2 (Fallback)
      │                                    │
      ▼                                    ▼
 processPwsPush()                  loadApiDataInBackground()
 [Unit conversion]                         │
      │                                    │
      ▼                                    ▼
   pwsData ──────────────────────► apiDataCache
                     │
                     ▼
              sendToFrontend()
              + getWindDirection()
              + weatherIconClass
                     │
                     ▼
              WEATHER_DATA → Frontend
```

## Author

Dr. Ralf Korell, 2025/2026

## License

MIT
