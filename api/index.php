<?php
require_once 'SolarEngine.php';

// --- Default Values ---
$cityName = $cityName ?? "Mumbai";
$numMonths = $numMonths ?? 3;
$requiredEnergy = $requiredEnergy ?? 100;
$useTracking = $useTracking ?? true;
$obsHeight = $obsHeight ?? 10;
$obsDist = $obsDist ?? 20;

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

        // 6. Elevation Data
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
    $width = 1200;
    $height = 400;
    $max = max($values) ?: 1;
    $points = "";
    $step = $width / (count($values) - 1);
    
    foreach ($values as $i => $v) {
        $x = $i * $step;
        $y = $height - ($v / $max * ($height - 60)) - 30;
        $points .= "$x,$y ";
    }
    
    $svg = "<svg viewBox='0 0 $width $height' class='chart-svg' preserveAspectRatio='none'>";
    $svg .= "<defs>
        <linearGradient id='line-grad' x1='0' y1='0' x2='1' y2='0'><stop offset='0%' stop-color='#38bdf8'/><stop offset='50%' stop-color='#fbbf24'/><stop offset='100%' stop-color='#0ea5e9'/></linearGradient>
        <linearGradient id='area-grad' x1='0' y1='0' x2='0' y2='1'><stop offset='0%' stop-color='#fbbf24' stop-opacity='0.15'/><stop offset='100%' stop-color='#fbbf24' stop-opacity='0'/></linearGradient>
        <filter id='shadow'><feDropShadow dx='0' dy='4' stdDeviation='4' flood-color='rgba(0,0,0,0.3)'/></filter>
    </defs>";
    
    // Grid Lines
    for ($i = 0; $i <= 5; $i++) {
        $y = $height - ($i * ($height / 5));
        $svg .= "<line x1='0' y1='$y' x2='$width' y2='$y' stroke='rgba(255,255,255,0.05)' stroke-width='1' />";
    }
    
    // Shading Highlight
    foreach ($shadingIndices as $idx) {
        $x = $idx * $step;
        $svg .= "<rect x='" . ($x - $step/2) . "' y='0' width='$step' height='$height' fill='rgba(239, 68, 68, 0.08)' />";
    }
    
    $svg .= "<polyline points='$points' fill='none' stroke='url(#line-grad)' stroke-width='4' stroke-linecap='round' stroke-linejoin='round' filter='url(#shadow)' />";
    $svg .= "<polygon points='0,$height $points $width,$height' fill='url(#area-grad)' />";
    $svg .= "</svg>";
    return $svg;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Solar Optimizer | Visual Excellence Edition</title>
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/styles.css">
</head>
<body class="luxury-theme">
    <!-- Immersive Background -->
    <div class="fixed-background">
        <div class="star-field"></div>
        <div class="nebula-1"></div>
        <div class="nebula-2"></div>
    </div>

    <div class="layout-wrapper">
        <!-- Sidebar Navigation & Controls -->
        <aside class="sidebar-modern">
            <div class="brand-section">
                <div class="logo-circle">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/><circle cx="12" cy="12" r="4"/></svg>
                </div>
                <div class="brand-info">
                    <span class="brand-name">SolarEngine</span>
                    <span class="brand-tag">v2.0 Performance</span>
                </div>
            </div>

            <nav class="nav-links">
                <div class="nav-item active">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></nav>
                    <span>Dashboard</span>
                </div>
            </nav>

            <div class="sidebar-card">
                <h3>Parameters</h3>
                <form method="POST" action="/">
                    <div class="input-group-modern">
                        <label>Target City</label>
                        <input type="text" name="city" value="<?= htmlspecialchars($cityName) ?>" class="input-modern" placeholder="Mumbai, IN">
                    </div>

                    <div class="input-split">
                        <div class="input-group-modern">
                            <label>Timeline</label>
                            <select name="months" class="select-modern">
                                <?php foreach([1,3,6,12] as $m): ?>
                                    <option value="<?= $m ?>" <?= $numMonths == $m ? 'selected' : '' ?>><?= $m ?> mo</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="input-group-modern">
                            <label>Energy (kWh)</label>
                            <input type="number" name="energy" value="<?= $requiredEnergy ?>" class="input-modern">
                        </div>
                    </div>

                    <div class="input-group-modern">
                        <label>Tracking Logic</label>
                        <div class="custom-toggle-group">
                            <input type="radio" name="tracking" value="false" id="static" <?= !$useTracking ? 'checked' : '' ?>>
                            <label for="static">Static</label>
                            <input type="radio" name="tracking" value="true" id="dual" <?= $useTracking ? 'checked' : '' ?>>
                            <label for="dual">Dual-Axis</label>
                        </div>
                    </div>

                    <div class="divider-text">Local Shading</div>
                    <div class="input-split">
                        <div class="input-group-modern">
                            <label>Height (m)</label>
                            <input type="number" step="0.1" name="obs_height" value="<?= $obsHeight ?>" class="input-modern">
                        </div>
                        <div class="input-group-modern">
                            <label>Dist (m)</label>
                            <input type="number" step="0.1" name="obs_dist" value="<?= $obsDist ?>" class="input-modern">
                        </div>
                    </div>

                    <button type="submit" name="analyze" class="btn-primary-modern">Run Analysis</button>
                    
                    <?php if ($results): ?>
                        <a href="?download_kml=1&lat=<?= $results['lat'] ?>&lon=<?= $results['lon'] ?>&elev=<?= $results['elevation'] ?>&city=<?= urlencode($cityName) ?>" class="btn-secondary-modern">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                            Export KML
                        </a>
                    <?php endif; ?>
                </form>
            </div>
        </aside>

        <!-- Main Content Area -->
        <main class="content-modern">
            <header class="content-header">
                <div>
                    <h1>Optimization Workspace</h1>
                    <?php if ($results): ?>
                        <p class="location-badge"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg> <?= $results['display_name'] ?></p>
                    <?php endif; ?>
                </div>
            </header>

            <?php if ($error): ?>
                <div class="error-toast"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <section class="dashboard-overview">
                <!-- Status Row -->
                <?php if ($results): ?>
                    <div class="status-row">
                        <div class="status-card <?= $results['suitable'] ? 'suitable' : 'poor' ?>">
                            <div class="status-icon">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                            </div>
                            <div class="status-body">
                                <h3>Location Suitability</h3>
                                <p><?= $results['suitable'] ? 'Peak Performance Mode: Data indicates high solar potential.' : 'Sub-Optimal: Low radiation levels detected for this area.' ?></p>
                            </div>
                        </div>
                        <?php if ($results['is_blocked']): ?>
                            <div class="status-card danger">
                                <div class="status-icon">
                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                                </div>
                                <div class="status-body">
                                    <h3>Obstacle Interference</h3>
                                    <p>The sun is currently obstructed by a <?= $obsHeight ?>m obstacle nearby.</p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Stats Grid -->
                <div class="modern-stats-grid">
                    <div class="mega-stat">
                        <span class="mega-val"><?= $results ? $results['num_panels'] : '--' ?></span>
                        <span class="mega-label">Required Panels</span>
                        <div class="mega-sub">Total Units</div>
                    </div>
                    <div class="mega-stat accent">
                        <span class="mega-val"><?= $results ? number_format($results['optimal_tilt'], 1) : '--' ?>°</span>
                        <span class="mega-label">Optimum Tilt</span>
                        <div class="mega-sub">Astronomical Center</div>
                    </div>
                    <div class="mega-stat solar">
                        <span class="mega-val"><?= $results ? number_format($results['energy_per_day'], 2) : '--' ?></span>
                        <span class="mega-label">Yield (kWh)</span>
                        <div class="mega-sub">Average Daily Output</div>
                    </div>
                    <div class="mega-stat earth">
                        <span class="mega-val"><?= $results ? number_format($results['elevation'], 0) : '--' ?>m</span>
                        <span class="mega-label">Elevation</span>
                        <div class="mega-sub">Above Sea Level</div>
                    </div>
                </div>

                <!-- Visualization Core -->
                <div class="visual-core-grid">
                    <div class="visual-card-large">
                        <div class="vcard-header">
                            <h3>Sun Trajectory Simulation</h3>
                            <span class="badge-v">Dual-Axis Tracking</span>
                        </div>
                        <div class="vis-container-premium">
                            <div class="scene-premium">
                                <div class="ground-premium">
                                    <div class="scanner-line"></div>
                                    <div class="compass-marker n">N</div>
                                    <div class="compass-marker s">S</div>
                                    <div class="compass-marker e">E</div>
                                    <div class="compass-marker w">W</div>
                                </div>
                                <?php if ($results): 
                                    $sun = $results['sun'];
                                    $azRad = deg2rad($sun['azimuth']);
                                    $elRad = deg2rad($sun['elevation']);
                                    $r = 180;
                                    $sx = $r * sin($azRad) * cos($elRad);
                                    $sz = $r * cos($azRad) * cos($elRad);
                                    $sy = $r * sin($elRad);
                                    
                                    $pX = $useTracking ? -$sun['elevation'] : -$results['optimal_tilt'];
                                    $pY = $useTracking ? $sun['azimuth'] : 0;
                                ?>
                                    <div class="sun-glow-field" style="transform: translate3d(<?= 140+$sx ?>px, <?= 140+$sz ?>px, <?= $sy ?>px);"></div>
                                    <div class="sun-premium" style="transform: translate3d(<?= 145+$sx ?>px, <?= 145+$sz ?>px, <?= $sy ?>px);"></div>
                                    <div class="tracking-panel" style="transform: rotateX(<?= $pX ?>deg) rotateY(<?= $pY ?>deg);">
                                        <div class="wafer-grid"></div>
                                    </div>
                                <?php else: ?>
                                    <div class="tracking-panel"></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="visual-card-wide">
                        <div class="vcard-header">
                            <h3>Radiation Timeline</h3>
                            <span class="badge-v">Spectral Analysis</span>
                        </div>
                        <div class="chart-wrapper-premium">
                            <?= $results ? generateSvgChart($results['values'], $results['labels'], $results['shading']['shaded']) : '<div class="empty-vis">Select a location to initialize data</div>' ?>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>
</body>
</html>
