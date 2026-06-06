<?php
require_once 'sidebar.php';
// var_dump($user);
  if (!in_array($user['position'],$access_PICU_patients)){
    
    echo "
    <div class='content-wrapper'>
    
  
    <section class='content'>
    <div class='container-fluid'>  
    <div class='alert alert-danger' role='alert'> you dont have permission to access this page, Contact you manager if you need to.
    </div>
    </div>
    </section>
    </div>
    ";
    require 'footer.php';

    exit();
  }

  ////assignt to me
if (isset($_POST['assign_me_btn'])) {
  // receive all input values from the form

 $patient_id = $_POST['patientid'];
 $user_id = $_POST['userid'];
 
          $query = "UPDATE  picupatients SET consultant_id=?, newassign='1', assigned_on=? WHERE ID=?";
          $stmt = $mysqli->prepare($query);
          $stmt->bind_param('isi', $user_id, $today, $patient_id);
          if (!$stmt->execute()) {
            echo("Error description: " . $mysqli -> error);
          }else{
            echo "<script language='javascript'>\n";
            echo "window.location.href = 'dmc-new-admissions.php';";
            echo "</script>\n";
            
          }
        //   // header('location: PICU-patients.php');
    
}


/// assign to consultnat

if (isset($_POST['assign_to_consultant_btn'])) {
  // receive all input values from the form

 $patient_id = $_POST['patientid1'];
 $consultant_id = $_POST['consultantid'];
 
 
  if (isset($_POST['newpt'])){

    $query = "UPDATE  picupatients SET consultant_id=?, newassign='1', assigned_on=? WHERE ID=?";
    $stmt = $mysqli->prepare($query);
    $stmt->bind_param('isi', $consultant_id, $today, $patient_id);
    if (!$stmt->execute()) {
      echo("Error description: " . $mysqli -> error);
    }else{
      echo "<script language='javascript'>\n";
      echo "window.location.href = 'dmc-new-admissions.php';";
      echo "</script>\n";
      
    }
  //   // header('location: PICU-patients.php');
  }else{
    $query = "UPDATE  picupatients SET consultant_id=? WHERE ID=?";
    $stmt = $mysqli->prepare($query);
    $stmt->bind_param('ii', $consultant_id, $patient_id);
    if (!$stmt->execute()) {
      echo("Error description: " . $mysqli -> error);
    }else{
      echo "<script language='javascript'>\n";
      echo "window.location.href = 'dmc-new-admissions.php';";
      echo "</script>\n";
      
    }
  //   // header('location: PICU-patients.php');

  }
        
    
}


// Fetch all ICD-10 codes and names
$icd10_result = $mysqli->query("SELECT id, name FROM icd10");
$icd10_names = [];
while ($row = $icd10_result->fetch_assoc()) {
    $icd10_names[$row['id']] = $row['name'];
}


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
   		
		$formationSQL = "SELECT * FROM picupatients WHERE DISDATE IS NULL AND consultant_id IS NULL";
		$result1 = $mysqli->query($formationSQL);
		$activepicupatints = $result1 -> fetch_all(MYSQLI_ASSOC);

    $formationSQL = "SELECT * FROM countries";
		$result1 = $mysqli->query($formationSQL);
		$countries = $result1 -> fetch_all(MYSQLI_ASSOC);

	?>



    <!-- Content Header (Page header) -->
    <div class="content-header">
      <div class="container-fluid">
        <div class="row mb-2">
          <div class="col-sm-6">
            <h1 class="m-0 text-dark">New Admissions</h1>
          </div><!-- /.col -->
          <div class="col-sm-6">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
              <li class="breadcrumb-item active">New Admissions</li>
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
            
           <?php
           $total=0;
           foreach($activepicupatints as $s){
            if(is_null($s["consultant_id"]) == 1 ){
            $total++;
            }
           }

           /// find unique by key function
           function unique_multidim_array($array, $key) {
            $temp_array = array();
            $i = 0;
            $key_array = array();
           
            foreach($array as $val) {
              
                if (!in_array($val[$key], $key_array)) {
                    $key_array[$i] = $val[$key];
                    $temp_array[$i] = $val;
                }
                $i++;
            }
            
            $admdates = array();

            foreach ($temp_array as $t){
              array_push($admdates, $t['ADMDATE']);

            }

            return $admdates;
        }

        $admdates = unique_multidim_array($activepicupatints,'ADMDATE');

           ?>
            <div class="card">
              <div  class="card-header">
                <h3 class="card-title"><i class="fas fa-user-tie text-info"></i> New Admissions List: <?php echo htmlspecialchars($total, ENT_QUOTES, 'UTF-8'); ?> Patient</h3>
                <div id="addbtn" class='eachrow' style='  float: right; text-align: center;'>
                  <?php
                  if ($user['add_new_patient']== '1'){

                echo "<a  class='btn btn-success'  href='#admiting_modal' data-bs-toggle='modal'  style='color: aliceblue; line-height: 2;padding: 0px 15px;margin-bottom: 2%;'>New Admission</a>
                <a  class='btn btn-success'  href='#icu_transfer_modal' data-bs-toggle='modal'  style='color: aliceblue; line-height: 2;padding: 0px 15px;margin-bottom: 2%;'>Admission from ICU</a>
               ";
                  }
                  if ($user['assign_access']== '1'){

                    echo "
                    <a  class='btn btn-danger'  href='newpatients/dmc-patients-shuffle.php' onclick='assignPatients(this)' style='color: aliceblue; line-height: 2;padding: 0px 15px;margin-bottom: 2%;'>Assign Patients</a>";
                      }
                  ?>
          </div>
              </div>
              <!-- /.card-header -->
              <div class="card-body">
                <div>
