# MMM-My-Actual-Weather - Projektdokumentation

**Autor:** Dr. Ralf Korell
**Modul:** MMM-My-Actual-Weather
**Status:** Aktiv
**Letzte Aktualisierung:** 2026-01-26

---

## Übersicht

- **Datenquellen**: Eigene PWS (Station IGEROL23) + Wunderground API
- **Weather Icon Provider**: Wählbar zwischen WUnderground (v3 API) oder Open-Meteo
- **Features**: HTTP-Server auf Port 8000 für PWS-Push, Auto-Fallback auf API bei Timeout
- **Zusatzsensoren**: temp1f (WoZi), temp2f (Therapie)
- **Besonderheiten**: Temperatur-Farbgradient, Tag/Nacht-Icons via API (dayOrNight / is_day)
- **Koordinaten**: 50.242 / 6.603 (Müllenborn)
- **Dependencies**: node-fetch (kein SunCalc mehr - entfernt in AP 4)

---

## API-Endpunkte und Keys

| API | Zweck | Key |
|-----|-------|-----|
| PWS API v2 | Wetterdaten von eigener Station | `apiKey` (d1a87...) |
| WUnderground v3 | Weather Icons | `wundergroundIconApiKey` (6532d...) |
| Open-Meteo | Weather Icons (Alternative) | kein Key nötig |

**Wichtig**: Der PWS-Key (`apiKey`) funktioniert NICHT für die v3 API. Separater `wundergroundIconApiKey` erforderlich.

---

## Config-Optionen (wichtigste)

```javascript
weatherProvider: "wunderground",      // "openmeteo" oder "wunderground"
wundergroundIconApiKey: "6532d...",   // Separater Key für v3 API
latitude: 50.242,                     // Für Weather Icon Provider
longitude: 6.603,
pwsPushPort: 8000,                    // HTTP-Server für PWS-Push
pwsPushInterval: 60,                  // Erwartetes Push-Intervall (Sekunden)
```

---

## State Machine

```
                         ┌─────────────────────────────────────┐
                         │  PWS push received (from any state) │
                         └──────────────────┬──────────────────┘
                                            ▼
┌──────────────┐  3 sec    ┌─────────────────────┐  3x interval  ┌──────────────┐
│ INITIALIZING │ ────────► │   WAITING_FOR_PWS   │ ────────────► │   API_ONLY   │
└──────────────┘  timeout  └─────────────────────┘    timeout    └──────────────┘
                                    ▲                                   │
                                    │ 3x interval timeout               │ 60 min
                                    │                                   │ recheck
                           ┌────────┴───────┐                           │
                           │   PWS_ACTIVE   │ ◄─────────────────────────┘
                           └────────────────┘
```

- **INITIALIZING**: Warten auf ersten PWS-Push (max 3 Sekunden)
- **PWS_ACTIVE**: PWS liefert Daten, API nur für Weather Icons
- **WAITING_FOR_PWS**: API-Daten anzeigen, auf PWS warten
- **API_ONLY**: Nur API-Daten, alle 60 Min erneut prüfen

---

## Datenfluss

```
PWS Push (:8000)                    PWS API v2 (Fallback)
      │                                    │
      ▼                                    ▼
 processPwsPush()                  loadApiDataInBackground()
 [°F→°C, mph→m/s, in→mm]                   │
      │                                    │
      ▼                                    ▼
   pwsData ──────────────────────► apiDataCache
                     │
                     ▼
              sendToFrontend()
              + getWindDirection()
              + weatherIconClass (aus Provider-Cache)
                     │
                     ▼
              WEATHER_DATA → Frontend
```

---

## Lokale Berechnungen

| Funktion | Zweck |
|----------|-------|
| `fahrenheitToCelsius()` | PWS Push sendet °F |
| `mphToMs()` | PWS Push sendet mph |
| `inchesToMm()` | PWS Push sendet inches |
| `getWindDirection()` | Grad → Himmelsrichtung (N, NO, etc.) |
| `parsePwsTimestamp()` | URLSearchParams dekodiert + zu Leerzeichen |

---

## Weather Icon Lookup Tables

- `OpenMeteoToWi`: WMO Codes (0-99) → .wi-xxx Klassen
- `WUndergroundToWi`: iconCode (0-47) → .wi-xxx Klassen
- Day/Night wird von API geliefert (`is_day` bzw. `dayOrNight`)

---

## CSS Icon Mapping

