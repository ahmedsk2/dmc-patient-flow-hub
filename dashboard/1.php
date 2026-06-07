<?php
require_once __DIR__ . '/../guard.php'; require_login();

require_once '../dbconnect.php';

function fetchDailyOverview($mysqli) {
    // PERF: the original ran 31 full-table scans (one prepared SUM(CASE...) per day over all
    // ~14.8k rows). Replace with two GROUP BY queries over a 31-day range (uses the ADMDATE /
    // DISDATE indexes), then assemble the series in PHP. Same daily counts, ~instant.
    $start = new DateTime();
    $start->modify('-30 day');
    $startStr = $start->format('Y-m-d');
    $endStr   = (new DateTime())->format('Y-m-d');

    $byDay = function ($col) use ($mysqli, $startStr, $endStr) {
        $out = [];
        $sql = "SELECT $col AS d, COUNT(*) AS c FROM picupatients
                WHERE $col BETWEEN ? AND ? AND (current_location != 'ICU' OR current_location IS NULL)
                GROUP BY $col";
        if ($stmt = $mysqli->prepare($sql)) {
            $stmt->bind_param("ss", $startStr, $endStr);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) { $out[$row['d']] = (int) $row['c']; }
            $stmt->close();
        }
        return $out;
    };
    $admByDay = $byDay('ADMDATE'); // $col is a fixed literal, not user input
    $disByDay = $byDay('DISDATE');

    $dates = [];
    $admissions = [];
    $discharges = [];
    $cur = clone $start;
    for ($n = 0; $n < 31; $n++) {
        $d = $cur->format('Y-m-d');
        $dates[]      = $d;
        $admissions[] = $admByDay[$d] ?? 0;
        $discharges[] = $disByDay[$d] ?? 0;
        $cur->modify('+1 day');
    }
    return ['dates' => $dates, 'admissions' => $admissions, 'discharges' => $discharges];
}

