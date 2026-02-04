"""
CloudWatcher Rain Sensor Heating Controller
Modified: 2026-02-04 20:40 - Initial creation
Modified: 2026-02-04 21:00 - BUGFIX: Impulse heating only when WET, not just cold

Implements the AAG CloudWatcher rain sensor heater control algorithm.
Based on INDI driver implementation and manufacturer Variations table.

The heater keeps the rain sensor above ambient temperature to:
- Prevent condensation (dew)
- Dry the sensor after rain
- Ensure accurate rain detection

Control strategy:
1. Target: Keep sensor temp slightly above ambient (delta = 4-8°C)
2. PWM lookup from Variations table based on ambient temp
3. Impulse heating ONLY when sensor is WET (to dry it quickly)
"""

import logging
from typing import Optional, Tuple
from datetime import datetime, timezone

logger = logging.getLogger(__name__)

# INDI driver defaults (= manufacturer defaults)
DEFAULT_MIN_DELTA = 4.0      # Minimum temp difference sensor-ambient
DEFAULT_MAX_DELTA = 8.0      # Maximum temp difference sensor-ambient
DEFAULT_IMPULSE_TEMP = 10.0  # Below this, use impulse heating
DEFAULT_IMPULSE_DURATION = 60   # Impulse duration in seconds
DEFAULT_IMPULSE_CYCLE = 600     # Impulse cycle period in seconds

# PWM range
PWM_MIN = 0
PWM_MAX = 1023

# Variations table from AAG CloudWatcher documentation
# Maps ambient temperature to base PWM value
# Format: (temp_threshold, pwm_value) - use pwm if ambient >= threshold
VARIATIONS_TABLE = [
    (-28, 1023),  # -28°C and below: maximum heating
    (-25, 1000),
    (-22, 980),
    (-19, 960),
    (-16, 940),
    (-13, 920),
    (-10, 900),
    (-7, 880),
    (-4, 860),
    (-1, 840),
    (2, 800),
    (5, 750),
    (8, 700),
    (11, 650),
    (14, 600),
    (17, 550),
    (20, 500),
    (23, 450),
    (26, 400),
    (29, 350),
    (32, 300),
    (35, 0),      # 35°C and above: no heating needed
]


