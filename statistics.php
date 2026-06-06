<?php
require_once 'sidebar.php';


    if (!in_array($user['position'],$access_PICU_control)){
    
        echo "
        <div class='content-wrapper'>
        
      
        <section class='content'>
        <div class='container-fluid'>  
        <div class='alert alert-danger' role='alert'> you dont have permission to access this page, Contact you manager if you need to.
        </div>
        </div>
        </section>
        </div>
        ";
        require 'footer.php';
    
        exit();
      }

   		
       $formationSQL = "SELECT * FROM picupatients";
       $result1 = $mysqli->query($formationSQL);
       $patints = $result1 -> fetch_all(MYSQLI_ASSOC);
   
       $formationSQL = "SELECT * FROM members WHERE position = '3'";
       $result1 = $mysqli->query($formationSQL);
       $consultants = $result1 -> fetch_all(MYSQLI_ASSOC);

       $query = "select * from settings";
       $result1 = $mysqli->query($query);
       $settings = $result1 -> fetch_array(MYSQLI_ASSOC);

       $shortlos=$settings['short_los'];
       $longlos=$settings['long_los'];
     ?>
   
   <style>
/* loader on search */
.se-pre-con1 {
	text-align: center;
    display: none;
}
.se-pre-con2 {
	text-align: center;
    display: none;
}
</style>
  <!-- Content Wrapper. Contains page content -->
  <div class="content-wrapper">
  
    <!-- Content Header (Page header) -->
    <div class="content-header">
      <div class="container-fluid">
        <div class="row mb-2">
          <div class="col-sm-6">
            <h1 class="m-0 text-dark">DMC Internal Medicine Statistics</h1>
          </div><!-- /.col -->
          <div class="col-sm-6">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
              <li class="breadcrumb-item active">Statistics</li>
            </ol>
          </div><!-- /.col -->
        </div><!-- /.row -->

      </div><!-- /.container-fluid -->
    </div>
    <!-- /.content-header -->

    <!-- Main content -->
    <section class="content">
      <div class="container-fluid">  
      <div class="card">
  <div  class="card-header">
    <h3 class="card-title"><i class="nav-icon fas fa-tachometer-alt text-info"></i> Select KPI</h3>
  </div>
  <!-- /.card-header -->
  <div class="card-body">
  <div class="row">
<div class="col-sm-3">

  <label style="width: auto;">KPI:</label>
                    <select class='txtdata1 select2' id ="kpi"  name='kpi' style=" width: fit-content; text-align: center;padding: 4px;">
                    <option selected value='admission'>Admissions / Consultations</option>
                    <option value='los'>Length of Stay</option>
                    <option value='readmission'>Readmissions</option>
                    </select> 
</div>
<div class="col-sm-3">
                    <label style="width: auto;">Date:</label>
                    <input autocomplete="off" class='txtdata1' id ="date1"  data-date-format="DD-MM-YYYY" type="text"  name='date1' style="text-align: center;padding: 0px;width: auto;" required>

                    </div>
<div class="col-sm-6">
                    <div style="display: flex;">
                    <label class="container">
  <input  class="interval1 txtdata1" type="radio" style="width: auto;"  value="daily" name="interval1" id="interval1" required>
  Daily
</label>
<label class="container">
  <input  class="interval1 txtdata1" type="radio" style="width: auto;"  value="monthly" name="interval1" id="interval1" required>
  Monthly
</label>

<label class="container">
<input  class="interval1 txtdata1" type="radio" style="width: auto;"  value="quarterly" name="interval1" id="interval1" checked="checked">
Quarterly
</label>
                    </div>
</div>

  </div>
  </div>
  <div class="se-pre-con2"><img src="dist/img/Preloader_3.gif"></div>

      </div>

<div id='charts1'></div>

      <div class="card">
  <div  class="card-header">
    <h3 class="card-title"><i class="nav-icon fas fa-tachometer-alt text-info"></i> Select Physician</h3>
  </div>
  <!-- /.card-header -->
  <div class="card-body">
  <div class="row">
<div class="col-sm-3">

  <label style="width: auto;">Select Consultant:</label>
                    <select class='txtdata select2' id ="consultant"  name='consultant' style=" width: fit-content; text-align: center;padding: 4px;">
                    <!-- <option selected disabled value=''>Select</option> -->
                        <?php
                        
                            foreach ($consultants as $key =>$c){
                            

                                echo  "<option value=".$c['member_id'].">".$c['full_name']."</option>";
                            }
                        ?>
                    </select> 
</div>
<div class="col-sm-3">
                    <label style="width: auto;">Date:</label>
                    <input autocomplete="off" class='txtdata' id ="date"  data-date-format="DD-MM-YYYY" type="text"  name='date' style="text-align: center;padding: 0px;width: auto;" required>

                    </div>
