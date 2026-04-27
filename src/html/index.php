<?php

// Init
ini_set('include_path', '..');
ini_set('memory_limit', '512M');
define('SCHEME', $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? $_SERVER['REQUEST_SCHEME']);
define('HOST', $_SERVER['HTTP_HOST']);
define('METHOD', $_SERVER['REQUEST_METHOD']);
define('HTML', str_contains($_SERVER['HTTP_ACCEPT'], 'text/html'));
define('DATA_DIR', '/data/');
define('SECRET_PATTERN', '/^[A-Za-z0-9_-]{5,50}$/');
define('DATASET_PATTERN', '/^[a-z0-9-]{1,15}$/');
define('HASH_PATTERN', '/^[0-9a-f]{16}$/');
define('RESOLUTIONS', ['minutes', 'quarters', 'hours', 'days', 'weeks', 'months', 'years']);
define('PERIOD_UNITS', ['minutes', 'hours', 'days', 'weeks', 'months', 'years']);
define('MAX_DATA_POINTS', 250);
define('CURRENT_TIMESTAMP', time());
define('CURRENT_FORMATTED_DATETIME', (new DateTime())->format(DateTime::ATOM));

$config = getConfig();
$meta = null; // Will be loaded when needed to avoid unnecessary file read operations

// Browser request
if (METHOD === 'GET' && HTML) {

    // Graph request
    if (isset($_GET['path'])) {
        // Check hash
        $hash = validateHash($_GET['path']);
        if (!hashExists($hash)) response('No graphs found', 'text/plain', 404);

        // Get parameters from URL and validate them
        /*
          Expected URL parameters:
          pn = number representing the lookback period to show (e.g., 1, 24, 7) (default: 1)
          pu = unit of the lookback period (minutes, quarters, hours, days, weeks, months, years) (default: hours)
          hm = hide menu (1 or 0) (default: 0)
          de1 = dataset enabled (1 or 0) (default: 1)
          da1 = dataset aggregation type (avg, min, max, last) (default: avg)
          de2...
        */
        // Period parameters
        $period = validatePeriod($_GET['pn'] ?? '1', $_GET['pu'] ?? 'hours');
        // Hide menu parameter
        $hideMenu = isset($_GET['hm']) && ($_GET['hm'] === '1');
        // Datasets parameters
        $datasetNames = getDatasetNames($hash);
        if (empty($datasetNames)) response('No graphs found', 'text/plain', 404);
        $datasets = [];
        for ($i = 1; $i <= count($datasetNames); $i++) {
            $name = $datasetNames[$i - 1] ?? '';
            $enabled = isset($_GET["de$i"]) ? ($_GET["de$i"] === '1') : true;
            $aggregation = validateAggregationType($_GET["da$i"] ?? 'avg');
            $datasets[] = ['name' => validateDataset($name), 'enabled' => $enabled, 'aggregation' => $aggregation];
        }

        // Generate graph data and show graph
        $data['title'] = 'Numeric Value Graph - ' . implode(' ', array_column($datasets, 'name'));
        $data['hm'] = $hideMenu;
        $data['pn'] = $period[0];
        $data['pu'] = $period[1];
        $data['datasets'] = $datasets;
        $data['chartDataJson'] = generateGraphData($hash, $period, $datasets);
        response_file('graph.php', $data);
    }

    // Show form
    else {
        response_file('form.php');
    }
}

// API request
if (METHOD === 'GET' && !HTML) {
    // Example: curl http://localhost:8080/d7c58323b604b471 
    // Datasets request
    if (isset($_GET['path'])) {
        $hash = validateHash($_GET['path']);
        $datasets = getDatasetNames($hash);
        $response = ['datasets' => $datasets];
        response(json_encode($response, JSON_PRETTY_PRINT), 'application/json');
    }

    // Example: curl http://localhost:8080
    // API Usage instructions
    else {
        $url = getUrl();
        $usage = "Usage:\n"
            . "To submit data: POST with fields 'secret', 'dataset1', 'dataset2', ... \n"
            . "Example: curl -d secret=YourSecretString -d line1data=3 -d line2data=4.5 $url\n";
        response($usage, 'text/plain');
    }
}

