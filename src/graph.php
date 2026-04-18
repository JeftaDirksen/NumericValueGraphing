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
            var options = {
                title: 'Numeric Value Graph',
                curveType: 'function',
                interpolateNulls: true,
                lineWidth: 2,
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
    <div id="curve_chart" style="width: 1200px; height: 600px"></div>
</body>

</html>