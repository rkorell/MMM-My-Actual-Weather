# CloudWatcher Integration - Projektdokumentation

**Projekt:** AAG CloudWatcher Integration für MagicMirror  
**Erstellt:** 2026-01-25  
**Status:** Planungsphase  
**Arbeitspaket:** AP 44

---

## 1. Projektübersicht

### 1.1 Zielsetzung

Integration eines AAG CloudWatcher Sensors zur Messung der Wolkenbedeckung. Die Daten sollen:
1. Auf einem Raspberry Pi 4 in der Garage erfasst werden
2. Über ein lokales Webinterface einsehbar sein
3. Per JSON-API vom MagicMirror-Modul abgerufen werden
4. Mit PWS-Daten und DWD/Bright Sky kombiniert werden, um akkurate lokale Wetterbedingungen anzuzeigen

### 1.2 Problemstellung

Personal Weather Stations (PWS) können folgende Werte NICHT messen:
- Wolkenbedeckung (bräuchte Sky-Cam oder IR-Sensor)
- Sichtweite
- Niederschlagsart-Unterscheidung

Die DWD-Daten (via Bright Sky API) kommen von Stationen, die 8-25 km entfernt sind (Lissendorf, Nürburg-Barweiler). In der Eifel mit ihren Höhenunterschieden kann das lokale Wetter stark abweichen.

### 1.3 Lösungsansatz

Der CloudWatcher misst die **Infrarot-Strahlungstemperatur des Himmels**:
- **Klarer Himmel**: IR-Strahlung geht ins All → sehr kalt (-20°C bis -40°C)
- **Bewölkt**: Wolken reflektieren IR → wärmer (nahe Umgebungstemperatur)
- **Delta** = `T_ambient - T_sky` → je größer, desto klarer der Himmel

---

## 2. Hardware

### 2.1 Komponenten

| Komponente | Beschreibung | Status |
|------------|--------------|--------|
| AAG CloudWatcher | IR-Himmelstemperatur-Sensor | Noch zu beschaffen (~500€) |
| Raspberry Pi 4 | Datenerfassung & Webserver | Vorhanden |
| Digitus USB-RS232 Adapter | Seriell-Schnittstelle | Im CloudWatcher-Lieferumfang oder separat |
| Netzteil 12V | Für CloudWatcher | Im Lieferumfang |
| USB-Netzteil 5V | Für Raspberry Pi | Vorhanden |

### 2.2 Aufstellungsort

- **Standort:** Garage (dort steht auch die PWS)
- **Netzwerk:** Eero Access Point mit WLAN (bevorzugt) oder Ethernet
- **Stromversorgung:** Vorhanden

### 2.3 Verkabelung

```
CloudWatcher ──RS232 (DB9)──► Digitus USB-RS232 Adapter ──USB-A──► Raspberry Pi 4
     │                                                                    │
     │ 12V Netzteil                                              5V USB Netzteil
     ▼                                                                    ▼
  [Steckdose]                                                       [Steckdose]
```

Der USB-RS232-Adapter erscheint auf dem Pi als `/dev/ttyUSB0` (oder `/dev/ttyUSB1`).

---

## 3. CloudWatcher RS232-Protokoll

### 3.1 Kommunikationsparameter

- **Baudrate:** Nicht explizit dokumentiert - typisch 9600 oder 19200 (ausprobieren)
- **Format:** 8N1 (8 Datenbits, keine Parität, 1 Stoppbit)
- **Protokoll:** Request-Response (immer vom Host initiiert)

### 3.2 Befehlsformat

Befehle bestehen aus einem Zeichen gefolgt von `!` (Ausrufezeichen):

| Befehl | Beschreibung | Antwort-Blöcke |
|--------|--------------|----------------|
| `S!` | IR Sky Temperature | 2 (30 Bytes) |
| `T!` | IR Sensor Temperature | 2 (30 Bytes) |
| `C!` | Values (Ambient, LDR, Rain Sensor, Zener) | 5 (75 Bytes) |
| `E!` | Rain Frequency | 2 (30 Bytes) |
| `D!` | Internal Errors | 5 (75 Bytes) |
| `F!` | Switch Status | 2 (30 Bytes) |
| `Q!` | PWM Value | 2 (30 Bytes) |
| `A!` | Internal Name | 2 (30 Bytes) |
| `B!` | Firmware Version | 2 (30 Bytes) |
| `z!` | Reset RS232 Buffer | 1 (15 Bytes) |

### 3.3 Antwortformat

Jede Antwort besteht aus Blöcken à 15 Bytes:

```
!XXyyyyyyyyyyyy
│ │└─────────── 12 Bytes: Dateninhalt
│ └──────────── 2 Bytes: Datentyp
└────────────── 1 Byte: Startzeichen
```

**Datentypen:**

| Code | Bedeutung |
|------|-----------|
| `1 ` | IR Sky Temperature (1/100 °C) |
| `2 ` | IR Sensor Temperature (1/100 °C) |
| `3 ` | Analog0: Ambient Temp NTC (0-1023) |
| `4 ` | Analog2: LDR Helligkeit (0-1023) |
| `5 ` | Analog3: Rain Sensor Temp NTC (0-1023) |
| `6 ` | Analog3: Zener Voltage Reference (0-1023) |
| `R ` | Rain Frequency Counter |
| `X ` | Switch Open |
| `Y ` | Switch Closed |
| `Q ` | PWM Duty Cycle |

**Handshaking-Block (letzter Block jeder Antwort):**
```
Position 1:  ! (0x21)
Position 2:  XON (0x11)
Position 3-14: Spaces (0x20)
Position 15: 0 (0x30)
```

### 3.4 Wertumrechnung

#### 3.4.1 IR-Temperaturen (Sky und Sensor)
```python
temp_celsius = raw_value / 100.0
# Beispiel: 2456 → 24.56°C
# Beispiel: -1845 → -18.45°C
```

#### 3.4.2 Ambient Temperature (NTC)
```python
ABSZERO = 273.15
AmbPullUpResistance = 9.9   # kOhm
AmbResAt25 = 10.0           # kOhm
AmbBeta = 3811

def calc_ambient_temp(raw):
    if raw > 1022: raw = 1022
    if raw < 1: raw = 1
    
    r = AmbPullUpResistance / ((1023.0 / raw) - 1)  # Widerstand in kOhm
    r = math.log(r / AmbResAt25)
    temp = 1.0 / (r / AmbBeta + 1.0 / (ABSZERO + 25)) - ABSZERO
    return temp  # °C
```

#### 3.4.3 LDR (Helligkeit)
```python
LDRPullupResistance = 56  # kOhm

def calc_ldr(raw):
    if raw > 1022: raw = 1022
    if raw < 1: raw = 1
    
    ldr = LDRPullupResistance / ((1023.0 / raw) - 1)  # kOhm
    return ldr
```

**LDR-Interpretation:**
| LDR (kΩ) | Helligkeit |
|----------|------------|
| < 1 | Sehr hell (direktes Sonnenlicht) |
| 1 - 10 | Hell (Tageslicht) |
| 10 - 100 | Dämmerung |
| 100 - 1000 | Dunkel |
| > 1000 | Sehr dunkel (Nacht) |

#### 3.4.4 Rain Frequency
```python
rain_freq = raw_value  # Direkt übernehmen
```

**Interpretation:**
- Hohe Frequenz (~2500+): Trocken
- Niedrige Frequenz: Feuchtigkeit/Regen auf Sensor

### 3.5 Empfohlener Auslesezyklus

Laut Dokumentation (~3 Sekunden pro Zyklus):

```python
# 5x wiederholen für statistische Filterung
for i in range(5):
    send("S!")  # IR Sky Temperature
    receive(30)
    
    send("T!")  # IR Sensor Temperature
    receive(30)
    
    send("C!")  # Values (Ambient, LDR, Rain Sensor Temp, Zener)
    receive(75)
    
    send("E!")  # Rain Frequency
    receive(30)

# Einmal pro Zyklus
send("Q!")  # PWM Value
receive(30)

send("D!")  # Errors
receive(75)

send("F!")  # Switch Status
receive(30)
```

### 3.6 Statistische Filterung

Die Dokumentation empfiehlt:
1. 5 Messungen durchführen
2. Mittelwert (AVG) und Standardabweichung (STD) berechnen
3. Werte außerhalb AVG±STD verwerfen
4. Mittelwert der verbleibenden Werte als Endwert

```python
def filtered_average(values):
    avg = sum(values) / len(values)
    std = (sum((x - avg) ** 2 for x in values) / len(values)) ** 0.5
    
    filtered = [x for x in values if avg - std <= x <= avg + std]
    
    if filtered:
        return sum(filtered) / len(filtered)
    return avg
```

---

## 4. Software-Architektur

### 4.1 Übersicht

