<?php
/**
 * RF coverage prediction — Phase 11.
 *
 * predict_rssi($sector, $rx_lat, $rx_lng) returns the predicted RSSI
 * in dBm at a given receiver location, given the sector's
 * frequency_mhz, tx_power_dbm, antenna_gain_dbi (default 16 dBi),
 * azimuth_deg, beamwidth_deg, plus the parent tower's lat/lng/height.
 *
 * Model: Hata-Okumura-style with a free-space-loss floor for short
 * distances + bearing-aware antenna pattern roll-off (cosine-squared
 * outside the main lobe, 25 dB front-to-back). Good enough to
 * differentiate "strong / fair / no signal" zones for sales and
 * planning; not surveying-grade.
 *
 * Wraps `rssi_grid_for_sector` for the map overlay's GeoJSON output.
 */

declare(strict_types=1);

require_once __DIR__ . '/wireless.php';
require_once __DIR__ . '/sites.php';

const COVERAGE_DEFAULT_GAIN_DBI       = 16.0;
const COVERAGE_DEFAULT_RX_GAIN_DBI    = 16.0;  // typical CPE
const COVERAGE_DEFAULT_RX_HEIGHT_M    = 6.0;
const COVERAGE_FRONT_TO_BACK_DB       = 25.0;

function predict_rssi(array $sector, array $tower, float $rx_lat, float $rx_lng): float {
    $tx_lat = (float)$tower['lat'];
    $tx_lng = (float)$tower['lng'];
    $tx_h   = max(3.0, (float)($tower['height_m'] ?? 15.0));
    $rx_h   = COVERAGE_DEFAULT_RX_HEIGHT_M;

    $d_km = haversine_km($tx_lat, $tx_lng, $rx_lat, $rx_lng);
    if ($d_km < 0.005) $d_km = 0.005; // ~5m floor so log doesn't blow up

    $tx_pow  = $sector['tx_power_dbm'] !== null ? (float)$sector['tx_power_dbm'] : 22.0;
    $tx_gain = COVERAGE_DEFAULT_GAIN_DBI;
    $rx_gain = COVERAGE_DEFAULT_RX_GAIN_DBI;
    $f_mhz   = $sector['frequency_mhz'] !== null ? (float)$sector['frequency_mhz'] : 5500.0;

    // Antenna pattern: drop off cosine-squared outside the main lobe.
    $az_deg  = $sector['azimuth_deg']   !== null ? (float)$sector['azimuth_deg']   : 0.0;
    $bw_deg  = $sector['beamwidth_deg'] !== null ? (float)$sector['beamwidth_deg'] : 360.0;
    $bearing = _bearing_deg($tx_lat, $tx_lng, $rx_lat, $rx_lng);
    $off     = abs(_normalise_angle($bearing - $az_deg));
    $half    = max(15.0, $bw_deg / 2.0);
    if ($bw_deg >= 360 || $off <= $half) {
        $pattern_loss_db = 0.0;
    } else {
        // 6 dB at edge of main lobe, ramping to front-to-back beyond 90°.
        $beyond = min(90.0, $off - $half);
        $pattern_loss_db = 6.0 + ($beyond / 90.0) * (COVERAGE_FRONT_TO_BACK_DB - 6.0);
    }

    // Path loss: pick the larger of free-space and Hata.
    $fsl = 20 * log10($d_km) + 20 * log10($f_mhz) + 32.45;

    // Hata small/medium-city, valid for 1500-2000 MHz; we extend to
    // 5 GHz with the COST-231 correction factor 0..3 dB.
    $a_hr = (1.1 * log10($f_mhz) - 0.7) * $rx_h - (1.56 * log10($f_mhz) - 0.8);
    $hata = 69.55 + 26.16 * log10($f_mhz) - 13.82 * log10($tx_h) - $a_hr
          + (44.9 - 6.55 * log10($tx_h)) * log10($d_km);
    $cost231 = $f_mhz > 1500 ? 3.0 : 0.0;
    $path = max($fsl, $hata + $cost231);

    return $tx_pow + $tx_gain + $rx_gain - $path - $pattern_loss_db;
}

/**
 * Compute a 64×64 grid of predicted RSSI cells around a sector's
 * tower, returned as a GeoJSON FeatureCollection of square Polygons
 * suitable for Leaflet.
 *
 * The grid extent is 2× the sector's coverage_radius_m (or 5 km
 * default). Cells with RSSI < -100 dBm are dropped to keep the
 * payload small.
 */
function rssi_grid_for_sector(array $sector, array $tower, int $cells = 48): array {
    $extent_m = max(500, ($tower['coverage_radius_m'] ?? 5000)) * 2;
    $lat0     = (float)$tower['lat'];
    $lng0     = (float)$tower['lng'];
    $deg_per_m_lat = 1.0 / 111_111.0;
    $deg_per_m_lng = 1.0 / (111_111.0 * cos(deg2rad($lat0)));
    $half_lat     = ($extent_m * $deg_per_m_lat) / 2.0;
    $half_lng     = ($extent_m * $deg_per_m_lng) / 2.0;
    $step_lat     = (2 * $half_lat) / $cells;
    $step_lng     = (2 * $half_lng) / $cells;

    $features = [];
    for ($i = 0; $i < $cells; $i++) {
        for ($j = 0; $j < $cells; $j++) {
            $lat = $lat0 - $half_lat + ($i + 0.5) * $step_lat;
            $lng = $lng0 - $half_lng + ($j + 0.5) * $step_lng;
            $rssi = predict_rssi($sector, $tower, $lat, $lng);
            if ($rssi < -100) continue;
            // Polygon rectangle.
            $features[] = [
                'type' => 'Feature',
                'properties' => ['rssi' => round($rssi, 1)],
                'geometry' => [
                    'type' => 'Polygon',
                    'coordinates' => [[
                        [$lng - $step_lng / 2, $lat - $step_lat / 2],
                        [$lng + $step_lng / 2, $lat - $step_lat / 2],
                        [$lng + $step_lng / 2, $lat + $step_lat / 2],
                        [$lng - $step_lng / 2, $lat + $step_lat / 2],
                        [$lng - $step_lng / 2, $lat - $step_lat / 2],
                    ]],
                ],
            ];
        }
    }
    return ['type' => 'FeatureCollection', 'features' => $features];
}

function _bearing_deg(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $phi1 = deg2rad($lat1); $phi2 = deg2rad($lat2);
    $dl   = deg2rad($lng2 - $lng1);
    $y = sin($dl) * cos($phi2);
    $x = cos($phi1) * sin($phi2) - sin($phi1) * cos($phi2) * cos($dl);
    return fmod(rad2deg(atan2($y, $x)) + 360.0, 360.0);
}

function _normalise_angle(float $deg): float {
    $deg = fmod($deg + 180.0, 360.0);
    if ($deg < 0) $deg += 360.0;
    return $deg - 180.0;
}
