<?php
require_once __DIR__ . '/../guard.php'; require_role([0]);

session_start();
require '../dbconnect.php';
$today = date("Y-m-d");

$time = $_REQUEST['timing2'] ?? 'daily';
$date = $_REQUEST['date2'] ?? date('Y-m-d');

function fetchCounts($mysqli, $table, $dateField, $groupBy, $start, $end, $extraWhere = '1=1') {
    $sql = "SELECT $groupBy AS grp, COUNT(*) AS cnt 
            FROM $table 
            WHERE $dateField BETWEEN ? AND ? AND ($extraWhere) 
            GROUP BY $groupBy 
            ORDER BY $groupBy ASC";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("ss", $start, $end);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[$row['grp']] = (int)$row['cnt'];
    }
    return $data;
}

function fetchReadmissions($mysqli, $groupBy, $start, $end) {
    $sql = "
        WITH admissions AS (
            SELECT ID, MRN, ADMDATE
            FROM picupatients
            WHERE ADMDATE BETWEEN ? AND ?
        ),
        recent_discharges AS (
            SELECT ID, MRN, DISDATE
            FROM picupatients
            WHERE trans_discharge IN ('discharge from ICU', 'discharge from ward')
              AND DISDATE IS NOT NULL
        )
        SELECT $groupBy AS grp, COUNT(DISTINCT a.ID) AS cnt
        FROM admissions a
        JOIN recent_discharges r
          ON a.MRN = r.MRN
         AND r.ID < a.ID
         AND r.DISDATE >= DATE_SUB(a.ADMDATE, INTERVAL 30 DAY)
         AND a.ADMDATE <= DATE_ADD(r.DISDATE, INTERVAL 3 DAY)
        GROUP BY grp
        ORDER BY grp;
    ";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("ss", $start, $end);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[$row['grp']] = (int)$row['cnt'];
    }
    return $data;
}


function printTable($title, $labelMap, $metrics) {
    echo '<div class="table-responsive text-nowrap">';
    echo '<table class="table table-striped">';
    echo '<thead><tr><th scope="col">KPI for ' . $title . '</th>';
    foreach ($labelMap as $label) {
        echo '<th>' . $label . '</th>';
    }
    echo '</tr></thead><tbody>';

    foreach ($metrics as $name => $dataset) {
        echo '<tr><th>' . ucwords(str_replace('_', ' ', $name)) . '</th>';
        foreach (array_keys($labelMap) as $key) {
            echo '<td>' . ($dataset[$key] ?? 0) . '</td>';
        }
        echo '</tr>';
    }

    echo '</tbody></table></div>';
}

if ($time === "daily") {
    $start = date('Y-m-01', strtotime($date));
    $end = date('Y-m-t', strtotime($date));

    $period = new DatePeriod(
        new DateTime($start),
        new DateInterval('P1D'),
        (new DateTime($end))->modify('+1 day')
    );

    $labelMap = [];
    foreach ($period as $dt) {
        $d = $dt->format('Y-m-d');
        $labelMap[$d] = $dt->format('d');
    }

    $metrics = [
        'Admissions'     => fetchCounts($mysqli, 'picupatients', 'ADMDATE', 'ADMDATE', $start, $end, "current_location != 'ICU' OR current_location IS NULL"),
        'Discharges'     => fetchCounts($mysqli, 'picupatients', 'DISDATE', 'DISDATE', $start, $end, "current_location != 'ICU' OR current_location IS NULL"),
        'Trans_To_ICU'          => fetchCounts($mysqli, 'picupatients', 'DISDATE', 'DISDATE', $start, $end, "DISTO = 'Intensive Care (ICU)'"),
        'ICU_Mortality'   => fetchCounts($mysqli, 'picupatients', 'DISDATE', 'DISDATE', $start, $end, "current_location = 'ICU' AND MORTALITY = 'Dead'"),
        'Out_ICU_Mortality'   => fetchCounts($mysqli, 'picupatients', 'DISDATE', 'DISDATE', $start, $end, "(current_location != 'ICU' OR current_location IS NULL) AND MORTALITY = 'Dead'"),
        'Consultations'       => fetchCounts($mysqli, 'consultations', 'consultation_date', 'consultation_date', $start, $end),
        'Sign_Offs'      => fetchCounts($mysqli, 'consultations', 'signoff_date', 'signoff_date', $start, $end),
        '72hr_Readmissions'   => fetchReadmissions($mysqli, 'ADMDATE', $start, $end)
    ];

    printTable(date('F Y', strtotime($date)), $labelMap, $metrics);
}

