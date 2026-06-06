<?php 
$today=date("Y-m-d");
require ('../dbconnect.php');
if (isset($_GET['y'])){
$date="1/1/".$_GET['y'];
}else{
  echo "<script language='javascript'>\n";
  echo "window.location.href = '../allstat.php';";
  echo "</script>\n";
}

?>
<Html>   
<Head>  
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.0.0/dist/chart.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>
<link href="../vendor/font-awesome-4.7/css/font-awesome.min.css" rel="stylesheet" media="all">
<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/css/bootstrap.min.css" integrity="sha384-Gn5384xqQ1aoWXA+058RXPxPg6fy4IWvTNh0E263XmFcJlSAwiGgFAW/dAiS6JXm" crossorigin="anonymous">

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


</Head>  

<?php
  $formationSQL = "SELECT * FROM members WHERE position = '3' AND active = 1";
  $result1 = $mysqli->query($formationSQL);
  $consultants = $result1 -> fetch_all(MYSQLI_ASSOC);

 $date1 = date("Y-12-1", strtotime($date));

    $n=0;

// var_dump($dishtransnumbers);
    /// get monthly data
    while($n < 12){

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
        $readmission=array();
        $dishtranslabels=array();
        $dishtransnumbers=array();


      $ydate1=date("Y",strtotime($date1));
      $mdate1=date("m",strtotime($date1));
      $last_day_ofmonth=date("Y-m-t", strtotime($date1));
      $first_day_ofmonth=date("Y-m-01", strtotime($date1));
      $ds=cal_days_in_month(CAL_GREGORIAN,$mdate1,$ydate1);

      $dateObj   = DateTime::createFromFormat('!m', $mdate1);
      $monthName = $dateObj->format('F'); // March



      /// skip months not yet startEd
      if (strtotime($first_day_ofmonth) >= strtotime($today)) {
      
        $n++;
        // $date->modify("-1 month");
        $time = strtotime($date1);
        $date1 = date("Y-m-d", strtotime("-1 month", $time));
        continue;
      }


    // get consultant based data for the year.
    $consultantname=array();
    $consultantLOS=array();

  
    foreach($consultants as $c){
    $formationSQL = "SELECT ADMDATE, DISDATE FROM picupatients WHERE DISDATE IS NOT NULL AND consultant_id='".$c['member_id']."' AND MONTH(DISDATE) = '".$mdate1."' AND YEAR(DISDATE) = '".$ydate1."' AND (current_location != 'ICU' or current_location is null)";
    $result1 = $mysqli->query($formationSQL);
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
      array_push($consultantLOS,(number_format(($average), 1, '.', '')));
      } else {
        array_push($consultantLOS,0);
              }
    array_push($consultantname,$c['full_name']);
    }



        ///// Discharge / Transfer to
        $title='Discharge / Transfer in '.$ydate1;
        $formationSQL = "SELECT DISTO, DISDATE, COUNT(*) FROM picupatients WHERE DISDATE IS NOT NULL AND MONTH(DISDATE) = '".$mdate1."' AND YEAR(DISDATE) = '".$ydate1."' AND (current_location != 'ICU' or current_location is null) GROUP BY DISTO";
        $result1 = $mysqli->query($formationSQL);
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




      $n1=0;
      $date2=date("Y-m-01", strtotime($date1));
      while($n1 < $ds){
        
     
  $formationSQL = "SELECT * FROM picupatients WHERE ADMDATE = '".$date2."' AND (current_location != 'ICU' or current_location is null)";
  $result1 = $mysqli->query($formationSQL);
  $admittedpcount = mysqli_num_rows($result1);

  $formationSQL = "SELECT * FROM picupatients WHERE DISDATE = '".$date2."' AND (current_location != 'ICU' or current_location is null)";
  $result1 = $mysqli->query($formationSQL);
  $dischargedpcount = mysqli_num_rows($result1);
  
  $formationSQL = "SELECT * FROM consultations WHERE consultation_date = '".$date2."'";
  $result1 = $mysqli->query($formationSQL);
  $newconsultscount = mysqli_num_rows($result1);

  $formationSQL = "SELECT * FROM consultations WHERE signoff_date = '".$date2."'";
  $result1 = $mysqli->query($formationSQL);
  $signedoffcount = mysqli_num_rows($result1);

    ///// Trans to ICU
    $formationSQL = "SELECT DISDATE FROM picupatients WHERE  DISDATE = '".$date2."' AND DISTO = 'Intensive Care (ICU)'";
    $result1 = $mysqli->query($formationSQL);
    $transtoicu = mysqli_num_rows($result1);

    array_push($label,$n1);
    array_push($admissions,$admittedpcount);
    array_push($discharges,$dischargedpcount);
    array_push($toicu,$transtoicu);
    array_push($newconsults,$newconsultscount);
    array_push($signedoff,$signedoffcount);
        $n1++;
        $date2 = date("Y-m-d", strtotime("+1 day", strtotime($date2)));

      }
      //////////////
      // Medical Los
      /////////////
  
      $formationSQL = "SELECT ADMDATE, med_DISDATE FROM picupatients WHERE DISDATE IS NOT NULL AND MONTH(DISDATE) = '".$mdate1."' AND YEAR(ADMDATE) = '".$ydate1."' AND (current_location != 'ICU' or current_location is null)";
      $result1 = $mysqli->query($formationSQL);
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
  
      $formationSQL = "SELECT ADMDATE, DISDATE FROM picupatients WHERE DISDATE IS NOT NULL AND MONTH(DISDATE) = '".$mdate1."' AND YEAR(ADMDATE) = '".$ydate1."' AND (current_location != 'ICU' or current_location is null)";
      $result1 = $mysqli->query($formationSQL);
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
  
      $formationSQL = "SELECT ADMDATE, DISDATE FROM picupatients WHERE DISDATE IS NOT NULL AND MONTH(DISDATE) = '".$mdate1."' AND YEAR(ADMDATE) = '".$ydate1."' AND current_location ='ICU'";
      $result1 = $mysqli->query($formationSQL);
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
$formationSQL = "SELECT * FROM picupatients WHERE ((DISDATE IS NOT NULL AND ADMDATE < '".$first_day_ofmonth."' AND DISDATE >= '".$last_day_ofmonth."') OR (DISDATE IS NULL AND ADMDATE + INTERVAL 30 DAY < '".$last_day_ofmonth."')) AND (current_location != 'ICU' or current_location is null)";
$result1 = $mysqli->query($formationSQL);
$LSP_count = mysqli_num_rows($result1);
// echo $last_day_ofmonth."</br>";
// var_dump($patient);

