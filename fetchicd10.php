<?php
require_once __DIR__ . '/guard.php'; require_login();
 
// connect to database 
require('dbconnect.php');


if(!isset($_GET['searchTerm'])){ 
    $json = [];
}else{
    $search = $_GET['searchTerm'];
    $sql = "SELECT id, name FROM icd10 
            WHERE name LIKE '%".$search."%'
            "; 
    $result = $mysqli->query($sql);
    $json = [];
    while($row = $result->fetch_assoc()){
        $json[] = ['id'=>$row['id'], 'text'=>$row['name']];
    }
}

echo json_encode($json);
?>