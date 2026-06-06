<?php 
session_start();
require_once "authCookieSessionValidate.php";

if(!$isLoggedIn) {
    header("Location: ./");
    exit;
}
require 'dbconnect.php';

$user_id = $_SESSION["member_id"];
$currentMonth = date("m");
$currentYear = date("Y");

// Fetch tuberculosis diagnosis IDs
$tuber_list_result = $mysqli->query("SELECT dx_id FROM tb_list");
$tuber_list = [];
while ($row = $tuber_list_result->fetch_assoc()) {
    $tuber_list[$row['dx_id']] = true;
}

// Fetch all ICD-10 codes and names
$icd10_result = $mysqli->query("SELECT id, name FROM icd10");
$icd10_names = [];
while ($row = $icd10_result->fetch_assoc()) {
    $icd10_names[$row['id']] = $row['name'];
}

// Fetch active patients and consultants
$activepicupatients = $mysqli->query("SELECT * FROM picupatients WHERE DISDATE IS NULL")->fetch_all(MYSQLI_ASSOC);
$consultants = $mysqli->query("SELECT member_id, full_name, active, specialty_id, on_service FROM members WHERE position = '3'")->fetch_all(MYSQLI_ASSOC);

$consultant_count = [];
foreach ($consultants as $consultant) {
    $consultant_count[$consultant['member_id']] = [
        'new' => 0,
        'old' => 0,
        'icu' => 0,
        'ward' => 0,
        'tb1' => 0,
        'activepatients' => 0,
        'active' => $consultant['active'],
        'specialty_id' => $consultant['specialty_id'],
        'on_service' => $consultant['on_service'],
        'name' => $consultant['full_name']
    ];
}

