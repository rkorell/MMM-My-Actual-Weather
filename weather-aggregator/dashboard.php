<?php
/**
 * Weather Dashboard
 *
 * Web dashboard showing current weather data and 24h temperature chart.
 *
 * Modified: 2026-01-28 - Initial creation
 */

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/config.php';

/**
 * Get current weather data
 */
function getCurrentData($pdo) {
    $sql = "SELECT * FROM weather_readings ORDER BY timestamp DESC LIMIT 1";
    $stmt = $pdo->query($sql);
    return $stmt->fetch();
}

/**
 * Get 24h history for chart
 */
function getHistoryData($pdo) {
    $sql = "SELECT timestamp, temp_c, temp1_c, temp2_c
            FROM weather_readings
            WHERE timestamp > NOW() - INTERVAL '24 hours'
            ORDER BY timestamp ASC";
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll();
}

/**
 * Get database stats
 */
function getDbStats($pdo) {
    $sql = "SELECT COUNT(*) as count FROM weather_readings";
    $stmt = $pdo->query($sql);
    $row = $stmt->fetch();
    return intval($row['count']);
}

/**
 * Format timestamp to German format
 */
function formatTimestamp($timestamp) {
    $dt = new DateTime($timestamp);
    return $dt->format('d.n.Y, H:i');
}

// Fetch data
$current = getCurrentData($pdo);
$history = getHistoryData($pdo);
$dbCount = getDbStats($pdo);

// Calculate data age
$dataAge = null;
$isOnline = false;
if ($current) {
    $lastUpdate = new DateTime($current['timestamp']);
    $now = new DateTime();
    $dataAge = $now->getTimestamp() - $lastUpdate->getTimestamp();
    $isOnline = $dataAge < 300; // Online if data < 5 min old
}

