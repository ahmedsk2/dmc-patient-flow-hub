<?php
require_once __DIR__ . '/../guard.php'; require_role([0]);
 
session_start();
date_default_timezone_set('Asia/Riyadh');
$today=date("Y-m-d");

require ('../dbconnect.php');
$kpi=$_REQUEST['kpi'];
$s_date=$_REQUEST['date'];
$interval=$_REQUEST['interval'];
?>
<script src="../vendor/Chart.js/4.4.2/chart.umd.min.js"></script>


<?php

$formationSQL = "SELECT * FROM members WHERE position = '3' AND active = 1";
$result1 = $mysqli->query($formationSQL);
$consultants = $result1 -> fetch_all(MYSQLI_ASSOC);

     ?>
   
    
<div class="row">
      <div id="mypresentersTable" class="col-md-12">

      <div class="card">
  <div  class="card-header">
  <h3 class="card-title"><i class="nav-icon fas fa-tachometer-alt text-info"></i> <?php echo ucwords($interval) ?> Overview:</h3><i style=" float: right; " download="download.png" id="btn-Preview-Image" type="button" value="Download" class="fas fa-download"></i>
  </div>
  <!-- /.card-header -->
  <div class="card-body">
  <div class="row" style="background: white" id="divpicture">



<?php
        $label=array();
        $admissions=array();
        $discharges=array();
        $newconsults=array();
        $signedoff=array();
        $chartdata=array();
        $chart_label='';
