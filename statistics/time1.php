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

// Grouped-SQL engine: run ONE "COUNT(*) ... GROUP BY <bucket>" query per metric over the whole
// window, then look up each period's count from a map — instead of one query per period
// (was up to 31x4=124 daily / 12x4=48 monthly / 4x4=16 quarterly separate queries).
// The predicates are unchanged, so the per-period counts are identical (proven byte-for-byte
// by tools/stats_validate.php: capture before -> this rewrite -> capture after -> compare).
$icuFilter = " AND (current_location != 'ICU' or current_location is null)";
$groupCount = function ($table, $col, $bucketExpr, $start, $end, $extra) use ($mysqli) {
    $sql = "SELECT $bucketExpr AS b, COUNT(*) AS c FROM $table WHERE $col >= ? AND $col < ?$extra GROUP BY b";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $r = $stmt->get_result();
    $m = [];
    while ($row = $r->fetch_assoc()) { $m[(string) $row['b']] = (int) $row['c']; }
    return $m;
};

if ($time == "daily"){
    $title ='Daily Overview';
    // 31 days: today back to today-30 (same as the old loop). Bucket = the date itself.
    $days = [];
    $d = new DateTime();
    for ($n = 0; $n < 31; $n++) { $days[] = $d->format('Y-m-d'); $d->modify('-1 day'); }
    $winStart = end($days);                                            // earliest day
    $winEnd   = date('Y-m-d', strtotime(reset($days) . ' +1 day'));    // day after the latest (half-open)

    $admMap = $groupCount('picupatients', 'ADMDATE', 'ADMDATE', $winStart, $winEnd, $icuFilter);
    $disMap = $groupCount('picupatients', 'DISDATE', 'DISDATE', $winStart, $winEnd, $icuFilter);
    $conMap = $groupCount('consultations', 'consultation_date', 'consultation_date', $winStart, $winEnd, '');
    $sgnMap = $groupCount('consultations', 'signoff_date', 'signoff_date', $winStart, $winEnd, '');

    foreach ($days as $date1) {
        array_push($label, $date1);
        array_push($admissions, $admMap[$date1] ?? 0);
        array_push($discharges, $disMap[$date1] ?? 0);
        array_push($newconsults, $conMap[$date1] ?? 0);
        array_push($signedoff, $sgnMap[$date1] ?? 0);
    }
} elseif ($time == "monthly"){
    $title ='Monthly Overview';
    // 12 months ending this month (same loop as before). Bucket = calendar month (Y-m).
    $months = [];
    $d = new DateTime();
    for ($n = 0; $n < 12; $n++) {
        $months[] = [
            'key'   => $d->format('Y-m'),
            'name'  => DateTime::createFromFormat('!m', $d->format('m'))->format('F'),
            'start' => $d->format('Y-m-01'),
        ];
        $d->modify('-1 month');
    }
    $starts   = array_column($months, 'start');
    $winStart = min($starts);                                          // earliest month start
    $winEnd   = date('Y-m-01', strtotime(max($starts) . ' +1 month')); // first of month after the latest

    $admMap = $groupCount('picupatients', 'ADMDATE', "DATE_FORMAT(ADMDATE,'%Y-%m')", $winStart, $winEnd, $icuFilter);
    $disMap = $groupCount('picupatients', 'DISDATE', "DATE_FORMAT(DISDATE,'%Y-%m')", $winStart, $winEnd, $icuFilter);
    $conMap = $groupCount('consultations', 'consultation_date', "DATE_FORMAT(consultation_date,'%Y-%m')", $winStart, $winEnd, '');
    $sgnMap = $groupCount('consultations', 'signoff_date', "DATE_FORMAT(signoff_date,'%Y-%m')", $winStart, $winEnd, '');

    foreach ($months as $mo) {
        array_push($label, $mo['name']);
        array_push($admissions, $admMap[$mo['key']] ?? 0);
        array_push($discharges, $disMap[$mo['key']] ?? 0);
        array_push($newconsults, $conMap[$mo['key']] ?? 0);
        array_push($signedoff, $sgnMap[$mo['key']] ?? 0);
    }
}  elseif ($time == "quarterly"){
    $title ='Quarterly Overview';
    // The four quarters of the CURRENT year (the old loop did NOT advance $date, so $ydate1 stayed
    // the current year; quarter ran 4,3,2,1). Bucket = YEAR-QUARTER over [Jan 1, next Jan 1).
    $y        = (int) (new DateTime())->format('Y');
    $winStart = sprintf('%04d-01-01', $y);
    $winEnd   = sprintf('%04d-01-01', $y + 1);

    $admMap = $groupCount('picupatients', 'ADMDATE', "CONCAT(YEAR(ADMDATE),'-',QUARTER(ADMDATE))", $winStart, $winEnd, $icuFilter);
    $disMap = $groupCount('picupatients', 'DISDATE', "CONCAT(YEAR(DISDATE),'-',QUARTER(DISDATE))", $winStart, $winEnd, $icuFilter);
    $conMap = $groupCount('consultations', 'consultation_date', "CONCAT(YEAR(consultation_date),'-',QUARTER(consultation_date))", $winStart, $winEnd, '');
    $sgnMap = $groupCount('consultations', 'signoff_date', "CONCAT(YEAR(signoff_date),'-',QUARTER(signoff_date))", $winStart, $winEnd, '');

    $quarter = 4;
    for ($n = 0; $n < 4; $n++) {
        $key = $y . '-' . $quarter;
        array_push($admissions, $admMap[$key] ?? 0);
        array_push($discharges, $disMap[$key] ?? 0);
        array_push($newconsults, $conMap[$key] ?? 0);
        array_push($signedoff, $sgnMap[$key] ?? 0);
        $quarter = $quarter - 1;
    }
    $label=['Forth Quarter','Third Quarter', 'Second Quarter', 'First Quarter']  ;
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
