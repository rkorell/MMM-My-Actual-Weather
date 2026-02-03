# Weather Aggregator

**Stand: 03.02.2026**

PHP-basierter Wetterdaten-Aggregator, der PWS- und CloudWatcher-Sensordaten kombiniert, WMO-Codes lokal ableitet und per MQTT an MagicMirror publisht.

## Dokumentation

- [WMO Code Derivation](docs/WMO_Ableitungsmoeglichkeiten.md) - Detaillierte WMO-Ableitungslogik und Entscheidungsbaum
- [Heater-PWM Analysis](docs/Heater-PWM-Analyse.md) - Tiefenanalyse des CloudWatcher-Regensensor-Verhaltens
- [Main Module README](../README.md) - MMM-My-Actual-Weather Modul-Dokumentation

## Architektur

```
┌─────────────┐  POST :8000/data/report/
│  PWS        │ ────────────────────────────┐
│ (IGEROL23)  │                             │
└─────────────┘                             ▼
                            ┌────────────────────────────────────────┐
                            │  Webserver (Apache + PHP + PostgreSQL) │
                            │                                        │
┌─────────────┐  HTTP GET   │  pws_receiver.php                      │
│ CloudWatcher│ ◄───────────│    ├── PWS-Daten parsen (Ecowitt)      │
│  (IR Sensor)│             │    ├── CloudWatcher-API abrufen        │
└─────────────┘             │    ├── Taupunkt + QNH berechnen        │
                            │    ├── WMO-Code ableiten               │
                            │    ├── In PostgreSQL speichern         │
                            │    └── MQTT publish (volle Wetterdaten)│
                            │                                        │
                            │  api.php → JSON-API (Fallback/Debug)   │
                            │  dashboard.php → Web-Dashboard         │
                            └────────────────────────────────────────┘
                                          │
                                          ▼ MQTT (real-time)
                            ┌────────────────────────────────────────┐
                            │  MagicMirror (node_helper.js)          │
                            └────────────────────────────────────────┘
```

## Komponenten

| Datei | Beschreibung |
|-------|--------------|
| `pws_receiver_post.php` | POST-to-GET Adapter für Ecowitt-Protokoll |
| `pws_receiver.php` | Hauptlogik: PWS parsen, CloudWatcher abrufen, DB speichern, MQTT publish |
| `wmo_derivation.php` | WMO-Code Ableitung aus Sensordaten |
| `api.php` | JSON-API (current, history, status, feedback) |
| `dashboard.php` | Web-Dashboard mit Charts und Feedback-UI |
| `config.php` | Konfiguration (Schwellenwerte, URLs, MQTT) - nicht im Git |
| `config.example.php` | Konfigurations-Template |
| `db_connect.php` | DB-Credentials - nicht im Git |

## Datenquellen

### PWS (Personal Weather Station)

Ecowitt-Protokoll via HTTP POST. Parameter werden in metrische Einheiten umgerechnet.

| Parameter | Quelle | Umrechnung |
|-----------|--------|------------|
| temp_c | tempf | (F-32) × 5/9 |
| humidity | humidity | direkt |
| dewpoint_c | berechnet | Magnus-Formel |
| pressure_hpa | baromabsin | × 33.8639 + Höhenkorrektur (QNH) |
| wind_speed_ms | windspeedmph | × 0.44704 |
| precip_rate_mm | rainratein | × 25.4 |

### CloudWatcher (IR Sky Sensor)

REST-API auf Port 5000. Liefert Himmelstemperatur, Regensensor und Helligkeitsdaten.

| Feld | Beschreibung | Verwendung |
|------|--------------|------------|
| sky_temp_c | Himmelstemperatur (IR) | Bewölkung (delta = temp - sky_temp) |
| rain_freq | Regensensor-Frequenz | Niederschlag (< 1700 = Regen) |
| heater_pwm | Heizungs-PWM 0-100% | Feuchtigkeitserkennung |
| is_wet | rain_freq < 2100 | Sensor feucht |
| is_raining | rain_freq < 1700 | Aktiver Niederschlag |
| is_daylight | Helligkeitssensor | Tag/Nacht-Icons |
| mpsas | Himmelshelligkeit | Astronomische Qualität |

**Wichtig:** Der Regensensor hat eine beheizte Oberfläche. Die Kombination aus `heater_pwm` und `is_wet` ermöglicht die Erkennung von leichtem Niederschlag, der die PWS-Wippe nicht auslöst. Details: [Heater-PWM-Analyse](docs/Heater-PWM-Analyse.md)

## WMO-Code Ableitung

Die WMO-Codes werden lokal aus Sensordaten abgeleitet - keine externe Wetter-API nötig.

**Prioritätsreihenfolge:**
1. **Niederschlag** (höchste Priorität) - PWS-Rate > 0 ODER CloudWatcher is_raining ODER Heater-Trick
2. **Nebel/Dunst** - Spread < 1°C, Humidity > 97%
3. **Bewölkung** (Fallback) - Delta-basiert

