# WMO-Code Ableitung aus Sensordaten

**Erstellt:** 2026-01-29
**Letzte Aktualisierung:** 2026-01-29
**Zweck:** Dokumentation der Möglichkeiten zur lokalen WMO-Wettercode-Ableitung aus PWS- und CloudWatcher-Sensordaten.

---

## Verwandte Dokumentation

- [README.md](../../README.md) - Modul-Dokumentation (öffentlich)
- [My-Actual-Weather-Projekt-Doku.md](../../My-Actual-Weather-Projekt-Doku.md) - Projektdokumentation (intern)
- [config.php](../config.php) - Aktuelle Schwellenwerte
- [wmo_derivation.php](../wmo_derivation.php) - Implementierung

---

## Übersicht

Das Ziel ist, WMO 4677 Present Weather Codes **lokal** aus eigenen Sensordaten abzuleiten, ohne externe Wetter-APIs zu benötigen. Die Kombination aus PWS (Personal Weather Station) und CloudWatcher IR-Sensor ermöglicht eine erstaunlich präzise Wetterklassifikation.

---

## Verfügbare Sensordaten

### PWS (Personal Weather Station) - Gemessen

| Feld | Parameter | Einheit | Verwendung |
|------|-----------|---------|------------|
| `temp_c` | Außentemperatur | °C | Niederschlagsart, Nebel |
| `humidity` | Luftfeuchtigkeit | % | Nebel, Dunst |
| `wind_speed_ms` | Windgeschwindigkeit | m/s | Bodennebel, Schauer |
| `wind_dir_deg` | Windrichtung | ° | — |
| `wind_gust_ms` | Windböen | m/s | — |
| `precip_rate_mm` | Niederschlagsrate | mm/h | Intensität |
| `precip_today_mm` | Tagesniederschlag | mm | — |
| `uv_index` | UV-Index | — | Dunst-Erkennung |
| `solar_radiation` | Solarstrahlung | W/m² | Gewitter-Proxy, Tag/Nacht |
| `temp1_c` | Sensor 1 (Indoor) | °C | Bodenfrost |
| `temp2_c` | Sensor 2 (Indoor) | °C | Bodenfrost |
| `humidity1` | Sensor 1 Feuchte | % | — |
| `humidity2` | Sensor 2 Feuchte | % | — |

### CloudWatcher - Gemessen

| Feld | Parameter | Einheit | Verwendung |
|------|-----------|---------|------------|
| `sky_temp_c` | Himmelstemperatur | °C | Bewölkung (Delta) |
| `rain_freq` | Regensensor-Frequenz | Hz | — |
| `mpsas` | Himmelshelligkeit | mag/arcsec² | — |
| `is_raining` | Niederschlag erkannt | bool | Früherkennung! |
| `is_daylight` | Tageslicht | bool | Icon-Auswahl |

### Berechnete Werte

| Feld | Formel | Verwendung |
|------|--------|------------|
| `dewpoint_c` | Magnus-Formel | Nebel (Spread) |
| `spread` | temp_c − dewpoint_c | Nebel vs. Bewölkung |
| `delta_c` | temp_c − sky_temp_c | Bewölkungsgrad |
| `pressure_hpa` | Barometrische Formel (QNH) | — |

---

## WMO-Codes und Ableitungslogik

### Codes 00-03: Bewölkung (Basis)

Die Bewölkung wird primär über **Delta T** (Außentemperatur minus Himmelstemperatur) bestimmt.

| WMO | Bezeichnung | Delta-Schwelle | Zusatzbedingung |
|-----|-------------|----------------|-----------------|
| 00 | Wolkenlos | > 25°C | Nacht: delta hoch, Tag: solar_radiation max |
| 01 | Überwiegend klar | > 18-20°C | Hohe, dünne Cirren |
| 02 | Teilweise bewölkt | > 8-15°C | Mittelhohe Bewölkung |
| 03 | Bedeckt | < 5-8°C | Dichte Schichtbewölkung |

**Hinweis:** Die exakten Schwellenwerte müssen für den Standort kalibriert werden. Empfohlene Startwerte: 25/18/8/5.

---

### Codes 04-19: Dunst, Nebel, Sichtbehinderung

