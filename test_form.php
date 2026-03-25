<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Form</title>
</head>
<body>
    <h1>Test Form Submission</h1>

    <?php
    // Display the result if redirected back with a value
    if (isset($_GET['result'])) {
        echo "<p>Received Value: " . htmlspecialchars($_GET['result'], ENT_QUOTES, 'UTF-8') . "</p>";
    }
    ?>


<form action="/includes/process_test.php" method="post">
    <label for="test">Test Value:</label>
    <input type="text" name="test" id="test" required>
    <br>
    <button type="submit">Submit</button>
</form>
</body>
</html>