```
┌─────────────────────────────────────────────────────────────────────┐
│                        Raspberry Pi 4 (Garage)                      │
│                                                                     │
│  ┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐ │
│  │  cloudwatcher   │    │     Flask       │    │    Daten-       │ │
│  │  _reader.py     │───►│   Webserver     │◄───│    Cache        │ │
│  │  (Serial I/O)   │    │   (Port 5000)   │    │   (Dict/JSON)   │ │
│  └─────────────────┘    └─────────────────┘    └─────────────────┘ │
│          │                      │                                   │
│          ▼                      ▼                                   │
│    /dev/ttyUSB0          HTTP Endpoints:                           │
│                          - GET /           (HTML Dashboard)        │
│                          - GET /api/data   (JSON für MM)           │
│                          - GET /api/raw    (Rohdaten, Debug)       │
└─────────────────────────────────────────────────────────────────────┘
                                  │
                                  │ WLAN (Eero)
                                  ▼
┌─────────────────────────────────────────────────────────────────────┐
│                     MagicMirror Raspberry Pi                        │
│                                                                     │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │                    MMM-My-Actual-Weather                     │   │
│  │                                                              │   │
│  │  ┌──────────┐   ┌──────────┐   ┌──────────┐                 │   │
│  │  │   PWS    │   │  Bright  │   │  Cloud   │                 │   │
│  │  │  Push    │   │   Sky    │   │  Watcher │                 │   │
│  │  │  Data    │   │   API    │   │   API    │                 │   │
│  │  └────┬─────┘   └────┬─────┘   └────┬─────┘                 │   │
│  │       │              │              │                        │   │
│  │       └──────────────┼──────────────┘                        │   │
│  │                      ▼                                       │   │
│  │           ┌─────────────────────┐                            │   │
│  │           │   Condition Logic   │                            │   │
│  │           │   (Veto/Override)   │                            │   │
│  │           └─────────────────────┘                            │   │
│  └─────────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────────┘
```

### 4.2 Dateistruktur auf dem Garage-Pi

```
/home/pi/cloudwatcher/
├── cloudwatcher_service.py    # Haupt-Service (Reader + Webserver)
├── cloudwatcher_reader.py     # RS232-Kommunikation (Modul)
├── config.py                  # Konfiguration (Port, Schwellwerte)
├── templates/
│   └── dashboard.html         # Web-Dashboard Template
├── static/
│   └── style.css              # Optional: CSS für Dashboard
├── requirements.txt           # Python Dependencies
├── cloudwatcher.service       # Systemd Service File
└── README.md                  # Setup-Anleitung
```

### 4.3 Python Dependencies

```
# requirements.txt
flask>=2.0
pyserial>=3.5
```

---

## 5. API-Spezifikation

### 5.1 Endpoint: GET /

**Beschreibung:** Human-readable HTML Dashboard

**Response:** HTML-Seite mit aktuellen Werten:

```
┌─────────────────────────────────────────┐
│  CloudWatcher Station                   │
│  ─────────────────────────────────────  │
│  Sky Temperature:    -18.4°C            │
│  Ambient Temperature: +2.3°C            │
│  Delta (Cloud Index): 20.7°C            │
│  Cloud Condition:     ◐ Partly Cloudy   │
│  ─────────────────────────────────────  │
│  Rain Sensor:        2340 Hz  [○ Dry]   │
│  Light (LDR):        125 kΩ   [● Night] │
│  ─────────────────────────────────────  │
│  Last Update: 2026-01-25 20:15:32       │
│  Uptime: 3d 14h 22m                     │
└─────────────────────────────────────────┘
```

### 5.2 Endpoint: GET /api/data

**Beschreibung:** JSON-Daten für MagicMirror

**Response:**
```json
{
  "timestamp": "2026-01-25T20:15:32Z",
  "sky_temp_c": -18.45,
  "ambient_temp_c": 2.30,
  "delta_c": 20.75,
  "rain_freq": 2340,
  "ldr_kohm": 125.3,
  "is_daylight": false,
  "is_raining": false,
  "uptime_s": 310932,
  "quality": "ok"
}
```

**Felder:**

| Feld | Typ | Beschreibung |
|------|-----|--------------|
| timestamp | string (ISO 8601) | Zeitpunkt der letzten Messung |
| sky_temp_c | float | IR-Himmelstemperatur in °C |
| ambient_temp_c | float | Umgebungstemperatur in °C |
| delta_c | float | Differenz ambient - sky (Cloud Index) |
| rain_freq | int | Regensensor-Frequenz (höher = trockener) |
| ldr_kohm | float | LDR-Widerstand in kΩ (höher = dunkler) |
| is_daylight | bool | Tageslicht erkannt (LDR < 50 kΩ) |
| is_raining | bool | Regen erkannt (rain_freq < 2000) |
| uptime_s | int | Service-Uptime in Sekunden |
| quality | string | "ok", "stale" (>5min alt), "error" |

