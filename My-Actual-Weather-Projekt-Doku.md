# MMM-My-Actual-Weather - Projektdokumentation

**Autor:** Dr. Ralf Korell
**Modul:** MMM-My-Actual-Weather
**Status:** Aktiv
**Letzte Aktualisierung:** 2026-02-03 (AP 55)

---

## Verwandte Dokumentation

- [README.md](README.md) - Modul-Dokumentation (öffentlich, für GitHub)
- [WMO_Ableitungsmoeglichkeiten.md](weather-aggregator/docs/WMO_Ableitungsmoeglichkeiten.md) - Detaillierte WMO-Ableitungslogik
- [CloudWatcher README](cloudwatcher/README.md) - CloudWatcher Service Dokumentation
- [CloudWatcher Projektdoku](cloudwatcher/cloudwatcher-projekt-dokumentation.md) - CloudWatcher Planung und Setup
- [Audit Log](AUDIT-Log-2026-01-30.md) - Qualitäts-Audit Ergebnisse

---

## Übersicht

- **Datenquelle primär**: Weather-Aggregator auf Webserver (WEBSERVER_IP) via MQTT
- **Datenquelle Fallback**: Wunderground API (wenn Aggregator-Daten > 180s alt)
- **Aggregator kombiniert**: PWS (YOUR_STATION_ID) + CloudWatcher (IR-Sensor)
- **Update-Mechanismus**: MQTT Real-Time (Aggregator publisht nach PWS-Push), Watchdog pollt API bei MQTT-Ausfall
- **WMO-Code**: Lokal abgeleitet aus Sensordaten (kein Cloud-API nötig)
- **Zusatzsensoren**: temp1 (Therapie), temp2 (WoZi)
- **Besonderheiten**: Temperatur-Farbgradient, Tag/Nacht-Icons, Auto-Fallback
- **Koordinaten**: YOUR_LAT / YOUR_LON
- **Dependencies**: mqtt, node-fetch

---

## Architektur

```
┌─────────────┐  POST :8000/data/report/
│  PWS        │ ────────────────────────────┐
│ (YOUR_STATION_ID)  │                             │
└─────────────┘                             ▼
                            ┌────────────────────────────────────────┐
                            │  Webserver (WEBSERVER_IP)             │
                            │                                        │
┌─────────────┐  HTTP GET   │  pws_receiver.php                      │
│ CloudWatcher│ ◄───────────│    ├── PWS-Daten parsen                │
│(CLOUDWATCHER_IP)             │    ├── CloudWatcher-API abrufen        │
└─────────────┘             │    ├── WMO-Code ableiten               │
                            │    ├── In PostgreSQL speichern         │
                            │    └── MQTT publish (volle Wetterdaten)│
                            │                                        │
                            │  api.php → JSON-API (Fallback)         │
                            └────────────────────────────────────────┘
                                          │
                                          ▼ MQTT (real-time)
                            ┌────────────────────────────────────────┐
                            │  MagicMirror (node_helper.js)          │
                            │    ├── MQTT subscribe (instant update) │
                            │    ├── Watchdog: Poll API bei MQTT-Aus │
                            │    ├── Fallback: Wunderground API      │
                            │    └── Send to Frontend                │
                            └────────────────────────────────────────┘
```

---

## API-Endpunkte und Keys

| API | Zweck | URL / Key |
|-----|-------|-----------|
| Aggregator API | Primäre Datenquelle | `http://WEBSERVER_IP/weather-api/api.php?action=current` |
| Aggregator Dashboard | Web-Ansicht | `http://WEBSERVER_IP/weather-api/dashboard.php` |
| PWS API v2 | Fallback Wetterdaten | `apiKey` (YOUR_API_KEY) |
| WUnderground v3 | Fallback Icons | `wundergroundIconApiKey` (YOUR_ICON_API_KEY) |
| CloudWatcher | IR-Sensor Daten | `http://CLOUDWATCHER_IP:5000/api/data` |

**MyFritz (extern):** `https://YOUR_DOMAIN/weather-api/...`

---

## Config-Optionen (wichtigste)