elseif ($time === "monthly") {
    $year = date('Y', strtotime($date));
    $labels = range(1, 12);
    $labelMap = array_combine($labels, array_map(fn($m) => date('F', mktime(0, 0, 0, $m, 10)), $labels));
    $start = "$year-01-01";
    $end = "$year-12-31";

    $metrics = [
        'Admissions'     => fetchCounts($mysqli, 'picupatients', 'ADMDATE', 'MONTH(ADMDATE)', $start, $end, "current_location != 'ICU' OR current_location IS NULL"),
        'Discharges'     => fetchCounts($mysqli, 'picupatients', 'DISDATE', 'MONTH(DISDATE)', $start, $end, "current_location != 'ICU' OR current_location IS NULL"),
        'Trans_To_ICU'          => fetchCounts($mysqli, 'picupatients', 'DISDATE', 'MONTH(DISDATE)', $start, $end, "DISTO = 'Intensive Care (ICU)'"),
        'ICU_Mortality'   => fetchCounts($mysqli, 'picupatients', 'DISDATE', 'MONTH(DISDATE)', $start, $end, "current_location = 'ICU' AND MORTALITY = 'Dead'"),
        'Out_ICU_Mortality'   => fetchCounts($mysqli, 'picupatients', 'DISDATE', 'MONTH(DISDATE)', $start, $end, "(current_location != 'ICU' OR current_location IS NULL) AND MORTALITY = 'Dead'"),
        'Consultations'       => fetchCounts($mysqli, 'consultations', 'consultation_date', 'MONTH(consultation_date)', $start, $end),
        'Sign_Offs'      => fetchCounts($mysqli, 'consultations', 'signoff_date', 'MONTH(signoff_date)', $start, $end),
        '72hr_Readmissions'   => fetchReadmissions($mysqli, 'MONTH(ADMDATE)', $start, $end)
    ];

    printTable($year, $labelMap, $metrics);
}

elseif ($time === "quarterly") {
    $year = date('Y', strtotime($date));
    $labelMap = [1 => 'First Quarter', 2 => 'Second Quarter', 3 => 'Third Quarter', 4 => 'Fourth Quarter'];
    $start = "$year-01-01";
    $end = "$year-12-31";

    $metrics = [
        'Admissions'     => fetchCounts($mysqli, 'picupatients', 'ADMDATE', 'QUARTER(ADMDATE)', $start, $end, "current_location != 'ICU' OR current_location IS NULL"),
        'Discharges'     => fetchCounts($mysqli, 'picupatients', 'DISDATE', 'QUARTER(DISDATE)', $start, $end, "current_location != 'ICU' OR current_location IS NULL"),
        'Trans_To_ICU'          => fetchCounts($mysqli, 'picupatients', 'DISDATE', 'QUARTER(DISDATE)', $start, $end, "DISTO = 'Intensive Care (ICU)'"),
        'ICU_Mortality'   => fetchCounts($mysqli, 'picupatients', 'DISDATE', 'QUARTER(DISDATE)', $start, $end, "current_location = 'ICU' AND MORTALITY = 'Dead'"),
        'Out_ICU_Mortality'   => fetchCounts($mysqli, 'picupatients', 'DISDATE', 'QUARTER(DISDATE)', $start, $end, "(current_location != 'ICU' OR current_location IS NULL) AND MORTALITY = 'Dead'"),
        'Consultations'       => fetchCounts($mysqli, 'consultations', 'consultation_date', 'QUARTER(consultation_date)', $start, $end),
        'Sign_Offs'      => fetchCounts($mysqli, 'consultations', 'signoff_date', 'QUARTER(signoff_date)', $start, $end),
        '72hr_Readmissions'   => fetchReadmissions($mysqli, 'QUARTER(ADMDATE)', $start, $end)
    ];

    printTable($year, $labelMap, $metrics);
}
?>