<!--          
                          <table class="col-md-12" >
                            <thead   style="text-align: center;font-weight: 700;">
                            <tr>
                              <td class='col-md-1' >Bed #</td>
                              <td class="col-md-2">MRN</td>
                              <td class="col-md-2">Patient Name</td>
                              <td class="col-md-2">Age</td>
                              <td class="col-md-2">Gender</td>
                              <td class="col-md-2">Nationality</td>
                              <td class="col-md-2">Admission Date</td>
                              <td class="col-md-2">Admission From</td>
                              <td class="col-md-3">Diagnosis</td>
                            </tr>
                            </thead> -->
                                         <?php
                                          foreach($admdates as $a){
                                            // echo $a;
                                            echo "<div class='card'>
                                            <div class='card-header'>
                                            ".date('l', strtotime($a))." admissions (".htmlspecialchars($a, ENT_QUOTES, 'UTF-8')."):
                                          </div>
                                          <div class='card-body row'>
                                          ";
                                                     foreach($activepicupatints as $s){

                                                      if(is_null($s["consultant_id"]) == 1 && strtotime($s['ADMDATE']) == strtotime($a)){
                                                     
                                                      $decodedadmissiondx=json_decode($s['admissiondiagnosis']);
                                                  
                                          
                                                    
                                                    echo"
                                                    <div class='col-sm-3'>
                                                    <div class='eachrow card'  style='text-align: center;' id='row".htmlspecialchars($s['ID'], ENT_QUOTES, 'UTF-8')."'>
                                                    <div style='  margin: 2%; display: none; ' class='eachcol id'  scope='row' >

                                                    <input class='txtdata' type='hidden' name='id' id='id' value='".htmlspecialchars($s['ID'], ENT_QUOTES, 'UTF-8')."' style='text-align: center;width: 85%;' >

                                                    </div>
                                                    <div style='  margin: 2%; display: inline; ' class='eachcol bed card-header'  scope='row' >
                                                    ";
                                                    if (in_array($user['position'],$access_PICU_control)){
                                                    echo "<a  type='button' class='btn-tool'  style='display: contents;'   onclick='del(".$s['ID'].")'><i class='fa fa-trash'></i>  </a>"; // [NEEDS REVIEW] line 256: $s['ID'] used inside onclick JS call argument
                                                    }


                                                echo"
                                                    <label style=' text-align: center;margin-bottom: 0px;'>Admit In</label>
                                                    <select class='txtdata' id='current_location' style='width: 100%; padding: 4px;text-align: center;' required>
                                                    <option selected value='".htmlspecialchars($s['current_location'], ENT_QUOTES, 'UTF-8')."'> ".htmlspecialchars($s['current_location'], ENT_QUOTES, 'UTF-8')."</option>
                                                    <option value='Ward'>Ward</option>
                                                    <option value='ICU'>ICU</option>
                                                    <option value='ER'>ER</option>
                                                    </select>


                                                    <label style='text-align: center;margin-bottom: 0px;'>Bed</label>
                                                    <input class='txtdata' name='bed' placeholder='Bed Number' value='".htmlspecialchars($s['BED'], ENT_QUOTES, 'UTF-8')."' style='text-align: center;' >
                                                      
                                                     
                                                      </div>
                                                      
                                                      <div style='  margin: 2%; display: inline; ' class='eachcol mrn' >
                                                      <label style='text-align: center;margin-bottom: 0px;'>MRN</label>
                                                      ";
                                                      if (in_array($user['position'],$access_PICU_control)){
                                                        echo"
                                                        <input class='txtdata' name='mrn' value='".htmlspecialchars($s['MRN'], ENT_QUOTES, 'UTF-8')."' style='text-align: center;'>";
                                                      }else{
                                                        echo"
                                                        <input class='txtdata' name='mrn' value='".htmlspecialchars($s['MRN'], ENT_QUOTES, 'UTF-8')."' style='text-align: center;' disabled>";
                                                      }
                                                        echo"
                                                      </div>

                                                      <div style='  margin: 2%; display: inline; ' class='eachcol name'>
                                                      <label style='text-align: center;margin-bottom: 0px;'>Patient Name</label>
                                                      <input class='txtdata'  name='name' value='".htmlspecialchars($s['PNAME'], ENT_QUOTES, 'UTF-8')."' style='text-align: center;'>
                                                      </div>

                                                      <div style='  margin: 2%; display: inline; ' class='eachcol age'>
                                                      <label style='text-align: center;margin-bottom: 0px;'>Age</label>
                                                      <input class='txtdata' name='age' value='".htmlspecialchars($s['age'], ENT_QUOTES, 'UTF-8')."' style='text-align: center;'>
                                                      </div>
                                                      
                                                      <div style='  margin: 2%; display: inline; ' class='eachcol gender'>
                                                      <label style='text-align: center;margin-bottom: 0px;'>Gender</label>
                                                      <select class='txtdata' id='gender' style='text-align: center; width: 100%; padding: 4px;' required>
                                                      <option selected value='".htmlspecialchars($s['gender'], ENT_QUOTES, 'UTF-8')."'> ".htmlspecialchars($s['gender'], ENT_QUOTES, 'UTF-8')."</option>
                                                      <option value='Male'>Male</option>
                                                      <option value='Female'>Female</option>
                                                      <option value='Unknown'>Unknown</option>
                                                      </select>

                                                  </div>
                                                  <div style='  margin: 2%; display: inline; ' class='eachcol nationality'>
                                                  <label style='text-align: center;margin-bottom: 0px;'>Nationality</label>
                                                      <select class='nationality_update txtdata' required>

                                                    <option selected value='".htmlspecialchars($s['nationality'], ENT_QUOTES, 'UTF-8')."'> ".htmlspecialchars($s['nationality'], ENT_QUOTES, 'UTF-8')."</option>";

                                                    foreach($countries as $country){
                                                        echo"
                                                        <option value='".htmlspecialchars($country['name'], ENT_QUOTES, 'UTF-8')."'>".htmlspecialchars($country['name'], ENT_QUOTES, 'UTF-8')."</option>";
                                                    }
                                                    echo"
                                                    </select>
                                                    </div>
                                                    

                                                    <div style='  margin: 2%; display: inline; ' class='eachcol admdate'  scope='row' >
                                                    <label style='text-align: center;margin-bottom: 0px;'>Admission Date</label>
                                                    ";
                                                    if (in_array($user['position'],$access_PICU_control)){
                                                      echo"
                                                      <input type='text' onfocus='(this.type='date')' class='txtdata' name='admdate'  id='datepicker' value='".htmlspecialchars($s['ADMDATE'], ENT_QUOTES, 'UTF-8')."' style='text-align: center;padding: 0px;' readonly>";
                                                    }else{
                                                      echo"
                                                      <input type='text' onfocus='(this.type='date')' class='txtdata' name='admdate'  id='datepicker' value='".htmlspecialchars($s['ADMDATE'], ENT_QUOTES, 'UTF-8')."' style='text-align: center;padding: 0px;' disabled readonly>";

                                                    }
                                                      
                                                      
                                                      echo"</div>

                                                      <div style='  margin: 2%; display: inline; ' class='eachcol admfrom'>
                                                      <label style='text-align: center;margin-bottom: 0px;'>Admission From</label>
                                                      <select class='txtdata' id ='admfrom' style='width: 100%;text-align: center;padding: 4px;' required>
                                                    <option selected value='".htmlspecialchars($s['ADMFROM'], ENT_QUOTES, 'UTF-8')."'> ".htmlspecialchars($s['ADMFROM'], ENT_QUOTES, 'UTF-8')."</option>
                                                    <option value='ER'>ER</option>
                                                    <option value='ICU'>ICU</option>
                                                    <option value='OPD'>OPD</option>
                                                    <option value='OR'>OR</option>
                                                    <option value='Referral'>Referral</option>
                                                    </select>
                                                    </div>
                                                      ";
                                                   
                                                 echo"
                                                 <div style='  margin: 2%; display: inline; ' class='eachcol admissiondiagnosis'>
                                                 <label style='text-align: center;margin-bottom: 0px;'>Diagnosis</label>
                                                      <select class='txtdata ddxname form-control' style='width: 100%;'  oninput='auto_grow(this)'  multiple='multiple' name='admissiondiagnosis'>
                                                      ";
                                                
                                                        if (is_array($decodedadmissiondx)) {
                                                          foreach ($decodedadmissiondx as $value) {
                                                              if (isset($icd10_names[$value])) {
                                                                  echo '<option selected value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($icd10_names[$value], ENT_QUOTES, 'UTF-8') . '</option>';
                                                              }
                                                          }
                                                      }

                                                
                                                      echo"
                                                      </select>
                                                
                                                      </div>";
                                                      
                                                      // if ($user['position']== '3'){

                                                      //   echo "
                                                      //   <form autocomplete='off' id='assignme' method='POST' action='dmc-new-admissions.php'>
                                                      //   <input class='txtdata' type='hidden' name='patientid' value='".$s['ID']."' style='text-align: center;' required>
                                                      //   <input class='txtdata' type='hidden' name='userid' value='".$user['member_id']."' style='text-align: center;' required>
                                                      //   <button  class='btn btn-success' type='submit' name='assign_me_btn'  style='color: aliceblue; line-height: 2;padding: 0px 15px;' form='assignme' value='Submit'>Assign to me</button>
                                                      //   </form>";
                                                      //     }

                                                          if ($user['assign_access']== '1'){

                                                            echo "
                                                            <a class='btn btn-danger'  href='#assignprimary_modal' data-bs-toggle='modal'  data-book-id='".htmlspecialchars($s['ID'], ENT_QUOTES, 'UTF-8')."'  style='color: aliceblue;line-height: 2;margin-top: 3%;padding: 0px 10%;width: 100%;'  id= 'assignprimary'>Assign To Primary</a>";
                                                              }
                                                      echo "</div >
                                                      </div>
                                                      ";
                                                        }
                                                     }

                                                     echo "</div>";
                                                     echo "</div>";
                                                    }

                                         ?> 
                        </table>


        
                    
    
                  
                  <!-- /.col -->
                </div>
                <!-- /.row -->
              </div>
              <!-- /.card-body -->
            </div>
            <!-- /.card -->
         
