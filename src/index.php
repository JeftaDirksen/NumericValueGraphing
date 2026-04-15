<?php

// Init
ini_set('memory_limit', '512M');
define('SCHEME', $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? $_SERVER['REQUEST_SCHEME']);
define('HOST', $_SERVER['HTTP_HOST']);
define('METHOD', $_SERVER['REQUEST_METHOD']);
define('HTML', str_contains($_SERVER['HTTP_ACCEPT'], 'text/html'));
define('DATA_DIR', '/data/');
define('SECRET_PATTERN', '/^[A-Za-z0-9_-]{5,50}$/');
define('DATASET_PATTERN', '/^[A-Za-z0-9-]{1,15}$/');
define('MAX_DATA_POINTS', 250);

// Browser request
if (METHOD === 'GET' && HTML) {

    // Graph request
    if (isset($_GET['path'])) {
        $data['chartDataJson'] = generateGraphData();
        response_file('graph.php', $data);
    }

    // Show form
    else {
        response_file('form.php');
    }
}

// API request
if (METHOD === 'GET' && !HTML) {

    // Datasets request
    if (isset($_GET['path'])) {
        $hash = $_GET['path'];
        $datasets = getDatasets($hash);
        $response = ['datasets' => $datasets];
        response(json_encode($response, JSON_PRETTY_PRINT), 'application/json');
    }

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

    // Wrong URL
    if (isset($_GET['path'])) {
        response('Method not allowed on this endpoint', 'text/plain', 405);
    }

    // Store data
    $secret = $_POST['secret'] ?? '';
    $hash = validateSecret($secret);
    $time = time();
    $fields = [];
    foreach ($_POST as $key => $value) {
        if ($key === 'secret') continue; // Skip secret field
        $fields[] = $key;
        $ds = validateDataset($key);
        if ($secret === 'testing' && $ds === 'testdata' && $value === '123') generateTestData($hash); // For testing purposes, generates a lot of random data
        $val = validateNumber($value);
        $datasetSamplesFile = DATA_DIR . $hash . '_' . $ds . '_samples.txt';
        $sample = "$time:$val|";
        file_put_contents($datasetSamplesFile, $sample, FILE_APPEND | LOCK_EX);
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
        $response = [
            'url' => getUrl($hash),
            'datasets' => getDatasets($hash)
        ];
        response(json_encode($response, JSON_PRETTY_PRINT), 'application/json');
    }
}

response('Method not allowed', 'text/plain', 405);

function response($data = '', $contenttype = 'text/html', $status = 200): void {
    http_response_code($status);
    header("Content-Type: $contenttype");
    exit($data);
}

function response_file($filename, $data = []): void {
    ob_start();
    include $filename;
    $content = ob_get_clean();
    response($content);
}