class HeatingController:
    """
    Rain sensor heater controller using manufacturer algorithm.

    The controller aims to keep the rain sensor temperature
    slightly above ambient to prevent condensation and ensure
    accurate rain detection.
    """

    def __init__(
        self,
        min_delta: float = DEFAULT_MIN_DELTA,
        max_delta: float = DEFAULT_MAX_DELTA,
        impulse_temp: float = DEFAULT_IMPULSE_TEMP,
        impulse_duration: int = DEFAULT_IMPULSE_DURATION,
        impulse_cycle: int = DEFAULT_IMPULSE_CYCLE,
    ):
        """
        Initialize heater controller.

        Args:
            min_delta: Minimum temperature difference sensor-ambient (°C)
            max_delta: Maximum temperature difference sensor-ambient (°C)
            impulse_temp: Ambient temp threshold for impulse heating (°C)
            impulse_duration: Duration of impulse heating (seconds)
            impulse_cycle: Period of impulse heating cycle (seconds)
        """
        self.min_delta = min_delta
        self.max_delta = max_delta
        self.impulse_temp = impulse_temp
        self.impulse_duration = impulse_duration
        self.impulse_cycle = impulse_cycle

        # State for impulse timing
        self._last_impulse_start: Optional[datetime] = None
        self._in_impulse = False

        # Last calculated values (for API/debugging)
        self.last_ambient: Optional[float] = None
        self.last_sensor_temp: Optional[float] = None
        self.last_delta: Optional[float] = None
        self.last_pwm: int = 0
        self.last_reason: str = "not_calculated"

        logger.info(
            f"HeatingController initialized: delta={min_delta}-{max_delta}°C, "
            f"impulse_temp={impulse_temp}°C, impulse={impulse_duration}s/{impulse_cycle}s"
        )

    def _lookup_base_pwm(self, ambient_temp: float) -> int:
        """
        Look up base PWM value from Variations table.

        Args:
            ambient_temp: Current ambient temperature in °C

        Returns:
            Base PWM value (0-1023)
        """
        # Find the appropriate PWM for the temperature
        # Table is sorted from coldest to warmest
        for threshold, pwm in VARIATIONS_TABLE:
            if ambient_temp < threshold:
                return pwm

        # Above all thresholds (very hot) - no heating
        return 0

    def _check_impulse(self, is_wet: bool) -> bool:
        """
        Check if we should apply impulse heating.

        Impulse heating is a full-power burst to DRY the sensor when wet.
        It is NOT for general cold-weather heating - that uses the Variations table.

        Args:
            is_wet: True if rain sensor detects moisture (rain_freq < WET_THRESHOLD)

        Returns:
            True if impulse heating should be active
        """
        # Only use impulse heating when sensor is WET
        if not is_wet:
            self._in_impulse = False
            self._last_impulse_start = None  # Reset cycle when dry
            return False

        now = datetime.now(timezone.utc)

        # First impulse when becoming wet
        if self._last_impulse_start is None:
            self._last_impulse_start = now
            self._in_impulse = True
            logger.info("Sensor wet - starting impulse heating to dry")
            return True

        elapsed = (now - self._last_impulse_start).total_seconds()

        # Within impulse duration - full power
        if elapsed < self.impulse_duration:
            self._in_impulse = True
            return True

        # After impulse, before next cycle - use normal control
        if elapsed < self.impulse_cycle:
            self._in_impulse = False
            return False

        # Start new impulse cycle (still wet after full cycle)
        self._last_impulse_start = now
        self._in_impulse = True
        logger.info("Sensor still wet - starting new impulse heating cycle")
        return True

    def calculate_pwm(
        self,
        sensor_temp: float,
        ambient_temp: float,
        rain_freq: Optional[int] = None,
        wet_threshold: int = 2100,
    ) -> Tuple[int, str]:
        """
        Calculate heater PWM value based on current conditions.

        The algorithm:
        1. If sensor is WET: impulse heating (full power to dry)
        2. Calculate delta = sensor_temp - ambient_temp
        3. If delta >= max_delta: heating not needed (sensor warm enough)
        4. If delta < min_delta: use base PWM from Variations table
        5. If min_delta <= delta < max_delta: proportional control

        Args:
            sensor_temp: Current rain sensor temperature (°C)
            ambient_temp: Current ambient temperature from ESP (°C)
            rain_freq: Rain sensor frequency (Hz), used for wet detection
            wet_threshold: Frequency below which sensor is considered wet

        Returns:
            Tuple of (pwm_value, reason_string)
        """
        # Store for API/debugging
        self.last_ambient = ambient_temp
        self.last_sensor_temp = sensor_temp

        # Calculate temperature difference
        delta = sensor_temp - ambient_temp
        self.last_delta = delta

        # Determine if sensor is wet
        is_wet = rain_freq is not None and rain_freq < wet_threshold

        # Check impulse heating first (only when WET - to dry the sensor)
        if self._check_impulse(is_wet):
            pwm = PWM_MAX
            reason = f"impulse_drying (rain_freq={rain_freq}Hz < {wet_threshold}Hz)"
            self.last_pwm = pwm
            self.last_reason = reason
            logger.debug(f"Heater: {reason}, PWM={pwm}")
            return (pwm, reason)

        # Sensor already warm enough
        if delta >= self.max_delta:
            pwm = PWM_MIN
            reason = f"sensor_warm (delta={delta:.1f}°C >= {self.max_delta}°C)"
            self.last_pwm = pwm
            self.last_reason = reason
            logger.debug(f"Heater: {reason}, PWM={pwm}")
            return (pwm, reason)

        # Get base PWM from Variations table
        base_pwm = self._lookup_base_pwm(ambient_temp)

        # Sensor too cold - full base heating
        if delta < self.min_delta:
            pwm = base_pwm
            reason = f"sensor_cold (delta={delta:.1f}°C < {self.min_delta}°C)"
            self.last_pwm = pwm
            self.last_reason = reason
            logger.debug(f"Heater: {reason}, PWM={pwm}")
            return (pwm, reason)

        # Proportional control in the delta range
        # Linear interpolation between base_pwm and 0
        # delta = min_delta -> pwm = base_pwm
        # delta = max_delta -> pwm = 0
        range_delta = self.max_delta - self.min_delta
        factor = (self.max_delta - delta) / range_delta
        pwm = int(base_pwm * factor)
        pwm = max(PWM_MIN, min(PWM_MAX, pwm))

        reason = f"proportional (delta={delta:.1f}°C, factor={factor:.2f})"
        self.last_pwm = pwm
        self.last_reason = reason
        logger.debug(f"Heater: {reason}, PWM={pwm}")
        return (pwm, reason)

    def get_status(self) -> dict:
        """Return current controller status for API/debugging."""
        return {
            'ambient_temp': self.last_ambient,
            'sensor_temp': self.last_sensor_temp,
            'delta': self.last_delta,
            'pwm': self.last_pwm,
            'reason': self.last_reason,
            'in_impulse': self._in_impulse,
            'config': {
                'min_delta': self.min_delta,
                'max_delta': self.max_delta,
                'impulse_temp': self.impulse_temp,
                'impulse_duration': self.impulse_duration,
                'impulse_cycle': self.impulse_cycle,
            }
        }
