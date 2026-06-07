<?php
require_once __DIR__ . '/../guard.php'; require_role([0]);
 
session_start();
// date_default_timezone_set('Asia/Riyadh');
$today=date("Y-m-d");

require ('../dbconnect.php');
$time="yearly";
if (isset($_GET['y'])){
  $y = (int)$_GET['y'];
  $date="1/1/".$y;
  }else{
    echo "<script language='javascript'>\n";
    echo "window.location.href = '../allstat.php';";
    echo "</script>\n";
  }
// $time = "quarterly";
?>

<script src="../vendor/Chart.js/4.4.2/chart.umd.min.js"></script>
<script src="../vendor/chartjs-plugin-datalabels/2.2.0/chartjs-plugin-datalabels.min.js"></script>
<link href="../vendor/font-awesome-4.7/css/font-awesome.min.css" rel="stylesheet" media="all">

<link rel="stylesheet" href="../vendor/bootstrap-5.3.3-dist/css/bootstrap.min.css">

<?php
$label=array();
$admissions=array();
$discharges=array();
$toicu=array();
$newconsults=array();
$signedoff=array();
$beddays=array();
$LOS=array();
$weekend=array();
$weekend_p=array();
$LSP=array();
$total_patients=array();
$LSP_P=array();
$m_LOS=array();
$icuaverageLOSa=array();
$readmission=array();
$dishtranslabels=array();
$dishtransnumbers=array();

