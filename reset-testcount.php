<?php
  require ('dbconnect.php');

/// resetting new labeling
if(isset($_POST['reset-testcount'])){

    if(isset($_POST['count'])){
    $count =$_POST['count'];
    $sql = "UPDATE picupatients SET newassign= Null, DISDATE= Null, med_DISDATE= Null, assigned_on= Null,  consultant_id = Null limit $count";
    // $sql = "UPDATE picupatients SET newassign= Null";
            if ($mysqli->query($sql) === TRUE) {
                $message= "Rest done";
            } else {
            $message= "Error New labeling Rest: " . $mysqli->error;
            }
            echo $message;
    }
}
          ?>

<form method="post" action="reset-testcount.php">

<input type="text" name="count">
<button type="submit" name="reset-testcount">submit</button>
</form>