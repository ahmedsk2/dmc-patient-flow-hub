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
?>

     <style>
          .hidden{
                  display: none;
          }
          textarea {
    resize: none;
    overflow: hidden;
}

/* loader on search */
.se-pre-con1 {
	text-align: center;
    display: none;
    width: 100%;

}
.se-pre-con2 {
	text-align: center;
    display: none;
    width: 100%;

}


      </style>



  <!-- Content Wrapper. Contains page content -->
  <div class="content-wrapper">

    <!-- Content Header (Page header) -->
    <div class="content-header">
      <div class="container-fluid">
        <div class="row mb-2">
          <div class="col-sm-6">
            <h1 class="m-0 text-dark">Statistics</h1>
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


      <div class="row">
      <div id="mypresentersTable" class="col-md-12">

      <div class="card">
  <div  class="card-header">
    <h3 class="card-title"><i class="nav-icon fas fa-tachometer-alt text-info"></i> Reports  </h3>
   
  </div>
  <!-- /.card-header -->
  <div class="card-body">
  <div class="row" style="width: 100%;">
<div class="col-sm-12" style=" display: inline-flex; ">
        <label>Select Year:</label>
        <select class='year111 select2' id='year1' style=" width: fit-content; text-align: center;float: left;padding: 4px;">
        <option>Select</option>
                        <?php
                                 $formationSQL = "SELECT DISTINCT YEAR(DISDATE) FROM picupatients";
                                 $result1 = $mysqli->query($formationSQL);
                                 $years = $result1 -> fetch_all(MYSQLI_ASSOC);
                                
                        foreach($years as $y){
                          echo"<option  value='".$y['YEAR(DISDATE)']."'>".$y['YEAR(DISDATE)']."</option>";

                        }
                        ?>
                        <!-- <option  value='yearly'>Yearly</option> -->
                    </select>
                    <div id="reportbtn" style=" display: inline-flex; ">
                    
                    </div>
</div><!-- end colomn  -->

</div><!-- end row -->
</div><!-- end colomn  -->
</div><!-- end row -->
  </div>
  <!-- /.card-body -->
</div>
<!-- /.card -->


      <div class="row">
      <div id="mypresentersTable" class="col-md-12">

      <div class="card">
  <div  class="card-header">
    <h3 class="card-title"><i class="nav-icon fas fa-tachometer-alt text-info"></i> Overview  </h3>
    <i style="float: right;margin: 1%;" download="download.png" id="btn-Preview-Image" type="button" value="Download" class="fas fa-download"></i>

    <select class='txtdata1 select2' id ="time1"  name='time1' style=" width: fit-content; text-align: center;float: right;padding: 4px;">
                        <option selected value='daily'>Daily</option>
                        <option  value='monthly'>Monthly</option>
                        <option  value='quarterly'>Quarterly</option>
                        <!-- <option  value='yearly'>Yearly</option> -->
                    </select> 
  </div>
  <!-- /.card-header -->
  <div class="card-body">
  <div class="row" style="background: white" id="divpicture">
  <div class="se-pre-con1"><img src="dist/img/Preloader_3.gif"></div>
<div class="col-sm-12" id='charts1'>
</div><!-- end colomn  -->

</div><!-- end row -->
</div><!-- end colomn  -->
</div><!-- end row -->
  </div>
  <!-- /.card-body -->
</div>
<!-- /.card -->





<div class="row">
      <div id="mypresentersTable" class="col-md-12">

      <div class="card">
  <div  class="card-header">
   
  <h3 class="card-title"><i class="nav-icon fas fa-tachometer-alt text-info"></i> Numbers</h3>
  <i style="float: right;margin: 1%;" download="download.png" id="btn-Preview-Image1" type="button" value="Download" class="fas fa-download"></i>

  <div style=" width: fit-content; text-align: center;float: right;padding: 4px;">
    <label style="width: auto;">Date:</label>

       <input autocomplete="off" class='txtdata2' id ="date2"  data-date-format="DD-MM-YYYY" type="text"  name='date2' style="text-align: center;padding: 0px;width: auto;" required>

    <select class='txtdata2 select2' id ="time2"  name='time2' style=" width: fit-content; text-align: center;float: right;padding: 4px;">
                        <option selected value='daily'>Daily</option>
                        <option  value='monthly'>Monthly</option>
                        <option  value='quarterly'>Quarterly</option>
                        <!-- <option  value='yearly'>Yearly</option> -->
                    </select> 
    </div>
  </div>
  <!-- /.card-header -->
  <div class="card-body">
  <div class="row" style="background: white" id="divpicture1">
  <div class="se-pre-con2"><img src="dist/img/Preloader_3.gif"></div>


  <div class="col-sm-12" id='kpi' >
