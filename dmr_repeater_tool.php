#!/usr/bin/env php
<?php
/**
 * DMR Repeater Tool
 *
 * Parses repeater callsigns from rpt.vkdmr.com, looks up each callsign
 * on the ACMA RRL to find TX/RX frequencies, site coordinates, and
 * generates a KML file for use in Google Earth / Google Maps.
 */

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------
define('VKDMR_URL', 'http://rpt.vkdmr.com/ipsc/_status.html');
define('ACMA_SEARCH_URL', 'https://web.acma.gov.au/rrl/register_search.search_dispatcher');
define('ACMA_SITE_URL', 'https://web.acma.gov.au/rrl/site_search.site_lookup');
define('ACMA_LICENCE_URL', 'https://web.acma.gov.au/rrl/licence_search.licence_lookup');
define('OUTPUT_KML', __DIR__ . '/dmr_repeaters.kml');
define('OUTPUT_CSV', __DIR__ . '/dmr_repeaters.csv');
define('OUTPUT_GEOJSON', __DIR__ . '/site/data/dmr_repeaters.geojson');
define('RATE_LIMIT_MS', 500); // ms between ACMA requests to be polite

// ---------------------------------------------------------------------------
// HTTP helper
// ---------------------------------------------------------------------------
function http_get(string $url): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'DMR-Repeater-Tool/1.0 (amateur radio utility)',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($body === false || $code >= 400) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("HTTP GET $url failed ($code): $err");
    }
    curl_close($ch);
    return $body;
}

function http_post(string $url, array $fields): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_USERAGENT => 'DMR-Repeater-Tool/1.0 (amateur radio utility)',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($body === false || $code >= 400) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("HTTP POST $url failed ($code): $err");
    }
    curl_close($ch);
    return $body;
}

// ---------------------------------------------------------------------------
// Step 1: Scrape repeater list from vkdmr.com
// ---------------------------------------------------------------------------
function fetch_repeater_list(): array {
    echo "Fetching repeater list from vkdmr.com ...\n";
    $html = http_get(VKDMR_URL);

    $repeaters = [];
    // The LINK-STATUS table has rows like:
    //   <td>VK2RAG</td>
    //   <td>Somersby  (28)</td>
    // We look for the pattern in table rows.
    // Each row: NR | REPEATER | INFO | ID | TS1 | CQ | TS1-INFO | TS2 | TS2-INFO | REF | START | HARDWARE
    if (!preg_match_all(
        '/<tr class="trow">\s*<td>\d+<\/td>\s*<td>(VK\d[A-Z]{2,4})<\/td>\s*<td>([^<]*)<\/td>\s*<td>(\d+)<\/td>/s',
        $html, $matches, PREG_SET_ORDER
    )) {
        throw new RuntimeException("Could not parse repeater list from vkdmr.com");
    }

    foreach ($matches as $m) {
        $callsign = trim($m[1]);
        $info = trim($m[2]);
        $dmr_id = trim($m[3]);
        // Avoid duplicates
        if (!isset($repeaters[$callsign])) {
            $repeaters[$callsign] = [
                'callsign' => $callsign,
                'info' => $info,
                'dmr_id' => $dmr_id,
            ];
        }
    }

    echo "  Found " . count($repeaters) . " unique repeaters.\n";
    return $repeaters;
}

