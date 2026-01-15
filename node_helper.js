// node_helper.js for MMM-My-Actual-Weather
// Modified: 2026-01-14, 14:30 - AP 1.1 + 1.2: Added HTTP server for PWS push data, parsing, unit conversion, and fallback logic
// Modified: 2026-01-14, 16:35 - AP 1.6: Immediate frontend notification on PWS push, debug log for timestamp

const NodeHelper = require("node_helper");
const fetch = require("node-fetch"); // For API requests
const SunCalc = require("suncalc"); // For sunrise/sunset calculations
const fs = require("fs").promises; // For reading SVG file content
const http = require("http"); // For PWS push server

module.exports = NodeHelper.create({
    start: function() {
        console.log("MMM-My-Actual-Weather: Starting node_helper.");
        this.pwsData = null; // Stores the latest PWS push data
        this.lastPushTime = null; // Timestamp of last PWS push
        this.pwsServerStarted = false; // Flag to prevent multiple server starts
        this.httpServer = null; // Reference to HTTP server
        this.fallbackTimer = null; // Timer for fallback check
        this.configData = null; // Store config for use in server callbacks
    },

    socketNotificationReceived: function(notification, payload) {
        if (notification === "FETCH_WEATHER") {
            this.configData = payload; // Store config
            // Start PWS server on first FETCH_WEATHER if not already started
            if (!this.pwsServerStarted && payload.pwsPushPort > 0) {
                this.startPwsServer(payload.pwsPushPort);
            }
            this.fetchWeatherData(payload);
        } else if (notification === "FETCH_SVG_ICON") {
            this.fetchSvgIcon();
        }
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

        // Log first push reception
        if (this.lastPushTime === null) {
            console.log("MMM-My-Actual-Weather: First PWS push received, switching to local data");
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

        // Notify frontend about new PWS data availability
        // The actual weather data will be sent via fetchWeatherData

        // Immediately fetch weather data and send to frontend
        if (this.configData) {
            this.fetchWeatherData(this.configData);
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

    // Check if PWS data is still valid (not timed out)
    isPwsDataValid: function(fallbackTimeout) {
        if (this.lastPushTime === null || this.pwsData === null) {
            return false;
        }
        const timeSinceLastPush = (Date.now() - this.lastPushTime) / 1000; // in seconds
        return timeSinceLastPush < fallbackTimeout;
    },

    fetchWeatherData: async function(config) {
        let weatherData = {};
        const fallbackTimeout = config.pwsPushFallbackTimeout || 180;

        // Check if we have valid PWS push data
        if (this.isPwsDataValid(fallbackTimeout)) {
            // Use PWS push data
            console.log("MMM-My-Actual-Weather: Using PWS data, timestamp=" + this.pwsData.timestamp);
            weatherData.temp = this.pwsData.temp;
            weatherData.windSpeed = this.pwsData.windSpeed;
            weatherData.precipTotal = this.pwsData.precipTotal;
            weatherData.windDirection = this.getWindDirection(this.pwsData.winddir, config.lang);
            weatherData.temp1 = this.pwsData.temp1;
            weatherData.humidity1 = this.pwsData.humidity1;
            weatherData.temp2 = this.pwsData.temp2;
            weatherData.humidity2 = this.pwsData.humidity2;
            weatherData.timestamp = this.pwsData.timestamp;
            weatherData.isLocalData = true;
        } else {
            // Fallback to Wunderground API
            if (this.lastPushTime !== null) {
                // Only log if we previously had push data
                console.log("MMM-My-Actual-Weather: PWS push timeout, falling back to API");
            }

            // Determine which data object to use based on units
            const dataObjectKey = (config.units === "e" || config.units === "h") ? "imperial" : "metric";

            const wundergroundUrl = `${config.baseURL}&units=${config.units}&numericPrecision=${config.numericPrecision}&stationId=${config.stationId}&apiKey=${config.apiKey}`;
            try {
                const response = await fetch(wundergroundUrl);
                if (!response.ok) {
                    throw new Error(`Wunderground API error: ${response.statusText}`);
                }
                const data = await response.json();

                if (data.observations && data.observations.length > 0) {
                    const obs = data.observations[0][dataObjectKey];
                    const currentObs = data.observations[0];

                    weatherData.temp = obs.temp;
                    weatherData.windSpeed = obs.windSpeed;
                    weatherData.precipTotal = obs.precipTotal;
                    weatherData.windDirection = this.getWindDirection(currentObs.winddir, config.lang);
                } else {
                    throw new Error("No observations found in Wunderground response.");
                }
            } catch (error) {
                console.error("MMM-My-Actual-Weather: Error fetching Wunderground data:", error);
                this.sendSocketNotification("WEATHER_ERROR", `Wunderground: ${error.message}`);
                return;
            }

            // API data: no sensors, no timestamp
            weatherData.temp1 = null;
            weatherData.temp2 = null;
            weatherData.humidity1 = null;
            weatherData.humidity2 = null;
            weatherData.timestamp = null;
            weatherData.isLocalData = false;
        }

        // Determine if it's day or night based on current time and location
        let isDay = true;
        if (config.latitude !== null && config.longitude !== null) {
            const now = new Date();
            const times = SunCalc.getTimes(now, config.latitude, config.longitude);
            isDay = now > times.sunrise && now < times.sunset;
        }
        weatherData.isDay = isDay;

        // Open-Meteo API Query for weather icon (always needed, regardless of data source)
        const openMeteoUrl = `${config.openMeteoUrl}?latitude=${config.latitude}&longitude=${config.longitude}&current_weather=true&forecast_days=1`;
        try {
            const response = await fetch(openMeteoUrl);
            if (!response.ok) {
                throw new Error(`Open-Meteo API error: ${response.statusText}`);
            }
            const data = await response.json();

            if (data.current_weather && data.current_weather.weathercode !== undefined) {
                weatherData.weatherCode = data.current_weather.weathercode;
                weatherData.weatherIconClass = this.getWeatherIcon(data.current_weather.weathercode, isDay);
            } else {
                throw new Error("No current_weather or weathercode found in Open-Meteo response.");
            }
        } catch (error) {
            console.error("MMM-My-Actual-Weather: Error fetching Open-Meteo data:", error);
            this.sendSocketNotification("WEATHER_ERROR", `Open-Meteo: ${error.message}`);
            return;
        }

        // Send the combined data to the main module
        this.sendSocketNotification("WEATHER_DATA", weatherData);
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