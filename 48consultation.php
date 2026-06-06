<?php
require_once 'sidebar.php';

if (!in_array($user['position'], $access_PICU_control)) {
    echo "
    <div class='content-wrapper'>
        <section class='content'>
            <div class='container-fluid'>  
                <div class='alert alert-danger' role='alert'>You don't have permission to access this page. Contact your manager if you need to.</div>
            </div>
        </section>
    </div>
    ";
    require 'footer.php';
    exit();
}

date_default_timezone_set('Asia/Riyadh');
$today = date("Y-m-d");

if (isset($_POST['undo_btn'])) {
    $consult_id = $_POST['consultid'];
    $query = "UPDATE consultations SET signoff_date= NULL WHERE ID=?";
    $stmt = $mysqli->prepare($query);
    $stmt->bind_param("i", $consult_id);
    if (!$stmt->execute()) {
        echo "Error description: " . $mysqli->error;
    } else {
        echo "<script>window.location.href = '48consultation.php';</script>";
    }
}

$today = date("Y-m-d");

$formationSQL = "SELECT * FROM consultations WHERE signoff_date + INTERVAL 1 DAY >= ?";
$stmt = $mysqli->prepare($formationSQL);
$stmt->bind_param("s", $today);
$stmt->execute();
$result1 = $stmt->get_result();
$olspatints = $result1->fetch_all(MYSQLI_ASSOC);

$formationSQL = "SELECT * FROM members WHERE position = '3'";
$result1 = $mysqli->query($formationSQL);
$consultants = $result1->fetch_all(MYSQLI_ASSOC);

$membersSQL = "SELECT * FROM members";
$result1 = $mysqli->query($membersSQL);
$members = [];
while ($row = $result1->fetch_array(MYSQLI_ASSOC)) {
    $members[$row['member_id']] = $row['full_name'];
}

$specialitiesSQL = "SELECT * FROM speciality";
$result1 = $mysqli->query($specialitiesSQL);
$specialities = [];
while ($row = $result1->fetch_assoc()) {
    $specialities[$row['id']] = $row['specilaity'];
}

$consultationReasonsSQL = "SELECT * FROM consultation_reason";
$result1 = $mysqli->query($consultationReasonsSQL);
$consultationReasons = [];
while ($row = $result1->fetch_assoc()) {
    $consultationReasons[$row['id']] = $row['consultation_reason'];
}

?>
<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0 text-dark">Consultation Registry</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                        <li class="breadcrumb-item active">Consultation Registry</li>
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
                        foreach ($olspatints as $s) {
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
                            <h3 class="card-title"><i class="fas fa-user-tie text-info"></i> Dr. <?php echo htmlspecialchars($consultant['full_name'], ENT_QUOTES, 'UTF-8'); ?> consultations List</h3>
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
                                            <td class="col-md-2">Age</td>
                                            <td class="col-md-1">Indications</td>
                                            <td class="col-md-1">Consultation Date</td>
                                            <td class="col-md-1">Consulted by</td>
                                            <td class="col-md-1">Sign off date</td>
                                            <td class="col-md-1">Entered by</td>
                                            <td class="col-md-1">Action</td>
                                        </tr>
                                    </thead>
                                    <?php
                                    foreach ($olspatints as $s) {
                                        if ($s['consultant_id'] == $consultant['member_id']) {
                                            $decodedIndications = json_decode($s['indication']);
                                            echo "
                                            <tr class='eachrow' id='row".htmlspecialchars($s['id'], ENT_QUOTES, 'UTF-8')."'>
                                                <td style='padding: 0px 1%; text-align: center' class='eachcol mrn'>
                                                    <p>".htmlspecialchars($s['MRN'], ENT_QUOTES, 'UTF-8')."</p>
                                                </td>
                                                <td style='padding: 0px 1%; text-align: center' class='eachcol name'>
                                                    <p>".htmlspecialchars($s['PNAME'], ENT_QUOTES, 'UTF-8')."</p>
                                                </td>
                                                <td style='padding: 0px 1%; text-align: center' class='eachcol AGE' scope='row'>
                                                    <p>".htmlspecialchars($s['age'], ENT_QUOTES, 'UTF-8')."</p>
                                                </td>
                                                <td style='padding: 0px 1%;' class='eachcol indications'>
                                                    <ul style='list-style-position: inside; margin: 1% 0% 1%;'>";
                                            if (is_array($decodedIndications)) {
                                                foreach ($decodedIndications as $value) {
                                                    echo "<li>" . htmlspecialchars($consultationReasons[$value], ENT_QUOTES, 'UTF-8') . "</li>";
                                                }
                                            }
                                            echo "
                                                    </ul>
                                                </td>
                                                <td style='padding: 0px 1%; text-align: center' class='eachcol condate' scope='row'>
                                                    <p>".htmlspecialchars($s['consultation_date'], ENT_QUOTES, 'UTF-8')."</p>
                                                </td>
                                                <td style='padding: 0px 1%; text-align: center' class='eachcol conby' scope='row'>
                                                    <p>".htmlspecialchars($s['consultation_from'], ENT_QUOTES, 'UTF-8')."</p>
                                                </td>
                                                <td style='padding: 0px 1%; text-align: center' class='eachcol singoffdate' scope='row'>
                                                    <p>".htmlspecialchars($s['signoff_date'], ENT_QUOTES, 'UTF-8')."</p>
                                                </td>
                                                <td style='padding: 0px 1%; text-align: center' class='eachcol enteredby' scope='row'>";
                                            $mem_id = $s['entered_by_id'];
                                            if (isset($members[$mem_id])) {
                                                echo "<p>" . htmlspecialchars($members[$mem_id], ENT_QUOTES, 'UTF-8') . "</p>";
                                            } else {
                                                echo "<p>Unknown member</p>";
                                            }
                                            echo "
                                                </td>
                                                <td style='padding: 0px 1%; text-align: center' class='eachcol actionbtn' scope='row'>
                                                    <form method='post' name='undo' action='48consultation.php'>
                                                        <input type='hidden' name='consultid' value='".htmlspecialchars($s['id'], ENT_QUOTES, 'UTF-8')."'>";
                                            if ($s['signoff_date'] == $today) {
                                                echo "<button type='submit' value='submit' class='btn btn-warning' name='undo_btn' onclick='undoconsult(this)'>Undo Signoff</button>";
                                            }
                                            echo "
                                                    </form>
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
<script>

function undoconsult(button) {
    // Disable the button and show loading spinner
    button.disabled = true;
    button.innerHTML = '<div class="spinner-border spinner-border-sm" role="status"><span class="sr-only">Loading...</span></div>';
    // Allow the form to be submitted
    // Create a hidden input with the same name as the button to include it in the form submission
    var hiddenInput = document.createElement('input');
    hiddenInput.type = 'hidden';
    hiddenInput.name = button.name;
    hiddenInput.value = button.value;
    button.form.appendChild(hiddenInput);

    // Allow the form to be submitted
    button.form.submit();
}

</script>