**Niederschlagserkennung (Dual-Sensor + Heater-Trick):**
```php
$heater_indicates_moisture = ($heater_pwm > 30) && $cw_is_wet;
$is_precipitating = ($precip_rate > 0) || $cw_is_raining || $heater_indicates_moisture;
```

Detaillierte Logik: [WMO_Ableitungsmoeglichkeiten.md](docs/WMO_Ableitungsmoeglichkeiten.md)

## API-Endpunkte

| Endpoint | Methode | Beschreibung |
|----------|---------|--------------|
| `api.php?action=current` | GET | Aktuelle Wetterdaten + WMO-Code |
| `api.php?action=history&hours=24` | GET | Historische Daten (1-168h) |
| `api.php?action=status` | GET | System-Status (letzte Aktualisierung, DB-Größe) |
| `api.php?action=raw` | GET | Letzte Rohdaten aus DB (Debug) |
| `api.php?action=feedback` | POST | Feedback speichern |
| `api.php?action=feedback_stats` | GET | Feedback-Statistiken |
| `api.php?action=wmo_list` | GET | WMO-Codes für Dropdown |

### Response-Beispiel (action=current)

```json
{
  "timestamp": "2026-02-03T15:10:00+01:00",
  "temp_c": 1.6,
  "humidity": 99,
  "dewpoint_c": 1.4,
  "pressure_hpa": 998.3,
  "wind_speed_ms": 0.8,
  "wind_dir_deg": 78,
  "precip_rate_mm": 0,
  "precip_today_mm": 3.5,
  "temp1_c": 17.6,
  "temp2_c": 22.9,
  "humidity1": 49,
  "humidity2": 35,
  "sky_temp_c": 1.0,
  "delta_c": 0.6,
  "rain_freq": 1920,
  "heater_pwm": 100,
  "is_wet": true,
  "wmo_code": 68,
  "condition": "sleet_light",
  "is_raining": false,
  "is_daylight": true,
  "cloudwatcher_online": true,
  "data_age_s": 30
}
```

## MQTT-Konfiguration

Der Aggregator publisht nach jedem PWS-Push die vollständigen Wetterdaten zu MQTT.

```php
// config.php
define('MQTT_BROKER_HOST', '172.23.56.157');  // MagicMirror-Pi
define('MQTT_BROKER_PORT', 1883);
define('MQTT_TOPIC', 'weather/aggregator/new_data');
```

**Voraussetzung:** `mosquitto-clients` Paket muss installiert sein.

## Installation

### 1. Dateien deployen

```bash
scp weather-aggregator/*.php pi@WEBSERVER:/var/www/weather-api/
```

### 2. PostgreSQL einrichten

```bash
# Als postgres-Superuser
sudo -u postgres psql
CREATE USER weather_user WITH PASSWORD 'xxx';
CREATE DATABASE weather OWNER weather_user;
\q

# Schema anlegen
sudo -u postgres psql -d weather -f setup/schema.sql
```

### 3. Konfiguration

```bash
# Auf dem Webserver
cd /var/www/weather-api
cp config.example.php config.php
cp db_connect.example db_connect.php
# Beide Dateien anpassen!
```

### 4. PWS konfigurieren

Ecowitt-Protokoll aktivieren:
- Server: `WEBSERVER_IP`
- Port: `8000`
- Path: `/data/report/`
- Interval: `60` Sekunden

## Debugging

```bash
# API testen
curl -s "http://WEBSERVER/weather-api/api.php?action=current" | jq

# MQTT-Traffic beobachten
mosquitto_sub -h MQTT_BROKER -t "weather/aggregator/new_data" -v

# Datenbank prüfen
ssh pi@WEBSERVER "psql -U weather_user -d weather -c 'SELECT * FROM weather_readings ORDER BY timestamp DESC LIMIT 5;'"

# PWS-Push simulieren
curl -X POST "http://WEBSERVER:8000/data/report/" \
  -d "PASSKEY=test&tempf=50&humidity=80&winddir=180&windspeedmph=5&rainratein=0"
```

## Externe Quellen

Die WMO-Ableitungslogik basiert auf:

- [WMO CODE TABLE 4677](https://www.nodc.noaa.gov/archive/arc0021/0002199/1.1/data/0-data/HTML/WMO-CODE/WMO4677.HTM) - Present Weather Codes
- [WMO International Cloud Atlas](https://cloudatlas.wmo.int/) - Meteorologische Definitionen
- [RTS2 CloudWatcher Driver](https://github.com/RTS2/rts2) - Heater-Algorithmus Referenz
- [KNMI PWS Technical Report](https://cdn.knmi.nl/knmi/pdf/bibliotheek/knmipubTR/TR259.pdf) - Automatische Wetterstationen

## Autor

Dr. Ralf Korell, 2026
