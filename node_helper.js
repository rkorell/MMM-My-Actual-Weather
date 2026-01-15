// node_helper.js for MMM-My-Actual-Weather
// Modified: 2026-01-14, 14:30 - AP 1.1 + 1.2: Added HTTP server for PWS push data, parsing, unit conversion, and fallback logic
// Modified: 2026-01-14, 16:35 - AP 1.6: Immediate frontend notification on PWS push, debug log for timestamp
// Modified: 2026-01-15, 14:30 - AP 2: Open-Meteo Caching, PWS-Verbindungsstatus, Logging auf debug
// Modified: 2026-01-15, 15:45 - AP 2: Design-Fix: processPwsPush() sendet direkt ans Frontend (keine Race Condition mehr)
// Modified: 2026-01-15, 16:15 - AP 2: State Machine für saubere PWS/API Koordination
// Modified: 2026-01-15, 17:00 - AP 2: Bug-Fixes: API-Daten bei WAITING_FOR_PWS/API_ONLY senden und aktualisieren

const NodeHelper = require("node_helper");
const fetch = require("node-fetch"); // For API requests
const SunCalc = require("suncalc"); // For sunrise/sunset calculations
const fs = require("fs").promises; // For reading SVG file content
const http = require("http"); // For PWS push server

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

        // Open-Meteo Caching
        this.lastOpenMeteoFetch = null; // Timestamp of last Open-Meteo fetch
        this.cachedOpenMeteoData = null; // Cached weather icon data

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
                // PWS active - just refresh Open-Meteo cache (PWS push will send data)
                this.refreshOpenMeteoCache();
            } else if (this.state === "WAITING_FOR_PWS" || this.state === "API_ONLY") {
                // API mode - reload API data and send to frontend (Bug 2 fix)
                this.loadApiDataInBackground();
            }
        } else if (notification === "FETCH_SVG_ICON") {
            this.fetchSvgIcon();
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

        // Also load Open-Meteo
        await this.refreshOpenMeteoCache();

        // If in WAITING_FOR_PWS or API_ONLY, send data now (Bug 1 fix)
        if (this.state === "WAITING_FOR_PWS" || this.state === "API_ONLY") {
            console.log("MMM-My-Actual-Weather: API data ready, sending to frontend");
            this.sendToFrontend();
        }
    },

    // Refresh Open-Meteo cache
    refreshOpenMeteoCache: async function() {
        if (!this.configData) return;

        const config = this.configData;
        const currentTime = Date.now();

        // Only refresh if cache is expired
        if (this.lastOpenMeteoFetch !== null &&
            this.cachedOpenMeteoData !== null &&
            (currentTime - this.lastOpenMeteoFetch) < config.updateInterval) {
            return; // Cache still valid
        }

        const openMeteoUrl = `${config.openMeteoUrl}?latitude=${config.latitude}&longitude=${config.longitude}&current_weather=true&forecast_days=1`;
        try {
            const response = await fetch(openMeteoUrl);
            if (response.ok) {
                const data = await response.json();
                if (data.current_weather && data.current_weather.weathercode !== undefined) {
                    this.cachedOpenMeteoData = { weatherCode: data.current_weather.weathercode };
                    this.lastOpenMeteoFetch = currentTime;
                }
            }
        } catch (error) {
            console.error("MMM-My-Actual-Weather: Error refreshing Open-Meteo cache:", error.message);
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

        // Calculate day/night
        let isDay = true;
        if (config.latitude !== null && config.longitude !== null) {
            const now = new Date();
            const times = SunCalc.getTimes(now, config.latitude, config.longitude);
            isDay = now > times.sunrise && now < times.sunset;
        }
        weatherData.isDay = isDay;

        // Add weather icon from cache (or fetch if needed)
        if (this.cachedOpenMeteoData !== null) {
            weatherData.weatherCode = this.cachedOpenMeteoData.weatherCode;
            weatherData.weatherIconClass = this.getWeatherIcon(this.cachedOpenMeteoData.weatherCode, isDay);
        } else {
            // Try to fetch Open-Meteo now
            await this.refreshOpenMeteoCache();
            if (this.cachedOpenMeteoData !== null) {
                weatherData.weatherCode = this.cachedOpenMeteoData.weatherCode;
                weatherData.weatherIconClass = this.getWeatherIcon(this.cachedOpenMeteoData.weatherCode, isDay);
            } else {
                weatherData.weatherCode = null;
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


    // Function to read and send SVG icon content
    fetchSvgIcon: async function() {
        const svgPath = this.path + "/img/wind-swirl.svg";
        try {
            const svgContent = await fs.readFile(svgPath, "utf8");
            this.sendSocketNotification("SVG_ICON_DATA", svgContent);
        } catch (error) {
            console.error("MMM-My-Actual-Weather: Error reading SVG icon:", error);
            this.sendSocketNotification("WEATHER_ERROR", `SVG Icon: ${error.message}`);
        }
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
    },

    // Helper function to convert Open-Meteo Weather Code to a weather-icon class
    // This mapping uses the weather-icons classes from your custom.css reference.
    getWeatherIcon: function(weatherCode, isDay) {
        // Open-Meteo Weather Codes: https://www.open-meteo.com/en/docs/forecast-api#weathercodes
        // Prioritize day/night specific icons if available in your custom.css reference
        switch (weatherCode) {
            case 0:  // Clear sky
                return isDay ? "wi-day-sunny" : "wi-night-clear";
            case 1:  // Mainly clear
                return isDay ? "wi-day-sunny-overcast" : "wi-night-partly-cloudy";
            case 2:  // Partly cloudy
                return isDay ? "wi-day-cloudy" : "wi-night-cloudy";
            case 3:  // Overcast
                return isDay ? "wi-day-cloudy-high" : "wi-night-cloudy-high";
            case 45: // Fog
                return isDay ? "wi-day-fog" : "wi-night-fog";
            case 48: // Depositing rime fog
                return isDay ? "wi-freezing-fog" : "wi-freezing-fog-night";

            // --- Drizzle ---
            case 51: // Light drizzle
                return isDay ? "wi-drizzle" : "wi-drizzle-night";
            case 53: // Moderate drizzle
                return isDay ? "wi-heavy-drizzle" : "wi-heavy-drizzle-night";
            case 55: // Dense drizzle
                return isDay ? "wi-heavy-freezing-drizzle" : "wi-heavy-freezing-drizzle-night";
            case 56: // Freezing drizzle light
                return isDay ? "wi-freezing-drizzle" : "wi-freezing-drizzle-night";
            case 57: // Freezing drizzle dense
                return isDay ? "wi-heavy-freezing-drizzle" : "wi-heavy-freezing-drizzle-night";

            // --- Rain ---
            case 61: // Rain: Slight
                return isDay ? "wi-day-rain-mix" : "wi-night-rain-mix";
            case 63: // Rain: Moderate
                return isDay ? "wi-day-rain" : "wi-night-rain";
            case 65: // Rain: Heavy
                return isDay ? "wi-day-extreme-rain-showers" : "wi-night-extreme-rain-showers";
            case 66: // Freezing Rain: Light
                return isDay ? "wi-freezing-rain" : "wi-freezing-rain-night";
            case 67: // Freezing Rain: Heavy
                return isDay ? "wi-heavy-freezing-drizzle" : "wi-heavy-freezing-drizzle-night";

            // --- Snow ---
            case 71: // Snow: Slight
                return isDay ? "wi-day-snow" : "wi-night-snow";
            case 73: // Snow: Moderate
                return isDay ? "wi-day-snow" : "wi-night-snow";
            case 75: // Snow: Heavy
                return isDay ? "wi-day-snow-wind" : "wi-night-snow-wind";
            case 77: // Snow grains
                return isDay ? "wi-day-snow" : "wi-night-snow";

            // --- Showers ---
            case 80: // Rain showers: Slight
                return isDay ? "wi-day-showers" : "wi-night-showers";
            case 81: // Rain showers: Moderate
                return isDay ? "wi-day-storm-showers" : "wi-night-storm-showers";
            case 82: // Rain showers: Violent
                return isDay ? "wi-day-extreme-rain-showers" : "wi-night-extreme-rain-showers";

            case 85: // Snow showers: Slight
                return isDay ? "wi-day-snow" : "wi-night-snow";
            case 86: // Snow showers: Heavy
                return isDay ? "wi-day-snow-wind" : "wi-night-snow-wind";

            // --- Thunderstorms ---
            case 95: // Thunderstorm: Slight or moderate
                return isDay ? "wi-day-thunderstorm" : "wi-night-thunderstorm";
            case 96: // Thunderstorm with slight hail
                return isDay ? "wi-day-hail" : "wi-night-hail";
            case 99: // Thunderstorm with heavy hail
                return isDay ? "wi-day-hail" : "wi-night-hail";

            default:
                return "wi-na";
        }
    }
});