```javascript
// Aggregator (primär)
aggregatorApiUrl: "http://WEBSERVER_IP/weather-api/api.php?action=current",
aggregatorFallbackTimeout: 180,     // Fallback nach 180s

// MQTT Real-Time Updates (Aggregator publisht nach jedem PWS-Push)
mqttServer: "mqtt://localhost:1883",      // MQTT-Broker (anpassen falls woanders)
mqttTopic: "weather/aggregator/new_data", // Topic für Wetterdaten
mqttFallbackTimeout: 5 * 60 * 1000,       // API-Poll wenn kein MQTT für 5 Min

// Wunderground (Fallback)
stationId: "YOUR_STATION_ID",
apiKey: "YOUR_API_KEY",                  // PWS API v2
wundergroundIconApiKey: "YOUR_ICON_API_KEY", // v3 API für Icons

// Koordinaten (für Fallback-Icons)
latitude: 50.242,
longitude: 6.603,

// Sensoren
showSensor1: true,
showSensor2: true,
sensor1Name: "Therapie",
sensor2Name: "WoZi",
```

---

## Datenfluss

```
1. MQTT-Update empfangen (real-time bei PWS-Push)
       │
       ├──► Kein MQTT für 5 Minuten? ──► Watchdog pollt Aggregator-API
       │
       ▼
2. Daten OK?
       │
       ├──► data_age_s > 180s? ────────────► Wunderground Fallback
       │                                          ├── PWS API v2 (Messdaten)
       ├──► cloudwatcher_online = false? ──► Wunderground Fallback
       │                                          └── v3 API (Icon)
       ▼
3. WMO → Icon Mapping (WmoToWeatherIcon)
       │
       ▼
4. WEATHER_DATA → Frontend
```

### Update-Mechanismus

| Methode | Beschreibung |
|---------|--------------|
| **MQTT (primär)** | Aggregator publisht nach jedem PWS-Push, MagicMirror erhält Update sofort |
| **Watchdog** | Wenn kein MQTT für 5 Minuten, pollt node_helper.js die Aggregator-API |
| **Wunderground** | Wenn Aggregator-Daten > 180s alt oder CloudWatcher offline |

### Fallback-Auslöser

| Bedingung | Grund |
|-----------|-------|
| `data_age_s > 180` | PWS-Daten zu alt |
| `cloudwatcher_online = false` | CloudWatcher offline, keine WMO-Ableitung möglich |
| API-Fehler | Aggregator nicht erreichbar |

---

## WMO-Code Ableitung (im Aggregator)

Der Aggregator leitet WMO-Codes lokal aus Sensordaten ab. Detaillierte Dokumentation: [WMO_Ableitungsmoeglichkeiten.md](weather-aggregator/docs/WMO_Ableitungsmoeglichkeiten.md)

| Bedingung | WMO Code | Ableitung |
|-----------|----------|-----------|
| Clear | 0 | Delta > 25°C |
| Mainly Clear | 1 | Delta > 18°C |
| Partly Cloudy | 2 | Delta > 8°C |
| Overcast | 3 | Delta ≤ 8°C |
| Haze | 4 | Humidity < 60%, Delta > 15 |
| Mist | 10 | Spread < 2.0, Humidity 90-97% |
| Shallow Fog | 11 | Temp ≤ Dewpoint, Wind < 1 m/s |
| Fog | 45 | Spread < 1.0, Humidity > 97%, Delta < 5 |
| Rime Fog | 48 | Fog + Temp < 0°C |
| Drizzle | 51, 53 | rate < 1.0 mm/h (light < 0.2, moderate 0.2-1.0) |
| Freezing Drizzle | 56, 57 | rate < 1.0 + Temp 0-0.5°C (light < 0.5, dense ≥ 0.5) |
| Rain | 61, 63, 65 | rate ≥ 1.0 mm/h (slight/moderate/heavy) |
| Freezing Rain | 66, 67 | Hohe Rate nahe 0°C (temp -1 bis 0.5°C, rate ≥ 1.0) |
| Sleet | 68, 69 | Niederschlag + Temp 1.5-3°C |
| Snow | 71, 73, 75 | Niederschlag + Temp < 1.5°C (sicher bei < -2°C) |
| Snow Grains | 77 | Rate < 0.2 mm/h + Temp < -2°C |