</div><!-- end colomn  -->


</div><!-- end row -->
</div><!-- end colomn  -->
</div><!-- end row -->
  </div>
  <!-- /.card-body -->
</div>
<!-- /.card -->



</div><!--/. container-fluid -->

    </section>
    <!-- /.content -->


<!-- PAGE SCRIPTS -->    


  </div>
  <!-- /.content-wrapper -->


<?php

require 'footer.php';

?>

<script type="text/javascript">
$('.year111').change(function(){

  var buttons = document.getElementById('reportbtn');
  year =document.getElementById('year1').value;
 
    // disto.style.display = "none";
    buttons.innerHTML ="<a href='statistics/a4.php?y="+year+"' class='btn btn-success' style='line-height: 150%;margin: 0px 2%;' target='_blank'>Yearly</a><a href='statistics/a4-monthly.php?y="+year+"' style='line-height: 150%;margin: 0px 2%;' class='btn btn-success'  target='_blank'>Monthly</a>";
    
    // mortuary.style.display = "block";

});
</script>
<script>
        $(document).ready(function() {


            var element = $("#divpicture"); // global variable
            var getCanvas; // global variable
            var newData;

            $("#btn-Preview-Image").on('click', function() {
                html2canvas(element, {
                    onrendered: function(canvas) {
                        getCanvas = canvas;
                        var imgageData = getCanvas.toDataURL("image/png");
                        var a = document.createElement("a");
                        a.href = imgageData; //Image Base64 Goes here
                        a.download = "Overview.png"; //File name Here
                        a.click(); //Downloaded file
                    }
                });
            });


        });
    </script>

<script>
        $(document).ready(function() {


            var element = $("#divpicture1"); // global variable
            var getCanvas; // global variable
            var newData;

            $("#btn-Preview-Image1").on('click', function() {
                html2canvas(element, {
                    onrendered: function(canvas) {
                        getCanvas = canvas;
                        var imgageData = getCanvas.toDataURL("image/png");
                        var a = document.createElement("a");
                        a.href = imgageData; //Image Base64 Goes here
                        a.download = "Overview.png"; //File name Here
                        a.click(); //Downloaded file
                    }
                });
            });


        });
    </script>


  <script>

$(document).ready(function($) {
  $(".se-pre-con1").show();
  var parent = document.getElementById('charts1');
    var timing1=document.getElementById('time1').value;

    datas1 = {timing1: timing1};
    $.post('statistics/time1.php', datas1, function(datas1){
        $(parent).html(datas1);
        $(".se-pre-con1").hide();
     
    });
    



    
  $('.txtdata1').on('change', function(){
    $(".se-pre-con1").show();
    var parent = document.getElementById('charts1');
    var timing1=document.getElementById('time1').value;

    datas1 = {timing1: timing1};
    $.post('statistics/time1.php', datas1, function(datas1){
        $(parent).html(datas1);
        $(".se-pre-con1").hide();
     
    });
    

  });
});
</script>

<!-- KPI -->
<script>

document.getElementById('date2').value = "<?php echo date("Y-m-d"); ?>";

$(document).ready(function($) {
  $(".se-pre-con2").show();
    var parent = document.getElementById('kpi');
    var timing2=document.getElementById('time2').value;
    var date2=document.getElementById('date2').value;
    datas2 = {date2:date2,timing2: timing2};
    $.post('statistics/kpis.php', datas2, function(datas2){
        $(parent).html(datas2);
        $(".se-pre-con2").hide();
    });
    

    
$(function() {
  $('input[name="date2"]').daterangepicker({
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
        var parent = document.getElementById('kpi');
    var timing2=document.getElementById('time2').value;
    var date2=document.getElementById('date2').value;
    datas2 = {date2:date2,timing2: timing2};
    $.post('statistics/kpis.php', datas2, function(datas2){
        $(parent).html(datas2);
        $(".se-pre-con2").hide();

    });
    
    });
});


    
  $('.txtdata2').on('change', function(){
    $(".se-pre-con2").show();
    var parent = document.getElementById('kpi');
    var timing2=document.getElementById('time2').value;
    var date2=document.getElementById('date2').value;
    datas2 = {date2:date2,timing2: timing2};
    $.post('statistics/kpis.php', datas2, function(datas2){
        $(parent).html(datas2);
        $(".se-pre-con2").hide();

    });
    

  });
});
</script>


<script src="vendor/moments.js/2.30.1/moment.min.js"></script>
<script src="vendor/daterangepicker/3.1.0/daterangepicker.min.js"></script>
<!-- then your own script that calls picker.startDate.format(...) -->