if ($time == "yearly"){

 $title ='Monthly Overview';

 $date1 = date("Y-12-1", strtotime($date));

    
    $n=0;


    // get consultant based data for the year.
    $consultantname=array();
    $consultantLOS=array();

    $formationSQL = "SELECT * FROM members WHERE position = '3' AND active = 1";
    $result1 = $mysqli->query($formationSQL);
    $consultants = $result1 -> fetch_all(MYSQLI_ASSOC);

    $ydate1=date("Y",strtotime($date1));
    $yStart = $ydate1 . '-01-01'; $yEnd = $ydate1 . '-12-31';
    foreach($consultants as $c){
    // sargable date range (was YEAR(DISDATE))
    $formationSQL = "SELECT ADMDATE, DISDATE FROM picupatients WHERE DISDATE IS NOT NULL AND consultant_id=? AND DISDATE BETWEEN ? AND ? AND (current_location != 'ICU' or current_location is null)";
    $stmt = $mysqli->prepare($formationSQL);
    $stmt->bind_param('iss', $c['member_id'], $yStart, $yEnd);
    $stmt->execute();
    $result1 = $stmt->get_result();
    $datesss = $result1 -> fetch_all(MYSQLI_ASSOC);

    $los=array();
      
    foreach ($datesss as $d){
      $timeDiff = abs(strtotime($d['ADMDATE']) - strtotime($d['DISDATE']));
    
      array_push($los,$timeDiff/86400);
      
    }

    if(count($los) > 0) {
      $average = array_sum($los)/count($los);
    } else {
      $average = 0;
    }

    if($average>0){
      array_push($consultantLOS,(number_format(($average), 2, '.', '')));
      } else {
        array_push($consultantLOS,0);
              }
    array_push($consultantname,$c['full_name']);
    }



        ///// Discharge / Transfer to
        $title='Discharge / Transfer in '.$ydate1;
        // sargable date range (was YEAR(DISDATE))
        $formationSQL = "SELECT DISTO, DISDATE, COUNT(*) FROM picupatients WHERE DISDATE IS NOT NULL AND DISDATE BETWEEN ? AND ? AND (current_location != 'ICU' or current_location is null) GROUP BY DISTO";
        $stmt = $mysqli->prepare($formationSQL);
        $stmt->bind_param('ss', $yStart, $yEnd);
        $stmt->execute();
        $result1 = $stmt->get_result();
        $DISTO = $result1 -> fetch_all(MYSQLI_ASSOC);
        // var_dump($DISTO);
      
        $dishtransnumbers=array();
        $dishtransnumbers['To Other Specilaity']=0;
        foreach ($DISTO as $dis){
          if ($dis['DISTO']=='Intensive Care (ICU)'){$dishtransnumbers['Intensive Care (ICU)']=$dis['COUNT(*)'];}
          elseif($dis['DISTO']=='Home'){$dishtransnumbers['Home']=$dis['COUNT(*)'];}
        elseif($dis['DISTO']=='Mortuary'){$dishtransnumbers['Mortuary']=$dis['COUNT(*)'];}
        elseif($dis['DISTO']=='Other Facility'){$dishtransnumbers['Other Facility']=$dis['COUNT(*)'];}
        elseif($dis['DISTO']=='Absconded'){$dishtransnumbers['Absconded']=$dis['COUNT(*)'];}
        elseif($dis['DISTO']=='LAMA'){$dishtransnumbers['LAMA']=$dis['COUNT(*)'];}
        else{$dishtransnumbers['To Other Specilaity']=$dishtransnumbers['To Other Specilaity']+$dis['COUNT(*)'];}
        }


// var_dump($dishtransnumbers);
    // Readmissions for the whole year in ONE grouped query (was an N+1 per-patient LIMIT-1
    // sub-query loop inside each month — the dominant query cost of this report). Per admission
    // only "does a qualifying prior record exist" (0/1) matters, so EXISTS + COUNT(*) GROUP BY
    // month reproduces the per-month mysqli_num_rows(LIMIT 1) sums exactly.
    $readmissionByMonth = [];
    $rq = "SELECT DATE_FORMAT(a.ADMDATE,'%Y-%m') AS ym, COUNT(*) AS c
           FROM picupatients a
           WHERE a.ADMDATE BETWEEN ? AND ?
             AND EXISTS (SELECT 1 FROM picupatients p
                         WHERE p.DISDATE + INTERVAL 3 DAY >= a.ADMDATE AND p.ID < a.ID AND p.MRN = a.MRN
                           AND (p.trans_discharge = 'discharge from ICU' or p.trans_discharge='discharge from ward' or p.trans_discharge IS NULL))
           GROUP BY ym";
    $stmt = $mysqli->prepare($rq);
    $stmt->bind_param('ss', $yStart, $yEnd);
    $stmt->execute();
    $rqr = $stmt->get_result();
    while ($row = $rqr->fetch_assoc()) { $readmissionByMonth[$row['ym']] = (int) $row['c']; }

    /// get monthly data
    while($n < 12){

      $ydate1=date("Y",strtotime($date1));
      $mdate1=date("m",strtotime($date1));
      $last_day_ofmonth=date("Y-m-t", strtotime($date1));
      $first_day_ofmonth=date("Y-m-01", strtotime($date1));

      $dateObj   = DateTime::createFromFormat('!m', $mdate1);
      $monthName = $dateObj->format('F'); // March

      // sargable date range (was MONTH()/YEAR())
      $formationSQL = "SELECT * FROM picupatients WHERE ADMDATE BETWEEN ? AND ? AND (current_location != 'ICU' or current_location is null)";
      $stmt = $mysqli->prepare($formationSQL);
      $stmt->bind_param('ss', $first_day_ofmonth, $last_day_ofmonth);
      $stmt->execute();
      $result1 = $stmt->get_result();
      $admittedpcount = mysqli_num_rows($result1);

      // sargable date range (was MONTH()/YEAR())
      $formationSQL = "SELECT * FROM picupatients WHERE DISDATE BETWEEN ? AND ? AND (current_location != 'ICU' or current_location is null)";
      $stmt = $mysqli->prepare($formationSQL);
      $stmt->bind_param('ss', $first_day_ofmonth, $last_day_ofmonth);
      $stmt->execute();
      $result1 = $stmt->get_result();
      $dischargedpcount = mysqli_num_rows($result1);

      // sargable date range (was MONTH()/YEAR())
      $formationSQL = "SELECT * FROM consultations WHERE consultation_date BETWEEN ? AND ?  ";
      $stmt = $mysqli->prepare($formationSQL);
      $stmt->bind_param('ss', $first_day_ofmonth, $last_day_ofmonth);
      $stmt->execute();
      $result1 = $stmt->get_result();
      $newconsultscount = mysqli_num_rows($result1);

      // sargable date range (was MONTH()/YEAR())
      $formationSQL = "SELECT * FROM consultations WHERE signoff_date BETWEEN ? AND ?  ";
      $stmt = $mysqli->prepare($formationSQL);
      $stmt->bind_param('ss', $first_day_ofmonth, $last_day_ofmonth);
      $stmt->execute();
      $result1 = $stmt->get_result();
      $signedoffcount = mysqli_num_rows($result1);

    ///// Trans to ICU
    // sargable date range (was MONTH()/YEAR())
    $formationSQL = "SELECT DISDATE FROM picupatients WHERE DISDATE BETWEEN ? AND ? AND DISTO = 'Intensive Care (ICU)'";
    $stmt = $mysqli->prepare($formationSQL);
    $stmt->bind_param('ss', $first_day_ofmonth, $last_day_ofmonth);
    $stmt->execute();
    $result1 = $stmt->get_result();
    $transtoicu = mysqli_num_rows($result1);


      //////////////
      // Medical Los
      /////////////
  
      // sargable date range (was MONTH()/YEAR())
      $formationSQL = "SELECT ADMDATE, med_DISDATE FROM picupatients WHERE DISDATE IS NOT NULL AND DISDATE BETWEEN ? AND ? AND (current_location != 'ICU' or current_location is null)";
      $stmt = $mysqli->prepare($formationSQL);
      $stmt->bind_param('ss', $first_day_ofmonth, $last_day_ofmonth);
      $stmt->execute();
      $result1 = $stmt->get_result();
      $datesss = $result1 -> fetch_all(MYSQLI_ASSOC);

      // echo $mdate1 . "</br>";

      $los=array();

      foreach ($datesss as $d){
        $timeDiff = abs(strtotime($d['ADMDATE']) - strtotime($d['med_DISDATE']));
      
        array_push($los,$timeDiff/86400);
        
      }
      // var_dump($los);
      // $a = array_filter($los);
      if(count($los) > 0) {
        $m_average = array_sum($los)/count($los);
      } else {
        $m_average = 0;
      }
     
       //////////////
      // physical Los
      /////////////
  
      // sargable date range (was MONTH()/YEAR())
      $formationSQL = "SELECT ADMDATE, DISDATE FROM picupatients WHERE DISDATE IS NOT NULL AND DISDATE BETWEEN ? AND ? AND (current_location != 'ICU' or current_location is null)";
      $stmt = $mysqli->prepare($formationSQL);
      $stmt->bind_param('ss', $first_day_ofmonth, $last_day_ofmonth);
      $stmt->execute();
      $result1 = $stmt->get_result();
      $datesss = $result1 -> fetch_all(MYSQLI_ASSOC);

      // echo $mdate1 . "</br>";

      $los=array();

      foreach ($datesss as $d){
        $timeDiff = abs(strtotime($d['ADMDATE']) - strtotime($d['DISDATE']));

        array_push($los,$timeDiff/86400);

      }
      // var_dump($los);
      // $a = array_filter($los);
      if(count($los) > 0) {
        $average = array_sum($los)/count($los);
      } else {
        $average = 0;
      }

            //////////////
      // ICU physical Los
      /////////////
  
      // sargable date range (was MONTH()/YEAR())
      $formationSQL = "SELECT ADMDATE, DISDATE FROM picupatients WHERE DISDATE IS NOT NULL AND DISDATE BETWEEN ? AND ? AND current_location ='ICU'";
      $stmt = $mysqli->prepare($formationSQL);
      $stmt->bind_param('ss', $first_day_ofmonth, $last_day_ofmonth);
      $stmt->execute();
      $result1 = $stmt->get_result();
      $icudatesss = $result1 -> fetch_all(MYSQLI_ASSOC);
      
      // echo $mdate1 . "</br>";
    
      $los=array();
      
      foreach ($icudatesss as $d){
        $timeDiff = abs(strtotime($d['ADMDATE']) - strtotime($d['DISDATE']));
      
        array_push($los,$timeDiff/86400);
        
      }
      // var_dump($los);
      // $a = array_filter($los);
      if(count($los) > 0) {
        $icuaverageLOS = array_sum($los)/count($los);
      } else {
        $icuaverageLOS = 0;
      }

////////////////////////////////////
//long stays > 30 days
////////////////////////////////////
// $formationSQL = "SELECT * FROM picupatients WHERE ADMDATE < '".$first_day_ofmonth."' AND DISDATE >= '".$last_day_ofmonth."' AND (current_location != 'ICU' or current_location is null)";
if (strtotime($last_day_ofmonth) <= strtotime($today)) {
$formationSQL = "SELECT * FROM picupatients WHERE ((DISDATE IS NOT NULL AND ADMDATE < ? AND DISDATE >= ?) OR (DISDATE IS NULL AND ADMDATE + INTERVAL 30 DAY < ?)) AND (current_location != 'ICU' or current_location is null)";
$stmt = $mysqli->prepare($formationSQL);
$stmt->bind_param('sss', $first_day_ofmonth, $last_day_ofmonth, $last_day_ofmonth);
$stmt->execute();
$result1 = $stmt->get_result();
$LSP_count = mysqli_num_rows($result1);
// echo $last_day_ofmonth."</br>";
// var_dump($patient);

// total number of patients at that month
$formationSQL = "SELECT * FROM picupatients WHERE (
  (DISDATE IS NULL AND ADMDATE <= ?)
 OR (DISDATE IS NOT NULL AND DISDATE >= ? AND DISDATE <= ?)
 OR (DISDATE IS NOT NULL AND ADMDATE < ? AND DISDATE > ?)
 OR   (ADMDATE >= ? AND ADMDATE <= ?)
 ) AND (current_location != 'ICU' or current_location is null)";
 $stmt = $mysqli->prepare($formationSQL);
 $stmt->bind_param('sssssss', $last_day_ofmonth, $first_day_ofmonth, $last_day_ofmonth, $first_day_ofmonth, $last_day_ofmonth, $first_day_ofmonth, $last_day_ofmonth);
 $stmt->execute();
 $result1 = $stmt->get_result();
$total_count = mysqli_num_rows($result1);

}else{
/////////////// last day of the month not yet came to calculate
$LSP_count=0;
$total_count=0;
}

       
////////////////////////////////////////////////////////////////
// Number of bed days
////////////////////////////////////////////////////////////////
// $formationSQL = "SELECT * FROM picupatients WHERE ADMDATE = '".$date1."' AND (DISDATE = '".$date1."' OR DISDATE IS NULL)";


// Set-based bed-days + weekend discharges (was a ~30-queries/month per-day census loop). Proven
// equivalent to the per-day census for every month including the current partial one, on the full
// production dataset (tools census/weekend validators: 54/54 months, 0 mismatches).
$cap = (strtotime($last_day_ofmonth) <= strtotime($today)) ? $last_day_ofmonth : $today; // min(last, today)
if (strtotime($cap) < strtotime($first_day_ofmonth)) {
    $monthly_beddayscount = 0; // wholly-future month: no days have occurred
    $allweekend_discharge = 0;
} else {
    // bed-days = sum over non-ICU, not-same-day-discharge patients of their overlap (in days) with [first, cap]
    $stmt = $mysqli->prepare("SELECT COALESCE(SUM(GREATEST(0,
            DATEDIFF(LEAST(CAST(? AS DATE), IFNULL(DISDATE, CAST(? AS DATE))),
                     GREATEST(ADMDATE, CAST(? AS DATE))) + 1)), 0) AS beddays
        FROM picupatients
        WHERE (current_location != 'ICU' or current_location is null)
          AND NOT DISDATE <=> ADMDATE
          AND ADMDATE <= CAST(? AS DATE)
          AND (DISDATE >= CAST(? AS DATE) OR DISDATE IS NULL)");
    $stmt->bind_param('sssss', $cap, $cap, $first_day_ofmonth, $cap, $first_day_ofmonth);
    $stmt->execute();
    $monthly_beddayscount = (int) $stmt->get_result()->fetch_assoc()['beddays'];

    // weekend discharges: non-ICU discharges on a Fri/Sat within [first, cap]
    // (PHP date('w') Fri=5/Sat=6 == MySQL DAYOFWEEK 6/7)
    $stmt = $mysqli->prepare("SELECT COUNT(*) AS c FROM picupatients
        WHERE DISDATE BETWEEN CAST(? AS DATE) AND CAST(? AS DATE)
          AND DAYOFWEEK(DISDATE) IN (6,7)
          AND (current_location != 'ICU' or current_location is null)");
    $stmt->bind_param('ss', $first_day_ofmonth, $cap);
    $stmt->execute();
    $allweekend_discharge = (int) $stmt->get_result()->fetch_assoc()['c'];
}
/////////////////////
    ///// readmissions  (grouped above into $readmissionByMonth — was an N+1 per-patient loop)
