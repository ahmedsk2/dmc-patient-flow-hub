<?php
require_once 'sidebar.php';

if (!in_array($user['position'], $access_PICU_control)) {
    echo "
    <div class='content-wrapper'>
        <section class='content'>
            <div class='container-fluid'>  
                <div class='alert alert-danger' role='alert'> You don't have permission to access this page. Contact your manager if you need to.</div>
            </div>
        </section>
    </div>";
    require 'footer.php';
    exit();
}

date_default_timezone_set('Asia/Riyadh');
$today = date("Y-m-d");

$formationSQL = "SELECT * FROM picupatients WHERE DISDATE IS NOT NULL";
$result1 = $mysqli->query($formationSQL);
$activepicupatints = $result1->fetch_all(MYSQLI_ASSOC);

$formationSQL = "SELECT * FROM members WHERE position = '3'";
$result1 = $mysqli->query($formationSQL);
$consultants = $result1->fetch_all(MYSQLI_ASSOC);

$query = "SELECT * FROM settings";
$result1 = $mysqli->query($query);
$settings = $result1->fetch_array(MYSQLI_ASSOC);

$shortlos = $settings['short_los'];
$longlos = $settings['long_los'];

$formationSQL = "SELECT dx_id FROM tb_list";
$result1 = $mysqli->query($formationSQL);
$tb_list1 = $result1->fetch_all(MYSQLI_ASSOC);
$tb_list = array_column($tb_list1, 'dx_id');

$membersSQL = "SELECT * FROM members";
$result1 = $mysqli->query($membersSQL);
$members = [];
while ($row = $result1->fetch_array(MYSQLI_ASSOC)) {
    $members[$row['member_id']] = $row['full_name'];
}

$icd10SQL = "SELECT * FROM icd10";
$result1 = $mysqli->query($icd10SQL);
$icd10 = [];
while ($row = $result1->fetch_array(MYSQLI_ASSOC)) {
    $icd10[$row['id']] = $row['name'];
}

