# WMO-Code Ableitung aus Sensordaten

**Erstellt:** 2026-01-29
**Zweck:** Dokumentation der Möglichkeiten zur lokalen WMO-Wettercode-Ableitung aus PWS- und CloudWatcher-Sensordaten.

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

**Hinweis:** Die exakten Schwellwerte müssen für den Standort kalibriert werden. Empfohlene Startwerte: 25/18/8/5.

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

**Wichtig WMO 68 (Schneeregen):** Aktuell NICHT implementiert! Tritt auf bei Temperaturen knapp über dem Gefrierpunkt.

---

### Codes 70-79: Schnee (Snow)

| WMO | Bezeichnung | Rate (mm/h) | Temperatur |
|-----|-------------|-------------|------------|
| 71 | Schnee leicht | < 1.0 | < 1.0°C |
| 73 | Schnee mäßig | 1.0 - 3.0 | < 1.0°C |
| 75 | Schnee stark | > 3.0 | < 1.0°C |
| 77 | Schneegriesel | < 0.2 | < -2.0°C |

**Profi-Trick "Winter-Schnee":** CloudWatcher erkennt Schnee über den Heizsensor (braucht Energie zum Schmelzen). PWS-Wippe versagt oft bei Schnee!

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

- WMO 00-03: Bewölkung nach Delta (25/20/15)
- WMO 45: Nebel (spread < 2.5, humidity > 95%, delta < 10)
- WMO 51, 53, 55: Nieselregen
- WMO 56: Gefrierender Niesel leicht
- WMO 61, 63, 65: Regen nach Intensität
- WMO 66: Gefrierender Regen
- WMO 71, 73, 75: Schnee (temp < 2°C)

### ❌ Noch nicht implementiert

**Einfach (keine Zeitreihen nötig):**
- WMO 04: Dunst (humidity < 60%, delta hoch)
- WMO 10: Feuchter Dunst (spread < 2, humidity 90-98%)
- WMO 11: Flacher Bodennebel (temp < dewpoint, wind < 1)
- WMO 48: Reifnebel (Nebel + temp < 0)
- WMO 57: Gefrierender Niesel stark
- WMO 67: Gefrierender Regen stark
- WMO 68/69: Schneeregen (temp 1-3°C)
- WMO 77: Schneegriesel (temp < -2°C, rate minimal)
- Striktere Nebel-Schwellen (spread < 1.0, humidity > 97%)
- Feinere Delta-Schwellen (25/18/8/5)

**Komplex (Zeitreihen-Analyse nötig):**
- WMO 80-86: Schauer (Varianz über 10-15 Min)
- Intermittierend vs. Kontinuierlich (60-Min-Historie)
- Gewitter-Proxy (5-Min solar-Einbruch)
- Lifting Fog (Tendenz-Erkennung)

### ⛔ Nicht machbar (fehlende Sensoren)

- WMO 30-35: Staubsturm (kein Partikelsensor)
- WMO 95-99: Gewitter (kein Blitzsensor)

---

## Schwellwerte (config.php)

### Aktuelle Werte

```php
define('THRESHOLD_CLEAR', 25);         // delta > 25 = klar
define('THRESHOLD_MAINLY_CLEAR', 20);  // delta > 20 = überwiegend klar
define('THRESHOLD_PARTLY_CLOUDY', 15); // delta > 15 = teilweise bewölkt

define('RAIN_LIGHT_MAX', 2.5);         // < 2.5 mm/h = leicht
define('RAIN_MODERATE_MAX', 7.5);      // < 7.5 mm/h = mäßig

define('FOG_SPREAD_MAX', 2.5);         // spread < 2.5
define('FOG_HUMIDITY_MIN', 95);        // humidity > 95%
define('FOG_DELTA_MAX', 10);           // delta < 10

define('SNOW_TEMP_MAX', 2.0);          // temp < 2 = Schnee
define('FREEZING_TEMP_MAX', 0.0);      // temp < 0 = gefrierend
```

### Empfohlene Optimierungen

```php
// Feinere Bewölkungs-Schwellen
define('THRESHOLD_CLEAR', 25);
define('THRESHOLD_MAINLY_CLEAR', 18);  // war 20
define('THRESHOLD_PARTLY_CLOUDY', 8);  // war 15
define('THRESHOLD_OVERCAST', 5);       // NEU

// Striktere Nebel-Erkennung
define('FOG_SPREAD_MAX', 1.0);         // war 2.5
define('FOG_HUMIDITY_MIN', 97);        // war 95
define('FOG_DELTA_MAX', 5);            // war 10

// Neue Schwellen
define('MIST_SPREAD_MAX', 2.0);        // NEU für WMO 10
define('MIST_HUMIDITY_MIN', 90);       // NEU
define('SLEET_TEMP_MIN', 1.0);         // NEU für Schneeregen
define('SLEET_TEMP_MAX', 3.0);         // NEU
define('SNOW_GRAINS_TEMP', -2.0);      // NEU für WMO 77
```

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