// POST
if (METHOD === 'POST') {
    // Example: curl -d secret=YourSecretString -d line1data=3 -d line2data=4.5 http://localhost:8080

    // Wrong URL
    if (isset($_GET['path'])) {
        response('Method not allowed on this endpoint', 'text/plain', 405);
    }

    // Store data
    $secret = $_POST['secret'] ?? '';
    $hash = validateSecret($secret);
    $time = CURRENT_TIMESTAMP;
    $fields = [];
    foreach ($_POST as $key => $value) {
        if ($key === 'secret') continue; // Skip secret field
        $fields[] = $key;
        $ds = validateDataset($key);
        if ($secret === 'testing' && $ds === 'testdata' && $value === '123') generateTestData($hash); // For testing purposes, generates a lot of random data
        $val = validateNumber($value);
        appendData($hash, $ds, 'samples', [$time, $val]);
        aggregateData($hash, $ds);
    }

    // HTML response
    if (HTML) {
        $fields = "&name1=" . ($fields[0] ?? '')
            . "&name2=" . ($fields[1] ?? '')
            . "&name3=" . ($fields[2] ?? '')
            . "&name4=" . ($fields[3] ?? '')
            . "&name5=" . ($fields[4] ?? '');
        redirect('?graphurl=' . getUrl($hash) . '&secret=' . $secret . $fields);
    }

    // API response
    if (!HTML) {
        $response = ['url' => getUrl($hash)];
        response(json_encode($response, JSON_UNESCAPED_SLASHES), 'application/json');
    }
}

response('Method not allowed', 'text/plain', 405);

// Subroutines
function aggregateData(string $hash, string $dataset): void {
    aggregateSamples($hash, $dataset);

    aggregateLevel("minutes", "quarters", $hash, $dataset);
    aggregateLevel("quarters", "hours", $hash, $dataset);
    aggregateLevel("hours", "days", $hash, $dataset);
    aggregateLevel("days", "weeks", $hash, $dataset);
    aggregateLevel("days", "months", $hash, $dataset);
    aggregateLevel("months", "years", $hash, $dataset);

    cleanupData($hash, $dataset);
}

function cleanupData(string $hash, string $dataset): void {
    foreach (RESOLUTIONS as $resolution) {
        $data = loadData($hash, $dataset, $resolution);
        if ($data === []) continue;
        saveData($hash, $dataset, $resolution, array_slice($data, -MAX_DATA_POINTS));
    }
}

function response(string $data = '', string $contenttype = 'text/html', int $status = 200): void {
    // End data with newline if not already present for better readability in terminal when using curl command
    if (substr($data, -1) !== "\n") $data .= "\n";
    http_response_code($status);
    header("Content-Type: $contenttype");
    exit($data);
}

function response_file(string $filename, array $data = []): void {
    ob_start();
    require $filename;
    $content = ob_get_clean();
    response($content);
}

function aggregateSamples(string $hash, string $dataset): void {
    $cur_minute = getPeriodTimestamp(CURRENT_TIMESTAMP, 'minutes');
    $samples = loadData($hash, $dataset, 'samples');
    if ($samples === []) return;  // No samples to aggregate

    $first_minute = getPeriodTimestamp($samples[0][0], 'minutes');
    if ($first_minute >= $cur_minute) return;  // No samples to aggregate yet

    $minutes = loadData($hash, $dataset, 'minutes');
    $aggregated = [];
    $bucket = ['sum' => 0, 'count' => 0, 'min' => null, 'max' => null, 'last' => null];
    $minute = $first_minute;

    foreach ($samples as $index => [$timestamp, $value]) {
        $sample_minute = getPeriodTimestamp($timestamp, 'minutes');
        if ($sample_minute >= $cur_minute) {
            $remainingSamples = array_slice($samples, $index);
            break;
        }

        if ($sample_minute !== $minute) {
            if ($bucket['count'] > 0) {
                $aggregated[] = [$minute, $bucket['sum'] / $bucket['count'], $bucket['min'], $bucket['max'], $bucket['last']];
            }
            $minute = $sample_minute;
            $bucket = ['sum' => 0, 'count' => 0, 'min' => null, 'max' => null, 'last' => null];
        }

        $bucket['sum'] += $value;
        $bucket['count']++;
        $bucket['min'] = $bucket['min'] === null || $value < $bucket['min'] ? $value : $bucket['min'];
        $bucket['max'] = $bucket['max'] === null || $value > $bucket['max'] ? $value : $bucket['max'];
        $bucket['last'] = $value;
    }

    if (!isset($remainingSamples)) {
        $remainingSamples = [];
    }

    if ($bucket['count'] > 0 && $minute < $cur_minute) {
        $aggregated[] = [$minute, $bucket['sum'] / $bucket['count'], $bucket['min'], $bucket['max'], $bucket['last']];
    }

    if ($aggregated !== []) {
        $minutes = array_merge($minutes, $aggregated);
        saveData($hash, $dataset, 'minutes', $minutes);
    }

    saveData($hash, $dataset, 'samples', $remainingSamples);
}