$tb_patient = [];
foreach ($activepicupatints as $s) {
    $decodedadmissiondx = json_decode($s['admissiondiagnosis']);
    if ($decodedadmissiondx) {
        $is_tb_patient = array_intersect($decodedadmissiondx, $tb_list);
    } else {
        $is_tb_patient = [];
    }
    if ($is_tb_patient) {
        $tb_patient[$s['ID']] = $s;
    }
}
?>
<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0 text-dark">TB Registry</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                        <li class="breadcrumb-item active">TB Registry</li>
                    </ol>
                </div>
            </div>
            <div class="row mb-2">
                <div class="col-sm-6">
                    <div style="font-weight: bold;" id="container" class="rs-select2 select--no-search"></div>
                </div>
            </div>
        </div>
    </div>
    <!-- /.content-header -->

    <!-- Main content -->
    <section class="content">
        <div class="container-fluid">
            <div class="row">
                <div id="mypresentersTable" class="col-md-12">
                    <!-- /.info-box -->
                    <?php 
                    foreach ($consultants as $consultant) {
                        $any = 0;
                        foreach ($tb_patient as $s) {
                            if ($s['consultant_id'] == $consultant['member_id']) {
                                $any++;
                            }
                        }
                        if ($any == 0) {
                            continue;
                        }
                    ?>
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-user-tie text-info"></i> Dr. <?php echo $consultant['full_name']; ?> consultations List</h3>
                            <div id="addbtn" class="eachrow" style="float: right;"></div>
                        </div>
                        <!-- /.card-header -->
                        <div class="card-body">
                            <div class="row table-responsive">
                                <table class="col-md-12">
                                    <thead style="text-align: center; font-weight: 700;">
                                        <tr>
                                            <td class="col-md-1">MRN</td>
                                            <td class="col-md-2">Patient Name</td>
                                            <td class="col-md-1">Admission Date</td>
                                            <td class="col-md-1">LOS</td>
                                            <td class="col-md-1">Diagnosis</td>
                                            <td class="col-md-1">Admitted by</td>
                                            <td class="col-md-1">Action by</td>
                                            <td class="col-md-1">Action</td>
                                            <td class="col-md-1">To</td>
                                            <td class="col-md-1">On</td>
                                            <td class="col-md-1">Action</td>
                                        </tr>
                                    </thead>
                                    <?php
                                    foreach ($tb_patient as $s) {
                                        if ($s['consultant_id'] == $consultant['member_id']) {
                                            $decodedadmissiondx = json_decode($s['admissiondiagnosis']);
                                            $today1 = strtotime($today);
                                            $timeDiff = abs($today1 - strtotime($s['ADMDATE']));
                                            $LOS = $timeDiff / 86400;  // 86400 seconds in one day

                                            echo "<tr class='eachrow' id='row" . $s['ID'] . "'>
                                                <td style='padding: 0px 1%; text-align: center' class='eachcol mrn'>
                                                    <p>" . $s['MRN'] . "</p>
                                                </td>
                                                <td style='padding: 0px 1%; text-align: center' class='eachcol name'>
                                                    <p>" . $s['PNAME'] . "</p>
                                                </td>
                                                <td style='padding: 0px 1%; text-align: center' class='eachcol admdate' scope='row'>
                                                    <p>" . $s['ADMDATE'] . "</p>
                                                </td>";

                                            if ($LOS < $shortlos) {
                                                echo "<td style='padding: 0px 1%; text-align: center; background: #d4edda;' class='eachcol admdate' scope='row'>";
                                            } elseif ($LOS > $longlos) {
                                                echo "<td style='padding: 0px 1%; background: #f8d7da; text-align: center' class='eachcol admdate' scope='row'>";
                                            } elseif ($LOS >= $shortlos) {
                                                echo "<td style='padding: 0px 1%; background: #fff3cd; text-align: center' class='eachcol admdate' scope='row'>";
                                            }

                                            echo "<p>" . $LOS . "</p>
                                                </td>
                                                <td style='padding: 0px 1%;' class='eachcol admissiondiagnosis'>
                                                    <ul style='list-style-position: inside; margin: 1% 0% 1%;'>";
                                            
                                            if (is_array($decodedadmissiondx)) {
                                                foreach ($decodedadmissiondx as $value) {
                                                    echo "<li>" . $icd10[$value] . "</li>";
                                                }
                                            }

                                            echo "</ul></td>
                                                <td style='padding: 0px 1%; text-align: center' class='eachcol madmby'>";
                                            
                                            $mem_id = $s['admitted_by'];
                                            if (isset($members[$mem_id])) {
                                                echo "<p>" . $members[$mem_id] . "</p>";
                                            } else {
                                                echo "<p>Unknown member</p>";
                                            }

                                            echo "</td>
                                                <td style='padding: 0px 1%; text-align: center' class='eachcol madmby'>";
                                            
                                            $mem_id = $s['trans_discharge_by'];
                                            if (isset($members[$mem_id])) {
                                                echo "<p>" . $members[$mem_id] . "</p>";
                                            } else {
                                                echo "<p>Unknown member</p>";
                                            }

                                            echo "</td>
                                                <td style='padding: 0px 1%; text-align: center' class='eachcol admdate' scope='row'>
                                                    <p>" . $s['trans_discharge'] . "</p>
                                                </td>
                                                <td style='padding: 0px 1%; text-align: center' class='eachcol admdate' scope='row'>
                                                    <p>" . $s['DISTO'] . "</p>
                                                </td>
                                                <td style='padding: 0px 1%; text-align: center' class='eachcol admdate' scope='row'>
                                                    <p>" . $s['DISDATE'] . "</p>
                                                </td>
                                                <td style='padding: 0px 1%; text-align: center' class='eachcol admdate' scope='row'>
                                                    <form method='post' name='undo' action='registry.php'>
                                                        <input type='hidden' name='patientid' value='" . $s['ID'] . "'>";
                                            
                                            if ($s['DISDATE'] == $today) {
                                                echo "<button type='submit' style='width: 100%;' value='submit' class='btn btn-warning' name='undo_btn'>Undo Discharge</button>";
                                            }

                                            echo "</form>
                                                </td>
                                            </tr>";
                                        }
                                    }
                                    ?> 
                                </table>
                            </div>
                            <!-- /.row -->
                        </div>
                        <!-- /.card-body -->
                    </div>
                    <!-- /.card -->
                    <?php } ?>
                </div>
            </div>
            <!--/. row -->
        </div>
        <!--/. container-fluid -->
    </section>
    <!-- /.content -->
</div>
<!-- /.content-wrapper -->
<?php
require 'footer.php';
?>
