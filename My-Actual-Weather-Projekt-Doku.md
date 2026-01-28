# MMM-My-Actual-Weather - Projektdokumentation

**Autor:** Dr. Ralf Korell
**Modul:** MMM-My-Actual-Weather
**Status:** Aktiv
**Letzte Aktualisierung:** 2026-01-28

---

## Übersicht

- **Datenquelle primär**: Weather-Aggregator auf Webserver (172.23.56.196)
- **Datenquelle Fallback**: Wunderground API (wenn Aggregator-Daten > 180s alt)
- **Aggregator kombiniert**: PWS (IGEROL23) + CloudWatcher (IR-Sensor)
- **WMO-Code**: Lokal abgeleitet aus Sensordaten (kein Cloud-API nötig)
- **Zusatzsensoren**: temp1 (Therapie), temp2 (WoZi)
- **Besonderheiten**: Temperatur-Farbgradient, Tag/Nacht-Icons, Auto-Fallback
- **Koordinaten**: 50.242 / 6.603 (Müllenborn)
- **Dependencies**: node-fetch

---

## Architektur

```
┌─────────────┐  POST :8000/data/report/
│  PWS        │ ────────────────────────────┐
│ (IGEROL23)  │                             │
└─────────────┘                             ▼
                            ┌────────────────────────────────────────┐
                            │  Webserver (172.23.56.196)             │
                            │                                        │
┌─────────────┐  HTTP GET   │  pws_receiver.php                      │
│ CloudWatcher│ ◄───────────│    ├── PWS-Daten parsen                │
│(172.23.56.60)             │    ├── CloudWatcher-API abrufen        │
└─────────────┘             │    ├── WMO-Code ableiten               │
                            │    └── In PostgreSQL speichern         │
                            │                                        │
                            │  api.php → JSON-API                    │
                            └────────────────────────────────────────┘
                                          │
                                          ▼ HTTP GET (polling 60s)
                            ┌────────────────────────────────────────┐
                            │  MagicMirror (node_helper.js)          │
                            │    ├── Poll aggregatorApiUrl           │
                            │    ├── Fallback: Wunderground API      │
                            │    └── Send to Frontend                │
                            └────────────────────────────────────────┘
```

---

## API-Endpunkte und Keys

| API | Zweck | URL / Key |
|-----|-------|-----------|
| Aggregator API | Primäre Datenquelle | `http://172.23.56.196/weather-api/api.php?action=current` |
| Aggregator Dashboard | Web-Ansicht | `http://172.23.56.196/weather-api/dashboard.php` |
| PWS API v2 | Fallback Wetterdaten | `apiKey` (d1a87...) |
| WUnderground v3 | Fallback Icons | `wundergroundIconApiKey` (6532d...) |
| CloudWatcher | IR-Sensor Daten | `http://172.23.56.60:5000/api/data` |

**MyFritz (extern):** `https://3iw49xthj5blmf7n.myfritz.net/weather-api/...`

---

## Config-Optionen (wichtigste)

```javascript
// Aggregator (primär)
aggregatorApiUrl: "http://172.23.56.196/weather-api/api.php?action=current",
aggregatorFallbackTimeout: 180,     // Fallback nach 180s

// Wunderground (Fallback)
stationId: "IGEROL23",
apiKey: "d1a87...",                  // PWS API v2
wundergroundIconApiKey: "6532d...", // v3 API für Icons

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
1. Poll Aggregator-API (alle 60s)
       │
       ▼
2. Daten OK und < 180s alt?
       │
   JA  │  NEIN
       │    └──► Wunderground Fallback
       │              ├── PWS API v2 (Messdaten)
       │              └── v3 API (Icon)
       ▼
3. WMO → Icon Mapping (WmoToWeatherIcon)
       │
       ▼
4. WEATHER_DATA → Frontend
```

---

## WMO-Code Ableitung (im Aggregator)

Der Aggregator leitet WMO-Codes lokal aus Sensordaten ab:

| Bedingung | WMO Code | Ableitung |
|-----------|----------|-----------|
| Clear | 0 | Delta > 25°C |
| Mainly Clear | 1 | Delta > 20°C |
| Partly Cloudy | 2 | Delta > 15°C |
| Overcast | 3 | Delta ≤ 15°C |
| Fog | 45 | Spread < 2.5 AND Humidity > 95% AND Delta < 10 |
| Drizzle | 51, 53, 55 | precip_rate gering |
| Rain | 61, 63, 65 | precip_rate nach Intensität |
| Freezing Rain | 66, 67 | Rain + Temp < 0°C |
| Snow | 71, 73, 75 | Niederschlag + Temp < 2°C |

**Delta** = Außentemperatur - Sky-Temperatur (CloudWatcher IR-Sensor)

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
| `pws_receiver.php` | Empfängt PWS-Push, ruft CloudWatcher ab, speichert in DB |
| `pws_receiver_post.php` | POST-Handler für Port 8000 |
| `wmo_derivation.php` | WMO-Code Ableitung aus Sensordaten |
| `api.php` | JSON-API (current, history, raw, status) |
| `dashboard.php` | Web-Dashboard mit Charts |
| `config.php` | Konfiguration (Schwellwerte, Sensor-Namen) |
| `db_connect.php` | DB-Credentials (nicht im Git!) |

**Pfad auf Webserver:** `/var/www/weather-api/`
**Source im Git:** `MMM-My-Actual-Weather/weather-aggregator/`

---

## CloudWatcher

- **Host:** CloudWatcher-Pi (172.23.56.60)
- **Service:** systemd cloudwatcher.service
- **Dashboard:** http://172.23.56.60:5000/
- **API:** http://172.23.56.60:5000/api/data

**Liefert:** sky_temp_c, rain_freq, mpsas, is_raining, is_daylight

**Dokumentation:** [cloudwatcher/cloudwatcher-projekt-dokumentation.md](cloudwatcher/cloudwatcher-projekt-dokumentation.md)

---

## Debug-Tipps

```bash
# MagicMirror Logs
pm2 logs MagicMirror --lines 50 | grep "MMM-My-Actual-Weather"

# Aggregator API testen
curl -s "http://172.23.56.196/weather-api/api.php?action=current" | jq

# Aggregator Status
curl -s "http://172.23.56.196/weather-api/api.php?action=status" | jq

# CloudWatcher API
curl -s "http://172.23.56.60:5000/api/data" | jq

# PWS-Push simulieren
curl -X POST "http://172.23.56.196:8000/data/report/" \
  -d "PASSKEY=test&tempf=50&humidity=80&winddir=180&windspeedmph=5&rainratein=0&dailyrainin=0"
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

---

## Dateien

| Datei | Beschreibung |
|-------|--------------|
| `MMM-My-Actual-Weather.js` | Frontend (DOM, Config-Defaults, Farbgradient) |
| `node_helper.js` | Backend (Aggregator-Polling, Wunderground-Fallback, Icon-Mapping) |
| `package.json` | Dependencies (node-fetch) |
| `README.md` | Dokumentation (öffentlich, für GitHub) |
| `MMM-My-Actual-Weather.css` | Styling |
| `weather-aggregator/` | Aggregator PHP-Code (deployed auf Webserver) |

---

*Projektdokumentation für internen Gebrauch (Claude-Kontext)*