// ---------------------------------------------------------------------------
// Step 2: Look up each callsign on ACMA
// ---------------------------------------------------------------------------
function acma_lookup_callsign(string $callsign): ?array {
    // Search for the callsign in the Licences register
    $html = http_post(ACMA_SEARCH_URL, [
        'pSEARCH_TYPE' => 'Licences',
        'pSUB_TYPE' => 'Callsign',
        'pEXACT_IND' => 'matches',
        'pQRY' => $callsign,
    ]);

    // Sometimes the search returns a results list instead of direct licence.
    // Check if we got a licence page directly (has "Licence Details") or a list.
    if (strpos($html, 'Licence Details') === false) {
        // Try to find a licence link in the results
        if (preg_match('/licence_search\.licence_lookup\?pLICENCE_NO=([^"&]+)/', $html, $m)) {
            $html = http_get('https://web.acma.gov.au/rrl/licence_search.licence_lookup?pLICENCE_NO=' . urlencode($m[1]));
            usleep(RATE_LIMIT_MS * 1000);
        } else {
            return null;
        }
    }

    $result = [
        'licence_no' => null,
        'client' => null,
        'site_id' => null,
        'site_name' => null,
        'frequencies' => [], // [{freq, type (T/R), band}]
    ];

    // Extract licence number
    if (preg_match('/Licence Number<\/td>\s*<td>([^<]+)<\/td>/', $html, $m)) {
        $result['licence_no'] = html_entity_decode(trim($m[1]));
    }

    // Extract client name
    if (preg_match('/Client<\/td>\s*<td>\s*<a[^>]*>([^<]+)<\/a>/', $html, $m)) {
        $result['client'] = html_entity_decode(trim($m[1]));
    }

    // Extract frequency assignments
    // Each assignment row has: ID | Frequency | Emission Designator | T/R | Site/Area
    // Frequency is in a tooltip: title="Center Frequency: 439.825 MHz, Bandwidth: ..."
    // T/R is: title="Transmitter">T or title="Receiver">R
    // Site link has: site_search.site_lookup?pSITE_ID=XXXX
    if (preg_match_all(
        '/title="Center Frequency:\s*([\d.]+)\s*(MHz|kHz|GHz)[^"]*">[^<]*<\/span>\s*<\/td>\s*<td>[^<]*<\/td>\s*<td>\s*<span[^>]*title="(Transmitter|Receiver)">\s*([TR])/s',
        $html, $fmatches, PREG_SET_ORDER
    )) {
        foreach ($fmatches as $fm) {
            $freq = (float) $fm[1];
            $unit = $fm[2];
            $type = $fm[4]; // T or R

            // Normalize to MHz
            if ($unit === 'kHz') $freq /= 1000;
            if ($unit === 'GHz') $freq *= 1000;

            $result['frequencies'][] = [
                'freq_mhz' => $freq,
                'type' => $type,
            ];
        }
    }

    // Extract the first site ID from the page
    if (preg_match('/site_search\.site_lookup\?pSITE_ID=(\d+)/', $html, $m)) {
        $result['site_id'] = $m[1];
    }

    // Extract site name from the site link text
    if (preg_match('/site_search\.site_lookup\?pSITE_ID=\d+">([^<]+)<\/a>/', $html, $m)) {
        $result['site_name'] = html_entity_decode(trim($m[1]));
    }

    return $result;
}

// ---------------------------------------------------------------------------
// Step 3: Look up site coordinates
// ---------------------------------------------------------------------------
function acma_lookup_site(string $site_id): ?array {
    $url = 'https://web.acma.gov.au/rrl/site_search.site_lookup?pSITE_ID=' . urlencode($site_id);
    $html = http_get($url);

    $result = ['lat' => null, 'lon' => null, 'location' => null];

    // Lat,Long (GDA94): -33.360078°,151.291215°
    if (preg_match('/Lat,Long \(GDA94\)<\/td>\s*<td>\s*([-\d.]+)&deg;,([-\d.]+)&deg;/', $html, $m)) {
        $result['lat'] = (float) $m[1];
        $result['lon'] = (float) $m[2];
    }

    // Location
    if (preg_match('/Location<\/td>\s*<td>([^<]+)<\/td>/', $html, $m)) {
        $result['location'] = html_entity_decode(trim($m[1]));
    }

    return $result;
}