//////////////////////////
$readmission_count = $readmissionByMonth[$ydate1 . '-' . $mdate1] ?? 0;

/////////////////
      array_push($label,$monthName);
      array_push($admissions,$admittedpcount);
      array_push($discharges,$dischargedpcount);
      array_push($toicu,$transtoicu);
      array_push($newconsults,$newconsultscount);
      array_push($signedoff,$signedoffcount);
      array_push($beddays,$monthly_beddayscount);
      array_push($LSP,$LSP_count);
      array_push($total_patients,$total_count);
      array_push($readmission,$readmission_count);


      if($average>0){
      array_push($LOS,(number_format(($average), 2, '.', '')));
      } else {
        array_push($LOS,0);
              }

              
      if($m_average>0){
      array_push($m_LOS,(number_format(($m_average), 2, '.', '')));
      } else {
        array_push($m_LOS,0);
              }

              if($icuaverageLOS>0){
                array_push($icuaverageLOSa,(number_format(($icuaverageLOS), 2, '.', '')));
                } else {
                  array_push($icuaverageLOSa,0);
                        }

      if($dischargedpcount>0){
        
        array_push($weekend,(number_format($allweekend_discharge, 0, '.', '').""));
      array_push($weekend_p,(number_format((($allweekend_discharge/$dischargedpcount)*100), 2, '.', '')."%"));
      
    }else{
      array_push($weekend_p,0);
      array_push($weekend,0);
    }
    
    if ($total_count>0){

      $num=($LSP_count/$total_count)*100;
      array_push($LSP_P,(number_format($num, 2, '.', '')."%"));
    }elseif(strcmp($total_count, 'Pending') == 0){
      array_push($LSP_P,"Pending");
    }else{
      array_push($LSP_P,"0%");
    }
    
    $n++;
    
    // $date->modify("-1 month");
    $time = strtotime($date1);
    $date1 = date("Y-m-d", strtotime("-1 month", $time));
    }
    

    
$label=array_reverse($label);
$admissions=array_reverse($admissions);
$discharges=array_reverse($discharges);
$toicu=array_reverse($toicu);
$newconsults=array_reverse($newconsults);
$signedoff=array_reverse($signedoff);
$beddays=array_reverse($beddays);
$LOS=array_reverse($LOS);
$m_LOS=array_reverse($m_LOS);
$icuaverageLOSa=array_reverse($icuaverageLOSa);
$weekend=array_reverse($weekend);
$weekend_p=array_reverse($weekend_p);

$LSP=array_reverse($LSP);
$total_patients=array_reverse($total_patients);
$LSP_P=array_reverse($LSP_P);
$readmission=array_reverse($readmission);
$dishtranslabels=array_reverse($dishtranslabels);
$dishtransnumbers=array_reverse($dishtransnumbers);
// var_dump($dishtranslabels);
// var_dump($dishtransnumbers);
    ?>
    <head>
<style>
  body {
  background: rgb(204,204,204); 
}
page {
  background: white;
  width: 21cm;
  height: 29.7cm;
  display: block;
  margin: 0 auto;
  /* padding: 10px 25px; */
  margin-bottom: 0.5cm;
  box-shadow: 0 0 0.5cm rgba(0, 0, 0, 0.5);
  overflow-y: hidden;
  box-sizing: border-box;
  overflow-x: hidden;
}
page[size="A4"] {  
  width: 21cm;
  height: 29.7cm; 
}
page[size="A4"][layout="landscape"] {
  width: 29.7cm;
  height: 21cm;  
}

