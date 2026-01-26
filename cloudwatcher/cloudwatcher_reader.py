"""
CloudWatcher RS232 Reader Module
Modified: 2026-01-25 15:30 - Initial creation

Handles serial communication with AAG CloudWatcher sensor.
Protocol based on RS232_Comms_v100.pdf documentation.
"""

import serial
import math
import time
import logging
from typing import Optional, Dict, List, Tuple

import config

logger = logging.getLogger(__name__)

# Constants
ABSZERO = 273.15
BLOCK_SIZE = 15
HANDSHAKE_XON = 0x11


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
            'T!': 2,  # Sensor temperature
            'C!': 5,  # Values (ambient, LDR, rain sensor, zener)
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
        value_str = block[2:14].decode('ascii', errors='ignore').strip()
        
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
    
    def _calc_ambient_temp(self, raw: int) -> float:
        """Convert raw NTC value to temperature in °C."""
        if raw > 1022:
            raw = 1022
        if raw < 1:
            raw = 1
        
        r = config.NTC_PULLUP_RESISTANCE / ((1023.0 / raw) - 1)
        r = math.log(r / config.NTC_RES_AT_25)
        temp = 1.0 / (r / config.NTC_BETA + 1.0 / (ABSZERO + 25)) - ABSZERO
        
        return round(temp, 2)
    
    def _calc_ldr(self, raw: int) -> float:
        """Convert raw LDR value to resistance in kOhm."""
        if raw > 1022:
            raw = 1022
        if raw < 1:
            raw = 1
        
        ldr = config.LDR_PULLUP_RESISTANCE / ((1023.0 / raw) - 1)
        return round(ldr, 1)
    
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
    
    def read_sensor_temp(self) -> Optional[float]:
        """Read IR sensor temperature in °C."""
        response = self._send_command('T!')
        if not response:
            return None
        
        parsed = self._parse_response(response)
        if '2' in parsed:
            try:
                return int(parsed['2']) / 100.0
            except ValueError:
                pass
        return None
    
    def read_values(self) -> Optional[Dict]:
        """Read ambient, LDR, rain sensor temp, zener values."""
        response = self._send_command('C!')
        if not response:
            return None
        
        parsed = self._parse_response(response)
        result = {}
        
        try:
            if '3' in parsed:
                result['ambient_raw'] = int(parsed['3'])
                result['ambient_temp_c'] = self._calc_ambient_temp(result['ambient_raw'])
            if '4' in parsed:
                result['ldr_raw'] = int(parsed['4'])
                result['ldr_kohm'] = self._calc_ldr(result['ldr_raw'])
            if '5' in parsed:
                result['rain_sensor_raw'] = int(parsed['5'])
            if '6' in parsed:
                result['zener_raw'] = int(parsed['6'])
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
            # Device name is typically in a non-standard block
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
        Read all values with statistical filtering.
        Takes multiple samples and filters outliers.
        """
        sky_temps = []
        sensor_temps = []
        ambient_raws = []
        ldr_raws = []
        rain_freqs = []
        
        for _ in range(num_samples):
            # Sky temperature
            sky = self.read_sky_temp()
            if sky is not None:
                sky_temps.append(sky)
            
            # Sensor temperature
            sensor = self.read_sensor_temp()
            if sensor is not None:
                sensor_temps.append(sensor)
            
            # Values (ambient, LDR, etc.)
            values = self.read_values()
            if values:
                if 'ambient_raw' in values:
                    ambient_raws.append(values['ambient_raw'])
                if 'ldr_raw' in values:
                    ldr_raws.append(values['ldr_raw'])
            
            # Rain frequency
            rain = self.read_rain_freq()
            if rain is not None:
                rain_freqs.append(rain)
            
            time.sleep(0.1)  # Small delay between samples
        
        # Check if we got enough data
        if not sky_temps or not ambient_raws:
            logger.warning("Insufficient data collected")
            return None
        
        # Calculate filtered averages
        sky_temp = self._filtered_average(sky_temps)
        ambient_raw_avg = self._filtered_average(ambient_raws)
        ambient_temp = self._calc_ambient_temp(int(ambient_raw_avg))
        
        result = {
            'sky_temp_c': round(sky_temp, 2),
            'ambient_temp_c': ambient_temp,
            'delta_c': round(ambient_temp - sky_temp, 2),
        }
        
        if sensor_temps:
            result['sensor_temp_c'] = round(self._filtered_average(sensor_temps), 2)
        
        if ldr_raws:
            ldr_raw_avg = self._filtered_average(ldr_raws)
            result['ldr_kohm'] = self._calc_ldr(int(ldr_raw_avg))
            result['is_daylight'] = result['ldr_kohm'] < config.LDR_DAYLIGHT_THRESHOLD
        
        if rain_freqs:
            result['rain_freq'] = int(self._filtered_average(rain_freqs))
            result['is_raining'] = result['rain_freq'] < config.RAIN_THRESHOLD
        
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
        
        ambient = random.uniform(-5, 25)
        sky = ambient - random.uniform(5, 30)  # Sky is always colder
        
        return {
            'sky_temp_c': round(sky, 2),
            'ambient_temp_c': round(ambient, 2),
            'delta_c': round(ambient - sky, 2),
            'sensor_temp_c': round(ambient + random.uniform(-1, 1), 2),
            'ldr_kohm': round(random.uniform(1, 500), 1),
            'is_daylight': random.choice([True, False]),
            'rain_freq': random.randint(1500, 2800),
            'is_raining': random.choice([True, False, False, False]),  # 25% chance
        }
    
    def read_device_info(self) -> Dict:
        return {'name': 'DummyCloudWatcher', 'firmware': '0.0.0'}