<div class="col-sm-6">
                    <div style="display: flex;">
                    <label class="container">
  <input  class="interval txtdata" type="radio" style="width: auto;"  value="daily" name="interval" id="interval" required>
  Daily
</label>
<label class="container">
  <input  class="interval txtdata" type="radio" style="width: auto;"  value="monthly" name="interval" id="interval" required>
  Monthly
</label>

<label class="container">
<input  class="interval txtdata" type="radio" style="width: auto;"  value="quarterly" name="interval" id="interval" checked="checked">
Quarterly
</label>
                    </div>
</div>

  </div>
  </div>
  <div class="se-pre-con1"><img src="dist/img/Preloader_3.gif"></div>
      </div>

<div id='charts'></div>

</div><!--/. container-fluid -->

            

 </section>
    <!-- /.content -->
    </div>  


 
  <!-- /.content-wrapper -->

<?php
require 'footer.php';
?>

  <!-- Select2 JS -->
  <script src="vendor/select2/4.0.13/select2.min.js"></script>
  <!-- Daterangepicker JS -->
  <script src="vendor/moments.js/2.30.1/moment.min.js"></script>
  <script src="vendor/daterangepicker/3.1.0/daterangepicker.min.js"></script>

<script>
    
    $(document).ready(function() {
        $('.select2').select2({

            placeholder: 'Select',
            dropdownAutoWidth : true,
   
      
    } );

      });
</script>

<script>

$(document).ready(function($) {
  
  $('.txtdata1').on('change', function(){
    $(".se-pre-con2").show();
    var parent = document.getElementById('charts1');
    kpi=document.getElementById('kpi').value;
    date=document.getElementById('date1').value;
    interval=document.querySelector('input[name=interval1]:checked').value;
console.log(interval);
    data = {kpi: kpi, date:date,interval:interval};
    $.post('statistics/charts1.php', data, function(data){
        $(parent).html(data);
        $(".se-pre-con2").hide();
    });
    

  });
});


document.getElementById('date1').value = "<?php echo date("Y-m-d"); ?>";

$(function() {
  $('input[name="date1"]').daterangepicker({
    singleDatePicker: true,
    timePicker: false,
    timePicker24Hour: false,
    autoUpdateInput: false,
    showButtonPanel: false,
    autoApply: true,
    showDropdowns: true,
    
    minYear: 2010,
    maxYear: parseInt(moment().format('YYYY'),10),
    locale: {
            format: 'YYYY-MM-DD'
        }
  }, ).on("apply.daterangepicker", function (e, picker) {
    $(".se-pre-con2").show();
        picker.element.val(picker.startDate.format(picker.locale.format));
        var parent = document.getElementById('charts1');
    kpi=document.getElementById('kpi').value;
    date=document.getElementById('date1').value;
    interval=document.querySelector('input[name=interval1]:checked').value;
console.log(interval);
    data = {kpi: kpi, date:date,interval:interval};
    $.post('statistics/charts1.php', data, function(data){
        $(parent).html(data);
        $(".se-pre-con2").hide();
    });
    
    });
});

</script>

<script>

$(document).ready(function($) {
  
  $('.txtdata').on('change', function(){
    $(".se-pre-con1").show();
    var parent = document.getElementById('charts');
    consultant=document.getElementById('consultant').value;
    date=document.getElementById('date').value;
    interval=document.querySelector('input[name=interval]:checked').value;
console.log(interval);
    data = {consultant: consultant, date:date,interval:interval};
    $.post('statistics/charts.php', data, function(data){
        $(parent).html(data);
        $(".se-pre-con1").hide();
    });
    

  });
});


document.getElementById('date').value = "<?php echo date("Y-m-d"); ?>";

$(function() {
  $('input[name="date"]').daterangepicker({
    singleDatePicker: true,
    timePicker: false,
    timePicker24Hour: false,
    autoUpdateInput: false,
    showButtonPanel: false,
    autoApply: true,
    showDropdowns: true,
    
    minYear: 2010,
    maxYear: parseInt(moment().format('YYYY'),10),
    locale: {
            format: 'YYYY-MM-DD'
        }
  }, ).on("apply.daterangepicker", function (e, picker) {
    $(".se-pre-con1").show();
        picker.element.val(picker.startDate.format(picker.locale.format));
        var parent = document.getElementById('charts');
    consultant=document.getElementById('consultant').value;
    date=document.getElementById('date').value;
    interval=document.querySelector('input[name=interval]:checked').value;

    data = {consultant: consultant, date:date,interval:interval};
    $.post('statistics/charts.php', data, function(data){
        $(parent).html(data);
        $(".se-pre-con1").hide();
    });
    
    });
});

</script>