</div>
			

           
 </div> <!--row -->

 
<!-- Modal -->

<div class="modal" id="assignprimary_modal">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
     
          <h4 class="modal-title">Assign Patient to Consultant</h4>
      </div>
      <div class="modal-body">
        <p>Choose consultant from list</p>

        <div id="assign_to_primary"></div>
       
      </div>

      <div class="modal-footer">

      </div>

          </form>
    </div>
  </div>
</div>
 

</div><!--/. container-fluid -->


    

    </section>
    <!-- /.content -->
  
<!-- Modal for admitting patient -->

<div class="modal" id="admiting_modal">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
     
          <h4 class="modal-title">Admit New Patient</h4>
      </div>
      <div class="modal-body">
        <p>Kindly Complete the Required Information</p>


        <form autocomplete="off"  method="POST">
        <label>Admit In</label>
        <select class='txtdata' id='current_location_new' style='width: 100%; padding: 4px;text-align: center;' required>
      <option value='Ward'>Ward</option>
      <option value='ICU'>ICU</option>
      <option value='ER'>ER</option>
    </select>
        <label>Bed Number</label>
        <input class='txtdata' id='bed_new' value='' style='text-align: center;' required>
        <label>MRNs</label>
        <input class='txtdata' id='mrn_new' value='' style='text-align: center;' required>
        <label>Patient Name</label>
        <input class='txtdata'  id='pname_new' value='' style='text-align: center;' required>
        <label>Age</label>
        <input class='txtdata' id='age_new' style='text-align: center;' required>
        <label>Gender</label>
        <select class='txtdata' id='gender_new' style='width: 100%; padding: 4px;text-align: center;' required>
      <option value='Male'>Male</option>
      <option value='Female'>Female</option>
    </select>
        <label>Nationality</label>
        <select class='nationality_new txtdata' id='nationality_new' style='width: 100%;text-align: center;' required>
         <?php   
        foreach($countries as $country)
            echo"
            <option value='".htmlspecialchars($country['name'], ENT_QUOTES, 'UTF-8')."'>".htmlspecialchars($country['name'], ENT_QUOTES, 'UTF-8')."</option>";
          ?>
    
  </select>
      

        <label>Admission date</label>
        <input value='' class='txtdata' id ="admdate_new"  data-date-format="DD-MM-YYYY" type="text"  name='admdate_new' style="text-align: center;padding: 0px;" required readonly>
        

        <label>Admitted from</label>
        <select class='txtdata' id ="admfrom_new" style="width: 100%;text-align: center;padding: 4px;" required>
        <option selected disabled value=''>Select</option>"
      <option value='ER'>ER</option>
      <option value='ICU'>ICU</option>
      <option value='OPD'>OPD</option>
      <option value='Other service'>Other service</option>
      <option value='Referral'>Referral</option>
    </select>
    
    <label>Admission Diagnosis</label>
    <select class='txtdata ddxname form-control' style='width: 100%;'  oninput='auto_grow(this)'  multiple='multiple' id='admissiondiagnosis_new' required></select>
      </div>

      <div class="modal-footer" style="text-align: center;display: block;">
      <div id='messsssage'></div>
