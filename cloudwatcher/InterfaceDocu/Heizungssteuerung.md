# CloudWatcher Regensensor-Heizungssteuerung

## Dokumentation und Analyse

**Autor:** Dr. Ralf Korell
**Erstellt:** 2026-02-04
**Version:** 1.6 (Updated: 2026-02-05 - ESP dual sensors im Dashboard)

---

## Inhaltsverzeichnis

1. [Zusammenfassung](#1-zusammenfassung)
2. [Hintergrund und Problemstellung](#2-hintergrund-und-problemstellung)
3. [Technische Grundlagen](#3-technische-grundlagen)
4. [Der "heater_pwm Hack" - Analyse eines Missverständnisses](#4-der-heater_pwm-hack---analyse-eines-missverständnisses)
5. [Offizielle Dokumentation und Quellen](#5-offizielle-dokumentation-und-quellen)
6. [VB-Beispiele und COM-Interface](#6-vb-beispiele-und-com-interface)
7. [INDI-Implementierung als Referenz](#7-indi-implementierung-als-referenz)
8. [Temperatur-Konvertierung](#8-temperatur-konvertierung)
9. [PWM-Steuerung](#9-pwm-steuerung)
10. [Heizungsalgorithmus](#10-heizungsalgorithmus)
11. [Empfohlene Python-Implementierung](#11-empfohlene-python-implementierung)
12. [Auswirkungen auf WMO-Code-Ableitung](#12-auswirkungen-auf-wmo-code-ableitung)
13. [Offene Punkte und nächste Schritte](#13-offene-punkte-und-nächste-schritte)
14. [Bugfix-Historie](#14-bugfix-historie)

---

## 1. Zusammenfassung

### Kernerkenntnisse

1. **Die Heizungssteuerung ist NICHT im CloudWatcher-Gerät implementiert.**  
   Sie muss von externer Software (Windows CloudWatcher Software, INDI, ASCOM, oder eigener Code) übernommen werden.

2. **Der "heater_pwm als Frühindikator für Regen"-Hack ist ein Missverständnis.**  
   Der PWM-Wert ist ein Steuerungswert, den die Software setzt - kein unabhängiger Sensorwert.

3. **Der echte Nutzen der Heizung:**  
   Sie hält den kapazitiven Regensensor warm und trocken, wodurch `rain_freq` genauer und zuverlässiger wird.

4. **Für die WMO-Code-Ableitung:**  
   Direkt `rain_freq` verwenden, nicht `heater_pwm`.

---

## 2. Hintergrund und Problemstellung

### Ausgangssituation

Bei der Integration des AAG CloudWatcher in das MagicMirror Weather-Modul wurde beobachtet:

- `heater_pwm` war konstant bei 100 (Rohwert)
- Dieser Wert änderte sich nie, unabhängig von Wetterbedingungen
- Die ursprüngliche Annahme war, dass `heater_pwm > 30` ein Indikator für Feuchtigkeit sei

### Die Fragen

1. Warum ist `heater_pwm` konstant?
2. Wird die Heizung automatisch vom Gerät gesteuert?
3. Ist `heater_pwm` ein valider Feuchtigkeitsindikator?
4. Wie sollte die Heizung korrekt gesteuert werden?

### Die Antworten (Kurzfassung)

1. Konstant, weil **keine Software den PWM steuert**
2. **Nein**, die Heizung wird von externer Software gesteuert
3. **Nein**, es ist ein abgeleiteter Wert von `rain_freq`
4. Basierend auf Außentemperatur und `rain_freq` nach INDI-Algorithmus

---

## 3. Technische Grundlagen

### 3.1 Der kapazitive Regensensor

Der CloudWatcher verwendet einen kapazitiven Sensor zur Regenerkennung:

- **Prinzip:** Variabler Kondensator, dessen Kapazität sich bei Feuchtigkeit ändert
- **Ausgabe:** `rain_freq` - Frequenz in Hz
- **Integrierte Heizung:** Widerstandsheizung im Sensor zum Trocknen

### 3.2 Schwellenwerte (Sensor Type C)

| Zustand | rain_freq | Bedeutung |
|---------|-----------|-----------|
| Dry | > 2100 Hz | Sensor trocken |
| Wet | 1700-2100 Hz | Feuchtigkeit auf Oberfläche |
| Rain | < 1700 Hz | Aktiver Niederschlag |

Quelle: RainSensorCalibration.pdf, Seite 2

### 3.3 PWM-Heizung

- **Wertebereich:** 0-1023 (10-bit)
- **Kommando lesen:** `Q!` → Antwort `!Q xxxx`
- **Kommando setzen:** `Pxxxx!` (4-stellig, z.B. `P0512!`)
- **Umrechnung:** `PWM% = raw * 100 / 1023`

### 3.4 Regelkreis der Heizungssteuerung

```
                            Störgrößen z
                           (Wind, Regen, Kälte)
                                  │
                                  ▼
┌────────────────────────────────────────────────────────────────────┐
│                                                                    │
│   w                 e                 u                 y          │
│ ┌─────┐    ⊕    ┌────────┐    ┌──────────┐    ┌───────┐          │
│ │Soll-│───►(○)─►│ Regler │───►│ Heizung  │───►│Sensor │────┬─────│
│ │wert │    -│   │(Variat.)│   │(PWM 0-1023)   │ (NTC) │    │     │
│ └─────┘     │   └────────┘    └──────────┘    └───────┘    │     │
│             │                                               │     │
│             │              Rückführung                      │     │
│             └───────────────────────────────────────────────┘     │
│                                                                    │
└────────────────────────────────────────────────────────────────────┘

Vorgeschaltete Sollwert-Berechnung:

  ambient_temp ───►┌────────────────┐───► desired_temp (w)
   (PWS/ESP)       │ w = f(ambient) │
                   └────────────────┘
```

**Variablen im Regelkreis:**

| Symbol | Name | Beschreibung | Quelle |
|--------|------|--------------|--------|
| w | desired_temp | Sollwert (Soll-Sensortemperatur) | Berechnet aus ambient_temp |
| y | rain_sensor_temp | Istwert (aktuelle Sensortemperatur) | CloudWatcher Type 5 (NTC) |
| e | Regeldifferenz | e = w - y | Berechnet |
| u | heater_pwm | Stellgröße (Heizleistung) | Wird an CloudWatcher gesendet |
| z | Störgrößen | Wind, Regen, Abstrahlung | Umgebung |

**Warum der Sensor WÄRMER als Umgebung sein soll:**
- Verhindert Kondensation (Tau) auf dem Sensor
- Trocknet Regentropfen schneller ab
- Liefert dadurch zuverlässigere `rain_freq` Werte

---

## 4. Der "heater_pwm Hack" - Analyse eines Missverständnisses

### 4.1 Die Behauptung

> "heater_pwm steigt BEVOR rain_freq auf 'rain' fällt - ein Frühindikator!"

### 4.2 Die tatsächliche Logik

```
Zeitlicher Ablauf mit Steuer-Software:

t=0:  rain_freq = 2500 (trocken)
      PWM = 10% (Minimum)

t=1:  rain_freq = 1900 (fällt unter wet_threshold 2100)
      Software erkennt "wet" → setzt PWM = 50%

t=2:  rain_freq = 1600 (fällt unter rain_threshold 1700)
      Software meldet "RAIN ALARM"
```

**Beobachtung:** PWM stieg bei t=1, Alarm kam bei t=2  
**Fehlschluss:** "PWM ist ein Frühindikator!"  
**Realität:** Software reagierte auf rain_freq - PWM ist ABGELEITET

### 4.3 Warum der "Hack" ein Zirkelschluss ist

```
Die Software schreibt:     PWM = f(rain_freq, temperature)
Jemand liest:              PWM
Jemand interpretiert:      "PWM hoch = Feuchtigkeit"

Das ist REDUNDANT - man könnte direkt rain_freq prüfen!
```

### 4.4 Warum es bei uns nicht funktionierte

- Keine Windows CloudWatcher Software
- Kein INDI-Treiber
- Kein ASCOM-Treiber
- **→ Niemand steuerte den PWM**
- **→ PWM blieb auf altem Wert (100)**

### 4.5 Schlussfolgerung

Der "heater_pwm Hack" ist:
- **Kein echter Hack**, sondern ein Missverständnis
- **Nur sichtbar** wenn Steuer-Software läuft
- **Redundant** zu rain_freq
- **Für uns unbrauchbar** als unabhängiger Indikator

---

## 5. Offizielle Dokumentation und Quellen

### 5.1 Rs232_Comms_v100.pdf (Seite 4, Punkt 6)

> "The algorithm that controls the heating cycles of the rain sensor is also 
> programmed in the Visual Basic 6 main program and **not in the device 
> microprocessor**."

**Dies ist der offizielle Beweis:** Die Heizungssteuerung ist NICHT im Gerät!

### 5.2 Verfügbare Dokumentation

| Dokument | Version | Inhalt |
|----------|---------|--------|
| Rs232_Comms_v100.pdf | 1.0 | Basis-Protokoll, Konvertierungsformeln |
| Rs232_Comms_v110.pdf | 1.1 | Updates |
| Rs232_Comms_v120.pdf | 1.2 | Updates |
| Rs232_Comms_v130.pdf | 1.3 | Updates |
| Rs232_Comms_v140.pdf | 1.4 | MPSAS Light Sensor |
| RainSensorCalibration.pdf | - | Sensor-Kalibrierung |

Speicherort: `InterfaceDocu/` (im Modul-Verzeichnis)

---

## 6. VB-Beispiele und COM-Interface

### 6.1 Windows CloudWatcher Software (SoloCloud.exe)

Die offizielle Windows-Software von Lunatico stellt ein **COM-Objekt** bereit:

```vb
Set obj = CreateObject("AAG_CloudWatcher.CloudWatcher")
```

Dieses COM-Objekt ermöglicht externen Programmen Zugriff auf Sensordaten und Konfiguration.

### 6.2 VB Example 1 - Parameter lesen/setzen

Datei: `VB Example1.zip` / `Example1Form.frm`

**Verfügbare Funktionen:**
- `Device_Start` / `Device_Stop` - Kommunikation starten/stoppen
- Schwellwert-Konfiguration (Cloud, Rain, Brightness)
- Temperature Factor K1-K5 (für sky temp Korrektur)
- Safe-Flags für Alarm-Entscheidungen
- Sound-Dateien für Alarme

**Wichtig:** Keine Heizungssteuerung über COM-Interface! Die Heizung wird intern von SoloCloud.exe gesteuert und ist nicht von außen steuerbar.

### 6.3 VB Example 2 - Sensordaten lesen

Datei: `VB Example2.zip` / `Example2Form.frm`

**Verfügbare Properties (Lesezugriff):**

| Property | Bedeutung |
|----------|-----------|
| `RainHeaterPercent` | Aktuelle Heizleistung in % (nur lesen!) |
| `RainHeaterStatus` | Heizungsstatus |
| `RainHeaterTemperature` | Regensensor-Temperatur |
| `SkyTemperature` | Korrigierte Himmelstemp |
| `SkySensorTemperature` | Rohe IR-Sensortemp |
| `RainValue` | rain_freq Rohwert |
| `BrightnessValue` | Helligkeits-Rohwert |
| `SwitchStatus` | Relais (Open/Close/Unknown) |
| `Safe` | Gesamtstatus (Safe/Unsafe) |

**Erkenntnis:** `RainHeaterPercent` ist nur ein **Lesewert**. Die Heizungslogik ist komplett intern in SoloCloud.exe implementiert und nicht über das COM-Interface steuerbar.

### 6.4 Konsequenz für unsere Implementierung

Da wir:
- Kein Windows verwenden (Raspberry Pi / Linux)
- Kein SoloCloud.exe haben
- Direkt per RS232/Serial kommunizieren

...müssen wir den Heizungsalgorithmus selbst implementieren. Die INDI-Implementierung dient als Referenz (siehe Abschnitt 7).

---

## 7. INDI-Implementierung als Referenz

### 7.1 Warum INDI die Referenz ist

- 10+ Jahre Produktionseinsatz in Observatorien
- Von Lunatico (Hersteller) validiert
- Community-getestet und gepflegt
- Vollständig dokumentiert im Code

**INDI-Quellcode:**
- Repository: https://github.com/indilib/indi-3rdparty
- Pfad: `indi-aagcloudwatcher-ng/`
- Hauptdateien:
  - `indi_aagcloudwatcher_ng.cpp` - Heizungsalgorithmus
  - `CloudWatcherController_ng.cpp` - Hardware-Kommunikation
  - `indi_aagcloudwatcher_ng_sk.xml` - Default-Parameter

### 7.2 Heizungs-Parameter (INDI Defaults = Hersteller-Defaults)

| Parameter | Default | Range | Bedeutung |
|-----------|---------|-------|-----------|
| tempLow | 0°C | -50 bis 100 | Untere Temperatur-Schwelle |
| tempHigh | 20°C | -50 bis 100 | Obere Temperatur-Schwelle |
| deltaLow | 6°C | 0 bis 50 | Soll-Temperatur bei Kälte |
| deltaHigh | 4°C | 0 bis 50 | Temperatur-Offset bei Wärme |
| min | 10% | 1 bis 20 | Minimale Heizleistung |
| heatImpulseTemp | 10°C | 1 bis 30 | Ziel-Temp bei Nässe-Impuls |
| heatImpulseDuration | 60s | 0 bis 600 | Aufheiz-Phase |
| heatImpulseCycle | 600s | 60 bis 1000 | Halte-Phase |

Quelle: `indi_aagcloudwatcher_ng_sk.xml`

**Hinweis:** Die INDI-Defaults entsprechen 1:1 den Hersteller-Defaults aus RainSensorHeaterAlgorithm.pdf!

### 7.3 State Machine

```
┌─────────────────────────────────────────────────────────────┐
│                    HEIZUNGS-STATE-MACHINE                   │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  NORMAL ──────[wet für >600s]────→ INCREASING_TO_PULSE     │
│    │                                       │                │
│    │                               [60s vergangen]         │
│    │                                       ↓                │
│    │                                   PULSE               │
│    │                                       │                │
│    ←────────[dry UND cycle vorbei]─────────┘               │
│                                                             │
│  In NORMAL: PWM = proportional zu (desired - sensor_temp)  │
│  In PULSE:  PWM = 100% (maximale Heizung zum Trocknen)    │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

---

## 8. Temperatur-Konvertierung

### 8.1 Rain Sensor Temperature (Type 5)

Der Wert von Type 5 ist ein NTC-Thermistor-Rohwert (0-1023 ADC).

**Konvertierung nach Steinhart-Hart:**

```python
import math

# Konstanten (aus INDI-Code)
RAIN_PULLUP_KOHM = 9.9
RAIN_RES_AT_25_KOHM = 10.0
RAIN_BETA = 3811
ABS_ZERO = 273.15

def convert_rain_sensor_temp(raw_adc: int) -> float:
    """
    Konvertiert NTC-Rohwert zu Celsius.
    
    Formel aus Rs232_Comms_v100.pdf, Seite 5-6,
    mit Konstanten aus INDI-Code.
    """
    # Clamp to valid range
    if raw_adc > 1022:
        raw_adc = 1022
    if raw_adc < 1:
        raw_adc = 1
    
    # Widerstand berechnen (Spannungsteiler)
    r_kohm = RAIN_PULLUP_KOHM / ((1023.0 / raw_adc) - 1.0)
    
    # Steinhart-Hart Gleichung
    ln_r = math.log(r_kohm / RAIN_RES_AT_25_KOHM)
    temp_kelvin = 1.0 / (ln_r / RAIN_BETA + 1.0 / (ABS_ZERO + 25.0))
    
    return temp_kelvin - ABS_ZERO
```

**Hinweis zur Diskrepanz:**

| Quelle | PullUp | ResAt25 | Beta |
|--------|--------|---------|------|
| Doku v100 | 1 kΩ | 1 kΩ | 3450 |
| INDI Code | 9.9 kΩ | 10 kΩ | 3811 |

Die INDI-Werte sind empirisch validiert und sollten verwendet werden.

### 8.2 IR Sky Temperature (Type 1)

```python
def convert_ir_temperature(raw_value: int) -> float:
    """Konvertiert Type 1 (Sky) zu Celsius."""
    return raw_value / 100.0
```

### 8.3 MPSAS Light Sensor (Type 8)

```python
SQ_REFERENCE = 19.6

def convert_mpsas(raw_period: int, ambient_temp_c: float = 10.0) -> float:
    """
    Konvertiert Lichtsensor-Periode zu MPSAS.
    Formel aus Rs232_Comms_v140.pdf, Seite 2.
    """
    if raw_period <= 0:
        return None
    
    mpsas = SQ_REFERENCE - 2.5 * math.log10(250000 / raw_period)
    mpsas_corrected = (mpsas - 0.042) + (0.00212 * ambient_temp_c)
    
    return mpsas_corrected
```

---

## 9. PWM-Steuerung

### 9.1 PWM lesen (Q! Kommando)

```python
def read_pwm(serial_conn) -> int:
    """Liest aktuellen PWM-Rohwert (0-1023)."""
    serial_conn.write(b'Q!')
    response = serial_conn.read(30)  # 2 Blöcke à 15 Bytes
    
    # Parse !Q block
    for i in range(len(response) // 15):
        block = response[i*15:(i+1)*15]
        if block[0] == ord('!') and block[1] == ord('Q'):
            value_str = block[2:15].decode('ascii').strip()
            return int(value_str)
    
    return None
```

### 9.2 PWM setzen (Pxxxx! Kommando)

```python
def set_pwm(serial_conn, percent: float) -> bool:
    """
    Setzt PWM-Heizleistung.
    
    Args:
        percent: Heizleistung in Prozent (0-100)
    
    Returns:
        True wenn erfolgreich
    """
    # Konvertiere Prozent zu Rohwert
    raw = int(percent * 1023 / 100)
    raw = max(0, min(1023, raw))
    
    # Kommando formatieren (4-stellig)
    cmd = f'P{raw:04d}!'.encode('ascii')
    
    serial_conn.write(cmd)
    response = serial_conn.read(30)
    
    # Validiere Echo
    # ... (response parsing)
    
    return True
```

### 9.3 Beispiele

| Prozent | Rohwert | Kommando |
|---------|---------|----------|
| 0% | 0 | P0000! |
| 10% | 102 | P0102! |
| 50% | 511 | P0511! |
| 100% | 1023 | P1023! |

---

## 10. Heizungsalgorithmus

### 10.1 Das "Variations"-Prinzip (RainSensorHeaterAlgorithm.pdf)

Die Tabelle auf Seite 3 der Hersteller-Dokumentation zeigt **inkrementelle PWM-Änderungen pro Zyklus**, NICHT absolute PWM-Werte:

| Temp-Differenz | Variation | Bedeutung |
|----------------|-----------|-----------|
| > 8°C zu kalt | +40% | PWM um 40% ERHÖHEN |
| 4-8°C zu kalt | +20% | PWM um 20% ERHÖHEN |
| 2-4°C zu kalt | +10% | PWM um 10% ERHÖHEN |
| 1-2°C zu kalt | +4% | PWM um 4% ERHÖHEN |
| 0-1°C zu kalt | +2% | PWM um 2% ERHÖHEN |
| 0-1°C zu warm | -2% | PWM um 2% VERRINGERN |
| 1-2°C zu warm | -4% | PWM um 4% VERRINGERN |
| 2-4°C zu warm | -10% | PWM um 10% VERRINGERN |
| 4-8°C zu warm | -20% | PWM um 20% VERRINGERN |
| > 8°C zu warm | -40% | PWM um 40% VERRINGERN |

**Beispiel-Ablauf (alle 10 Sekunden):**

```
Zyklus 1: Sensor 5°C, Ziel 15°C → Diff 10°C → PWM +40% (von 10% auf 14%)
Zyklus 2: Sensor 8°C, Ziel 15°C → Diff 7°C  → PWM +20% (von 14% auf 16.8%)
Zyklus 3: Sensor 12°C, Ziel 15°C → Diff 3°C → PWM +10% (von 16.8% auf 18.5%)
... bis Gleichgewicht erreicht
```

Dies ist ein **PI-Regler** (Proportional-Integral):
- Die Variation ist **proportional** zur Temperatur-Abweichung
- Durch wiederholte Anwendung **integriert** sich der Effekt

### 10.2 Soll-Temperatur berechnen

```python
def calc_desired_temp(ambient_temp: float,
                      temp_low: float = 0.0,
                      temp_high: float = 20.0,
                      delta_low: float = 6.0,
                      delta_high: float = 4.0) -> float:
    """
    Berechnet Soll-Temperatur für den Regensensor.
    
    Logik aus INDI heatingAlgorithm():
    - Bei Kälte (< temp_low): Feste Soll-Temp (delta_low)
    - Bei Wärme (> temp_high): Relativ zu ambient (+ delta_high)
    - Dazwischen: Lineare Interpolation
    """
    if ambient_temp < temp_low:
        return delta_low
    elif ambient_temp > temp_high:
        return ambient_temp + delta_high
    else:
        # Lineare Interpolation
        fraction = (ambient_temp - temp_low) / (temp_high - temp_low)
        return (delta_low * (1 - fraction) + 
                (ambient_temp + delta_high) * fraction)
```

**Beispiele mit INDI-Defaults:**

| Außentemp | Berechnung | Soll-Sensor-Temp |
|-----------|------------|------------------|
| -10°C | < 0°C → delta_low | 6°C |
| 0°C | = temp_low | 6°C |
| 10°C | Interpolation | 10°C |
| 20°C | = temp_high | 24°C |
| 30°C | > 20°C → ambient + delta_high | 34°C |

### 10.3 PWM berechnen (Proportional-Modus)

```python
def calc_pwm_proportional(sensor_temp: float,
                          desired_temp: float,
                          min_output: float = 10.0,
                          update_period: float = 10.0) -> int:
    """
    Berechnet PWM nach INDI Proportional-Algorithmus.
    """
    diff = desired_temp - sensor_temp
    
    # Nicht-lineare Modifier-Tabelle
    if diff > 8.0:
        modifier = 1.4
    elif diff > 4.0:
        modifier = 1.2
    elif diff > 3.0:
        modifier = 1.1
    elif diff > 2.0:
        modifier = 1.06
    elif diff > 1.0:
        modifier = 1.04
    elif diff > 0.5:
        modifier = 1.02
    elif diff > 0.3:
        modifier = 1.01
    else:
        modifier = 1.0
    
    # Skalierung nach Update-Intervall
    refresh_factor = math.sqrt(update_period / 10.0)
    modifier = modifier / refresh_factor
    
    # Output berechnen
    output = min_output + (modifier - 1.0) * 100.0
    output = max(min_output, min(100.0, output))
    
    return int(output)
```

### 10.4 Vollständige State Machine

```python
class HeatingController:
    """
    State Machine für Regensensor-Heizung.
    
    Basiert auf INDI heatingAlgorithm().
    """
    
    def __init__(self, config: dict = None):
        self.config = config or {
            'temp_low': 0.0,
            'temp_high': 20.0,
            'delta_low': 6.0,
            'delta_high': 4.0,
            'min_output': 10.0,
            'impulse_temp': 10.0,
            'impulse_duration': 60,
            'impulse_cycle': 600,
            'wet_threshold': 2100,
        }
        
        self.state = 'normal'
        self.state_start_time = None
    
    def update(self,
               sensor_temp: float,
               ambient_temp: float,
               rain_freq: int,
               current_time: float) -> int:
        """
        Aktualisiert State Machine und berechnet PWM.
        
        Args:
            sensor_temp: Regensensor-Temperatur (°C)
            ambient_temp: Außentemperatur (°C)
            rain_freq: Regensensor-Frequenz (Hz)
            current_time: Aktuelle Zeit (Sekunden)
        
        Returns:
            PWM in Prozent (0-100)
        """
        is_wet = rain_freq < self.config['wet_threshold']
        
        if self.state == 'normal':
            if is_wet:
                self.state = 'increasing_to_pulse'
                self.state_start_time = current_time
                return 100
            
            desired = self._calc_desired(ambient_temp)
            return self._calc_pwm(sensor_temp, desired)
        
        elif self.state == 'increasing_to_pulse':
            elapsed = current_time - self.state_start_time
            
            if elapsed >= self.config['impulse_duration']:
                self.state = 'pulse'
                self.state_start_time = current_time
            
            return 100
        
        elif self.state == 'pulse':
            elapsed = current_time - self.state_start_time
            
            if elapsed >= self.config['impulse_cycle']:
                if not is_wet:
                    self.state = 'normal'
                    return 0
                else:
                    self.state_start_time = current_time
            
            return 100
    
    def _calc_desired(self, ambient: float) -> float:
        """Berechnet Soll-Temperatur."""
        c = self.config
        
        if ambient < c['temp_low']:
            return c['delta_low']
        elif ambient > c['temp_high']:
            return ambient + c['delta_high']
        else:
            frac = (ambient - c['temp_low']) / (c['temp_high'] - c['temp_low'])
            return c['delta_low'] * (1 - frac) + (ambient + c['delta_high']) * frac
    
    def _calc_pwm(self, sensor: float, desired: float) -> int:
        """Berechnet PWM (Proportional-Modus)."""
        diff = desired - sensor
        
        if diff > 8.0: modifier = 1.4
        elif diff > 4.0: modifier = 1.2
        elif diff > 3.0: modifier = 1.1
        elif diff > 2.0: modifier = 1.06
        elif diff > 1.0: modifier = 1.04
        elif diff > 0.5: modifier = 1.02
        elif diff > 0.3: modifier = 1.01
        else: modifier = 1.0
        
        output = self.config['min_output'] + (modifier - 1.0) * 100.0
        return int(max(self.config['min_output'], min(100, output)))
```

---

## 11. Empfohlene Python-Implementierung

### 11.1 Architektur

```
┌─────────────────────────────────────────────────────────────┐
│  cloudwatcher_heater.py (NEU)                              │
│  - HeatingController Klasse                                 │
│  - State Machine                                            │
│  - Temperatur-Konvertierung                                 │
└─────────────────────────────────────────────────────────────┘
              │
              │ Wird verwendet von
              ▼
┌─────────────────────────────────────────────────────────────┐
│  cloudwatcher_service.py (ERWEITERT)                       │
│  - Holt Außentemperatur von ESP (http://172.23.56.150/api) │
│  - Ruft HeatingController.update() auf                     │
│  - Schreibt PWM via P-Kommando                             │
└─────────────────────────────────────────────────────────────┘
              │
              │ Kommuniziert mit
              ▼
┌─────────────────────────────────────────────────────────────┐
│  CloudWatcher Hardware                                      │
│  - Liest Sensordaten (S!, C!, E!, Q!)                      │
│  - Empfängt PWM-Kommandos (Pxxxx!)                         │
└─────────────────────────────────────────────────────────────┘
```

### 11.2 Außentemperatur-Quelle

**Option:** ESP-Temperatursensor unter http://172.23.56.150/api

```python
import requests

def get_ambient_temp() -> float:
    """Holt Außentemperatur vom ESP-Sensor."""
    try:
        response = requests.get('http://172.23.56.150/api', timeout=5)
        data = response.json()
        
        # "Schatten" Sensor verwenden (genauer)
        for sensor in data.get('sensors', []):
            if sensor.get('name') == 'Schatten':
                return sensor.get('value')
        
        return None
    except Exception as e:
        logging.error(f"ESP-Sensor nicht erreichbar: {e}")
        return None
```

### 11.3 Integration in cloudwatcher_service.py

```python
# In der Hauptschleife:

def run_service():
    reader = CloudWatcherReader()
    heater = HeatingController()
    
    while True:
        try:
            # Sensordaten lesen
            data = reader.read_all()
            
            # Außentemperatur holen
            ambient = get_ambient_temp()
            
            if data and ambient is not None:
                # Heizung steuern
                pwm = heater.update(
                    sensor_temp=data.get('rain_sensor_temp_c'),
                    ambient_temp=ambient,
                    rain_freq=data.get('rain_freq'),
                    current_time=time.time()
                )
                
                # PWM setzen
                reader.set_pwm(pwm)
                
                # Logging
                logging.info(
                    f"Heater: state={heater.state}, "
                    f"pwm={pwm}%, "
                    f"sensor={data.get('rain_sensor_temp_c'):.1f}°C, "
                    f"ambient={ambient:.1f}°C"
                )
        
        except Exception as e:
            logging.error(f"Fehler: {e}")
        
        time.sleep(10)  # 10 Sekunden Intervall
```

---

## 12. Auswirkungen auf WMO-Code-Ableitung

### 12.1 Der entfernte "Hack" (war in wmo_derivation.php)

```php
// ENTFERNT am 2026-02-04:
$heater_indicates_moisture = ($heater_pwm > 30) && $cw_is_wet;
$is_precipitating = ($precip_rate > 0) || $cw_is_raining || $heater_indicates_moisture;
```

**Warum entfernt:**
- `heater_pwm` ist ein Steuerungswert, kein Sensorwert
- Zirkuläre Logik (PWM wird aus rain_freq abgeleitet)
- Bei uns war PWM konstant 100 (keine Steuerung aktiv)

### 12.2 Aktuelle Logik (sauber)

```php
// In wmo_derivation.php (aktuell):
$is_precipitating = ($precip_rate > 0) || $cw_is_raining;
```

**Niederschlagserkennung durch zwei unabhängige Sensoren:**

| Sensor | Variable | Schwelle | Typ |
|--------|----------|----------|-----|
| PWS Tipping Bucket | `precip_rate` | > 0 mm/h | Mechanisch |
| CloudWatcher | `cw_is_raining` | rain_freq < 1700 Hz | Kapazitiv |

### 12.3 Warum das besser ist

| Aspekt | Mit heater_pwm (alt) | Ohne heater_pwm (neu) |
|--------|----------------------|-----------------------|
| Datenquelle | Abgeleitet | Direkt vom Sensor |
| Zuverlässigkeit | Abhängig von Steuerung | Unabhängig |
| Logik | Zirkulär | Eindeutig |
| Status | ~~ENTFERNT~~ | **AKTIV** |

---

## 13. Offene Punkte und nächste Schritte

### 13.1 Implementierungsstatus

1. **cloudwatcher_reader.py erweitern:**
   - [x] Type 5 (Rain Sensor Temp) auslesen und konvertieren ✓ (2026-02-04)
   - [x] `set_pwm()` Methode implementieren ✓ (2026-02-04)

2. **heating_controller.py erstellen:**
   - [x] HeatingController Klasse ✓ (2026-02-04)
   - [x] Variations-Table Algorithmus ✓ (2026-02-04)
   - [x] Impulse-Heating NUR bei Nässe ✓ (2026-02-04, Bugfix 21:00)
   - [x] Konfiguration via config.py ✓ (2026-02-04)

3. **cloudwatcher_service.py erweitern:**
   - [x] ESP-Temperatur-Abfrage ✓ (2026-02-04)
   - [x] Heizungssteuerung integrieren ✓ (2026-02-04)
   - [x] `/api/heater` Endpoint hinzugefügt ✓ (2026-02-04)
   - [x] READ_INTERVAL auf 10s reduziert ✓ (2026-02-04)

4. **wmo_derivation.php anpassen:**
   - [x] heater_pwm-Logik entfernen ✓ (2026-02-04)
   - [x] Direkt rain_freq verwenden ✓ (2026-02-04)

### 13.2 Aktive Konfiguration (config.py)

```python
# ESP Temperature Sensor (Temp2IoT)
ESP_URL = "http://172.23.56.150/api"
ESP_SENSOR_NAME = "Schatten"
ESP_TIMEOUT = 5

# Heater control settings (INDI/manufacturer defaults)
HEATER_ENABLED = True
HEATER_MIN_DELTA = 4.0      # Minimum temp difference sensor-ambient (°C)
HEATER_MAX_DELTA = 8.0      # Maximum temp difference sensor-ambient (°C)
HEATER_IMPULSE_TEMP = 10.0  # Below this ambient temp, use impulse heating (°C)
HEATER_IMPULSE_DURATION = 60   # Impulse duration (seconds)
HEATER_IMPULSE_CYCLE = 600     # Impulse cycle period (seconds)
```

### 13.3 Monitoring-API

**Endpoint:** `http://172.23.56.60:5000/api/heater`

**Beispiel (Normalbetrieb - Proportionalregelung):**
```json
{
    "enabled": true,
    "timestamp": "2026-02-04T21:30:00+00:00",
    "ambient_temp_c": 2.6,
    "sensor_temp_c": 10.6,
    "delta_c": 8.0,
    "target_pwm": 100,
    "actual_pwm": 100,
    "reason": "proportional (delta=8.0°C, factor=0.50)",
    "in_impulse": false,
    "config": {
        "min_delta": 4.0,
        "max_delta": 8.0,
        "impulse_temp": 10.0,
        "impulse_duration": 60,
        "impulse_cycle": 600
    }
}
```

**Beispiel (Impulse-Trocknung - nur bei NASSEM Sensor):**
```json
{
    "enabled": true,
    "timestamp": "2026-02-04T22:00:00+00:00",
    "ambient_temp_c": 5.2,
    "sensor_temp_c": 12.3,
    "delta_c": 7.1,
    "target_pwm": 1023,
    "actual_pwm": 1023,
    "reason": "impulse_drying (rain_freq=1850Hz < 2100Hz)",
    "in_impulse": true,
    "config": { ... }
}
```

**Wichtig:** Impulse-Heizung wird NUR aktiviert wenn `rain_freq < WET_THRESHOLD` (Sensor ist nass). Die Impulse-Heizung dient zum schnellen Trocknen des Sensors nach Regen, NICHT für allgemeine Kälteheizung.

### 13.4 Offene Punkte für die Zukunft

1. **Langzeit-Beobachtung:**
   - Verhalten bei verschiedenen Wetterbedingungen beobachten
   - Ggf. Parameter nachjustieren

2. **Logging in Datenbank:**
   - Heater-Status könnte in weather_readings gespeichert werden
   - Für Analyse und Optimierung

3. **Ungeklärte "100"-Werte in heater_pwm (2026-02-04):**
   - In der Datenbank tauchen vereinzelt glatte `100` als heater_pwm auf
   - Möglicherweise Timing-Issue während Service-Neustarts
   - Oder interner Gerätezustand vor erstem PWM-Kommando
   - Nicht kritisch, aber zur Beobachtung notiert

4. **ERLEDIGT: ESP Ambient-Temperaturen im Dashboard (2026-02-05):**

   **Ursprüngliches Problem:** Das Weather-Dashboard zeigte `rain_sensor_temp_c` und die PWS-Außentemperatur. Die Heizungssteuerung verwendet aber den ESP "Schatten"-Sensor als Ambient-Referenz - diese Werte können abweichen.

   **Umgesetzte Lösung (erweitert):** Beide ESP-Sensoren (Schatten + Sonne) durch die gesamte Kette:

   1. **CloudWatcher Service** (`cloudwatcher_service.py`): `fetch_esp_temps()` holt beide Sensoren
   2. **CloudWatcher API**: liefert `esp_temp_shadow_c` und `esp_temp_sun_c`
   3. **pws_receiver.php**: extrahiert und speichert beide Werte
   4. **PostgreSQL**: `esp_temp_shadow_c REAL`, `esp_temp_sun_c REAL` (migrate_004)
   5. **Dashboard**: ESP-Sensor Card mit beiden Temps nebeneinander (Schatten blau, Sonne gelb)

   **Zusätzliche Dashboard-Änderungen:**
   - MPSAS-Karte entfernt (Astronomie-Wert, nicht relevant für Wetter)
   - Therapie-Sensor Farbe von gelb auf grün geändert (Farbkollision vermeiden)
   - Cards vertikal unten ausgerichtet (ruhigeres Layout)

---

## 14. Bugfix-Historie

### 2026-02-04 21:00 - KRITISCH: Impulse-Heizung nur bei Nässe

**Problem:** Sensor überhitzte auf 25°C (Delta 22°C statt Ziel 4-8°C)

**Ursache:** Die ursprüngliche Implementierung aktivierte Impulse-Heizung wenn `ambient_temp < 10°C`, unabhängig davon ob der Sensor nass war. Dies führte zu permanenter Volllast-Heizung bei kaltem Wetter.

**Fehlerhafte Logik (heating_controller.py):**
```python
# FALSCH - Impuls bei Kälte
if self._check_impulse(ambient_temp):  # ambient < 10°C → Impuls
    pwm = PWM_MAX
```

**Korrigierte Logik:**
```python
# KORREKT - Impuls NUR bei nassem Sensor
is_wet = rain_freq is not None and rain_freq < wet_threshold
if self._check_impulse(is_wet):  # rain_freq < 2100 → Impuls
    pwm = PWM_MAX
```

**Erklärung:** Die Impulse-Heizung hat einen spezifischen Zweck: Den Sensor nach Regen schnell zu trocknen, damit `rain_freq` wieder zuverlässig wird. Sie ist KEINE allgemeine Kälteheizung - dafür ist die Proportionalregelung mit der Variations-Tabelle zuständig.

**Auswirkung nach Fix:** Sensor stabilisierte sich bei ~10°C (Delta 6-8°C wie gewünscht).

### 2026-02-04 20:40 - PWM-Kommando Acknowledgement

**Problem:** Log meldete "Failed to set PWM to 1023"

**Ursache:** Code erwartete `!P` als Antwort auf `Pxxxx!`, aber Gerät antwortet mit `!Q` (aktueller PWM-Wert).

**Fix in cloudwatcher_reader.py:**
```python
# Geändert von 'P' zu 'Q'
if 'Q' in parsed:  # Device responds with Q, not P
    ack_value = int(parsed['Q'])
```

---

## Anhang: Referenzen

### Offizielle Dokumentation
- Rs232_Comms_v100.pdf bis v140.pdf (Lunatico)
- RainSensorCalibration.pdf (Lunatico)

### INDI-Quellcode
- https://github.com/indilib/indi-3rdparty/tree/master/indi-aagcloudwatcher-ng

### Thermistor-Berechnungen
- Steinhart-Hart Equation: https://www.ametherm.com/thermistor/ntc-thermistors-steinhart-and-hart-equation

### Projekt-Repository
- CloudWatcher Service: `/home/pi/MagicMirror/modules/MMM-My-Actual-Weather/cloudwatcher/`
- Weather Aggregator: `/home/pi/MagicMirror/modules/MMM-My-Actual-Weather/weather-aggregator/`
- Interface-Dokumentation: `/home/pi/MagicMirror/modules/MMM-My-Actual-Weather/cloudwatcher/InterfaceDocu/`

### VB-Beispiele (Lunatico)
- `VB Example1.zip` - Parameter lesen/setzen via COM
- `VB Example2.zip` - Sensordaten lesen via COM

---

*Dokumentation erstellt: 2026-02-04*
*Letzte Aktualisierung: 2026-02-04 21:30 - Bugfix Impulse-Heizung, vollständige Dokumentation*