switch ($kpi){
    case 'los':
      $chart_label="Average lenght of Stay";
        echo "
        <div class='col-sm-12' style='position: relative; height:40vh'>
        <canvas id='myChartlos'></canvas>";
            switch ($interval){
            case 'daily':
            case 'monthly':
                                
                    $month = date("m",strtotime($s_date));
                    $mdate1_name=date("F",strtotime($s_date));
                    $ydate1=date("Y",strtotime($s_date));
                    $chart_title="Average Length of Stay for " . $mdate1_name . " " . $ydate1; 
                  
                    foreach($consultants as $c){

                    $formationSQL = "SELECT ADMDATE, DISDATE FROM picupatients WHERE MONTH(DISDATE) = ? AND YEAR(DISDATE) = ? AND consultant_id=? AND (current_location != 'ICU' or current_location is null)";
                    $stmt = $mysqli->prepare($formationSQL);
                    $stmt->bind_param('iii', $month, $ydate1, $c['member_id']);
                    $stmt->execute();
                    $result1 = $stmt->get_result();
                    $dates = $result1 -> fetch_all(MYSQLI_ASSOC);

                
                    $los=array();

                    foreach ($dates as $date){
                        $timeDiff = abs(strtotime($date['ADMDATE']) - strtotime($date['DISDATE']));

                        array_push($los,$timeDiff/86400);

                    }
                    // var_dump($los);
                    // $a = array_filter($los);
                    if(count($los)) {
                        $average = array_sum($los)/count($los);
                    }else {
                    $average = 0;
                    }

                    array_push($label,$c['full_name']);
                    array_push($chartdata,$average);
                  
                  }
                break;

            case "quarterly":
                $month = date("m",strtotime($s_date));
                $ydate1=date("Y",strtotime($s_date));

                if ($month >=1 && $month <=3){
                $quarter=1;
                $chart_title="Average Length of Stay for first quarter"  .$ydate1;
                }elseif($month >=4 && $month <=6){
                    $quarter=2;
                    $chart_title="Average Length of Stay for second quarter "  .$ydate1;
                }elseif($month >=7 && $month <=9){
                    $quarter=3;
                    $chart_title="Average Length of Stay for third quarter "  .$ydate1;
                }elseif($month >=10 && $month <=12){
                    $quarter=4;
                    $chart_title="Average Length of Stay for forth quarter " .$ydate1;
                }
                // by type of discharge
                foreach($consultants as $c){

                    $formationSQL = "SELECT ADMDATE, DISDATE FROM picupatients WHERE QUARTER(DISDATE) = ? AND YEAR(DISDATE) = ? AND consultant_id=? AND (current_location != 'ICU' or current_location is null)";
                    $stmt = $mysqli->prepare($formationSQL);
                    $stmt->bind_param('iii', $quarter, $ydate1, $c['member_id']);
                    $stmt->execute();
                    $result1 = $stmt->get_result();
                    $dates = $result1 -> fetch_all(MYSQLI_ASSOC);

                
                    $los=array();

                    foreach ($dates as $date){
                        $timeDiff = abs(strtotime($date['ADMDATE']) - strtotime($date['DISDATE']));

                        array_push($los,$timeDiff/86400);

                    }
                    // var_dump($los);
                    // $a = array_filter($los);
                    if(count($los)) {
                        $average = array_sum($los)/count($los);
                    }else {
                    $average = 0;
                    }

                    array_push($label,$c['full_name']);
                    array_push($chartdata,$average);
                  
                  }
               
            break;
            }
        break;

        case 'readmission':
        
          echo "
          <div class='col-sm-12' style='position: relative; height:40vh'>
          <canvas id='myChartlos'></canvas>";
          $chart_label="72 hours readmission count";
              switch ($interval){
              case 'daily':
              case 'monthly':
                                                    
                  $month = date("m", strtotime($s_date));
                  $mdate1_name = date("F", strtotime($s_date));
                  $ydate1 = date("Y", strtotime($s_date));
                  $chart_title = "Readmissions for " . $mdate1_name . " " . $ydate1;

                  // Fetch all admitted patients for the given month and year.
                  // Sargable half-open month range so the ADMDATE index is usable (was MONTH()/YEAR()).
                  $mStart = sprintf('%04d-%02d-01', $ydate1, $month);
                  $mEnd   = date('Y-m-t', strtotime($mStart));
                  $formationSQL = "
                      SELECT consultant_id, ID, MRN, ADMDATE
                      FROM picupatients
                      WHERE ADMDATE BETWEEN ? AND ?
                  ";
                  $stmt = $mysqli->prepare($formationSQL);
                  $stmt->bind_param('ss', $mStart, $mEnd);
                  $stmt->execute();
                  $result1 = $stmt->get_result();
                  $admitted_patients = $result1->fetch_all(MYSQLI_ASSOC);

                  $readmissions = [];

                  // Fetch all readmissions within 3 days for the admitted patients
                  if (!empty($admitted_patients)) {
                      $patient_ids = array_column($admitted_patients, 'ID');
                      $patient_mrns = array_column($admitted_patients, 'MRN');
                      $patient_adm_dates = array_column($admitted_patients, 'ADMDATE');

                      $ids_placeholder = implode(',', array_fill(0, count($patient_ids), '?'));
                      $mrns_placeholder = implode(',', array_fill(0, count($patient_mrns), '?'));
                      $dates_placeholder = implode(',', array_fill(0, count($patient_adm_dates), '?'));

                      $formationSQL = "
                          SELECT *
                          FROM picupatients
                          WHERE DISDATE + INTERVAL 3 DAY >= ? AND ID < ? AND MRN = ? 
                          AND (trans_discharge = 'discharge from ICU' OR trans_discharge = 'discharge from ward' OR trans_discharge IS NULL)
                          LIMIT 1
                      ";

                      $stmt = $mysqli->prepare($formationSQL);
                      foreach ($admitted_patients as $s) {
                          $stmt->bind_param('sis', $s['ADMDATE'], $s['ID'], $s['MRN']);
                          $stmt->execute();
                          $result2 = $stmt->get_result();
                          $readmission = $result2->fetch_array(MYSQLI_ASSOC);

                          if ($readmission) {
                              $readmissions[] = $readmission;
                          }
                      }
                  }

                  foreach ($consultants as $c) {
                      $readmission_count = 0;

                      foreach ($readmissions as $recentadmission) {
                          if ($c['member_id'] == $recentadmission['consultant_id']) {
                              $readmission_count++;
                          }
                      }

                      array_push($label, $c['full_name']);
                      array_push($chartdata, $readmission_count);
                  }

                  break;
  
              case "quarterly":
                  $month = date("m",strtotime($s_date));
                  $ydate1=date("Y",strtotime($s_date));
                  

                  if ($month >=1 && $month <=3){
                  $quarter=1;
                  $chart_title="72 hours Readmissions for first quarter"  .$ydate1;
                  }elseif($month >=4 && $month <=6){
                      $quarter=2;
                      $chart_title="72 hours Readmissions for second quarter "  .$ydate1;
                  }elseif($month >=7 && $month <=9){
                      $quarter=3;
                      $chart_title="72 hours Readmissions for third quarter "  .$ydate1;
                  }elseif($month >=10 && $month <=12){
                      $quarter=4;
                      $chart_title="72 hours Readmissions for forth quarter " .$ydate1;
                  }
                  foreach($consultants as $c){
                          
                    /////////////////////
                        // readmissions
                    //////////////////////////
                    // Sargable quarter range so the ADMDATE index is usable (was QUARTER()/YEAR()).
                    $qStart = sprintf('%04d-%02d-01', $ydate1, ($quarter - 1) * 3 + 1);
                    $qEnd   = date('Y-m-t', strtotime($qStart . ' +2 month'));
                    $formationSQL = "SELECT consultant_id,ID, MRN, ADMDATE FROM picupatients WHERE ADMDATE BETWEEN ? AND ?";
                    $stmt = $mysqli->prepare($formationSQL);
                    $stmt->bind_param('ss', $qStart, $qEnd);
                    $stmt->execute();
                    $result1 = $stmt->get_result();
                    $admitted_patients = $result1 -> fetch_all(MYSQLI_ASSOC);

                    $readmission_count=0;
                    // echo $date1 ."</br>";
                    $formationSQL = "SELECT * FROM picupatients WHERE DISDATE + INTERVAL 3 DAY >=? AND ID < ?  AND MRN=? AND (trans_discharge = 'discharge from ICU' or trans_discharge='discharge from ward' or trans_discharge IS NULL) LIMIT 1";
                    $stmt = $mysqli->prepare($formationSQL);
                    foreach ($admitted_patients as $s){
                    $stmt->bind_param('sis', $s['ADMDATE'], $s['ID'], $s['MRN']);
                    $stmt->execute();
                    $result1 = $stmt->get_result();
                    $recentadmission = $result1 -> fetch_array(MYSQLI_ASSOC);

                   if ($c['member_id'] == $recentadmission['consultant_id']){
                    $readmission_count=$readmission_count+1;
                    // var_dump($recentadmission);
                  }
                    }

                  array_push($label,$c['full_name']);
                  array_push($chartdata,$readmission_count);
                
                }
                 
              break;
              }
          break;
    case 'admission':
        echo "
        <div class='col-sm-12' style='position: relative; height:40vh'>
        <canvas id='myChartadmission'></canvas>"; 
// echo "ahmed";
        
        $month = date("m",strtotime($s_date));
        $mdate1_name=date("F",strtotime($s_date));
        $ydate1=date("Y",strtotime($s_date));

            switch ($interval){
            case 'daily':
                $chart_title="Admissions / Consultations for "  .$s_date;
                // var_dump($consultants);
                foreach($consultants as $c){
                    $formationSQL = "SELECT * FROM picupatients WHERE ADMDATE = ?  AND consultant_id=? AND (current_location != 'ICU' or current_location is null)";
                    $stmt = $mysqli->prepare($formationSQL);
                    $stmt->bind_param('si', $s_date, $c['member_id']);
                    $stmt->execute();
                    $result1 = $stmt->get_result();
                    $admittedpcount = mysqli_num_rows($result1);

                    $formationSQL = "SELECT * FROM picupatients WHERE DISDATE = ? AND consultant_id=? AND (current_location != 'ICU' or current_location is null)";
                    $stmt = $mysqli->prepare($formationSQL);
                    $stmt->bind_param('si', $s_date, $c['member_id']);
                    $stmt->execute();
                    $result1 = $stmt->get_result();
                    $dischargedpcount = mysqli_num_rows($result1);

                    $formationSQL = "SELECT * FROM consultations WHERE consultation_date = ?  AND consultant_id=? ";
                    $stmt = $mysqli->prepare($formationSQL);
                    $stmt->bind_param('si', $s_date, $c['member_id']);
                    $stmt->execute();
                    $result1 = $stmt->get_result();
                    $newconsultscount = mysqli_num_rows($result1);

                    $formationSQL = "SELECT * FROM consultations WHERE signoff_date = ?  AND consultant_id=? ";
                    $stmt = $mysqli->prepare($formationSQL);
                    $stmt->bind_param('si', $s_date, $c['member_id']);
                    $stmt->execute();
                    $result1 = $stmt->get_result();
                    $signedoffcount = mysqli_num_rows($result1);
            
                    array_push($label,$c['full_name']);
                    array_push($admissions,$admittedpcount);
                    array_push($discharges,$dischargedpcount);
                    array_push($newconsults,$newconsultscount);
                    array_push($signedoff,$signedoffcount);
                }

                break;
            case 'monthly':
                                 
                    $chart_title="Admissions / Consultations for " . $mdate1_name . " " . $ydate1; 
                    foreach($consultants as $c){
                        $formationSQL = "SELECT * FROM picupatients WHERE MONTH(ADMDATE) = ? AND YEAR(ADMDATE) = ? AND consultant_id=? AND (current_location != 'ICU' or current_location is null)";
                        $stmt = $mysqli->prepare($formationSQL);
                        $stmt->bind_param('iii', $month, $ydate1, $c['member_id']);
                        $stmt->execute();
                        $result1 = $stmt->get_result();
                        $admittedpcount = mysqli_num_rows($result1);
                        $formationSQL = "SELECT * FROM picupatients WHERE MONTH(DISDATE) = ? AND YEAR(DISDATE) = ? AND consultant_id=? AND (current_location != 'ICU' or current_location is null)";
                        $stmt = $mysqli->prepare($formationSQL);
                        $stmt->bind_param('iii', $month, $ydate1, $c['member_id']);
                        $stmt->execute();
                        $result1 = $stmt->get_result();
                        $dischargedpcount = mysqli_num_rows($result1);

                        $formationSQL = "SELECT * FROM consultations WHERE MONTH(consultation_date) = ? AND YEAR(consultation_date) = ?  AND consultant_id=? ";
                        $stmt = $mysqli->prepare($formationSQL);
                        $stmt->bind_param('iii', $month, $ydate1, $c['member_id']);
                        $stmt->execute();
                        $result1 = $stmt->get_result();
                        $newconsultscount = mysqli_num_rows($result1);

                        $formationSQL = "SELECT * FROM consultations WHERE MONTH(signoff_date) = ? AND YEAR(signoff_date) = ?  AND consultant_id=? ";
                        $stmt = $mysqli->prepare($formationSQL);
                        $stmt->bind_param('iii', $month, $ydate1, $c['member_id']);
                        $stmt->execute();
                        $result1 = $stmt->get_result();
                        $signedoffcount = mysqli_num_rows($result1);
                
                        array_push($label,$c['full_name']);
                        array_push($admissions,$admittedpcount);
                        array_push($discharges,$dischargedpcount);
                        array_push($newconsults,$newconsultscount);
                        array_push($signedoff,$signedoffcount);
                    }
             

                break;

            case "quarterly":
               
                if ($month >=1 && $month <=3){
                $quarter=1;
                $chart_title="Admissions / Consultations for first quarter"  .$ydate1;
                }elseif($month >=4 && $month <=6){
                    $quarter=2;
                    $chart_title="Admissions / Consultations for second quarter "  .$ydate1;
                }elseif($month >=7 && $month <=9){
                    $quarter=3;
                    $chart_title="Admissions / Consultations for third quarter "  .$ydate1;
                }elseif($month >=10 && $month <=12){
                    $quarter=4;
                    $chart_title="Admissions / Consultations for forth quarter " .$ydate1;
                }
      
              
                    foreach($consultants as $c){
                        $formationSQL = "SELECT * FROM picupatients WHERE QUARTER(ADMDATE) = ? AND YEAR(ADMDATE) = ? AND consultant_id=? AND (current_location != 'ICU' or current_location is null)";
                        $stmt = $mysqli->prepare($formationSQL);
                        $stmt->bind_param('iii', $quarter, $ydate1, $c['member_id']);
                        $stmt->execute();
                        $result1 = $stmt->get_result();
                        $admittedpcount = mysqli_num_rows($result1);
                        $formationSQL = "SELECT * FROM picupatients WHERE QUARTER(DISDATE) = ? AND YEAR(DISDATE) = ? AND consultant_id=? AND (current_location != 'ICU' or current_location is null)";
                        $stmt = $mysqli->prepare($formationSQL);
                        $stmt->bind_param('iii', $quarter, $ydate1, $c['member_id']);
                        $stmt->execute();
                        $result1 = $stmt->get_result();
                        $dischargedpcount = mysqli_num_rows($result1);

                        $formationSQL = "SELECT * FROM consultations WHERE QUARTER(consultation_date) = ? AND YEAR(consultation_date) = ?  AND consultant_id=? ";
                        $stmt = $mysqli->prepare($formationSQL);
                        $stmt->bind_param('iii', $quarter, $ydate1, $c['member_id']);
                        $stmt->execute();
                        $result1 = $stmt->get_result();
                        $newconsultscount = mysqli_num_rows($result1);

                        $formationSQL = "SELECT * FROM consultations WHERE QUARTER(signoff_date) = ? AND YEAR(signoff_date) = ?  AND consultant_id=? ";
                        $stmt = $mysqli->prepare($formationSQL);
                        $stmt->bind_param('iii', $quarter, $ydate1, $c['member_id']);
                        $stmt->execute();
                        $result1 = $stmt->get_result();
                        $signedoffcount = mysqli_num_rows($result1);
                
                        array_push($label,$c['full_name']);
                        array_push($admissions,$admittedpcount);
                        array_push($discharges,$dischargedpcount);
                        array_push($newconsults,$newconsultscount);
                        array_push($signedoff,$signedoffcount);
                    }
            break;
            }
    break;
    }