### 5.3 Endpoint: GET /api/raw

**Beschreibung:** Rohdaten für Debugging

**Response:**
```json
{
  "timestamp": "2026-01-25T20:15:32Z",
  "raw": {
    "sky_temp": [-1845, -1842, -1850, -1843, -1847],
    "sensor_temp": [230, 231, 230, 229, 231],
    "ambient_raw": [512, 513, 512, 514, 512],
    "ldr_raw": [45, 44, 45, 45, 46],
    "rain_freq": [2340, 2342, 2338, 2341, 2339],
    "zener_raw": [845, 845, 846, 845, 845]
  },
  "calculated": {
    "sky_temp_c": -18.45,
    "sensor_temp_c": 2.30,
    "ambient_temp_c": 2.28,
    "ldr_kohm": 125.3,
    "rain_freq_avg": 2340,
    "supply_voltage": 3.63
  },
  "errors": {
    "e1": 0,
    "e2": 0,
    "e3": 0,
    "e4": 0
  },
  "device": {
    "name": "CloudWatcher",
    "firmware": "1.10",
    "pwm": 512,
    "switch": "open"
  }
}
```

---

## 6. Bewölkungs-Interpretation

### 6.1 Cloud Index (Delta)

Die Differenz zwischen Umgebungstemperatur und Himmelstemperatur:

```python
delta = ambient_temp_c - sky_temp_c
```

### 6.2 Schwellwerte

| Delta (°C) | Bewölkung | Beschreibung |
|------------|-----------|--------------|
| > 25 | Clear | Klarer Himmel |
| 20 - 25 | Mostly Clear | Überwiegend klar |
| 15 - 20 | Partly Cloudy | Teilweise bewölkt |
| 10 - 15 | Mostly Cloudy | Überwiegend bewölkt |
| 5 - 10 | Cloudy | Bewölkt |
| < 5 | Overcast | Bedeckt |

**Hinweis:** Diese Schwellwerte sind Richtwerte und müssen eventuell für den Standort kalibriert werden.

### 6.3 Temperatur-Korrektur

Bei sehr kalten oder sehr warmen Umgebungstemperaturen verschieben sich die Schwellwerte:

```python
def get_cloud_condition(delta, ambient_temp):
    # Korrektur für extreme Temperaturen
    correction = 0
    if ambient_temp < -10:
        correction = -3  # Winter: Schwellwerte senken
    elif ambient_temp > 25:
        correction = +3  # Sommer: Schwellwerte erhöhen
    
    adjusted_delta = delta - correction
    
    if adjusted_delta > 25:
        return "clear"
    elif adjusted_delta > 20:
        return "mostly_clear"
    elif adjusted_delta > 15:
        return "partly_cloudy"
    elif adjusted_delta > 10:
        return "mostly_cloudy"
    elif adjusted_delta > 5:
        return "cloudy"
    else:
        return "overcast"
```

---

## 7. Integration mit Wetter-Condition-Logik

### 7.1 Datenquellen

Die finale Wetter-Condition wird aus drei Quellen kombiniert:

| Quelle | Liefert | Update-Frequenz |
|--------|---------|-----------------|
| PWS (Push) | Temp, Humidity, Dewpoint, Pressure, Wind, Rain | ~60s |
| Bright Sky (DWD) | Condition, Icon, Cloud Cover, Visibility | ~60 min |
| CloudWatcher | Sky Temp, Delta, LDR, Rain Detect | ~30s |

### 7.2 Veto-Logik

Der CloudWatcher kann die DWD-Condition überschreiben:

#### 7.2.1 Nebel-Veto

DWD sagt "fog", aber lokale Daten widersprechen:

```python
def veto_fog(dwd_condition, pws_data, cw_data):
    if dwd_condition != "fog":
        return False  # Kein Veto nötig
    
    # Spread-Check: Nebel braucht Temp ≈ Dewpoint
    spread = pws_data['temp'] - pws_data['dewpoint']
    if spread > 2.5:
        return True  # Veto: Zu trocken für Nebel
    
    # Humidity-Check: Nebel braucht >95%
    if pws_data['humidity'] < 93:
        return True  # Veto: Luftfeuchtigkeit zu niedrig
    
    # CloudWatcher-Check: Nebel = Overcast
    if cw_data['delta_c'] > 15:
        return True  # Veto: Himmel zu klar für Nebel
    
    return False  # Kein Veto, DWD hat recht
```