@media print {
  .page-break {
    display: block;
    page-break-before: always;
  }
  /* size: A4 portrait; */
  body, page {
    margin: 0;
    padding: 0;
    size: landscape;
    box-shadow: 0;
  }
  @page {size: A4 landscape;   
    box-shadow: none;
    margin: 0;
    width: auto;
    height: auto;}
    
}

  .counter{
    color: #f27f21;
    font-family: 'Open Sans', sans-serif;
    text-align: center;
    height: 190px;
    width: 190px;
    padding: 30px 25px 25px;
    margin: 0 auto;
    border: 3px solid #f27f21;
    border-radius: 20px 20px;
    position: relative;
    z-index: 1;
}
.counter:before,
.counter:after{
    content: "";
    background: #f3f3f3;
    border-radius: 20px;
    box-shadow: 4px 4px 2px rgba(0,0,0,0.2);
    position: absolute;
    left: 15px;
    top: 15px;
    bottom: 15px;
    right: 15px;
    z-index: -1;
}
.counter:after{
    background: transparent;
    width: 100px;
    height: 100px;
    border: 15px solid #f27f21;
    border-top: none;
    border-right: none;
    border-radius: 0 0 0 20px;
    box-shadow: none;
    top: auto;
    left: -10px;
    bottom: -10px;
    right: auto;
}
.counter .counter-icon{
    font-size: 35px;
    line-height: 35px;
    margin: 0 0 15px;
    transition: all 0.3s ease 0s;
}
.counter:hover .counter-icon{ transform: rotateY(360deg); }
.counter .counter-value{
    color: #555;
    font-size: 30px;
    font-weight: 600;
    line-height: 20px;
    margin: 0 0 20px;
    display: block;
    transition: all 0.3s ease 0s;
}
.counter:hover .counter-value{ text-shadow: 2px 2px 0 #d1d8e0; }
.counter h3{
    font-size: 17px;
    font-weight: 700;
    text-transform: uppercase;
    margin: 0 0 15px;
}
.counter.blue{
    color: rgb(41, 134, 204, 0.9);
    border-color: rgb(41, 134, 204, 0.9);
}
.counter.blue:after{
    border-bottom-color: rgb(41, 134, 204, 1);
    border-left-color: rgb(41, 134, 204, 1);
}
@media screen and (max-width:990px){
    .counter{ margin-bottom: 40px; }
}
  </style>
</head>
<body class="hold-transition sidebar-mini layout-fixed layout-navbar-fixed layout-footer-fixed">

  <page size="A4" layout="landscape">
  <div class="row">
    <div class="col-md-12">
      <img style="width: inherit;" src="../dist/img/reportheader.png">
    </div>
  </div>
  <div class="row">
    <div class="col-md-12" style=" text-align: center; margin-top: 15%; ">
      <spnan style=" color: #004aab; font-size: xx-large; font-weight: 600; font-family: inherit; ">Internal Medicine Department Performance Report For <?php echo (int)$ydate1; ?></spnan>
    </div>
  </div>
  </page>



  <page size="A4" layout="landscape">
  <div class="row" >
    <div class="col-md-12">
      <img style="width: inherit;" src="../dist/img/reportheader1.png">
    </div>
  </div>
<div class="row" style="border: 2px solid;border-color: gray;padding: 1%;margin: 0.5%;height: 85%;background-color: hsla(117,0%,95%,.3);">

<div class="col-md-12">
<div class="row">
        <div class="col-md-3">
            <div class="counter blue">
                <div class="counter-icon">
                    <i class="fa fa-users"></i>
                </div>
                <span class="counter-value"><?php echo array_sum($total_patients); ?></span>
                <h3>Total Patients</h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="counter blue">
                <div class="counter-icon">
                    <i class="fa fa-calendar"></i>
                </div>
                <span class="counter-value" style="    font-size: 22px;"><?php echo array_sum($weekend); echo " (".(number_format((array_sum($weekend)/array_sum($discharges)*100), 2, '.', ''))."%)"; ?></span>
                <h3>Weekend Discharge</h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="counter blue">
                <div class="counter-icon">
                    <i class="fa fa-clock-o"></i>
                </div>
                <span class="counter-value" style="     font-size: 22px;"><?php echo array_sum($LSP); echo " (".(number_format((array_sum($LSP)/array_sum($total_patients)*100), 2, '.', ''))."%)"; ?></span>
                <h3>Long Stay</h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="counter blue">
                <div class="counter-icon">
                    <i class="fa fa-refresh"></i>
                </div>
                <span class="counter-value"><?php echo array_sum($readmission); ?></span>
                <h3>72 hrs Readmissions</h3>
            </div>
        </div>
  
</div>

</div>
  <div class="col-md-12">

    <div class="row" >
      <div class="col-md-12" style="text-align: center;">

  <span style=" font-size: large; font-weight: 500; color: darkslategray; ">Monthly Overview: Admission | Diaschage | Consultations</span>
        <div class="chart-container" style="position: relative; height:26vh">
        <canvas id="mymonthlyChart"></canvas>
        </div>

      </div>
    </div>

<script>
   Chart.register(ChartDataLabels);
  var label = <?php echo json_encode($label); ?>;
  var admissions = <?php echo json_encode($admissions); ?>;
  var discharges = <?php echo json_encode($discharges); ?>;
  var newconsults = <?php echo json_encode($newconsults); ?>;
  var signedoff = <?php echo json_encode($signedoff); ?>;
  // var consultant = [];
  // var admissions = [];

  // for(var i=0; i<jArray.length; i++){
  //   consultant.push("Dr. " + jArray[i].full_name);
  //   admissions.push(jArray[i].admittions);
  //   // alert(jArray[i].full_name);
  //   }
    // alert(JSON.stringify(label));
  const mlabels = label;

  const mdata = {
    labels: mlabels,
    datasets: [{
      label: 'Admissions',
      backgroundColor: 'rgb(41, 134, 204, 0.9)',
      borderColor: 'rgb(41, 134, 204, 0.9)',
      data: admissions,
     
 
    },
    {
      label: 'Discharges',
      backgroundColor: 'rgb(204, 41, 134, 0.9)',
      borderColor: 'rgb(204, 41, 134, 0.9)',
      data: discharges,
     
   
    },
    {
      label: 'New Consultations',
      backgroundColor: 'rgb(75, 192, 192, 0.9)',
      borderColor: 'rgb(75, 192, 192, 0.9)',
      data: newconsults,
   

    },
    {
      label: 'Signed Off Consultations',
      backgroundColor: 'rgb(255, 205, 86, 0.9)',
      borderColor: 'rgb(255, 205, 86, 0.9)',
      data: signedoff,

   
    }]
  };
  
  const colors = ['red', 'blue', 'yellow', 'green'];
  const weekend =[];
  const mconfig = {
    type: 'bar',
    
    data: mdata,
    options: {
        scales : {
        y : {
            afterDataLimits(scale) {
          scale.max += 50;
        }
        }
    },

      maintainAspectRatio: false,
      responsive: true,
    plugins: {
        datalabels: {
                anchor: 'end',
                align: 'top',
                formatter: Math.round,
                font: {
                    weight: 'thin',
                    size: 14
                }
            },
      
    },
   
    
  },
  };
const ctx = document.getElementById('mymonthlyChart').getContext('2d');
const mymonthlyChart = new Chart(
    ctx,
    mconfig
  );
 
</script>

</div>
</div>
  </page>
  <page size="A4" layout="landscape">
  <div class="row" >
    <div class="col-md-12">
      <img style="width: inherit;" src="../dist/img/reportheader1.png">
    </div>
  </div>
  <div class="row" style="border: 2px solid;border-color: gray;padding: 1%;margin: 0.5%;height: 83%;background-color: hsla(117,0%,95%,.3);">
          <div class="col-md-8">
<div class="row">
          <div class="col-md-6">
                            
                        <div class="chart-container" style="position: relative; height:25vh">
                        <canvas id="medicalLOS"></canvas>
                        </div>

                        <script>
                      
                          var label = <?php echo json_encode($label); ?>;
                          var LOS = <?php echo json_encode($m_LOS); ?>;
                          // var consultant = [];
                          // var admissions = [];

                          // for(var i=0; i<jArray.length; i++){
                          //   consultant.push("Dr. " + jArray[i].full_name);
                          //   admissions.push(jArray[i].admittions);
                          //   // alert(jArray[i].full_name);
                          //   }
                            // alert(JSON.stringify(label));
                          const medicalLOSlabels = label;

                          const medicalLOSdata = {
                            labels: medicalLOSlabels,
                            datasets: [{
                              label: 'Medical LOS',
                              backgroundColor: 'rgb(41, 134, 204, 0.9)',
                              borderColor: 'rgb(41, 134, 204, 0.9)',
                              data: LOS,
                            
                        
                            }]
                          };
                          

                          const medicalLOSconfig = {
                            type: 'bar',
                            
                            data: medicalLOSdata,
                            options: {
                                scales : {
                                y : {
                                    afterDataLimits(scale) {
                                  scale.max += 1;
                                }
                                }
                            },

                              maintainAspectRatio: false,
                              responsive: true,
                            plugins: {
                                datalabels: {
                                        anchor: 'end',
                                        align: 'top',
                                        formatter: Math.round,
                                        font: {
                                            weight: 'thin',
                                            size: 14
                                        }
                                    },
                              
                            },
                          
                            
                          },
                          };
                        const medicalLOSctx = document.getElementById('medicalLOS').getContext('2d');
                        const medicalLOS = new Chart(
                          medicalLOSctx,
                          medicalLOSconfig
                          );
                        
                        </script>
          </div>
          <div class="col-md-6">
                            
                        <div class="chart-container" style="position: relative; height:25vh">
                        <canvas id="physicalLOS"></canvas>
                        </div>

                        <script>
                      
                          var label = <?php echo json_encode($label); ?>;
                          var LOS = <?php echo json_encode($LOS); ?>;
                          // var consultant = [];
                          // var admissions = [];

                          // for(var i=0; i<jArray.length; i++){
                          //   consultant.push("Dr. " + jArray[i].full_name);
                          //   admissions.push(jArray[i].admittions);
                          //   // alert(jArray[i].full_name);
                          //   }
                            // alert(JSON.stringify(label));
                          const physicalLOSlabels = label;

                          const physicalLOSdata = {
                            labels: physicalLOSlabels,
                            datasets: [{
                              label: 'Physical LOS',
                              backgroundColor: 'rgb(41, 134, 204, 0.9)',
                              borderColor: 'rgb(41, 134, 204, 0.9)',
                              data: LOS,
                            
                        
                            }]
                          };
                          

                          const physicalLOSconfig = {
                            type: 'bar',
                            
                            data: physicalLOSdata,
                            options: {
                                scales : {
                                y : {
                                    afterDataLimits(scale) {
                                  scale.max += 1;
                                }
                                }
                            },

                              maintainAspectRatio: false,
                              responsive: true,
                            plugins: {
                                datalabels: {
                                        anchor: 'end',
                                        align: 'top',
                                        formatter: Math.round,
                                        font: {
                                            weight: 'thin',
                                            size: 14
                                        }
                                    },
                              
                            },
                          
                            
                          },
                          };
                        const physicalLOSctx = document.getElementById('physicalLOS').getContext('2d');
                        const physicalLOS = new Chart(
                          physicalLOSctx,
                            physicalLOSconfig
                          );
                        
                        </script>
                       </div>
                       </div>
                       <div class="row">
                    <div class="col-md-6" >
                    <div class="chart-container" style=" height:25vh;">

                        <canvas id="myChartdoughnut"></canvas>
                                          </div>
                                      
                   </div>
                   <div class="col-md-6">
                   <div class="chart-container" style="position: relative; height:25vh">
                        <canvas id="icuLOS"></canvas>
                        </div>

                        <script>
                      
                          var label = <?php echo json_encode($label); ?>;
                          var LOS = <?php echo json_encode($icuaverageLOSa); ?>;
                          // var consultant = [];
                          // var admissions = [];

                          // for(var i=0; i<jArray.length; i++){
                          //   consultant.push("Dr. " + jArray[i].full_name);
                          //   admissions.push(jArray[i].admittions);
                          //   // alert(jArray[i].full_name);
                          //   }
                            // alert(JSON.stringify(label));
                          const icuLOSlabels = label;

                          const icuLOSdata = {
                            labels: icuLOSlabels,
                            datasets: [{
                              label: 'ICU LOS',
                              backgroundColor: 'rgb(41, 134, 204, 0.9)',
                              borderColor: 'rgb(41, 134, 204, 0.9)',
                              data: LOS,
                            
                        
                            }]
                          };
                          

                          const icuLOSconfig = {
                            type: 'bar',
                            
                            data: icuLOSdata,
                            options: {
                                scales : {
                                y : {
                                    afterDataLimits(scale) {
                                  scale.max += 1;
                                }
                                }
                            },

                              maintainAspectRatio: false,
                              responsive: true,
                            plugins: {
                                datalabels: {
                                        anchor: 'end',
                                        align: 'top',
                                        formatter: Math.round,
                                        font: {
                                            weight: 'thin',
                                            size: 14
                                        }
                                    },
                              
                            },
                          
                            
                          },
                          };
                        const icuLOSctx = document.getElementById('icuLOS').getContext('2d');
                        const icuLOS = new Chart(
                          icuLOSctx,
                          icuLOSconfig
                          );
                        
                        </script>
        </div>
                   </div>

                       
</div>
<div class="col-md-4">
  <div class="chart-container" style="position: relative; height:45vh">
                          <canvas id="ConsultantLOS"></canvas>
                          </div>
</div>
</div>

                        </page>

<page size="A4" layout="landscape" display="none" style="display: none">
<div class="row">

                        <script>
                      
                          var label = <?php echo json_encode($consultantname); ?>;
                          var LOS = <?php echo json_encode($consultantLOS); ?>;
                          // var consultant = [];
                          // var admissions = [];

                          // for(var i=0; i<jArray.length; i++){
                          //   consultant.push("Dr. " + jArray[i].full_name);
                          //   admissions.push(jArray[i].admittions);
                          //   // alert(jArray[i].full_name);
                          //   }
                            // alert(JSON.stringify(label));
                          const  consultantLOSlabels = label;

                          const  consultantLOSdata = {
                            labels:  consultantLOSlabels,
                            datasets: [{
                              label: 'Per Consultant Physical LOS',
                              backgroundColor: 'rgb(41, 134, 204, 0.9)',
                              borderColor: 'rgb(41, 134, 204, 0.9)',
                              data: LOS,
                            
                        
                            }]
                          };
                          

                          const  consultantLOSconfig = {
                            type: 'bar',
                            
                            data:  consultantLOSdata,
                            options: {
                              indexAxis: 'y',
                                scales : {
                                x : {
                                    afterDataLimits(scale) {
                                  scale.max += 0;
                                }
                                }
                            },

                              maintainAspectRatio: false,
                              responsive: true,
                              
                            plugins: {
                                datalabels: {
                                        anchor: 'center',
                                        align: 'center',
                                        formatter: Math.round,
                                        color: '#ffffff',
                                        font: {
                                            weight: 'thin',
                                            size: 14
                                        }
                                    },
          
                              
                            },
                          
                            
                          },
                          };
                        const ConsultantLOSctx = document.getElementById('ConsultantLOS').getContext('2d');
                        const ConsultantLOS = new Chart(
                          ConsultantLOSctx,
                            consultantLOSconfig
                          );
                        
                        </script>

</div>

<script>
  
  
  var label1 = <?php echo json_encode(array_keys($dishtransnumbers)); ?>;
  var all_counts = <?php echo json_encode(array_values($dishtransnumbers)); ?>;
  // var consultant = [];
  // var admissions = [];

  // for(var i=0; i<jArray.length; i++){
  //   consultant.push("Dr. " + jArray[i].full_name);
  //   admissions.push(jArray[i].admittions);
  //   // alert(jArray[i].full_name);
  //   }
    // alert(JSON.stringify(label));
  const labels1 = label1;

  const data1 = {
    labels: labels1,
    
    datasets: [{
      label: 'Current Active Patient Counts',
      backgroundColor: [
      'rgb(255, 99, 132)',
      'rgb(54, 162, 235)',
      'rgb(255, 205, 86)',
      'rgb(36, 169, 115)',
      'rgb(239, 62, 91)',
      'rgb(75, 37, 109)',
      'rgb(63, 100, 126)',

    ],
      data: all_counts,
    }]
  };

  const myChartdoughnutconfig = {
    type: 'doughnut',
    data: data1,
    options: {
    responsive: false,

    plugins: {
   
      datalabels: {
        display: false,
                                        anchor: 'end',
                                        align: 'end',
                                        formatter: Math.round,
                                        color: '#000000',
                                        font: {
                                            weight: 'thin',
                                            size: 14
                                        }
                                    },
                                    legend: {
                                      labels: {
          generateLabels: (chart) => {
            const datasets = chart.data.datasets;
            return datasets[0].data.map((data, i) => ({
              text: `${chart.data.labels[i]} (${data})`,
              fillStyle: datasets[0].backgroundColor[i],
            }))
          }
        },
                                      display: true,
                                      position: 'top'
                                },
      title: {
        display: true,
        text: '<?php echo $title; ?>',
      }
    }
  },
  };
</script>
<script>
  const myChartdoughnutctx = document.getElementById('myChartdoughnut').getContext('2d');
  const myChartdoughnut = new Chart(
    myChartdoughnutctx,
    myChartdoughnutconfig
                          );

  
                       
                        
 
</script>

<div class="table-responsive text-nowrap">
<table class="table table-striped">
<thead>
  <tr>
    <th scope="col">KPI for <?php echo (int)$ydate1; ?></th>
<?php
foreach ($label as $l){
  echo "<th>" . $l . "</th>" ;
}
?>
</tr>
</thead>
<tbody>
 
  <tr>
    <th>Transfer to ICU</th>
    <?php
foreach ($toicu as $t){
  echo "<td>" . $t . "</td>" ;
}
?>
  </tr>
  
  <tr>
    <th>Bed Days</th>
    <?php
foreach ($beddays as $b){
  echo "<td>" . $b . "</td>" ;
}
?>
  </tr>

  <tr>
    <th>Weekend Discharge #</th>
    <?php
foreach ($weekend as $w){
  echo "<td>" . $w . "</td>" ;
}
?>
  </tr>
  <tr>
    <th>Weekend Discharge %</th>
    <?php
foreach ($weekend_p as $w){
  echo "<td>" . $w . "</td>" ;
}
?>
  </tr>
  <tr>
    <th>Total Patients</th>
    <?php
foreach ($total_patients as $tp){
  echo "<td>" . $tp . "</td>" ;
}
?>
  </tr>
  <tr>
    <th>Long Stay</th>
    <?php
foreach ($LSP as $L){
  echo "<td>" . $L . "</td>" ;
}
?>
  </tr>
  <tr>
    <th>Long Stay %</th>
    <?php
foreach ($LSP_P as $Lp){
  echo "<td>" . $Lp . "</td>" ;
}
?>
  </tr>
  <tr>
    <th>72 hrs Readmissions</th>
    <?php
foreach ($readmission as $r){
  echo "<td>" . $r . "</td>" ;
}
?>
  </tr>
</tbody>
</table>
</div>
</page>


</body>
<?php
    






























}  elseif ($time == "quarterly"){
    $title ='Quarterly Overview';

       $n=0;
       $quarter=4;
       $allweekend_discharge=0;

       
       $date1 = date("Y-12-1", strtotime($date));
       $long_stay_date = date("Y-12-1", strtotime($date));
       $date_day1 =  date("Y-10-1", strtotime($date));
       $first_day1 =  date("Y-12-1", strtotime($date));

       while($n < 4){

        
      
        $ydate1=date("Y",strtotime($date1));
         $qStart = sprintf('%04d-%02d-01', $ydate1, ($quarter - 1) * 3 + 1);
         $qEnd   = date('Y-m-t', strtotime($qStart . ' +2 month'));
         // sargable date range (was QUARTER()/YEAR())
         $formationSQL = "SELECT * FROM picupatients WHERE ADMDATE BETWEEN ? AND ? AND (current_location != 'ICU' or current_location is null)";
         $stmt = $mysqli->prepare($formationSQL);
         $stmt->bind_param('ss', $qStart, $qEnd);
         $stmt->execute();
         $result1 = $stmt->get_result();
         $admittedpcount = mysqli_num_rows($result1);

         // sargable date range (was QUARTER()/YEAR())
         $formationSQL = "SELECT * FROM picupatients WHERE DISDATE BETWEEN ? AND ? AND (current_location != 'ICU' or current_location is null)";
         $stmt = $mysqli->prepare($formationSQL);
         $stmt->bind_param('ss', $qStart, $qEnd);
         $stmt->execute();
         $result1 = $stmt->get_result();
         $dischargedpcount = mysqli_num_rows($result1);

         // sargable date range (was QUARTER()/YEAR())
         $formationSQL = "SELECT * FROM consultations WHERE consultation_date BETWEEN ? AND ?";
         $stmt = $mysqli->prepare($formationSQL);
         $stmt->bind_param('ss', $qStart, $qEnd);
         $stmt->execute();
         $result1 = $stmt->get_result();
         $newconsultscount = mysqli_num_rows($result1);

         // sargable date range (was QUARTER()/YEAR())
         $formationSQL = "SELECT * FROM consultations WHERE signoff_date BETWEEN ? AND ?";
         $stmt = $mysqli->prepare($formationSQL);
         $stmt->bind_param('ss', $qStart, $qEnd);
         $stmt->execute();
         $result1 = $stmt->get_result();
         $signedoffcount = mysqli_num_rows($result1);

         ///// Trans to ICU
    // sargable date range (was QUARTER()/YEAR())
    $formationSQL = "SELECT DISDATE FROM picupatients WHERE DISDATE BETWEEN ? AND ? AND DISTO = 'Intensive Care (ICU)'";
    $stmt = $mysqli->prepare($formationSQL);
    $stmt->bind_param('ss', $qStart, $qEnd);
    $stmt->execute();
    $result1 = $stmt->get_result();
    $transtoicu = mysqli_num_rows($result1);

         $m_in_quarter = $quarter * 3;

               
      //////////////
      //Los
      /////////////
      $q_m_average=0;
      $q_average=0;
      $q_LSP_count=0;
      $q_total_count=0;
      $q_readmission_count=0;
      $m_in_quarter1=$m_in_quarter;
      for ($x = 1; $x <= 3; $x++) {

// sargable date range bounds for the current month ($m_in_quarter1/$ydate1)
$q_first_day_ofmonth = sprintf('%04d-%02d-01', $ydate1, $m_in_quarter1);
$q_last_day_ofmonth  = date('Y-m-t', strtotime($q_first_day_ofmonth));

//////////////
// Medical Los
/////////////

// sargable date range (was MONTH()/YEAR())
$formationSQL = "SELECT ADMDATE, med_DISDATE FROM picupatients WHERE DISDATE IS NOT NULL AND DISDATE BETWEEN ? AND ? AND (current_location != 'ICU' or current_location is null)";
$stmt = $mysqli->prepare($formationSQL);
$stmt->bind_param('ss', $q_first_day_ofmonth, $q_last_day_ofmonth);
$stmt->execute();
$result1 = $stmt->get_result();
$datesss = $result1 -> fetch_all(MYSQLI_ASSOC);


$los=array();

foreach ($datesss as $d){
  $timeDiff = abs(strtotime($d['ADMDATE']) - strtotime($d['med_DISDATE']));

  array_push($los,$timeDiff/86400);
  
}
// var_dump($los);
// $a = array_filter($los);
if(count($los) > 0) {
  $m_average = array_sum($los)/count($los);
} else {
  $m_average = 0;
}
$q_m_average=$q_m_average+$m_average;

 //////////////
// physical Los
/////////////

// sargable date range (was MONTH()/YEAR())
$formationSQL = "SELECT ADMDATE, DISDATE FROM picupatients WHERE DISDATE IS NOT NULL AND DISDATE BETWEEN ? AND ? AND (current_location != 'ICU' or current_location is null)";
$stmt = $mysqli->prepare($formationSQL);
$stmt->bind_param('ss', $q_first_day_ofmonth, $q_last_day_ofmonth);
$stmt->execute();
$result1 = $stmt->get_result();
$datesss = $result1 -> fetch_all(MYSQLI_ASSOC);


$los=array();

foreach ($datesss as $d){
  $timeDiff = abs(strtotime($d['ADMDATE']) - strtotime($d['DISDATE']));

  array_push($los,$timeDiff/86400);
  
}
// var_dump($los);
// $a = array_filter($los);
if(count($los) > 0) {
  $average = array_sum($los)/count($los);
} else {
  $average = 0;
}
$q_average=$q_average+$average;


/////////////////////
    ///// readmissions
//////////////////////////
// echo $mdate1 ."</br>";
// sargable date range (was MONTH()/YEAR())
$formationSQL = "SELECT * FROM picupatients WHERE ADMDATE BETWEEN ? AND ?";
$stmt = $mysqli->prepare($formationSQL);
$stmt->bind_param('ss', $q_first_day_ofmonth, $q_last_day_ofmonth);
$stmt->execute();
$result1 = $stmt->get_result();
$admitted_patients = $result1 -> fetch_all(MYSQLI_ASSOC);

$readmission_count=0;

$formationSQL = "SELECT * FROM picupatients WHERE DISDATE + INTERVAL 3 DAY >=? AND ID <?  AND MRN=? AND (trans_discharge = 'discharge from ICU' or trans_discharge='discharge from ward' or trans_discharge IS NULL) LIMIT 1";
$stmt = $mysqli->prepare($formationSQL);
foreach ($admitted_patients as $s){
  $stmt->bind_param('sis', $s['ADMDATE'], $s['ID'], $s['MRN']);
  $stmt->execute();
  $result1 = $stmt->get_result();
$recentadmission = mysqli_num_rows($result1);
// $recentadmission1 = $result1 -> fetch_array(MYSQLI_ASSOC);
// var_dump($recentadmission1);
$readmission_count=$readmission_count+$recentadmission;
}

$q_readmission_count=$q_readmission_count+$readmission_count;

$m_in_quarter1=$m_in_quarter1-1;




////////////////////////////////////
//long stays > 30 days
////////////////////////////////////


$first_day_ofmonth = $first_day1;
$last_day_ofmonth= date("Y-m-t", strtotime($first_day_ofmonth));
// echo $last_day_ofmonth ."</br>";
// echo strtotime($last_day_ofmonth) . "</br>";
// echo strtotime($today). "</br>";
if (strtotime($last_day_ofmonth) <= strtotime($today)) {
  $formationSQL = "SELECT * FROM picupatients WHERE ((DISDATE IS NOT NULL AND ADMDATE < ? AND DISDATE >= ?) OR (DISDATE IS NULL AND ADMDATE + INTERVAL 30 DAY < ?)) AND (current_location != 'ICU' or current_location is null)";
  $stmt = $mysqli->prepare($formationSQL);
  $stmt->bind_param('sss', $first_day_ofmonth, $last_day_ofmonth, $last_day_ofmonth);
  $stmt->execute();
  $result1 = $stmt->get_result();
  $LSP_count = mysqli_num_rows($result1);
  // echo $last_day_ofmonth."</br>";
  // var_dump($patient);

  // total number of patients at that month
  $formationSQL = "SELECT * FROM picupatients WHERE (
    (DISDATE IS NULL AND ADMDATE <= ?)
   OR (DISDATE IS NOT NULL AND DISDATE >= ? AND DISDATE <= ?)
   OR (DISDATE IS NOT NULL AND ADMDATE < ? AND DISDATE > ?)
   OR   (ADMDATE >= ? AND ADMDATE <= ?)
   ) AND (current_location != 'ICU' or current_location is null)";
   $stmt = $mysqli->prepare($formationSQL);
   $stmt->bind_param('sssssss', $last_day_ofmonth, $first_day_ofmonth, $last_day_ofmonth, $first_day_ofmonth, $last_day_ofmonth, $first_day_ofmonth, $last_day_ofmonth);
   $stmt->execute();
   $result1 = $stmt->get_result();
  $total_count = mysqli_num_rows($result1);
  
  $time2 = strtotime($first_day1);
  $first_day1 = date("Y-m-d", strtotime("-1 month", $time2));

$q_LSP_count=$q_LSP_count+$LSP_count;
$q_total_count=$q_total_count+$total_count;
  }else{
    $q_LSP_count=$q_LSP_count+0;
$q_total_count=$q_total_count+0;
$time2 = strtotime($first_day1);
$first_day1 = date("Y-m-d", strtotime("-1 month", $time2));
  }



}


    ////////////////////////////////////////////////////////////////
    //average of the 3 months calcualted by sum and devided by
    ////////////////////////////////////////////////////////////////
    $q_average=$q_average/3;
    $q_m_average=$q_m_average/3;




////////////////////////////////////////////////////////////////
// Number of bed days
////////////////////////////////////////////////////////////////
// $formationSQL = "SELECT * FROM picupatients WHERE ADMDATE = '".$date1."' AND (DISDATE = '".$date1."' OR DISDATE IS NULL)";

// number of day in quarter
$d_in_quarter=0;
    for ($x = 1; $x <= 3; $x++) {

        $ds=cal_days_in_month(CAL_GREGORIAN,$m_in_quarter,$ydate1);
        $m_in_quarter=$m_in_quarter-1;
        $d_in_quarter=$d_in_quarter+$ds;
    }
// echo $d_in_quarter."</br>";
$quarter_beddayscount=0;

// echo $date_day;
$date_day= $date_day1;
    for ($x = 1; $x <= $d_in_quarter; $x++) {
        // Where not in ICU and where not discharged at the same day of admission
        // echo $date_day ."</br>";
        if (strtotime($date_day) <= strtotime($today)) {
        $formationSQL = "SELECT * FROM picupatients WHERE ADMDATE <= ? AND (DISDATE >= ? OR DISDATE IS NULL) AND NOT DISDATE <=> ADMDATE  AND (current_location != 'ICU' or current_location is null)";
        $stmt = $mysqli->prepare($formationSQL);
        $stmt->bind_param('ss', $date_day, $date_day);
        $stmt->execute();
        $result1 = $stmt->get_result();
        $dayscount = mysqli_num_rows($result1);
        $quarter_beddayscount=$quarter_beddayscount+$dayscount;
        // echo $date_day . "</br>";
      }
      // echo $date_day . "</br>";
      
      //weekend discharges
      if (date('w', strtotime($date_day)) == 6 || date('w', strtotime($date_day)) == 5){
        // echo $date_day . "</br>";
    $formationSQL = "SELECT * FROM picupatients WHERE DISDATE = ? AND (current_location != 'ICU' or current_location is null)";
    $stmt = $mysqli->prepare($formationSQL);
    $stmt->bind_param('s', $date_day);
    $stmt->execute();
    $result1 = $stmt->get_result();
    $weekend_discharge = mysqli_num_rows($result1);
    $allweekend_discharge=$allweekend_discharge+$weekend_discharge;
      }
   

        $date_day= date('Y-m-d', strtotime($date_day . ' +1 day'));

    }
     


      array_push($admissions,$admittedpcount);
      array_push($discharges,$dischargedpcount);
      array_push($toicu,$transtoicu);
      array_push($newconsults,$newconsultscount);
      array_push($signedoff,$signedoffcount);
      array_push($beddays,$quarter_beddayscount);
      array_push($LSP,$q_LSP_count);
      array_push($total_patients,$q_total_count);
      array_push($readmission,$q_readmission_count);

      if($q_average>0){
        array_push($LOS,(number_format(($q_average), 2, '.', '')." Days"));
        } else {
          array_push($LOS,0);
                }

      if($q_m_average>0){
        array_push($m_LOS,(number_format(($q_m_average), 2, '.', '')." Days"));
        } else {
          array_push($m_LOS,0);
                }

      if($dischargedpcount>0){
        
        array_push($weekend,(number_format($allweekend_discharge, 0, '.', '').""));
      array_push($weekend_p,(number_format((($allweekend_discharge/$dischargedpcount)*100), 2, '.', '')."%"));
      
    }else{
      array_push($weekend_p,"N/A");
      array_push($weekend,"N/A");
    }
      
    if ($q_total_count>0){

      $num=($q_LSP_count/$q_total_count)*100;
      array_push($LSP_P,(number_format($num, 2, '.', '')."%"));
    }elseif(strcmp($q_total_count, 'Pending') == 0){
      array_push($LSP_P,"Pending");
    }else{
      array_push($LSP_P,"0%");
    }


    $n++;
    $quarter=$quarter-1;
    
    $time = strtotime($date_day1);
    $date_day1 = date("Y-m-d", strtotime("-3 month", $time));
   
    
    }
    
    $label=['Forth Quarter','Third Quarter', 'Second Quarter', 'First Quarter']  ;
    
