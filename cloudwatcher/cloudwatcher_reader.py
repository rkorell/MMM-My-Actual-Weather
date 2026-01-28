"""
CloudWatcher RS232 Reader Module
Modified: 2026-01-25 15:30 - Initial creation
Modified: 2026-01-28 19:00 - Removed ambient temp (no NTC sensor), added MPSAS support

Handles serial communication with AAG CloudWatcher sensor.
Protocol based on RS232_Comms_v100 through v140 documentation.

This unit has:
- IR sensor for sky temperature (S!)
- Rain sensor Type C (E!)
- New light sensor for MPSAS (Type 8 in C!)
- NO ambient temperature sensor (NTC removed, no RH sensor option)

Ambient temperature must be obtained from external source (e.g., PWS).
"""

import serial
import math
import time
import logging
from typing import Optional, Dict, List, Tuple

import config

logger = logging.getLogger(__name__)

# Constants
BLOCK_SIZE = 15
HANDSHAKE_XON = 0x11

# MPSAS calculation constants (from v140 documentation)
SQ_REFERENCE = 19.6  # Default reference value for sky quality


class CloudWatcherReader:
    """Handles RS232 communication with AAG CloudWatcher sensor."""

    def __init__(self, port: str = None, baudrate: int = None):
        self.port = port or config.SERIAL_PORT
        self.baudrate = baudrate or config.BAUDRATE
        self.serial: Optional[serial.Serial] = None
        self._connect()

    def _connect(self) -> bool:
        """Establish serial connection."""
        try:
            self.serial = serial.Serial(
                port=self.port,
                baudrate=self.baudrate,
                bytesize=serial.EIGHTBITS,
                parity=serial.PARITY_NONE,
                stopbits=serial.STOPBITS_ONE,
                timeout=2.0
            )
            # Wait after port open (recommended in v130 for pocketCW compatibility)
            time.sleep(2)
            logger.info(f"Connected to CloudWatcher on {self.port} @ {self.baudrate} baud")
            return True
        except serial.SerialException as e:
            logger.error(f"Failed to connect to {self.port}: {e}")
            self.serial = None
            return False

    def _reconnect(self) -> bool:
        """Attempt to reconnect after connection loss."""
        self.close()
        time.sleep(1)
        return self._connect()

    def close(self):
        """Close serial connection."""
        if self.serial and self.serial.is_open:
            self.serial.close()
            logger.info("Serial connection closed")

    def _send_command(self, cmd: str) -> Optional[bytes]:
        """Send command and receive response."""
        if not self.serial or not self.serial.is_open:
            if not self._reconnect():
                return None

        try:
            # Clear buffers
            self.serial.reset_input_buffer()
            self.serial.reset_output_buffer()

            # Send command
            self.serial.write(cmd.encode('ascii'))
            self.serial.flush()

            # Determine expected response size based on command
            num_blocks = self._get_expected_blocks(cmd)
            expected_bytes = num_blocks * BLOCK_SIZE

            # Read response
            response = self.serial.read(expected_bytes)

            if len(response) < expected_bytes:
                logger.warning(f"Incomplete response for {cmd}: got {len(response)}/{expected_bytes} bytes")
                return None

            return response

        except serial.SerialException as e:
            logger.error(f"Serial error during command {cmd}: {e}")
            return None

    def _get_expected_blocks(self, cmd: str) -> int:
        """Return expected number of 15-byte blocks for a command."""
        block_counts = {
            'S!': 2,  # Sky temperature
            'C!': 5,  # Values (LDR, rain sensor temp, zener, light sensor)
            'E!': 2,  # Rain frequency
            'D!': 5,  # Internal errors
            'F!': 2,  # Switch status
            'Q!': 2,  # PWM value
            'A!': 2,  # Internal name
            'B!': 2,  # Firmware version
            'z!': 1,  # Reset RS232 buffer
        }
        return block_counts.get(cmd, 2)

    def _parse_block(self, block: bytes) -> Tuple[str, str]:
        """Parse a 15-byte block into (type_code, value)."""
        if len(block) != BLOCK_SIZE:
            return ('', '')

        if block[0] != ord('!'):
            return ('', '')

        # Check for handshake block
        if block[1] == HANDSHAKE_XON:
            return ('XON', '')

        type_code = chr(block[1])
        # Block is 15 bytes: [0]=!, [1]=type, [2:15]=value (13 chars, strip spaces)
        value_str = block[2:15].decode('ascii', errors='ignore').strip()

        return (type_code, value_str)

    def _parse_response(self, response: bytes) -> Dict[str, str]:
        """Parse full response into dict of type_code -> value."""
        result = {}
        num_blocks = len(response) // BLOCK_SIZE

        for i in range(num_blocks):
            block = response[i * BLOCK_SIZE:(i + 1) * BLOCK_SIZE]
            type_code, value = self._parse_block(block)
            if type_code and type_code != 'XON':
                result[type_code] = value

        return result

    def _filtered_average(self, values: List[float]) -> float:
        """Calculate average excluding outliers (outside 1 std dev)."""
        if not values:
            return 0.0

        avg = sum(values) / len(values)

        if len(values) < 3:
            return avg

        std = (sum((x - avg) ** 2 for x in values) / len(values)) ** 0.5

        if std == 0:
            return avg

        filtered = [x for x in values if avg - std <= x <= avg + std]

        if filtered:
            return sum(filtered) / len(filtered)
        return avg

    def _calc_mpsas(self, raw_period: int, ambient_temp_c: float = 10.0) -> Optional[float]:
        """
        Convert raw light sensor period to MPSAS (Magnitudes Per Square Arc-Second).

        Formula from v140 documentation:
        mpsas = SQReference - 2.5 * log10(250000 / period)
        mpsas_corrected = (mpsas - 0.042) + (0.00212 * temperature)

        Higher MPSAS = darker sky (better for astronomy)
        Typical values: 17-18 (city), 21-22 (dark site)
        """
        if raw_period <= 0:
            return None

        try:
            mpsas = SQ_REFERENCE - 2.5 * math.log10(250000 / raw_period)
            mpsas_corrected = (mpsas - 0.042) + (0.00212 * ambient_temp_c)
            return round(mpsas_corrected, 2)
        except (ValueError, ZeroDivisionError):
            return None

    def read_sky_temp(self) -> Optional[float]:
        """Read IR sky temperature in °C."""
        response = self._send_command('S!')
        if not response:
            return None

        parsed = self._parse_response(response)
        if '1' in parsed:
            try:
                return int(parsed['1']) / 100.0
            except ValueError:
                pass
        return None

    def read_values(self) -> Optional[Dict]:
        """
        Read sensor values from C! command.

        Returns LDR and light sensor raw values.
        Note: Type 4 (LDR) is estimated by firmware when new light sensor is installed.
        """
        response = self._send_command('C!')
        if not response:
            return None

        parsed = self._parse_response(response)
        result = {}

        try:
            if '4' in parsed:
                result['ldr_raw'] = int(parsed['4'])
            if '8' in parsed:
                # New light sensor raw period (for MPSAS calculation)
                result['light_sensor_raw'] = int(parsed['8'])
        except ValueError as e:
            logger.warning(f"Value parsing error: {e}")

        return result if result else None

    def read_rain_freq(self) -> Optional[int]:
        """Read rain sensor frequency."""
        response = self._send_command('E!')
        if not response:
            return None

        parsed = self._parse_response(response)
        if 'R' in parsed:
            try:
                return int(parsed['R'])
            except ValueError:
                pass
        return None

    def read_device_info(self) -> Dict:
        """Read device name and firmware version."""
        info = {}

        response = self._send_command('A!')
        if response:
            parsed = self._parse_response(response)
            for key, value in parsed.items():
                if value and key not in ('XON',):
                    info['name'] = value.strip()
                    break

        response = self._send_command('B!')
        if response:
            parsed = self._parse_response(response)
            for key, value in parsed.items():
                if value and key not in ('XON',):
                    info['firmware'] = value.strip()
                    break

        return info

    def read_all(self, num_samples: int = 5) -> Optional[Dict]:
        """
        Read all sensor values with statistical filtering.

        Returns:
            sky_temp_c: IR sky temperature in °C
            rain_freq: Rain sensor frequency (higher = drier)
            is_raining: True if rain_freq < RAIN_THRESHOLD
            is_wet: True if rain_freq < WET_THRESHOLD
            light_sensor_raw: Raw period from new light sensor
            mpsas: Sky quality in mag/arcsec² (if light sensor present)
            is_daylight: True if light level indicates daylight

        Note: ambient_temp_c is NOT included - must be obtained from PWS.
        """
        sky_temps = []
        rain_freqs = []
        light_raws = []

        for _ in range(num_samples):
            # Sky temperature
            sky = self.read_sky_temp()
            if sky is not None:
                sky_temps.append(sky)

            # Sensor values (LDR, light sensor)
            values = self.read_values()
            if values and 'light_sensor_raw' in values:
                light_raws.append(values['light_sensor_raw'])

            # Rain frequency
            rain = self.read_rain_freq()
            if rain is not None:
                rain_freqs.append(rain)

            time.sleep(0.1)  # Small delay between samples

        # Check if we got enough data
        if not sky_temps:
            logger.warning("No sky temperature data collected")
            return None

        # Calculate filtered averages
        sky_temp = self._filtered_average(sky_temps)

        result = {
            'sky_temp_c': round(sky_temp, 2),
        }

        # Rain sensor (Type C thresholds: Dry > 2000, Wet > 1700, Rain = 0)
        if rain_freqs:
            rain_freq = int(self._filtered_average(rain_freqs))
            result['rain_freq'] = rain_freq
            result['is_raining'] = rain_freq < config.RAIN_THRESHOLD
            result['is_wet'] = rain_freq < config.WET_THRESHOLD

        # Light sensor (MPSAS)
        if light_raws:
            light_raw = int(self._filtered_average(light_raws))
            result['light_sensor_raw'] = light_raw

            # Calculate MPSAS (use default temp since we don't have ambient)
            mpsas = self._calc_mpsas(light_raw)
            if mpsas is not None:
                result['mpsas'] = mpsas
                # Daylight detection based on MPSAS
                # < 10 MPSAS = very bright (daylight)
                # > 18 MPSAS = dark (night)
                result['is_daylight'] = mpsas < config.MPSAS_DAYLIGHT_THRESHOLD

        return result


# For testing without hardware
class DummyCloudWatcherReader:
    """Dummy reader that returns simulated data for testing."""

    def __init__(self, port: str = None, baudrate: int = None):
        logger.info("Using DummyCloudWatcherReader (no hardware)")

    def close(self):
        pass

    def read_all(self, num_samples: int = 5) -> Dict:
        """Return simulated data."""
        import random

        sky = random.uniform(-30, 10)  # Sky temperature (always cold)
        rain_freq = random.randint(1500, 3500)
        mpsas = random.uniform(5, 21)  # 5 = bright daylight, 21 = dark sky

        return {
            'sky_temp_c': round(sky, 2),
            'rain_freq': rain_freq,
            'is_raining': rain_freq < 1700,
            'is_wet': rain_freq < 2000,
            'light_sensor_raw': random.randint(10, 1000),
            'mpsas': round(mpsas, 2),
            'is_daylight': mpsas < 10,
        }

    def read_device_info(self) -> Dict:
        return {'name': 'DummyCloudWatcher', 'firmware': '0.0.0'}