#### 7.2.2 Clear-Override

DWD sagt "clear", aber CloudWatcher sieht Wolken:

```python
def override_clear(dwd_condition, cw_data):
    if dwd_condition not in ["clear", "clear-night"]:
        return None  # Keine Korrektur
    
    if cw_data['delta_c'] < 10:
        return "cloudy"  # Korrektur: Bewölkt
    elif cw_data['delta_c'] < 20:
        return "partly_cloudy"  # Korrektur: Teilweise bewölkt
    
    return None  # Keine Korrektur, DWD hat recht
```

#### 7.2.3 Rain-Validation

```python
def validate_rain(dwd_condition, pws_data, cw_data):
    dwd_says_rain = dwd_condition in ["rain", "drizzle", "thunderstorm"]
    pws_says_rain = pws_data['precip_rate'] > 0
    cw_says_rain = cw_data['is_raining']
    
    # Mindestens 2 von 3 Quellen müssen Regen melden
    rain_votes = sum([dwd_says_rain, pws_says_rain, cw_says_rain])
    
    if rain_votes >= 2:
        return "rain"
    elif rain_votes == 1 and pws_says_rain:
        return "light_rain"  # PWS hat Priorität (lokal)
    
    return None  # Kein Regen
```

### 7.3 Condition-Mapping für Icons

```python
CONDITION_TO_ICON = {
    "clear": "wi-day-sunny",
    "clear-night": "wi-night-clear",
    "mostly_clear": "wi-day-sunny-overcast",
    "partly_cloudy": "wi-day-cloudy",
    "partly_cloudy-night": "wi-night-alt-cloudy",
    "mostly_cloudy": "wi-cloudy",
    "cloudy": "wi-cloudy",
    "overcast": "wi-cloud",
    "fog": "wi-fog",
    "rain": "wi-rain",
    "light_rain": "wi-sprinkle",
    "snow": "wi-snow",
    "thunderstorm": "wi-thunderstorm",
}
```

---

## 8. Systemd Service

### 8.1 Service File

