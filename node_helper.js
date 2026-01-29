// node_helper.js for MMM-My-Actual-Weather
// Modified: 2026-01-14, 14:30 - AP 1.1 + 1.2: Added HTTP server for PWS push data, parsing, unit conversion, and fallback logic
// Modified: 2026-01-14, 16:35 - AP 1.6: Immediate frontend notification on PWS push, debug log for timestamp
// Modified: 2026-01-15, 14:30 - AP 2: Open-Meteo Caching, PWS-Verbindungsstatus, Logging auf debug
// Modified: 2026-01-15, 15:45 - AP 2: Design-Fix: processPwsPush() sendet direkt ans Frontend (keine Race Condition mehr)
// Modified: 2026-01-15, 16:15 - AP 2: State Machine für saubere PWS/API Koordination
// Modified: 2026-01-15, 17:00 - AP 2: Bug-Fixes: API-Daten bei WAITING_FOR_PWS/API_ONLY senden und aktualisieren
// Modified: 2026-01-16, 10:30 - AP 3: SVG-Hack entfernt (Wind-Icon jetzt via Font)
// Modified: 2026-01-18, 11:00 - AP 4: Dual weather provider support (WUnderground/OpenMeteo), SunCalc removed, lookup tables
// Modified: 2026-01-28, 18:00 - AP 46: Switched to Weather-Aggregator API polling, removed PWS server and state machine
// Modified: 2026-01-28, 20:15 - AP 46: Added Wunderground fallback when aggregator data > 180s old
// Modified: 2026-01-29, 21:10 - Added WMO codes 4, 10, 11, 68, 69 to WmoToWeatherIcon mapping

const NodeHelper = require("node_helper");
const fetch = require("node-fetch");

// ==================== WMO CODE → WEATHER ICON MAPPING ====================
// Open-Meteo WMO Weather Codes → weather-icons class mapping
// https://www.open-meteo.com/en/docs#weathervariables
// Day/night variants: [day, night]
const WmoToWeatherIcon = {
    0:  ["wi-day-sunny", "wi-night-clear"],              // Clear sky
    1:  ["wi-day-sunny-overcast", "wi-night-partly-cloudy"], // Mainly clear
    2:  ["wi-day-cloudy", "wi-night-cloudy"],            // Partly cloudy
    3:  ["wi-day-cloudy-high", "wi-night-cloudy-high"],  // Overcast
    4:  ["wi-day-haze", "wi-mist-night"],                // Haze
    10: ["wi-mist", "wi-mist-night"],                    // Mist
    11: ["wi-fog", "wi-fog-night"],                      // Shallow fog
    45: ["wi-day-fog", "wi-night-fog"],                  // Fog
    48: ["wi-freezing-fog", "wi-freezing-fog-night"],    // Depositing rime fog
    51: ["wi-drizzle", "wi-drizzle-night"],              // Light drizzle
    53: ["wi-heavy-drizzle", "wi-heavy-drizzle-night"],  // Moderate drizzle
    55: ["wi-heavy-freezing-drizzle", "wi-heavy-freezing-drizzle-night"], // Dense drizzle
    56: ["wi-freezing-drizzle", "wi-freezing-drizzle-night"], // Light freezing drizzle
    57: ["wi-heavy-freezing-drizzle", "wi-heavy-freezing-drizzle-night"], // Dense freezing drizzle
    61: ["wi-day-rain-mix", "wi-night-rain-mix"],        // Slight rain
    63: ["wi-day-rain", "wi-night-rain"],                // Moderate rain
    65: ["wi-extreme-rain", "wi-extreme-rain-night"],    // Heavy rain
    66: ["wi-freezing-rain", "wi-freezing-rain-night"],  // Light freezing rain
    67: ["wi-freezing-rain", "wi-freezing-rain-night"],  // Heavy freezing rain
    68: ["wi-day-sleet", "wi-night-sleet"],              // Sleet light
    69: ["wi-day-sleet-storm", "wi-night-sleet-storm"],  // Sleet heavy
    71: ["wi-day-snow", "wi-night-snow"],                // Slight snow
    73: ["wi-day-snow", "wi-night-snow"],                // Moderate snow
    75: ["wi-day-snow-wind", "wi-night-snow-wind"],      // Heavy snow
    77: ["wi-day-snow", "wi-night-snow"],                // Snow grains
    80: ["wi-day-showers", "wi-night-showers"],          // Slight rain showers
    81: ["wi-day-storm-showers", "wi-night-storm-showers"], // Moderate rain showers
    82: ["wi-extreme-rain", "wi-extreme-rain-night"],    // Violent rain showers
    85: ["wi-day-snow", "wi-night-snow"],                // Slight snow showers
    86: ["wi-day-snow-wind", "wi-night-snow-wind"],      // Heavy snow showers
    95: ["wi-day-thunderstorm", "wi-night-thunderstorm"], // Thunderstorm
    96: ["wi-thunderstorms-with-hail", "wi-thunderstorms-with-hail-night"], // Thunderstorm with slight hail
    99: ["wi-thunderstorms-with-hail", "wi-thunderstorms-with-hail-night"]  // Thunderstorm with heavy hail
};

