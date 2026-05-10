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

// Create & connect SQLite database
$db = new SQLite3(DATA_DIR . 'nvg.db');
$db->exec('CREATE TABLE IF NOT EXISTS config (key TEXT PRIMARY KEY, value TEXT)');
$db->exec('CREATE TABLE IF NOT EXISTS collection (id INTEGER PRIMARY KEY, hash TEXT UNIQUE)');
$db->exec('CREATE TABLE IF NOT EXISTS dataset (id INTEGER PRIMARY KEY, collection_id INTEGER, name TEXT, FOREIGN KEY(collection_id) REFERENCES collection(id), UNIQUE(collection_id, name))');
$db->exec('CREATE TABLE IF NOT EXISTS datapoint (dataset_id INTEGER, timestamp INTEGER, avg REAL, min REAL, max REAL, last REAL, resolution TEXT, FOREIGN KEY(dataset_id) REFERENCES dataset(id))');

// Db version
$db_version = $db->querySingle("SELECT value FROM config WHERE key = 'db_version'");
if ($db_version === false) {
    $db->exec("INSERT INTO config (key, value) VALUES ('db_version', '1')");
    $db_version = 1;
}

// Add salt to config if not already present
$result = $db->query("SELECT value FROM config WHERE key = 'salt'");
if ($result->fetchArray() === false) {
    $salt = bin2hex(random_bytes(16));
    $db->exec("INSERT INTO config (key, value) VALUES ('salt', '$salt')");
}

// Get config from database
$r = $db->query("SELECT * FROM config");
$config = [];
while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
    $config[$row['key']] = $row['value'];
}

$meta = null; // Will be loaded when needed to avoid unnecessary file read operations

