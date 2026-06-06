<?php

// Database connection details
$dbhost = 'localhost';
$dbuser = '***REMOVED-21***';
$dbpass = '***REMOVED-21***';
$dbname = '***DB_NAME_REMOVED***';

// Connect to the database
$mysqli = new mysqli($dbhost, $dbuser, $dbpass, $dbname);

// Check connection
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}

?>
