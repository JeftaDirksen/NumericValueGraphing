<?php

// Init
ini_set('memory_limit', '512M');
define('SCHEME', $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? $_SERVER['REQUEST_SCHEME']);
define('HOST', $_SERVER['HTTP_HOST']);
define('DATA_DIR', '/data/');
define('SECRET_PATTERN', '/^[A-Za-z0-9_-]{5,50}$/');
define('DATASET_PATTERN', '/^[A-Za-z0-9-]{1,15}$/');

switch ($_SERVER['REQUEST_METHOD']) {

    case 'GET':
        if (isset($_GET['path'])) {
            include 'graph.php';
            exit();
        } else {
            include 'form.php';
            exit();
        }
        break;

    case 'POST':
        if (isset($_GET['path'])) {
            http_response_code(405);
            exit('Method not allowed on this endpoint');
        }

        $secret = $_POST['secret'] ?? '';
        $hash = validateSecret($secret);
        $time = time();

        // Iterate datasets (POST fields)
        $fields = [];
        foreach ($_POST as $key => $value) {
            if ($key === 'secret') continue; // Skip secret field
            if (str_starts_with($key, '_')) continue; // Skip if key starts with _ (e.g. _redirect)
            $fields[] = $key;
            $ds = validateDataset($key);
            if ($secret === 'testing' && $ds === 'testdata' && $value === '123') generateTestData($hash); // For testing purposes, generates a lot of random data
            $val = validateNumber($value);
            $datasetSamplesFile = DATA_DIR . $hash . '_' . $ds . '_samples.txt';
            $sample = "$time:$val|";
            file_put_contents($datasetSamplesFile, $sample, FILE_APPEND | LOCK_EX);
            aggregateData($hash, $ds);
        }

        if (isset($_POST['_redirect'])) {
            redirect('?graphurl=' . getUrl($hash) . '&secret=' . $secret . '&name1=' . ($fields[0] ?? '') . '&name2=' . ($fields[1] ?? '') . '&name3=' . ($fields[2] ?? '') . '&name4=' . ($fields[3] ?? '') . '&name5=' . ($fields[4] ?? ''));
        }
        exit(getUrl($hash));

    default:
        http_response_code(405);
        exit('Method not allowed');
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
    exit();
}

function validateSecret($secret): string {
    if (empty($secret)) {
        http_response_code(400);
        exit('Secret is required');
    }
    if (!preg_match(SECRET_PATTERN, $secret)) {
        http_response_code(400);
        exit('Secret must be between 5 and 50 characters long and can only contain letters, numbers, underscores and dashes');
    }
    return hash('sha256', $secret . HOST);
}

function validateDataset($dataset): string {
    if (!preg_match(DATASET_PATTERN, $dataset)) {
        http_response_code(400);
        exit('Dataset names must be between 1 and 10 characters long and can only contain letters, numbers and dashes');
    }
    return $dataset;
}

function validateNumber($number): float {
    if (!is_numeric($number)) {
        http_response_code(400);
        exit('Not a valid number');
    }
    if (strlen($number) > 10) {
        http_response_code(400);
        exit('Number must be less than 10 characters long');
    }
    return (float)$number;
}

function getUrl($hash): string {
    return SCHEME . '://' . HOST . '/' . $hash;
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