// total number of patients at that month
$formationSQL = "SELECT * FROM picupatients WHERE (
  (DISDATE IS NULL AND ADMDATE <= '".$last_day_ofmonth."') 
 OR (DISDATE IS NOT NULL AND DISDATE >= '".$first_day_ofmonth."' AND DISDATE <= '".$last_day_ofmonth."') 
 OR (DISDATE IS NOT NULL AND ADMDATE < '".$first_day_ofmonth."' AND DISDATE > '".$last_day_ofmonth."') 
 OR   (ADMDATE >= '".$first_day_ofmonth."' AND ADMDATE <= '".$last_day_ofmonth."')
 ) AND (current_location != 'ICU' or current_location is null)";
 $result1 = $mysqli->query($formationSQL);
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


$ds=cal_days_in_month(CAL_GREGORIAN,$mdate1,$ydate1);
$monthly_beddayscount=0;
$allweekend_discharge=0;
$date_day= $date1;
// echo $date_day;

    for ($x = 1; $x <= $ds; $x++) {
        // Where not in ICU and where not discharged at the same day of admission
        // echo $date_day ."</br>";
        if (strtotime($date_day) <= strtotime($today)) {
        $formationSQL = "SELECT * FROM picupatients WHERE ADMDATE <= '".$date_day."' AND (DISDATE >= '".$date_day."' OR DISDATE IS NULL) AND NOT DISDATE <=> ADMDATE  AND (current_location != 'ICU' or current_location is null)";
        $result1 = $mysqli->query($formationSQL);
        $dayscount = mysqli_num_rows($result1);
        $monthly_beddayscount=$monthly_beddayscount+$dayscount;


      //weekend discharges
      if (date('w', strtotime($date_day)) == 6 || date('w', strtotime($date_day)) == 5){
          // echo $date_day . "</br>";
      $formationSQL = "SELECT * FROM picupatients WHERE DISDATE = '".$date_day."' AND (current_location != 'ICU' or current_location is null)";
      $result1 = $mysqli->query($formationSQL);
      $weekend_discharge = mysqli_num_rows($result1);
      $allweekend_discharge=$allweekend_discharge+$weekend_discharge;
        }
     


      }
        $date_day= date('Y-m-d', strtotime($date_day . ' +1 day'));
    }
