<?php
require_once 'sidebar.php';

if (!in_array($user['position'],$access_PICU_control)){
    
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


date_default_timezone_set('Asia/Riyadh');
$today=date("Y-m-d");

if (isset($_POST['undo_btn'])) {

 $patient_id = $_POST['patientid'];

/// if dscharge date = today... or yesterday


$query = "UPDATE  picupatients SET DISDATE= NULL, med_DISDATE= NULL, MORTALITY= NULL, DISTO= NULL, trans_discharge= NULL, trans_discharge_by= NULL WHERE ID=?";
          $stmt = $mysqli->prepare($query);
          $stmt->bind_param("i", $patient_id);
          if (!$stmt->execute()) {
            echo("Error description: " . $mysqli -> error);
          } else {
           
            echo "<script language='javascript'>\n";
            echo "window.location.href = 'dmc-patients.php';";
            echo "</script>\n";

          }
  
}
?>


<style>
/* loader on search */
.se-pre-con1 {
	text-align: center;
    display: none;
}

/* X Color for select 2 */
.select2-selection__clear{
font-size: medium;color: red;
}
/* Customize the label (the container) */
.container {
  display: block;
  position: relative;
  padding-left: 35px;
  cursor: pointer;

  -webkit-user-select: none;
  -moz-user-select: none;
  -ms-user-select: none;
  user-select: none;
}

/* Hide the browser's default radio button */
.container input {
  position: absolute;
  opacity: 0;
  cursor: pointer;
  height: 0;
  width: 0;
}

/* Create a custom radio button */
.checkmark {
  position: absolute;
  height: 100%;
  
  background-color: #eee;
  border-radius: 50%;
}

/* On mouse-over, add a grey background color */
.container:hover input ~ .checkmark {
  background-color: #ccc;
}

/* When the radio button is checked, add a blue background */
.container input:checked ~ .checkmark {
  background-color: #2196F3;
}

/* Create the indicator (the dot/circle - hidden when not checked) */
.checkmark:after {
  content: "";
  position: absolute;
  display: none;
}

/* Show the indicator (dot/circle) when checked */
.container input:checked ~ .checkmark:after {
  display: block;
}

/* Style the indicator (dot/circle) */
.container .checkmark:after {
  top: 7px;
  left: 7px;
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: white;
}

</style>

<?php
   		
       $formationSQL = "SELECT * FROM picupatients WHERE DISDATE IS NOT NULL";
       $result1 = $mysqli->query($formationSQL);
       $olspatints = $result1 -> fetch_all(MYSQLI_ASSOC);
   
       $formationSQL = "SELECT * FROM countries";
       $result1 = $mysqli->query($formationSQL);
       $countries = $result1 -> fetch_all(MYSQLI_ASSOC);
   
       $formationSQL = "SELECT * FROM members WHERE position = '3'";
       $result1 = $mysqli->query($formationSQL);
       $consultants = $result1 -> fetch_all(MYSQLI_ASSOC);

       $query = "select * from settings";
       $result1 = $mysqli->query($query);
       $settings = $result1 -> fetch_array(MYSQLI_ASSOC);

       $shortlos=$settings['short_los'];
       $longlos=$settings['long_los'];
     ?>
   
  <!-- Content Wrapper. Contains page content -->
  <div class="content-wrapper">
  
    <!-- Content Header (Page header) -->
    <div class="content-header">
      <div class="container-fluid">
        <div class="row mb-2">
          <div class="col-sm-6">
            <h1 class="m-0 text-dark">Search Registry</h1>
          </div><!-- /.col -->
          <div class="col-sm-6">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
              <li class="breadcrumb-item active">Search Registry</li>
            </ol>
          </div><!-- /.col -->
        </div><!-- /.row -->
         <div class="row mb-2">
                 <div class="col-sm-6">

            <div style=" font-weight: bold;" id="container"  class="rs-select2 select--no-search"  >
               
		</div><!-- /.col -->							
		</div>
		</div><!-- /.row -->
      </div><!-- /.container-fluid -->
    </div>
    <!-- /.content-header -->

    <!-- Main content -->
    <section class="content">
      <div class="container-fluid">
      <div class="card">
        <div class="row card-body" style="display: flex;margin-top: 2%;">
            <label class="container" style="width: 33%;">Admissions
            <input  checked="checked"  class="search_type" type="radio"   value="admissions" name="search_type" id="search_type">
            <span class="checkmark"></span>
            </label>
            <label class="container" style="width: 33%;">Consultations
            <input  class="search_type" type="radio"   value="consultations" name="search_type" id="search_type">
            <span class="checkmark" ></span>
            </label>
            <label class="container" style="width: 33%;">Free text diagnosis
            <input   class="search_type" type="radio"   value="diagnosis" name="search_type" id="search_type">
            <span class="checkmark"></span>
            </label>
        </div>
      </div>

        <div id="admission_search">  
      <form name="search_registry" action="registry/export-results-exel.php" method="post">

    
        <div class="card">
            <div class="card-header">
                Search for admissions in Registry
            </div>
            <div class="row card-body">
                    
                <div class="col-sm-3" style="text-align: center;">
                    <label style='text-align: center'>MRN</label>
                    <input class='txtdata' name='mrn' id='mrn' style='text-align: center;'>
                </div>
                <div class="col-sm-3" style="text-align: center;">
                    <label>Gender</label>
                    <select class='txtdata select2' id='gender' name='gender' style='width: 100%; padding: 4px;text-align: center;'>
                    <option selected disabled value=''>Select</option>
                    <option value='Male'>Male</option>
                    <option value='Female'>Female</option>
                    </select>
                </div>
                <div class="col-sm-3" style="text-align: center;">
                    <label>Nationality</label>
                    <select class='select2 txtdata' id='nationality' name='nationality' style='width: 100%;text-align: center;'>
                    <option selected disabled value=''>Select</option>
                    <?php   

                        $formationSQL = "SELECT * FROM countries";
                        $result1 = $mysqli->query($formationSQL);
                        $countries = $result1 -> fetch_all(MYSQLI_ASSOC);

                        foreach($countries as $country)
                            echo"
                            <option value='".htmlspecialchars($country['name'], ENT_QUOTES, 'UTF-8')."'>".htmlspecialchars($country['name'], ENT_QUOTES, 'UTF-8')."</option>";
                        ?>
                    
                    </select>
                    
                </div>
                <div class="col-sm-3" style="text-align: center;">
                    <label style='text-align: center;'>Age Range</label>
                    <input class='txtdata' type='number' name='agerange1' id='agerange1' placeholder='From' style="text-align: center;margin-bottom: 2%;">
                    <input class='txtdata' type='number' name='agerange2' id='agerange2' placeholder='To' style='text-align: center;'>
                </div>
                <div class="col-sm-3" style="text-align: center;">

                    <label style='text-align: center;'>Admission Date</label>
                    <input value='' class='txtdata' id ="beforedate" placeholder='From'  data-date-format="DD-MM-YYYY" type="text"  name='beforedate' style="text-align: center;margin-bottom: 2%;">
                    <input value='' class='txtdata' id ="afterdate" placeholder='To' data-date-format="DD-MM-YYYY" type="text"  name='afterdate' style="text-align: center;padding: 0px;">
                </div>
                <div class="col-sm-3" style="text-align: center;">

                <label> Admitted From</label>
                    <select class='txtdata select2' id ="admfrom"  name='admfrom' style="width: 100%;text-align: center;padding: 4px;">
                        <option selected disabled value=''>Select</option>
                        <?php
                        
                            $formationSQL =  "SELECT DISTINCT(ADMFROM) FROM picupatients WHERE ADMFROM IS NOT NULL AND ADMFROM !=''";
                            $result1 = $mysqli->query($formationSQL);
                            $admfrom = $result1 -> fetch_all(MYSQLI_ASSOC);
                            
                            foreach ($admfrom as $a){
                                echo  "<option value=".htmlspecialchars($a['ADMFROM'], ENT_QUOTES, 'UTF-8').">".htmlspecialchars($a['ADMFROM'], ENT_QUOTES, 'UTF-8')."</option>";
                            }
                        ?>
                    </select>
                </div>
                <div class="col-sm-3" style="text-align: center;">

                <label> Admitted To</label>
                    <select class='txtdata select2' id ="current_location"  name='current_location' style="width: 100%;text-align: center;padding: 4px;">
                        <option selected disabled value=''>Select</option>
                        <?php
                        
                            $formationSQL =  "SELECT DISTINCT(current_location) FROM picupatients WHERE current_location IS NOT NULL AND current_location !=''";
                            $result1 = $mysqli->query($formationSQL);
                            $current_location = $result1 -> fetch_all(MYSQLI_ASSOC);
                            
                            foreach ($current_location as $a){
                                echo  "<option value=".htmlspecialchars($a['current_location'], ENT_QUOTES, 'UTF-8').">".htmlspecialchars($a['current_location'], ENT_QUOTES, 'UTF-8')."</option>";
                            }
                        ?>
                    </select>
                </div>
                <div class="col-sm-3" style="text-align: center;"> 
                

                    <label style='text-align: center;'>Diagnosis</label>
                    <select class='txtdata ddxname form-control' style='width: 100%;'  oninput='auto_grow(this)'  multiple='multiple' id='admissiondiagnosis' name='admissiondiagnosis[]'>
                    </select>

<div style="display: flex;margin-top: 2%;">
<label class="container">And
  <input  class="dxcondition" type="radio"   value="and" name="dxcondition" id="dxcondition" required>
  <span class="checkmark"></span>
</label>

<label class="container">Or
<input  class="dxcondition" type="radio"   value="or" name="dxcondition" id="dxcondition" checked="checked">
  <span class="checkmark"></span>
</label>
</div>
                </div>
                <div class="col-sm-3" style="text-align: center;">

                    <label> Discharged to</label>
                    <select class='txtdata select2' id ="dischargedto"  name='dischargedto' style="width: 100%;text-align: center;padding: 4px;">
                        <option selected disabled value=''>Select</option>
                        <?php
                        
                            $formationSQL =  "SELECT DISTINCT(DISTO) FROM picupatients WHERE DISTO IS NOT NULL AND DISTO !=''";
                            $result1 = $mysqli->query($formationSQL);
                            $disto = $result1 -> fetch_all(MYSQLI_ASSOC);
                            
                            foreach ($disto as $d){
                                echo  "<option value=".htmlspecialchars($d['DISTO'], ENT_QUOTES, 'UTF-8').">".htmlspecialchars($d['DISTO'], ENT_QUOTES, 'UTF-8')."</option>";
                            }
                        ?>
                    </select> 
                </div>
                <div class="col-sm-3" style="text-align: center;">

                    <label> Delay of discharge</label>
                    <select class='txtdata select2' id ="delay"  name='delay' style="width: 100%;text-align: center;padding: 4px;">
                        <option selected disabled value=''>Select</option>
                        <?php
                        
                            $formationSQL =  "SELECT DISTINCT(delay) FROM picupatients WHERE delay IS NOT NULL AND delay !=''";
                            $result1 = $mysqli->query($formationSQL);
                            $delay = $result1 -> fetch_all(MYSQLI_ASSOC);
                            
                            foreach ($delay as $d){
                                echo  "<option value=".htmlspecialchars($d['delay'], ENT_QUOTES, 'UTF-8').">".htmlspecialchars($d['delay'], ENT_QUOTES, 'UTF-8')."</option>";
                            }
                        ?>
                    </select> 
                </div>
                <div class="col-sm-3" style="text-align: center;">

                    <label> Mortality Status</label>
                    <select class='txtdata select2' id ="mortality"  name='mortality' style="width: 100%;text-align: center;padding: 4px;">
                        <option selected disabled value=''>Select</option>
                        <?php
                        
                            $formationSQL =  "SELECT DISTINCT(MORTALITY) FROM picupatients WHERE MORTALITY IS NOT NULL AND MORTALITY !=''";
                            $result1 = $mysqli->query($formationSQL);
                            $mortality = $result1 -> fetch_all(MYSQLI_ASSOC);
                            
                            foreach ($mortality as $m){
                                echo  "<option value=".htmlspecialchars($m['MORTALITY'], ENT_QUOTES, 'UTF-8').">".htmlspecialchars($m['MORTALITY'], ENT_QUOTES, 'UTF-8')."</option>";
                            }
                        ?>
                    </select>
                </div>
                <div class="col-sm-3" style="text-align: center;"> 

                <label > Primary Consultant</label>
                    <select class='txtdata select2' id ="consultant"  name='consultant' style="width: 100%;text-align: center;padding: 4px;">
                        <option selected disabled value=''>Select</option>
                        <?php
                        
                            $formationSQL =  "SELECT DISTINCT(consultant_id) FROM picupatients WHERE consultant_id IS NOT NULL AND consultant_id !=''";
                            $result1 = $mysqli->query($formationSQL);
                            $consultant = $result1 -> fetch_all(MYSQLI_ASSOC);
                            
                            foreach ($consultant as $c){
                                $id=$c['consultant_id'];
                                $formationSQL =  "SELECT * FROM members WHERE member_id = ?";
                                $stmt = $mysqli->prepare($formationSQL);
                                $stmt->bind_param("i", $id);
                                $stmt->execute();
                                $result1 = $stmt->get_result();
                                $name = $result1 -> fetch_array(MYSQLI_ASSOC);

                                echo  "<option value=".htmlspecialchars($name['member_id'], ENT_QUOTES, 'UTF-8').">".htmlspecialchars($name['full_name'], ENT_QUOTES, 'UTF-8')."</option>";
                            }
                        ?>
                    </select>
                </div>
                <div class="col-sm-3" style="text-align: center;">

                    <input style='width: auto;' class='txtdata'  type='checkbox' id='longterm' name='longterm' value='longterm'>
                    <label for='longterm' style=' display: contents; '> Long Term Patient</label> </br>

                    
                    <input style='width: auto;' class='txtdata'  type='checkbox' name='only' id='only' value='only'>
                    <label for='only' style=' display: contents; '> Only discharged patients</label> </br>

                    <input style='width: auto;' class='txtdata'  type='checkbox' name='readmission' id='readmission' value='readmission'>
                    <label for='readmission' style=' display: contents; '> Readmission</label>
                </div>
                <div class="col-sm-3" style="text-align: center;">
                    <!-- <button type="submit" class="btn btn-primary" value="Search" name="search_btn" id="search_btn" style="line-height: 200%;margin-top: 2%;width: 100%;">Search</button> -->
                    <button type='button' class='btn btn-primary'  onclick='search(this)' style="line-height: 200%;margin-top: 2%;width: 100%;">Search</button>

                </div>
                <div class="col-sm-3" style="text-align: center;">
                    // <!-- <button type='submit' class='btn btn-success' name='search_btn'  style="line-height: 200%;margin-top: 2%;width: 100%;">Export</button> -->

                </div>

            </div> 
         
           
        </div> 
    
</form>
                        </div> <!-- end of admission_search -->




                        <!-- style="display: none" -->
                        <div id="consultation_search" style="display: none" >  
      <form name="search_consultations_registry" action="search.php" method="post">

    
        <div class="card">
            <div class="card-header">
                Search for Consultations in Registry
            </div>
            <div class="row card-body">
                    
                <div class="col-sm-3" style="text-align: center;">
                    <label style='text-align: center'>MRN</label>
                    <input class='txtdata' name='mrn_consultation' id='mrn_consultation' style='text-align: center;'>
                    <input style='width: auto;' class='txtdata'  type='checkbox' id='signoff' name='signoff' value='signoff'>
                    <label for='signoff' style=' display: contents; '> Signed Off Only</label> </br>
                </div>
                <div class="col-sm-3" style="text-align: center;">
                    <label style='text-align: center;'>Age Range</label>
                    <input class='txtdata' type='number' name='agerange1_consultation' id='agerange1_consultation' placeholder='From' style="text-align: center;margin-bottom: 2%;">
                    <input class='txtdata' type='number' name='agerange2_consultation' id='agerange2_consultation' placeholder='To' style='text-align: center;'>
                </div>
                <div class="col-sm-3" style="text-align: center;"> 
                

                <label style='text-align: center;'>Indication</label>
                <select class='txtdata select2 form-control' style='width: 100%;'  oninput='auto_grow(this)'  multiple='multiple' id='indications' name='indications[]'>
                <?php
                    
                    $formationSQL =  "SELECT * FROM consultation_reason WHERE consultation_reason IS NOT NULL AND consultation_reason !=''";
                    $result1 = $mysqli->query($formationSQL);
                    $consultation_reason = $result1 -> fetch_all(MYSQLI_ASSOC);
                    
                    foreach ($consultation_reason as $a){
                        echo  "<option value=".htmlspecialchars($a['id'], ENT_QUOTES, 'UTF-8').">".htmlspecialchars($a['consultation_reason'], ENT_QUOTES, 'UTF-8')."</option>";
                    }
                ?>

                </select>
             
<div style="display: flex;margin-top: 2%;">
<label class="container">And
<input  class="indcondition" type="radio"   value="and" name="indcondition" id="indcondition" required>
<span class="checkmark"></span>
</label>

<label class="container">Or
<input  class="indcondition" type="radio"   value="or" name="indcondition" id="indcondition" checked="checked">
<span class="checkmark"></span>
</label>
</div>
            </div>
                <div class="col-sm-3" style="text-align: center;">

                    <label style='text-align: center;'>Consultation Date</label>
                    <input value='' class='txtdata' id ="beforedate_consultation" placeholder='From'  data-date-format="DD-MM-YYYY" type="text"  name='beforedate_consultation' style="text-align: center;margin-bottom: 2%;">
                    <input value='' class='txtdata' id ="afterdate_consultation" placeholder='To' data-date-format="DD-MM-YYYY" type="text"  name='afterdate_consultation' style="text-align: center;padding: 0px;">
                </div>
                <div class="col-sm-3" style="text-align: center;">

                <label>Consultation From</label>
                    <select class='txtdata select2' id ="consultation_from"  name='consultation_from' style="width: 100%;text-align: center;padding: 4px;">
                        <option selected disabled value=''>Select</option>
                        <?php
                        
                            $formationSQL =  "SELECT DISTINCT(consultation_from) FROM consultations WHERE consultation_from IS NOT NULL AND consultation_from !=''";
                            $result1 = $mysqli->query($formationSQL);
                            $consultation_from = $result1 -> fetch_all(MYSQLI_ASSOC);
                            
                            foreach ($consultation_from as $a){
                                echo  "<option value='".htmlspecialchars($a['consultation_from'], ENT_QUOTES, 'UTF-8')."'>".htmlspecialchars($a['consultation_from'], ENT_QUOTES, 'UTF-8')."</option>";
                            }
                        ?>
                    </select>
                </div>
                <div class="col-sm-3" style="text-align: center;">

<label>Consulted Service</label>
    <select class='txtdata select2' id ="consultation_to_service"  name='consultation_to_service' style="width: 100%;text-align: center;padding: 4px;">
        <option selected disabled value=''>Select</option>
        <?php
        
            $formationSQL =  "SELECT DISTINCT(consultation_to_service) FROM consultations WHERE consultation_to_service IS NOT NULL AND consultation_to_service !=''";
            $result1 = $mysqli->query($formationSQL);
            $consultation_to = $result1 -> fetch_all(MYSQLI_ASSOC);
            
              // Collect all numeric consultation_to_service values
              $numericServices = [];
              foreach ($consultation_to as $a) {
                  if (is_numeric($a['consultation_to_service'])) {
                      $numericServices[] = $a['consultation_to_service'];
                  }
              }

                // Query the database for all specialities
                $specialities = [];
                $formationSQL = "SELECT * FROM speciality";
                $result1 = $mysqli->query($formationSQL);

                if (!$result1) {
                    die("Error executing query: " . $mysqli->error);
                }

                while ($row = $result1->fetch_array(MYSQLI_ASSOC)) {
                    $specialities[$row['id']] = $row['specilaity'];
                }
              

              // Now loop through the consultation_to array
              foreach ($consultation_to as $a) {
                  $service = $a['consultation_to_service'];
                  if (is_numeric($service)) {
                      if (isset($specialities[$service])) {
                          echo "<option value='".htmlspecialchars($service, ENT_QUOTES, 'UTF-8')."'>".htmlspecialchars($specialities[$service], ENT_QUOTES, 'UTF-8')."</option>";
                      } else {
                          echo "<option value='".htmlspecialchars($service, ENT_QUOTES, 'UTF-8')."'>".htmlspecialchars($service, ENT_QUOTES, 'UTF-8')."</option>";
                      }
                  } else {
                      echo "<option value='".htmlspecialchars($service, ENT_QUOTES, 'UTF-8')."'>".htmlspecialchars($service, ENT_QUOTES, 'UTF-8')."</option>";
                  }
              }
        ?>
    </select>
    <?
    // var_dump($specialities);

    ?>
</div>
               
                <div class="col-sm-3" style="text-align: center;">
   

<label> Primary Consultant</label>
    <select class='txtdata select2' id ="consultant_consultations"  name='consultant_consultations' style="width: 100%;text-align: center;padding: 4px;">
        <option selected disabled value=''>Select</option>
        <?php
        
            $formationSQL =  "SELECT DISTINCT(consultant_id) FROM consultations WHERE consultant_id IS NOT NULL AND consultant_id !=''";
            $result1 = $mysqli->query($formationSQL);
            $consultant = $result1 -> fetch_all(MYSQLI_ASSOC);
            
            foreach ($consultant as $c){
                $id=$c['consultant_id'];
                $formationSQL =  "SELECT * FROM members WHERE member_id = ?";
                $stmt = $mysqli->prepare($formationSQL);
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $result1 = $stmt->get_result();
                $name = $result1 -> fetch_array(MYSQLI_ASSOC);

                echo  "<option value=".htmlspecialchars($name['member_id'], ENT_QUOTES, 'UTF-8').">".htmlspecialchars($name['full_name'], ENT_QUOTES, 'UTF-8')."</option>";
            }
        ?>
    </select>




                   
                </div>
                <div class="col-sm-3" style="text-align: center;">
                    <!-- <button type="submit" class="btn btn-primary" value="Search" name="search_btn" id="search_btn" style="line-height: 200%;margin-top: 2%;width: 100%;">Search</button> -->
                    <button type='button' class='btn btn-primary'  onclick='search_consultation(this)' style="line-height: 200%;margin-top: 2%;width: 100%;">Search</button>

                </div>

            </div> 
            
            
            
        </div> 
    
</form>
                        </div> <!-- consultation_search -->

<div id="diagnosis_search" style="display: none" >  
  <form name="search_bydiagnosis" action="registry/export-results-exel.php" method="post">
    <div class="card">
      <div class="card-header">
          Search By diagnosis free text
      </div>
      <div class="row card-body">
        <div class="col-sm-4" style="text-align: center;">
          <label style='text-align: center'>Keyword</label>
          <input class='txtdata' name='keyword' id='keyword' style='text-align: center;'>
        </div>

        <div class="col-sm-4" style="text-align: center;">
          <label style='text-align: center;'>Admission Date</label>
          <input value='' class='txtdata' id="beforedate_keyword" placeholder='From' data-date-format="YYYY-MM-DD" type="text" name='beforedate_keyword' style="text-align: center;margin-bottom: 2%;">
          <input value='' class='txtdata' id="afterdate_keyword"  placeholder='To'   data-date-format="YYYY-MM-DD" type="text" name='afterdate_keyword'  style="text-align: center;padding: 0px;">
        </div>

        <div class="col-sm-4" style="text-align: center;">
          <button type='button' class='btn btn-primary' onclick='search_freetext(this)' style="line-height: 200%;margin-top: 2%;width: 100%;">Search</button>
        </div>
      </div>
    </div>
  </form>
</div>


                        <div class="se-pre-con1"><img src="dist/img/Preloader_3.gif"></div>
                        <div id="messsssage"></div>
</div><!--/. container-fluid -->

            

 </section>
    <!-- /.content -->
    

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

<script type="text/javascript">

function search(button) {

    var parent = document.getElementById('messsssage');
    parent.innerHTML ="";
    $(".se-pre-con1").show();
// var rowname= "row";
// rowname+=value;
// row = document.getElementById(rowname);
//   var id = value;

mrn=document.getElementById('mrn').value;
agerange1=document.getElementById('agerange1').value;
agerange2=document.getElementById('agerange2').value;
gender=document.getElementById('gender').value;
nationality=document.getElementById('nationality').value;
beforedate=document.getElementById('beforedate').value;
afterdate=document.getElementById('afterdate').value;
admfrom=document.getElementById('admfrom').value;
current_location=document.getElementById('current_location').value;
var checked = document.querySelectorAll('#admissiondiagnosis :checked');
var admissiondiagnosis = [...checked].map(option => option.value);
dischargedto=document.getElementById('dischargedto').value;
delay=document.getElementById('delay').value;
mortality=document.getElementById('mortality').value;
consultant=document.getElementById('consultant').value;
dxcondition=document.querySelector('input[name=dxcondition]:checked').value;
// longterm=document.getElementById('longterm').value;

var longtermbox = document.getElementById('longterm');
if(longtermbox.checked === true){
          var longterm = document.getElementById('longterm').value;
        }else{
          var longterm = '';
        }



// only=document.getElementById('only').value;

var onlybox = document.getElementById('only');
if(onlybox.checked === true){
          var only = document.getElementById('only').value;
        }else{
          var only = '';
        }


        var readmissionbox = document.getElementById('readmission');
if(readmissionbox.checked === true){
          var readmission = document.getElementById('readmission').value;
        }else{
          var readmission = '';
        }

    button.disabled = true;
    button.innerHTML = '<div class="spinner-border spinner-border-sm" role="status"><span class="sr-only">Loading...</span></div>';

data = {mrn: mrn, agerange1: agerange1, agerange2: agerange2, gender: gender, nationality: nationality,beforedate:beforedate,
    afterdate:afterdate, admfrom:admfrom,current_location:current_location,admissiondiagnosis:admissiondiagnosis,dischargedto:dischargedto, delay:delay
, mortality:mortality, consultant:consultant, longterm:longterm, readmission:readmission, only:only,dxcondition:dxcondition};
            
            $.post('registry/search-results.php', data, function(data){
$(parent).html(data);
// return false
// location.reload();
$(".se-pre-con1").hide();
    setTimeout(function() {
            button.disabled = false;
            button.innerHTML = 'Search'
     }, 000); // Simulate a delay of 2 seconds
});

// alert(patientId);
//   row.style.display = "none";
  }


</script>

<script type="text/javascript">

function search_consultation(button) {

    var parent = document.getElementById('messsssage');
    parent.innerHTML ="";
    $(".se-pre-con1").show();
// var rowname= "row";
// rowname+=value;
// row = document.getElementById(rowname);
//   var id = value;

mrn_consultation=document.getElementById('mrn_consultation').value;
agerange1_consultation=document.getElementById('agerange1_consultation').value;
agerange2_consultation=document.getElementById('agerange2_consultation').value;
beforedate_consultation=document.getElementById('beforedate_consultation').value;
afterdate_consultation=document.getElementById('afterdate_consultation').value;
consultation_from=document.getElementById('consultation_from').value;
consultant_consultations=document.getElementById('consultant_consultations').value;
var checked = document.querySelectorAll('#indications :checked');
var indications = [...checked].map(option => option.value);
consultation_to_service=document.getElementById('consultation_to_service').value;
indcondition=document.querySelector('input[name=indcondition]:checked').value;
// longterm=document.getElementById('longterm').value;

var signoffbox = document.getElementById('signoff');
if(signoffbox.checked === true){
          var signoff = document.getElementById('signoff').value;
        }else{
          var signoff = '';
        }

    button.disabled = true;
    button.innerHTML = '<div class="spinner-border spinner-border-sm" role="status"><span class="sr-only">Loading...</span></div>';

data = {mrn_consultation: mrn_consultation, agerange1_consultation: agerange1_consultation, agerange2_consultation: agerange2_consultation
    ,beforedate_consultation:beforedate_consultation, afterdate_consultation:afterdate_consultation, consultation_from:consultation_from,
    indications:indications,consultation_to_service:consultation_to_service, indcondition:indcondition, signoff:signoff,consultant_consultations:consultant_consultations};
            
            $.post('registry/search-results-consultations.php', data, function(data){
$(parent).html(data);
// return false
// location.reload();
$(".se-pre-con1").hide();
    setTimeout(function() {
            button.disabled = false;
            button.innerHTML = 'Search'
     }, 000); // Simulate a delay of 2 seconds
});

// alert(patientId);
//   row.style.display = "none";
  }





  
function search_freetext(button) {
  var parent = document.getElementById('messsssage');
  parent.innerHTML ="";
  $(".se-pre-con1").show();

  var keyword = document.getElementById('keyword').value || '';
  var beforedate_keyword = document.getElementById('beforedate_keyword').value || '';
  var afterdate_keyword  = document.getElementById('afterdate_keyword').value  || '';

  button.disabled = true;
  button.innerHTML = '<div class="spinner-border spinner-border-sm" role="status"><span class="sr-only">Loading...</span></div>';

  var data = {
    keyword: keyword,
    beforedate_keyword: beforedate_keyword,
    afterdate_keyword:  afterdate_keyword
  };

  $.post('registry/search-results-diagnosis.php', data, function(data){
      $(parent).html(data);
      $(".se-pre-con1").hide();
      setTimeout(function() {
          button.disabled = false;
          button.innerHTML = 'Search'
      }, 0);
  });
}
$(function() {
  $('input[name="beforedate_keyword"]').daterangepicker({
    singleDatePicker: true,
    timePicker: false,
    autoUpdateInput: false,
    autoApply: true,
    showDropdowns: true,
    minYear: 2010,
    maxYear: parseInt(moment().format('YYYY'),10),
    locale: { format: 'YYYY-MM-DD' }
  }).on("apply.daterangepicker", function (e, picker) {
      picker.element.val(picker.startDate.format(picker.locale.format));
  });
});

$(function() {
  $('input[name="afterdate_keyword"]').daterangepicker({
    singleDatePicker: true,
    timePicker: false,
    autoUpdateInput: false,
    autoApply: true,
    showDropdowns: true,
    minYear: 2010,
    maxYear: parseInt(moment().format('YYYY'),10),
    locale: { format: 'YYYY-MM-DD' }
  }).on("apply.daterangepicker", function (e, picker) {
      picker.element.val(picker.startDate.format(picker.locale.format));
  });
});

</script>


<script>


function auto_grow(element) {
    element.style.height = "5px";
    element.style.height = (element.scrollHeight)+"px";
}
</script>

<script type="text/javascript">

$('.search_type').change(function(){
    // alert("press");
    var admissions = document.getElementById('admission_search');
    var consultations = document.getElementById('consultation_search');
    var diagnosis = document.getElementById('diagnosis_search');
if($(this).val() == 'admissions'){
    // alert("admissions");
    admissions.style.display = "block";
    consultations.style.display = "none";
    diagnosis.style.display = "none";
}
if($(this).val() == 'consultations'){
    // alert("consu");
    admissions.style.display = "none";
    consultations.style.display = "block";
    diagnosis.style.display = "none";
}
if($(this).val() == 'diagnosis'){
    // alert("consu");
    admissions.style.display = "none";
    consultations.style.display = "none";
    diagnosis.style.display = "block";
}
});


      $(document).ready(function() {
        $('.select2').select2({
            allowClear: true,
            placeholder: 'Select',
      
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

      
$(function() {
  $('input[name="beforedate"]').daterangepicker({
    singleDatePicker: true,
    timePicker: false,
    timePicker24Hour: false,
    showButtonPanel: false,
    autoUpdateInput: false,
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


$(function() {
  $('input[name="afterdate"]').daterangepicker({
    singleDatePicker: true,
    timePicker: false,
    timePicker24Hour: false,
    showButtonPanel: false,
    autoUpdateInput: false,
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


$(function() {
  $('input[name="beforedate_consultation"]').daterangepicker({
    singleDatePicker: true,
    timePicker: false,
    timePicker24Hour: false,
    showButtonPanel: false,
    autoUpdateInput: false,
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


$(function() {
  $('input[name="afterdate_consultation"]').daterangepicker({
    singleDatePicker: true,
    timePicker: false,
    timePicker24Hour: false,
    showButtonPanel: false,
    autoUpdateInput: false,
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

//prevent enter
$(document).ready(function() {
  $(window).keydown(function(event){
    if(event.keyCode == 13) {
      event.preventDefault();
      return false;
    }
  });
});
    </script>