function aggregateLevel(string $fromLevel, string $toLevel, string $hash, string $dataset): void {
    // Load data
    $fromData = loadData($hash, $dataset, $fromLevel);
    if ($fromData === []) return;
    $toData = loadData($hash, $dataset, $toLevel);

    $lastToDataPeriod = $toData !== [] ? $toData[array_key_last($toData)][0] : 0;
    $currentToPeriod = getPeriodTimestamp(CURRENT_TIMESTAMP, $toLevel);
    $periodData = [];

    foreach ($fromData as [$timestamp, $avg, $min, $max, $last]) {
        $thisToPeriod = getPeriodTimestamp($timestamp, $toLevel);
        if ($thisToPeriod <= $lastToDataPeriod) continue;  // Skip if we already have data for this period
        if ($thisToPeriod >= $currentToPeriod) break;      // Stop if we've reached the current period

        $bucket = &$periodData[$thisToPeriod];
        if (!isset($bucket)) {
            $bucket = ['sum' => 0, 'count' => 0, 'min' => $min, 'max' => $max, 'last' => $last];
        }
        $bucket['sum'] += $avg;
        $bucket['count']++;
        $bucket['min'] = $min < $bucket['min'] ? $min : $bucket['min'];
        $bucket['max'] = $max > $bucket['max'] ? $max : $bucket['max'];
        $bucket['last'] = $last;
        unset($bucket);
    }

    if ($periodData === []) return;  // No new data to aggregate

    // Convert and save aggregated data
    foreach ($periodData as $period => $data) {
        $toData[] = [$period, $data['sum'] / $data['count'], $data['min'], $data['max'], $data['last']];
    }
    saveData($hash, $dataset, $toLevel, $toData);
}

function redirect(string $url): void {
    if (!str_starts_with($url, 'http')) {
        $url = SCHEME . '://' . HOST . '/' . ltrim($url, '/');
    }
    header('Location: ' . $url);
    exit('Redirecting to ' . $url);
}

// Validation functions
function validateHash(string $hash): string {
    $hash = strtolower(trim($hash));
    if (!preg_match(HASH_PATTERN, $hash)) {
        response('Invalid path', 'text/plain', 404);
    }
    return $hash;
}

function validateAggregationType(string $type): string {
    $type = strtolower(trim($type));
    if (!in_array($type, ['avg', 'min', 'max', 'last'], true)) {
        response('Invalid aggregation type', 'text/plain', 400);
    }
    return $type;
}

function validatePeriod(string $number, string $unit): array {
    $number = strtolower(trim($number));
    $unit = strtolower(trim($unit));

    // Check if period is a number
    if (!is_numeric($number)) {
        response('Period must be a number', 'text/plain', 400);
    }

    // Check if unit is valid
    if (!in_array($unit, PERIOD_UNITS, true)) {
        response('Invalid period unit', 'text/plain', 400);
    }

    return [(int)$number, $unit];
}

function validateSecret(string $secret): string {
    global $config;
    if (empty($secret)) {
        response('Secret is required', 'text/plain', 400);
    }
    if (!preg_match(SECRET_PATTERN, $secret)) {
        response('Secret must be between 5 and 50 characters long and can only contain letters, numbers, underscores and dashes', 'text/plain', 400);
    }
    return substr(hash('sha256', $secret . $config['salt']), 0, 16);
}

function validateDataset(string $dataset): string {
    if (!preg_match(DATASET_PATTERN, $dataset)) {
        response('Dataset names must be between 1 and 15 characters long and can only contain lowercase letters, numbers and dashes', 'text/plain', 400);
    }
    return $dataset;
}

function validateNumber(string $number): float {
    if (!is_numeric($number)) {
        response("Not a valid number ($number)", 'text/plain', 400);
    }
    if (strlen($number) > 10) {
        response('Number must be less than 10 characters long', 'text/plain', 400);
    }
    return (float)$number;
}

function hashExists(string $hash): bool {
    $hash = validateHash($hash);
    foreach (glob(DATA_DIR . $hash . '_*_samples.json') as $file) {
        if (file_exists($file)) return true;
    }
    return false;
}