Custom CSS enthält 3 Kontexte für Weather Icons:
- `.weather_current` - Standard MagicMirror Weather
- `.weather_current_own` - MMM-My-Actual-Weather (PNG)
- `.weather_forecast` - Forecast (SVG)

Icons in: `/home/pi/MagicMirror/css/icons/current/` (91 PNGs) und `.../forecast/` (SVGs)

---

## Debug-Tipps

```bash
# Weather Provider und Icon prüfen
pm2 logs MagicMirror --lines 50 | grep "provider="

# State Machine Status
pm2 logs MagicMirror --lines 50 | grep "State"

# PWS Push empfangen?
pm2 logs MagicMirror --lines 50 | grep "PWS push"

# API-Fehler?
pm2 logs MagicMirror --lines 100 | grep -i "error.*my-actual"
```

---

## Bekannte Einschränkungen

- **Kein Retry bei API-Fehlern**: Bei Timeout wird erst beim nächsten `updateInterval` (5 Min) erneut versucht
- **Open-Meteo Genauigkeit**: Kann für manche Standorte ungenau sein (z.B. "Overcast" bei klarem Himmel)
- **Rate Limits**: WUnderground v3 API hat 500 Calls/Tag Limit (bei 5-Min-Intervall: 288/Tag → OK)

---

## AP-Historie (Änderungsprotokoll)

| AP | Datum | Beschreibung |
|----|-------|--------------|
| 1 | 2026-01-14 | PWS Push Server, Unit Conversion, Fallback-Logik |
| 2 | 2026-01-15 | State Machine, Open-Meteo Caching, PWS-Verbindungsstatus |
| 3 | 2026-01-16 | Layout vereinfacht (Table statt Flex), Wind-SVG durch Font ersetzt |
| 4 | 2026-01-18 | Dual Weather Provider (WUnderground/OpenMeteo), SunCalc entfernt, Lookup Tables, README Architecture Docs |
| 44 | 2026-01-25 | CloudWatcher-Integration vorbereitet (Software auf CloudWatcher-Pi, Dummy-Modus) |

---

## Dateien

| Datei | Beschreibung |
|-------|--------------|
| `MMM-My-Actual-Weather.js` | Frontend (DOM, Config-Defaults, Farbgradient) |
| `node_helper.js` | Backend (APIs, State Machine, PWS Server, Lookup Tables) |
| `package.json` | Dependencies (nur node-fetch) |
| `README.md` | Dokumentation (öffentlich, für GitHub) |
| `MMM-My-Actual-Weather.css` | Styling |

---

## CloudWatcher Integration (geplant)

**Status:** Software vorbereitet, Hardware bestellt (~500€)

**Ziel:** AAG CloudWatcher IR-Sensor zur lokalen Messung der Wolkenbedeckung. Ergänzt PWS-Daten um den fehlenden Bewölkungsgrad, da DWD-Stationen (Lissendorf/Nürburg) 8-25 km entfernt sind.

**Detaillierte Dokumentation:** [cloudwatcher/cloudwatcher-projekt-dokumentation.md](cloudwatcher/cloudwatcher-projekt-dokumentation.md)

**CloudWatcher-Pi:**
- **Host:** CloudWatcher (172.23.56.60)
- **SSH:** `ssh pi@172.23.56.60` (Key-Auth von MagicMirrorPi5)
- **Service:** `/home/pi/cloudwatcher/` (systemd: cloudwatcher.service)
- **Dashboard:** http://172.23.56.60:5000/
- **API:** http://172.23.56.60:5000/api/data

**Aktueller Modus:** Dummy (simulierte Daten bis Hardware angeschlossen)

**Befehle:**
```bash
# Status prüfen
ssh pi@172.23.56.60 "sudo systemctl status cloudwatcher"

# Logs ansehen
ssh pi@172.23.56.60 "sudo journalctl -u cloudwatcher -f"

# Auf echte Hardware umstellen (wenn CloudWatcher angeschlossen)
ssh pi@172.23.56.60 "sudo sed -i 's|--dummy||' /etc/systemd/system/cloudwatcher.service && sudo systemctl daemon-reload && sudo systemctl restart cloudwatcher"
```

**Nächste Schritte:**
1. CloudWatcher-Hardware beschaffen (bestellt, in Lieferung)
2. USB-RS232-Adapter anschließen
3. Baudrate ermitteln (9600 oder 19200)
4. Service auf echte Hardware umstellen
5. Schwellwerte kalibrieren
6. Weather-Aggregator auf Webserver einrichten
7. MagicMirror-Integration (Veto-Logik für Bewölkung/Nebel)