/////////////////////
    ///// readmissions
//////////////////////////
// echo $mdate1 ."</br>";
$formationSQL = "SELECT * FROM picupatients WHERE MONTH(ADMDATE) = '".$mdate1."' AND YEAR(ADMDATE) = '".$ydate1."'";
$result1 = $mysqli->query($formationSQL);
$admitted_patients = $result1 -> fetch_all(MYSQLI_ASSOC);

$readmission_count=0;

foreach ($admitted_patients as $s){
  $formationSQL = "SELECT * FROM picupatients WHERE DISDATE + INTERVAL 3 DAY >='".$s['ADMDATE']."' AND ID <'".$s['ID']."'  AND MRN='".$s['MRN']."' AND (trans_discharge = 'discharge from ICU' or trans_discharge='discharge from ward' or trans_discharge IS NULL) LIMIT 1";
  $result1 = $mysqli->query($formationSQL);
$recentadmission = mysqli_num_rows($result1);
// $recentadmission1 = $result1 -> fetch_array(MYSQLI_ASSOC);
// var_dump($recentadmission1);
$readmission_count=$readmission_count+$recentadmission;
}

/////////////////
      

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

    
    if ($total_count>0){

      $num=($LSP_count/$total_count)*100;
      array_push($LSP_P,(number_format($num, 2, '.', '')."%"));
    }elseif(strcmp($total_count, 'Pending') == 0){
      array_push($LSP_P,"Pending");
    }else{
      array_push($LSP_P,"0%");
    }
    
   











?>


<!-- First Page -->
<page size="A4" layout="landscape">
  <div class="row">
    <div class="col-md-12">
      <img style="width: inherit;" src="../dist/img/reportheader.png">
    </div>
  </div>
  <div class="row">
    <div class="col-md-12" style=" text-align: center; margin-top: 15%; ">
      <spnan style=" color: #004aab; font-size: xx-large; font-weight: 600; font-family: inherit; ">Internal Medicine Department Performance Report For <?php echo $ydate1 . "/" . $mdate1; ?></spnan>
    </div>
  </div>
  </page>



<!-- Second Page -->
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
                <span class="counter-value"><?php echo $total_count; ?></span>
                <h3>Total Patients</h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="counter blue">
                <div class="counter-icon">
                    <i class="fa fa-calendar"></i>
                </div>
                <span class="counter-value" style="    font-size: 22px;"><?php if($dischargedpcount==0){echo "0";}else{echo $allweekend_discharge; echo " (".(number_format((($allweekend_discharge/array_sum($discharges))*100), 2, '.', ''))."%)";} ?></span>
                <h3>Weekend Discharge</h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="counter blue">
                <div class="counter-icon">
                    <i class="fa fa-clock-o"></i>
                </div>
                <span class="counter-value" style="     font-size: 22px;"><?php if (strtotime($today) <= strtotime($last_day_ofmonth)){echo "Pending";}else{ echo $LSP_count; echo " (".(number_format(($LSP_count/ $total_count*100), 2, '.', ''))."%)";} ?></span>
                <h3>Long Stay</h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="counter blue">
                <div class="counter-icon">
                    <i class="fa fa-refresh"></i>
                </div>
                <span class="counter-value"><?php echo $readmission_count; ?></span>
                <h3>72 hrs Readmissions</h3>
            </div>
        </div>
  
</div>