function generateGraphData(): string {
    $hash = $_GET['path'];
    $period = $_GET['period'] ?? '1hour';
    $datasets = $_GET['datasets'] ?? getDatasets($hash);

    // Put variables in URL if not present
    if (!isset($_GET['period']) || !isset($_GET['datasets'])) {
        redirect($hash . '?period=' . $period . '&datasets=' . $datasets);
    }

    // Calculate smallest aggregation level that keeps the number of data points under MAX_DATA_POINTS
    $periodInMinutes = getPeriodInMinutes($period);
    $aggregationLevelsMinutes = ['minutes' => 1, 'quarters' => 15, 'hours' => 60, 'days' => 1440, 'weeks' => 10080, 'months' => 43680, 'years' => 524160];
    $aggregationLevel = 'years'; // Default to years if no suitable level is found
    foreach ($aggregationLevelsMinutes as $level => $minutes) {
        $points = $periodInMinutes / $minutes;
        if ($points <= MAX_DATA_POINTS) {
            $aggregationLevel = $level;
            break;
        }
    }

    // Iterate datasets and get data for each
    $data = [];
    foreach (explode(',', $datasets) as $dataset) {
        $data[$dataset] = getAggregatedData($hash, $dataset, $aggregationLevel, time() - $periodInMinutes * 60);
    }

    // No data to show
    if (empty($data)) {
        $chartDataJson = "[[{type: 'datetime', label: 'Time'},{type: 'number', label: 'Data'}],[new Date(0), 0]]";
    }

    // Convert data to format suitable for Google Charts with multiple datasets
    else {
        $chartDataJson = "[[{type: 'datetime', label: 'Time'}";
        foreach ($data as $dataset => $entries) {
            $chartDataJson .= ", {type: 'number', label: '$dataset'}";
        }
        $chartDataJson .= "]";

        // Collect all timestamps
        $timestamps = [];
        foreach ($data as $entries) {
            foreach ($entries as $entry) {
                $timestamps[$entry[0]] = true;
            }
        }
        ksort($timestamps);

        // Build rows with values for each dataset
        foreach (array_keys($timestamps) as $timestamp) {
            $chartDataJson .= ",[new Date(" . $timestamp * 1000 . ")";
            foreach ($data as $entries) {
                $value = null;
                foreach ($entries as $entry) {
                    if ($entry[0] == $timestamp) {
                        $value = $entry[1];
                        break;
                    }
                }
                $chartDataJson .= ", $value";
            }
            $chartDataJson .= "]";
        }
        $chartDataJson .= "]";
    }

    return $chartDataJson;
}

function aggregateData($hash, $dataset): void {
    aggregateSamples($hash, $dataset);
    aggregatePeriods("minutes", "quarters", $hash, $dataset);
    aggregatePeriods("quarters", "hours", $hash, $dataset);
    aggregatePeriods("hours", "days", $hash, $dataset);
    aggregatePeriods("days", "weeks", $hash, $dataset);
    aggregatePeriods("days", "months", $hash, $dataset);
    aggregatePeriods("months", "years", $hash, $dataset);

    // TODO: Cleanup old data
}

function aggregateSamples($hash, $dataset): void {

    $cur_time = time();
    $cur_minute = getPeriodTimestamp($cur_time, 'minutes');

    // Get samples for the dataset
    $samplesFile = DATA_DIR . $hash . '_' . $dataset . '_samples.txt';
    $samplesData = file_get_contents($samplesFile);
    $samplesArray = explode('|', trim($samplesData, '|'));
    $samples = array_map(function ($sample) {
        list($timestamp, $value) = explode(':', $sample);
        return [(int)$timestamp, (float)$value];
    }, $samplesArray);

    // Aggregate samples into minutes and store in a separate file
    $first_sample_minute = getPeriodTimestamp($samples[0][0], 'minutes');
    if ($first_sample_minute < $cur_minute) {
        $minute = $first_sample_minute;
        $count = 0;
        $sum = 0;
        $min = null;
        $max = null;
        $last = null;
        foreach ($samples as $sample) {
            $sample_minute = getPeriodTimestamp($sample[0], 'minutes');

            // If we've moved to the next minute, calculate and store the aggregate for the previous minute
            if ($sample_minute > $minute) {
                $avg = $count > 0 ? $sum / $count : 0;
                $aggMinutesFile = DATA_DIR . $hash . '_' . $dataset . '_minutes.txt';
                $minuteData = "$minute:$avg,$min,$max,$last|";
                file_put_contents($aggMinutesFile, $minuteData, FILE_APPEND | LOCK_EX);

                // Reset for the new minute
                $minute = $sample_minute;
                $count = 0;
                $sum = 0;
                $min = null;
                $max = null;
                $last = null;

                if ($minute >= $cur_minute) {
                    break; // Stop if we've reached the current minute
                }
            }

            // Aggregate data for the minute
            $count++;
            $sum += $sample[1];
            if ($min === null || $sample[1] < $min) {
                $min = $sample[1];
            }
            if ($max === null || $sample[1] > $max) {
                $max = $sample[1];
            }
            $last = $sample[1];
        }

        // Remove processed samples
        $remainingSamples = array_filter($samples, function ($sample) use ($cur_minute) {
            return $sample[0] >= $cur_minute;
        });

        // Save remaining samples back to file
        $datasetSamplesFile = DATA_DIR . $hash . '_' . $dataset . '_samples.txt';
        file_put_contents($datasetSamplesFile, implode('|', array_map(function ($sample) {
            return $sample[0] . ':' . $sample[1];
        }, $remainingSamples)) . '|', LOCK_EX);
    }
}