**Delta** = Außentemperatur - Sky-Temperatur (CloudWatcher IR-Sensor)
**Spread** = Außentemperatur - Taupunkt

---

## Weather Icon Lookup Tables

| Tabelle | Quelle | Codes |
|---------|--------|-------|
| `WmoToWeatherIcon` | Aggregator API | WMO 0-99 |
| `WUndergroundToWi` | Fallback v3 API | iconCode 0-47 |

Day/Night wird von API geliefert (`is_daylight` bzw. `dayOrNight`)

---

## Aggregator-Komponenten (Webserver)

| Datei | Beschreibung |
|-------|--------------|
| `pws_receiver.php` | Empfängt PWS-Push, ruft CloudWatcher ab, speichert in DB, publisht MQTT |
| `pws_receiver_post.php` | POST-Handler für Port 8000 |
| `wmo_derivation.php` | WMO-Code Ableitung aus Sensordaten |
| `api.php` | JSON-API (current, history, raw, status) - Fallback wenn MQTT nicht verfügbar |
| `dashboard.php` | Web-Dashboard mit Charts und WMO-Icon-Übersicht |
| `config.php` | Konfiguration (Schwellenwerte, Sensor-Namen, MQTT-Settings) |
| `db_connect.php` | DB-Credentials (nicht im Git!) |

**Pfad auf Webserver:** `/var/www/weather-api/`
**Source im Git:** `MMM-My-Actual-Weather/weather-aggregator/`

**Dashboard URLs:**
| URL | Beschreibung |
|-----|--------------|
| `http://WEBSERVER_IP/weather-api/dashboard.php` | Wetter-Übersicht mit Charts |
| `http://WEBSERVER_IP/weather-api/dashboard.php?tab=feedback` | Feedback-Eingabe (OK/Falsch) |
| `http://WEBSERVER_IP/weather-api/dashboard.php?tab=analyse` | Feedback-Analyse und Empfehlungen |
| `http://WEBSERVER_IP/weather-api/dashboard.php?tab=icons` | WMO-Icon-Übersicht (alle Mappings) |

**API-Endpoints:**
| Endpoint | Methode | Beschreibung |
|----------|---------|--------------|
| `api.php?action=current` | GET | Aktuelle Wetterdaten + WMO-Code |
| `api.php?action=history&hours=24` | GET | Historische Daten |
| `api.php?action=status` | GET | System-Status |
| `api.php?action=feedback` | POST | Feedback speichern (`{feedback: bool, correct_wmo: int, comment: string}`) |
| `api.php?action=feedback_stats` | GET | Feedback-Statistiken und Empfehlungen |
| `api.php?action=wmo_list` | GET | WMO-Codes sortiert nach Nähe zum aktuellen |
| `api.php?action=apply_recommendations` | POST | Schwellenwerte in config.php anpassen (mit Backup) |

**WMO-Dropdown-Sortierung:** Die `wmo_list` API sortiert WMO-Codes nach meteorologischer Verwechslungswahrscheinlichkeit (z.B. Nebel↔Niesel näher als Nebel↔Schnee). Labels sind auf Deutsch (definiert in `config.php` → `WMO_CONDITIONS`).

**Feedback-Datenbank-Spalten:**
| Spalte | Typ | Beschreibung |
|--------|-----|--------------|
| `feedback` | BOOLEAN | true = korrekt, false = falsch |
| `feedback_correct_wmo` | INTEGER | Korrigierter WMO-Code (bei false) |
| `feedback_comment` | TEXT | Optionaler Kommentar |

---

## CloudWatcher

- **Host:** CloudWatcher-Pi (CLOUDWATCHER_IP)
- **Service:** systemd cloudwatcher.service
- **Dashboard:** http://CLOUDWATCHER_IP:5000/
- **API:** http://CLOUDWATCHER_IP:5000/api/data

**Liefert:** sky_temp_c, rain_freq, mpsas, heater_pwm, is_raining, is_wet, is_daylight

### Regensensor-Erkennung

Der CloudWatcher hat einen kapazitiven Regensensor mit Heizung. Die Heizung wird aktiv, wenn Feuchtigkeit erkannt wird.