<?php
     echo" <button type='button' class='btn btn-success'  onclick='admission(this)'>Complete Admission</button>";
  ?>
      <button type="button" class="btn btn-default" style=" color: black; " data-bs-dismiss="modal">Close</button>
      </div>

          </form>
         

    </div>
  </div>
</div>


  </div>  

<!-- icu_transfer_modal patient -->

    <div class="modal" id="icu_transfer_modal">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
     
          <h4 class="modal-title">Admit From ICU</h4>
          <button type="button" class="close" data-bs-dismiss="modal">&times;</button>
      </div>
      <div class="modal-body">
        <p>Choose ICU patient to admit to ward</p>

<?php
		$formationSQL = "SELECT * FROM picupatients WHERE DISDATE IS NULL AND current_location = 'ICU' ";
		$result1 = $mysqli->query($formationSQL);
		$icupatients = $result1 -> fetch_all(MYSQLI_ASSOC);
    // var_dump($icupatients);

    echo "<div class='row' style='margin: 1%;'>
            <table class='col-md-12'>
            <thead style='text-align: center;font-weight: 700;'>
              <tr>
                              <td class='col-md-2' style='  border-bottom: solid 1px darkgray;padding: 0px 1%;text-align: center'>MRN</td>
                              <td class='col-md-2' style='  border-bottom: solid 1px darkgray;padding: 0px 1%;text-align: center'>BED</td>
                              <td class='col-md-6' style='  border-bottom: solid 1px darkgray;padding: 0px 1%;text-align: center'>Patient Name</td>
                            
                              <td class='col-md-2' style='  border-bottom: solid 1px darkgray;padding: 0px 1%;text-align: center'>Transfer</td>
              </tr>
              </thead>
              ";
    foreach ($icupatients as $icu){
      echo "<tr class='eachrow'>
            <td style='  border-bottom: solid 1px darkgray;padding: 0px 1%;text-align: center'>".htmlspecialchars($icu['MRN'], ENT_QUOTES, 'UTF-8')."</td>
            <td style='  border-bottom: solid 1px darkgray;padding: 0px 1%;text-align: center'>".htmlspecialchars($icu['BED'], ENT_QUOTES, 'UTF-8')."</td>
            <td style='  border-bottom: solid 1px darkgray;padding: 0px 1%;text-align: center'>".htmlspecialchars($icu['PNAME'], ENT_QUOTES, 'UTF-8')."</td>
            <td class='eachcol' style='  border-bottom: solid 1px darkgray;padding: 0px 1%;text-align: center'>
          <button class='btn btn-success' onclick='icutransfer(this, " . $icu['ID'] . ")' style='color: aliceblue;line-height: 2;margin-top: 3%;margin-bottom: 3%;padding: 0px 10%;width: 100%;' id='Transfer'>Transfer</button>
            </td>
            </tr>"; // [NEEDS REVIEW] line 571: $icu['ID'] used inside onclick JS call argument

    }
    echo"
      
      </table>
      <div id='message'></div>
    ";