#### WMO 04 - Dunst/Rauch (Haze)

```
Bedingung:
  - humidity < 60%
  - delta_c > 15 (kein dichtes Gewölk)
  - uv_index niedrig (trotz Tageslicht)
  - ODER: humidity 60-80% mit unruhigem IR-Wert
```

#### WMO 10 - Feuchter Dunst (Mist)

```
Bedingung:
  - spread (temp - dewpoint) < 2.0°C
  - humidity > 90%
  - humidity < 98% (sonst Nebel)
  - Sichtweite typisch 1-5 km
```

**Unterschied zu Nebel:** Mist hat etwas bessere Sichtweite, Nebel < 1 km.

#### WMO 11 - Flacher Bodennebel (Shallow Fog)

```
Bedingung:
  - temp_c < dewpoint_c (Kondensation)
  - wind_speed_ms < 1.0 (windstill)
  - humidity > 95%
  - Typisch: Früh morgens in Senken
```

#### WMO 45 - Nebel (Fog)

```
Bedingung (aktuell):
  - spread < 2.5°C
  - humidity > 95%
  - delta_c < 10°C

Bedingung (optimiert):
  - spread < 1.0°C
  - humidity > 97-98%
  - delta_c < 2-5°C

VETO-Regel:
  - Wenn spread > 3.0°C → KEIN Nebel, sondern WMO 03 (Bedeckt)
```

**Profi-Trick "Nebel-Falle":** CloudWatcher kann Nebel nicht von sehr tiefer Wolke unterscheiden. Der Spread ist der Schlüssel!

#### WMO 48 - Reifnebel (Depositing Rime Fog)

```
Bedingung:
  - Alle Nebel-Bedingungen erfüllt
  - temp_c < 0°C (Reifbildung)
```

---

### Codes 50-59: Nieselregen (Drizzle)

Nieselregen = Sehr feine Tröpfchen, geringe Intensität.

**Schlüssel:** CloudWatcher meldet `is_raining = true`, aber PWS zeigt `precip_rate < 0.2 mm/h`.

| WMO | Bezeichnung | Bedingung |
|-----|-------------|-----------|
| 51 | Niesel leicht | is_raining & rate < 0.2 & temp > 0°C |
| 53 | Niesel mäßig | is_raining & rate 0.2-0.5 & temp > 0°C |
| 55 | Niesel stark | is_raining & rate 0.5-1.0 & temp > 0°C |
| 56 | Gefr. Niesel leicht | is_raining & rate < 0.2 & temp < 0°C |
| 57 | Gefr. Niesel stark | is_raining & rate > 0.2 & temp < 0°C & humidity > 95% |

**Profi-Trick "Dry Rain":** CloudWatcher erkennt Niederschlag BEVOR die PWS-Wippe kippt! Nutze `is_raining` für sofortige Reaktion.

#### Implementierung: Dual-Sensor-Logik

Die Kombination beider Regensensoren ermöglicht sowohl frühe Erkennung als auch Intensitätsmessung:

```php
// Niederschlagserkennung: OR-Logik
$is_precipitating = ($precip_rate > 0) || $cw_is_raining;

// Nieselregen-Unterscheidung: AND-Logik
$is_drizzle = $cw_is_raining && ($precip_rate < DRIZZLE_MAX);
```

| Szenario | PWS rate | CW is_raining | Ergebnis |
|----------|----------|---------------|----------|
| Kein Regen | 0 | false | Kein Niederschlag |
| Sehr feiner Niesel | 0 | true | **Drizzle** (CW erkennt früher!) |
| Leichter Niesel | 0.1 | true | **Drizzle** |
| Leichter Regen | 1.0 | true | Rain slight |
| Starker Regen | 8.0 | true | Rain heavy |
| PWS-only (selten) | 1.0 | false | Rain slight |

**Vorteile dieser Kombination:**
- **Früherkennung:** CloudWatcher reagiert sofort auf feinste Tröpfchen
- **Intensitätsmessung:** PWS-Wippe liefert quantitative Rate (mm/h)
- **Niesel-Unterscheidung:** CW meldet Regen, PWS registriert kaum etwas → Drizzle

---

### Codes 60-69: Regen (Rain)

