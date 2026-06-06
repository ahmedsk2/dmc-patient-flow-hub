<?php 
session_start();

require_once "authCookieSessionValidate.php";

if(!$isLoggedIn) {
    header("Location: ./");
}

?>

  <!-- Navbar -->
<?php

	require ('dbconnect.php');

  $errors = array(); 

  if (isset($_POST['update_user'])) {
      // receive all input values from the form
      $username1 = mysqli_real_escape_string($mysqli, $_POST['member_name']);
      $full_name1 = mysqli_real_escape_string($mysqli, $_POST['full_name']);
      $email1 = mysqli_real_escape_string($mysqli, $_POST['member_email']);
      $member_id1=mysqli_real_escape_string($mysqli, $_POST['member_id']);
      
      // form validation: ensure that the form is correctly filled ...
      // by adding (array_push()) corresponding error unto $errors array
      if (empty($username1)) { array_push($errors, "Username is required"); }
      if (empty($full_name1)) { array_push($errors, "Your full name is required"); }
      if (empty($email1)) { array_push($errors, "Email is required"); }
     
    
      // first check the database to make sure 
      // a user does not already exist with the same username and/or email
      $user_check_query = "SELECT * FROM members WHERE (member_name='".$username1."' OR member_email='".$email1."') AND (member_id != '".$member_id1."') LIMIT 1";
      $result = mysqli_query($mysqli, $user_check_query);
      $user2 = mysqli_fetch_assoc($result);
      
      if ($user2) { // if user exists
        if ($user2['member_name'] === $username1) {
          array_push($errors, "Username already exists");
        }
    
        if ($user2['member_email'] === $email1) {
          array_push($errors, "email already exists");
        }
        
      }
    
      // Finally, register user if there are no errors in the form
      if (count($errors) == 0) {
        
          $query = "UPDATE members set member_name='".$username1."', full_name='".$full_name1."', member_email='".$email1."' where member_id='".$member_id1."'";
          mysqli_query($mysqli, $query);

          // header('location: profile.php?s=success');
          echo "<script language='javascript'>\n";
          echo "window.location.href = 'profile.php?s=success';";
          echo "</script>\n";
      }
    }
    
    require 'sidebar.php';
?>

     <style>
          .hidden{
                  display: none;
          }
          textarea {
    resize: none;
    overflow: hidden;
}

      </style>

       
  <!-- Content Wrapper. Contains page content -->
  <div class="content-wrapper">
  
	<?php
  $user_id = $_SESSION["member_id"];
  $formationSQL = "SELECT * FROM members WHERE member_id = '".$user_id."'";
  $result1 = $mysqli->query($formationSQL);
  $user1 = $result1 -> fetch_array(MYSQLI_ASSOC);
	?>



    <!-- Content Header (Page header) -->
    <div class="content-header">
      <div class="container-fluid">
        <div class="row mb-2">
          <div class="col-sm-6">
            <h1 class="m-0 text-dark">User Profile</h1>
          </div><!-- /.col -->
          <div class="col-sm-6">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
              <li class="breadcrumb-item active">User Profile</li>
            </ol>
          </div><!-- /.col -->
        </div><!-- /.row -->
      </div><!-- /.container-fluid -->
    </div>
    <!-- /.content-header -->

    <!-- Main content -->
    <section class="content">
      <div class="container-fluid">  
  
<div class="row">

 <div id="mypresentersTable" class="col-md-12">

            <!-- /.info-box -->

            <div class="card">
              <div  class="card-header">
                <h3 class="card-title"><i class="fas fa-user-tie text-info"></i> User Profile</h3>
 
            </div>
              <!-- /.card-header -->
              <div class="card-body">
              <form method="post" autocomplete="off" action="profile.php">
                <div class="row">
              
                  <div class="col-md-6">
                        <div class="input-group mb-3">
                            <div class="col-4" style=" display: table-cell;">
                                <label>Login Name</label>
                            </div>
                            <div class="col-8">
                            <input type="hidden" name="member_id" value="<?php echo $user1['member_id']  ?>">

                            <input type="text" name="member_name" value="<?php echo $user1['member_name']  ?>" placeholder="Login Name" >
                            </div>
                        </div>
                  </div>
                  <!-- /.col -->
                  <div class="col-md-6">
                        <div class="input-group mb-3">
                            <div class="col-4" style=" display: table-cell;">
                                <label>Full / Display Name </label>
                            </div>
                            <div class="col-8">
                            <input type="text" name="full_name" value="<?php echo $user1['full_name']  ?>" placeholder="Login Name" >
                            </div>
                        </div>
                  </div>
                  <!-- /.col -->
                  <div class="col-md-6">
                        <div class="input-group mb-3">
                            <div class="col-4" style=" display: table-cell;">
                                <label>E-Mail </label>
                            </div>
                            <div class="col-8">
                            <input type="text" name="member_email" value="<?php echo $user1['member_email']  ?>" placeholder="Login Name" >
                            </div>
                        </div>
                  </div>
                  <!-- /.col -->
                  <div class="col-md-6">
                      <?php $change_pass_link="change-password.php?pass=true";
                    //   $_SESSION["member_id"]=$user1['member_id'];

                      ?>
                  <a style="float: right;color: black;" class="btn btn-light" onclick="location.href='<?php echo $change_pass_link; ?>'">Change Password</a>

                  </div>
                  <!-- /.col -->
                  <div class="col-md-12">
                  <button type="submit" id='registerbtn' class="btn btn-success" name="update_user">Change Details</button>
                  <div class="input-group mb-3" style='color: red;'>
                      <?php include('errors.php');
                        ?>
                  </div>
                  <div class="error-message" style="color:green;font-weight: 700;">
      <?php
      
      if (isset($_GET['s']) AND strcmp($_GET['s'], 'success') == 0){ 
          	echo "Changes submitted successfully";
} else if (isset($_GET['s']) AND $_GET['s']=='changed'){ 
  echo "Your password changed successfully, Please login";
}


?>
        </div>
		
                  </div>

                </div>
                <!-- /.row -->
                </form>
              </div>
              <!-- /.card-body -->
             
              <!-- /.footer -->
            </div>
            <!-- /.card -->
</div>
<!-- member_id -->

           
 </div> <!--row -->
			
 

<!-- PAGE SCRIPTS -->

<!-- AdminLTE App -->
<script src="dist/js/adminlte.js"></script>

<!-- OPTIONAL SCRIPTS -->
<script src="dist/js/demo.js"></script>

</div><!--/. container-fluid -->

    </section>
    <!-- /.content -->


<!-- PAGE SCRIPTS -->    


  </div>
  <!-- /.content-wrapper -->


<?php

require 'footer.php';

?>