// ==================== WUNDERGROUND ICON CODE MAPPING ====================
// Weather Underground (TWC) iconCode → weather-icons class mapping
// https://weather.com/swagger-docs/ui/sun/v3/sunV3CurrentsOnDemand.json
const WUndergroundToWi = {
    0:  { icon: "wi-tornado", daySpecific: false },
    1:  { icon: "wi-tropical-storm", daySpecific: false },
    2:  { icon: "wi-tropical-storm", daySpecific: false },
    3:  { icon: "wi-thunderstorm", daySpecific: false },
    4:  { icon: "wi-thunderstorms-with-hail", daySpecific: false },
    5:  { icon: "wi-day-rain-mix", nightIcon: "wi-night-rain-mix", daySpecific: false },
    6:  { icon: "wi-day-sleet", nightIcon: "wi-night-sleet", daySpecific: false },
    7:  { icon: "wi-day-sleet", nightIcon: "wi-night-sleet", daySpecific: false },
    8:  { icon: "wi-freezing-drizzle", nightIcon: "wi-freezing-drizzle-night", daySpecific: false },
    9:  { icon: "wi-drizzle", nightIcon: "wi-drizzle-night", daySpecific: false },
    10: { icon: "wi-freezing-rain", nightIcon: "wi-freezing-rain-night", daySpecific: false },
    11: { icon: "wi-showers", nightIcon: "wi-showers-night", daySpecific: false },
    12: { icon: "wi-day-rain", nightIcon: "wi-night-rain", daySpecific: false },
    13: { icon: "wi-day-snow", nightIcon: "wi-night-snow", daySpecific: false },
    14: { icon: "wi-day-snow", nightIcon: "wi-night-snow", daySpecific: false },
    15: { icon: "wi-day-snow-wind", nightIcon: "wi-night-snow-wind", daySpecific: false },
    16: { icon: "wi-snow", nightIcon: "wi-snow-night", daySpecific: false },
    17: { icon: "wi-day-hail", nightIcon: "wi-night-hail", daySpecific: false },
    18: { icon: "wi-sleet", nightIcon: "wi-sleet-night", daySpecific: false },
    19: { icon: "wi-dust", nightIcon: "wi-dust-night", daySpecific: false },
    20: { icon: "wi-fog", nightIcon: "wi-fog-night", daySpecific: false },
    21: { icon: "wi-day-haze", nightIcon: "wi-mist-night", daySpecific: false },
    22: { icon: "wi-day-haze", nightIcon: "wi-mist-night", daySpecific: false },
    23: { icon: "wi-day-windy", nightIcon: "wi-night-windy", daySpecific: false },
    24: { icon: "wi-day-windy", nightIcon: "wi-night-windy", daySpecific: false },
    25: { icon: "wi-cold", nightIcon: "wi-cold-night", daySpecific: false },
    26: { icon: "wi-cloudy", nightIcon: "wi-cloudy-night", daySpecific: false },
    27: { icon: "wi-night-cloudy", daySpecific: true },
    28: { icon: "wi-day-cloudy", daySpecific: true },
    29: { icon: "wi-night-partly-cloudy", daySpecific: true },
    30: { icon: "wi-day-sunny-overcast", daySpecific: true },
    31: { icon: "wi-night-clear", daySpecific: true },
    32: { icon: "wi-day-sunny", daySpecific: true },
    33: { icon: "wi-night-clear", daySpecific: true },
    34: { icon: "wi-day-sunny", daySpecific: true },
    35: { icon: "wi-thunderstorms-with-hail", nightIcon: "wi-thunderstorms-with-hail-night", daySpecific: false },
    36: { icon: "wi-hot", nightIcon: "wi-hot-night", daySpecific: false },
    37: { icon: "wi-day-thunderstorm", nightIcon: "wi-night-thunderstorm", daySpecific: false },
    38: { icon: "wi-day-thunderstorm", nightIcon: "wi-night-thunderstorm", daySpecific: false },
    39: { icon: "wi-day-showers", daySpecific: true },
    40: { icon: "wi-extreme-rain", nightIcon: "wi-extreme-rain-night", daySpecific: false },
    41: { icon: "wi-day-snow", daySpecific: true },
    42: { icon: "wi-extreme-snow", nightIcon: "wi-extreme-snow-night", daySpecific: false },
    43: { icon: "wi-blizzard", nightIcon: "wi-blizzard-night", daySpecific: false },
    44: { icon: "wi-na", daySpecific: true },
    45: { icon: "wi-night-showers", daySpecific: true },
    46: { icon: "wi-night-snow", daySpecific: true },
    47: { icon: "wi-night-thunderstorm", daySpecific: true }
};

