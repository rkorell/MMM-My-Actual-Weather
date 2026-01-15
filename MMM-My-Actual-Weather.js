
// Modul for reading PWS - initial via WeatherUnderground Cloud query
// (c) Dr. Ralf Korell, 2025/2026
// Modified: 2026-01-14, 15:00 - AP 1.3 + 1.4: Added PWS push config parameters, sensor display, timestamp display



Module.register("MMM-My-Actual-Weather", {
    // Helper to convert any CSS color string (named, hex, rgb()) to RGB object
    // This function leverages the browser's ability to compute styles.
    cssColorToRgb: function(colorString) {
        // Create a temporary element
        const tempDiv = document.createElement('div');
        // Make it invisible and small, but still part of the layout for getComputedStyle
        tempDiv.style.cssText = `position: absolute; visibility: hidden; width: 1px; height: 1px; color: ${colorString};`;
        document.body.appendChild(tempDiv);
        const computedColor = window.getComputedStyle(tempDiv).color;
        document.body.removeChild(tempDiv);

        // --- ADDED LOGGING FOR DEBUGGING (from previous step) ---
        Log.log(`MMM-My-Actual-Weather: Resolving color "${colorString}" -> Computed: "${computedColor}"`);
        // --- END ADDED LOGGING ---

        const match = computedColor.match(/rgb\((\d+),\s*(\d+),\s*(\d+)\)/);
        if (match) {
            return {
                r: parseInt(match[1]),
                g: parseInt(match[2]),
                b: parseInt(match[3])
            };
        }
        // Fallback for hex if it was passed directly and not converted by browser, or other formats
        // This is less likely to be hit if colorString is a valid CSS color name or hex.
        if (colorString.startsWith("#") && (colorString.length === 7 || colorString.length === 4)) {
            const bigint = parseInt(colorString.slice(1), 16);
            return {
                r: (bigint >> 16) & 255,
                g: (bigint >> 8) & 255,
                b: bigint & 255
            };
        }
        Log.warn(`MMM-My-Actual-Weather: Failed to resolve color "${colorString}" to RGB. Computed: "${computedColor}". Defaulting to white.`);
        return { r: 255, g: 255, b: 255 }; // Default to white if resolution fails
    },

    // Default configurations for the module
    defaults: {
        baseURL: "https://api.weather.com/v2/pws/observations/current?format=json",
        units: "m", // 'm' for metric, 'e' for english
        numericPrecision: "decimal", // 'decimal' for decimal places
        stationId: "Your_StationID", // REPLACE THIS WITH YOUR WUNDERGROUND STATION ID
        apiKey: "to_be_replaced_with_your_key", // REPLACE THIS WITH YOUR WUNDERGROUND API KEY
        openMeteoUrl: "https://api.open-meteo.com/v1/forecast",
        latitude: null, // Must be set in config.js for Open-Meteo and day/night calculation
        longitude: null, // Must be set in config.js for Open-Meteo and day/night calculation
        updateInterval: 5 * 60 * 1000, // Update interval in milliseconds (5 minutes)
        animationSpeed: 1000, // Animation speed in milliseconds
        lang: config.language, // Language from MagicMirror configuration
        decimalPlacesTemp: 1, // Number of decimal places for temperature
        decimalPlacesPrecip: 1, // Number of decimal places for precipitation

        // New color parameters
        windColor: "white", // Default color for wind information
        precipitationColor: "white", // Default color for precipitation information
        temperatureColor: "white", // Default color for temperature (if tempSensitive is false)
        tempSensitive: true, // If true, temperature color changes based on value
        // Define temperature points and their corresponding colors (named or hex)
        // The module will interpolate colors between these points.
        // Use meaningful names for colors that your browser understands (e.g., "Dodgerblue", "Crimson", "Orange").
        tempColorGradient: [
            { temp: -17, color: "Dodgerblue" }, // Very cold
            { temp: -8, color: "Blue" },        // Colder
            { temp: 2, color: "LightBlue" },    // Cold
            { temp: 8, color: "Yellow" },       // Normal
            { temp: 15, color: "Gold" },        // Warm
            { temp: 18, color: "Orange" },      // Warmer
            { temp: 25, color: "Darkorange" },  // Very warm
            { temp: 28, color: "Orangered" },   // Very very warm
            { temp: 32, color: "Red" }         // Very very very warm
            // You can add more points here if needed, e.g., { temp: 30, color: "DarkRed" }
        ],

        // PWS Push Configuration
        pwsPushPort: 8000,              // Port for HTTP server (0 = disabled)
        pwsPushInterval: 60,            // Expected push interval in seconds
        pwsPushFallbackTimeout: 180,    // Seconds without push → fallback to API

        // Additional Sensors
        showSensor1: false,             // Show sensor 1 (temp1)
        showSensor2: false,             // Show sensor 2 (temp2)
        sensor1Name: "WoZi",            // Display name for sensor 1
        sensor2Name: "Therapie",        // Display name for sensor 2
        sensorTextColor: "lightgray",   // Color for sensor text and timestamp

        // Data Source Indicator
        showDataSource: true            // Show timestamp when using local PWS data
    },

    // Module initialization
    start: function() {
        this.weatherData = null; // Stores the fetched weather data
        this.windSvgContent = null; // Property to store SVG content
        this.loaded = false; // Flag if data has been loaded
        this.getWeatherData(); // Starts the first data fetch
        this.getSvgIcon(); // Fetch SVG content on start
        this.scheduleUpdate(); // Schedules recurring updates
        Log.info("Starting module: " + this.name);
    },

    // CSS files to be loaded
    getStyles: function() {
        return ["MMM-My-Actual-Weather.css", "weather-icons.css"]; // weather-icons.css is still needed for main weather icon
    },

    // Translations for the module
    getTranslations: function() {
        return {
            en: "translations/en.json",
            de: "translations/de.json"
        };
    },

    // Helper function to get the interpolated temperature color
    getTemperatureColor: function(temp) {
        if (!this.config.tempSensitive) {
            return this.config.temperatureColor; // Use fixed color if not sensitive
        }

        // Resolve named colors and sort gradient points by temperature
        const gradientPoints = this.config.tempColorGradient
            // Store original color name as 'colorName' and resolved RGB as 'rgb'
            .map(point => ({ temp: point.temp, colorName: point.color, rgb: this.cssColorToRgb(point.color) }))
            .sort((a, b) => a.temp - b.temp); // Ensure points are sorted by temperature

        Log.log(`MMM-My-Actual-Weather: Calculating color for temp ${temp}°C`);

        if (gradientPoints.length === 0) {
            Log.warn("MMM-My-Actual-Weather: tempColorGradient is empty. Defaulting to white.");
            return "rgb(255, 255, 255)"; // Fallback if no gradient points are defined
        }
        if (gradientPoints.length === 1) {
            Log.log(`MMM-My-Actual-Weather: Only one gradient point. Using color: rgb(${gradientPoints[0].rgb.r}, ${gradientPoints[0].rgb.g}, ${gradientPoints[0].rgb.b})`);
            return `rgb(${gradientPoints[0].rgb.r}, ${gradientPoints[0].rgb.g}, ${gradientPoints[0].rgb.b})`; // Only one point, use its color
        }

        // Find the segment for the current temperature
        let lowerPoint = null;
        let upperPoint = null;

        for (let i = 0; i < gradientPoints.length - 1; i++) {
            if (temp >= gradientPoints[i].temp && temp <= gradientPoints[i + 1].temp) {
                lowerPoint = gradientPoints[i];
                upperPoint = gradientPoints[i + 1];
                break;
            }
        }

        // Handle temperatures outside the defined range
        if (temp < gradientPoints[0].temp) {
            Log.log(`MMM-My-Actual-Weather: Temp ${temp}°C below lowest point ${gradientPoints[0].temp}°C. Using color of lowest point (${gradientPoints[0].colorName}).`);
            return `rgb(${gradientPoints[0].rgb.r}, ${gradientPoints[0].rgb.g}, ${gradientPoints[0].rgb.b})`;
        }
        if (temp > gradientPoints[gradientPoints.length - 1].temp) {
            Log.log(`MMM-My-Actual-Weather: Temp ${temp}°C above highest point ${gradientPoints[gradientPoints.length - 1].temp}°C. Using color of highest point (${gradientPoints[gradientPoints.length - 1].colorName}).`);
            return `rgb(${gradientPoints[gradientPoints.length - 1].rgb.r}, ${gradientPoints[gradientPoints.length - 1].rgb.g}, ${gradientPoints[gradientPoints.length - 1].rgb.b})`;
        }

        // If for some reason lowerPoint or upperPoint are still null (shouldn't happen with the above checks)
        if (!lowerPoint || !upperPoint) {
            Log.error(`MMM-My-Actual-Weather: Logic error in getTemperatureColor for temp ${temp}°C. Falling back to white.`);
            return "rgb(255, 255, 255)";
        }

        // Calculate interpolation factor (0 to 1)
        const factor = (temp - lowerPoint.temp) / (upperPoint.temp - lowerPoint.temp);

        // Interpolate RGB components
        const r = Math.round(lowerPoint.rgb.r + factor * (upperPoint.rgb.r - lowerPoint.rgb.r));
        const g = Math.round(lowerPoint.rgb.g + factor * (upperPoint.rgb.g - lowerPoint.rgb.g));
        const b = Math.round(lowerPoint.rgb.b + factor * (upperPoint.rgb.b - lowerPoint.rgb.b));

        const finalColor = `rgb(${r}, ${g}, ${b})`;
        // Log the colorName property, which now holds the original string
        Log.log(`MMM-My-Actual-Weather: Temp ${temp}°C, Segment: ${lowerPoint.temp}°C (${lowerPoint.colorName}) to ${upperPoint.temp}°C (${upperPoint.colorName}), Factor: ${factor.toFixed(4)}, Final Color: ${finalColor}`);
        return finalColor;
    },

    // Creates the DOM content of the module
    getDom: function() {
        var wrapper = document.createElement("div");
        wrapper.className = "MMM-My-Actual-Weather";

        if (!this.loaded) {
            wrapper.innerHTML = this.translate("LOADING");
            wrapper.className += " dimmed light small";
            return wrapper;
        }

        if (!this.weatherData) {
            wrapper.innerHTML = this.translate("NO_WEATHER_DATA");
            wrapper.className += " dimmed light small";
            return wrapper;
        }

        // --- Current Weather Section (mimics default module structure) ---
        var currentWeatherWrapper = document.createElement("div");
        currentWeatherWrapper.className = "current-weather-wrapper"; // For relative positioning of wind

        // Weather Details (Icon and Temperature)
        var weatherDetails = document.createElement("div");
        weatherDetails.className = "weather-details";

        // Weather Icon
        var iconSpan = document.createElement("span");
        iconSpan.className = "icon";
        var weatherIcon = document.createElement("span");
        weatherIcon.className = "wi " + this.weatherData.weatherIconClass;
        iconSpan.appendChild(weatherIcon);
        weatherDetails.appendChild(iconSpan);

        // Temperature
        var temperature = document.createElement("span");
        temperature.className = "temperature";
        temperature.innerHTML = this.weatherData.temp.toFixed(this.config.decimalPlacesTemp) + "&deg;";
        temperature.style.color = this.getTemperatureColor(this.weatherData.temp);
        weatherDetails.appendChild(temperature);

        currentWeatherWrapper.appendChild(weatherDetails);

        // Wind Information
        var windInfo = document.createElement("div");
        windInfo.className = "wind-info";
        if (this.weatherData.windSpeed !== null && this.weatherData.windDirection !== null) {
            // Container for the dynamically loaded SVG
            var windIconSvgContainer = document.createElement("div");
            windIconSvgContainer.className = "wind-icon-svg-container";
            if (this.windSvgContent) {
                // Inject the SVG string directly into the div
                windIconSvgContainer.innerHTML = this.windSvgContent;
            }
            windInfo.appendChild(windIconSvgContainer);
            
            var windSpeed = document.createElement("span");
            windSpeed.className = "wind-speed";
            
            let windUnit = "m/s"; // Default from Wunderground PWS if units=s
            let displaySpeed = this.weatherData.windSpeed;

            if (this.config.units === "m") {
                displaySpeed = (this.weatherData.windSpeed * 3.6); // Convert m/s to km/h
                windUnit = "km/h";
            } else if (this.config.units === "e" || this.config.units === "h") {
                // If config.units is 'e' or 'h', node_helper fetches from 'imperial' object, which is mph.
                windUnit = "mph";
            }
            // If config.units is 's', node_helper fetches from 'metric' object, which is m/s.
            // No conversion needed, unit is m/s.

            windSpeed.innerHTML = displaySpeed.toFixed(1) + " " + windUnit;
            windInfo.appendChild(windSpeed);

            var windDirection = document.createElement("span");
            windDirection.className = "wind-direction";
            windDirection.innerHTML = this.weatherData.windDirection;
            windInfo.appendChild(windDirection);
        } else {
            var noWindText = document.createElement("span");
            noWindText.className = "wind-direction"; // Use this class for styling consistency
            noWindText.innerHTML = this.translate("NO_WIND_DATA");
            windInfo.appendChild(noWindText);
        }
        // Apply wind color to the text elements and the inlined SVG (via inheritance to currentColor)
        windInfo.style.color = this.config.windColor;
        currentWeatherWrapper.appendChild(windInfo);

        wrapper.appendChild(currentWeatherWrapper);

        // --- Sensor Section (only when local PWS data available) ---
        if (this.weatherData.isLocalData) {
            if (this.config.showSensor1 && this.weatherData.temp1 !== null) {
                var sensor1Info = document.createElement("div");
                sensor1Info.className = "sensor-info";
                sensor1Info.innerHTML = this.config.sensor1Name + ": " + this.weatherData.temp1.toFixed(this.config.decimalPlacesTemp) + "&deg;C";
                sensor1Info.style.color = this.config.sensorTextColor;
                wrapper.appendChild(sensor1Info);
            }
            if (this.config.showSensor2 && this.weatherData.temp2 !== null) {
                var sensor2Info = document.createElement("div");
                sensor2Info.className = "sensor-info";
                sensor2Info.innerHTML = this.config.sensor2Name + ": " + this.weatherData.temp2.toFixed(this.config.decimalPlacesTemp) + "&deg;C";
                sensor2Info.style.color = this.config.sensorTextColor;
                wrapper.appendChild(sensor2Info);
            }
        }

        // --- Precipitation Section (below current weather) ---
        var precipitationInfo = document.createElement("div");
        precipitationInfo.className = "precipitation-info";

        var precipitation = document.createElement("span");
        precipitation.className = "precipitation dimmed";
        if (this.weatherData.precipTotal !== null) {
            precipitation.innerHTML = this.translate("PRECIPITATION") + ": " + this.weatherData.precipTotal.toFixed(this.config.decimalPlacesPrecip) + " mm";
        } else {
            precipitation.innerHTML = this.translate("NO_PRECIPITATION_DATA");
        }
        precipitation.style.color = this.config.precipitationColor; // Apply precipitation color
        precipitationInfo.appendChild(precipitation);

        // Timestamp (only when local PWS data and showDataSource enabled)
        if (this.weatherData.isLocalData && this.config.showDataSource && this.weatherData.timestamp) {
            var timestamp = document.createElement("span");
            timestamp.className = "data-timestamp";
            timestamp.innerHTML = this.weatherData.timestamp;
            timestamp.style.color = this.config.sensorTextColor;
            precipitationInfo.appendChild(timestamp);
        }

        wrapper.appendChild(precipitationInfo);

        return wrapper;
    },

    // Schedules the next update
    scheduleUpdate: function() {
        var self = this;
        setInterval(function() {
            self.getWeatherData();
        }, this.config.updateInterval);
    },

    // Requests SVG icon content from the node_helper
    getSvgIcon: function() {
        this.sendSocketNotification("FETCH_SVG_ICON");
    },

    // Requests weather data from the node_helper
    getWeatherData: function() {
        // Check if latitude and longitude are configured
        if (this.config.latitude === null || this.config.longitude === null) {
            Log.error(this.name + ": Latitude and Longitude must be set in config.js for Open-Meteo and day/night calculation.");
            this.loaded = true;
            this.updateDom();
            return;
        }
        // Send the full config to the node_helper, including lat/lon for suncalc
        this.sendSocketNotification("FETCH_WEATHER", this.config);
    },

    // Receives notifications from the node_helper
    socketNotificationReceived: function(notification, payload) {
        if (notification === "WEATHER_DATA") {
            this.weatherData = payload;
            this.loaded = true;
            this.updateDom(this.config.animationSpeed);
        } else if (notification === "WEATHER_ERROR") {
            Log.error(this.name + ": " + payload);
            this.loaded = true;
            this.weatherData = null; // Set data to null to display error message
            this.updateDom(this.config.animationSpeed);
        } else if (notification === "SVG_ICON_DATA") { // New notification handler for SVG content
            this.windSvgContent = payload;
            this.updateDom(); // Update DOM once SVG is loaded
        }
    }
});