$label=array_reverse($label);
$admissions=array_reverse($admissions);
$discharges=array_reverse($discharges);
$toicu=array_reverse($toicu);
$newconsults=array_reverse($newconsults);
$signedoff=array_reverse($signedoff);
$beddays=array_reverse($beddays);
$LOS=array_reverse($LOS);
$m_LOS=array_reverse($m_LOS);
$weekend=array_reverse($weekend);
$weekend_p=array_reverse($weekend_p);
$LSP=array_reverse($LSP);
$total_patients=array_reverse($total_patients);
$LSP_P=array_reverse($LSP_P);
$readmission=array_reverse($readmission);

    ?>


<table class="table table-striped" display="none">
<thead>
  <tr>
    <th scope="col">KPI for <?php echo (int)$ydate1; ?></th>
<?php
foreach ($label as $l){
  echo "<th>" . $l . "</th>" ;
}
?>
</tr>
</thead>
<tbody>
  <tr>
    <th>Admissions</th>
    <?php
foreach ($admissions as $a){
  echo "<td>" . $a . "</td>" ;
}
?>
  </tr>
  <tr>
    <th>Discharges</th>
    <?php
foreach ($discharges as $d){
  echo "<td>" . $d . "</td>" ;
}
?>
  </tr>
  <tr>
    <th>Transfer to ICU</th>
    <?php