// ---------------------------------------------------------------------------
// Pick the most likely DMR TX and RX frequencies from assignment list
// ---------------------------------------------------------------------------
function pick_dmr_frequencies(array $frequencies): array {
    // DMR repeaters in Australia typically use UHF (430-450 MHz) or VHF (144-148 MHz).
    // For a repeater, we want one TX and one RX frequency.
    // The UHF pair is most commonly used for DMR.
    // Standard offset: TX higher than RX by 5 MHz on UHF, 0.6 MHz on VHF.

    $tx_freqs = [];
    $rx_freqs = [];

    foreach ($frequencies as $f) {
        if ($f['type'] === 'T') {
            $tx_freqs[] = $f['freq_mhz'];
        } else {
            $rx_freqs[] = $f['freq_mhz'];
        }
    }

    // Prefer UHF (430-450 MHz) DMR pair
    $best_tx = null;
    $best_rx = null;

    // Try to find a UHF TX/RX pair with standard 5 MHz offset
    foreach ($tx_freqs as $tx) {
        if ($tx >= 430 && $tx <= 450) {
            foreach ($rx_freqs as $rx) {
                if ($rx >= 430 && $rx <= 450 && abs(abs($tx - $rx) - 5.0) < 0.1) {
                    $best_tx = $tx;
                    $best_rx = $rx;
                    break 2;
                }
            }
        }
    }

    // Fallback: any UHF TX/RX pair
    if ($best_tx === null) {
        foreach ($tx_freqs as $tx) {
            if ($tx >= 430 && $tx <= 450) {
                foreach ($rx_freqs as $rx) {
                    if ($rx >= 430 && $rx <= 450) {
                        $best_tx = $tx;
                        $best_rx = $rx;
                        break 2;
                    }
                }
            }
        }
    }

    // Fallback: VHF pair with 0.6 MHz offset
    if ($best_tx === null) {
        foreach ($tx_freqs as $tx) {
            if ($tx >= 144 && $tx <= 148) {
                foreach ($rx_freqs as $rx) {
                    if ($rx >= 144 && $rx <= 148 && abs(abs($tx - $rx) - 0.6) < 0.05) {
                        $best_tx = $tx;
                        $best_rx = $rx;
                        break 2;
                    }
                }
            }
        }
    }

    // Fallback: just take the first TX and first RX
    if ($best_tx === null && !empty($tx_freqs) && !empty($rx_freqs)) {
        $best_tx = $tx_freqs[0];
        $best_rx = $rx_freqs[0];
    }

    return ['tx' => $best_tx, 'rx' => $best_rx];
}

