-- Migration 003: Add rain_sensor_temp_c for heater control feedback loop
-- Modified: 2026-02-04 - Initial creation
--
-- The rain sensor has an integrated NTC thermistor (Type 5) that measures
-- the sensor's temperature. This is the feedback signal for the heater
-- control loop (not to be confused with ambient temperature).
--
-- Run as weather_user:
--   \c weather
--   \i migrate_003_add_rain_sensor_temp.sql

ALTER TABLE weather_readings
ADD COLUMN IF NOT EXISTS rain_sensor_temp_c REAL;

COMMENT ON COLUMN weather_readings.rain_sensor_temp_c IS 'Rain sensor NTC temperature in °C (heater control feedback)';