?>



      </div>

      <div class="modal-footer">

      <button type="button" class="btn btn-default" style=" color: black; " data-bs-dismiss="modal">Close</button>
    <div id='messsssage'></div>
      </div>

          </form>
    </div>
  </div>
</div>


  <!-- /.content-wrapper -->
<?php

require 'footer.php';

?>
  <!-- Select2 JS -->
  <script src="vendor/select2/4.0.13/select2.min.js"></script>
  <!-- Daterangepicker JS -->
  <script src="vendor/moments.js/2.30.1/moment.min.js"></script>
  <script src="vendor/daterangepicker/3.1.0/daterangepicker.min.js"></script>

  
  
        <script>


function assignPatients(button) {
    // Disable the button and show loading spinner
    button.disabled = true;
    button.innerHTML = '<div class="spinner-border spinner-border-sm" role="status"><span class="sr-only">Loading...</span></div>';

    // Simulate an AJAX call to assign patients
    setTimeout(function() {
        // Redirect to the actual URL after the loading spinner
        window.location.href = 'newpatients/dmc-patients-shuffle.php';
    }, 2000); // Simulate a delay of 2 seconds
}

function icutransfer(button, value){
  // var parent = $(this).parent('.eachcol').parent('.eachrow');
    // Disable the button and show loading spinner
    button.disabled = true;
    button.innerHTML = '<div class="spinner-border spinner-border-sm" role="status"><span class="sr-only">Transfer...</span></div>';
  var patientid=value;
var userid= <?php echo json_encode($user['member_id']); ?>;
data = {patientid: patientid,userid:userid};
$.post('newpatients/dmc-patients-icu-transfer.php', data, function(data){
  // $('#message').html(data);

location.reload();
});

}