function aggregatePeriods($fromPeriod, $toPeriod, $hash, $dataset): void {
    // Get relevant data for aggregation
    $fromData = getAggregatedData($hash, $dataset, $fromPeriod);
    if (empty($fromData)) return;
    $toData = getAggregatedData($hash, $dataset, $toPeriod);

    // Determine the last period that has already been aggregated in the target period to avoid re-aggregating data
    $lastToDataPeriod = end($toData)[0] ?? 0;
    $currentToPeriod = getPeriodTimestamp(time(), $toPeriod);

    // Aggregate data
    $periodData = [];
    foreach ($fromData as $entry) {
        $thisToPeriod = getPeriodTimestamp($entry[0], $toPeriod);
        if ($thisToPeriod <= $lastToDataPeriod) continue; // Skip already aggregated periods
        if ($thisToPeriod >= $currentToPeriod) break; // Don't aggregate current period
        if (!isset($periodData[$thisToPeriod])) $periodData[$thisToPeriod] = ['sum' => 0, 'count' => 0, 'min' => null, 'max' => null, 'last' => null];
        $periodData[$thisToPeriod]['sum'] += $entry[1]; // avg value from lower period is used for aggregation
        $periodData[$thisToPeriod]['count']++;
        if ($periodData[$thisToPeriod]['min'] === null || $entry[2] < $periodData[$thisToPeriod]['min']) {
            $periodData[$thisToPeriod]['min'] = $entry[2];
        }
        if ($periodData[$thisToPeriod]['max'] === null || $entry[3] > $periodData[$thisToPeriod]['max']) {
            $periodData[$thisToPeriod]['max'] = $entry[3];
        }
        $periodData[$thisToPeriod]['last'] = $entry[4];
    }

    // Stop if there's no new data to aggregate
    if (empty($periodData)) return;

    // Write aggregated data to file
    $file = DATA_DIR . $hash . '_' . $dataset . '_' . $toPeriod . '.txt';
    $dataString = "";
    foreach ($periodData as $period => $data) {
        $avg = $data['count'] > 0 ? $data['sum'] / $data['count'] : 0;
        $periodDataString = "$period:$avg,{$data['min']},{$data['max']},{$data['last']}|";
        $dataString .= $periodDataString;
    }
    file_put_contents($file, $dataString, FILE_APPEND | LOCK_EX);
}

function generateTestData($hash): void {
    $datasets = ['testdata1', 'testdata2', 'testdata3'];
    foreach ($datasets as $dataset) {
        $datasetSamplesFile = DATA_DIR . $hash . '_' . $dataset . '_samples.txt';
        $now = time();
        $twoYearsAgo = $now - (2 * 365 * 24 * 60 * 60);
        $samples = [];
        $value = 0;
        for ($time = $twoYearsAgo; $time <= $now; $time += 120) { // Generate a sample every 2 minutes
            $samples[] = "$time:$value";
            $value += rand(-1 * 1000, 1 * 1000) / 1000; // Add some random noise to the value
        }
        file_put_contents($datasetSamplesFile, implode("|", $samples) . "|", LOCK_EX);
        aggregateData($hash, $dataset);
    }
    redirect('?graphurl=' . getUrl($hash) . '&secret=testing&name1=testdata');
}

function redirect($url): void {
    if (!str_starts_with($url, 'http')) {
        $url = SCHEME . '://' . HOST . '/' . ltrim($url, '/');
    }
    header('Location: ' . $url);
    exit('Redirecting to ' . $url);
}