| WMO | Bezeichnung | Rate (mm/h) | Temperatur |
|-----|-------------|-------------|------------|
| 61 | Regen leicht | 0.2 - 2.5 | > 0.5°C |
| 63 | Regen mäßig | 2.5 - 7.5 | > 0.5°C |
| 65 | Regen stark | > 7.5 | > 0.5°C |
| 66 | Gefr. Regen leicht | < 2.5 | < 0.5°C |
| 67 | Gefr. Regen stark | > 2.5 | < 0.5°C |
| 68 | Schneeregen leicht | beliebig | 1.0 - 3.0°C |
| 69 | Schneeregen stark | > 2.5 | 1.0 - 3.0°C |

**WMO 68/69 (Schneeregen):** Jetzt implementiert! Tritt auf bei Temperaturen 1-3°C (knapp über dem Gefrierpunkt).

---

### Codes 70-79: Schnee (Snow)

| WMO | Bezeichnung | Rate (mm/h) | Temperatur |
|-----|-------------|-------------|------------|
| 71 | Schnee leicht | < 1.0 | < 1.0°C |
| 73 | Schnee mäßig | 1.0 - 3.0 | < 1.0°C |
| 75 | Schnee stark | > 3.0 | < 1.0°C |
| 77 | Schneegriesel | < 0.2 | < -2.0°C |

**Profi-Trick "Winter-Schnee":** CloudWatcher erkennt Schnee über den Heizsensor (braucht Energie zum Schmelzen). PWS-Wippe versagt oft bei Schnee!

#### Implementierung: Schnee-Erkennung mit CloudWatcher

Der CloudWatcher hat einen **beheizten Regensensor**:
- Beheizte Oberfläche hält Sensor frei von Eis/Schnee
- Wenn Schnee/Eis auf den Sensor fällt → Schmelzenergie wird benötigt → Frequenzänderung (`rain_freq`)
- CloudWatcher setzt `is_raining = true` (auch bei Schnee!)

**Problem PWS-Wippe bei Schnee:**
- PWS misst Niederschlag volumetrisch über Kippwippe
- Bei Schnee: Wippe kann verstopfen, Schnee schmilzt nicht schnell genug
- `precip_rate_mm` ist bei Schnee oft **unzuverlässig niedrig**

**Lösung durch Dual-Sensor-Logik:**
```php
$is_precipitating = ($precip_rate > 0) || $cw_is_raining;
```
→ Wenn PWS-Wippe bei Schnee versagt, erkennt CloudWatcher trotzdem Niederschlag!

**Schnee vs. Regen Unterscheidung:**
- Erfolgt ausschließlich über **Temperatur** (nicht über Sensortyp)
- temp < 1°C + Niederschlag erkannt → **Schnee** (WMO 71/73/75)
- temp < -2°C + sehr geringe Rate → **Schneegriesel** (WMO 77)

---

### Codes 80-99: Schauer (Showers)

Schauer = **Intermittierender** Niederschlag mit starker zeitlicher Varianz.

| WMO | Bezeichnung | Bedingung |
|-----|-------------|-----------|
| 80 | Regenschauer leicht | rate < 2.5 & hohe Varianz |
| 81 | Regenschauer mäßig | rate 2.5-7.5 & hohe Varianz |
| 82 | Regenschauer stark | rate > 7.5 & hohe Varianz |
| 85 | Schneeschauer leicht | wie 80, temp < 1°C |
| 86 | Schneeschauer stark | wie 82, temp < 1°C |

**Erkennung:** Prüfe `precip_rate` der letzten 10-15 Minuten. Wenn Standardabweichung hoch oder > 3 Wechsel zwischen 0 und > 0 → Schauer.

```sql
-- Beispiel: Varianz der letzten 10 Minuten
SELECT STDDEV(precip_rate_mm) as varianz
FROM weather_readings
WHERE timestamp > NOW() - INTERVAL '10 minutes';
```

---

### Codes 95-99: Gewitter (Thunderstorm)

**NICHT direkt messbar** - kein Blitzsensor vorhanden.

#### Gewitter-Proxy (experimentell)