function auto_grow(element) {
    element.style.height = "5px";
    element.style.height = (element.scrollHeight)+"px";
}


function del(value){
  if(!confirm("Do you really want to delete this patient?")) {
    return false;
  }
var rowname= "row";
rowname+=value;
row = document.getElementById(rowname);

    var id = value;
    data = {id: id};
    $.post('patients/dmc-patient-delete.php', data, function(data){
    // $(parent).html(data);
  });
  // location.reload();
  row.style.display = "none";
    }

function admission(button) {
  
// var rowname= "row";
// rowname+=value;
// row = document.getElementById(rowname);
//   var id = value;
bed_new=document.getElementById('bed_new').value;
mrn_new=document.getElementById('mrn_new').value;
pname_new=document.getElementById('pname_new').value;
gender_new=document.getElementById('gender_new').value;
nationality_new=document.getElementById('nationality_new').value;
age_new=document.getElementById('age_new').value;
admdate_new=document.getElementById('admdate_new').value;
admfrom_new=document.getElementById('admfrom_new').value;
current_location_new=document.getElementById('current_location_new').value;
admitted_by=<?php echo json_encode($user['member_id']); ?>;
var checked = document.querySelectorAll('#admissiondiagnosis_new :checked');
var admissiondiagnosis_new = [...checked].map(option => option.value);
var parent = document.getElementById('messsssage');
// alert(patientId);

if(bed_new==""){
            //do something
            // alert("name can not be null");
            parent.innerHTML="<p style='color:red'>   Please Fill all required informations</p>"
            return false;
            //this will not submit your form
        }
        else if(mrn_new==""){
          parent.innerHTML="<p style='color:red'>   Please Fill all required informations</p>"
            return false;
        }
        else if(pname_new==""){
          parent.innerHTML="<p style='color:red'>   Please Fill all required informations</p>"
          // alert(mrn_new);
            return false;
        }
        else if(gender_new==""){
          parent.innerHTML="<p style='color:red'>   Please Fill all required informations</p>"
            return false;
        }
        else if(nationality_new==""){
          parent.innerHTML="<p style='color:red'>   Please Fill all required informations</p>"
            return false;
        }
        else if(age_new==""){
          parent.innerHTML="<p style='color:red'>   Please Fill all required informations</p>"
            return false;
        }
        else if(admdate_new==""){
          parent.innerHTML="<p style='color:red'>   Please Fill all required informations</p>"
            return false;
        }
        else if(admfrom_new==""){
          parent.innerHTML="<p style='color:red'>   Please Fill all required informations</p>"
            return false;
        }
        else if(admissiondiagnosis_new==""){
          parent.innerHTML="<p style='color:red'>   Please Fill all required informations</p>"
            return false;
        }
        else if(current_location_new==""){
          parent.innerHTML="<p style='color:red'>   Please Fill all required informations</p>"
            return false;
        }
        else{

   button.disabled = true;
    button.innerHTML = '<div class="spinner-border spinner-border-sm" role="status"><span class="sr-only">Transfer...</span></div>';

          data = {bed_new: bed_new, mrn_new: mrn_new, gender_new: gender_new, pname_new: pname_new, nationality_new: nationality_new,current_location_new:current_location_new,
            age_new:age_new, admdate_new:admdate_new,admfrom_new:admfrom_new,admitted_by:admitted_by, admissiondiagnosis_new:admissiondiagnosis_new};
            
            $.post('newpatients/dmc-patients-add.php', data, function(data){
$(parent).html(data);

    // Simulate an AJAX call to transfer the patient
    setTimeout(function() {
            button.disabled = false;
            button.innerHTML = 'Complete Admission'
         }, 3000); // Simulate a delay of 3 seconds
// return false
// location.reload();
});
// alert(data);
//This will submit your form.
        }
// alert(patientId);
//   row.style.display = "none";
  }


