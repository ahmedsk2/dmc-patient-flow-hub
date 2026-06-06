<?php 
session_start();
date_default_timezone_set('Asia/Riyadh');
$today=date("Y-m-d");

require ('../dbconnect.php');
$id=$_REQUEST['consultant'];
$s_date=$_REQUEST['date'];
$interval=$_REQUEST['interval'];
?>
<script src="../vendor/Chart.js/4.4.2/chart.umd.min.js"></script>


<?php


  
$formationSQL = "SELECT * FROM members WHERE member_id = '".$id."'";
$result1 = $mysqli->query($formationSQL);
$consultant_name = $result1 -> fetch_array(MYSQLI_ASSOC);


     ?>
   
    
<div class="row">
      <div id="mypresentersTable" class="col-md-12">

      <div class="card">
  <div  class="card-header">
  <h3 class="card-title"><i class="nav-icon fas fa-tachometer-alt text-info"></i> <?php echo ucwords($interval) ?> Overview for: Dr.  <?php echo $consultant_name['full_name'] ?></h3><i style=" float: right; " download="download.png" id="btn-Preview-Image1" type="button" value="Download" class="fas fa-download"></i>
  </div>
  <!-- /.card-header -->
  <div class="card-body">
  <div class="row" style="background: white" id="divpicture1">


  <div class="col-sm-6" style="height: fit-content;">
<canvas id="myChartdoughnut"></canvas>
<?php



switch ($interval){
  case 'daily':
  case 'monthly':
$month = date("m",strtotime($s_date));
$mdate1_name=date("F",strtotime($s_date));
$ydate1=date("Y",strtotime($s_date));
$title="Patient Destination for " . $mdate1_name . " " .$ydate1;
// by type of discharge
$formationSQL = "SELECT trans_discharge, COUNT(trans_discharge) FROM picupatients WHERE MONTH(DISDATE) = '".$month."' AND YEAR(DISDATE) = '".$ydate1."' AND consultant_id='".$id."' AND (current_location != 'ICU' or current_location is null) GROUP BY trans_discharge;";
$result1 = $mysqli->query($formationSQL);
$dischargedpatients = $result1 -> fetch_all(MYSQLI_ASSOC);

// var_dump($dischargedpatients);

$transin=0;
$discharged=0;
$transout=0;
$icudischarge=0;
foreach ($dischargedpatients as $dp){

  if($dp['trans_discharge'] == 'transfer to other speciality'){
      $transin=$dp['COUNT(trans_discharge)'];
  }
  
  if($dp['trans_discharge'] == 'discharge from ward'){
      $discharged=$dp['COUNT(trans_discharge)'];
      
  }
  if($dp['trans_discharge'] == 'other transfer'){
      $transout=$dp['COUNT(trans_discharge)'];
  }
  if($dp['trans_discharge'] == 'discharge from ICU'){
      $icudischarge=$dp['COUNT(trans_discharge)'];
  }

}
/// all counts are
$all_counts=[$discharged,$transin,$transout,$icudischarge];
$sum=array_sum($all_counts);
$label1=["Discharged","Intra Department Transfer","Out Department Transfer","Discharged from ICU"];
break;
case "quarterly":
  $month = date("m",strtotime($s_date));
  $ydate1=date("Y",strtotime($s_date));
  if ($month >=1 && $month <=3){
$quarter=1;
$title="Patient Destination for first quarter"  .$ydate1;
  }elseif($month >=4 && $month <=6){
    $quarter=2;
    $title="Patient Destination for second quarter "  .$ydate1;
  }elseif($month >=7 && $month <=9){
    $quarter=3;
    $title="Patient Destination for third quarter "  .$ydate1;
  }elseif($month >=10 && $month <=12){
    $quarter=4;
    $title="Patient Destination for forth quarter " .$ydate1;
  }
  // by type of discharge
  $formationSQL = "SELECT trans_discharge, COUNT(trans_discharge) FROM picupatients WHERE QUARTER(DISDATE) = '".$quarter."' AND YEAR(DISDATE) = '".$ydate1."' AND consultant_id='".$id."' AND (current_location != 'ICU' or current_location is null) GROUP BY trans_discharge;";
  $result1 = $mysqli->query($formationSQL);
  $dischargedpatients = $result1 -> fetch_all(MYSQLI_ASSOC);
  
  // var_dump($dischargedpatients);
  
  $transin=0;
  $discharged=0;
  $transout=0;
  $icudischarge=0;
  
  foreach ($dischargedpatients as $dp){
  
    if($dp['trans_discharge'] == 'transfer to other speciality'){
        $transin=$dp['COUNT(trans_discharge)'];
    }
    
    if($dp['trans_discharge'] == 'discharge from ward'){
        $discharged=$dp['COUNT(trans_discharge)'];
        
    }
    if($dp['trans_discharge'] == 'other transfer'){
        $transout=$dp['COUNT(trans_discharge)'];
    }
    if($dp['trans_discharge'] == 'discharge from ICU'){
        $icudischarge=$dp['COUNT(trans_discharge)'];
    }
  
  }
  /// all counts are
  $all_counts=[$discharged,$transin,$transout,$icudischarge];
  $sum=array_sum($all_counts);
  $label1=["Discharged","Intra Department Transfer","Out Department Transfer","Discharged from ICU"];
  break;

}
?>
<script>