```
Bedingung für "Gewitter wahrscheinlich":
  - solar_radiation fällt um > 80% innerhalb 5 Minuten
  - delta_c fällt rapide (massive Wolkenentwicklung)
  - OHNE sofortigen Niederschlag
  → Deutet auf Cumulonimbus-Entwicklung hin
```

---

## Fortgeschrittene Logik

### Intermittierend vs. Kontinuierlich

WMO unterscheidet zwischen kontinuierlichem und unterbrochenem Niederschlag:

- **Kontinuierlich (61, 63, 65):** Niederschlag ohne Unterbrechung
- **Intermittierend (60, 62, 64):** Niederschlag mit Pausen

```
Logik:
  - Prüfe letzte 60 Minuten
  - Wenn precip_rate > 3x zwischen 0 und > 0 wechselt
  → Intermittierend (60, 62, 64 statt 61, 63, 65)
```

### Lifting Fog (Sich auflösender Nebel)

```
Bedingung:
  - Vorher: Nebel erkannt (WMO 45)
  - delta_c STEIGT (IR wird kälter = Wolke hebt sich)
  - humidity noch hoch
  - solar_radiation STEIGT
  → Nebel hebt sich zu Stratus ab
```

### Bodenfrost-Warnung

```
Bedingung:
  - temp1_c oder temp2_c < 0°C (bodennah platziert)
  - temp_c (2m Höhe) > 0°C
  → Bodenfrost trotz positiver Lufttemperatur
```

---

## Implementierungsstatus

### ✅ Implementiert (wmo_derivation.php)

**Bewölkung (mit optimierten Delta-Schwellen 25/18/8):**
- WMO 00: Wolkenlos (delta > 25°C)
- WMO 01: Überwiegend klar (delta > 18°C)
- WMO 02: Teilweise bewölkt (delta > 8°C)
- WMO 03: Bedeckt (delta ≤ 8°C)

**Dunst/Nebel (mit strikten Schwellen):**
- WMO 04: Dunst (humidity < 60%, delta > 15)
- WMO 10: Feuchter Dunst (spread < 2.0, humidity 90-97%)
- WMO 11: Flacher Bodennebel (temp ≤ dewpoint, wind < 1 m/s, humidity > 95%)
- WMO 45: Nebel (spread < 1.0, humidity > 97%, delta < 5)
- WMO 48: Reifnebel (Nebelbedingungen + temp < 0°C)
- **Fog VETO:** spread > 3.0 → kein Nebel möglich

**Nieselregen (temp ≥ 3°C, rate < 1.0 mm/h):**
- WMO 51: Niesel leicht (rate < 0.2 mm/h)
- WMO 53: Niesel mäßig (rate 0.2-1.0 mm/h)
- ~~WMO 55~~: Nicht verwendet (gleiche Icon wie 53)

**Gefrierender Nieselregen (temp < 0.5°C, rate < 1.0 mm/h):**
- WMO 56: Gefrierender Niesel leicht (rate < 0.5 mm/h)
- WMO 57: Gefrierender Niesel stark (rate ≥ 0.5 mm/h)

**Regen (rate ≥ 1.0 mm/h):**
- WMO 61: Regen leicht (rate 1.0-2.5 mm/h)
- WMO 63: Regen mäßig (rate < 7.5 mm/h)
- WMO 65: Regen stark (rate ≥ 7.5 mm/h)
- WMO 66: Gefrierender Regen leicht (temp < 0.5°C)
- WMO 67: Gefrierender Regen stark (temp < 0.5°C, rate ≥ 2.5 mm/h)
- WMO 68: Schneeregen leicht (temp 1-3°C, rate < 2.5 mm/h)
- WMO 69: Schneeregen stark (temp 1-3°C, rate ≥ 2.5 mm/h)

**Schnee:**
- WMO 71: Schnee leicht (temp < 1°C, rate < 2.5 mm/h)
- WMO 73: Schnee mäßig (temp < 1°C, rate < 7.5 mm/h)
- WMO 75: Schnee stark (temp < 1°C, rate ≥ 7.5 mm/h)
- WMO 77: Schneegriesel (temp < -2°C, rate < 0.2 mm/h)

### 🔄 Noch nicht implementiert