```ini
# /etc/systemd/system/cloudwatcher.service

[Unit]
Description=CloudWatcher Weather Sensor Service
After=network.target

[Service]
Type=simple
User=pi
WorkingDirectory=/home/pi/cloudwatcher
ExecStart=/usr/bin/python3 /home/pi/cloudwatcher/cloudwatcher_service.py
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

### 8.2 Installation

```bash
sudo cp cloudwatcher.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable cloudwatcher
sudo systemctl start cloudwatcher
```

### 8.3 Logs

```bash
sudo journalctl -u cloudwatcher -f
```

---

## 9. Netzwerk-Konfiguration

### 9.1 Hostname

Option A: Statische IP (in Router/Eero konfigurieren)
```
IP: 192.168.x.y
Port: 5000
URL: http://192.168.x.y:5000/
```

Option B: mDNS (automatisch)
```
Hostname: cloudwatcher
URL: http://cloudwatcher.local:5000/
```

Für mDNS auf dem Pi:
```bash
sudo apt install avahi-daemon
sudo hostnamectl set-hostname cloudwatcher
```

### 9.2 Firewall

Falls UFW aktiv:
```bash
sudo ufw allow 5000/tcp
```

---

## 10. Arbeitspakete

### AP 44a: Hardware-Setup & Test

**Ziel:** CloudWatcher am Pi anschließen und RS232-Kommunikation testen

**Schritte:**
1. CloudWatcher aufstellen und verkabeln
2. USB-RS232-Adapter anschließen
3. Device identifizieren (`ls /dev/ttyUSB*`)
4. Baudrate ermitteln (9600 oder 19200)
5. Manuelle Kommunikation testen:
   ```bash
   # Mit screen oder minicom
   screen /dev/ttyUSB0 9600
   # Dann tippen: S! (Enter)
   # Erwartete Antwort: 30 Bytes
   ```

**Deliverable:** Funktionierende serielle Verbindung

---

### AP 44b: Python Reader-Modul

**Ziel:** `cloudwatcher_reader.py` - RS232-Kommunikation und Parsing

**Funktionen:**
- `CloudWatcherReader(port, baudrate)` - Klasse
- `read_sky_temp()` → float (°C)
- `read_ambient_temp()` → float (°C)
- `read_values()` → dict (ambient, ldr, rain_temp, zener)
- `read_rain_freq()` → int
- `read_all()` → dict (alle Werte, gefiltert)
- `close()` - Verbindung schließen

**Besonderheiten:**
- 5 Messungen pro Wert
- Statistische Filterung (Ausreißer entfernen)
- Timeout-Handling
- Reconnect bei Verbindungsabbruch

**Deliverable:** Funktionierendes Python-Modul

---

### AP 44c: Flask Webserver

**Ziel:** `cloudwatcher_service.py` - Webserver mit Dashboard und API

**Endpoints:**
- `GET /` - HTML Dashboard
- `GET /api/data` - JSON für MagicMirror
- `GET /api/raw` - Rohdaten für Debugging

**Features:**
- Hintergrund-Thread für periodisches Auslesen (alle 30s)
- Daten-Cache (Thread-safe)
- Uptime-Tracking
- Qualitäts-Indikator (ok/stale/error)

**Deliverable:** Laufender Webserver mit funktionierender API

---

### AP 44d: Systemd Integration

**Ziel:** Service automatisch starten

**Schritte:**
1. Service-File erstellen
2. Service registrieren und aktivieren
3. Auto-Start nach Reboot testen
4. Log-Rotation konfigurieren

**Deliverable:** Robust laufender Hintergrund-Service

---

### AP 44e: MagicMirror Integration

**Ziel:** CloudWatcher-Daten in MMM-My-Actual-Weather einbinden

**Schritte:**
1. Neuen Daten-Endpoint in `node_helper.js` hinzufügen
2. Polling-Intervall konfigurieren (60s)
3. Daten in Modul-State integrieren
4. Condition-Logik erweitern (Veto/Override)
5. Icon-Mapping anpassen

**Deliverable:** CloudWatcher-Daten im MagicMirror sichtbar

---

## 11. Testplan

### 11.1 Hardware-Tests

| Test | Erwartung |
|------|-----------|
| Device erscheint als /dev/ttyUSB0 | ✓ |
| Baudrate 9600 funktioniert | ✓ oder 19200 |
| Befehl `A!` liefert "CloudWatcher" | 30 Bytes, Name erkennbar |
| Befehl `S!` liefert Temperatur | Wert plausibel (-40 bis +20°C) |

### 11.2 Software-Tests

| Test | Erwartung |
|------|-----------|
| Reader liest alle Werte | Keine Exceptions |
| Filterung entfernt Ausreißer | Standardabweichung sinkt |
| Webserver startet | Port 5000 erreichbar |
| /api/data liefert JSON | Valides JSON, alle Felder |
| Service überlebt Reboot | Automatischer Start |

### 11.3 Integrations-Tests

| Test | Erwartung |
|------|-----------|
| MagicMirror ruft API ab | Daten im Log sichtbar |
| Bewölkung wird angezeigt | Delta → Condition → Icon |
| Veto-Logik funktioniert | Bei Spread >2.5°C kein Nebel |

---

## 12. Offene Punkte / Fragen

1. **Baudrate:** 9600 oder 19200? Muss getestet werden.

2. **Kalibrierung:** Die Delta-Schwellwerte (25/20/15/10/5°C) sind Richtwerte. Nach Installation Feinabstimmung nötig.

3. **Sommer vs. Winter:** Die IR-Messung verhält sich bei extremen Temperaturen anders. Eventuell saisonale Korrektur nötig.

4. **Regensensor-Heizung:** Der CloudWatcher hat eine PWM-gesteuerte Heizung für den Regensensor. Die Steuerlogik dafür ist im Python-Service zu implementieren (oder erstmal ignorieren).

5. **Fallback:** Was passiert, wenn der CloudWatcher ausfällt? Nur PWS + DWD nutzen.

---

## 13. Ressourcen

### 13.1 CloudWatcher

- **Hersteller:** AAG (Lunatico Astronomia)
- **Dokumentation:** RS232_Comms_v100.pdf (im Projekt-Ordner)
- **Preis:** ~500€

### 13.2 Software-Bibliotheken

- **pyserial:** https://pyserial.readthedocs.io/
- **Flask:** https://flask.palletsprojects.com/

### 13.3 Bright Sky API

- **Dokumentation:** https://brightsky.dev/docs/
- **Endpoint:** `https://api.brightsky.dev/current_weather?lat=50.242&lon=6.603`
- **Nächste Stationen:** Lissendorf (8km), Nürburg-Barweiler (23km)

---

## Anhang A: Beispiel-Responses

### A.1 CloudWatcher Befehl `S!` (Sky Temperature)

**Senden:** `S!` (2 Bytes)