$(document).ready(function($) {
  
  $('.txtdata').on('change', function(){
   var parent = $(this).closest('.eachrow');
    var id1 = parent.find('.id input').val();
    var bed = parent.find('.bed input').val();
    var mrn = parent.find('.mrn input').val();
    var name = parent.find('.name input').val();
    var age = parent.find('.age input').val();
    var gender = parent.find('.gender select').val();
    var current_location = parent.find('.bed select').val();
    var nationalityElement = parent.find('.nationality_update');
    var nationality = nationalityElement.val();
    var admdate = parent.find('.admdate input').val();
    var admfrom = parent.find('.admfrom select').val();
    var admissiondiagnosis = parent.find('.admissiondiagnosis select').val();
         
    
    var attribChanged = $(this).attr('name');
    data = {id1: id1, bed: bed, mrn: mrn,name: name, age:age, gender:gender, nationality,nationality, admfrom:admfrom,  admdate: admdate
      // , admfrom: admfrom
      , admissiondiagnosis:admissiondiagnosis,current_location:current_location, attribChanged: attribChanged};
    $.post('newpatients/dmc-new-patients-update.php', data, function(data){
      // $(parent).html(data);
     
    });
    $(this).parent('.eachcol').css("backgroundColor", "#90EE90");

  });
});
</script>

<script>

// document.getElementById('addpatient').onclick = function(){
//   var parent = $(this).parent('.eachrow');
//   var attribChanged = $(this).attr('name');
//   data = {attribChanged: attribChanged};
//   $.post('dmc-patients-add.php', data, function(data){
//   $(parent).html(data);
//   location.reload();
// });
// }



