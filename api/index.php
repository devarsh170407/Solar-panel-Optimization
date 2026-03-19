<?php
require_once 'SolarEngine.php';

// --- Default Values ---
$cityName = "Mumbai";
$numMonths = 3;
$requiredEnergy = 100;
$useTracking = true;
$obsHeight = 10;
$obsDist = 20;

$error = null;
$results = null;

// --- Handle KML Download ---
if (isset($_GET['download_kml'])) {
    $lat = (float)($_GET['lat'] ?? 0);
    $lon = (float)($_GET['lon'] ?? 0);
    $elev = (float)($_GET['elev'] ?? 0);
    $city = $_GET['city'] ?? "Target Location";
    $kml = SolarEngine::generateKml($lat, $lon, $elev, $city);
    header('Content-Type: application/vnd.google-earth.kml+xml');
    header('Content-Disposition: attachment; filename="SolarSite.kml"');
    echo $kml;
    exit;
}

// --- Handle Analysis ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['analyze'])) {
    $cityName = $_POST['city'] ?? $_GET['city'] ?? $cityName;
    $numMonths = (int)($_POST['months'] ?? $_GET['months'] ?? $numMonths);
    $requiredEnergy = (float)($_POST['energy'] ?? $_GET['energy'] ?? $requiredEnergy);
    $useTracking = ($_POST['tracking'] ?? $_GET['tracking'] ?? ($useTracking ? 'true' : 'false')) === 'true';
    $obsHeight = (float)($_POST['obs_height'] ?? $obsHeight);
    $obsDist = (float)($_POST['obs_dist'] ?? $obsDist);

    try {
        // 1. Geocode
        $geoUrl = "https://nominatim.openstreetmap.org/search?q=" . urlencode($cityName) . "&format=json&limit=1";
        $opts = ['http' => ['header' => "User-Agent: SolarOptimizer/1.0\r\n"]];
        $context = stream_context_create($opts);
        $geoJson = @file_get_contents($geoUrl, false, $context);
        $geoData = json_decode($geoJson, true);

        if (empty($geoData)) throw new Exception("City not found");
        $lat = (float)$geoData[0]['lat'];
        $lon = (float)$geoData[0]['lon'];
        $displayName = $geoData[0]['display_name'];

        // 2. Fetch NASA Data
        $endDate = new DateTime('-3 days');
        $startDate = clone $endDate;
        $startDate->modify("-$numMonths months");

        $fmt = function($d) { return $d->format('Ymd'); };
        $nasaUrl = "https://power.larc.nasa.gov/api/temporal/daily/point?parameters=ALLSKY_SFC_SW_DWN&community=RE&longitude=$lon&latitude=$lat&start=" . $fmt($startDate) . "&end=" . $fmt($endDate) . "&format=JSON";
        
        $nasaJson = @file_get_contents($nasaUrl, false, $context);
        $nasaData = json_decode($nasaJson, true);
        
        if (!isset($nasaData['properties']['parameter']['ALLSKY_SFC_SW_DWN'])) {
            throw new Exception("Could not fetch solar data");
        }
        
        $solarRadiation = $nasaData['properties']['parameter']['ALLSKY_SFC_SW_DWN'];
        $labels = array_keys($solarRadiation);
        $values = array_values($solarRadiation);
        $values = array_filter($values, function($v) { return $v > 0; });
        $values = array_values($values);

        // 3. Port MATLAB Logic
        $now = new DateTime();
        $declination = SolarEngine::calculateDeclination($now);
        $efficiencyFactor = $useTracking ? 1.25 : 1.0;
        $energyPerPanel = SolarEngine::estimateEnergy($values, 0.2, 1.6, $efficiencyFactor);
        $numPanels = SolarEngine::calculateNumPanels($requiredEnergy, $energyPerPanel);
        $optimalTilt = SolarEngine::calculateOptimalTilt($lat, $declination);

        // 4. Shading Analysis
        $shadingResults = SolarEngine::performShadingAnalysis($values);
        $isSuitable = SolarEngine::checkSuitability($values);

        // 5. Sun Position for Visualization
        $currentHour = (float)$now->format('G') + ((float)$now->format('i') / 60);
        $sunPos = SolarEngine::calculateSunPosition($lat, $lon, $declination, $currentHour);
        $isBlocked = SolarEngine::isBlockedByObstacle($sunPos['elevation'], $obsHeight, $obsDist);

        // 6. Elevation Data (Simulated for speed, or could fetch from opentopodata if user prefers)
        $elevUrl = "https://api.opentopodata.org/v1/srtm90m?locations=$lat,$lon";
        $elevJson = @file_get_contents($elevUrl, false, $context);
        $elevData = json_decode($elevJson, true);
        $elevation = $elevData['results'][0]['elevation'] ?? 0;

        $results = [
            'display_name' => $displayName,
            'lat' => $lat,
            'lon' => $lon,
            'num_panels' => $numPanels,
            'optimal_tilt' => $optimalTilt,
            'energy_per_day' => $energyPerPanel,
            'labels' => $labels,
            'values' => $values,
            'sun' => $sunPos,
            'elevation' => $elevation,
            'shading' => $shadingResults,
            'suitable' => $isSuitable,
            'is_blocked' => $isBlocked
        ];

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// --- Helper: Generate SVG Chart ---
function generateSvgChart($values, $labels, $shadingIndices) {
    if (empty($values)) return "";
    $width = 1000;
    $height = 300;
    $max = max($values) ?: 1;
    $points = "";
    $step = $width / (count($values) - 1);
    
    foreach ($values as $i => $v) {
        $x = $i * $step;
        $y = $height - ($v / $max * ($height - 40)) - 20;
        $points .= "$x,$y ";
    }
    
    $svg = "<svg viewBox='0 0 $width $height' class='chart-svg' preserveAspectRatio='none'>";
    $svg .= "<defs><linearGradient id='grad' x1='0' y1='0' x2='0' y2='1'><stop offset='0%' stop-color='#fbbf24' stop-opacity='0.2'/><stop offset='100%' stop-color='#fbbf24' stop-opacity='0'/></linearGradient></defs>";
    
    // Shading areas
    foreach ($shadingIndices as $idx) {
        $x = $idx * $step;
        $svg .= "<rect x='" . ($x - $step/2) . "' y='0' width='$step' height='$height' fill='rgba(239, 68, 68, 0.1)' />";
    }
    
    // Line & Gradient
    $svg .= "<polyline points='$points' fill='none' stroke='#fbbf24' stroke-width='3' stroke-linecap='round' stroke-linejoin='round' />";
    $svg .= "<polygon points='0,$height $points $width,$height' fill='url(#grad)' />";
    $svg .= "</svg>";
    return $svg;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Solar Optimizer | Advanced Premium Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
    <style>
        :root {
            --glass: rgba(255, 255, 255, 0.03);
            --glass-border: rgba(255, 255, 255, 0.08);
            --premium-accent: #0ea5e9;
        }
        body { font-family: 'Outfit', sans-serif; background: #020617; }
        .vis-container-3d { border: 1px solid var(--glass-border); box-shadow: 0 0 50px rgba(0,0,0,0.5); }
        .alert-card {
            padding: 1.5rem;
            border-radius: 16px;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            border: 1px solid transparent;
        }
        .alert-success { background: rgba(16, 185, 129, 0.1); border-color: rgba(16, 185, 129, 0.2); color: #10b981; }
        .alert-warning { background: rgba(245, 158, 11, 0.1); border-color: rgba(245, 158, 11, 0.2); color: #f59e0b; }
        .alert-error { background: rgba(239, 68, 68, 0.1); border-color: rgba(239, 68, 68, 0.2); color: #ef4444; }
        
        .kml-btn {
            background: rgba(255,255,255,0.05);
            border: 1px solid var(--glass-border);
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .kml-btn:hover { background: var(--premium-accent); border-color: var(--premium-accent); }
    </style>
</head>
<body>
    <div class="app-container">
        <header>
            <div class="premium-badge">Advanced MATLAB Port</div>
            <h1>Solar Optimizer <span style="font-weight: 300; opacity: 0.6;">v2.0</span></h1>
            <p>Unlocking precision solar data with dual-axis tracking simulation and machine-learning heuristics.</p>
        </header>

        <?php if ($results): ?>
            <div class="results-top-bar">
                <?php if ($results['suitable']): ?>
                    <div class="alert-card alert-success">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        <span><strong>Suitable Location:</strong> Solar radiation levels are excellent for implementation.</span>
                    </div>
                <?php else: ?>
                    <div class="alert-card alert-warning">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        <span><strong>Warning:</strong> Location may have sub-optimal radiation for peak efficiency.</span>
                    </div>
                <?php endif; ?>
                
                <?php if ($results['is_blocked']): ?>
                    <div class="alert-card alert-error">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg>
                        <span><strong>Current Shading Alert:</strong> The sun is currently blocked by local obstacles (<?= $obsHeight ?>m at <?= $obsDist ?>m).</span>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <main class="dashboard-grid">
            <aside class="glass-card input-section">
                <h2>Control Panel</h2>
                <form method="POST" action="/">
                    <div class="form-group">
                        <label>Target Site</label>
                        <input type="text" name="city" class="input-box" value="<?= htmlspecialchars($cityName) ?>" placeholder="City Name">
                    </div>

                    <div class="input-row">
                        <div class="form-group">
                            <label>Data Range</label>
                            <select name="months" class="input-box">
                                <?php foreach([1,3,6,12] as $m): ?>
                                    <option value="<?= $m ?>" <?= $numMonths == $m ? 'selected' : '' ?>><?= $m ?> Months</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Goal (kWh)</label>
                            <input type="number" name="energy" class="input-box" value="<?= $requiredEnergy ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Tracking System</label>
                        <select name="tracking" class="input-box">
                            <option value="false" <?= !$useTracking ? 'selected' : '' ?>>Static Panel Array</option>
                            <option value="true" <?= $useTracking ? 'selected' : '' ?>>Dual-Axis Smart Tracking</option>
                        </select>
                    </div>

                    <div class="form-divider">Advanced Shading Params</div>
                    <div class="input-row">
                        <div class="form-group">
                            <label>Obstacle Height (m)</label>
                            <input type="number" step="0.1" name="obs_height" class="input-box" value="<?= $obsHeight ?>">
                        </div>
                        <div class="form-group">
                            <label>Distance (m)</label>
                            <input type="number" step="0.1" name="obs_dist" class="input-box" value="<?= $obsDist ?>">
                        </div>
                    </div>

                    <button type="submit" name="analyze" class="btn-primary">Calculate Optimization</button>
                    
                    <?php if ($results): ?>
                        <div style="margin-top: 1rem;">
                            <a href="?download_kml=1&lat=<?= $results['lat'] ?>&lon=<?= $results['lon'] ?>&elev=<?= $results['elevation'] ?>&city=<?= urlencode($cityName) ?>" class="kml-btn">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                Export KML (Google Earth)
                            </a>
                        </div>
                    <?php endif; ?>
                </form>
            </aside>

            <section class="visualization-section">
                <div class="stats-grid <?= $results ? '' : 'hidden' ?>">
                    <div class="stat-card">
                        <div class="stat-icon sun"></div>
                        <div class="stat-val"><?= $results ? $results['num_panels'] : '--' ?></div>
                        <div class="stat-label">Panel Count</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon tilt"></div>
                        <div class="stat-val"><?= $results ? number_format($results['optimal_tilt'], 1) : '--' ?>°</div>
                        <div class="stat-label">Optimal Tilt</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon energy"></div>
                        <div class="stat-val"><?= $results ? number_format($results['energy_per_day'], 2) : '--' ?></div>
                        <div class="stat-label">Avg kWh/Day</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon mountain"></div>
                        <div class="stat-val"><?= $results ? number_format($results['elevation'], 0) : '--' ?>m</div>
                        <div class="stat-label">Elevation</div>
                    </div>
                </div>

                <div class="glass-card">
                    <h2>Sun Trajectory & Dynamic Tracking</h2>
                    <div class="vis-container-3d">
                        <div class="scene-3d">
                            <div class="ground-3d">
                                <div class="grid-overlay"></div>
                                <div class="compass-label n">N</div>
                                <div class="compass-label s">S</div>
                                <div class="compass-label e">E</div>
                                <div class="compass-label w">W</div>
                            </div>
                            <?php if ($results): 
                                $sun = $results['sun'];
                                $azRad = deg2rad($sun['azimuth']);
                                $elRad = deg2rad($sun['elevation']);
                                $r = 160;
                                $sx = $r * sin($azRad) * cos($elRad);
                                $sz = $r * cos($azRad) * cos($elRad);
                                $sy = $r * sin($elRad);
                                
                                $pX = $useTracking ? -$sun['elevation'] : -$results['optimal_tilt'];
                                $pY = $useTracking ? $sun['azimuth'] : 0;
                            ?>
                                <div class="sun-orbit"></div>
                                <div class="sun-3d" style="transform: translate3d(<?= 135+$sx ?>px, <?= 135+$sz ?>px, <?= $sy ?>px);"></div>
                                <div class="panel-3d" style="transform: rotateX(<?= $pX ?>deg) rotateY(<?= $pY ?>deg);">
                                    <div class="panel-cells"></div>
                                </div>
                            <?php else: ?>
                                <div class="panel-3d"></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="glass-card">
                    <h2>Radiation Analysis & Shading Detection</h2>
                    <div class="chart-container">
                        <?= $results ? generateSvgChart($results['values'], $results['labels'], $results['shading']['shaded']) : '<div class="empty-state">Analyze to see radiation timeline</div>' ?>
                    </div>
                    <?php if ($results && !empty($results['shading']['shaded'])): ?>
                        <div class="shading-legend">
                            <span class="dot warning"></span> Shading detected on <?= count($results['shading']['shaded']) ?> days in the selected period.
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>
</body>
</html>