**Empfangen:** (30 Bytes)
```
!1 -1845      !␑           0
└─┬─┘└──┬────┘└─┬─┘└──────┬───┘
  │     │       │          │
Block 1:        Block 2: Handshake
Type "1 "       XON + Spaces + "0"
Value: -1845
= -18.45°C
```

### A.2 CloudWatcher Befehl `C!` (Values)

**Senden:** `C!` (2 Bytes)

**Empfangen:** (75 Bytes, 5 Blöcke)
```
Block 1: !6 845        (Zener: 845)
Block 2: !3 512        (Ambient NTC: 512)
Block 3: !4 45         (LDR: 45)
Block 4: !5 510        (Rain Sensor Temp: 510)
Block 5: !␑           0 (Handshake)
```

---

## Anhang B: Verkabelung MAX3232 (Alternative)

Falls kein USB-RS232-Adapter verfügbar und ein ESP32 verwendet werden soll:

```
CloudWatcher DB9        MAX3232-Modul         ESP32
─────────────────       ─────────────────     ──────
Pin 2 (RX)  ────────►   R1IN  → R1OUT  ────►  GPIO16 (RX2)
Pin 3 (TX)  ◄────────   T1OUT ← T1IN   ◄────  GPIO17 (TX2)
Pin 5 (GND) ────────►   GND ──────────────►   GND
                        VCC ◄─────────────    3.3V
```

**Diese Option ist nur relevant, wenn der Pi durch einen ESP32 ersetzt werden soll.**

---

## 14. Implementierter Stand (2026-01-25)

Dieser Abschnitt dokumentiert, was tatsächlich auf dem CloudWatcher-Pi implementiert wurde.

### 14.1 CloudWatcher-Pi Systemübersicht

| Eigenschaft | Wert |
|-------------|------|
| **Hostname** | CloudWatcher |
| **IP-Adresse** | 172.23.56.60 |
| **Hardware** | Raspberry Pi 4, 4GB RAM |
| **OS** | Debian 12 (Bookworm) |
| **Python** | 3.11.2 |
| **Speicher** | 29 GB (19 GB frei) |

### 14.2 SSH-Zugang

SSH-Key-Authentifizierung von MagicMirrorPi5 eingerichtet (2026-01-25):

```bash
# Verbindung vom MagicMirrorPi5
ssh pi@172.23.56.60
```

SSH-Key liegt in `/home/pi/.ssh/id_rsa` auf MagicMirrorPi5.

### 14.3 Installierte Software

#### 14.3.1 System-Dependencies

```bash
# Bereits installiert (systemweit)
sudo apt install python3-flask python3-serial
```

Kein Virtual Environment - alle Dependencies systemweit installiert.

#### 14.3.2 Projektverzeichnis

Auf dem CloudWatcher-Pi: `/home/pi/cloudwatcher/`

```
/home/pi/cloudwatcher/
├── cloudwatcher_reader.py      # RS232-Kommunikation (12.5 KB)
├── cloudwatcher_service.py     # Flask-Webserver (8.5 KB)
├── cloudwatcher.service        # Systemd Service File
├── config.py                   # Konfiguration (Schwellwerte, Ports)
├── README.md                   # Setup-Anleitung
└── templates/
    └── dashboard.html          # Web-Dashboard (deutsch)
```

### 14.4 Datei-Beschreibungen

#### config.py
- Serial Port: `/dev/ttyUSB0`
- Baudrate: 9600 (alternativ 19200)
- Web Port: 5000
- Read Interval: 30 Sekunden
- Cloud-Schwellwerte: clear >25°C, mostly_clear >20°C, etc.
- Temperatur-Korrektur für extreme Werte

#### cloudwatcher_reader.py
- Klasse `CloudWatcherReader` für RS232-Kommunikation
- Implementiert alle Befehle (S!, T!, C!, E!, etc.)
- Statistische Filterung (5 Messungen, Ausreißer entfernen)
- Klasse `DummyCloudWatcherReader` für Tests ohne Hardware

#### cloudwatcher_service.py
- Flask-Webserver mit Hintergrund-Thread
- Endpoints:
  - `GET /` - HTML Dashboard (deutsch, Auto-Refresh 30s)
  - `GET /api/data` - JSON für MagicMirror
  - `GET /api/raw` - Debug-Daten
  - `GET /api/health` - Health-Check
- Cloud-Condition-Berechnung aus Delta-Wert
- `--dummy` Flag für Testbetrieb ohne Hardware