**Komplex (Zeitreihen-Analyse nötig):**
- WMO 80-86: Schauer (Varianz über 10-15 Min)
- Intermittierend vs. Kontinuierlich (60-Min-Historie)
- Gewitter-Proxy (5-Min solar-Einbruch)
- Lifting Fog (Tendenz-Erkennung)

### ⛔ Nicht machbar (fehlende Sensoren)

- WMO 30-35: Staubsturm (kein Partikelsensor)
- WMO 95-99: Gewitter (kein Blitzsensor)

---

## Schwellenwerte (config.php)

### Aktuelle Werte (Stand 2026-01-30)

```php
// Bewölkungs-Schwellen (optimiert)
define('THRESHOLD_CLEAR', 25);         // delta > 25 = klar (WMO 0)
define('THRESHOLD_MAINLY_CLEAR', 18);  // delta > 18 = überwiegend klar (WMO 1)
define('THRESHOLD_PARTLY_CLOUDY', 8);  // delta > 8 = teilweise bewölkt (WMO 2)
                                       // delta ≤ 8 = bedeckt (WMO 3)

// Niederschlags-Intensität
define('DRIZZLE_LIGHT_MAX', 0.2);      // < 0.2 mm/h = Niesel leicht (WMO 51)
define('DRIZZLE_MAX', 1.0);            // < 1.0 mm/h = Niesel, >= 1.0 = Regen
define('FREEZING_DRIZZLE_DENSE', 0.5); // >= 0.5 mm/h = gefr. Niesel stark (WMO 57)
define('RAIN_LIGHT_MAX', 2.5);         // < 2.5 mm/h = leicht
define('RAIN_MODERATE_MAX', 7.5);      // < 7.5 mm/h = mäßig, >= 7.5 = stark

// Nebel-Erkennung (strikt)
define('FOG_SPREAD_MAX', 1.0);         // spread < 1.0
define('FOG_HUMIDITY_MIN', 97);        // humidity > 97%
define('FOG_DELTA_MAX', 5);            // delta < 5
define('FOG_SPREAD_VETO', 3.0);        // spread > 3.0 → KEIN Nebel

// Feuchter Dunst (WMO 10)
define('MIST_SPREAD_MAX', 2.0);        // spread < 2.0
define('MIST_HUMIDITY_MIN', 90);       // humidity > 90%
define('MIST_HUMIDITY_MAX', 97);       // humidity < 97% (sonst Nebel)

// Flacher Bodennebel (WMO 11)
define('SHALLOW_FOG_WIND_MAX', 1.0);   // wind < 1 m/s

// Dunst (WMO 04)
define('HAZE_HUMIDITY_MAX', 60);       // humidity < 60%
define('HAZE_DELTA_MIN', 15);          // delta > 15

// Temperatur-Schwellen (restructured 2026-01-30)
define('SNOW_TEMP_MAX', 1.5);          // temp < 1.5°C = Schnee möglich
define('SNOW_CERTAIN_TEMP', -2.0);     // temp < -2°C = sicher Schnee (zu kalt für Flüssigkeit)
define('SLEET_TEMP_MIN', 1.5);         // temp >= 1.5°C = Schneeregen möglich
define('SLEET_TEMP_MAX', 3.0);         // temp < 3°C = Schneeregen
define('FREEZING_TEMP_MAX', 0.5);      // temp < 0.5°C = gefrierender Niesel
define('FREEZING_RAIN_TEMP', -1.0);    // temp > -1°C = gefrierender Regen möglich (bei hoher Rate)
define('SNOW_GRAINS_TEMP', -2.0);      // temp < -2°C = Schneegriesel möglich
```

---

---

## Entscheidungsbaum (Implementierung)

Die WMO-Ableitung in `wmo_derivation.php` ist ein **prioritätsbasierter Entscheidungsbaum**:

```
derive_wmo_code($pws, $cw)
│
│   Eingabe: temp, humidity, dewpoint, precip_rate, wind_speed (PWS)
│            sky_temp, is_raining (CloudWatcher)
│   Berechnet: delta = temp - sky_temp
│              spread = temp - dewpoint
│
├─► 1. NIEDERSCHLAG? (höchste Priorität)
│   │
│   │   $is_precipitating = ($precip_rate > 0) || $cw_is_raining
│   │
│   └─► JA → derive_precipitation_code()
│            │
│            │   $is_drizzle = $cw_is_raining && ($precip_rate < 0.2)
│            │
│            ├─► temp < 1.5°C? ──────────────────► SNOW ZONE
│            │       │
│            │       ├─► temp < -2°C? ───────────► CERTAINLY SNOW
│            │       │       │                     (too cold for liquid)
│            │       │       └─► derive_snow_code()
│            │       │
│            │       ├─► temp -2°C to 0°C? ──────► PRIMARILY SNOW
│            │       │       │
│            │       │       ├─► rate >= 2.5 && temp > -1°C?
│            │       │       │       └─► derive_freezing_rain_code()
│            │       │       │           (high rate near 0 = freezing rain)
│            │       │       │
│            │       │       └─► else → derive_snow_code()
│            │       │
│            │       ├─► temp 0°C to 0.5°C? ─────► FREEZING ZONE
│            │       │       │
│            │       │       ├─► rate >= 1.0? → derive_freezing_rain_code()
│            │       │       └─► rate < 1.0?  → derive_freezing_drizzle_code()
│            │       │
│            │       └─► temp 0.5°C to 1.5°C? ───► SNOW
│            │               └─► derive_snow_code()
│            │
│            ├─► temp 1.5-3.0°C? ────────────────► SLEET
│            │       │
│            │       ├─► rate < 2.5?  → 68 (sleet light)
│            │       └─► rate >= 2.5? → 69 (sleet heavy)
│            │
│            └─► temp >= 3.0°C? ─────────────────► RAIN/DRIZZLE
│                    │
│                    ├─► $is_drizzle?     → 51 (drizzle light)
│                    ├─► rate < 0.2?      → 51 (drizzle light)
│                    ├─► rate < 1.0?      → 53 (drizzle moderate)
│                    ├─► rate < 2.5?      → 61 (rain slight)
│                    ├─► rate < 7.5?      → 63 (rain moderate)
│                    └─► rate >= 7.5?     → 65 (rain heavy)
│
│   derive_snow_code(temp, rate):
│       ├─► rate < 0.2 && temp < -2°C? → 77 (snow grains)
│       ├─► rate < 2.5?                → 71 (snow slight)
│       ├─► rate < 7.5?                → 73 (snow moderate)
│       └─► rate >= 7.5?               → 75 (snow heavy)
│
│   derive_freezing_rain_code(rate):
│       ├─► rate >= 2.5? → 67 (freezing rain heavy)
│       └─► rate < 2.5?  → 66 (freezing rain light)
│
│   derive_freezing_drizzle_code(rate):
│       ├─► rate >= 0.5? → 57 (freezing drizzle dense)
│       └─► rate < 0.5?  → 56 (freezing drizzle light)
│
├─► 2. NEBEL/DUNST? (nur wenn KEIN Niederschlag)
│   │
│   │   derive_fog_mist_code()
│   │
│   ├─► VETO: spread > 3.0? → return null (kein Nebel möglich)
│   │
│   ├─► spread < 1.0 && humidity > 97% && delta < 5 && temp < 0°C?
│   │       └─► 48 (depositing rime fog)
│   │
│   ├─► temp <= dewpoint && wind < 1.0 && humidity > 95%?
│   │       └─► 11 (shallow fog)  ← NOW CHECKED BEFORE FOG!
│   │
│   ├─► spread < 1.0 && humidity > 97% && delta < 5?
│   │       └─► 45 (fog)
│   │
│   └─► spread < 2.0 && humidity 90-97%?
│           └─► 10 (mist)
│
├─► 3. DUNST? (nur wenn KEIN Niederschlag, KEIN Nebel)
│   │
│   └─► humidity < 60% && delta > 15?
│           └─► 4 (haze)
│
└─► 4. BEWÖLKUNG (Fallback, immer erreichbar wenn delta verfügbar)
        │
        ├─► delta > 25  → 0 (clear)
        ├─► delta > 18  → 1 (mainly clear)
        ├─► delta > 8   → 2 (partly cloudy)
        └─► delta <= 8  → 3 (overcast)
```

---

## Fehlerquellenanalyse