</div>
  <div class="col-md-12">

    <div class="row" >
      <div class="col-md-12" style="text-align: center;">

  <span style=" font-size: large; font-weight: 500; color: darkslategray; ">Daily Overview: Admission | Diaschage | Consultations</span>
        <div class="chart-container" style="position: relative; height:26vh">
        <canvas id="mymonthlyChart<?php echo $n; ?>"></canvas>
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
  const mlabels<?php echo $n; ?> = label;

  const mdata<?php echo $n; ?> = {
    labels: mlabels<?php echo $n; ?>,
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
  
  const colors<?php echo $n; ?> = ['red', 'blue', 'yellow', 'green'];
  const weekend<?php echo $n; ?> =[];
  const mconfig<?php echo $n; ?> = {
    type: 'bar',
    
    data: mdata<?php echo $n; ?>,
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
const ctx<?php echo $n; ?> = document.getElementById('mymonthlyChart<?php echo $n; ?>').getContext('2d');
const mymonthlyChart<?php echo $n; ?> = new Chart(
    ctx<?php echo $n; ?>,
    mconfig<?php echo $n; ?>
  );
 
</script>

</div>
</div>
  </page>





<!-- Third Page -->
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
                        <div class="counter blue">
                            <div class="counter-icon">
                                <i class="fa fa-users"></i>
                            </div>
                            <span class="counter-value"><?php echo number_format($m_average, 2, '.', ''); ?></span>
                            <h3>Clinical LOS</h3>
                        </div>       

                        </div>
                        <div class="col-md-6">
                     
                        <div class="counter blue">
                          <div class="counter-icon">
                              <i class="fa fa-users"></i>
                          </div>
                          <span class="counter-value"><?php echo number_format($average, 2, '.', ''); ?></span>
                          <h3>Physical LOS</h3>
                      </div> 
                        </div>
                </div>
                <div class="row" style=" margin-top: 5%; ">
                    <div class="col-md-6" >
                    <div class="chart-container" style=" height:25vh;">

                        <canvas id="myChartdoughnut<?php echo $n; ?>"></canvas>
                     </div>
                                      
                   </div>                        <div class="col-md-6">
                        
                        <div class="counter blue">
                          <div class="counter-icon">
                              <i class="fa fa-users"></i>
                          </div>
                          <span class="counter-value"><?php echo number_format($icuaverageLOS, 2, '.', ''); ?></span>
                          <h3>ICU LOS</h3>
                      </div> 
                        </div>
                </div>

                       
            </div>
            <div class="col-md-4">
                <div class="chart-container" style="position: relative; height:45vh">
                                        <canvas id="ConsultantLOS<?php echo $n; ?>"></canvas>
                                        </div>
            </div>
</div>
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
                          const  consultantLOSlabels<?php echo $n; ?> = label;

                          const  consultantLOSdata<?php echo $n; ?> = {
                            labels:  consultantLOSlabels<?php echo $n; ?>,
                            datasets: [{
                              label: 'Per Consultant Physical LOS',
                              backgroundColor: 'rgb(41, 134, 204, 0.9)',
                              borderColor: 'rgb(41, 134, 204, 0.9)',
                              data: LOS,
                            
                        
                            }]
                          };
                          

                          const  consultantLOSconfig<?php echo $n; ?> = {
                            type: 'bar',
                            
                            data:  consultantLOSdata<?php echo $n; ?>,
                            options: {
                              indexAxis: 'y',
                                scales : {
                                x : {
                                    afterDataLimits(scale) {
                                  scale.max += 5;
                                }
                                }
                            },

                              maintainAspectRatio: false,
                              responsive: true,
                            plugins: {
                                datalabels: {
                                        anchor: 'end',
                                        align: 'end',
                                        formatter: Math,
                                        color: '#00000',
                                        font: {
                                            weight: 'thin',
                                            size: 14
                                        }
                                    },
                              
                            },
                          
                            
                          },
                          };
                        const ConsultantLOSctx<?php echo $n; ?> = document.getElementById('ConsultantLOS<?php echo $n; ?>').getContext('2d');
                        const ConsultantLOS<?php echo $n; ?> = new Chart(
                          ConsultantLOSctx<?php echo $n; ?>,
                            consultantLOSconfig<?php echo $n; ?>
                          );
                        
                        </script>

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
                        const labels1<?php echo $n; ?> = label1;

                        const data1<?php echo $n; ?> = {
                            labels: labels1<?php echo $n; ?>,
                            
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

                        const myChartdoughnutconfig<?php echo $n; ?> = {
                            type: 'doughnut',
                            data: data1<?php echo $n; ?>,
                            options: {
                            responsive: true,

                            plugins: {
                        
                              datalabels: {
        display: false,
                                        anchor: 'end',
                                        align: 'end',
                                        formatter: Math,
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
                        const myChartdoughnutctx<?php echo $n; ?> = document.getElementById('myChartdoughnut<?php echo $n; ?>').getContext('2d');
                        const myChartdoughnut<?php echo $n; ?> = new Chart(
                            myChartdoughnutctx<?php echo $n; ?>,
                            myChartdoughnutconfig<?php echo $n; ?>
                                                );

                        
                                            
                                                
                        
                        </script>

                        </page>













<?php

$n++;
    
// $date->modify("-1 month");
$time = strtotime($date1);
$date1 = date("Y-m-d", strtotime("-1 month", $time));
    }
    ?>
 