(function() {
let label1 = <?php echo json_encode($label1); ?>;
let all_counts = <?php echo json_encode($all_counts); ?>;
// var consultant = [];
// var admissions = [];

// for(var i=0; i<jArray.length; i++){
//   consultant.push("Dr. " + jArray[i].full_name);
//   admissions.push(jArray[i].admittions);
//   // alert(jArray[i].full_name);
//   }
  // alert(JSON.stringify(label));
let labels1 = label1;

let data1 = {
  labels: labels1,
  datasets: [{
    label: 'Patient Destination',
    backgroundColor:  [
    'rgb(255, 99, 132)',
    'rgb(54, 162, 235)',
    'rgb(255, 205, 86)',
    'rgb(36, 169, 115)'
  ],
    data: all_counts,
  }]
};

let config1 = {
  
  type: 'doughnut',
  data: data1,
  options: {
  responsive: true,
  plugins: {
    legend: {
      position: 'top',
    },
    title: {
      display: true,
      text: '<?php echo $title ." (" .  $sum . ")"; ?>',
    }
  }
},
};


let myChartdoughnut = new Chart(
  document.getElementById('myChartdoughnut'),
  config1
);
})();
</script>

</div><!-- end colomn  -->
<div class="col-sm-6" style="height: fit-content;">
<canvas id="myChartdischarge"></canvas>
<?php


switch ($interval){
  case 'daily':
  case 'monthly':
$month = date("m",strtotime($s_date));
$mdate1_name=date("F",strtotime($s_date));
$ydate1=date("Y",strtotime($s_date));
$title="Discharged patients for " . $mdate1_name . " " .$ydate1;

// by type of discharge
$formationSQL = "SELECT DISTO, COUNT(DISTO) FROM picupatients WHERE MONTH(DISDATE) = '".$month."' AND YEAR(DISDATE) = '".$ydate1."' AND consultant_id='".$id."' AND (current_location != 'ICU' or current_location is null) GROUP BY DISTO;";
$result1 = $mysqli->query($formationSQL);
$dischargedpatients1 = $result1 -> fetch_all(MYSQLI_ASSOC);

// var_dump($dischargedpatients1);

$home=0;
$lama=0;
$otherfacility=0;
$abscanded=0;
$mortuary=0;
$transfer=0;
foreach ($dischargedpatients1 as $dp){

  if($dp['DISTO'] == 'Home'){
      $home=$dp['COUNT(DISTO)'];
  }
  
  elseif($dp['DISTO'] == 'Other Facility'){
      $lama=$dp['COUNT(DISTO)'];
      
  }
  elseif($dp['DISTO'] == 'LAMA'){
      $otherfacility=$dp['COUNT(DISTO)'];
  }
  elseif($dp['DISTO'] == 'Absconded'){
      $abscanded=$dp['COUNT(DISTO)'];
  }
  elseif($dp['DISTO'] == 'Mortuary'){
      $mortuary=$dp['COUNT(DISTO)'];
  }else{
    $transfer=$transfer+$dp['COUNT(DISTO)'];
  }

}
/// all counts are
$all_counts=[$home,$lama,$otherfacility,$abscanded,$mortuary,$transfer];
$sum=array_sum($all_counts);
$label1=["Home","Other Facility","LAMA","Absconded","Mortuary"];
break;
case "quarterly":
  $month = date("m",strtotime($s_date));
  $ydate1=date("Y",strtotime($s_date));
  if ($month >=1 && $month <=3){
    $quarter=1;
    $title="Discharged patients for first quarter"  .$ydate1;
      }elseif($month >=4 && $month <=6){
        $quarter=2;
        $title="Discharged patients for second quarter "  .$ydate1;
      }elseif($month >=7 && $month <=9){
        $quarter=3;
        $title="Discharged patients for third quarter "  .$ydate1;
      }elseif($month >=10 && $month <=12){
        $quarter=4;
        $title="Discharged patients for forth quarter " .$ydate1;
      }  
  // by type of discharge
  $formationSQL = "SELECT DISTO, COUNT(DISTO) FROM picupatients WHERE QUARTER(DISDATE) = '".$quarter."' AND YEAR(DISDATE) = '".$ydate1."' AND consultant_id='".$id."' AND (current_location != 'ICU' or current_location is null) GROUP BY DISTO;";
  $result1 = $mysqli->query($formationSQL);
  $dischargedpatients1 = $result1 -> fetch_all(MYSQLI_ASSOC);
  
  // var_dump($dischargedpatients1);
  
  $home=0;
  $lama=0;
  $otherfacility=0;
  $abscanded=0;
  $mortuary=0;
  $transfer=0;
  foreach ($dischargedpatients1 as $dp){
  
    if($dp['DISTO'] == 'Home'){
        $home=$dp['COUNT(DISTO)'];
    }
    
    elseif($dp['DISTO'] == 'Other Facility'){
        $lama=$dp['COUNT(DISTO)'];
        
    }
    elseif($dp['DISTO'] == 'LAMA'){
        $otherfacility=$dp['COUNT(DISTO)'];
    }
    elseif($dp['DISTO'] == 'Absconded'){
        $abscanded=$dp['COUNT(DISTO)'];
    }
    elseif($dp['DISTO'] == 'Mortuary'){
        $mortuary=$dp['COUNT(DISTO)'];
      }else{
        $transfer=$transfer+$dp['COUNT(DISTO)'];
      }
    
    }
    /// all counts are
    $all_counts1=[$home,$lama,$otherfacility,$abscanded,$mortuary,$transfer];
  $sum=array_sum($all_counts1);
  $label1=["Home","Other Facility","LAMA","Absconded","Mortuary", "Transfer"];
  break;
}
?>
<script>