// Browser request
if (METHOD === 'GET' && HTML) {

    // Graph request
    if (isset($_GET['path'])) {
        // Check hash
        $hash = validateHash($_GET['path']);
        if (!($collectionId = getCollectionId($hash))) response('No graphs found', 'text/plain', 404);

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
        $datasetNames = getDatasetNames($collectionId);
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
        $data['resolution'] = getResolution($period);
        $data['chartDataJson'] = generateGraphData($collectionId, $period, $datasets);
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
    $db->exec("INSERT INTO collection (hash) VALUES ('$hash') ON CONFLICT(hash) DO NOTHING");
    $collectionId = $db->querySingle("SELECT id FROM collection WHERE hash = '$hash'");
    $time = CURRENT_TIMESTAMP;
    $fields = [];
    foreach ($_POST as $key => $value) {
        if ($key === 'secret') continue; // Skip secret field
        $fields[] = $key;
        $ds = validateDataset($key);
        if ($secret === 'testing' && $ds === 'testdata' && $value === '123') generateTestData($collectionId); // For testing purposes, generates a lot of random data
        $val = validateNumber($value);
        $db->exec("INSERT INTO dataset (collection_id, name) VALUES ($collectionId, '$ds') ON CONFLICT(collection_id, name) DO NOTHING");
        $datasetId = $db->querySingle("SELECT id FROM dataset WHERE collection_id = $collectionId AND name = '$ds'");
        $db->exec("INSERT INTO datapoint (dataset_id, timestamp, avg, resolution) VALUES ($datasetId, $time, $val, 'samples')");
        aggregateData($datasetId);
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
function aggregateData(int $datasetId): void {
    aggregateDatapoints("samples", "minutes", $datasetId);
    aggregateDatapoints("minutes", "quarters", $datasetId);
    aggregateDatapoints("quarters", "hours", $datasetId);
    aggregateDatapoints("hours", "days", $datasetId);
    aggregateDatapoints("days", "weeks", $datasetId);
    aggregateDatapoints("days", "months", $datasetId);
    aggregateDatapoints("months", "years", $datasetId);

    cleanupData($datasetId);
}

function cleanupData(int $datasetId): void {
    global $db;

    // Cleanup resolutions
    foreach (RESOLUTIONS as $resolution) {
        $sql = "DELETE FROM datapoint " .
            "WHERE dataset_id = $datasetId AND resolution = '$resolution' AND timestamp < (" .
            "SELECT MIN(timestamp) FROM (SELECT timestamp FROM datapoint " .
            "WHERE dataset_id = $datasetId AND resolution = '$resolution' " .
            "ORDER BY timestamp DESC LIMIT 1 OFFSET " . MAX_DATA_POINTS . "))";
        $db->exec($sql);
    }

    // Cleanup samples
    $cur_minute = getPeriodTimestamp(CURRENT_TIMESTAMP, 'minutes');
    $db->exec("DELETE FROM datapoint WHERE dataset_id = $datasetId AND resolution = 'samples' AND timestamp < $cur_minute");
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

function aggregateDatapoints(string $fromResolution, string $toResolution, int $datasetId): void {
    global $db;

    $cur_to_timestamp = getPeriodTimestamp(CURRENT_TIMESTAMP, $toResolution);

    $periodData = [];
    $sql = "SELECT timestamp, avg, COALESCE(min, avg) as min, COALESCE(max, avg) as max, COALESCE(last, avg) as last " .
        "FROM datapoint " .
        "WHERE dataset_id = $datasetId AND resolution = '$fromResolution' AND timestamp < $cur_to_timestamp " .
        "ORDER BY timestamp ASC";
    $result = $db->query($sql);
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $thisToPeriod = getPeriodTimestamp($row['timestamp'], $toResolution);
        $bucket = &$periodData[$thisToPeriod];
        if (!isset($bucket)) {
            $bucket = ['sum' => 0, 'count' => 0, 'min' => $row['min'], 'max' => $row['max'], 'last' => $row['last']];
        }
        $bucket['sum'] += $row['avg'];
        $bucket['count']++;
        $bucket['min'] = $row['min'] < $bucket['min'] ? $row['min'] : $bucket['min'];
        $bucket['max'] = $row['max'] > $bucket['max'] ? $row['max'] : $bucket['max'];
        $bucket['last'] = $row['last'];
        unset($bucket);
    }

    if ($periodData === []) return;  // No new data to aggregate

    // Convert and save aggregated data
    $rows = [];
    foreach ($periodData as $period => $data) {
        $avg  = $data['sum'] / $data['count'];
        $min  = $data['min'];
        $max  = $data['max'];
        $last = $data['last'];
        $rows[] = "($datasetId, $period, $avg, $min, $max, $last, '$toResolution')";
    }
    if (!empty($rows)) {
        $sql = "INSERT INTO datapoint (dataset_id, timestamp, avg, min, max, last, resolution) VALUES " . implode(',', $rows);
        $db->exec($sql);
    }
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

function getCollectionId(string $hash): int|false {
    global $db;
    $hash = validateHash($hash);
    $stmt = $db->prepare('SELECT id FROM collection WHERE hash = :hash LIMIT 1');
    $stmt->bindValue(':hash', $hash, SQLITE3_TEXT);
    $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return $result ? (int)$result['id'] : false;
}

// Data functions
function loadData(string $hash, string $dataset, string $resolution, string $aggregation = 'all', int $from = 0): array {
    $file = DATA_DIR . $hash . '_' . $dataset . '_' . $resolution . '.json';
    if (!file_exists($file)) return [];
    $data = file_get_json($file);

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

function getDatasetNames(int $collectionId): array {
    global $db;
    $stmt = $db->prepare("SELECT name FROM dataset WHERE collection_id = :id ORDER BY name ASC");
    $stmt->bindValue(':id', $collectionId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $names = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $names[] = $row['name'];
    }
    return $names;
}

function getResolution(array $period): string {
    $periodInMinutes = getPeriodInMinutes($period);
    $resolutionMinutes = ['minutes' => 1, 'quarters' => 15, 'hours' => 60, 'days' => 1440, 'weeks' => 10080, 'months' => 43680, 'years' => 524160];
    foreach ($resolutionMinutes as $res => $minutes) {
        if ($periodInMinutes <= $minutes * MAX_DATA_POINTS) {
            return $res;
        }
    }
    return 'years'; // Default to years if no suitable level is found
}

function generateGraphData(int $collectionId, array $period = ['1', 'hours'], array $datasets = []): string {
    global $db;
    $periodInMinutes = getPeriodInMinutes($period);
    $resolution = getResolution($period);

    $startFrom = time() - ($periodInMinutes * 60);
    $allData = [];
    $allTimestamps = [$startFrom]; // Initialize with startFrom to ensure the starting null

    // 1. Prepare Statement (Do this once outside the loop!)
    $stmt = $db->prepare("SELECT timestamp, avg, min, max, last FROM datapoint dp 
            JOIN dataset d ON dp.dataset_id = d.id 
            WHERE d.collection_id = :collId AND d.name = :name 
            AND dp.resolution = :res AND dp.timestamp >= :start 
            ORDER BY dp.timestamp ASC");

    $enabledDatasets = [];
    foreach ($datasets as $ds) {
        if (!($ds['enabled'] ?? false)) continue;

        $name = $ds['name'];
        $enabledDatasets[$name] = $ds;
        $allData[$name] = [];

        $stmt->bindValue(':collId', $collectionId, SQLITE3_INTEGER);
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':res', $resolution, SQLITE3_TEXT);
        $stmt->bindValue(':start', $startFrom, SQLITE3_INTEGER);

        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $val = $row[$ds['aggregation'] ?? 'avg'];
            $allData[$name][$row['timestamp']] = $val;
            $allTimestamps[] = $row['timestamp'];
        }

        // Ensure end point
        $now = time();
        $allTimestamps[] = $now;
    }

    if (empty($enabledDatasets)) {
        return "[[{type: 'datetime', label: 'Time'},{type: 'number', label: 'Data'}],[new Date(0), 0]]";
    }

    // 2. Clean and Sort Timestamps
    $uniqueTimestamps = array_unique($allTimestamps);
    sort($uniqueTimestamps);

    // 3. Build the JSON string
    $header = [['type' => 'datetime', 'label' => 'Time']];
    foreach ($enabledDatasets as $name => $ds) {
        $header[] = ['type' => 'number', 'label' => $name];
    }

    $rows = [];
    foreach ($uniqueTimestamps as $ts) {
        $rowStr = "[new Date(" . ($ts * 1000) . ")";
        foreach ($enabledDatasets as $name => $ds) {
            // If it's the exact startFrom and we have no data for this TS, it stays null
            $val = $allData[$name][$ts] ?? 'null';
            $rowStr .= ", " . $val;
        }
        $rowStr .= "]";
        $rows[] = $rowStr;
    }

    // Manual assembly of the outer array to handle the 'new Date()' non-JSON objects
    return "[" . json_encode($header) . "," . implode(",", $rows) . "]";
}

function generateTestData(int $collectionId): void {
    global $db;
    $datasets = ['testdata1', 'testdata2', 'testdata3'];
    $twoYearsAgo = CURRENT_TIMESTAMP - (2 * 365 * 24 * 60 * 60);

    foreach ($datasets as $dataset) {
        $db->exec("INSERT INTO dataset (collection_id, name) VALUES ($collectionId, '$dataset') ON CONFLICT(collection_id, name) DO NOTHING");
        $datasetId = $db->querySingle("SELECT id FROM dataset WHERE collection_id = $collectionId AND name = '$dataset'");
        $value = 0.0;
        $rows = [];
        for ($timestamp = $twoYearsAgo; $timestamp <= CURRENT_TIMESTAMP; $timestamp += 120) {
            $value += rand(-1000, 1000) / 1000;
            $rows[] = "($datasetId, $timestamp, $value, 'samples')";
        }

        if (!empty($rows)) {
            $sql = "INSERT INTO datapoint (dataset_id, timestamp, avg, resolution) VALUES " . implode(',', $rows);
            $db->exec($sql);
        }

        aggregateData($datasetId);
    }

    $hash = $db->querySingle("SELECT hash FROM collection WHERE id = $collectionId");
    redirect('?graphurl=' . getUrl($hash) . '&secret=testing&name1=testdata');
}

function file_get_json(string $jsonFileName): array {
    $contents = null;
    $fp = fopen($jsonFileName, 'r');
    if (flock($fp, LOCK_SH)) {
        $contents = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    } else {
        fclose($fp);
        response("Could not read file $jsonFileName", 'text/plain', 500);
    }
    $decoded = json_decode($contents, true);
    if ($decoded === null) {
        response("Could not decode JSON from file $jsonFileName", 'text/plain', 500);
    }
    return $decoded;
}

function updateMeta(string $key, string $hash, string $datasetName = ''): void {
    // Lock meta file
    $metaFile = DATA_DIR . 'meta.json';
    $fp = fopen($metaFile, 'c+');
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        response("Could not read meta file", 'text/plain', 500);
    }
    // Get meta
    $contents = stream_get_contents($fp);
    $meta = json_decode($contents, true) ?? [];
    // Merge meta
    $meta[$hash] = array_merge($meta[$hash] ?? ['lastAccessed' => '', 'lastModified' => ''], [$key => CURRENT_FORMATTED_DATETIME]);
    if (!empty($datasetName)) {
        $meta[$hash][$datasetName] = array_merge($meta[$hash][$datasetName] ?? ['lastAccessed' => '', 'lastModified' => ''], [$key => CURRENT_FORMATTED_DATETIME]);
    }
    // Save meta
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($meta, JSON_PRETTY_PRINT));
    fclose($fp);
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
