<?php 
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once ('../dbconnect.php');

// Ensure all inputs are set and use default values if not
$id = $_REQUEST['id'] ?? '';
$bed_modify = $_REQUEST['bed_modify'] ?? '';
$mrn_modify = trim($_REQUEST['mrn_modify'] ?? '');
$pname_modify = $_REQUEST['pname_modify'] ?? '';
$age_modify = $_REQUEST['age_modify'] ?? '';
$current_location_modify = $_REQUEST['current_location_modify'] ?? '';
$other_indication_modify = $_REQUEST['other_indication_modify'] ?? '';
$indication_modify1 = $_REQUEST['indication_modify'] ?? [];
$indication_modify = json_encode($indication_modify1);

// Log the received data for debugging
error_log("Received data: " . print_r($_REQUEST, true));

// Verify data before processing
if (empty($id) || empty($bed_modify) || empty($mrn_modify) || empty($pname_modify) || empty($age_modify) || empty($current_location_modify) || empty($indication_modify)) {
    error_log("Missing required fields");
    echo "Missing required fields";
    exit();
}

$sql = "UPDATE consultations SET 
    MRN = ?,
    current_location = ?,
    BED = ?,
    PNAME = ?,
    age = ?,
    indication = ?,
    other_ind = ?
    WHERE id = ?";

// Log the SQL query for debugging
error_log("SQL query: $sql");

$stmt = $mysqli->prepare($sql);

if ($stmt) {
    $stmt->bind_param(
        "sssssssi",
        $mrn_modify,
        $current_location_modify,
        $bed_modify,
        $pname_modify,
        $age_modify,
        $indication_modify,
        $other_indication_modify,
        $id
    );

    if ($stmt->execute()) {
        $message = "Record updated successfully";
        error_log($message);
                          echo "<script language='javascript'>\n";
                  echo "window.location.href = 'dmc-new-consultation.php';";
                  echo "</script>\n";
    } else {
        $message = "Error updating record: " . $stmt->error;
        error_log($message);
    }
    $stmt->close();
} else {
    $message = "Error preparing statement: " . $mysqli->error;
    error_log($message);
}

echo $message;

$mysqli->close();
?>