// ---------------------------------------------------------------------------
// KML generation
// ---------------------------------------------------------------------------
function generate_kml(array $repeaters, string $filename): void {
    $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?>
<kml xmlns="http://www.opengis.net/kml/2.2">
  <Document/>
</kml>');

    $doc = $xml->Document;
    $doc->addChild('name', 'VK DMR Repeaters');
    $doc->addChild('description', 'DMR repeaters from rpt.vkdmr.com with ACMA licence data. Generated ' . date('Y-m-d H:i:s'));

    // Style for the pins
    $style = $doc->addChild('Style');
    $style->addAttribute('id', 'repeaterPin');
    $iconStyle = $style->addChild('IconStyle');
    $iconStyle->addChild('color', 'ff0000ff'); // red
    $iconStyle->addChild('scale', '1.2');
    $icon = $iconStyle->addChild('Icon');
    $icon->addChild('href', 'http://maps.google.com/mapfiles/kml/paddle/red-circle.png');

    foreach ($repeaters as $r) {
        if ($r['lat'] === null || $r['lon'] === null) {
            continue;
        }

        $pm = $doc->addChild('Placemark');
        $pm->addChild('name', htmlspecialchars($r['callsign']));
        $pm->addChild('styleUrl', '#repeaterPin');

        // Build description
        $desc = [];
        $desc[] = '<b>Callsign:</b> ' . htmlspecialchars($r['callsign']);
        if (!empty($r['info'])) {
            $desc[] = '<b>Info:</b> ' . htmlspecialchars($r['info']);
        }
        if (!empty($r['dmr_id'])) {
            $desc[] = '<b>DMR ID:</b> ' . htmlspecialchars($r['dmr_id']);
        }
        if (!empty($r['client'])) {
            $desc[] = '<b>Licensee:</b> ' . htmlspecialchars($r['client']);
        }
        if (!empty($r['location'])) {
            $desc[] = '<b>Location:</b> ' . htmlspecialchars($r['location']);
        }
        if ($r['tx'] !== null) {
            $desc[] = '<b>TX Frequency:</b> ' . number_format($r['tx'], 4) . ' MHz';
        }
        if ($r['rx'] !== null) {
            $desc[] = '<b>RX Frequency:</b> ' . number_format($r['rx'], 4) . ' MHz';
        }
        if ($r['tx'] !== null && $r['rx'] !== null) {
            $offset = $r['tx'] - $r['rx'];
            $desc[] = '<b>Offset:</b> ' . ($offset >= 0 ? '+' : '') . number_format($offset, 3) . ' MHz';
        }
        if (!empty($r['licence_no'])) {
            $desc[] = '<b>Licence:</b> ' . htmlspecialchars($r['licence_no']);
        }
        $desc[] = '<b>Coordinates:</b> ' . $r['lat'] . ', ' . $r['lon'];

        $descNode = $pm->addChild('description');
        // Use CDATA for HTML content
        $dom = dom_import_simplexml($descNode);
        $cdata = $dom->ownerDocument->createCDATASection(implode('<br/>', $desc));
        $dom->appendChild($cdata);

        $point = $pm->addChild('Point');
        $point->addChild('coordinates', $r['lon'] . ',' . $r['lat'] . ',0');
    }

    // Format the XML nicely
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;
    $dom->loadXML($xml->asXML());
    $dom->save($filename);
}

// ---------------------------------------------------------------------------
// CSV generation
// ---------------------------------------------------------------------------
function generate_csv(array $repeaters, string $filename): void {
    $fp = fopen($filename, 'w');
    fputcsv($fp, [
        'Callsign', 'Info', 'DMR ID', 'Licensee', 'Location',
        'TX MHz', 'RX MHz', 'Offset MHz', 'Latitude', 'Longitude',
        'Licence No', 'Site ID'
    ], ',', '"', '\\');
    foreach ($repeaters as $r) {
        $offset = ($r['tx'] !== null && $r['rx'] !== null)
            ? number_format($r['tx'] - $r['rx'], 3)
            : '';
        fputcsv($fp, [
            $r['callsign'],
            $r['info'] ?? '',
            $r['dmr_id'] ?? '',
            $r['client'] ?? '',
            $r['location'] ?? '',
            $r['tx'] !== null ? number_format($r['tx'], 4) : '',
            $r['rx'] !== null ? number_format($r['rx'], 4) : '',
            $offset,
            $r['lat'] ?? '',
            $r['lon'] ?? '',
            $r['licence_no'] ?? '',
            $r['site_id'] ?? '',
        ], ',', '"', '\\');
    }
    fclose($fp);
}