foreach ($toicu as $t){
  echo "<td>" . $t . "</td>" ;
}
?>
  </tr>
  <tr>
    <th>Consultations</th>
    <?php
foreach ($newconsults as $n){
  echo "<td>" . $n . "</td>" ;
}
?>
  </tr>
  <tr>
    <th>Sign Offs</th>
    <?php
foreach ($signedoff as $s){
  echo "<td>" . $s . "</td>" ;
}
?>
  </tr>
  <tr>
    <th>Bed Days</th>
    <?php
foreach ($beddays as $b){
  echo "<td>" . $b . "</td>" ;
}
?>
  </tr>
  <tr>
    <th>Clinical LOS</th>
    <?php
foreach ($m_LOS as $l){
  echo "<td>" . $l . "</td>" ;
}
?>
  </tr>
  <tr>
    <th>Physical LOS</th>
    <?php
foreach ($LOS as $l){
  echo "<td>" . $l . "</td>" ;
}
?>
  </tr>
  <tr>
    <th>Weekend Discharge #</th>
    <?php
foreach ($weekend as $w){
  echo "<td>" . $w . "</td>" ;
}
?>
  </tr>
  <tr>
    <th>Weekend Discharge %</th>
    <?php
foreach ($weekend_p as $w){
  echo "<td>" . $w . "</td>" ;
}
?>
  </tr>
  <tr>
    <th>Total Patients</th>
    <?php
foreach ($total_patients as $tp){
  echo "<td>" . $tp . "</td>" ;
}
?>
  </tr>
  <tr>
    <th>Long Stay</th>
    <?php
foreach ($LSP as $L){
  echo "<td>" . $L . "</td>" ;
}
?>
  </tr>
  <tr>
    <th>Long Stay %</th>
    <?php
foreach ($LSP_P as $Lp){
  echo "<td>" . $Lp . "</td>" ;
}
?>
  </tr>
  <tr>
    <th>72 hrs Readmissions</th>
    <?php
foreach ($readmission as $r){
  echo "<td>" . $r . "</td>" ;
}
?>
  </tr>
</tbody>
</table>
<?php
    
} 
?>


