"""
CloudWatcher RS232 Reader Module
Modified: 2026-01-25 15:30 - Initial creation
Modified: 2026-01-28 19:00 - Removed ambient temp (no NTC sensor), added MPSAS support
Modified: 2026-02-03 14:00 - Added heater PWM reading (Q! command) for rain detection
Modified: 2026-02-04 19:15 - Added rain sensor temperature (Type 5) for heater control loop
Modified: 2026-02-04 20:45 - Added set_pwm() method for heater control
Modified: 2026-02-04 20:55 - Fixed set_pwm() response parsing (device responds with Q, not P)

Handles serial communication with AAG CloudWatcher sensor.
Protocol based on RS232_Comms_v100 through v140 documentation.

This unit has:
- IR sensor for sky temperature (S!)
- Rain sensor Type C (E!) with integrated heater and NTC thermistor
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

# Rain sensor NTC thermistor constants (from INDI driver, empirically validated)
RAIN_PULLUP_KOHM = 9.9      # Pull-up resistor value
RAIN_RES_AT_25_KOHM = 10.0  # NTC resistance at 25°C
RAIN_BETA = 3811            # Beta coefficient for Steinhart-Hart
ABS_ZERO = 273.15           # Absolute zero in Kelvin

# PWM limits
PWM_MIN = 0
PWM_MAX = 1023


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
        # Handle Pxxxx! commands (PWM set)
        if cmd.startswith('P') and cmd.endswith('!'):
            return 2

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

    def _calc_rain_sensor_temp(self, raw_adc: int) -> Optional[float]:
        """
        Convert rain sensor NTC thermistor ADC value to temperature in °C.

        Uses Steinhart-Hart equation with constants from INDI driver.
        The NTC is integrated into the rain sensor for heater control feedback.

        Args:
            raw_adc: ADC value 0-1023 from Type 5 response

        Returns:
            Temperature in °C, or None if invalid
        """
        # Clamp to valid range (avoid division by zero)
        if raw_adc > 1022:
            raw_adc = 1022
        if raw_adc < 1:
            return None

        try:
            # Calculate resistance from voltage divider
            r_kohm = RAIN_PULLUP_KOHM / ((1023.0 / raw_adc) - 1.0)

            # Steinhart-Hart equation (simplified Beta formula)
            ln_r = math.log(r_kohm / RAIN_RES_AT_25_KOHM)
            temp_kelvin = 1.0 / (ln_r / RAIN_BETA + 1.0 / (ABS_ZERO + 25.0))

            return round(temp_kelvin - ABS_ZERO, 2)
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

        Returns:
            ldr_raw: LDR value (Type 4, estimated when new light sensor installed)
            rain_sensor_temp_raw: NTC ADC value (Type 5, for heater control)
            rain_sensor_temp_c: Converted temperature in °C
            light_sensor_raw: Light sensor period (Type 8, for MPSAS)
        """
        response = self._send_command('C!')
        if not response:
            return None

        parsed = self._parse_response(response)
        result = {}

        try:
            if '4' in parsed:
                result['ldr_raw'] = int(parsed['4'])
            if '5' in parsed:
                # Rain sensor NTC thermistor (for heater control feedback)
                raw_adc = int(parsed['5'])
                result['rain_sensor_temp_raw'] = raw_adc
                temp_c = self._calc_rain_sensor_temp(raw_adc)
                if temp_c is not None:
                    result['rain_sensor_temp_c'] = temp_c
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

    def read_pwm(self) -> Optional[int]:
        """
        Read heater PWM duty cycle (0-1023).

        Returns the current PWM value set for the rain sensor heater.
        """
        response = self._send_command('Q!')
        if not response:
            return None

        parsed = self._parse_response(response)
        if 'Q' in parsed:
            try:
                return int(parsed['Q'])
            except ValueError:
                pass
        return None

    def set_pwm(self, value: int) -> bool:
        """
        Set heater PWM duty cycle.

        Command format: Pxxxx! where xxxx is 0-1023 (4 digits, zero-padded)
        Response: !Q followed by the set value (device echoes back as Q-type)

        Args:
            value: PWM value 0-1023

        Returns:
            True if command was acknowledged, False otherwise
        """
        # Clamp to valid range
        value = max(PWM_MIN, min(PWM_MAX, value))

        # Format command: Pxxxx! (4-digit zero-padded)
        cmd = f"P{value:04d}!"

        response = self._send_command(cmd)
        if not response:
            logger.error(f"No response to PWM command {cmd}")
            return False

        parsed = self._parse_response(response)

        # Device responds with Q-type (same as query response)
        if 'Q' in parsed:
            try:
                ack_value = int(parsed['Q'])
                if ack_value == value:
                    logger.debug(f"PWM set to {value}")
                    return True
                else:
                    logger.warning(f"PWM mismatch: requested {value}, got {ack_value}")
                    return True  # Still acknowledge (device accepted it)
            except ValueError:
                logger.warning(f"Invalid PWM response: {parsed['Q']}")

        return False

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
            heater_pwm: Heater duty cycle 0-1023
            rain_sensor_temp_c: Rain sensor NTC temperature in °C (for heater control)
            light_sensor_raw: Raw period from new light sensor
            mpsas: Sky quality in mag/arcsec² (if light sensor present)
            is_daylight: True if light level indicates daylight

        Note: ambient_temp_c is NOT included - must be obtained from PWS.
        """
        sky_temps = []
        rain_freqs = []
        light_raws = []
        pwm_values = []
        rain_sensor_temps = []

        for _ in range(num_samples):
            # Sky temperature
            sky = self.read_sky_temp()
            if sky is not None:
                sky_temps.append(sky)

            # Sensor values (LDR, rain sensor temp, light sensor)
            values = self.read_values()
            if values:
                if 'light_sensor_raw' in values:
                    light_raws.append(values['light_sensor_raw'])
                if 'rain_sensor_temp_c' in values:
                    rain_sensor_temps.append(values['rain_sensor_temp_c'])

            # Rain frequency
            rain = self.read_rain_freq()
            if rain is not None:
                rain_freqs.append(rain)

            # Heater PWM
            pwm = self.read_pwm()
            if pwm is not None:
                pwm_values.append(pwm)

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

        # Rain sensor (Type C thresholds: Dry > 2100, Wet = 1700-2100, Rain < 1700)
        if rain_freqs:
            rain_freq = int(self._filtered_average(rain_freqs))
            result['rain_freq'] = rain_freq
            result['is_raining'] = rain_freq < config.RAIN_THRESHOLD
            result['is_wet'] = rain_freq < config.WET_THRESHOLD

        # Heater PWM (0-1023 raw value)
        if pwm_values:
            result['heater_pwm'] = int(self._filtered_average(pwm_values))

        # Rain sensor temperature (for heater control feedback loop)
        if rain_sensor_temps:
            result['rain_sensor_temp_c'] = round(self._filtered_average(rain_sensor_temps), 2)

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
        self._pwm = 0

    def close(self):
        pass

    def set_pwm(self, value: int) -> bool:
        """Simulate PWM setting."""
        self._pwm = max(0, min(1023, value))
        logger.debug(f"Dummy: PWM set to {self._pwm}")
        return True

    def read_all(self, num_samples: int = 5) -> Dict:
        """Return simulated data."""
        import random

        sky = random.uniform(-30, 10)  # Sky temperature (always cold)
        rain_freq = random.randint(1500, 3500)
        mpsas = random.uniform(5, 21)  # 5 = bright daylight, 21 = dark sky
        # Rain sensor temp slightly above ambient (heated)
        rain_sensor_temp = random.uniform(10, 25)

        return {
            'sky_temp_c': round(sky, 2),
            'rain_freq': rain_freq,
            'is_raining': rain_freq < 1700,
            'is_wet': rain_freq < 2100,
            'heater_pwm': self._pwm,
            'rain_sensor_temp_c': round(rain_sensor_temp, 2),
            'light_sensor_raw': random.randint(10, 1000),
            'mpsas': round(mpsas, 2),
            'is_daylight': mpsas < 10,
        }

    def read_device_info(self) -> Dict:
        return {'name': 'DummyCloudWatcher', 'firmware': '0.0.0'}