#### templates/dashboard.html
- Deutsches Web-Dashboard
- Anzeige: Bewölkung, Temperaturen (Himmel/Umgebung), Sensoren
- Systemstatus: Datenqualität, Laufzeit, Gerät
- Copyright: © Dr. Ralf Korell
- Auto-Refresh alle 30 Sekunden

### 14.5 Deployment

Die Source-of-Truth liegt im Git-Repo auf MagicMirrorPi5. Deployment auf den CloudWatcher-Pi per rsync.

#### Dateien synchronisieren

```bash
# Vom MagicMirrorPi5 ausführen
rsync -avz --exclude '__pycache__' \
  /home/pi/MagicMirror/modules/MMM-My-Actual-Weather/cloudwatcher/ \
  pi@172.23.56.60:/home/pi/cloudwatcher/
```

#### Service-File aktualisieren (bei Änderungen)

```bash
ssh pi@172.23.56.60 "sudo cp /home/pi/cloudwatcher/cloudwatcher.service /etc/systemd/system/ && sudo systemctl daemon-reload"
```

#### Service neustarten

```bash
ssh pi@172.23.56.60 "sudo systemctl restart cloudwatcher"
```

#### Komplett-Deployment (alles in einem)

```bash
rsync -avz --exclude '__pycache__' \
  /home/pi/MagicMirror/modules/MMM-My-Actual-Weather/cloudwatcher/ \
  pi@172.23.56.60:/home/pi/cloudwatcher/ && \
ssh pi@172.23.56.60 "sudo cp /home/pi/cloudwatcher/cloudwatcher.service /etc/systemd/system/ && sudo systemctl daemon-reload && sudo systemctl restart cloudwatcher"
```

### 14.6 Systemd Service

#### Service-File

Das Service-File liegt im Git-Repo: `cloudwatcher/cloudwatcher.service`

Speicherort auf CloudWatcher-Pi: `/etc/systemd/system/cloudwatcher.service`

#### Installation (bereits durchgeführt)

```bash
# Service-File kopieren
sudo cp /home/pi/cloudwatcher/cloudwatcher.service /etc/systemd/system/

# Systemd neu laden
sudo systemctl daemon-reload

# Auto-Start aktivieren
sudo systemctl enable cloudwatcher

# Service starten
sudo systemctl start cloudwatcher
```

#### Aktueller Status

Der Service läuft im **Dummy-Modus** (simulierte Daten):

```bash
# In /etc/systemd/system/cloudwatcher.service:
ExecStart=/usr/bin/python3 /home/pi/cloudwatcher/cloudwatcher_service.py --dummy
```

#### Umstellung auf echte Hardware

Wenn der CloudWatcher angeschlossen ist:

```bash
# Dummy-Flag entfernen
sudo sed -i 's|--dummy||' /etc/systemd/system/cloudwatcher.service
sudo systemctl daemon-reload
sudo systemctl restart cloudwatcher
```

### 14.7 Nützliche Befehle

```bash
# Service-Status prüfen
ssh pi@172.23.56.60 "sudo systemctl status cloudwatcher"

# Logs ansehen (live)
ssh pi@172.23.56.60 "sudo journalctl -u cloudwatcher -f"

# Service neustarten
ssh pi@172.23.56.60 "sudo systemctl restart cloudwatcher"

# Service stoppen
ssh pi@172.23.56.60 "sudo systemctl stop cloudwatcher"

# API testen
curl http://172.23.56.60:5000/api/data

# Dashboard im Browser
# http://172.23.56.60:5000/
```

### 14.8 API-Response (Beispiel)

```json
{
    "timestamp": "2026-01-25T14:33:16.694806+00:00",
    "sky_temp_c": -19.28,
    "ambient_temp_c": 8.21,
    "delta_c": 27.49,
    "cloud_condition": "clear",
    "rain_freq": 1763,
    "ldr_kohm": 352.5,
    "is_daylight": true,
    "is_raining": false,
    "uptime_s": 3600,
    "quality": "ok"
}
```

### 14.9 Offene Punkte

1. **Hardware nicht angeschlossen** - CloudWatcher bestellt, in Lieferung
2. **Baudrate unbekannt** - 9600 oder 19200, muss getestet werden
3. **Schwellwerte nicht kalibriert** - Richtwerte aus Dokumentation, Feinabstimmung nach Installation
4. **MagicMirror-Integration ausstehend** - Veto-Logik noch nicht implementiert

---

*Dokumentation erstellt: 2026-01-25*
*Implementierung: 2026-01-25 (AP 44)*
*Nächster Schritt: CloudWatcher-Hardware anschließen und testen*