function validateSecret($secret): string {
    if (empty($secret)) {
        response('Secret is required', 'text/plain', 400);
    }
    if (!preg_match(SECRET_PATTERN, $secret)) {
        response('Secret must be between 5 and 50 characters long and can only contain letters, numbers, underscores and dashes', 'text/plain', 400);
    }
    return hash('sha256', $secret . HOST);
}

function validateDataset($dataset): string {
    if (!preg_match(DATASET_PATTERN, $dataset)) {
        response('Dataset names must be between 1 and 15 characters long and can only contain letters, numbers and dashes', 'text/plain', 400);
    }
    return $dataset;
}

function validateNumber($number): float {
    if (!is_numeric($number)) {
        response("Not a valid number ($number)", 'text/plain', 400);
    }
    if (strlen($number) > 10) {
        response('Number must be less than 10 characters long', 'text/plain', 400);
    }
    return (float)$number;
}

function getUrl($hash = ''): string {
    return rtrim(SCHEME . '://' . HOST . '/' . $hash, '/');
}

function getAggregatedData($hash, $dataset, $aggregationLevel, $from = 0): array {
    $file = DATA_DIR . $hash . '_' . $dataset . '_' . $aggregationLevel . '.txt';
    if (!file_exists($file)) return [];
    $data = file_get_contents($file);
    $array = explode('|', trim($data, '|'));
    $data = array_map(function ($entry) {
        list($timestamp, $values) = explode(':', $entry);
        list($avg, $min, $max, $last) = explode(',', $values);
        return [(int)$timestamp, (float)$avg, (float)$min, (float)$max, (float)$last];
    }, $array);
    return array_filter($data, function ($entry) use ($from) {
        return $entry[0] >= $from;
    });
}

function getPeriodTimestamp($timestamp, $period): int {
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

function getPeriodInMinutes($period): int {
    // Split period into number and unit.
    // If the regex does not match, defaults to 1 hour (60 minutes).
    // If no number is specified, defaults to 1; if no unit is specified, defaults to hours.
    $period = strtolower(trim($period));
    $number = 1;
    $unit = 'hours';
    preg_match('/(\d+)(\w+)/', $period, $matches);
    if (isset($matches[1]) && isset($matches[2])) {
        $number = (int)$matches[1];
        $unit = $matches[2];
    } elseif (preg_match('/^(\d+)$/', $period, $matches)) {
        $number = (int)$matches[1];
    } elseif (preg_match('/^(\w+)$/', $period, $matches)) {
        $unit = $matches[1];
    } else {
        return 60; // Default to 1 hour if period is not recognized
    }

    switch ($unit) {
        case str_starts_with($unit, 'mi'):
            return $number * 1;
        case str_starts_with($unit, 'h'):
            return $number * 60;
        case str_starts_with($unit, 'd'):
            return $number * 1440;
        case str_starts_with($unit, 'w'):
            return $number * 10080;
        case str_starts_with($unit, 'mo'):
            return $number * 43200;
        case str_starts_with($unit, 'y'):
            return $number * 525600;
        default:
            return 60; // Default to 1 hour if period is not recognized
    }
}

function getSamples($hash, $dataset): array {
    $samplesFile = DATA_DIR . $hash . '_' . $dataset . '_samples.txt';
    if (!file_exists($samplesFile)) {
        return [];
    }
    $samplesData = file_get_contents($samplesFile);
    $samplesArray = explode('|', trim($samplesData, '|'));
    return array_map(function ($sample) {
        list($timestamp, $value) = explode(':', $sample);
        return [(int)$timestamp, (float)$value];
    }, $samplesArray);
}

function htmlUrl($url): string {
    return '<a href="' . $url . '" target="_blank">' . $url . '</a>';
}

function getDatasets($hash): string {
    // Iterate files in DATA_DIR and find the datasets for the given hash
    $datasets = [];
    foreach (glob(DATA_DIR . $hash . '_*_samples.txt') as $file) {
        $filename = basename($file);
        $parts = explode('_', $filename);
        $datasets[] = $parts[1];
    }
    return implode(',', $datasets);
}
