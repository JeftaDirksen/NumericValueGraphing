<?php

/** @var array $data */
?>
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
                interpolateNulls: true,
                lineWidth: 2,
                legend: {
                    position: 'bottom'
                },
                chartArea: {
                    left: 60,
                    top: 60,
                    width: '90%',
                    height: '75%'
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

        function beforeSubmit() {
            // Disable hidden checkboxes when the visible checkbox is checked
            var form = document.querySelector('.submenu form');
            var checkboxes = form.querySelectorAll('input[type="checkbox"][id^="de"]');
            checkboxes.forEach(function(checkbox) {
                var hiddenInput = document.getElementById(checkbox.id + 'h');
                if (checkbox.checked) {
                    hiddenInput.disabled = true;
                } else {
                    hiddenInput.disabled = false;
                }
            });

            if (document.getElementById('hm').checked) {
                document.getElementById('hmh').disabled = true;
            } else {
                document.getElementById('hmh').disabled = false;
            }

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

        .submenu table {
            border-collapse: collapse;
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

        .submenu h1,
        .submenu h2,
        .submenu label,
        .submenu input,
        .submenu select,
        .submenu input[type="submit"],
        .submenu input[type="button"] {
            margin: 5px;
        }

        .submenu h1 {
            font-size: medium;
            font-weight: bold;
            display: inline-block;
        }

        .submenu h2 {
            font-size: small;
            font-weight: bold;
            display: inline-block;
        }
    </style>
</head>

<body>
    <div style="position: relative;">
        <div id="curve_chart" style="width: 100%; max-width: 1200px; height: 600px;"></div>
        <div class="submenu-button"><a href="#" onclick="getElementById('submenu').style.display = 'block'; return false;">☰</a></div>
        <div class="submenu" id="submenu">
            <form>
                <h1>Settings</h1> <a href="#" onclick="getElementById('submenu').style.display = 'none'; return false;">✖</a><br>
                <h2 title="Lookback period">Period</h2>
                <input type="number" name="pn" value="<?= $data['pn'] ?>" min="1" max="365" title="Lookback period">
                <select name="pu" title="Lookback period">
                    <option value="minutes" <?= $data['pu'] === 'minutes' ? 'selected' : '' ?>>Minute(s)</option>
                    <option value="hours" <?= $data['pu'] === 'hours' ? 'selected' : '' ?>>Hour(s)</option>
                    <option value="days" <?= $data['pu'] === 'days' ? 'selected' : '' ?>>Day(s)</option>
                    <option value="weeks" <?= $data['pu'] === 'weeks' ? 'selected' : '' ?>>Week(s)</option>
                    <option value="months" <?= $data['pu'] === 'months' ? 'selected' : '' ?>>Month(s)</option>
                    <option value="years" <?= $data['pu'] === 'years' ? 'selected' : '' ?>>Year(s)</option>
                </select><br>
                <h2>Datasets</h2>
                <table>
                    <?php foreach ($data['datasets'] as $key => $dataset): ?>
                        <tr>
                            <td>
                                <input type="hidden" id="de<?= $key + 1 ?>h" name="de<?= $key + 1 ?>" value="0">
                                <input type="checkbox" id="de<?= $key + 1 ?>" name="de<?= $key + 1 ?>" value="1" <?= $dataset['enabled'] ? 'checked' : '' ?> title="Show/hide dataset">
                            </td>
                            <td>
                                <label for="de<?= $key + 1 ?>" title="Dataset name"><?= $dataset['name'] ?></label>
                            </td>
                            <td>
                                <select name="da<?= $key + 1 ?>" title="Aggregation type">
                                    <option value="avg" <?= $dataset['aggregation'] === 'avg' ? 'selected' : '' ?>>Average</option>
                                    <option value="min" <?= $dataset['aggregation'] === 'min' ? 'selected' : '' ?>>Minimum</option>
                                    <option value="max" <?= $dataset['aggregation'] === 'max' ? 'selected' : '' ?>>Maximum</option>
                                    <option value="last" <?= $dataset['aggregation'] === 'last' ? 'selected' : '' ?>>Last</option>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
                <input type="submit" value="Apply" onclick="beforeSubmit()">
                <input type="hidden" id="hmh" name="hm" value="0">
                <span class="hm" title="Hide menu button (use browser back button to show again)">
                    <input type="checkbox" id="hm" name="hm" value="1" <?= $data['hm'] ? 'checked' : '' ?>>
                    Hide menu button
                </span>
            </form>
        </div>
    </div>
</body>

</html>