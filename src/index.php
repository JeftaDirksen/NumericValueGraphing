<?php

// Init
define('SCHEME', $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? $_SERVER['REQUEST_SCHEME']);
define('HOST', $_SERVER['HTTP_HOST']);
define('SECRET_PATTERN', '/^[A-Za-z0-9_-]{5,50}$/');
define('DATASET_PATTERN', '/^[A-Za-z0-9-]{1,10}$/');
header('Content-Type: text/plain');

switch ($_SERVER['REQUEST_METHOD']) {

    case 'GET':

    case 'POST':
        if (isset($_GET['path'])) {
            http_response_code(405);
            exit('Method not allowed on this endpoint');
        }

        $hash = validateSecret($_POST['secret'] ?? '');

        // Iterate datasets (POST fields)
        foreach ($_POST as $key => $value) {
            if ($key === 'secret') continue; // Skip secret field
            validateDataset($key);
            validateNumber($value);
            $datasetSamplesFile = '/data/' . $hash . '_' . $key . '_samples.txt';
            $sample = time() . ':' . $value . '|';
            file_put_contents($datasetSamplesFile, $sample, FILE_APPEND | LOCK_EX);
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

function validateDataset($dataset): void {
    if (!preg_match(DATASET_PATTERN, $dataset)) {
        http_response_code(400);
        exit('Dataset names must be between 1 and 10 characters long and can only contain letters, numbers and dashes');
    }
}

function validateNumber($number): void {
    if (!is_numeric($number)) {
        http_response_code(400);
        exit('Not a valid number');
    }
    if (strlen($number) > 10) {
        http_response_code(400);
        exit('Number must be less than 10 characters long');
    }
}

function getUrl($hash): string {
    return SCHEME . '://' . HOST . '/' . $hash;
}