// Data functions
function loadData(string $hash, string $dataset, string $resolution, string $aggregation = 'all', int $from = 0): array {
    $file = DATA_DIR . $hash . '_' . $dataset . '_' . $resolution . '.json';
    if (!file_exists($file)) return [];
    $data = json_decode(file_get_contents($file), true);

    // If from is specified, filter data to only include entries with timestamp greater than or equal to from
    if ($from > 0) {
        $data = array_filter($data, function ($entry) use ($from) {
            return $entry[0] >= $from;
        });
    }

    // Add from timestamp as the first entry if it's not already included and from is specified
    $firstEntry = reset($data);
    if ($from > 0 && !empty($firstEntry) && $firstEntry[0] > $from) {
        array_unshift($data, [$from, 'null', 'null', 'null', 'null']);
    }

    // If aggregation is specified, filter data accordingly (e.g., if aggregation is 'max', return only the max values)
    if ($aggregation !== 'all') {
        $typeIndex = ['avg' => 1, 'min' => 2, 'max' => 3, 'last' => 4][$aggregation] ?? 1;
        $data = array_map(function ($entry) use ($typeIndex) {
            return [$entry[0], $entry[$typeIndex]];
        }, $data);
    }

    return $data;
}

function saveData(string $hash, string $dataset, string $resolution, array $data): void {
    $file = DATA_DIR . $hash . '_' . $dataset . '_' . $resolution . '.json';
    file_put_contents($file, json_encode($data), LOCK_EX);
    updateMeta('lastModified', $hash, $dataset);
}

function appendData(string $hash, string $dataset, string $type, array $entry): void {
    $data = loadData($hash, $dataset, $type);
    $data[] = $entry;
    saveData($hash, $dataset, $type, $data);
}

function getDatasetNames(string $hash): array {
    // Iterate files in DATA_DIR and find the datasets for the given hash
    $names = [];
    foreach (glob(DATA_DIR . $hash . '_*_samples.json') as $file) {
        $filename = basename($file);
        $parts = explode('_', $filename);
        $names[] = $parts[1];
    }
    sort($names);
    return $names;
}

function generateGraphData(string $hash, array $period = ['1', 'hours'], array $datasets = []): string {
    // Calculate smallest resolution that keeps the number of data points under MAX_DATA_POINTS
    $periodInMinutes = getPeriodInMinutes($period);
    $resolutionMinutes = ['minutes' => 1, 'quarters' => 15, 'hours' => 60, 'days' => 1440, 'weeks' => 10080, 'months' => 43680, 'years' => 524160];
    $resolution = 'years'; // Default to years if no suitable level is found
    foreach ($resolutionMinutes as $res => $minutes) {
        $points = $periodInMinutes / $minutes;
        if ($points <= MAX_DATA_POINTS) {
            $resolution = $res;
            break;
        }
    }

    // Iterate datasets and get data for each
    updateMeta('lastAccessed', $hash);
    $data = [];
    $startFrom = CURRENT_TIMESTAMP - $periodInMinutes * 60;
    foreach ($datasets as $dataset) {
        if (!$dataset['enabled']) continue; // Skip disabled datasets
        updateMeta('lastAccessed', $hash, $dataset['name']);
        $data[$dataset['name']] = loadData($hash, $dataset['name'], $resolution, $dataset['aggregation'], $startFrom);

        // Add current timestamp with null value if there is no data point for the current period to make sure the graph always reaches the current time
        if (empty($data[$dataset['name']]) || end($data[$dataset['name']])[0] < CURRENT_TIMESTAMP) {
            $data[$dataset['name']][] = [CURRENT_TIMESTAMP, 'null'];
        }
    }

    // No data to show
    if (empty($data)) {
        $chartDataJson = "[[{type: 'datetime', label: 'Time'},{type: 'number', label: 'Data'}],[new Date(0), 0]]";
    }

    // Convert data to format suitable for Google Charts with multiple datasets
    else {
        $chartDataJson = "[[{type: 'datetime', label: 'Time'}";
        foreach ($data as $dataset => $entries) {
            $chartDataJson .= ", {type: 'number', label: " . json_encode($dataset, JSON_UNESCAPED_UNICODE) . "}";
        }
        $chartDataJson .= "]";

        // Collect all timestamps
        $timestamps = [];
        foreach ($data as $entries) {
            foreach ($entries as $entry) {
                $timestamps[] = $entry[0];
            }
        }
        sort($timestamps);

        // Build rows with values for each dataset
        foreach ($timestamps as $timestamp) {
            $chartDataJson .= ",[new Date(" . $timestamp * 1000 . ")";
            foreach ($data as $entries) {
                $value = null;
                foreach ($entries as $entry) {
                    if ($entry[0] == $timestamp) {
                        $value = $entry[1];
                        break;
                    }
                }
                $chartDataJson .= ", " . ($value === null ? 'null' : $value);
            }
            $chartDataJson .= "]";
        }
        $chartDataJson .= "]";
    }

    return $chartDataJson;
}

