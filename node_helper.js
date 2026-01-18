// node_helper.js for MMM-My-Actual-Weather
// Modified: 2026-01-14, 14:30 - AP 1.1 + 1.2: Added HTTP server for PWS push data, parsing, unit conversion, and fallback logic
// Modified: 2026-01-14, 16:35 - AP 1.6: Immediate frontend notification on PWS push, debug log for timestamp
// Modified: 2026-01-15, 14:30 - AP 2: Open-Meteo Caching, PWS-Verbindungsstatus, Logging auf debug
// Modified: 2026-01-15, 15:45 - AP 2: Design-Fix: processPwsPush() sendet direkt ans Frontend (keine Race Condition mehr)
// Modified: 2026-01-15, 16:15 - AP 2: State Machine für saubere PWS/API Koordination
// Modified: 2026-01-15, 17:00 - AP 2: Bug-Fixes: API-Daten bei WAITING_FOR_PWS/API_ONLY senden und aktualisieren
// Modified: 2026-01-16, 10:30 - AP 3: SVG-Hack entfernt (Wind-Icon jetzt via Font)
// Modified: 2026-01-18, 11:00 - AP 4: Dual weather provider support (WUnderground/OpenMeteo), SunCalc removed, lookup tables

const NodeHelper = require("node_helper");
const fetch = require("node-fetch"); // For API requests
const http = require("http"); // For PWS push server

// ==================== WEATHER ICON LOOKUP TABLES ====================

