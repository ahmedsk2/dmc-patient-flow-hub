<?php
require_once __DIR__ . '/guard.php'; require_role([0]);

require 'dbconnect.php';

$year = $_POST['selected_year'];
$type = $_POST['patient_type'];

if (!in_array($type, ['ICU', 'Non-ICU']) || !is_numeric($year)) {
    die("Invalid input");
}

$condition = ($type === 'ICU')
    ? "current_location = 'ICU'"
    : "(current_location IS NULL OR current_location != 'ICU')";

// Query: all fields from the database
$sql = "
    SELECT 
        ID, MRN, age, gender, nationality, admissiondiagnosis,
        ADMFROM, ADMDATE, med_DISDATE, DISDATE, delay, longterm,
        trans_discharge, DISTO, MORTALITY,
        consultant_id, current_location
    FROM picupatients
    WHERE YEAR(ADMDATE) = ? AND $condition
";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $year);
$stmt->execute();
$result = $stmt->get_result();

// Prepare file name
$filename = "{$type}_patients_{$year}.xls";

// Output headers for .xls download
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

// Start Excel-compatible HTML table
echo "<table border='1'>";
echo "<tr>
        <th>Admission ID</th><th>MRN</th><th>Age</th><th>Gender</th><th>Nationality</th>
        <th>Admission Diagnosis</th><th>Admitted From</th><th>Admission Date</th>
        <th>Medical Discharge Date</th><th>Discharge Date</th><th>Any Delay in Discharge</th>
        <th>Is Long Term Patient?</th> <th>Transfer / Discharge Status</th><th>Discharged / Tranferred To</th>
       <th>Mortality Status</th>
        <th>Consultant ID</th><th>Current Location</th>
        </tr>";

// Output each row
while ($row = $result->fetch_assoc()) {
    echo "<tr>";
    foreach ($row as $value) {
        echo "<td>" . htmlspecialchars($value) . "</td>";
    }
    echo "</tr>";
}
echo "</table>";
exit;