function generateTestData(string $hash): void {
    $datasets = ['testdata1', 'testdata2', 'testdata3'];
    $twoYearsAgo = CURRENT_TIMESTAMP - (2 * 365 * 24 * 60 * 60);

    foreach ($datasets as $dataset) {
        $samples = [];
        $value = 0.0;
        for ($timestamp = $twoYearsAgo; $timestamp <= CURRENT_TIMESTAMP; $timestamp += 120) {
            $samples[] = [$timestamp, $value];
            $value += rand(-1000, 1000) / 1000;
        }

        saveData($hash, $dataset, 'samples', $samples);
        aggregateData($hash, $dataset);
    }

    redirect('?graphurl=' . getUrl($hash) . '&secret=testing&name1=testdata');
}

function getConfig(): array {
    $configFile = DATA_DIR . 'config.json';
    if (!file_exists($configFile)) {
        $salt = bin2hex(random_bytes(16));
        file_put_contents($configFile, json_encode(['salt' => $salt], JSON_PRETTY_PRINT));
    }
    return json_decode(file_get_contents($configFile), true);
}

function getMeta(): array {
    $metaFile = DATA_DIR . 'meta.json';
    if (!file_exists($metaFile)) return [];
    return json_decode(file_get_contents($metaFile), true);
}

function updateMeta(string $key, string $hash, string $datasetName = ''): void {
    global $meta;
    if ($meta === null) $meta = getMeta(); // Load meta data if not already loaded
    $meta[$hash] = array_merge($meta[$hash] ?? ['lastAccessed' => '', 'lastModified' => ''], [$key => CURRENT_FORMATTED_DATETIME]);
    if (!empty($datasetName)) {
        $meta[$hash][$datasetName] = array_merge($meta[$hash][$datasetName] ?? ['lastAccessed' => '', 'lastModified' => ''], [$key => CURRENT_FORMATTED_DATETIME]);
    }
    saveMeta($meta);
}

function saveMeta(array $meta): void {
    $metaFile = DATA_DIR . 'meta.json';
    file_put_contents($metaFile, json_encode($meta, JSON_PRETTY_PRINT), LOCK_EX);
}

// Helper functions
function getUrl(string $hash = ''): string {
    return rtrim(SCHEME . '://' . HOST . '/' . $hash, '/');
}

function htmlUrl(string $url): string {
    if (!filter_var($url, FILTER_VALIDATE_URL)) response('Invalid URL', 'text/plain', 400);
    return '<a href="' . $url . '" target="_blank">' . $url . '</a>';
}

function getPeriodTimestamp(int $timestamp, string $period): int {
    switch ($period) {
        case 'minutes':
            return floor($timestamp / 60) * 60;
        case 'quarters':
            return floor($timestamp / 900) * 900;
        case 'hours':
            return floor($timestamp / 3600) * 3600;
        case 'days':
            return floor($timestamp / 86400) * 86400;
        case 'weeks':
            $date = new DateTime();
            $date->setTimestamp($timestamp);
            $date->setISODate($date->format('o'), $date->format('W'), 1); // Set to Monday of this week
            $date->setTime(0, 0, 0);
            return $date->getTimestamp();
        case 'months':
            $date = new DateTime();
            $date->setTimestamp($timestamp);
            $date->setDate($date->format('Y'), $date->format('m'), 1); // Set to the first day of this month
            $date->setTime(0, 0, 0);
            return $date->getTimestamp();
        case 'years':
            $date = new DateTime();
            $date->setTimestamp($timestamp);
            $date->setDate($date->format('Y'), 1, 1); // Set to the first day of this year
            $date->setTime(0, 0, 0);
            return $date->getTimestamp();
        default:
            return $timestamp;
    }
}

function getPeriodInMinutes(array $period): int {
    $number = (int)$period[0] ?? 1;
    $unit = $period[1] ?? 'hours';
    switch ($unit) {
        case 'minutes':
            return $number * 1;
        case 'quarters':
            return $number * 15;
        case 'hours':
            return $number * 60;
        case 'days':
            return $number * 1440;
        case 'weeks':
            return $number * 10080;
        case 'months':
            return $number * 43200;
        case 'years':
            return $number * 525600;
        default:
            response('Invalid period unit', 'text/plain', 400);
            return 0;
    }
}
