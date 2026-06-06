<?php
require_once __DIR__ . '/../guard.php'; require_login();
csrf_verify();
 
require_once ('../dbconnect.php');
$response = ["success" => false, "message" => "Invalid input"];

if (isset($_REQUEST['patient_ids']) && is_array($_REQUEST['patient_ids']) && isset($_REQUEST['primary_modify'])) {
    $ids = $_REQUEST['patient_ids'];
    $primary_modify = $_REQUEST['primary_modify'];

    // Prepare SQL statement
    $sql = "UPDATE picupatients SET consultant_id = ? WHERE ID = ?";
    $stmt = $mysqli->prepare($sql);

    if (!$stmt) {
        error_log(__FILE__ . ": prepare failed " . $mysqli->error); $response["message"] = "A database error occurred.";
        echo json_encode($response);
        exit;
    }

    foreach ($ids as $id) {
        // Bind parameters and execute
        $stmt->bind_param("ii", $primary_modify, $id); // Assuming both 'consultant_id' and 'ID' are integers
        if ($stmt->execute()) {
            $response["success"] = true;
            $response["message"] = "Record updated successfully";
        } else {
            error_log(__FILE__ . ": update " . $stmt->error); $response["message"] = "Error updating record.";
            break; // Stop the loop if an error occurs
        }
    }
    audit_log('patient.change_consultant','picupatients',null, ['new_consultant'=>$primary_modify ?? null]);

    $stmt->close();
} else {
    $response["message"] = "Invalid input";
}

echo json_encode($response);
?>
