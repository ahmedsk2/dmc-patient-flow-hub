<?php
require_once __DIR__ . '/guard.php'; require_login();
 
// connect to database 
require('dbconnect.php');


if(!isset($_GET['searchTerm'])){ 
    $json = [];
}else{
    $search = $_GET['searchTerm'];
    $sql = "SELECT id, name FROM icd10
            WHERE name LIKE CONCAT('%', ?, '%')
            ";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('s', $search);
    $stmt->execute();
    $result = $stmt->get_result();
    $json = [];
    while($row = $result->fetch_assoc()){
        $json[] = ['id'=>$row['id'], 'text'=>$row['name']];
    }
}

echo json_encode($json);
?>