module.exports = NodeHelper.create({
    start: function() {
        console.log("MMM-My-Actual-Weather: Starting node_helper (Aggregator mode)");
        this.configData = null;
        this.pollingTimer = null;
        this.lastData = null;
    },

    socketNotificationReceived: function(notification, payload) {
        if (notification === "FETCH_WEATHER") {
            this.configData = payload;

            // Start polling if not already running
            if (!this.pollingTimer) {
                this.startPolling();
            }

            // Fetch immediately on first request
            this.fetchAggregatorData();
        }
    },

    // Start polling the aggregator API
    startPolling: function() {
        const self = this;
        const interval = this.configData.updateInterval || 60000; // Default: 60 seconds

        console.log(`MMM-My-Actual-Weather: Starting API polling every ${interval/1000}s`);

        this.pollingTimer = setInterval(function() {
            self.fetchAggregatorData();
        }, interval);
    },

    // Fetch data from weather-aggregator API
    fetchAggregatorData: async function() {
        if (!this.configData) return;

        const config = this.configData;
        const apiUrl = config.aggregatorApiUrl;

        try {
            const response = await fetch(apiUrl, { timeout: 10000 });

            if (!response.ok) {
                console.error(`MMM-My-Actual-Weather: API error ${response.status}`);
                return;
            }

            const data = await response.json();

            if (data.error) {
                console.error(`MMM-My-Actual-Weather: API returned error: ${data.error}`);
                return;
            }

            // Check data freshness
            const dataAge = data.data_age_s || 0;
            const isStale = dataAge > 300; // More than 5 minutes old

            if (isStale) {
                console.log(`MMM-My-Actual-Weather: Data is stale (${dataAge}s old)`);
            }

            // Map WMO code to weather icon
            const wmoCode = data.wmo_code;
            const isDay = data.is_daylight !== false; // Default to day if unknown
            const iconClass = this.getWeatherIcon(wmoCode, isDay);

            // Prepare weather data for frontend
            const weatherData = {
                temp: data.temp_c,
                windSpeed: data.wind_speed_ms,
                precipTotal: data.precip_today_mm,
                windDirection: this.getWindDirection(data.wind_dir_deg, config.lang),
                temp1: data.temp1_c,
                temp2: data.temp2_c,
                humidity1: null, // Not in aggregator yet
                humidity2: null,
                timestamp: data.timestamp ? this.formatTimestamp(data.timestamp) : null,
                isLocalData: true,
                waitingForPws: false,
                weatherCode: wmoCode,
                isDay: isDay,
                weatherIconClass: iconClass,
                condition: data.condition,
                skyTemp: data.sky_temp_c,
                delta: data.delta_c,
                dataAge: dataAge,
                isStale: isStale
            };

            // Check if fallback is needed
            const fallbackTimeout = config.aggregatorFallbackTimeout || 180;
            if (dataAge > fallbackTimeout) {
                console.log(`MMM-My-Actual-Weather: Data too old (${dataAge}s > ${fallbackTimeout}s), using Wunderground fallback`);
                await this.fetchWundergroundFallback();
                return;
            }

            this.lastData = weatherData;

            console.log(`MMM-My-Actual-Weather: Data received - temp=${data.temp_c}°C, wmo=${wmoCode} (${data.condition}), icon=${iconClass}`);

            // Send to frontend
            this.sendSocketNotification("WEATHER_DATA", weatherData);

        } catch (error) {
            console.error("MMM-My-Actual-Weather: Error fetching aggregator API:", error.message);

            // Try Wunderground fallback
            console.log("MMM-My-Actual-Weather: Aggregator failed, trying Wunderground fallback");
            await this.fetchWundergroundFallback();
        }
    },

    // Fallback: Fetch data from Wunderground APIs
    fetchWundergroundFallback: async function() {
        const config = this.configData;
        if (!config.stationId || !config.apiKey) {
            console.error("MMM-My-Actual-Weather: Wunderground fallback requires stationId and apiKey");
            if (this.lastData) {
                this.lastData.isStale = true;
                this.sendSocketNotification("WEATHER_DATA", this.lastData);
            }
            return;
        }

        try {
            // Fetch PWS data from v2 API
            const pwsUrl = `${config.baseURL}&units=${config.units}&numericPrecision=${config.numericPrecision}&stationId=${config.stationId}&apiKey=${config.apiKey}`;
            const pwsResponse = await fetch(pwsUrl, { timeout: 10000 });

            if (!pwsResponse.ok) {
                throw new Error(`PWS API error: ${pwsResponse.status}`);
            }

            const pwsData = await pwsResponse.json();
            const obs = pwsData.observations?.[0];

            if (!obs) {
                throw new Error("No observations in PWS response");
            }

            // Get metric or imperial values
            const metric = obs.metric || obs.imperial;

            // Fetch icon from v3 API
            let iconClass = "wi-day-cloudy";
            let isDay = true;
            try {
                const iconData = await this.fetchWundergroundIcon(config);
                if (iconData) {
                    iconClass = iconData.iconClass;
                    isDay = iconData.isDay;
                }
            } catch (iconError) {
                console.error("MMM-My-Actual-Weather: Icon fetch failed:", iconError.message);
            }

            // Prepare weather data for frontend
            const weatherData = {
                temp: metric.temp,
                windSpeed: metric.windSpeed ? metric.windSpeed / 3.6 : null, // km/h to m/s
                precipTotal: metric.precipTotal,
                windDirection: this.getWindDirection(obs.winddir, config.lang),
                temp1: null, // Not available from Wunderground
                temp2: null,
                humidity1: null,
                humidity2: null,
                timestamp: obs.obsTimeLocal ? this.formatTimestamp(obs.obsTimeLocal) : null,
                isLocalData: false, // Wunderground fallback
                waitingForPws: false,
                weatherCode: null,
                isDay: isDay,
                weatherIconClass: iconClass,
                condition: "wunderground_fallback",
                skyTemp: null,
                delta: null,
                dataAge: null,
                isStale: true
            };

            this.lastData = weatherData;

            console.log(`MMM-My-Actual-Weather: Wunderground fallback - temp=${metric.temp}°C, icon=${iconClass}`);

            this.sendSocketNotification("WEATHER_DATA", weatherData);

        } catch (error) {
            console.error("MMM-My-Actual-Weather: Wunderground fallback failed:", error.message);

            // If we have cached data, resend it
            if (this.lastData) {
                this.lastData.isStale = true;
                this.sendSocketNotification("WEATHER_DATA", this.lastData);
            }
        }
    },

    // Fetch weather icon from Wunderground v3 API
    fetchWundergroundIcon: async function(config) {
        const iconApiKey = config.wundergroundIconApiKey || config.apiKey;
        const iconUrl = `https://api.weather.com/v3/wx/observations/current?geocode=${config.latitude},${config.longitude}&format=json&units=m&language=${config.lang || 'de'}-DE&apiKey=${iconApiKey}`;

        const response = await fetch(iconUrl, { timeout: 10000 });

        if (!response.ok) {
            throw new Error(`Icon API error: ${response.status}`);
        }

        const data = await response.json();
        const iconCode = data.iconCode;
        const isDay = data.dayOrNight === "D";
        const iconClass = this.getWeatherIconWUnderground(iconCode, isDay);

        return { iconClass, isDay };
    },

    // Get weather icon class from Wunderground iconCode
    getWeatherIconWUnderground: function(iconCode, isDay) {
        const mapping = WUndergroundToWi[iconCode];
        if (!mapping) {
            return isDay ? "wi-day-cloudy" : "wi-night-cloudy";
        }

        if (mapping.daySpecific) {
            return mapping.icon;
        }

        if (isDay) {
            return mapping.icon;
        } else {
            return mapping.nightIcon || mapping.icon;
        }
    },

    // Get weather icon class from WMO code
    getWeatherIcon: function(wmoCode, isDay) {
        const mapping = WmoToWeatherIcon[wmoCode];
        if (mapping) {
            return isDay ? mapping[0] : mapping[1];
        }
        // Default icon for unknown codes
        return isDay ? "wi-day-cloudy" : "wi-night-cloudy";
    },

    // Format ISO timestamp to HH:MM
    formatTimestamp: function(isoTimestamp) {
        try {
            const date = new Date(isoTimestamp);
            const hours = date.getHours().toString().padStart(2, '0');
            const minutes = date.getMinutes().toString().padStart(2, '0');
            return `${hours}:${minutes}`;
        } catch (e) {
            return null;
        }
    },

    // Convert wind direction (degrees to cardinal direction)
    getWindDirection: function(degree, lang) {
        if (degree === null || degree === undefined) return "";

        let directions;
        if (lang === "de") {
            directions = [
                "N", "NNO", "NO", "ONO", "O", "OSO", "SO", "SSO",
                "S", "SSW", "SW", "WSW", "W", "WNW", "NW", "NNW"
            ];
        } else {
            directions = [
                "N", "NNE", "NE", "ENE", "E", "ESE", "SE", "SSE",
                "S", "SSW", "SW", "WSW", "W", "WNW", "NW", "NNW"
            ];
        }
        const normalizedDegree = (degree % 360 + 360) % 360;
        const index = Math.round(normalizedDegree / (360 / directions.length)) % directions.length;
        return directions[index];
    }
});