### ✅ Korrekt implementiert

**Temperatur-Grenzen (restructured 2026-01-30):**
| Bereich | Niederschlagsart | Logik |
|---------|------------------|-------|
| temp < -2°C | Snow (sicher) | Zu kalt für flüssigen Niederschlag |
| -2°C ≤ temp < 0°C | Snow (primär) | Freezing Rain nur bei hoher Rate UND temp > -1°C |
| 0°C ≤ temp < 0.5°C | Freezing Drizzle/Rain | Rate entscheidet: < 1.0 = Drizzle, ≥ 1.0 = Rain |
| 0.5°C ≤ temp < 1.5°C | Snow | Schnee noch möglich |
| 1.5°C ≤ temp < 3.0°C | Sleet (Schneeregen) | Mischform |
| temp ≥ 3.0°C | Rain/Drizzle | Flüssiger Niederschlag |

**Grenzwert-Test:**
- temp = -5.0°C → Snow ✓ (sicher Schnee, zu kalt für Flüssigkeit)
- temp = -1.5°C, rate = 0.5 → Snow ✓ (primär Schnee)
- temp = -0.5°C, rate = 3.0 → Freezing Rain ✓ (hohe Rate nahe 0°C)
- temp = 0.3°C, rate = 0.5 → Freezing Drizzle ✓
- temp = 0.3°C, rate = 2.0 → Freezing Rain ✓
- temp = 1.0°C → Snow ✓
- temp = 1.5°C → Sleet ✓ (nicht mehr Snow)
- temp = 2.99°C → Sleet ✓
- temp = 3.00°C → Rain ✓ (nicht mehr Sleet)

**Niederschlagsintensität (disjunkt, lückenlos):**
| Rate (mm/h) | Intensität |
|-------------|------------|
| < 0.2 | Drizzle/Niesel |
| 0.2 - 2.5 | Light/Leicht |
| 2.5 - 7.5 | Moderate/Mäßig |
| ≥ 7.5 | Heavy/Stark |

**Mist vs Fog Luftfeuchtigkeit (disjunkt):**
- Mist: 90% < humidity ≤ 97%
- Fog: humidity > 97%

---

### ⚠️ Potenzielle Probleme

#### ~~Problem 1: Shallow Fog (11) wird praktisch nie erreicht~~ ✅ BEHOBEN (2026-01-30)

**Ursprüngliches Problem:** Die Prüfung für Fog (45) kam VOR Shallow Fog (11).

**Lösung:** WMO 11 (Shallow Fog) wird jetzt VOR WMO 45 (Fog) geprüft. Shallow Fog ist der spezifischere Fall (windstill + temp ≤ dewpoint) und hat jetzt Vorrang.

---

#### Problem 2: Snow Grains (77) zu restriktiv

**Bedingung:** `rate < 0.2 && temp < -2.0°C`

**Problem:** Schneegriesel ist meteorologisch definiert als sehr kleine Eiskörner bei sehr kalter Temperatur. Die aktuelle Implementierung erfordert BEIDE:
- Sehr geringe Rate (< 0.2 mm/h)
- Sehr kalt (< -2°C)

**Konsequenz:** Bei temp = -5°C und rate = 0.5 mm/h → Snow slight (71), nicht Snow grains (77)

**Bewertung:** ⚠️ Möglicherweise korrekt - Schneegriesel ist per Definition sehr leicht. Bei höherer Intensität ist es normaler Schneefall.

---

#### Problem 3: Freezing Drizzle dense (57) Kriterium fragwürdig

**Code:**
```php
if ($precip_rate < DRIZZLE_MAX && $humidity > 95) {
    return 57;  // freezing drizzle, dense
} else {
    return 56;  // freezing drizzle, light
}
```

**Problem:** Warum bestimmt Luftfeuchtigkeit > 95% die Dichte des Nieselregens?

**Meteorologisch:** Dichte (dense) bezieht sich auf die Tropfendichte/Sichtweite, nicht auf Luftfeuchtigkeit.

**Bewertung:** ⚠️ Fragwürdige Logik. Könnte vereinfacht werden zu nur WMO 56 (light), da rate < 0.2 per Definition leicht ist.

---