?>

</div><!-- end colomn  -->


<script>
        $(document).ready(function() {


            let element = $("#divpicture"); // global variable
            let getCanvas; // global variable
            let newData;

            $("#btn-Preview-Image").on('click', function() {
                html2canvas(element, {
                    onrendered: function(canvas) {
                        getCanvas = canvas;
                        let imgageData = getCanvas.toDataURL("image/png");
                        let a = document.createElement("a");
                        a.href = imgageData; //Image Base64 Goes here
                        a.download = "Overview.png"; //File name Here
                        a.click(); //Downloaded file
                    }
                });
            });


        });
    </script>

</div><!-- end row -->
</div><!-- end colomn  -->
</div><!-- end row -->
  </div>
  <!-- /.card-body -->
</div>
<!-- /.card -->


<script>
          $(document).ready(function() {
  let label = <?php echo json_encode($label); ?>;
  let chartdata = <?php echo json_encode($chartdata); ?>;

  // let consultant = [];
  // let admissions = [];

  // for(let i=0; i<jArray.length; i++){
  //   consultant.push("Dr. " + jArray[i].full_name);
  //   admissions.push(jArray[i].admittions);
  //   // alert(jArray[i].full_name);
  //   }
    // alert(JSON.stringify(label));
  let mlabels = label;

  let mdata = {
    labels: mlabels,
    datasets: [{
      label: '<?php echo $chart_label; ?>',
      backgroundColor: 'rgb(41, 134, 204, 0.9)',
      borderColor: 'rgb(41, 134, 204, 0.9)',
      data: chartdata,
      fill: true,
      stack: 'Stack 0',
    }]
  };
  let colors = ['red', 'blue', 'yellow', 'green'];
  let weekend =[];
  let mconfig = {
    type: 'bar',
    
    data: mdata,
    options: {
      maintainAspectRatio: false,
    plugins: {
      filler: {
        propagate: false,
      },
      title: {
        display: true,
        text: '<?php echo $chart_title; ?>'
      }
    },
    responsive: true,
    interaction: {
      intersect: false,
    },
    scales: {
        y: {
            beginAtZero: true,
            stacked: true,
        },
      x: {
        stacked: true,
        ticks: {
          callback: function(value, index, ticks) {
                    let day =new Date(this.getLabelForValue(value));
                    // alert (day.getDay());
                    if (day.getDay() == 6 || day.getDay() == 5){
                                  // alert("this one");
                                  weekend.push(value);
                                }

                    return this.getLabelForValue(value);

                    },
          color: (c) => {
            if (weekend.includes(c.index)){
            return colors[0]
            }
          }
        }
      },
        }
  },
  };


  let myChartlos = new Chart(
    document.getElementById('myChartlos'),
    mconfig
  );
 
        });
