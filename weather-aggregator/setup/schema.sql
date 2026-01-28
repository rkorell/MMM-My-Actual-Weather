-- Weather Aggregator Database Schema
-- Modified: 2026-01-28 - Initial creation
--
-- Run as postgres superuser:
--   CREATE USER weather_user WITH PASSWORD 'xxx';
--   CREATE DATABASE weather OWNER weather_user;
--
-- Then connect to weather database and run this script:
--   \c weather
--   \i schema.sql

CREATE TABLE IF NOT EXISTS weather_readings (
    id SERIAL PRIMARY KEY,
    timestamp TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    -- PWS Data
    temp_c REAL,
    humidity REAL,
    dewpoint_c REAL,
    pressure_hpa REAL,
    wind_speed_ms REAL,
    wind_dir_deg INTEGER,
    wind_gust_ms REAL,
    precip_rate_mm REAL,
    precip_today_mm REAL,
    uv_index REAL,
    solar_radiation REAL,
    temp1_c REAL,           -- Additional sensor: WoZi
    temp2_c REAL,           -- Additional sensor: Therapie

    -- CloudWatcher Data
    sky_temp_c REAL,
    rain_freq INTEGER,
    mpsas REAL,
    cw_is_raining BOOLEAN,
    cw_is_daylight BOOLEAN,

    -- Derived Values
    delta_c REAL,           -- temp_c - sky_temp_c
    wmo_code INTEGER,
    condition VARCHAR(30)
);

-- Index for time-based queries (most recent first)
CREATE INDEX IF NOT EXISTS idx_weather_timestamp ON weather_readings(timestamp DESC);

-- Grant permissions to weather_user (run as superuser)
-- GRANT ALL PRIVILEGES ON TABLE weather_readings TO weather_user;
-- GRANT USAGE, SELECT ON SEQUENCE weather_readings_id_seq TO weather_user;