| Feld | Beschreibung |
|------|--------------|
| `rain_freq` | Sensorfrequenz: ~2100 (trocken), <1700 (Regen), 600-1700 (feucht) |
| `heater_pwm` | Heizungs-PWM 0-100% (>0 = Feuchtigkeit erkannt, Heizung aktiv) |
| `is_wet` | `true` wenn `rain_freq < 2100` (Sensoroberfläche feucht) |
| `is_raining` | `true` wenn `rain_freq < 1700` (aktiver Niederschlag) |

**Heater-Trick:** Wenn `heater_pwm > 30%` und `is_wet = true`, wertet die WMO-Ableitung dies als Niederschlag - auch wenn die PWS-Regenrate 0 mm/h zeigt. Dies erkennt leichten Regen/Schnee, der die PWS-Wippe nicht auslöst.

**Dokumentation:** [cloudwatcher/cloudwatcher-projekt-dokumentation.md](cloudwatcher/cloudwatcher-projekt-dokumentation.md)

---

## PostgreSQL-Datenbank

PostgreSQL auf dem Webserver. Credentials in `db_connect.php` (nicht im Git).

**Tabelle `weather_readings` (wichtige Spalten):**

| Spalte | Typ | Beschreibung |
|--------|-----|--------------|
| `id` | SERIAL | Primary Key |
| `timestamp` | TIMESTAMPTZ | Zeitstempel der Messung |
| `temp_c` | NUMERIC | Außentemperatur |
| `humidity` | NUMERIC | Luftfeuchtigkeit (%) |
| `precip_rate_mm` | NUMERIC | Niederschlagsrate PWS (mm/h) |
| `sky_temp_c` | NUMERIC | Himmelstemperatur (CloudWatcher) |
| `delta_c` | NUMERIC | temp_c - sky_temp_c |
| `rain_freq` | INTEGER | CloudWatcher Regensensor-Frequenz |
| `heater_pwm` | INTEGER | CloudWatcher Regensensor-Heizung PWM 0-100% |
| `cw_is_raining` | BOOLEAN | CloudWatcher Regensensor (rain_freq < 1700) |
| `cw_is_daylight` | BOOLEAN | CloudWatcher Tag/Nacht |
| `wmo_code` | INTEGER | Abgeleiteter WMO-Code |
| `condition` | TEXT | WMO-Bezeichnung |
| `feedback` | BOOLEAN | User-Feedback (true=korrekt, false=falsch) |
| `feedback_correct_wmo` | INTEGER | Korrigierter WMO-Code |
| `feedback_comment` | TEXT | Kommentar zum Feedback |

---

## MQTT-Konfiguration

### Aggregator (config.php auf Webserver)

```php
// MQTT notification (MagicMirror)
define('MQTT_BROKER_HOST', 'MQTT_BROKER_IP');  // MagicMirror-Pi (Mosquitto-Broker)
define('MQTT_BROKER_PORT', 1883);
define('MQTT_TOPIC', 'weather/aggregator/new_data');
```

Der Aggregator nutzt `mosquitto_pub` (Paket `mosquitto-clients` muss installiert sein).

### MagicMirror (config.js)

```javascript
mqttServer: "mqtt://localhost:1883",      // Broker läuft auf MagicMirror-Pi
mqttTopic: "weather/aggregator/new_data",
mqttFallbackTimeout: 5 * 60 * 1000,       // 5 Minuten
```

### MQTT-Payload (vom Aggregator)

```json
{
  "timestamp": "2026-02-02T18:30:00+01:00",
  "temp_c": 2.5,
  "humidity": 92,
  "dewpoint_c": 1.3,
  "pressure_hpa": 1012.5,
  "wind_speed_ms": 1.8,
  "wind_dir_deg": 225,
  "wind_gust_ms": 3.2,
  "precip_rate_mm": 0,
  "precip_today_mm": 1.2,
  "uv_index": 0,
  "solar_radiation": 0,
  "temp1_c": 21.5,
  "temp2_c": 19.8,
  "humidity1": 50,
  "humidity2": 36,
  "sky_temp_c": -8.5,
  "delta_c": 11.0,
  "rain_freq": 2048,
  "mpsas": 18.5,
  "heater_pwm": 0,
  "is_wet": false,
  "wmo_code": 3,
  "condition": "overcast",
  "is_raining": false,
  "is_daylight": false,
  "cloudwatcher_online": true,
  "data_age_s": 0
}
```

