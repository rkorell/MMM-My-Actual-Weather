-- Migration 001: Add indoor humidity columns
-- Run as weather_user on weather database
-- Date: 2026-01-29

ALTER TABLE weather_readings ADD COLUMN IF NOT EXISTS humidity1 INTEGER;
ALTER TABLE weather_readings ADD COLUMN IF NOT EXISTS humidity2 INTEGER;

-- Verify
SELECT column_name, data_type FROM information_schema.columns
WHERE table_name = 'weather_readings' AND column_name IN ('humidity1', 'humidity2');
