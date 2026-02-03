-- Migration 002: Add heater_pwm column for CloudWatcher rain sensor
-- Run as postgres superuser on weather database (weather_user lacks ALTER permission)
-- Date: 2026-02-03
--
-- The heater_pwm value (0-100%) indicates the CloudWatcher rain sensor heater activity.
-- When moisture is detected, the heater activates to dry the sensor.
-- PWM > 30% combined with is_wet = true indicates active precipitation,
-- even when the PWS rain gauge shows 0 mm/h.

ALTER TABLE weather_readings ADD COLUMN IF NOT EXISTS heater_pwm INTEGER;

-- Verify
SELECT column_name, data_type FROM information_schema.columns
WHERE table_name = 'weather_readings' AND column_name = 'heater_pwm';