#### Problem 4: Kein WMO 53/55 (Drizzle moderate/dense)

**Aktuell:** Bei temp ≥ 3°C wird unterschieden:
- rate < 0.2 → 51 (drizzle light)
- rate ≥ 0.2 → 61/63/65 (rain)

**Problem:** WMO 53 (drizzle moderate) und WMO 55 (drizzle dense) werden nie verwendet.

**Bewertung:** ⚠️ Feature-Lücke. Nieselregen wird nur als "light" erkannt, stärkerer Niesel wird als Regen klassifiziert.

**Mögliche Korrektur:**
```php
if ($is_drizzle || $precip_rate < 0.2) {
    return 51;  // light
} elseif ($precip_rate < 0.5) {
    return 53;  // moderate
} elseif ($precip_rate < 1.0) {
    return 55;  // dense
} else {
    return 61;  // rain slight
}
```

---

#### Problem 5: Delta kann null sein

**Situation:** Wenn CloudWatcher keine Daten liefert, ist `sky_temp = null` und damit `delta = null`.

**Konsequenz:**
- Fog-Erkennung schlägt fehl (delta < 5 nicht prüfbar)
- Haze-Erkennung schlägt fehl (delta > 15 nicht prüfbar)
- Cloud-Cover-Fallback schlägt fehl

**Ergebnis:** `wmo_code = null` wird zurückgegeben.

**Bewertung:** ⚠️ Bei CloudWatcher-Ausfall keine WMO-Ableitung möglich. Könnte Fallback auf reine PWS-Daten implementieren (z.B. nur Niederschlag/Temperatur).

---

#### Problem 6: Niederschlag bei Nebel nicht möglich

**Priorität:** Niederschlag wird VOR Nebel geprüft.

**Problem:** Nebel MIT leichtem Niederschlag (z.B. Sprühregen im Nebel) wird als Drizzle klassifiziert, nicht als Nebel.

**Bewertung:** ✅ Meteorologisch korrekt - WMO-Codes sind disjunkt, Niederschlag hat Vorrang.

---

### Zusammenfassung Fehlerquellen

| # | Problem | Schwere | Status |
|---|---------|---------|--------|
| 1 | ~~Shallow Fog selten erkannt~~ | - | ✅ Behoben (2026-01-30) - WMO 11 vor WMO 45 |
| 2 | Snow Grains restriktiv | Niedrig | ⚠️ Wahrscheinlich korrekt |
| 3 | Freezing Drizzle dense Logik | Niedrig | ⚠️ Überdenken |
| 4 | ~~Kein Drizzle moderate/dense~~ | - | ✅ Behoben (2026-01-30) |
| 5 | ~~Delta null → kein WMO~~ | - | ✅ Behoben (Wunderground-Fallback) |
| 6 | Niederschlag vor Nebel | - | ✅ Korrekt |
| 7 | ~~Snow/Freezing Priorität falsch~~ | - | ✅ Behoben (2026-01-30) - Snow hat Vorrang bei temp < -2°C |

---

## Referenzen

- WMO 4677 Present Weather Code Table
- CloudWatcher AAG Solo Dokumentation
- Ecowitt PWS Protokoll (Wunderground-kompatibel)

---

## Änderungshistorie

| Datum | Änderung |
|-------|----------|
| 2026-01-29 | Initiale Erstellung der Dokumentation |
| 2026-01-29 | Implementierung WMO 04, 10, 11, 48, 57, 67, 68, 69, 77; strikte Nebel-Schwellen; optimierte Delta-Schwellen |
| 2026-01-30 | Drizzle-Schwellenwerte: light < 0.2, moderate 0.2-1.0, rain >= 1.0 mm/h |
| 2026-01-30 | CloudWatcher-Fallback: `cloudwatcher_online` API-Feld, Wunderground-Fallback bei Ausfall |
| 2026-01-30 | **Snow/Freezing-Logik umstrukturiert**: Schnee hat Vorrang bei temp < -2°C; neue Temperaturzonen; SNOW_TEMP_MAX 1.0→1.5°C |
| 2026-01-30 | **WMO 11 vor WMO 45**: Shallow Fog wird jetzt vor Fog geprüft (spezifischerer Fall hat Vorrang) |
