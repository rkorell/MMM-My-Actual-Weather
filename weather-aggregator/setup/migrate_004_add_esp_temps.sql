-- Migration 004: Add ESP ambient temperature sensors (sun and shadow)
-- Modified: 2026-02-05 - Initial creation
--
-- The CloudWatcher heater control uses an external ESP Temp2IoT sensor
-- for ambient temperature reference. The ESP provides two sensors:
-- - "Schatten" (shadow): shaded sensor, used for heater control
-- - "Sonne" (sun): sun-exposed sensor
--
-- Both are now stored for monitoring and analysis.
--
-- Run as weather_user:
--   \c weather
--   \i migrate_004_add_esp_temps.sql

ALTER TABLE weather_readings
ADD COLUMN IF NOT EXISTS esp_temp_shadow_c REAL,
ADD COLUMN IF NOT EXISTS esp_temp_sun_c REAL;

COMMENT ON COLUMN weather_readings.esp_temp_shadow_c IS 'ESP ambient temperature (shadow sensor) in °C - used for heater control';
COMMENT ON COLUMN weather_readings.esp_temp_sun_c IS 'ESP ambient temperature (sun sensor) in °C';
