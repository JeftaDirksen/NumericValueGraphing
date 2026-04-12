<?php

// Config
define('MAX_DATA_POINTS', 250);

// Init
$hash = $_GET['path'];
$period = $_GET['period'] ?? '1hour';
$dataset = $_GET['dataset'] ?? 'test';
if (!isset($_GET['period']) || !isset($_GET['dataset'])) redirect($hash . '?period=' . $period . '&dataset=' . $dataset);

$periodInMinutes = getPeriodInMinutes($period);

// Calculate smallest aggregation level that keeps the number of data points under MAX_DATA_POINTS
$aggregationLevelsMinutes = ['minutes' => 1, 'hours' => 60, 'days' => 1440, 'weeks' => 10080, 'months' => 43200, 'years' => 525600];
$aggregationLevel = 'years'; // Default to years if no suitable level is found
foreach ($aggregationLevelsMinutes as $level => $minutes) {
    $points = $periodInMinutes / $minutes;
    if ($points <= MAX_DATA_POINTS) {
        $aggregationLevel = $level;
        break;
    }
}
//echo "Using aggregation level: $aggregationLevel for period: $period ($periodInMinutes minutes) with approximately " . round($periodInMinutes / $aggregationLevelsMinutes[$aggregationLevel]) . " data points.<br>";

// Get data for the graph
$data = getAggregatedData($hash, "test", $aggregationLevel);
if (empty($data)) {
    $chartData = [['Time', 'Value'], ['No data', 0]];
} else {
    // Convert data to format suitable for Google Charts
    $chartDataJson = "[[{type: 'datetime', label: 'Time'}, {type: 'number', label: 'Value'}],";
    foreach ($data as $entry) {
        $datetime = 'new Date(' . $entry[0] * 1000 . ')';
        $chartDataJson .= "[$datetime, {$entry[1]}],";
    }
    $chartDataJson = rtrim($chartDataJson, ',') . ']';
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

?>
<html>

<head>
    <script type="text/javascript" src="https://www.gstatic.com/charts/loader.js"></script>
    <script type="text/javascript">
        google.charts.load('current', {
            'packages': ['corechart']
        });
        google.charts.setOnLoadCallback(drawChart);

        function drawChart() {
            var data = google.visualization.arrayToDataTable(<?= $chartDataJson ?>);
            var options = {
                title: 'Numeric Value Graph',
                curveType: 'function',
                legend: {
                    position: 'bottom'
                }
            };

            var chart = new google.visualization.LineChart(document.getElementById('curve_chart'));

            chart.draw(data, options);
        }
    </script>
</head>

<body>
    <div id="curve_chart" style="width: 900px; height: 500px"></div>
</body>

</html>