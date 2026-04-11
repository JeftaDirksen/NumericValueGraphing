<?php

?>
<!DOCTYPE html>
<html>

<head>
    <title>Submit Data</title>
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
    <form method="POST" action="/">
        <label>Secret:</label>
        <input type="text" id="secret" name="secret" value="<?php echo isset($_GET['secret']) ? htmlspecialchars($_GET['secret']) : ''; ?>" pattern="[A-Za-z0-9_-]{5,50}" placeholder="MySecretString!" required><br />

        <label>Dataset 1:</label>
        <input type="text" id="name1" name="name1" value="<?php echo isset($_GET['name1']) ? htmlspecialchars($_GET['name1']) : ''; ?>" pattern="[A-Za-z0-9-]{1,10}" placeholder="name" required> =
        <input type="number" id="value1" name="value1" step="any" pattern="[0-9.]{1,10}" placeholder="value" required><br />

        <label>Dataset 2:</label>
        <input type="text" id="name2" name="name2" value="<?php echo isset($_GET['name2']) ? htmlspecialchars($_GET['name2']) : ''; ?>" pattern="[A-Za-z0-9-]{1,10}" placeholder="name"> =
        <input type="number" id="value2" name="value2" step="any" pattern="[0-9.]{1,10}" placeholder="value"><br />

        <label>Dataset 3:</label>
        <input type="text" id="name3" name="name3" value="<?php echo isset($_GET['name3']) ? htmlspecialchars($_GET['name3']) : ''; ?>" pattern="[A-Za-z0-9-]{1,10}" placeholder="name"> =
        <input type="number" id="value3" name="value3" step="any" pattern="[0-9.]{1,10}" placeholder="value"><br />

        <label>Dataset 4:</label>
        <input type="text" id="name4" name="name4" value="<?php echo isset($_GET['name4']) ? htmlspecialchars($_GET['name4']) : ''; ?>" pattern="[A-Za-z0-9-]{1,10}" placeholder="name"> =
        <input type="number" id="value4" name="value4" step="any" pattern="[0-9.]{1,10}" placeholder="value"><br />

        <label>Dataset 5:</label>
        <input type="text" id="name5" name="name5" value="<?php echo isset($_GET['name5']) ? htmlspecialchars($_GET['name5']) : ''; ?>" pattern="[A-Za-z0-9-]{1,10}" placeholder="name"> =
        <input type="number" id="value5" name="value5" step="any" pattern="[0-9.]{1,10}" placeholder="value"><br />

        <input type="hidden" name="_redirect" value="1">
        <input type="submit" value="Submit"> <span class="msg ok"><?php echo isset($_GET['queryurl']) ? 'OK - Query url: ' . htmlUrl($_GET['queryurl']) : ''; ?></span>
    </form>

    <script>
        const form = document.querySelector('form');

        form.addEventListener('submit', function(event) {
            const name1 = document.getElementById('name1');
            document.getElementById('value1').name = name1.value.trim();
            name1.disabled = true;

            const name2 = document.getElementById('name2');
            document.getElementById('value2').name = name2.value.trim();
            name2.disabled = true;

            const name3 = document.getElementById('name3');
            document.getElementById('value3').name = name3.value.trim();
            name3.disabled = true;

            const name4 = document.getElementById('name4');
            document.getElementById('value4').name = name4.value.trim();
            name4.disabled = true;

            const name5 = document.getElementById('name5');
            document.getElementById('value5').name = name5.value.trim();
            name5.disabled = true;
        });
    </script>
</body>

</html>