(function() {
let label2 = <?php echo json_encode($label1); ?>;
let all_counts1 = <?php echo json_encode($all_counts1); ?>;
// var consultant = [];
// var admissions = [];

// for(var i=0; i<jArray.length; i++){
//   consultant.push("Dr. " + jArray[i].full_name);
//   admissions.push(jArray[i].admittions);
//   // alert(jArray[i].full_name);
//   }
  // alert(JSON.stringify(label));
let labels2 = label2;

let data2 = {
  labels: labels2,
  datasets: [{
    label: 'Patient Destination',
    backgroundColor:  [
    'rgb(255, 99, 132)',
    'rgb(54, 162, 235)',
    'rgb(255, 205, 86)',
    'rgb(36, 169, 115)',
    'rgb(152, 23, 166)',
    'rgb(166, 152, 23)'
  ],
    data: all_counts1,
  }]
};

let config2 = {
  
  type: 'doughnut',
  data: data2,
  options: {
  responsive: true,
  plugins: {
    legend: {
      position: 'top',
    },
    title: {
      display: true,
      text: '<?php echo $title ." (" .  $sum . ")"; ?>',
    }
  }
},
};


let myChartdischarge = new Chart(
  document.getElementById('myChartdischarge'),
  config2
);
})();
</script>

</div><!-- end colomn  -->

</div><!-- end row -->