// Prepare chart data
$chartLabels = [];
$chartOutside = [];
$chartSensor1 = [];
$chartSensor2 = [];
foreach ($history as $row) {
    $dt = new DateTime($row['timestamp']);
    $chartLabels[] = $dt->format('H:i');
    $chartOutside[] = $row['temp_c'];
    $chartSensor1[] = $row['temp1_c'];
    $chartSensor2[] = $row['temp2_c'];
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Weather Dashboard</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --bg-primary: #1a1a2e;
            --bg-card: #16213e;
            --bg-card-hover: #1f2b47;
            --text-primary: #eee;
            --text-secondary: #aaa;
            --accent: #00bceb;
            --accent-dim: #007a99;
            --success: #4ade80;
            --warning: #fbbf24;
            --danger: #f87171;
            --chart-outside: #ff6384;
            --chart-sensor1: #36a2eb;
            --chart-sensor2: #ffce56;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 2px solid var(--accent);
        }

        h1 {
            font-size: 1.8rem;
            font-weight: 600;
        }

        .status {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
        }

        .status-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: var(--danger);
        }

        .status-dot.online {
            background: var(--success);
            box-shadow: 0 0 8px var(--success);
        }

        .card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .card {
            background: var(--bg-card);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            transition: background 0.2s;
        }

        .card:hover {
            background: var(--bg-card-hover);
        }

        .card-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 4px;
        }

        .card-value.temp {
            color: var(--chart-outside);
        }

        .card-value.sky {
            color: var(--accent);
        }

        .card-value.sensor1 {
            color: var(--chart-sensor1);
        }

        .card-value.sensor2 {
            color: var(--chart-sensor2);
        }

        .card-label {
            font-size: 0.85rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .section-title {
            font-size: 1rem;
            color: var(--text-secondary);
            margin-bottom: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .chart-container {
            background: var(--bg-card);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
        }

        .chart-container h2 {
            font-size: 1rem;
            color: var(--text-secondary);
            margin-bottom: 16px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .chart-wrapper {
            position: relative;
            height: 300px;
        }

        footer {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            font-size: 0.85rem;
            color: var(--text-secondary);
            padding-top: 16px;
            border-top: 1px solid var(--bg-card);
        }

        .condition-card {
            grid-column: span 2;
        }

        .bool-yes {
            color: var(--success);
        }

        .bool-no {
            color: var(--text-secondary);
        }

        @media (max-width: 600px) {
            body {
                padding: 12px;
            }

            h1 {
                font-size: 1.4rem;
            }

            .card-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 10px;
            }

            .card {
                padding: 14px 10px;
            }

            .card-value {
                font-size: 1.4rem;
            }

            .card-label {
                font-size: 0.75rem;
            }

            .condition-card {
                grid-column: span 3;
            }

            .chart-wrapper {
                height: 250px;
            }

            footer {
                flex-direction: column;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>Weather Dashboard</h1>
            <div class="status">
                <span class="status-dot <?= $isOnline ? 'online' : '' ?>"></span>
                <span><?= $isOnline ? 'Online' : 'Offline' ?></span>
            </div>
        </header>

        <?php if ($current): ?>

        <!-- Temperature Row -->
        <div class="section-title">Temperatur</div>
        <div class="card-grid">
            <div class="card">
                <div class="card-value temp"><?= number_format($current['temp_c'], 1) ?>°C</div>
                <div class="card-label">Außen</div>
            </div>
            <div class="card">
                <div class="card-value sky"><?= $current['sky_temp_c'] !== null ? number_format($current['sky_temp_c'], 1) . '°C' : '—' ?></div>
                <div class="card-label">Sky Temp</div>
            </div>
            <div class="card">
                <div class="card-value"><?= $current['delta_c'] !== null ? number_format($current['delta_c'], 1) . '°C' : '—' ?></div>
                <div class="card-label">Delta</div>
            </div>
        </div>

        <!-- Weather Row -->
        <div class="card-grid">
            <div class="card">
                <div class="card-value"><?= number_format($current['humidity'], 0) ?>%</div>
                <div class="card-label">Luftfeuchte</div>
            </div>
            <div class="card">
                <div class="card-value"><?= $current['wind_speed_ms'] !== null ? number_format($current['wind_speed_ms'] * 3.6, 1) . ' km/h' : '—' ?></div>
                <div class="card-label">Wind</div>
            </div>
            <div class="card">
                <div class="card-value"><?= $current['precip_today_mm'] !== null ? number_format($current['precip_today_mm'], 1) . ' mm' : '—' ?></div>
                <div class="card-label">Niederschlag</div>
            </div>
        </div>

        <!-- Condition -->
        <div class="card-grid">
            <div class="card condition-card">
                <div class="card-value"><?= ucfirst(str_replace('_', ' ', $current['condition'] ?? '—')) ?></div>
                <div class="card-label">WMO <?= $current['wmo_code'] ?? '—' ?></div>
            </div>
        </div>

        <!-- Sensors -->
        <div class="section-title">Sensoren</div>
        <div class="card-grid">
            <div class="card">
                <div class="card-value sensor1"><?= $current['temp1_c'] !== null ? number_format($current['temp1_c'], 1) . '°C' : '—' ?></div>
                <div class="card-label"><?= SENSOR1_NAME ?></div>
            </div>
            <div class="card">
                <div class="card-value sensor2"><?= $current['temp2_c'] !== null ? number_format($current['temp2_c'], 1) . '°C' : '—' ?></div>
                <div class="card-label"><?= SENSOR2_NAME ?></div>
            </div>
        </div>

        <!-- CloudWatcher -->
        <div class="section-title">CloudWatcher</div>
        <div class="card-grid">
            <div class="card">
                <div class="card-value"><?= $current['rain_freq'] !== null ? $current['rain_freq'] : '—' ?></div>
                <div class="card-label">Rain Freq</div>
            </div>
            <div class="card">
                <div class="card-value"><?= $current['mpsas'] !== null ? number_format($current['mpsas'], 2) : '—' ?></div>
                <div class="card-label">MPSAS</div>
            </div>
            <div class="card">
                <div class="card-value <?= ($current['cw_is_raining'] === 't' || $current['cw_is_raining'] === true) ? 'bool-yes' : 'bool-no' ?>">
                    <?= ($current['cw_is_raining'] === 't' || $current['cw_is_raining'] === true) ? 'Ja' : 'Nein' ?>
                </div>
                <div class="card-label">Regen?</div>
            </div>
            <div class="card">
                <div class="card-value <?= ($current['cw_is_daylight'] === 't' || $current['cw_is_daylight'] === true) ? 'bool-yes' : 'bool-no' ?>">
                    <?= ($current['cw_is_daylight'] === 't' || $current['cw_is_daylight'] === true) ? 'Tag' : 'Nacht' ?>
                </div>
                <div class="card-label">Tageslicht</div>
            </div>
        </div>

        <!-- Chart: Outside Temperature -->
        <div class="chart-container">
            <h2>Außentemperatur (24h)</h2>
            <div class="chart-wrapper">
                <canvas id="outsideChart"></canvas>
            </div>
        </div>

        <!-- Chart: Indoor Sensors -->
        <div class="chart-container">
            <h2>Innentemperaturen (24h)</h2>
            <div class="chart-wrapper">
                <canvas id="indoorChart"></canvas>
            </div>
        </div>

        <?php else: ?>
        <div class="card">
            <div class="card-value">Keine Daten</div>
            <div class="card-label">Warte auf erste Messung...</div>
        </div>
        <?php endif; ?>

        <footer>
            <span>Stand: <?= $current ? formatTimestamp($current['timestamp']) : '—' ?></span>
            <span><?= number_format($dbCount) ?> Einträge</span>
            <span>Refresh: 60s</span>
        </footer>
    </div>

    <script>
        // Common chart options
        const commonOptions = {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                intersect: false,
                mode: 'index'
            },
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        color: '#aaa',
                        usePointStyle: true,
                        padding: 20
                    }
                },
                tooltip: {
                    backgroundColor: '#16213e',
                    titleColor: '#eee',
                    bodyColor: '#eee',
                    borderColor: '#00bceb',
                    borderWidth: 1,
                    callbacks: {
                        label: function(context) {
                            return context.dataset.label + ': ' + (context.parsed.y !== null ? context.parsed.y.toFixed(1) + '°C' : '—');
                        }
                    }
                }
            },
            scales: {
                x: {
                    ticks: {
                        color: '#aaa',
                        maxTicksLimit: 12
                    },
                    grid: {
                        color: 'rgba(255,255,255,0.1)'
                    }
                },
                y: {
                    ticks: {
                        color: '#aaa',
                        callback: function(value) {
                            return value + '°C';
                        }
                    },
                    grid: {
                        color: 'rgba(255,255,255,0.1)'
                    }
                }
            }
        };

        // Outside temperature chart
        const outsideCtx = document.getElementById('outsideChart');
        if (outsideCtx) {
            new Chart(outsideCtx, {
                type: 'line',
                data: {
                    labels: <?= json_encode($chartLabels) ?>,
                    datasets: [{
                        label: 'Außen',
                        data: <?= json_encode($chartOutside) ?>,
                        borderColor: '#ff6384',
                        backgroundColor: 'rgba(255, 99, 132, 0.1)',
                        borderWidth: 2,
                        tension: 0.3,
                        fill: true,
                        pointRadius: 0,
                        pointHitRadius: 10
                    }]
                },
                options: commonOptions
            });
        }

        // Indoor sensors chart
        const indoorCtx = document.getElementById('indoorChart');
        if (indoorCtx) {
            new Chart(indoorCtx, {
                type: 'line',
                data: {
                    labels: <?= json_encode($chartLabels) ?>,
                    datasets: [
                        {
                            label: '<?= SENSOR1_NAME ?>',
                            data: <?= json_encode($chartSensor1) ?>,
                            borderColor: '#36a2eb',
                            backgroundColor: 'rgba(54, 162, 235, 0.1)',
                            borderWidth: 2,
                            tension: 0.3,
                            fill: false,
                            pointRadius: 0,
                            pointHitRadius: 10
                        },
                        {
                            label: '<?= SENSOR2_NAME ?>',
                            data: <?= json_encode($chartSensor2) ?>,
                            borderColor: '#ffce56',
                            backgroundColor: 'rgba(255, 206, 86, 0.1)',
                            borderWidth: 2,
                            tension: 0.3,
                            fill: false,
                            pointRadius: 0,
                            pointHitRadius: 10
                        }
                    ]
                },
                options: commonOptions
            });
        }

        // Auto-refresh every 60 seconds
        setTimeout(function() {
            location.reload();
        }, 60000);
    </script>
</body>
</html>