$("textarea").each(function(textarea) {
    $(this).height( $(this)[0].scrollHeight );
});


  </script>


<script>
$(function() {
  $('input[name="admdate"]').daterangepicker({
    singleDatePicker: true,
    timePicker: false,
    timePicker24Hour: false,
    autoUpdateInput: false,
    showDropdowns: true,
    showButtonPanel: false,
    autoapply: true,
    minYear: 2010,
    maxYear: parseInt(moment().format('YYYY'),10),
    locale: {
            format: 'YYYY-MM-DD'
        }
  }, ).on("apply.daterangepicker", function (e, picker) {
        picker.element.val(picker.startDate.format(picker.locale.format));
        var parent = $(this).parent('.eachcol').parent('.eachrow');
    var id1 = parent.find('.id input').val();
    var bed = parent.find('.bed input').val();
    var mrn = parent.find('.mrn input').val();
    var name = parent.find('.name input').val();
    var age = parent.find('.age input').val();
    var gender = parent.find('.gender select').val();
    var current_location = parent.find('.bed select').val();
    var nationalityElement = parent.find('.nationality_update');
    var nationality = nationalityElement.val();
    var admdate = parent.find('.admdate input').val();
    var admfrom = parent.find('.admfrom select').val();
    var admissiondiagnosis = parent.find('.admissiondiagnosis select').val();
         
    //  alert (scot);

    var attribChanged = $(this).attr('name');
    data = {id1: id1, bed: bed, mrn: mrn,name: name, age:age, gender:gender, nationality,nationality, admfrom:admfrom,  admdate: admdate
      // , admfrom: admfrom
      , admissiondiagnosis:admissiondiagnosis,current_location:current_location, attribChanged: attribChanged};
    $.post('newpatients/dmc-new-patients-update.php', data, function(data){
      // $(parent).html(data);
     
    });
    $(this).parent('.eachcol').css("backgroundColor", "#90EE90");
    });
});


</script>

<script>


$(function() {
  $('input[name="admdate_new"]').daterangepicker({
    singleDatePicker: true,
    timePicker: false,
    timePicker24Hour: false,
    showButtonPanel: false,
    autoUpdateInput: true,
    autoApply: true,
    showDropdowns: true,
    minYear: 2010,
    maxYear: parseInt(moment().format('YYYY'),10),
    locale: {
            format: 'YYYY-MM-DD'
        }
  }, ).on("apply.daterangepicker", function (e, picker) {
        picker.element.val(picker.startDate.format(picker.locale.format));
    });
});



$('#assignprimary_modal').on('show.bs.modal', function(e) {
 
 var bookId = $(e.relatedTarget).data('book-id');

data = {bookId: bookId};
$.post('newpatients/dmc-assign-to-primary.php', data, function(data){
$('#assign_to_primary').html(data);
    
   });
 
   // $(e.currentTarget).find('input[name="patientId"]').val(bookId);
   // disdate=document.getElementById('disdate').value='';
   // document.getElementById('finaldiagnosis').value='';
   // document.getElementById('disstatus').value='';
   // document.getElementById('disto').value='';
   // document.getElementById('disdate').style.backgroundColor = "";
   // document.getElementById('finaldiagnosis').style.backgroundColor = "";
   // document.getElementById('disto').style.backgroundColor = "";
   // document.getElementById('disstatus').style.backgroundColor = "";
});



</script>
<script type="text/javascript">
      $(document).ready(function() {
          
        $('.nationality_update').select2({
      placeholder: 'Select',
    width: '100%'
    } );
             $('.nationality_new').select2({
      placeholder: 'Select',
    width: '100%',
    dropdownParent: $("#admiting_modal")
    } );

        $('.ddxname').select2({
            placeholder: 'Select',
            minimumInputLength: 4,
            ajax: {
                url: 'fetchicd10.php',
                dataType: 'json',
                delay: 250,
                data: function (data) {
                    return {
                        searchTerm: data.term // search term
                    };
                },
                processResults: function (response) {
                    return {
                        results:response
                    };
                },
                cache: true
            }
        });
      
      
      });

    </script>