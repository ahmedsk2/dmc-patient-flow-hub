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
      $username1 = trim($_POST['member_name'] ?? '');
      $full_name1 = trim($_POST['full_name'] ?? '');
      $email1 = trim($_POST['member_email'] ?? '');
      // IDOR fix: always act on the logged-in user, never a client-supplied id.
      $member_id1 = (int) $_SESSION['member_id'];
      
      // form validation: ensure that the form is correctly filled ...
      // by adding (array_push()) corresponding error unto $errors array
      if (empty($username1)) { array_push($errors, "Username is required"); }
      if (empty($full_name1)) { array_push($errors, "Your full name is required"); }
      if (empty($email1)) { array_push($errors, "Email is required"); }
     
    
      // first check the database to make sure 
      // a user does not already exist with the same username and/or email
      $chk = $mysqli->prepare("SELECT member_name, member_email FROM members WHERE (member_name = ? OR member_email = ?) AND member_id != ? LIMIT 1");
      $chk->bind_param("ssi", $username1, $email1, $member_id1);
      $chk->execute();
      $user2 = $chk->get_result()->fetch_assoc();
      
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
        
          $upd = $mysqli->prepare("UPDATE members SET member_name = ?, full_name = ?, member_email = ? WHERE member_id = ?");
          $upd->bind_param("sssi", $username1, $full_name1, $email1, $member_id1);
          $upd->execute();

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
  $user_id = (int) $_SESSION["member_id"];
  $ps = $mysqli->prepare("SELECT * FROM members WHERE member_id = ?");
  $ps->bind_param("i", $user_id);
  $ps->execute();
  $user1 = $ps->get_result()->fetch_array(MYSQLI_ASSOC);
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
                            <input type="hidden" name="member_id" value="<?php echo htmlspecialchars($user1['member_id'], ENT_QUOTES, 'UTF-8')  ?>">

                            <input type="text" name="member_name" value="<?php echo htmlspecialchars($user1['member_name'], ENT_QUOTES, 'UTF-8')  ?>" placeholder="Login Name" >
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
                            <input type="text" name="full_name" value="<?php echo htmlspecialchars($user1['full_name'], ENT_QUOTES, 'UTF-8')  ?>" placeholder="Login Name" >
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
                            <input type="text" name="member_email" value="<?php echo htmlspecialchars($user1['member_email'], ENT_QUOTES, 'UTF-8')  ?>" placeholder="Login Name" >
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