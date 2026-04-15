<!DOCTYPE html>
<html>

<head>
    <title>Numeric Value Graphing</title>
    <style>
        body {
            font-family: Verdana, Geneva, Tahoma, sans-serif;
            margin: 20px;
        }

        label {
            display: inline-block;
            margin-bottom: 10px;
            width: 100px;
        }

        input[type="text"] {
            padding: 5px;
        }

        textarea {
            resize: none;
        }

        input[type="submit"] {
            padding: 5px 15px;
        }

        .msg {
            margin-left: 15px;
        }

        .ok {
            color: green;
        }
    </style>
</head>

<body>
    <h1>Submit Data</h1>
    <span>You can also submit data using curl like:</span><br />
    <textarea rows="2" cols="100" readonly disabled>curl -d secret=MySecretString -d datasetA=1.0 -d datasetB=10.5 <?= getUrl() ?></textarea><br />
    <br />
    <form method="POST" action="/">
        <label>Secret:</label>
        <input type="text" id="secret" name="secret" value="<?php echo isset($_GET['secret']) ? htmlspecialchars($_GET['secret']) : ''; ?>" pattern="[A-Za-z0-9_\-]{5,50}" placeholder="MySecretString" required><br />

        <label>Dataset 1:</label>
        <input type="text" id="name1" name="name1" value="<?php echo isset($_GET['name1']) ? htmlspecialchars($_GET['name1']) : ''; ?>" pattern="[A-Za-z0-9_\-]{1,15}" placeholder="name"> =
        <input type="number" id="value1" name="value1" step="any" pattern="[0-9.]{1,10}" placeholder="value"><br />

        <label>Dataset 2:</label>
        <input type="text" id="name2" name="name2" value="<?php echo isset($_GET['name2']) ? htmlspecialchars($_GET['name2']) : ''; ?>" pattern="[A-Za-z0-9_\-]{1,15}" placeholder="name"> =
        <input type="number" id="value2" name="value2" step="any" pattern="[0-9.]{1,10}" placeholder="value"><br />

        <label>Dataset 3:</label>
        <input type="text" id="name3" name="name3" value="<?php echo isset($_GET['name3']) ? htmlspecialchars($_GET['name3']) : ''; ?>" pattern="[A-Za-z0-9_\-]{1,15}" placeholder="name"> =
        <input type="number" id="value3" name="value3" step="any" pattern="[0-9.]{1,10}" placeholder="value"><br />

        <label>Dataset 4:</label>
        <input type="text" id="name4" name="name4" value="<?php echo isset($_GET['name4']) ? htmlspecialchars($_GET['name4']) : ''; ?>" pattern="[A-Za-z0-9_\-]{1,15}" placeholder="name"> =
        <input type="number" id="value4" name="value4" step="any" pattern="[0-9.]{1,10}" placeholder="value"><br />

        <label>Dataset 5:</label>
        <input type="text" id="name5" name="name5" value="<?php echo isset($_GET['name5']) ? htmlspecialchars($_GET['name5']) : ''; ?>" pattern="[A-Za-z0-9_\-]{1,15}" placeholder="name"> =
        <input type="number" id="value5" name="value5" step="any" pattern="[0-9.]{1,10}" placeholder="value"><br />

        <input type="submit" value="Submit"> <span class="msg ok"><?php echo isset($_GET['graphurl']) ? 'OK - Graph URL: ' . htmlUrl($_GET['graphurl']) : ''; ?></span>
    </form>

    <script>
        const form = document.querySelector('form');

        form.addEventListener('submit', function(event) {
            const name1 = document.getElementById('name1');
            const value1 = document.getElementById('value1');
            value1.name = name1.value.trim();
            name1.disabled = true;
            if (value1.value.trim() === '') value1.disabled = true;

            const name2 = document.getElementById('name2');
            const value2 = document.getElementById('value2');
            value2.name = name2.value.trim();
            name2.disabled = true;
            if (value2.value.trim() === '') value2.disabled = true;

            const name3 = document.getElementById('name3');
            const value3 = document.getElementById('value3');
            value3.name = name3.value.trim();
            name3.disabled = true;
            if (value3.value.trim() === '') value3.disabled = true;

            const name4 = document.getElementById('name4');
            const value4 = document.getElementById('value4');
            value4.name = name4.value.trim();
            name4.disabled = true;
            if (value4.value.trim() === '') value4.disabled = true;

            const name5 = document.getElementById('name5');
            const value5 = document.getElementById('value5');
            value5.name = name5.value.trim();
            name5.disabled = true;
            if (value5.value.trim() === '') value5.disabled = true;
        });
    </script>
</body>

</html>