---

## Debug-Tipps

```bash
# MagicMirror Logs
pm2 logs MagicMirror --lines 50 | grep "MMM-My-Actual-Weather"

# MQTT-Traffic beobachten
mosquitto_sub -h localhost -t "weather/aggregator/new_data" -v

# Aggregator API testen
curl -s "http://WEBSERVER_IP/weather-api/api.php?action=current" | jq

# Aggregator Status
curl -s "http://WEBSERVER_IP/weather-api/api.php?action=status" | jq

# CloudWatcher API
curl -s "http://CLOUDWATCHER_IP:5000/api/data" | jq

# PWS-Push simulieren
curl -X POST "http://WEBSERVER_IP:8000/data/report/" \
  -d "PASSKEY=test&tempf=50&humidity=80&winddir=180&windspeedmph=5&rainratein=0&dailyrainin=0"

# MQTT manuell testen (vom Webserver)
ssh pi@WEBSERVER_IP "mosquitto_pub -h MQTT_BROKER_IP -t 'weather/aggregator/new_data' -m '{\"test\":true}'"
```

---

## Bekannte Einschränkungen

- **Gewitter nicht erkennbar**: Kein Blitzsensor vorhanden (WMO 95-99 nicht ableitbar)
- **Fallback ohne Sensoren**: Wunderground liefert keine temp1/temp2 oder CloudWatcher-Daten
- **Rate Limits**: WUnderground v3 API hat 500 Calls/Tag Limit

---

## AP-Historie (Änderungsprotokoll)

| AP | Datum | Beschreibung |
|----|-------|--------------|
| 1 | 2026-01-14 | PWS Push Server, Unit Conversion, Fallback-Logik |
| 2 | 2026-01-15 | State Machine, Open-Meteo Caching, PWS-Verbindungsstatus |
| 3 | 2026-01-16 | Layout vereinfacht (Table statt Flex), Wind-SVG durch Font ersetzt |
| 4 | 2026-01-18 | Dual Weather Provider (WUnderground/OpenMeteo), SunCalc entfernt |
| 44 | 2026-01-25 | CloudWatcher-Integration vorbereitet |
| 46 | 2026-01-28 | Weather-Aggregator implementiert, PWS-Server entfernt, Wunderground-Fallback |
| 47 | 2026-01-30 | WMO 55 Icon-Fix, CloudWatcher-Offline-Fallback, Double-Reload-Fix, CSS-Mapping-Korrekturen, Dashboard WMO-Icons-Tab |
| 50 | 2026-01-30 | Feedback-Mechanismus (OK/Falsch-Buttons, Analyse-Tab, Empfehlungen), Dashboard-Kosmetik (Header, Logo, DB-Größe) |
| 51 | 2026-01-30 | WMO-Logik-Fixes: Snow/Freezing umstrukturiert (Schnee Vorrang bei < -2°C), WMO 11 vor WMO 45 |
| 53 | 2026-01-31 | WMO-Labels auf Deutsch, Dropdown-Sortierung verbessert (Nebel↔Niesel näher) |
| 54 | 2026-02-02 | MQTT Real-Time Updates: Aggregator publisht zu MQTT, Watchdog-Fallback auf API-Polling |
| 55 | 2026-02-03 | Heater-PWM Regenerkennung: CloudWatcher heater_pwm als zusätzlicher Niederschlagsindikator (WET_THRESHOLD 2100) |

---

## Dateien

| Datei | Beschreibung |
|-------|--------------|
| `MMM-My-Actual-Weather.js` | Frontend (DOM, Config-Defaults, Farbgradient, Watchdog) |
| `node_helper.js` | Backend (MQTT-Client, Aggregator-API-Fallback, Wunderground-Fallback, Icon-Mapping) |
| `package.json` | Dependencies (mqtt, node-fetch) |
| `README.md` | Dokumentation (öffentlich, für GitHub) |
| `MMM-My-Actual-Weather.css` | Styling |
| `weather-aggregator/` | Aggregator PHP-Code (deployed auf Webserver) |

---

*Projektdokumentation für internen Gebrauch (Claude-Kontext)*
