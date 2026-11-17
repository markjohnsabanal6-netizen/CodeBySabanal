
<?php

date_default_timezone_set('Asia/Manila');

require_once 'config/database.php';

echo "<h2>Detailed Date and Time Diagnostic</h2>";

echo "<h3>PHP Information</h3>";
echo "Timezone: " . date_default_timezone_get() . "<br>";
echo "PHP Date and Time: " . date('Y-m-d H:i:s') . "<br>";
echo "PHP Timezone Offset: " . date('P') . "<br>";
echo "PHP UTC Time: " . gmdate('Y-m-d H:i:s') . "<br>";
echo "PHP Unix Timestamp: " . time() . "<br>";
echo "PHP Default Setting: " . ini_get('date.timezone') . "<br>";

echo "<hr>";

echo "<h3>MySQL Information</h3>";

$result = $conn->query("
    SELECT
        NOW() AS mysql_now,
        CURDATE() AS mysql_date,
        UTC_TIMESTAMP() AS utc_now,
        @@session.time_zone AS session_timezone,
        @@global.time_zone AS global_timezone,
        @@system_time_zone AS system_timezone
")->fetch(PDO::FETCH_ASSOC);

foreach ($result as $key => $value) {
    echo htmlspecialchars($key) . ": ";
    echo htmlspecialchars((string)$value) . "<br>";
}

echo "<hr>";

echo "<h3>Connection Check</h3>";
echo "MySQL Connection: " . ($conn instanceof PDO ? "Connected" : "Not Connected");

?>
