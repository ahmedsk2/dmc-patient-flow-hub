<?php
require_once __DIR__ . '/../guard.php'; require_role([0]);
 
session_start();
date_default_timezone_set('Asia/Riyadh');
$today=date("Y-m-d");

require ('../dbconnect.php');
$time=$_REQUEST['timing1'];
?>
<script src="../vendor/Chart.js/4.4.2/chart.umd.min.js"></script>


<div class="chart-container" style="position: relative; height:40vh">
<canvas id="mymonthlyChart"></canvas>
</div>
<?php
$label=array();
$admissions=array();
$discharges=array();
$newconsults=array();
$signedoff=array();

if ($time == "daily"){
    $title ='Daily Overview';
$date=new DateTime();
$n=0;
while($n < 31){
  $date1 = $date->format('Y-m-d');

  $formationSQL = "SELECT * FROM picupatients WHERE ADMDATE = ? AND (current_location != 'ICU' or current_location is null)";
  $stmt = $mysqli->prepare($formationSQL);
  $stmt->bind_param('s', $date1);
  $stmt->execute();
  $result1 = $stmt->get_result();
  $admittedpcount = mysqli_num_rows($result1);

  $formationSQL = "SELECT * FROM picupatients WHERE DISDATE = ? AND (current_location != 'ICU' or current_location is null)";
  $stmt = $mysqli->prepare($formationSQL);
  $stmt->bind_param('s', $date1);
  $stmt->execute();
  $result1 = $stmt->get_result();
  $dischargedpcount = mysqli_num_rows($result1);

  $formationSQL = "SELECT * FROM consultations WHERE consultation_date = ?";
  $stmt = $mysqli->prepare($formationSQL);
  $stmt->bind_param('s', $date1);
  $stmt->execute();
  $result1 = $stmt->get_result();
  $newconsultscount = mysqli_num_rows($result1);

  $formationSQL = "SELECT * FROM consultations WHERE signoff_date = ?";
  $stmt = $mysqli->prepare($formationSQL);
  $stmt->bind_param('s', $date1);
  $stmt->execute();
  $result1 = $stmt->get_result();
  $signedoffcount = mysqli_num_rows($result1);

  array_push($label,$date1);
  array_push($admissions,$admittedpcount);
  array_push($discharges,$dischargedpcount);
  array_push($newconsults,$newconsultscount);
  array_push($signedoff,$signedoffcount);
$n++;
$date->modify("-1 day");

}
} elseif ($time == "monthly"){
 $title ='Monthly Overview';
    $date=new DateTime();
    $n=0;
    while($n < 12){
      $date1 = $date->format('Y-m-d');
      $mdate1=date("m",strtotime($date1));
      $ydate1=date("Y",strtotime($date1));
      $dateObj   = DateTime::createFromFormat('!m', $mdate1);
      $monthName = $dateObj->format('F'); // March

      // Sargable half-open month range [first-of-month, first-of-next-month) so the indexes on
      // ADMDATE/DISDATE are usable (was MONTH()/YEAR() wrapping, which defeats them). Proven
      // result-identical to the old MONTH+YEAR predicate on DATE columns.
      $rangeStart = sprintf('%04d-%02d-01', $ydate1, $mdate1);
      $rangeEnd   = date('Y-m-01', strtotime($rangeStart . ' +1 month'));

      $formationSQL = "SELECT * FROM picupatients WHERE ADMDATE >= ? AND ADMDATE < ? AND (current_location != 'ICU' or current_location is null)";
      $stmt = $mysqli->prepare($formationSQL);
      $stmt->bind_param('ss', $rangeStart, $rangeEnd);
      $stmt->execute();
      $result1 = $stmt->get_result();
      $admittedpcount = mysqli_num_rows($result1);

      $formationSQL = "SELECT * FROM picupatients WHERE DISDATE >= ? AND DISDATE < ? AND (current_location != 'ICU' or current_location is null)";
      $stmt = $mysqli->prepare($formationSQL);
      $stmt->bind_param('ss', $rangeStart, $rangeEnd);
      $stmt->execute();
      $result1 = $stmt->get_result();
      $dischargedpcount = mysqli_num_rows($result1);

      // Consultations were previously MONTH-only (no year) -> they counted that month across ALL
      // years. Now scoped to the same month+year as the admissions/discharges series (cross-year
      // fix) and sargable.
      $formationSQL = "SELECT * FROM consultations WHERE consultation_date >= ? AND consultation_date < ?";
      $stmt = $mysqli->prepare($formationSQL);
      $stmt->bind_param('ss', $rangeStart, $rangeEnd);
      $stmt->execute();
      $result1 = $stmt->get_result();
      $newconsultscount = mysqli_num_rows($result1);

      $formationSQL = "SELECT * FROM consultations WHERE signoff_date >= ? AND signoff_date < ?";
      $stmt = $mysqli->prepare($formationSQL);
      $stmt->bind_param('ss', $rangeStart, $rangeEnd);
      $stmt->execute();
      $result1 = $stmt->get_result();
      $signedoffcount = mysqli_num_rows($result1);
    // var_dump($admittedpcount);
      array_push($label,$monthName);
      array_push($admissions,$admittedpcount);
      array_push($discharges,$dischargedpcount);
      array_push($newconsults,$newconsultscount);
      array_push($signedoff,$signedoffcount);
    $n++;
    $date->modify("-1 month");
    
    }
    
    
}  elseif ($time == "quarterly"){
    $title ='Quarterly Overview';
       $date=new DateTime();
       $n=0;
       $quarter=4;
       while($n < 4){
        $date1 = $date->format('Y-m-d');
        $ydate1=date("Y",strtotime($date1));

         // Sargable half-open quarter range [quarter-start, next-quarter-start). $quarter is 4..1.
         $qStart = sprintf('%04d-%02d-01', $ydate1, ($quarter - 1) * 3 + 1);
         $qEnd   = date('Y-m-01', strtotime($qStart . ' +3 month'));

         $formationSQL = "SELECT * FROM picupatients WHERE ADMDATE >= ? AND ADMDATE < ? AND (current_location != 'ICU' or current_location is null)";
         $stmt = $mysqli->prepare($formationSQL);
         $stmt->bind_param('ss', $qStart, $qEnd);
         $stmt->execute();
         $result1 = $stmt->get_result();
         $admittedpcount = mysqli_num_rows($result1);

         $formationSQL = "SELECT * FROM picupatients WHERE DISDATE >= ? AND DISDATE < ? AND (current_location != 'ICU' or current_location is null)";
         $stmt = $mysqli->prepare($formationSQL);
         $stmt->bind_param('ss', $qStart, $qEnd);
         $stmt->execute();
         $result1 = $stmt->get_result();
         $dischargedpcount = mysqli_num_rows($result1);

         // Consultations were QUARTER-only (no year) -> scoped to the displayed year now (cross-year fix) + sargable.
         $formationSQL = "SELECT * FROM consultations WHERE consultation_date >= ? AND consultation_date < ?";
         $stmt = $mysqli->prepare($formationSQL);
         $stmt->bind_param('ss', $qStart, $qEnd);
         $stmt->execute();
         $result1 = $stmt->get_result();
         $newconsultscount = mysqli_num_rows($result1);

         $formationSQL = "SELECT * FROM consultations WHERE signoff_date >= ? AND signoff_date < ?";
         $stmt = $mysqli->prepare($formationSQL);
         $stmt->bind_param('ss', $qStart, $qEnd);
         $stmt->execute();
         $result1 = $stmt->get_result();
         $signedoffcount = mysqli_num_rows($result1);
       
        //  array_push($label,$quarter);
         array_push($admissions,$admittedpcount);
         array_push($discharges,$dischargedpcount);
         array_push($newconsults,$newconsultscount);
         array_push($signedoff,$signedoffcount);
       $n++;
       $quarter=$quarter-1;
       
       }
       $label=['Forth Quarter','Third Quarter', 'Second Quarter', 'First Quarter']  ;
    //    array_merge($label,$quarter_label);
   } 


$label=array_reverse($label);
$admissions=array_reverse($admissions);
$discharges=array_reverse($discharges);
$newconsults=array_reverse($newconsults);
$signedoff=array_reverse($signedoff);




?>
  <script>
  (function(){
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
  const colors = ['red', 'blue', 'yellow', 'green'];
  const weekend =[];
  const mconfig = {
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
        text: '<?php echo $title; ?>'
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
                    var day =new Date(this.getLabelForValue(value));
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


  const mymonthlyChart = new Chart(
    document.getElementById('mymonthlyChart'),
    mconfig
  );
 })();
</script>
