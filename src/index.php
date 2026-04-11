<?php

// Init
define('SCHEME', $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? $_SERVER['REQUEST_SCHEME']);
define('HOST', $_SERVER['HTTP_HOST']);
define('DATA_DIR', '/data/');
define('SECRET_PATTERN', '/^[A-Za-z0-9_-]{5,50}$/');
define('DATASET_PATTERN', '/^[A-Za-z0-9-]{1,10}$/');

switch ($_SERVER['REQUEST_METHOD']) {

    case 'GET':
        if (!isset($_GET['path'])) {
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
            $val = validateNumber($value);
            $datasetSamplesFile = DATA_DIR . $hash . '_' . $ds . '_samples.txt';
            $sample = "$time:$val|";
            file_put_contents($datasetSamplesFile, $sample, FILE_APPEND | LOCK_EX);
            aggregateData($hash, $ds);
        }

        if (isset($_POST['_redirect'])) {
            header('Location: ' . SCHEME . '://' . HOST . '/?queryurl=' . getUrl($hash) . '&secret=' . $secret . '&name1=' . $fields[0] . '&name2=' . ($fields[1] ?? '') . '&name3=' . ($fields[2] ?? '') . '&name4=' . ($fields[3] ?? '') . '&name5=' . ($fields[4] ?? ''));
            exit();
        }
        exit(getUrl($hash));

    default:
        http_response_code(405);
        exit('Method not allowed');
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

function aggregateData($hash, $dataset) {
    $cur_time = time();
    $cur_minute = getMinute($cur_time);

    $samples = getSamples($hash, $dataset);

    $first_sample_minute = getMinute($samples[0][0]);
    if ($first_sample_minute < $cur_minute) {
        $minute = $first_sample_minute;
        $count = 0;
        $sum = 0;
        $min = null;
        $max = null;
        $last = null;
        foreach ($samples as $sample) {
            $sample_minute = getMinute($sample[0]);

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
        saveSamples($hash, $dataset, $remainingSamples);
    }
}

function getMinute($timestamp): int {
    return floor($timestamp / 60) * 60;
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

function saveSamples($hash, $dataset, $samples) {
    $datasetSamplesFile = DATA_DIR . $hash . '_' . $dataset . '_samples.txt';
    file_put_contents($datasetSamplesFile, implode('|', array_map(function ($sample) {
        return $sample[0] . ':' . $sample[1];
    }, $samples)) . '|', LOCK_EX);
}

function htmlUrl($url): string {
    return '<a href="' . $url . '" target="_blank">' . $url . '</a>';
}