// Open-Meteo WMO Weather Codes → weather-icons class mapping
// https://www.open-meteo.com/en/docs#weathervariables
// Day/night variants: [day, night]
const OpenMeteoToWi = {
    0:  ["wi-day-sunny", "wi-night-clear"],              // Clear sky
    1:  ["wi-day-sunny-overcast", "wi-night-partly-cloudy"], // Mainly clear
    2:  ["wi-day-cloudy", "wi-night-cloudy"],            // Partly cloudy
    3:  ["wi-day-cloudy-high", "wi-night-cloudy-high"],  // Overcast
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

// Weather Underground (TWC) iconCode → weather-icons class mapping
// https://weather.com/swagger-docs/ui/sun/v3/sunV3CurrentsOnDemand.json
// Some codes are inherently day (D) or night (N), others need dayOrNight from API
// Format: { icon: "wi-class", daySpecific: true/false }
// If daySpecific is false, we'll use dayOrNight from API to pick day/night variant
const WUndergroundToWi = {
    0:  { icon: "wi-tornado", daySpecific: false },                    // Tornado
    1:  { icon: "wi-tropical-storm", daySpecific: false },             // Tropical Storm
    2:  { icon: "wi-tropical-storm", daySpecific: false },             // Hurricane
    3:  { icon: "wi-thunderstorm", daySpecific: false },               // Strong Storms
    4:  { icon: "wi-thunderstorms-with-hail", daySpecific: false },    // Thunder and Hail
    5:  { icon: "wi-day-rain-mix", nightIcon: "wi-night-rain-mix", daySpecific: false }, // Rain to Snow Showers
    6:  { icon: "wi-day-sleet", nightIcon: "wi-night-sleet", daySpecific: false }, // Rain/Sleet
    7:  { icon: "wi-day-sleet", nightIcon: "wi-night-sleet", daySpecific: false }, // Wintry Mix
    8:  { icon: "wi-freezing-drizzle", nightIcon: "wi-freezing-drizzle-night", daySpecific: false }, // Freezing Drizzle
    9:  { icon: "wi-drizzle", nightIcon: "wi-drizzle-night", daySpecific: false }, // Drizzle
    10: { icon: "wi-freezing-rain", nightIcon: "wi-freezing-rain-night", daySpecific: false }, // Freezing Rain
    11: { icon: "wi-showers", nightIcon: "wi-showers-night", daySpecific: false }, // Showers
    12: { icon: "wi-day-rain", nightIcon: "wi-night-rain", daySpecific: false }, // Rain
    13: { icon: "wi-day-snow", nightIcon: "wi-night-snow", daySpecific: false }, // Flurries
    14: { icon: "wi-day-snow", nightIcon: "wi-night-snow", daySpecific: false }, // Snow Showers
    15: { icon: "wi-day-snow-wind", nightIcon: "wi-night-snow-wind", daySpecific: false }, // Blowing/Drifting Snow
    16: { icon: "wi-snow", nightIcon: "wi-snow-night", daySpecific: false }, // Snow
    17: { icon: "wi-day-hail", nightIcon: "wi-night-hail", daySpecific: false }, // Hail
    18: { icon: "wi-sleet", nightIcon: "wi-sleet-night", daySpecific: false }, // Sleet
    19: { icon: "wi-dust", nightIcon: "wi-dust-night", daySpecific: false }, // Blowing Dust/Sandstorm
    20: { icon: "wi-fog", nightIcon: "wi-fog-night", daySpecific: false }, // Foggy
    21: { icon: "wi-day-haze", nightIcon: "wi-mist-night", daySpecific: false }, // Haze
    22: { icon: "wi-day-haze", nightIcon: "wi-mist-night", daySpecific: false }, // Smoke
    23: { icon: "wi-day-windy", nightIcon: "wi-night-windy", daySpecific: false }, // Breezy
    24: { icon: "wi-day-windy", nightIcon: "wi-night-windy", daySpecific: false }, // Windy
    25: { icon: "wi-cold", nightIcon: "wi-cold-night", daySpecific: false }, // Frigid/Ice Crystals
    26: { icon: "wi-cloudy", nightIcon: "wi-cloudy-night", daySpecific: false }, // Cloudy
    27: { icon: "wi-night-cloudy", daySpecific: true },                // Mostly Cloudy Night
    28: { icon: "wi-day-cloudy", daySpecific: true },                  // Mostly Cloudy Day
    29: { icon: "wi-night-partly-cloudy", daySpecific: true },         // Partly Cloudy Night
    30: { icon: "wi-day-sunny-overcast", daySpecific: true },          // Partly Cloudy Day
    31: { icon: "wi-night-clear", daySpecific: true },                 // Clear Night
    32: { icon: "wi-day-sunny", daySpecific: true },                   // Sunny
    33: { icon: "wi-night-clear", daySpecific: true },                 // Fair Night
    34: { icon: "wi-day-sunny", daySpecific: true },                   // Fair Day
    35: { icon: "wi-thunderstorms-with-hail", nightIcon: "wi-thunderstorms-with-hail-night", daySpecific: false }, // Mixed Rain and Hail
    36: { icon: "wi-hot", nightIcon: "wi-hot-night", daySpecific: false }, // Hot
    37: { icon: "wi-day-thunderstorm", nightIcon: "wi-night-thunderstorm", daySpecific: false }, // Isolated Thunderstorms
    38: { icon: "wi-day-thunderstorm", nightIcon: "wi-night-thunderstorm", daySpecific: false }, // Scattered Thunderstorms
    39: { icon: "wi-day-showers", daySpecific: true },                 // Scattered Showers Day
    40: { icon: "wi-extreme-rain", nightIcon: "wi-extreme-rain-night", daySpecific: false }, // Heavy Rain
    41: { icon: "wi-day-snow", daySpecific: true },                    // Scattered Snow Showers Day
    42: { icon: "wi-extreme-snow", nightIcon: "wi-extreme-snow-night", daySpecific: false }, // Heavy Snow
    43: { icon: "wi-blizzard", nightIcon: "wi-blizzard-night", daySpecific: false }, // Blizzard
    44: { icon: "wi-na", daySpecific: true },                          // Not Available
    45: { icon: "wi-night-showers", daySpecific: true },               // Scattered Showers Night
    46: { icon: "wi-night-snow", daySpecific: true },                  // Scattered Snow Showers Night
    47: { icon: "wi-night-thunderstorm", daySpecific: true }           // Scattered Thunderstorms Night
};

module.exports = NodeHelper.create({
    start: function() {
        console.log("MMM-My-Actual-Weather: Starting node_helper.");

        // Core data
        this.pwsData = null; // Stores the latest PWS push data
        this.lastPushTime = null; // Timestamp of last PWS push
        this.configData = null; // Store config for use in server callbacks
        this.apiDataCache = null; // Cached API data for fallback

        // Server
        this.pwsServerStarted = false; // Flag to prevent multiple server starts
        this.httpServer = null; // Reference to HTTP server

        // Weather Icon Caching (provider-agnostic)
        this.lastWeatherIconFetch = null; // Timestamp of last weather icon fetch
        this.cachedWeatherIconData = null; // Cached weather icon data { iconClass, weatherCode, isDay }

        // State Machine
        // States: INITIALIZING | PWS_ACTIVE | WAITING_FOR_PWS | API_ONLY
        this.state = "INITIALIZING";
        this.initialWaitTimer = null; // Timer for initial PWS wait (3 sec)
        this.pwsTimeoutTimer = null; // Timer for PWS timeout detection
        this.recheckTimer = null; // Timer for periodic recheck (60 min)

        // Timing constants (will be set from config)
        this.INITIAL_WAIT_MS = 3000; // 3 seconds initial wait
        this.PWS_TIMEOUT_MS = 180000; // 3x push interval (180 sec)
        this.RECHECK_INTERVAL_MS = 3600000; // 60 minutes
    },

    socketNotificationReceived: function(notification, payload) {
        if (notification === "FETCH_WEATHER") {
            this.configData = payload; // Store config

            // Calculate timing constants from config
            const pushInterval = payload.pwsPushInterval || 60; // seconds
            this.INITIAL_WAIT_MS = Math.max(3000, pushInterval * 50); // 5% of push interval, min 3 sec
            this.PWS_TIMEOUT_MS = pushInterval * 3 * 1000; // 3x push interval

            // Start PWS server on first FETCH_WEATHER if not already started
            if (!this.pwsServerStarted && payload.pwsPushPort > 0) {
                this.startPwsServer(payload.pwsPushPort);
                // Initialize State Machine
                this.initializeStateMachine();
            } else if (this.state === "PWS_ACTIVE") {
                // PWS active - just refresh weather icon cache (PWS push will send data)
                this.refreshWeatherIconCache();
            } else if (this.state === "WAITING_FOR_PWS" || this.state === "API_ONLY") {
                // API mode - reload API data and send to frontend (Bug 2 fix)
                this.loadApiDataInBackground();
            }
        }
    },

    // ==================== STATE MACHINE ====================

    // Initialize the state machine
    initializeStateMachine: function() {
        console.log("MMM-My-Actual-Weather: Initializing State Machine");
        this.transitionTo("INITIALIZING");

        // Load API data in parallel (non-blocking)
        this.loadApiDataInBackground();

        // Start initial wait timer
        this.startInitialWaitTimer();
    },

    // Transition to a new state
    transitionTo: function(newState) {
        const oldState = this.state;
        this.state = newState;
        console.log(`MMM-My-Actual-Weather: State ${oldState} → ${newState}`);

        // Clear all timers on state change
        this.clearAllTimers();

        // State-specific actions
        switch (newState) {
            case "INITIALIZING":
                // Nothing to send yet, waiting for push or timeout
                break;

            case "PWS_ACTIVE":
                // Start PWS timeout timer
                this.startPwsTimeoutTimer();
                // Send PWS data to frontend
                this.sendToFrontend();
                break;

            case "WAITING_FOR_PWS":
                // Start wait timeout timer (max 3x push interval)
                this.startWaitingForPwsTimer();
                // Send API data with "waiting for PWS" flag
                this.sendToFrontend();
                break;

            case "API_ONLY":
                // Start recheck timer (60 min)
                this.startRecheckTimer();
                // Send API data without "waiting for PWS" flag
                this.sendToFrontend();
                break;
        }
    },

    // Clear all timers
    clearAllTimers: function() {
        if (this.initialWaitTimer) {
            clearTimeout(this.initialWaitTimer);
            this.initialWaitTimer = null;
        }
        if (this.pwsTimeoutTimer) {
            clearTimeout(this.pwsTimeoutTimer);
            this.pwsTimeoutTimer = null;
        }
        if (this.recheckTimer) {
            clearTimeout(this.recheckTimer);
            this.recheckTimer = null;
        }
    },

    // Timer: Initial wait for PWS (3 seconds)
    startInitialWaitTimer: function() {
        const self = this;
        this.initialWaitTimer = setTimeout(async function() {
            if (self.state === "INITIALIZING") {
                console.log("MMM-My-Actual-Weather: Initial wait timeout, no PWS push received");

                // Wait for API data if not yet loaded
                if (!self.apiDataCache) {
                    console.log("MMM-My-Actual-Weather: Waiting for API data...");
                    // Poll for API data (max 10 seconds)
                    for (let i = 0; i < 20 && !self.apiDataCache && self.state === "INITIALIZING"; i++) {
                        await new Promise(resolve => setTimeout(resolve, 500));
                    }
                }

                // Only transition if still in INITIALIZING (PWS may have arrived)
                if (self.state === "INITIALIZING") {
                    self.transitionTo("WAITING_FOR_PWS");
                }
            }
        }, this.INITIAL_WAIT_MS);
    },

    // Timer: PWS timeout (no push received in 3x interval)
    startPwsTimeoutTimer: function() {
        const self = this;
        this.pwsTimeoutTimer = setTimeout(async function() {
            if (self.state === "PWS_ACTIVE") {
                console.log("MMM-My-Actual-Weather: PWS timeout, no push received");
                // Bug 3 fix: Reload API data before transitioning
                await self.loadApiDataInBackground();
                self.transitionTo("WAITING_FOR_PWS");
            }
        }, this.PWS_TIMEOUT_MS);
    },

    // Timer: Waiting for PWS timeout (give up after 3x interval)
    startWaitingForPwsTimer: function() {
        const self = this;
        this.pwsTimeoutTimer = setTimeout(function() {
            if (self.state === "WAITING_FOR_PWS") {
                console.log("MMM-My-Actual-Weather: PWS not responding, switching to API only");
                self.transitionTo("API_ONLY");
            }
        }, this.PWS_TIMEOUT_MS);
    },

    // Timer: Recheck for PWS (every 60 minutes)
    startRecheckTimer: function() {
        const self = this;
        this.recheckTimer = setTimeout(async function() {
            if (self.state === "API_ONLY") {
                console.log("MMM-My-Actual-Weather: Rechecking for PWS availability");
                // Bug 4 fix: Load fresh API data
                await self.loadApiDataInBackground();
                self.transitionTo("INITIALIZING");
                self.startInitialWaitTimer();
            }
        }, this.RECHECK_INTERVAL_MS);
    },

    // Load API data in background (non-blocking)
    loadApiDataInBackground: async function() {
        if (!this.configData) return;

        try {
            const config = this.configData;
            const dataObjectKey = (config.units === "e" || config.units === "h") ? "imperial" : "metric";
            const wundergroundUrl = `${config.baseURL}&units=${config.units}&numericPrecision=${config.numericPrecision}&stationId=${config.stationId}&apiKey=${config.apiKey}`;

            const response = await fetch(wundergroundUrl);
            if (response.ok) {
                const data = await response.json();
                if (data.observations && data.observations.length > 0) {
                    const obs = data.observations[0][dataObjectKey];
                    const currentObs = data.observations[0];

                    this.apiDataCache = {
                        temp: obs.temp,
                        windSpeed: obs.windSpeed,
                        precipTotal: obs.precipTotal,
                        windDirection: this.getWindDirection(currentObs.winddir, config.lang),
                        temp1: null,
                        temp2: null,
                        humidity1: null,
                        humidity2: null,
                        timestamp: null,
                        isLocalData: false
                    };
                    console.log("MMM-My-Actual-Weather: API data loaded in background");
                }
            }
        } catch (error) {
            console.error("MMM-My-Actual-Weather: Error loading API data in background:", error.message);
        }

        // Also load weather icon data
        await this.refreshWeatherIconCache();

        // If in WAITING_FOR_PWS or API_ONLY, send data now (Bug 1 fix)
        if (this.state === "WAITING_FOR_PWS" || this.state === "API_ONLY") {
            console.log("MMM-My-Actual-Weather: API data ready, sending to frontend");
            this.sendToFrontend();
        }
    },

    // ==================== WEATHER ICON PROVIDERS ====================

    // Refresh weather icon cache from configured provider
    refreshWeatherIconCache: async function() {
        if (!this.configData) return;

        const config = this.configData;
        const currentTime = Date.now();
        const timestamp = new Date().toISOString();

        // Only refresh if cache is expired
        if (this.lastWeatherIconFetch !== null &&
            this.cachedWeatherIconData !== null &&
            (currentTime - this.lastWeatherIconFetch) < config.updateInterval) {
            console.log(`MMM-My-Actual-Weather [${timestamp}]: Cache still valid, age=${Math.round((currentTime - this.lastWeatherIconFetch)/1000)}s, interval=${config.updateInterval/1000}s`);
            return; // Cache still valid
        }

        const provider = config.weatherProvider || "openmeteo";
        console.log(`MMM-My-Actual-Weather [${timestamp}]: Fetching weather icon from ${provider} (cache expired or empty)`);

        if (provider === "wunderground") {
            await this.fetchWUndergroundWeatherIcon(config, timestamp);
        } else {
            await this.fetchOpenMeteoWeatherIcon(config, timestamp);
        }
    },

    // Fetch weather icon data from Open-Meteo
    fetchOpenMeteoWeatherIcon: async function(config, timestamp) {
        const openMeteoUrl = `${config.openMeteoUrl}?latitude=${config.latitude}&longitude=${config.longitude}&current_weather=true&forecast_days=1`;
        try {
            const response = await fetch(openMeteoUrl);
            if (response.ok) {
                const data = await response.json();
                if (data.current_weather && data.current_weather.weathercode !== undefined) {
                    const oldCode = this.cachedWeatherIconData ? this.cachedWeatherIconData.weatherCode : 'none';
                    const weatherCode = data.current_weather.weathercode;
                    // Open-Meteo provides is_day (1=day, 0=night)
                    const isDay = data.current_weather.is_day === 1;
                    const iconClass = this.getWeatherIconOpenMeteo(weatherCode, isDay);

                    this.cachedWeatherIconData = {
                        weatherCode: weatherCode,
                        isDay: isDay,
                        iconClass: iconClass
                    };
                    this.lastWeatherIconFetch = Date.now();

                    console.log(`MMM-My-Actual-Weather [${timestamp}]: Open-Meteo fetched, weatherCode: ${oldCode} → ${weatherCode}, isDay: ${isDay}, iconClass: ${iconClass}`);
                }
            } else {
                console.log(`MMM-My-Actual-Weather [${timestamp}]: Open-Meteo fetch failed, status=${response.status}`);
            }
        } catch (error) {
            console.error(`MMM-My-Actual-Weather [${timestamp}]: Error fetching Open-Meteo:`, error.message);
        }
    },

    // Fetch weather icon data from Weather Underground
    fetchWUndergroundWeatherIcon: async function(config, timestamp) {
        // Weather Underground current conditions API (same as weather.com v3 API)
        const wuIconUrl = `https://api.weather.com/v3/wx/observations/current?geocode=${config.latitude},${config.longitude}&format=json&units=m&language=${config.lang || 'de'}-DE&apiKey=${config.wundergroundIconApiKey || config.apiKey}`;
        try {
            const response = await fetch(wuIconUrl);
            if (response.ok) {
                const data = await response.json();
                if (data.iconCode !== undefined) {
                    const oldCode = this.cachedWeatherIconData ? this.cachedWeatherIconData.weatherCode : 'none';
                    const iconCode = data.iconCode;
                    // WUnderground provides dayOrNight ("D" or "N")
                    const isDay = data.dayOrNight === "D";
                    const iconClass = this.getWeatherIconWUnderground(iconCode, isDay);

                    this.cachedWeatherIconData = {
                        weatherCode: iconCode,
                        isDay: isDay,
                        iconClass: iconClass
                    };
                    this.lastWeatherIconFetch = Date.now();

                    console.log(`MMM-My-Actual-Weather [${timestamp}]: WUnderground fetched, iconCode: ${oldCode} → ${iconCode}, isDay: ${isDay}, iconClass: ${iconClass}`);
                }
            } else {
                console.log(`MMM-My-Actual-Weather [${timestamp}]: WUnderground fetch failed, status=${response.status}`);
            }
        } catch (error) {
            console.error(`MMM-My-Actual-Weather [${timestamp}]: Error fetching WUnderground:`, error.message);
        }
    },

    // Get weather icon class from Open-Meteo weather code
    getWeatherIconOpenMeteo: function(weatherCode, isDay) {
        const mapping = OpenMeteoToWi[weatherCode];
        if (mapping) {
            return isDay ? mapping[0] : mapping[1];
        }
        return "wi-na";
    },

    // Get weather icon class from Weather Underground icon code
    getWeatherIconWUnderground: function(iconCode, isDay) {
        const mapping = WUndergroundToWi[iconCode];
        if (!mapping) {
            return "wi-na";
        }

        // If this icon code is day-specific (already includes day/night in the code), use it directly
        if (mapping.daySpecific) {
            return mapping.icon;
        }

        // Otherwise, pick day or night variant based on API's dayOrNight
        if (isDay) {
            return mapping.icon;
        } else {
            return mapping.nightIcon || mapping.icon;
        }
    },

    // Central function to send data to frontend based on current state
    sendToFrontend: async function() {
        if (!this.configData) return;

        const config = this.configData;
        let weatherData = {};

        // Determine data source based on state
        if (this.state === "PWS_ACTIVE" && this.pwsData) {
            // Use PWS data
            weatherData = {
                temp: this.pwsData.temp,
                windSpeed: this.pwsData.windSpeed,
                precipTotal: this.pwsData.precipTotal,
                windDirection: this.getWindDirection(this.pwsData.winddir, config.lang),
                temp1: this.pwsData.temp1,
                humidity1: this.pwsData.humidity1,
                temp2: this.pwsData.temp2,
                humidity2: this.pwsData.humidity2,
                timestamp: this.pwsData.timestamp,
                isLocalData: true,
                waitingForPws: false
            };
        } else if (this.apiDataCache) {
            // Use API data
            weatherData = {
                temp: this.apiDataCache.temp,
                windSpeed: this.apiDataCache.windSpeed,
                precipTotal: this.apiDataCache.precipTotal,
                windDirection: this.apiDataCache.windDirection,
                temp1: null,
                temp2: null,
                humidity1: null,
                humidity2: null,
                timestamp: null,
                isLocalData: false,
                waitingForPws: (this.state === "WAITING_FOR_PWS")
            };
        } else {
            // No data available yet
            console.log("MMM-My-Actual-Weather: No data available to send");
            return;
        }

        // Add weather icon from cache (or fetch if needed)
        if (this.cachedWeatherIconData !== null) {
            weatherData.weatherCode = this.cachedWeatherIconData.weatherCode;
            weatherData.isDay = this.cachedWeatherIconData.isDay;
            weatherData.weatherIconClass = this.cachedWeatherIconData.iconClass;
            const provider = config.weatherProvider || "openmeteo";
            console.log(`MMM-My-Actual-Weather DEBUG: provider=${provider}, weatherCode=${this.cachedWeatherIconData.weatherCode}, isDay=${this.cachedWeatherIconData.isDay}, iconClass=${weatherData.weatherIconClass}`);
        } else {
            // Try to fetch weather icon now
            await this.refreshWeatherIconCache();
            if (this.cachedWeatherIconData !== null) {
                weatherData.weatherCode = this.cachedWeatherIconData.weatherCode;
                weatherData.isDay = this.cachedWeatherIconData.isDay;
                weatherData.weatherIconClass = this.cachedWeatherIconData.iconClass;
            } else {
                weatherData.weatherCode = null;
                weatherData.isDay = true;
                weatherData.weatherIconClass = "wi-na";
            }
        }

        // Send to frontend
        this.sendSocketNotification("WEATHER_DATA", weatherData);
    },

    // Start HTTP server for PWS push data
    startPwsServer: function(port) {
        const self = this;

        this.httpServer = http.createServer((req, res) => {
            if (req.method === "POST" && req.url === "/data/report/") {
                let body = "";

                req.on("data", (chunk) => {
                    body += chunk.toString();
                });

                req.on("end", () => {
                    self.processPwsPush(body);
                    res.writeHead(200, { "Content-Type": "text/plain" });
                    res.end("OK");
                });

                req.on("error", (err) => {
                    console.error("MMM-My-Actual-Weather: Error receiving PWS push:", err);
                    res.writeHead(500, { "Content-Type": "text/plain" });
                    res.end("Error");
                });
            } else {
                res.writeHead(404, { "Content-Type": "text/plain" });
                res.end("Not Found");
            }
        });

        this.httpServer.listen(port, () => {
            console.log(`MMM-My-Actual-Weather: PWS Push server listening on port ${port}`);
            this.pwsServerStarted = true;
        });

        this.httpServer.on("error", (err) => {
            console.error(`MMM-My-Actual-Weather: Failed to start PWS server on port ${port}:`, err.message);
        });
    },

    // Process incoming PWS push data
    processPwsPush: function(body) {
        const params = new URLSearchParams(body);
        const data = {};

        // Extract all parameters into object
        for (const [key, value] of params) {
            data[key] = value;
        }

        // Update timestamp
        this.lastPushTime = Date.now();

        // Convert and store relevant data
        this.pwsData = {
            // Main temperature (Fahrenheit → Celsius)
            temp: this.fahrenheitToCelsius(parseFloat(data.tempf)),
            // Wind speed (mph → m/s for internal storage)
            windSpeed: this.mphToMs(parseFloat(data.windspeedmph)),
            // Wind direction (degrees)
            winddir: parseInt(data.winddir),
            // Daily precipitation (inches → mm)
            precipTotal: this.inchesToMm(parseFloat(data.dailyrainin)),
            // Sensor 1 (Fahrenheit → Celsius)
            temp1: data.temp1f ? this.fahrenheitToCelsius(parseFloat(data.temp1f)) : null,
            humidity1: data.humidity1 ? parseInt(data.humidity1) : null,
            // Sensor 2 (Fahrenheit → Celsius)
            temp2: data.temp2f ? this.fahrenheitToCelsius(parseFloat(data.temp2f)) : null,
            humidity2: data.humidity2 ? parseInt(data.humidity2) : null,
            // Timestamp from PWS (dateutc format: "2026-01-14+11:58:20")
            timestamp: data.dateutc ? this.parsePwsTimestamp(data.dateutc) : null,
            // Mark as local data
            isLocalData: true
        };

        // State Machine: Handle PWS push based on current state
        if (this.state === "PWS_ACTIVE") {
            // Already in PWS_ACTIVE - just reset timer and send data
            this.clearAllTimers();
            this.startPwsTimeoutTimer();
            this.sendToFrontend();
        } else {
            // Transition to PWS_ACTIVE from any other state
            console.log("MMM-My-Actual-Weather: PWS push received, switching to PWS_ACTIVE");
            this.transitionTo("PWS_ACTIVE");
        }
    },

    // Parse PWS timestamp and extract time portion
    parsePwsTimestamp: function(dateutc) {
        // Format: "2026-01-14+11:58:20" → extract "11:58"
        if (!dateutc) return null;
        const parts = dateutc.split(" ");
        if (parts.length === 2) {
            const timeParts = parts[1].split(":");
            if (timeParts.length >= 2) {
                return timeParts[0] + ":" + timeParts[1]; // "HH:MM"
            }
        }
        return null;
    },

    // Unit conversion functions
    fahrenheitToCelsius: function(tempF) {
        return (tempF - 32) * 5 / 9;
    },

    mphToMs: function(mph) {
        return mph * 0.44704;
    },

    inchesToMm: function(inches) {
        return inches * 25.4;
    },

    // Helper function to convert wind direction (degrees to cardinal direction)
    getWindDirection: function(degree, lang) {
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
