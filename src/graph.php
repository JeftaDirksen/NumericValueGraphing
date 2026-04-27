<!doctype html>
<html>

<head>
    <title><?= $data['title'] ?></title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <script type="text/javascript" src="https://www.gstatic.com/charts/loader.js"></script>
    <script type="text/javascript">
        google.charts.load('current', {
            'packages': ['corechart']
        });
        google.charts.setOnLoadCallback(drawChart);

        function drawChart() {
            var data = google.visualization.arrayToDataTable(<?= $data['chartDataJson'] ?>);

            // Get the actual min/max from the data
            var dateRange = data.getColumnRange(0);

            var options = {
                title: 'Numeric Value Graph',
                curveType: 'function',
                interpolateNulls: true,
                lineWidth: 2,
                legend: {
                    position: 'bottom'
                },
                hAxis: {
                    viewWindow: {
                        min: dateRange.min,
                        max: dateRange.max
                    }
                }
            };

            var chart = new google.visualization.LineChart(document.getElementById('curve_chart'));

            chart.draw(data, options);
        }
    </script>
    <style>
        body {
            font-family: Arial, sans-serif;
        }

        .submenu-button {
            position: absolute;
            top: 0;
            left: 0;
            padding: 5px 10px;
            cursor: pointer;
            visibility: <?= $data['hm'] ? 'hidden' : 'visible' ?>;
        }

        .submenu-button a {
            text-decoration: none;
            font-size: 24px;
            color: #333;
        }

        .submenu {
            display: none;
            position: absolute;
            top: 10px;
            left: 10px;
            border: 1px solid #ccc;
            background: #fdfdfd;
            padding: 10px;
            z-index: 1000;
            white-space: nowrap;
        }

        .submenu a {
            text-decoration: none;
            font-size: 18px;
            color: #333;
            float: right;
            top: -5px;
            left: 2px;
            position: relative;
        }

        .submenu .hm {
            float: right;
            position: relative;
            font-size: smaller;
            margin-top: 10px;
        }

        .submenu label,
        .submenu input,
        .submenu select,
        .submenu input[type="submit"],
        .submenu input[type="button"] {
            margin: 5px;
        }

        .submenu label {
            font-size: small;
            font-weight: bold;
        }
    </style>
</head>

<body>
    <div style="position: relative;">
        <div id="curve_chart" style="width: 1200px; height: 600px"></div>
        <div class="submenu-button"><a href="#" onclick="getElementById('submenu').style.display = 'block'; return false;">☰</a></div>
        <div class="submenu" id="submenu">
            <form>
                <b>Settings</b> <a href="#" onclick="getElementById('submenu').style.display = 'none'; return false;">✖</a><br>
                <label title="Lookback period">Period</label>
                <input type="number" name="pn" value="<?= $data['pn'] ?>" min="1" max="365" title="Lookback period">
                <select name="pu" title="Lookback period">
                    <option value="minutes" <?= $data['pu'] === 'minutes' ? 'selected' : '' ?>>Minute(s)</option>
                    <option value="hours" <?= $data['pu'] === 'hours' ? 'selected' : '' ?>>Hour(s)</option>
                    <option value="days" <?= $data['pu'] === 'days' ? 'selected' : '' ?>>Day(s)</option>
                    <option value="weeks" <?= $data['pu'] === 'weeks' ? 'selected' : '' ?>>Week(s)</option>
                    <option value="months" <?= $data['pu'] === 'months' ? 'selected' : '' ?>>Month(s)</option>
                    <option value="years" <?= $data['pu'] === 'years' ? 'selected' : '' ?>>Year(s)</option>
                </select><br>
                <label>Datasets</label><br>
                <?php foreach ($data['datasets'] as $key => $dataset): ?>
                    <input type="hidden" name="de<?= $key + 1 ?>" value="0">
                    <input type="checkbox" name="de<?= $key + 1 ?>" value="1" <?= $dataset['enabled'] ? 'checked' : '' ?> title="Show/hide dataset">
                    <span title="Dataset name"><?= $dataset['name'] ?></span>
                    <select name="da<?= $key + 1 ?>" title="Aggregation type">
                        <option value="avg" <?= $dataset['aggregation'] === 'avg' ? 'selected' : '' ?>>Average</option>
                        <option value="min" <?= $dataset['aggregation'] === 'min' ? 'selected' : '' ?>>Minimum</option>
                        <option value="max" <?= $dataset['aggregation'] === 'max' ? 'selected' : '' ?>>Maximum</option>
                        <option value="last" <?= $dataset['aggregation'] === 'last' ? 'selected' : '' ?>>Last</option>
                    </select><br>
                <?php endforeach; ?>
                <input type="submit" value="Apply">
                <input type="hidden" name="hm" value="0">
                <span class="hm" title="Hide menu button (use browser back button to show again)">
                    <input type="checkbox" name="hm" value="1" <?= $data['hm'] ? 'checked' : '' ?>>
                    Hide menu button
                </span>
            </form>
        </div>
    </div>
</body>

</html>