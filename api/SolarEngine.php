<?php
/**
 * SolarEngine.php
 * Ported from SolarEngine.js / original MATLAB solar_final.m
 * Handles all astronomical and energy calculations.
 */

class SolarEngine {
    public static function deg2rad($deg) {
        return ($deg * M_PI) / 180;
    }

    public static function rad2deg($rad) {
        return ($rad * 180) / M_PI;
    }

    /**
     * Calculate Solar Declination Angle
     * @param DateTime $dateObj
     * @returns float Declination in degrees
     */
    public static function calculateDeclination($dateObj) {
        $dayOfYear = (int)$dateObj->format('z') + 1; // 1-366
        
        // MATLAB: declination = 23.45 * sind(360 * (N - 81) / 365);
        return 23.45 * sin(self::deg2rad((360 * ($dayOfYear - 81)) / 365));
    }

    /**
     * Calculate Sun position at a specific time
     * @param float $latitude 
     * @param float $longitude 
     * @param float $declination 
     * @param float $hour (6 to 18)
     * @returns array { elevation, azimuth } in degrees
     */
    public static function calculateSunPosition($latitude, $longitude, $declination, $hour) {
        $hourAngle = ($hour - 12) * 15; // 15 degrees per hour
        
        $latRad = self::deg2rad($latitude);
        $decRad = self::deg2rad($declination);
        $hrRad = self::deg2rad($hourAngle);

        // Solar Elevation Angle (Altitude)
        // sin(el) = sin(lat)sin(dec) + cos(lat)cos(dec)cos(hr)
        $sinElevation = sin($latRad) * sin($decRad) + 
                         cos($latRad) * cos($decRad) * cos($hrRad);
        
        $elevation = self::rad2deg(asin($sinElevation));
        if ($elevation < 0) $elevation = 0;

        // Solar Azimuth Angle
        // cos(az) = (sin(dec) - sin(el)sin(lat)) / (cos(el)cos(lat))
        $elRad = self::deg2rad($elevation);
        $cosAzimuth = (sin($decRad) - sin($elRad) * sin($latRad)) / 
                       (cos($elRad) * cos($latRad));
        
        // Clip for precision
        $cosAzimuth = max(-1, min(1, $cosAzimuth));
        $azAngle = self::rad2deg(acos($cosAzimuth));

        if ($hourAngle < 0) {
            $azimuth = $azAngle; // Morning (East of South)
        } else {
            $azimuth = 360 - $azAngle; // Afternoon (West of South)
        }

        return [ 'elevation' => $elevation, 'azimuth' => $azimuth ];
    }

    /**
     * Estimate Optimal Tilt Angle
     * @param float $latitude 
     * @param float $declination 
     * @returns float angle in degrees
     */
    public static function calculateOptimalTilt($latitude, $declination) {
        return abs($latitude - $declination);
    }

    /**
     * Estimate Energy Output
     * @param float[] $solarValues kWh/m²/day
     * @param float $panelEfficiency (e.g. 0.2)
     * @param float $panelArea (m²)
     * @param float $efficiencyFactor (e.g. 1.25 for tracking)
     * @returns float kWh/day
     */
    public static function estimateEnergy($solarValues, $panelEfficiency = 0.2, $panelArea = 1.6, $efficiencyFactor = 1.0) {
        $sumRadiation = array_sum($solarValues);
        if (count($solarValues) > 0) {
            $avgRadiation = $sumRadiation / count($solarValues);
        } else {
            $avgRadiation = 0;
        }
        // Original JS logic: $sumRadiation * panelEfficiency * panelArea * efficiencyFactor
        // But the JS sumRadiation was for the whole period. 
        // We usually want daily average energy or total for period.
        // Let's stick to the average daily energy for the "Est. kWh/Day" stat.
        return $avgRadiation * $panelEfficiency * $panelArea * $efficiencyFactor;
    }

    /**
     * Calculate Number of Panels
     * @param float $requiredEnergy kWh
     * @param float $energyPerPanel kWh/day
     * @returns int
     */
    public static function calculateNumPanels($requiredEnergy, $energyPerPanel) {
        if ($energyPerPanel <= 0) return 0;
        return (int)ceil($requiredEnergy / $energyPerPanel);
    }
}
?>