// ---------------------------------------------------------------------------
// GeoJSON generation
// ---------------------------------------------------------------------------
function generate_geojson(array $repeaters, string $filename): void {
    // Ensure the directory exists
    $dir = dirname($filename);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $features = [];
    foreach ($repeaters as $r) {
        if ($r['lat'] === null || $r['lon'] === null) {
            continue;
        }

        $offset = null;
        if ($r['tx'] !== null && $r['rx'] !== null) {
            $offset = round($r['tx'] - $r['rx'], 4);
        }

        $features[] = [
            'type' => 'Feature',
            'geometry' => [
                'type' => 'Point',
                'coordinates' => [$r['lon'], $r['lat']],
            ],
            'properties' => [
                'callsign' => $r['callsign'],
                'info' => $r['info'] ?? '',
                'dmr_id' => $r['dmr_id'] ?? '',
                'client' => $r['client'] ?? '',
                'location' => $r['location'] ?? '',
                'tx_mhz' => $r['tx'],
                'rx_mhz' => $r['rx'],
                'offset_mhz' => $offset,
                'licence_no' => $r['licence_no'] ?? '',
                'site_id' => $r['site_id'] ?? '',
            ],
        ];
    }

    $collection = [
        'type' => 'FeatureCollection',
        'generated' => date('c'),
        'source' => 'DMR Repeater Tool – rpt.vkdmr.com + ACMA RRL',
        'features' => $features,
    ];

    file_put_contents($filename, json_encode($collection, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------
function main(): void {
    echo "=== VK DMR Repeater Tool ===\n\n";

    // Step 1: Get repeater list
    $repeaters = fetch_repeater_list();

    // Step 2 & 3: Look up each callsign on ACMA
    $results = [];
    $total = count($repeaters);
    $i = 0;

    foreach ($repeaters as $callsign => $rpt) {
        $i++;
        echo "  [$i/$total] Looking up $callsign ... ";

        try {
            $acma = acma_lookup_callsign($callsign);
            usleep(RATE_LIMIT_MS * 1000);

            if ($acma === null) {
                echo "not found on ACMA.\n";
                $results[] = array_merge($rpt, [
                    'licence_no' => null, 'client' => null,
                    'site_id' => null, 'site_name' => null,
                    'tx' => null, 'rx' => null,
                    'lat' => null, 'lon' => null, 'location' => null,
                ]);
                continue;
            }

            // Pick DMR frequencies
            $freqs = pick_dmr_frequencies($acma['frequencies']);

            // Look up site coordinates
            $site = null;
            if ($acma['site_id']) {
                $site = acma_lookup_site($acma['site_id']);
                usleep(RATE_LIMIT_MS * 1000);
            }

            $entry = array_merge($rpt, [
                'licence_no' => $acma['licence_no'],
                'client' => $acma['client'],
                'site_id' => $acma['site_id'],
                'site_name' => $acma['site_name'],
                'tx' => $freqs['tx'],
                'rx' => $freqs['rx'],
                'lat' => $site['lat'] ?? null,
                'lon' => $site['lon'] ?? null,
                'location' => $site['location'] ?? null,
            ]);

            $results[] = $entry;

            $status = [];
            if ($freqs['tx']) $status[] = 'TX=' . number_format($freqs['tx'], 4);
            if ($freqs['rx']) $status[] = 'RX=' . number_format($freqs['rx'], 4);
            if ($site && $site['lat']) $status[] = 'pos=' . $site['lat'] . ',' . $site['lon'];
            echo implode(' ', $status) ?: 'partial data';
            echo "\n";

        } catch (Exception $e) {
            echo "ERROR: " . $e->getMessage() . "\n";
            $results[] = array_merge($rpt, [
                'licence_no' => null, 'client' => null,
                'site_id' => null, 'site_name' => null,
                'tx' => null, 'rx' => null,
                'lat' => null, 'lon' => null, 'location' => null,
            ]);
        }
    }

    // Step 4: Generate outputs
    $with_coords = array_filter($results, fn($r) => $r['lat'] !== null);
    echo "\n" . count($with_coords) . " of " . count($results) . " repeaters have coordinates.\n";

    echo "Generating KML: " . OUTPUT_KML . "\n";
    generate_kml($results, OUTPUT_KML);

    echo "Generating CSV: " . OUTPUT_CSV . "\n";
    generate_csv($results, OUTPUT_CSV);

    echo "Generating GeoJSON: " . OUTPUT_GEOJSON . "\n";
    generate_geojson($results, OUTPUT_GEOJSON);

    echo "\nDone!\n";
}

main();
