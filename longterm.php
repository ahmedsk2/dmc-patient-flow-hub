<?php 
session_start();

require_once "authCookieSessionValidate.php";

if(!$isLoggedIn) {
    header("Location: ./");
}

?>

  <!-- Navbar -->
<?php
require 'sidebar.php';
require ('dbconnect.php');
?>


<?php
   		
       $formationSQL = "SELECT * FROM picupatients WHERE longterm = 'longterm'";
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
            <h1 class="m-0 text-dark">Long Term Patients</h1>
          </div><!-- /.col -->
          <div class="col-sm-6">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
              <li class="breadcrumb-item active">Long Term Patients</li>
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
      

<div class="row">

 <div id="mypresentersTable" class="col-md-12">

            <!-- /.info-box -->
            <?php 
                               
            foreach ($consultants as $consultant){
              ?>




            <div class="card">
              <div  class="card-header">
                <h3 class="card-title"><i class="fas fa-user-tie text-info"></i> Dr. <?php echo $consultant['full_name'];  ?> Patient List</h3>
                <div id="addbtn" class='eachrow' style=' float: right; '>
  
                <!-- <a  class='btn btn-success'  href='#admiting_modal' data-toggle='modal'  style='color: aliceblue; line-height: 2;padding: 0px 15px;'>Add New Patient</a> -->
                  </td>
        
          </div>
              </div>
              <!-- /.card-header -->
              <div class="card-body">
                <div class="row table-responsive">
         
                          <table class="col-md-12" >
                            <thead   style="text-align: center;font-weight: 700;">
                            <tr>
                           
                              <td class="col-md-1">MRN</td>
                              <td class="col-md-2">Patient Name</td>
                              <td class="col-md-1">Admission Date</td>
                              <td class="col-md-1">LOS</td>
                              <td class="col-md-2">Diagnosis</td>

                            </tr>
                            </thead>
                                         <?php

                                        
                                                     foreach($olspatints as $s){

                                                           

                                                      if($s['consultant_id'] == $consultant['member_id'] ){


                                                      $decodedadmissiondx=json_decode($s['admissiondiagnosis']);
                                                  
                                                  
                                                      $today = date("Y-m-d");
                                                      $today1 = strtotime(date("Y-m-d"));
                                                      // $datediff = $today - strtotime($s['ADMDATE']);
                                                      // $LOS = $today->date_diff($s['ADMDATE'])->format("%a");
                                                      $timeDiff = abs($today1 - strtotime($s['ADMDATE']));

                                                      $LOS = $timeDiff/86400;  // 86400 seconds in one day

                                                      // $LOS= round($datediff / (60 * 60 * 24));
                                                      

                                                      
                                                    echo"  
                                                   
                                                    <tr class='eachrow'  id='row".$s['ID']."'>
                                                    
                                                    
                                                      
                                                      <td style='  padding: 0px 1%;text-align: center' class='eachcol mrn' >
                                                      <p>".$s['MRN']."</p>
                                                      </td>

                                                      <td style='  padding: 0px 1%;text-align: center' class='eachcol name'>
                                                      <p>".$s['PNAME']."</p>
                                                      </td>
                                            
                                                      <td style='  padding: 0px 1%;text-align: center' class='eachcol admdate'  scope='row' >
                                                      <p>".$s['ADMDATE']."</p>
                                                      </td>";

                                                      if ($LOS < $shortlos){
                                                        echo"   <td style='  padding: 0px 1%;text-align: center; background: #d4edda;' class='eachcol admdate'    scope='row' >";
                                                      } elseif   ($LOS > $longlos){
                                                        echo"   <td style='  padding: 0px 1%; background: #f8d7da;text-align: center' class='eachcol admdate'   scope='row' >";
                                                      }elseif   ($LOS >= $shortlos){
                                                        echo"   <td style='  padding: 0px 1%; background: #fff3cd;text-align: center' class='eachcol admdate'    scope='row' >";
                                                      }
                                                    

                                                      echo"
                                                      <p>".$LOS."</p>
                                                      </td>
                                                      ";
                                                   
                                                 echo"
                                                      <td style='  padding: 0px 1%;' class='eachcol admissiondiagnosis'>
                                                      <ul style='list-style-position: inside;margin: 1% 0% 1%;'>
                                                      ";
                                                
                                                      if (is_array($decodedadmissiondx)){
                                                        
                                                        foreach($decodedadmissiondx as $key => $value)
                                                  {
                                                    $formationSQL = "SELECT * FROM icd10 WHERE id='".$value."'";
                                                    $result1 = $mysqli->query($formationSQL);
                                                    $dxlist = $result1 -> fetch_array(MYSQLI_ASSOC);
                                                     
                                                
                                                      echo '<li>'.  $dxlist['name']. '</li>';
                                                  }}
                                                
                                                      echo"
                                                   </ul></td>


                                                  </tr >
                                                        
                                                      ";
                                                        }
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
            <?php } ?>
</div>
			
      
 </div> <!--row -->


</div><!--/. container-fluid -->

            

 </section>
    <!-- /.content -->
    

<!-- PAGE SCRIPTS -->

<!-- AdminLTE App -->
<script src="dist/js/adminlte.js"></script>

<!-- OPTIONAL SCRIPTS -->
<script src="dist/js/demo.js"></script>


  </div>
  <!-- /.content-wrapper -->
<?php
require 'footer.php';
?>




