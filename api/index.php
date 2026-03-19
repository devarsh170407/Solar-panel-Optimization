<?php
require_once 'SolarEngine.php';

// --- Default Values ---
$cityName = "Mumbai";
$numMonths = 3;
$requiredEnergy = 100;
$useTracking = true;

$error = null;
$results = null;

// --- Handle Analysis ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['analyze'])) {
    $cityName = $_POST['city'] ?? $_GET['city'] ?? $cityName;
    $numMonths = (int)($_POST['months'] ?? $_GET['months'] ?? $numMonths);
    $requiredEnergy = (float)($_POST['energy'] ?? $_GET['energy'] ?? $requiredEnergy);
    $useTracking = ($_POST['tracking'] ?? $_GET['tracking'] ?? ($useTracking ? 'true' : 'false')) === 'true';

    try {
        // 1. Geocode
        $geoUrl = "https://nominatim.openstreetmap.org/search?q=" . urlencode($cityName) . "&format=json&limit=1";
        $opts = ['http' => ['header' => "User-Agent: SolarOptimizer/1.0\r\n"]];
        $context = stream_context_create($opts);
        $geoJson = file_get_contents($geoUrl, false, $context);
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
        
        $nasaJson = file_get_contents($nasaUrl, false, $context);
        $nasaData = json_decode($nasaJson, true);
        
        if (!isset($nasaData['properties']['parameter']['ALLSKY_SFC_SW_DWN'])) {
            throw new Exception("Could not fetch solar data");
        }
        
        $solarRadiation = $nasaData['properties']['parameter']['ALLSKY_SFC_SW_DWN'];
        $labels = array_keys($solarRadiation);
        $values = array_values($solarRadiation);
        // Filter out bad data
        $values = array_filter($values, function($v) { return $v > 0; });

        // 3. Process Data
        $now = new DateTime();
        $declination = SolarEngine::calculateDeclination($now);
        $efficiencyFactor = $useTracking ? 1.25 : 1.0;
        $energyPerPanel = SolarEngine::estimateEnergy($values, 0.2, 1.6, $efficiencyFactor);
        $numPanels = SolarEngine::calculateNumPanels($requiredEnergy, $energyPerPanel);
        $optimalTilt = SolarEngine::calculateOptimalTilt($lat, $declination);

        // 4. Sun Position for Visualization (Current Hour)
        $currentHour = (float)$now->format('G') + ((float)$now->format('i') / 60);
        $sunPos = SolarEngine::calculateSunPosition($lat, $lon, $declination, $currentHour);

        $results = [
            'display_name' => $displayName,
            'lat' => $lat,
            'lon' => $lon,
            'num_panels' => $numPanels,
            'optimal_tilt' => $optimalTilt,
            'energy_per_day' => $energyPerPanel,
            'labels' => $labels,
            'values' => array_values($values),
            'sun' => $sunPos
        ];

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// --- Helper: Generate SVG Chart ---
function generateSvgChart($values, $labels) {
    if (empty($values)) return "";
    $width = 1000;
    $height = 300;
    $max = max($values) ?: 1;
    $points = "";
    $step = $width / (count($values) - 1);
    
    foreach ($values as $i => $v) {
        $x = $i * $step;
        $y = $height - ($v / $max * ($height - 20)) - 10;
        $points .= "$x,$y ";
    }
    
    $svg = "<svg viewBox='0 0 $width $height' class='chart-svg' preserveAspectRatio='none'>";
    // Grid lines
    for ($i = 0; $i <= 4; $i++) {
        $y = $height - ($i * ($height / 4));
        $svg .= "<line x1='0' y1='$y' x2='$width' y2='$y' stroke='rgba(255,255,255,0.05)' stroke-width='1' />";
    }
    // Gradient
    $svg .= "<defs><linearGradient id='grad' x1='0%' y1='0%' x2='0%' y2='100%'><stop offset='0%' style='stop-color:#fbbf24;stop-opacity:0.2' /><stop offset='100%' style='stop-color:#fbbf24;stop-opacity:0' /></linearGradient></defs>";
    $fillPoints = "0,$height " . $points . " $width,$height";
    $svg .= "<polygon points='$fillPoints' fill='url(#grad)' />";
    // Line
    $svg .= "<polyline points='$points' fill='none' stroke='#fbbf24' stroke-width='3' stroke-linecap='round' stroke-linejoin='round' />";
    $svg .= "</svg>";
    return $svg;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Solar Optimizer | PHP Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
    <style>
        /* PHP-Specific styles for SVG and CSS 3D */
        .chart-svg { width: 100%; height: 100%; display: block; overflow: visible; }
        .vis-container-3d {
            perspective: 1000px;
            width: 100%;
            height: 500px;
            position: relative;
            background: #000;
            border-radius: 24px;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .scene-3d {
            width: 300px;
            height: 300px;
            transform-style: preserve-3d;
            transform: rotateX(60deg) rotateZ(45deg);
            position: relative;
        }
        .ground-3d {
            width: 100%;
            height: 100%;
            background: rgba(30, 41, 59, 0.5);
            border: 2px solid #475569;
            position: absolute;
        }
        .panel-3d {
            width: 100px;
            height: 70px;
            background: #2563eb;
            border: 2px solid #3b82f6;
            position: absolute;
            left: 100px;
            top: 115px;
            transform-origin: center;
            box-shadow: 0 0 20px rgba(37, 99, 235, 0.2);
        }
        .sun-3d {
            width: 20px;
            height: 20px;
            background: #facc15;
            border-radius: 50%;
            position: absolute;
            box-shadow: 0 0 40px #facc15, 0 0 80px rgba(250, 204, 21, 0.5);
            transform-style: preserve-3d;
        }
    </style>
</head>
<body>
    <div class="app-container">
        <header>
            <h1>Solar Analysis <span style="color: var(--text-main); opacity: 0.5;">&</span> Tracking</h1>
            <p>Optimize your solar energy harvest with precise server-side astronomical calculations.</p>
        </header>

        <main class="dashboard-grid">
            <!-- Sidebar / Inputs -->
            <aside class="glass-card input-section">
                <h2>
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.1a2 2 0 0 1-1-1.72v-.51a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
                    Configuration
                </h2>
                
                <?php if ($error): ?>
                    <div class="stat-label" style="color: var(--error); margin-bottom: 1rem;"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="POST" action="/">
                    <div class="form-group">
                        <label for="city-input">Target Location</label>
                        <input type="text" name="city" id="city-input" class="input-box" placeholder="e.g. Mumbai, India" value="<?= htmlspecialchars($cityName) ?>">
                    </div>

                    <div class="form-group">
                        <label for="months-input">Historical Data Range</label>
                        <select name="months" id="months-input" class="input-box">
                            <?php foreach ([1, 3, 6, 12] as $m): ?>
                                <option value="<?= $m ?>" <?= $numMonths == $m ? 'selected' : '' ?>><?= $m ?> Month<?= $m > 1 ? 's' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="energy-input">Required Energy Output (kWh)</label>
                        <input type="number" name="energy" id="energy-input" class="input-box" value="<?= htmlspecialchars($requiredEnergy) ?>">
                    </div>

                    <div class="form-group">
                        <label for="tracking-switch">Tracking System</label>
                        <select name="tracking" id="tracking-switch" class="input-box">
                            <option value="false" <?= !$useTracking ? 'selected' : '' ?>>Static Panels</option>
                            <option value="true" <?= $useTracking ? 'selected' : '' ?>>Dual-Axis Tracking (+25%)</option>
                        </select>
                    </div>

                    <button type="submit" name="analyze" class="btn-primary">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m13 2-2 10h8L7 22l2-10H1z"/></svg>
                        Perform Analysis
                    </button>
                </form>
            </aside>

            <!-- Main Content / Results -->
            <section class="visualization-section">
                <!-- Top Stats -->
                <div id="stats-container" class="stats-grid <?= $results ? '' : 'hidden' ?>">
                    <div class="stat-card">
                        <div class="stat-val"><?= $results ? $results['num_panels'] : '--' ?></div>
                        <div class="stat-label">Required Panels</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-val"><?= $results ? number_format($results['optimal_tilt'], 1) : '--' ?>°</div>
                        <div class="stat-label">Optimal Tilt</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-val"><?= $results ? number_format($results['energy_per_day'], 2) : '--' ?></div>
                        <div class="stat-label">Est. kWh/Day</div>
                    </div>
                </div>

                <!-- 3D Visualization (CSS Based) -->
                <div class="glass-card">
                    <h2>
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/></svg>
                        Sun Position & Tracking
                    </h2>
                    <div class="vis-container-3d">
                        <div class="scene-3d">
                            <div class="ground-3d"></div>
                            
                            <?php if ($results): 
                                $sun = $results['sun'];
                                // Map azimuth/elevation to 3D coords
                                // Azimuth 0 is South (in our model let's say Z is S-N, X is E-W)
                                $azRad = deg2rad($sun['azimuth']);
                                $elRad = deg2rad($sun['elevation']);
                                $r = 150; // visual radius
                                $x = $r * sin($azRad) * cos($elRad);
                                $z = $r * cos($azRad) * cos($elRad);
                                $y = $r * sin($elRad);
                                
                                // Panel Rotation
                                $tilt = $results['optimal_tilt'];
                                $pX = $useTracking ? -$sun['elevation'] : -$tilt;
                                $pY = $useTracking ? $sun['azimuth'] : 0;
                            ?>
                                <div class="sun-3d" style="transform: translate3d(<?= 140+$x ?>px, <?= 140+$z ?>px, <?= $y ?>px);"></div>
                                <div class="panel-3d" style="transform: rotateX(<?= $pX ?>deg) rotateY(<?= $pY ?>deg);"></div>
                            <?php else: ?>
                                <div class="panel-3d"></div>
                            <?php endif; ?>
                            
                            <!-- Compass Labels -->
                            <div style="position: absolute; top: -20px; left: 140px; color: #10b981; transform: rotateX(-60deg);">N</div>
                            <div style="position: absolute; bottom: -20px; left: 140px; color: #ef4444; transform: rotateX(-60deg);">S</div>
                            <div style="position: absolute; right: -20px; top: 140px; color: #3b82f6; transform: rotateX(-60deg);">E</div>
                            <div style="position: absolute; left: -20px; top: 140px; color: #f59e0b; transform: rotateX(-60deg);">W</div>
                        </div>
                    </div>
                </div>

                <!-- 2D Charts (SVG Based) -->
                <div class="glass-card">
                    <h2>
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/></svg>
                        Solar Radiation Timeline
                    </h2>
                    <div class="chart-container">
                        <?php if ($results): ?>
                            <?= generateSvgChart($results['values'], $results['labels']) ?>
                        <?php else: ?>
                            <div class="stat-label" style="text-align: center; margin-top: 100px;">Perform analysis to see historical data</div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        </main>
    </div>
</body>
</html>
