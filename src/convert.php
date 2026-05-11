<?php

// Iterate all data files (filename format: hash_dataset_resolution.json) and convert to SQLite database
$dataFiles = glob(DATA_DIR . '*_*_*.json');
foreach ($dataFiles as $file) {
    $filename = basename($file, '.json');
    list($hash, $dataset, $resolution) = explode('_', $filename);
    $jsonData = json_decode(file_get_contents($file), true);
    if ($jsonData) {
        $collectionId = createCollection($hash, '');
        $datasetId = createDataset($collectionId, $dataset);

        if ($resolution == 'samples') {
            foreach ($jsonData as $entry) {
                $timestamp = $entry[0];
                $value = $entry[1];
                $db->exec("INSERT INTO datapoint (dataset_id, timestamp, avg, resolution) VALUES ('$datasetId', '$timestamp', '$value', '$resolution')");
            }
        } else {
            foreach ($jsonData as $entry) {
                $timestamp = $entry[0];
                $avg = $entry[1];
                $min = $entry[2];
                $max = $entry[3];
                $last = $entry[4];
                $db->exec("INSERT INTO datapoint (dataset_id, timestamp, avg, min, max, last, resolution) VALUES ('$datasetId', '$timestamp', '$avg', '$min', '$max', '$last', '$resolution')");
            }
        }
    }
    unlink($file);
}

// Convert old JSON data to SQLite database
$jsonData = json_decode(file_get_contents(DATA_DIR . 'config.json'), true);
if ($jsonData) {
    foreach ($jsonData as $key => $value) {
        $db->exec("INSERT OR REPLACE INTO config (key, value) VALUES ('$key', '$value')");
    }
}
unlink(DATA_DIR . 'config.json');

unlink(DATA_DIR . 'meta.json');