---

## Weather-Aggregator (geplant)

**Ziel:** Zentraler Datensammler auf dem Webserver, der PWS- und CloudWatcher-Daten aggregiert und eine API für interne und externe Nutzung bereitstellt.

**Status:** Ausführliche Planungsphase erforderlich (Komplexität!)

**Webserver:**
- **Host:** WebServer (172.23.56.196)
- **SSH:** `ssh pi@172.23.56.196` (Key-Auth von MagicMirrorPi5)
- **Extern:** `https://3iw49xthj5blmf7n.myfritz.net`
- **Stack:** Apache + PHP + PostgreSQL + Flask

**Geplante Struktur:**
```
/home/pi/weather-api/  oder  /var/www/weather-api/
├── weather_aggregator.py    # Flask-Service (Port 5001)
├── config.py                # Endpoints, API-Keys
└── templates/
    └── dashboard.html       # Wetter-Dashboard (optional)
```

**Datenquellen:**
- CloudWatcher-Pi: http://172.23.56.60:5000/api/data
- PWS: Wunderground API oder direkt

**Kernkonzept: Autarke Wetterkonditions-Ableitung**

Mit PWS + CloudWatcher können wir ~25 WMO-Codes selbst ableiten (ohne Cloud-APIs):

| Kategorie | WMO Codes | Ableitung aus |
|-----------|-----------|---------------|
| Cloud Cover | 0, 1, 2, 3 | CloudWatcher Delta |
| Fog | 45, 48 | Spread + Humidity + Delta |
| Drizzle | 51, 53, 55 | precip_rate sehr gering |
| Freezing Drizzle | 56, 57 | Drizzle + Temp < 0°C |
| Rain | 61, 63, 65 | precip_rate Schwellwerte |
| Freezing Rain | 66, 67 | Rain + Temp < 0°C |
| Snow | 71, 73, 75 | Niederschlag + Temp < ~2°C |
| Rain Showers | 80, 81, 82 | Regen + schnelle Intensitätsänderung |
| Snow Showers | 85, 86 | Schnee + schnelle Intensitätsänderung |

**Nicht ableitbar:** Gewitter (95-99) - kein Blitzsensor

**Entscheidungsbaum (State Machine):**
```
┌─ Niederschlag? (PWS precip_rate > 0 OR CloudWatcher rain_sensor)
│   ├─ JA → Temp < 0°C? → FREEZING (56-57, 66-67)
│   │        Temp < 2°C? → SNOW (71-75, 85-86)
│   │        sonst → RAIN/DRIZZLE (51-55, 61-65, 80-82)
│   │        Intensität → light/moderate/heavy
│   │        Zeitverlauf → Schauer vs. Dauerregen
│   └─ NEIN
│       ├─ Nebel? (Spread < 2.5 AND Humidity > 95% AND Delta < 10)
│       │   └─ JA → FOG (45, 48)
│       └─ NEIN → Bewölkung (CloudWatcher Delta)
│           ├─ Delta > 25 → CLEAR (0)
│           ├─ Delta > 20 → MAINLY_CLEAR (1)
│           ├─ Delta > 15 → PARTLY_CLOUDY (2)
│           └─ Delta ≤ 15 → OVERCAST (3)
└─ Tag/Nacht (CloudWatcher LDR) → Icon-Variante
```

**Datenhistorie (PostgreSQL):**
- Zeitverläufe ermöglichen Schauer-Erkennung (schnelle Intensitätsänderung)
- Drucktrends für Fronterkennung
- Langzeit-Kalibrierung der Schwellwerte

**Zu klären in Planungsphase:**
- Datenbank-Schema für Zeitreihen
- Retention Policy (wie lange speichern?)
- Externe API: Auth erforderlich? Rate Limiting?
- Koexistenz mit CPQI auf demselben Server
- Apache Reverse Proxy für Flask?

**Arbeitskontext:**
- Wird von MagicMirrorPi5 aus via SSH entwickelt (nicht von der Webserver-Claude-Instanz, die für CPQI zuständig ist)
- **Git-Workflow:** Wie CloudWatcher - Source im lokalen Repo, Deployment per rsync
- Source-Verzeichnis: `MMM-My-Actual-Weather/weather-aggregator/` (noch anzulegen)

---

*Projektdokumentation für internen Gebrauch (Claude-Kontext)*