<script>
        $(document).ready(function() {


            let element = $("#divpicture1"); // global variable
            let getCanvas; // global variable
            let newData;

            $("#btn-Preview-Image1").on('click', function() {
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


</div><!-- end colomn  -->
</div><!-- end row -->
  </div>
  <!-- /.card-body -->
</div>
<!-- /.card -->





      <div class="row">
      <div id="mypresentersTable" class="col-md-12">

      <div class="card">
  <div  class="card-header">
  <h3 class="card-title"><i class="nav-icon fas fa-tachometer-alt text-info"></i> <?php echo ucwords($interval) ?> Overview for: Dr.  <?php echo $consultant_name['full_name'] ?></h3><i style=" float: right; " download="download.png" id="btn-Preview-Image2" type="button" value="Download" class="fas fa-download"></i>
  </div>
  <!-- /.card-header -->
  <div class="card-body">
  <div class="row" style="background: white" id="divpicture2">

<div class="col-sm-12">
<div class="chart-container" style="position: relative; height:40vh">
<canvas id="mymonthlyChart"></canvas>
</div>
<?php
$label=array();
$admissions=array();
$discharges=array();
$newconsults=array();
$signedoff=array();

$date=new DateTime();
$n=0;

$mdate1=date("m",strtotime($s_date));
$mdate1_name=date("F",strtotime($s_date));
$ydate1=date("Y",strtotime($s_date));

switch ($interval) {
  case 'daily':
      $chart_title = "Daily Overview for " . $mdate1_name . " " . $ydate1;
      $ydate1 = date("Y", strtotime($s_date));
      $lastDateOfMonth = date("Y-m-t", strtotime($s_date));
      $ds = cal_days_in_month(CAL_GREGORIAN, $mdate1, $ydate1);

      $dates = [];
      while ($n < $ds) {
          $dates[] = $lastDateOfMonth;
          $n++;
          $time = strtotime($lastDateOfMonth);
          $lastDateOfMonth = date("Y-m-d", strtotime("-1 day", $time));
      }

      $dateList = implode("','", $dates);

      $formationSQL = "SELECT ADMDATE, DISDATE, consultation_date, signoff_date,
                      SUM(CASE WHEN ADMDATE IN ('" . $dateList . "') THEN 1 ELSE 0 END) AS admittedpcount,
                      SUM(CASE WHEN DISDATE IN ('" . $dateList . "') THEN 1 ELSE 0 END) AS dischargedpcount,
                      SUM(CASE WHEN consultation_date IN ('" . $dateList . "') THEN 1 ELSE 0 END) AS newconsultscount,
                      SUM(CASE WHEN signoff_date IN ('" . $dateList . "') THEN 1 ELSE 0 END) AS signedoffcount
                      FROM picupatients
                      LEFT JOIN consultations ON picupatients.consultant_id = consultations.consultant_id
                      WHERE picupatients.consultant_id='" . $id . "'
                      AND (picupatients.current_location != 'ICU' OR picupatients.current_location IS NULL)
                      AND (consultations.consultant_id = '" . $id . "')";

      $result1 = $mysqli->query($formationSQL);
      $data = $result1->fetch_assoc();

      foreach ($dates as $date) {
          array_push($label, $date);
          array_push($admissions, $data['admittedpcount']);
          array_push($discharges, $data['dischargedpcount']);
          array_push($newconsults, $data['newconsultscount']);
          array_push($signedoff, $data['signedoffcount']);
      }
      break;
  case 'monthly':

    $chart_title = "Monthly Overview for " . $ydate1;
    $lastMonth = date("Y-12-5", strtotime($s_date));

    $months = [];
    $monthNames = [];

    while ($n < 12) {
        $month = date("m", strtotime($lastMonth));
        $m_name = date("F", strtotime($lastMonth));
        $months[] = $month;
        $monthNames[] = $m_name;
        $n++;
        $time = strtotime($lastMonth);
        $lastMonth = date("Y-m-d", strtotime("-1 month", $time));
    }

    $monthList = implode("','", $months);

    // Combined SQL query to get all the data
    $formationSQL = "
        SELECT 
            month,
            SUM(admittedpcount) as admittedpcount,
            SUM(dischargedpcount) as dischargedpcount,
            SUM(newconsultscount) as newconsultscount,
            SUM(signedoffcount) as signedoffcount
        FROM (
            SELECT 
                MONTH(ADMDATE) as month, 
                COUNT(*) as admittedpcount,
                0 as dischargedpcount,
                0 as newconsultscount,
                0 as signedoffcount
            FROM picupatients 
            WHERE MONTH(ADMDATE) IN ('$monthList') 
            AND YEAR(ADMDATE) = '$ydate1' 
            AND consultant_id='$id' 
            AND (current_location != 'ICU' OR current_location IS NULL)
            GROUP BY month

            UNION ALL

            SELECT 
                MONTH(DISDATE) as month, 
                0 as admittedpcount,
                COUNT(*) as dischargedpcount,
                0 as newconsultscount,
                0 as signedoffcount
            FROM picupatients 
            WHERE MONTH(DISDATE) IN ('$monthList') 
            AND YEAR(DISDATE) = '$ydate1' 
            AND consultant_id='$id' 
            AND (current_location != 'ICU' OR current_location IS NULL)
            GROUP BY month

            UNION ALL

            SELECT 
                MONTH(consultation_date) as month, 
                0 as admittedpcount,
                0 as dischargedpcount,
                COUNT(*) as newconsultscount,
                0 as signedoffcount
            FROM consultations 
            WHERE MONTH(consultation_date) IN ('$monthList') 
            AND YEAR(consultation_date) = '$ydate1' 
            AND consultant_id='$id'
            GROUP BY month

            UNION ALL

            SELECT 
                MONTH(signoff_date) as month, 
                0 as admittedpcount,
                0 as dischargedpcount,
                0 as newconsultscount,
                COUNT(*) as signedoffcount
            FROM consultations 
            WHERE MONTH(signoff_date) IN ('$monthList') 
            AND YEAR(signoff_date) = '$ydate1' 
            AND consultant_id='$id'
            GROUP BY month
        ) as combined_data
        GROUP BY month";
    
    $result1 = $mysqli->query($formationSQL);
    $data = [
        'admissions' => [],
        'discharges' => [],
        'newconsults' => [],
        'signedoff' => []
    ];
    while ($row = $result1->fetch_assoc()) {
        $data['admissions'][$row['month']] = $row['admittedpcount'];
        $data['discharges'][$row['month']] = $row['dischargedpcount'];
        $data['newconsults'][$row['month']] = $row['newconsultscount'];
        $data['signedoff'][$row['month']] = $row['signedoffcount'];
    }

    foreach ($months as $index => $month) {
        array_push($label, $monthNames[$index]);
        array_push($admissions, $data['admissions'][$month] ?? 0);
        array_push($discharges, $data['discharges'][$month] ?? 0);
        array_push($newconsults, $data['newconsults'][$month] ?? 0);
        array_push($signedoff, $data['signedoff'][$month] ?? 0);
    }
    break;

case 'quarterly':

    $chart_title = "Quarterly Overview for " . $ydate1;
    $quarters = [4, 3, 2, 1];
    $quarterNames = ['Fourth Quarter', 'Third Quarter', 'Second Quarter', 'First Quarter'];

    // Combined query to get all the data
    $formationSQL = "
        SELECT 
            quarter,
            SUM(admittedpcount) as admittedpcount,
            SUM(dischargedpcount) as dischargedpcount,
            SUM(newconsultscount) as newconsultscount,
            SUM(signedoffcount) as signedoffcount
        FROM (
            SELECT 
                QUARTER(ADMDATE) as quarter, 
                COUNT(*) as admittedpcount,
                0 as dischargedpcount,
                0 as newconsultscount,
                0 as signedoffcount
            FROM picupatients 
            WHERE QUARTER(ADMDATE) IN (4, 3, 2, 1) 
            AND YEAR(ADMDATE) = '$ydate1' 
            AND consultant_id='$id' 
            AND (current_location != 'ICU' OR current_location IS NULL)
            GROUP BY quarter

            UNION ALL

            SELECT 
                QUARTER(DISDATE) as quarter, 
                0 as admittedpcount,
                COUNT(*) as dischargedpcount,
                0 as newconsultscount,
                0 as signedoffcount
            FROM picupatients 
            WHERE QUARTER(DISDATE) IN (4, 3, 2, 1) 
            AND YEAR(DISDATE) = '$ydate1' 
            AND consultant_id='$id' 
            AND (current_location != 'ICU' OR current_location IS NULL)
            GROUP BY quarter

            UNION ALL

            SELECT 
                QUARTER(consultation_date) as quarter, 
                0 as admittedpcount,
                0 as dischargedpcount,
                COUNT(*) as newconsultscount,
                0 as signedoffcount
            FROM consultations 
            WHERE QUARTER(consultation_date) IN (4, 3, 2, 1) 
            AND YEAR(consultation_date) = '$ydate1' 
            AND consultant_id='$id'
            GROUP BY quarter

            UNION ALL

            SELECT 
                QUARTER(signoff_date) as quarter, 
                0 as admittedpcount,
                0 as dischargedpcount,
                0 as newconsultscount,
                COUNT(*) as signedoffcount
            FROM consultations 
            WHERE QUARTER(signoff_date) IN (4, 3, 2, 1) 
            AND YEAR(signoff_date) = '$ydate1' 
            AND consultant_id='$id'
            GROUP BY quarter
        ) as combined_data
        GROUP BY quarter";
    
    $result1 = $mysqli->query($formationSQL);
    $data = [
        'admissions' => [],
        'discharges' => [],
        'newconsults' => [],
        'signedoff' => []
    ];
    while ($row = $result1->fetch_assoc()) {
        $data['admissions'][$row['quarter']] = $row['admittedpcount'];
        $data['discharges'][$row['quarter']] = $row['dischargedpcount'];
        $data['newconsults'][$row['quarter']] = $row['newconsultscount'];
        $data['signedoff'][$row['quarter']] = $row['signedoffcount'];
    }

    foreach ($quarters as $index => $quarter) {
        array_push($label, $quarterNames[$index]);
        array_push($admissions, $data['admissions'][$quarter] ?? 0);
        array_push($discharges, $data['discharges'][$quarter] ?? 0);
        array_push($newconsults, $data['newconsults'][$quarter] ?? 0);
        array_push($signedoff, $data['signedoff'][$quarter] ?? 0);
    }
    break;

}

// var_dump($chart);
// var_dump($consultants);

$label=array_reverse($label);
$admissions=array_reverse($admissions);
$discharges=array_reverse($discharges);
$newconsults=array_reverse($newconsults);
$signedoff=array_reverse($signedoff);

?>
  <script>
  (function() {
  let label = <?php echo json_encode($label); ?>;
  let admissions = <?php echo json_encode($admissions); ?>;
  let discharges = <?php echo json_encode($discharges); ?>;
  let newconsults = <?php echo json_encode($newconsults); ?>;
  let signedoff = <?php echo json_encode($signedoff); ?>;
  // var consultant = [];
  // var admissions = [];

  // for(var i=0; i<jArray.length; i++){
  //   consultant.push("Dr. " + jArray[i].full_name);
  //   admissions.push(jArray[i].admittions);
  //   // alert(jArray[i].full_name);
  //   }
    // alert(JSON.stringify(label));
  let mlabels = label;

  let mdata = {
    labels: mlabels,
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


  let mymonthlyChart = new Chart(
    document.getElementById('mymonthlyChart'),
    mconfig
  );
 })();
</script>

</div><!-- end colomn  -->




</div><!-- end row -->


<script>
        $(document).ready(function() {


            let element = $("#divpicture2"); // global variable
            let getCanvas; // global variable
            let newData;

            $("#btn-Preview-Image2").on('click', function() {
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
</div><!-- end colomn  -->
</div><!-- end row -->
  </div>
  <!-- /.card-body -->
</div>
<!-- /.card -->


<div class="row">
      <div id="mypresentersTable" class="col-md-12">

      <div class="card">
  <div  class="card-header">
    <h3 class="card-title"><i class="nav-icon fas fa-tachometer-alt text-info"></i> Numbers</h3><i style=" float: right; " download="download.png" id="btn-Preview-Image3" type="button" value="Download" class="fas fa-download"></i>
  </div>
  <!-- /.card-header -->
  <div class="card-body">
  <div class="row" style="background: white" id="divpicture3">


<div class="col-sm-6" style="height: fit-content;">
<?php
switch ($interval){
  case 'daily':
  case 'monthly':

$month = date("m",strtotime($s_date));
$mdate1_name=date("F",strtotime($s_date));
$ydate1=date("Y",strtotime($s_date));
$title="Top 5 Dignosis for " . $mdate1_name . " " . $ydate1; 

$formationSQL = "SELECT admissiondiagnosis FROM picupatients WHERE MONTH(ADMDATE) = '".$month."' AND YEAR(ADMDATE) = '".$ydate1."' AND consultant_id='".$id."' AND (current_location != 'ICU' or current_location is null)";
  $result1 = $mysqli->query($formationSQL);
  $ddx = $result1 -> fetch_all(MYSQLI_ASSOC);

$dx_count=array();

foreach ($ddx as $dx){
$dx_list=json_decode($dx['admissiondiagnosis']);

    foreach ($dx_list as $key => $value){

        if(isset($dx_count[$value])){
            $dx_count[$value]++;
        }else{
            $dx_count[$value]=1;
        }
        

    }


}

$dx_name_count = array();

// Collect all keys
$keys = array_keys($dx_count);

// Prepare the SQL statement to fetch all necessary records
$in  = str_repeat('?,', count($keys) - 1) . '?';
$sql = "SELECT id, name FROM icd10 WHERE id IN ($in)";
$stmt = $mysqli->prepare($sql);

// Bind the parameters
$types = str_repeat('s', count($keys));
$stmt->bind_param($types, ...$keys);
$stmt->execute();

// Fetch all results
$result1 = $stmt->get_result();
$dx_names = [];
while ($row = $result1->fetch_assoc()) {
    $dx_names[$row['id']] = $row['name'];
}

// Close the statement
$stmt->close();

// Map counts to diagnostic names
foreach ($dx_count as $key => $value) {
    if (isset($dx_names[$key])) {
        $dx_name_count[$dx_names[$key]] = $value;
    }
}

// Sort the array in descending order based on the count
arsort($dx_name_count);

// var_dump($dx_count);
// var_dump($dx_name_count);
break;
case 'quarterly':

  $month = date("m",strtotime($s_date));
  $mdate1_name=date("F",strtotime($s_date));
  $ydate1=date("Y",strtotime($s_date));
  
  if ($month >=1 && $month <=3){
    $quarter=1;
    $title="Top 5 Dignosis for first quarter"  .$ydate1;
      }elseif($month >=4 && $month <=6){
        $quarter=2;
        $title="Top 5 Dignosis for second quarter "  .$ydate1;
      }elseif($month >=7 && $month <=9){
        $quarter=3;
        $title="Top 5 Dignosis for third quarter "  .$ydate1;
      }elseif($month >=10 && $month <=12){
        $quarter=4;
        $title="Top 5 Dignosis for forth quarter " .$ydate1;
      }  

  $formationSQL = "SELECT admissiondiagnosis FROM picupatients WHERE QUARTER(ADMDATE) = '".$quarter."' AND YEAR(ADMDATE) = '".$ydate1."' AND consultant_id='".$id."' AND (current_location != 'ICU' or current_location is null)";
    $result1 = $mysqli->query($formationSQL);
    $ddx = $result1 -> fetch_all(MYSQLI_ASSOC);
  
  $dx_count=array();
  
  foreach ($ddx as $dx){
  $dx_list=json_decode($dx['admissiondiagnosis']);
  
      foreach ($dx_list as $key => $value){
  
          if(isset($dx_count[$value])){
              $dx_count[$value]++;
          }else{
              $dx_count[$value]=1;
          }
          
  
      }
  
  
  }
  
$dx_name_count = array();

// Collect all keys
$keys = array_keys($dx_count);

// Prepare the SQL statement to fetch all necessary records
$in  = str_repeat('?,', count($keys) - 1) . '?';
$sql = "SELECT id, name FROM icd10 WHERE id IN ($in)";
$stmt = $mysqli->prepare($sql);

// Bind the parameters
$types = str_repeat('s', count($keys));
$stmt->bind_param($types, ...$keys);
$stmt->execute();

// Fetch all results
$result1 = $stmt->get_result();
$dx_names = [];
while ($row = $result1->fetch_assoc()) {
    $dx_names[$row['id']] = $row['name'];
}

// Close the statement
$stmt->close();

// Map counts to diagnostic names
foreach ($dx_count as $key => $value) {
    if (isset($dx_names[$key])) {
        $dx_name_count[$dx_names[$key]] = $value;
    }
}

// Sort the array in descending order based on the count
arsort($dx_name_count);

  // var_dump($dx_count);
  // var_dump($dx_name_count);
break;
}
?>

<table class="table">
  <thead>
    <tr>
      <th scope="col">#</th>
      <th scope="col"><?php echo $title ?></th>
      <th scope="col">Volume</th>
    </tr>
  </thead>
  <tbody>
  <?php
      $n=0;
      foreach ($dx_name_count as $key => $value){
          $n++;
          if ($n == 6){
              break;
          }
          echo"
    <tr>
      <th scope='row'>".$n."</th>
      <td>".$key."</td>
      <td>".$value."</td>
    </tr>";
}

    ?>
  </tbody>
</table>

</div><!-- end colomn  -->

<div class="col-sm-6" style="height: fit-content;">
<?php

switch ($interval) {
    case 'daily':
    case 'monthly':

        $month = date("m", strtotime($s_date));
        $mdate1_name = date("F", strtotime($s_date));
        $ydate1 = date("Y", strtotime($s_date));
        $title = "Average Length of Stay for " . $mdate1_name . " " . $ydate1;

        // Fetch all necessary data in a single query
        $formationSQL = "
            SELECT ADMDATE, DISDATE, 
                   (MONTH(ADMDATE) = '$month') AS is_admission, 
                   (MONTH(DISDATE) = '$month') AS is_discharge, 
                   (DISTO = 'Intensive Care (ICU)') AS is_icu_transfer, 
                   consultant_id,
                   MRN, ID, trans_discharge
            FROM picupatients
            WHERE (MONTH(ADMDATE) = '$month' OR MONTH(DISDATE) = '$month') 
                  AND YEAR(ADMDATE) = '$ydate1' 
                  AND consultant_id = '$id' 
                  AND (current_location != 'ICU' OR current_location IS NULL)
        ";
        $result1 = $mysqli->query($formationSQL);
        $patients = $result1->fetch_all(MYSQLI_ASSOC);

        $los = [];
        $admission_count = 0;
        $discharge_count = 0;
        $transtoicu = 0;

        foreach ($patients as $patient) {
            if ($patient['is_admission']) $admission_count++;
            if ($patient['is_discharge']) {
                $discharge_count++;
                $timeDiff = abs(strtotime($patient['ADMDATE']) - strtotime($patient['DISDATE']));
                array_push($los, $timeDiff / 86400);
            }
            if ($patient['is_icu_transfer']) $transtoicu++;
        }

        $average = count($los) ? array_sum($los) / count($los) : 0;

        // Total Consultations
        $formationSQL = "
            SELECT COUNT(*) as total_consults 
            FROM consultations 
            WHERE MONTH(consultation_date) = '$month' 
                  AND YEAR(consultation_date) = '$ydate1' 
                  AND consultant_id = '$id'
        ";
        $result1 = $mysqli->query($formationSQL);
        $totalconsults = $result1->fetch_assoc()['total_consults'];

        // Total Sign Offs
        $formationSQL = "
            SELECT COUNT(*) as total_signoffs 
            FROM consultations 
            WHERE MONTH(signoff_date) = '$month' 
                  AND YEAR(signoff_date) = '$ydate1' 
                  AND consultant_id = '$id'
        ";
        $result1 = $mysqli->query($formationSQL);
        $totalsignoffs = $result1->fetch_assoc()['total_signoffs'];

        // 72 hours Re-admissions
        $readmission_count = 0;
        foreach ($patients as $s) {
            $formationSQL = "
                SELECT * 
                FROM picupatients 
                WHERE DISDATE + INTERVAL 3 DAY >= '{$s['ADMDATE']}' 
                      AND ID < '{$s['ID']}' 
                      AND MRN = '{$s['MRN']}' 
                      AND (trans_discharge = 'discharge from ICU' 
                      OR trans_discharge = 'discharge from ward' 
                      OR trans_discharge IS NULL) 
                LIMIT 1
            ";
            $result1 = $mysqli->query($formationSQL);
            $recentadmission = $result1->fetch_assoc();
            if ($id == $recentadmission['consultant_id']) $readmission_count++;
        }

        $for = " for " . $mdate1_name . " " . $ydate1;
        break;

    case 'quarterly':

        $month = date("m", strtotime($s_date));
        $mdate1_name = date("F", strtotime($s_date));
        $ydate1 = date("Y", strtotime($s_date));

        if ($month >= 1 && $month <= 3) {
            $quarter = 1;
            $title = "Average Length of Stay for first quarter" . $ydate1;
            $for = " for first quarter" . $ydate1;
        } elseif ($month >= 4 && $month <= 6) {
            $quarter = 2;
            $title = "Average Length of Stay for second quarter " . $ydate1;
            $for = " for second quarter" . $ydate1;
        } elseif ($month >= 7 && $month <= 9) {
            $quarter = 3;
            $title = "Average Length of Stay for third quarter " . $ydate1;
            $for = " for third quarter" . $ydate1;
        } elseif ($month >= 10 && $month <= 12) {
            $quarter = 4;
            $title = "Average Length of Stay for forth quarter " . $ydate1;
            $for = " for forth quarter" . $ydate1;
        }

        // Fetch all necessary data in a single query
        $formationSQL = "
            SELECT ADMDATE, DISDATE, 
                   (QUARTER(ADMDATE) = '$quarter') AS is_admission, 
                   (QUARTER(DISDATE) = '$quarter') AS is_discharge, 
                   (DISTO = 'Intensive Care (ICU)') AS is_icu_transfer, 
                   consultant_id,
                   MRN, ID, trans_discharge
            FROM picupatients
            WHERE (QUARTER(ADMDATE) = '$quarter' OR QUARTER(DISDATE) = '$quarter') 
                  AND YEAR(ADMDATE) = '$ydate1' 
                  AND consultant_id = '$id' 
                  AND (current_location != 'ICU' OR current_location IS NULL)
        ";
        $result1 = $mysqli->query($formationSQL);
        $patients = $result1->fetch_all(MYSQLI_ASSOC);

        $los = [];
        $admission_count = 0;
        $discharge_count = 0;
        $transtoicu = 0;

        foreach ($patients as $patient) {
            if ($patient['is_admission']) $admission_count++;
            if ($patient['is_discharge']) {
                $discharge_count++;
                $timeDiff = abs(strtotime($patient['ADMDATE']) - strtotime($patient['DISDATE']));
                array_push($los, $timeDiff / 86400);
            }
            if ($patient['is_icu_transfer']) $transtoicu++;
        }

        $average = count($los) ? array_sum($los) / count($los) : 0;

        // Total Consultations
        $formationSQL = "
            SELECT COUNT(*) as total_consults 
            FROM consultations 
            WHERE QUARTER(consultation_date) = '$quarter' 
                  AND YEAR(consultation_date) = '$ydate1' 
                  AND consultant_id = '$id'
        ";
        $result1 = $mysqli->query($formationSQL);
        $totalconsults = $result1->fetch_assoc()['total_consults'];

        // Total Sign Offs
        $formationSQL = "
            SELECT COUNT(*) as total_signoffs 
            FROM consultations 
            WHERE QUARTER(signoff_date) = '$quarter' 
                  AND YEAR(signoff_date) = '$ydate1' 
                  AND consultant_id = '$id'
        ";
        $result1 = $mysqli->query($formationSQL);
        $totalsignoffs = $result1->fetch_assoc()['total_signoffs'];

        // 72 hours Re-admissions
        $readmission_count = 0;
        foreach ($patients as $s) {
            $formationSQL = "
                SELECT * 
                FROM picupatients 
                WHERE DISDATE + INTERVAL 3 DAY >= '{$s['ADMDATE']}' 
                      AND ID < '{$s['ID']}' 
                      AND MRN = '{$s['MRN']}' 
                      AND (trans_discharge = 'discharge from ICU' 
                      OR trans_discharge = 'discharge from ward' 
                      OR trans_discharge IS NULL) 
                LIMIT 1
            ";
            $result1 = $mysqli->query($formationSQL);
            $recentadmission = $result1->fetch_assoc();
            if ($id == $recentadmission['consultant_id']) $readmission_count++;
        }
        break;
}

?>

<table class="table">
    <thead>
        <tr style="text-align: center;">
            <th scope="col" colspan="4">Numbers<?php echo $for; ?></th>
        </tr>
        <tr>
            <th scope="col" colspan="2"><?php echo $title; ?></th>
            <th scope="col" colspan="2"><?php echo number_format((float)$average, 2, '.', ''); ?> Days</th>
        </tr>
        <tr>
            <th scope="col">Total Admissions</th>
            <th scope="col"><?php echo $admission_count; ?> Patients</th>
            <th scope="col">Total Discharges</th>
            <th scope="col"><?php echo $discharge_count; ?> Patients</th>
        </tr>
        <tr>
            <th scope="col">Transfer To ICU</th>
            <th scope="col"><?php echo $transtoicu; ?> Patients</th>
            <th scope="col">72 hours Re-admissions</th>
            <th scope="col"><?php echo $readmission_count; ?> Patients</th>
        </tr>
        <tr>
            <th scope="col">Total Consultations</th>
            <th scope="col"><?php echo $totalconsults; ?> Patients</th>
            <th scope="col">Total Sign Offs</th>
            <th scope="col"><?php echo $totalsignoffs; ?> Patients</th>
        </tr>
    </thead>
    <tbody>
        <?php
        // $n=0;
        // foreach ($dx_name_count as $key => $value){
        //     $n++;
        //     echo"
        // <tr>
        //   <th scope='row'>".$n."</th>
        //   <td>".$key."</td>
        // </tr>";
        // }
        ?>
    </tbody>
</table>

</div><!-- end colomn  -->

</div><!-- end row -->



<script>
        $(document).ready(function() {


            let element = $("#divpicture3"); // global variable
            let getCanvas; // global variable
            let newData;

            $("#btn-Preview-Image3").on('click', function() {
                html2canvas(element, {
                    onrendered: function(canvas) {
                        getCanvas = canvas;
                        let imgageData = getCanvas.toDataURL("image/png");
                        let a = document.createElement("a");
                        a.href = imgageData; //Image Base64 Goes here
                        a.download = "Numbers.png"; //File name Here
                        a.click(); //Downloaded file
                    }
                });
            });


        });
    </script>
</div><!-- end colomn  -->
</div><!-- end row -->
  </div>
  <!-- /.card-body -->
</div>
<!-- /.card -->

