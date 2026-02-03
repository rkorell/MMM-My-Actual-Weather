# CloudWatcher Heater-PWM Analyse

**Erstellt:** 03.02.2026
**Autor:** Dr. Ralf Korell
**Anlass:** Untersuchung des Regensensor-Verhaltens bei heater_pwm=100% und is_wet=false

---

## Problemstellung

Bei der Beobachtung der Sensordaten fiel auf:
- `heater_pwm = 100%` (Heizung auf Maximum)
- `rain_freq = 2944` (über WET_THRESHOLD von 2100)
- `is_wet = false`

**Frage:** Ist das ein Logikfehler? Trocknet der Heater den Nebel weg?

---

## CloudWatcher Regensensor - Funktionsweise

### Hardwareaufbau

Der CloudWatcher verwendet einen **kapazitiven Regensensor** mit integrierter Heizung:

> "The CloudWatcher uses a variable capacitor to determine the existence of rain. In addition, the capacitor incorporates an internal resistance for heating the element, drying it, which allows a constant reliable reading."
> — [Lunatico Astronomia](https://lunaticoastro.com/aag-cloud-watcher/)

### Drei Zustände (aus RTS2 Quellcode)

Laut [RTS2 CloudWatcher Driver](https://github.com/RTS2/rts2/blob/master/src/sensord/aag.cpp):

| Zustand | rain_freq | Heater-Verhalten |
|---------|-----------|------------------|
| **IS_DRY** | > THRESHOLD_DRY (~2100) | Aus oder minimal |
| **IS_WET** | zwischen Schwellen | Aktiv, proportional zur Feuchtigkeit |
| **IS_RAIN** | < THRESHOLD_WET (~1700) | Maximum (100%) |

### Heater-Aktivierungslogik

```
HEAT_NOT      = 0%   (deaktiviert)
HEAT_REGULAR  = proportional zur rain_freq
HEAT_MAX      = 100% (Maximum bei erkannter Feuchtigkeit)
```

Der Heater aktiviert sich, wenn `rain_freq` unter die Trocken-Schwelle fällt. Er versucht dann, den Sensor zu trocknen und `rain_freq` wieder zu erhöhen.

---

## Analyse des beobachteten Verhaltens

### Zeitreihe (03.02.2026, 14:41-15:10)

| Zeit | rain_freq | heater_pwm | is_wet | WMO | Interpretation |
|------|-----------|------------|--------|-----|----------------|
| 14:41 | 2684 | 100% | false | 45 fog | Heater kämpft gegen Kondensation |
| 14:50 | 2521 | 100% | false | 45 fog | rain_freq sinkt trotz Heater |
| 15:00 | 2240 | 100% | false | 45 fog | Feuchtigkeit gewinnt |
| 15:04 | 2048 | 100% | **true** | **68 sleet** | Schwelle unterschritten! |
| 15:10 | 1920 | 100% | true | 68 sleet | Niederschlag korrekt erkannt |

### Erkenntnisse

1. **Der Heater läuft präventiv:** Bei 99% Luftfeuchtigkeit und 1.5°C bildet sich Kondensation. Der Heater läuft auf 100%, um den Sensor trocken zu halten.

2. **rain_freq sinkt kontinuierlich:** Trotz 100% Heater-Leistung sinkt rain_freq von 2684 auf 1920. Die Feuchtigkeit (Niederschlag) überwältigt den Heater.

3. **Korrekte Zustandsänderung:** Bei rain_freq < 2100 wechselt `is_wet` auf true, und der WMO-Code ändert sich von Nebel (45) zu Schneeregen (68).

---

## Die AND-Logik: Warum sie korrekt ist

### Implementierung

```php
$heater_indicates_moisture = ($heater_pwm > 30) && $cw_is_wet;
$is_precipitating = ($precip_rate > 0) || $cw_is_raining || $heater_indicates_moisture;
```

### Warum nicht nur heater_pwm > 30?

**Problem:** Der Heater kann auch bei Nebel/Tau auf 100% laufen, ohne dass es regnet.

| Szenario | heater_pwm | is_wet | Ergebnis | Korrekt? |
|----------|------------|--------|----------|----------|
| Nebel, Heater hält trocken | 100% | false | Kein Niederschlag | ✓ |
| Leichter Regen, Heater überfordert | 100% | true | Niederschlag | ✓ |
| Starker Regen | 100% | true | Niederschlag | ✓ |
| Trocken, kein Heater | 0% | false | Kein Niederschlag | ✓ |

### Schlüsselerkenntnis

> **heater_pwm allein ist kein zuverlässiger Niederschlagsindikator.**
>
> Erst die Kombination `heater_pwm > 30% UND is_wet = true` zeigt an, dass die Feuchtigkeit den Heater überwältigt - ein sicheres Zeichen für echten Niederschlag.

---

## Validierung gegen Datenbank

SQL-Analyse der letzten 24 Stunden:

```sql
SELECT
  count(*) as anzahl,
  CASE
    WHEN rain_freq < 2100 AND heater_pwm > 30 AND wmo_code IN (10,11,45,48)
      THEN 'FEHLER: Wet+Heater aber Nebel-WMO'
    WHEN rain_freq < 2100 AND heater_pwm > 30 AND wmo_code NOT IN (10,11,45,48)
      THEN 'OK: Wet+Heater = Niederschlag'
    WHEN rain_freq >= 2100 AND heater_pwm > 30
      THEN 'OK: Heater aber trocken = kein Niederschlag'
    ELSE 'Andere'
  END as status
FROM weather_readings
WHERE timestamp > NOW() - INTERVAL '24 hours'
GROUP BY status;
```

**Ergebnis:**

| Status | Anzahl |
|--------|--------|
| Heater + trocken = kein Niederschlag | 66 |
| Heater + nass = Niederschlag erkannt | 6 |
| **FEHLER** | **0** |

---

## Meteorologische Grundlagen

### Nebel vs. Niederschlag

Laut [WMO International Cloud Atlas](https://cloudatlas.wmo.int/en/fog.html):

- **Nebel** = Suspension von Wassertröpfchen (Sichtweite < 1 km)
- **Niederschlag** = Fallende Partikel (Regen, Schnee, etc.)

> "Drizzle falls from a layer of Stratus, usually low, sometimes touching the ground (fog)."
> — [WMO Cloud Atlas: Drizzle](https://cloudatlas.wmo.int/en/drizzle.html)

Nebel und Nieselregen können **gleichzeitig** auftreten. In WMO 4677 hat Niederschlag jedoch Priorität.

### Temperaturzonen für Niederschlagsart

| Temperatur | Niederschlagsart | WMO-Codes |
|------------|------------------|-----------|
| < -2°C | Schnee (sicher) | 71, 73, 75, 77 |
| -2°C bis 0°C | Schnee (primär), ggf. gefrierender Regen | 71-75, 66-67 |
| 0°C bis 0.5°C | Gefrierender Niesel/Regen | 56-57, 66-67 |
| 0.5°C bis 1.5°C | Schnee | 71-75 |
| 1.5°C bis 3°C | Schneeregen (Sleet) | 68, 69 |
| > 3°C | Regen/Niesel | 51-65 |

Bei der Beobachtung (1.6°C, WMO 68 sleet_light) ist die Klassifikation meteorologisch korrekt.

---

## False-Positive-Risiko: Kondensation vs. Regen

### Bekannte Probleme

Aus [Cloudy Nights Forum](https://www.cloudynights.com/topic/731073-need-ideas-for-rain-sensor/):

> "The RG-9 rain sensor does not distinguish condensation from rain, so we do not recommend it in high humidity environments."

Der kapazitive Sensor kann Tau/Kondensation nicht von Regen unterscheiden. Die Heizung verhindert jedoch meist, dass Kondensation zu `is_wet = true` führt.

### Warum unsere Lösung funktioniert

1. **Heater-Kapazität:** Bei reiner Kondensation/Tau reicht die Heizleistung meist aus, um `rain_freq` über der Schwelle zu halten.

2. **Echter Niederschlag:** Wenn es tatsächlich regnet/schneit, überwältigt die Feuchtigkeit den Heater, und `rain_freq` sinkt unter die Schwelle.

3. **Konservative AND-Logik:** Wir triggern nur bei BEIDEN Bedingungen (heater aktiv UND Sensor nass).

---

## Schwellenwerte

### Aktuelle Konfiguration

```php
// CloudWatcher Schwellen (config.py auf CloudWatcher-Pi)
RAIN_THRESHOLD = 1700   // < 1700 = is_raining
WET_THRESHOLD = 2100    // < 2100 = is_wet

// Heater-Schwelle (wmo_derivation.php)
HEATER_PWM_MOISTURE_THRESHOLD = 30  // PWM > 30% = aktive Feuchtigkeitsbekämpfung
```

### Kalibrierungsmethode (RTS2)

Laut [RTS2 Dokumentation](https://azug.minpet.unibas.ch/wikiobsvermes/index.php/AAG_cloud_sensor):

```
THRESHOLD_DRY = dry_reading - 10
THRESHOLD_WET = THRESHOLD_DRY - 60
```

Bei typischem dry_reading von ~2100-2200 ergibt das:
- THRESHOLD_DRY ≈ 2100
- THRESHOLD_WET ≈ 2040

Unsere Werte (2100/1700) sind konservativer, was false positives reduziert.

---

## Fazit

### Keine Design-/Logikfehler

Die Beobachtung `heater_pwm=100%` mit `is_wet=false` ist **kein Fehler**, sondern zeigt:

1. Hohe Luftfeuchtigkeit verursacht Kondensation auf dem Sensor
2. Der Heater arbeitet auf Maximum, um den Sensor trocken zu halten
3. Solange `rain_freq > 2100`, ist der Heater erfolgreich → kein Niederschlag
4. Erst wenn `rain_freq < 2100` (Feuchtigkeit > Heater-Kapazität) → Niederschlag erkannt

### Implementierung ist korrekt

```
heater_pwm=100% allein     → Kann Nebel/Tau sein → KEIN Niederschlag
heater_pwm=100% + is_wet   → Heater überfordert  → NIEDERSCHLAG
```

Die AND-Logik verhindert false positives bei Nebel und erkennt trotzdem leichten Niederschlag, den die PWS-Wippe verpasst.

---

## Externe Quellen

1. [Lunatico AAG CloudWatcher](https://lunaticoastro.com/aag-cloud-watcher/) - Hersteller-Dokumentation
2. [RTS2 CloudWatcher Driver (GitHub)](https://github.com/RTS2/rts2/blob/master/src/sensord/aag.cpp) - Referenz-Implementierung
3. [WMO CODE TABLE 4677](https://www.nodc.noaa.gov/archive/arc0021/0002199/1.1/data/0-data/HTML/WMO-CODE/WMO4677.HTM) - Present Weather Codes
4. [WMO International Cloud Atlas](https://cloudatlas.wmo.int/) - Meteorologische Definitionen
5. [WMO Cloud Atlas: Fog](https://cloudatlas.wmo.int/en/fog.html) - Nebel-Definition
6. [WMO Cloud Atlas: Drizzle](https://cloudatlas.wmo.int/en/drizzle.html) - Nieselregen und Nebel
7. [KNMI PWS Technical Report (PDF)](https://cdn.knmi.nl/knmi/pdf/bibliotheek/knmipubTR/TR259.pdf) - Automatische Wetterstationen
8. [Cloudy Nights: Rain Sensor Discussion](https://www.cloudynights.com/topic/731073-need-ideas-for-rain-sensor/) - Community-Erfahrungen

---

## Änderungshistorie

| Datum | Änderung |
|-------|----------|
| 2026-02-03 | Initiale Erstellung nach Tiefenanalyse |
