<?php 
require_once ('../dbconnect.php');
$response = ["success" => false, "message" => "Invalid input"];

if (isset($_REQUEST['patient_ids']) && is_array($_REQUEST['patient_ids']) && isset($_REQUEST['primary_modify'])) {
    $ids = $_REQUEST['patient_ids'];
    $primary_modify = $_REQUEST['primary_modify'];

    // Prepare SQL statement
    $sql = "UPDATE picupatients SET consultant_id = ? WHERE ID = ?";
    $stmt = $mysqli->prepare($sql);

    if (!$stmt) {
        $response["message"] = "Error preparing statement: " . $mysqli->error;
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
            $response["message"] = "Error updating record: " . $stmt->error;
            break; // Stop the loop if an error occurs
        }
    }

    $stmt->close();
} else {
    $response["message"] = "Invalid input";
}

echo json_encode($response);
?>