function fetchCurrentPatients($mysqli) {
    // Non-ICU, currently-admitted census by specialty / long-term. The previous version
    // LEFT JOINed tb_list via JSON_CONTAINS, which DUPLICATED a patient row for every matching
    // TB code and inflated all four counts. Count the specialty/long-term buckets without that
    // join (one row per patient), and count TB as DISTINCT patients separately.
    $query = "
        SELECT
            SUM(CASE WHEN m.specialty_id = '1' AND (p.longterm IS NULL OR p.longterm = '') THEN 1 ELSE 0 END) as under_hospitalist,
            SUM(CASE WHEN m.specialty_id != '1' AND (p.longterm IS NULL OR p.longterm = '') THEN 1 ELSE 0 END) as under_subs,
            SUM(CASE WHEN p.longterm = 'longterm' THEN 1 ELSE 0 END) as longterm
        FROM members m
        JOIN picupatients p ON m.member_id = p.consultant_id
        WHERE p.DISDATE IS NULL AND (p.current_location != 'ICU' OR p.current_location IS NULL)
    ";
    $result = $mysqli->query($query);
    $row = $result->fetch_assoc();

    // Distinct assigned, non-ICU, currently-admitted patients with >= 1 TB diagnosis.
    $tb = $mysqli->query("
        SELECT COUNT(DISTINCT p.ID) AS c
        FROM members m
        JOIN picupatients p ON m.member_id = p.consultant_id
        WHERE p.DISDATE IS NULL AND (p.current_location != 'ICU' OR p.current_location IS NULL)
          AND EXISTS (SELECT 1 FROM tb_list t WHERE JSON_CONTAINS(p.admissiondiagnosis, JSON_QUOTE(t.dx_id)))
    ");
    $row['tuber_count'] = $tb ? (int) ($tb->fetch_assoc()['c'] ?? 0) : 0;
    return $row;
}

function fetchConsultantsData($mysqli) {
    $query = "
        SELECT 
            m.full_name,
            SUM(CASE WHEN p.ADMDATE + INTERVAL 1 DAY >= CURDATE() AND (p.current_location != 'ICU' OR p.current_location IS NULL) THEN 1 ELSE 0 END) AS admitted_count,
            SUM(CASE WHEN p.DISDATE + INTERVAL 1 DAY >= CURDATE() AND (p.current_location != 'ICU' OR p.current_location IS NULL) THEN 1 ELSE 0 END) AS discharged_count
        FROM 
            members m
        JOIN 
            picupatients p 
        ON 
            m.member_id = p.consultant_id
        WHERE 
            m.position = '3' AND m.active = 1
        GROUP BY 
            m.full_name;
    ";

    $result = $mysqli->query($query);
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    return $data;
}

function fetchConsultationsData($mysqli) {
    $query = "
        SELECT 
            SUM(CASE WHEN signoff_date + INTERVAL 1 DAY >= CURDATE() THEN 1 ELSE 0 END) as signedoff,
            SUM(CASE WHEN signoff_date IS NULL THEN 1 ELSE 0 END) as active
        FROM consultations
    ";

    $result = $mysqli->query($query);
    return $result->fetch_assoc();
}
?>

<div class="card">
  <div class="card-header">
    <h3 class="card-title"><i class="nav-icon fas fa-tachometer-alt text-info"></i> 30 Days Overview</h3>
    <button style="float: right;" id="btn-Preview-Image" type="button" class="fas fa-download"></button>
  </div>
  <div class="card-body">
    <div class="row">
      <div id="testttt" class="col-sm-12">
        <div class="chart-container" style="position: relative; height:40vh">
          <canvas style="background: white" id="mymonthlyChart"></canvas>
        </div>
        <?php
        $overviewData = fetchDailyOverview($mysqli);
        $dates = $overviewData['dates'];
        $admissions = $overviewData['admissions'];
        $discharges = $overviewData['discharges'];
        ?>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <h3 class="card-title"><i class="nav-icon fas fa-tachometer-alt text-info"></i> Daily Overview</h3>
    <button style="float: right;" id="btn-Preview-Image1" type="button" class="fas fa-download"></button>
  </div>
  <div class="card-body">
    <div class="row" style="background: white" id="dailygraphs">
      <div class="col-sm-3">
        <canvas id="myChartdoughnut"></canvas>
        <?php
        $currentPatients = fetchCurrentPatients($mysqli);
        $under_hospitalist = $currentPatients['under_hospitalist'];
        $under_subs = $currentPatients['under_subs'];
        $longterm = $currentPatients['longterm'];
        $tuber_count = $currentPatients['tuber_count'];
        $all_counts = [$under_hospitalist, $under_subs, $longterm];
        $label1 = ["Hospitalist", "Sub Speciality", "Long Term"];
        $totalps = $under_hospitalist + $under_subs + $longterm;
        $title1 = "Current Patients: " . $totalps . " Including " . $tuber_count . " TB Patients";
        ?>
      </div>
      <div class="col-sm-6">
        <div class="chart-container" style="position: relative; height:30vh">
          <canvas id="myChart"></canvas>
        </div>
        <?php
        $consultantsData = fetchConsultantsData($mysqli);
        $label_chart2 = [];
        $admissions_chart2 = [];
        $discharges_chart2 = [];
        foreach ($consultantsData as $data) {
            $label_chart2[] = $data['full_name'];
            $admissions_chart2[] = $data['admitted_count'];
            $discharges_chart2[] = $data['discharged_count'];
        }
        ?>
      </div>
      <div class="col-sm-3">
        <canvas id="myChartdoughnut1"></canvas>
        <?php
        $consultationsData = fetchConsultationsData($mysqli);
        $all_counts2 = [$consultationsData['signedoff'], $consultationsData['active']];
        $label2 = ["Signed Off", "Active Consultations"];
        ?>
      </div>
    </div>
  </div>
</div>

<script>
var label = <?php echo json_encode($dates); ?>;
var admissions = <?php echo json_encode($admissions); ?>;
var discharges = <?php echo json_encode($discharges); ?>;
const colors = ['red', 'blue', 'yellow', 'green'];
const weekend = [];
const mlabels = label;

const mdata = {
  labels: mlabels,
  datasets: [{
    label: 'Admissions',
    backgroundColor: 'rgba(41, 134, 204, 0.3)',
    borderColor: 'rgba(41, 134, 204, 0.6)',
    data: admissions,
    fill: true
  },
  {
    label: 'Discharges',
    backgroundColor: 'rgba(204, 41, 134, 0.3)',
    borderColor: 'rgba(204, 41, 134, 0.6)',
    data: discharges,
    fill: true
  }]
};

const mconfig = {
  type: 'line',
  data: mdata,
  options: {
    maintainAspectRatio: false,
    plugins: {
      filler: {
        propagate: false,
      },
      title: {
        display: true,
        text: 'Daily Admission and Discharge'
      }
    },
    pointBackgroundColor: '#fff',
    radius: 8,
    interaction: {
      intersect: false,
    },
    scales: {
      x: {
        ticks: {
          callback: function (value, index, ticks) {
            var day = new Date(this.getLabelForValue(value));
            if (day.getDay() == 6 || day.getDay() == 5) {
              weekend.push(value);
            }
            return this.getLabelForValue(value);
          },
          color: (c) => {
            if (weekend.includes(c.index)) {
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
</script>

<script>
var label1 = <?php echo json_encode($label1); ?>;
var all_counts = <?php echo json_encode($all_counts); ?>;

const data1 = {
  labels: label1,
  datasets: [{
    label: 'Current Active Patient Counts',
    backgroundColor: [
      'rgb(255, 99, 132)',
      'rgb(54, 162, 235)',
      'rgb(255, 205, 86)',
      'rgb(36, 169, 115)'
    ],
    data: all_counts,
  }]
};

const config1 = {
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
        text: '<?php echo $title1; ?>',
      }
    }
  },
};

const myChartdoughnut = new Chart(
  document.getElementById('myChartdoughnut'),
  config1
);
</script>

<script>
var label = <?php echo json_encode($label_chart2); ?>;
var admissions = <?php echo json_encode($admissions_chart2); ?>;
var discharges = <?php echo json_encode($discharges_chart2); ?>;

const data = {
  labels: label,
  datasets: [{
    label: 'Admissions',
    backgroundColor: 'rgb(41, 134, 204)',
    borderColor: 'rgb(41, 134, 204)',
    data: admissions,
  },
  {
    label: 'Discharges',
    backgroundColor: 'rgb(204, 41, 134)',
    borderColor: 'rgb(204, 41, 134)',
    data: discharges,
  }]
};

const config = {
  type: 'bar',
  data: data,
  options: {
    maintainAspectRatio: false,
    plugins: {
      title: {
        display: true,
        text: 'Admission / Discharge per Consultant since last day'
      },
    },
    responsive: true,
    scales: {
      x: {
        stacked: true,
      },
      y: {
        stacked: true
      }
    }
  }
};

const myChart = new Chart(
  document.getElementById('myChart'),
  config
);
</script>

<script>
var label2 = <?php echo json_encode($label2); ?>;
var all_count2 = <?php echo json_encode($all_counts2); ?>;

const data2 = {
  labels: label2,
  datasets: [{
    label: 'Current Active Consultations Counts',
    backgroundColor: [
      'rgb(255, 99, 132)',
      'rgb(54, 162, 235)',
      'rgb(255, 205, 86)',
      'rgb(36, 169, 115)'
    ],
    data: all_count2,
  }]
};

const config2 = {
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
        text: 'Consultations since last day'
      }
    }
  },
};

const myChartdoughnut1 = new Chart(
  document.getElementById('myChartdoughnut1'),
  config2
);
</script>