$active_ICU_count = 0;
$all_activepatients = 0;
foreach ($activepicupatients as $patient) {
    $consultant_id = $patient['consultant_id'];
    if (isset($consultant_count[$consultant_id])) {
        $decodedadmissiondx = json_decode($patient['admissiondiagnosis'], true);
        $is_tb_patient = false;
        foreach ($decodedadmissiondx as $dx) {
            if (isset($tuber_list[$dx])) {
                $is_tb_patient = true;
                $consultant_count[$consultant_id]['tb1']++;
                break;
            }
        }
        $consultant_count[$consultant_id][is_null($patient['newassign']) ? 'old' : 'new']++;
        if ($patient['current_location'] == 'ICU') {
            $consultant_count[$consultant_id]['icu']++;
            $active_ICU_count++;
        } else {
            $consultant_count[$consultant_id]['ward']++;
            $all_activepatients++;
        }
        if ($patient['current_location'] != 'ICU' && !$patient['med_DISDATE'] && !$patient['longterm'] && !$is_tb_patient) {
            $consultant_count[$consultant_id]['activepatients']++;
        }
    }
}
// var_dump($consultant_count);

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="x-ua-compatible" content="ie=edge">
  <title>DMC | Dashboard</title>
  <link href="css/css/all.min.css" rel="stylesheet">
  <style>
    .select2-container--default .select2-selection--multiple .select2-selection__rendered li { list-style: none; color: black; }
    .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 15px; }
    .select2-container--default .select2-selection--multiple .select2-selection__choice__remove { cursor: pointer; display: contents; font-weight: bold; margin-right: 2px; }
    .cancelBtn { color: black; }
    input, textarea { text-align: center; background-color: white; border: 1px solid #aaa; border-radius: 4px; cursor: text; }
    label { width: 100%; }
    .no-js #loader { display: none; }
    .js #loader { display: block; position: absolute; left: 100px; top: 0; }
    .se-pre-con { position: fixed; left: 0%; top: 0px; width: 100%; height: 100%; z-index: 100; background: url(dist/img/Preloader_3.gif) center no-repeat #fff; }
  </style>
</head>
<body class="hold-transition sidebar-mini layout-fixed layout-navbar-fixed layout-footer-fixed">
 <div class="se-pre-con"></div>
  <div class="content-header">
    <div class="container-fluid">
      <div class="row mb-2">
        <div class="col-sm-3"></div>
        <div class="col-sm-6" style="text-align: center; display: inline-grid;">
          <h1 class="m-0 text-dark">Active Patients</h1>
          <a href="dashboard.php">Dashboard</a>
        </div>
        <div class="col-sm-3">
          <img style="max-width: 312px; max-height: 67px;" src="dist/img/logo-wt.png">
        </div>
      </div>
    </div>
  </div>
  <section class="content">
    <div class="container-fluid">  
      <div class="row">
        <div id="mypresentersTable" class="col-md-12">
          <div class="card">
            <div class="card-header">
              <h3 class="card-title"><i class="fas fa-user-tie text-info"></i> Patient Count per Consultant</h3>
              <div id="addbtn" class="eachrow" style="float: right;">
                <h3 class="card-title"><i class="fas fa-user-tie text-info"></i> Total Count (non ICU): <?php echo $all_activepatients; ?></h3>
              </div>
            </div>
            <div class="card-body">
              <div class="row table-responsive">
                <table class="col-md-12" style="text-align: center;">
                  <thead style="text-align: center; font-weight: 700;">
                    <tr>
                      <td class="col-md-4">Consultant</td>
                      <td class="col-md-1">Old Patients</td>
                      <td class="col-md-1">New Patients</td>
                      <td class="col-md-6" colspan="4">All Patients</td>
                    </tr>
                    <tr>
                      <td class="col-md-4"></td>
                      <td class="col-md-1"></td>
                      <td class="col-md-1"></td>
                      <td class="col-md-2">Active Patients</td>
                      <td class="col-md-2">Total Ward Patients</td>
                      <td class="col-md-1">ICU Patients</td>
                      <td class="col-md-1">TB Patients</td>
                    </tr>
                  </thead>
                  <?php
                  // on service hospitalist consultant
                  foreach ($consultant_count as $key => $cc) {
                    if ($cc['on_service'] == '1' && $cc['specialty_id'] == '1' && $cc['active'] == '1') {
                      $total = $cc['ward'];
                      echo "
                      <tr class='eachrow'>
                        <td><p>Dr. " . $cc['name'] . "</p></td>
                        <td style='padding: 0px 1%;' class='eachcol'><p>" . $cc['old'] . "</p></td>
                        <td style='padding: 0px 1%;' class='eachcol'><p>" . $cc['new'] . "</p></td>
                        <td style='padding: 0px 1%;' class='eachcol' scope='row'><p>" . $cc['activepatients'] . "</p></td>
                        <td style='padding: 0px 1%;' class='eachcol' scope='row'><p>" . $total . "</p></td>
                        <td style='padding: 0px 1%;' class='eachcol' scope='row'><p>" . $cc['icu'] . "</p></td>
                        <td style='padding: 0px 1%;' class='eachcol' scope='row'><p>" . $cc['tb1'] . "</p></td>
                      </tr>";
                    }
                  }

                  // on service subs consultant
                  foreach ($consultant_count as $key => $cc) {
                    if ($cc['on_service'] == '1' && $cc['specialty_id'] !== '1' && $cc['active'] == '1') {
                      $total = $cc['ward'];
                      echo "
                      <tr class='eachrow'>
                        <td><p>Dr. " . $cc['name'] . "</p></td>
                        <td style='padding: 0px 1%;' class='eachcol'><p>" . $cc['old'] . "</p></td>
                        <td style='padding: 0px 1%;' class='eachcol'><p>" . $cc['new'] . "</p></td>
                        <td style='padding: 0px 1%;' class='eachcol' scope='row'><p>" . $cc['activepatients'] . "</p></td>
                        <td style='padding: 0px 1%;' class='eachcol' scope='row'><p>" . $total . "</p></td>
                        <td style='padding: 0px 1%;' class='eachcol' scope='row'><p>" . $cc['icu'] . "</p></td>
                        <td style='padding: 0px 1%;' class='eachcol' scope='row'><p>" . $cc['tb1'] . "</p></td>
                      </tr>";
                    }
                  }

                  // NOT on service consultant
                  echo "<tr class='eachrow'><td colspan='7'><p style='color: red;font-weight: bold;'> OFF Service: </p></td></tr>";
                  foreach ($consultant_count as $key => $cc) {
                    if ($cc['on_service'] == '0' && $cc['active'] == '1') {
                      $total = $cc['ward'];
                      echo "
                      <tr class='eachrow'>
                        <td><p>Dr. " . $cc['name'] . "</p></td>
                        <td style='padding: 0px 1%;' class='eachcol'><p>" . $cc['old'] . "</p></td>
                        <td style='padding: 0px 1%;' class='eachcol'><p>" . $cc['new'] . "</p></td>
                        <td style='padding: 0px 1%;' class='eachcol' scope='row'><p>" . $cc['activepatients'] . "</p></td>
                        <td style='padding: 0px 1%;' class='eachcol' scope='row'><p>" . $total . "</p></td>
                        <td style='padding: 0px 1%;' class='eachcol' scope='row'><p>" . $cc['icu'] . "</p></td>
                        <td style='padding: 0px 1%;' class='eachcol' scope='row'><p>" . $cc['tb1'] . "</p></td>
                      </tr>";
                    }
                  }
                  ?>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="row">
        <div id="accordion" class="col-md-12">
          <?php
          $admdate = array_column($activepicupatients, 'ADMDATE');
          array_multisort($admdate, SORT_ASC, $activepicupatients);

          $consultantIdMap = array_reduce($activepicupatients, function ($carry, $item) {
              if (!isset($carry[$item['consultant_id']])) {
                  $carry[$item['consultant_id']] = 0;
              }
              $carry[$item['consultant_id']]++;
              return $carry;
          }, []);

          $conditions = [];
          $params = [];
          $types = '';
          foreach ($activepicupatients as $s) {
              $conditions[] = "(ID < ? AND MRN = ? AND DISDATE + INTERVAL 3 DAY >= ?)";
              $params[] = $s['ID'];
              $params[] = $s['MRN'];
              $params[] = $s['ADMDATE'];
              $types .= 'iss';
          }

          $result1 = false;
          if (!empty($conditions)) {
              $formationSQL = "SELECT DISTINCT MRN FROM picupatients WHERE (" . implode(' OR ', $conditions) . ") AND (trans_discharge = 'discharge from ICU' OR trans_discharge = 'discharge from ward' OR trans_discharge IS NULL)";
              $stmt = $mysqli->prepare($formationSQL);
              if ($stmt) {
                  $stmt->bind_param($types, ...$params);
                  $stmt->execute();
                  $result1 = $stmt->get_result();
              }
          }

          $recent = [];
          if ($result1) {
              while ($row = $result1->fetch_assoc()) {
                  $recent[$row['MRN']] = true;
              }
          }

          foreach ($consultants as $consultant) {
              if (empty($consultantIdMap[$consultant['member_id']])) {
                  continue;
              }
          ?>
<div class="card">
  <div class="card-header">
    <h3 class="card-title">
      <a style="font-size: larger; font-weight: 700; color: black;" class="SeeMore2" id="<?php echo $consultant['member_id']; ?>-1" data-bs-toggle="collapse" href="#collapse<?php echo $consultant['member_id']; ?>" aria-expanded="false" aria-controls="collapse<?php echo $consultant['member_id']; ?>" onclick="showfunction('<?php echo $consultant['member_id']; ?>-1')">+</a>
      <a style="color: black;"  data-bs-toggle="collapse" href="#collapse<?php echo $consultant['member_id']; ?>" aria-expanded="false" aria-controls="collapse<?php echo $consultant['member_id']; ?>" onclick="showfunction('<?php echo $consultant['member_id']; ?>-1')"><i class="fas fa-user-tie text-info"></i> Dr. <?php echo $consultant['full_name']; ?> Patient List</a>
    </h3>
    <div id="addbtn" class='eachrow' style='float: right;'></div>
  </div>
  <div class="collapse" id="collapse<?php echo $consultant['member_id']; ?>">
    <div class="card-body">
      <div class="row">
        <?php
foreach ($activepicupatients as $s) {
    if ($s['consultant_id'] == $consultant['member_id']) {
        $decodedadmissiondx = json_decode($s['admissiondiagnosis'], true);
        if (is_array($decodedadmissiondx)) {
            $tb_patient = array_intersect($decodedadmissiondx, array_keys($tuber_list));
        } else {
            $tb_patient = [];
        }

        $today1 = strtotime(date("Y-m-d"));
        $timeDiff = abs($today1 - strtotime($s['ADMDATE']));
        $LOS = $timeDiff / 86400;

        echo "<div class='col-sm-3'><div class='eachrow card'>";
        echo "<div style='display: inline; padding: 2%; text-align: center;' class='eachcol bed card-header' scope='row'>";
        if ($s['newassign'] == '1') {
            echo "<p style='color: red;'><strong>New Patient</strong></p>";
        }
        if (isset($recent[$s['MRN']])) {
            echo "<div style='background: #fd7e14'><strong>Readmission in 72 hours</strong></div>";
        }
        if (!empty($tb_patient)) {
            echo "<div style='background: #01ff70'><strong> TB Patient</strong></div>";
        }
        if ($s['delay'] !== null) {
            echo "<div style='background: gold'><strong> Discharged Still in</strong></div>";
        }
        if (!empty($s['longterm'])) {
            echo "<div style='background: #87503e;color: white;'><strong> Long Term Patient</strong></div>";
        }
        echo "<p class='txtdata' name='bed' placeholder='Bed Number' style='text-align: center;'><strong>In " . $s['current_location'] . " Bed # " . $s['BED'] . "</strong></p></div>";
        echo "<div style='margin: 0% 2%;' class='eachcol mrn'><p class='txtdata' name='mrn' style='text-align: center;'><strong> MRN: </strong>" . $s['MRN'] . "</p></div>";
        echo "<div style='margin: 0% 2%;' class='eachcol name'><label style='margin: 0px; text-align: center;'>Patient Name:</label><p class='txtdata' name='mrn' style='text-align: center;'>" . $s['PNAME'] . "</p></div>";
        if ($s['current_location'] == 'ICU') {
            echo "<div style='margin: 2%; background: royalblue;color: white;' class='eachcol' scope='row'><p style='text-align: center;margin-bottom: 0px;'>ICU patient</p></div>";
        } else {
            if ($LOS < $shortlos) {
                echo "<div style='margin: 0% 2%; background: #d4edda;' class='eachcol LOS' scope='row'>";
            } elseif ($LOS > $longlos) {
                echo "<div style='margin: 0% 2%; background: #f8d7da;' class='eachcol LOS' scope='row'>";
            } elseif ($LOS >= $shortlos) {
                echo "<div style='margin: 0% 2%; background: #fff3cd;' class='eachcol LOS' scope='row'>";
            }
            echo "<p class='txtdata' name='mrn' style='text-align: center;'><strong> Duration of Admission: </strong>" . $LOS . "</p></div>";
        }
        echo "<div style='margin: 0% 2%;' class='eachcol admissiondiagnosis'><label style='margin: 0px; text-align: center;'>Admission Diagnosis:</label><ul style='list-style-position: inside;margin: 1% 0% 5%;'>";
          if (is_array($decodedadmissiondx)) {
              foreach ($decodedadmissiondx as $value) {
                  if (isset($icd10_names[$value])) {
                      echo '<li>' . $icd10_names[$value] . '</li>';
                  }
              }
          }
        echo "</ul></div></div></div>";
    }
}
        ?>
      </div>
    </div>
  </div>
</div>
<?php } ?>
        </div>
      </div>
    </div>
  </section>
  <footer class="main-footer" style="margin: 0px;">
    <strong>Designed by <a href="https://www.Innovia.ai/">Innovia.ai</a>.</strong>
    All rights reserved.
    <div class="float-right d-none d-sm-inline-block">
      <b>Version</b> 2.2
    </div>
  </footer>
<script src="vendor/jquery-3.7.1.min.js"></script>
<script src="vendor/bootstrap-5.3.3-dist/js/bootstrap.bundle.min.js"></script>
<script>
$(window).on('load', function() {
  $(".se-pre-con").fadeOut("slow");
});

document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.collapse').forEach(function (collapseElement) {
    collapseElement.addEventListener('show.bs.collapse', function () {
      var triggerElement = collapseElement.previousElementSibling.querySelector('.SeeMore2');
      if (triggerElement) {
        triggerElement.textContent = '-';
      }
    });
    collapseElement.addEventListener('hide.bs.collapse', function () {
      var triggerElement = collapseElement.previousElementSibling.querySelector('.SeeMore2');
      if (triggerElement) {
        triggerElement.textContent = '+';
      }
    });
  });
});

function showfunction(elementId) {
  var element = document.getElementById(elementId);
  if (!element) return;
  element.classList.toggle('SeeMore2');
  element.innerHTML = element.classList.contains('SeeMore2') ? '+' : '-';
}
</script>
</body>
</html>