</script>


<script>
  (function() {
  let label = <?php echo json_encode($label); ?>;
  let admissions = <?php echo json_encode($admissions); ?>;
  let discharges = <?php echo json_encode($discharges); ?>;
  let newconsults = <?php echo json_encode($newconsults); ?>;
  let signedoff = <?php echo json_encode($signedoff); ?>;

  // let consultant = [];
  // let admissions = [];

  // for(let i=0; i<jArray.length; i++){
  //   consultant.push("Dr. " + jArray[i].full_name);
  //   admissions.push(jArray[i].admittions);
  //   // alert(jArray[i].full_name);
  //   }
    // alert(JSON.stringify(label));
  let labels = label;

  let data = {
    labels: labels,
    datasets: [{
      label: 'Admissions',
      backgroundColor: 'rgb(41, 134, 204, 0.9)',
      borderColor: 'rgb(41, 134, 204, 0.9)',
      data: admissions,
      fill: true,
      stack: 'Stack 0',
    },
    {
      label: 'Discharges',
      backgroundColor: 'rgb(204, 41, 134, 0.9)',
      borderColor: 'rgb(204, 41, 134, 0.9)',
      data: discharges,
      fill: true,
      stack: 'Stack 0',
    },
    {
      label: 'New Consultations',
      backgroundColor: 'rgb(75, 192, 192, 0.9)',
      borderColor: 'rgb(75, 192, 192, 0.9)',
      data: newconsults,
      fill: true,
      stack: 'Stack 1',
    },
    {
      label: 'Signed Off Consultations',
      backgroundColor: 'rgb(255, 205, 86, 0.9)',
      borderColor: 'rgb(255, 205, 86, 0.9)',
      data: signedoff,
      fill: true,
      stack: 'Stack 1',
    }]
  };
  let colors = ['red', 'blue', 'yellow', 'green'];
  let weekend =[];
  let config = {
    type: 'bar',
    
    data: data,
    options: {
      maintainAspectRatio: false,
    plugins: {
      filler: {
        propagate: false,
      },
      title: {
        display: true,
        text: '<?php echo $chart_title; ?>'
      }
    },
    responsive: true,
    interaction: {
      intersect: false,
    },
    scales: {
        y: {
            beginAtZero: true,
            stacked: true,
        },
      x: {
        stacked: true,
        ticks: {
          callback: function(value, index, ticks) {
                    let day =new Date(this.getLabelForValue(value));
                    // alert (day.getDay());
                    if (day.getDay() == 6 || day.getDay() == 5){
                                  // alert("this one");
                                  weekend.push(value);
                                }

                    return this.getLabelForValue(value);

                    },
          color: (c) => {
            if (weekend.includes(c.index)){
            return colors[0]
            }
          }
        }
      },
        }
  },
  };


  let myChartadmission = new Chart(
    document.getElementById('myChartadmission'),
    config
  );
 })();
</script>
