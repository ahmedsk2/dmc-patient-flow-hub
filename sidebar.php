<?php
session_start();
require_once 'dbconnect.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/csrf.php';
require_once 'authCookieSessionValidate.php';
date_default_timezone_set('Asia/Riyadh');
$today = date("Y-m-d");

if(!$isLoggedIn) {
    header("Location: ./");
}

if (!isset($_SESSION['member_id'])) {
    header("Location: ./");
    exit; // Don't forget to call exit after header redirection
}
$user_id = $_SESSION["member_id"];
$access_PICU_patients = [0, 2, 3, 4];
$access_PICU_endorsement = [0, 2, 4];
$access_PICU_control = [0];
$access_PICU_registrar = [0, 2];
$access_PICU_consultant = [0, 2, 3];

$formationSQL = "SELECT * FROM members WHERE member_id = ?";
$stmt = $mysqli->prepare($formationSQL);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_array(MYSQLI_ASSOC);
$stmt->close();

$username1 = $_SESSION['name'];
$position = $_SESSION['position'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="x-ua-compatible" content="ie=edge">
  <title>DMC | Dashboard</title>

  <!-- Combined CSS files -->
  <link href="css/css/all.min.css" rel="stylesheet">
  
  

  <style>
.select2-container--default .select2-selection--multiple .select2-selection__choice {
    background-color: #e4e4e4;
    border-color: #aaa;
}
.spinner-border {
    display: inline-block;
    width: 1rem;
    height: 1rem;
    vertical-align: text-bottom;
    border: 0.25em solid currentColor;
    border-right-color: transparent;
    border-radius: 50%;
    animation: spinner-border .75s linear infinite;
}

@keyframes spinner-border {
    to { transform: rotate(360deg); }
}

   .loading-spinner {
      /* position: fixed;
      top: 50%;
      left: 50%; */
      transform: translate(0%, 0%);
      text-align: center;
      font-size: 2rem;
      color: #007bff;
    }
    .select2-container--default .select2-selection--multiple .select2-selection__rendered li {
      list-style: none;
      color: black;
    }
    .select2-container--default .select2-selection--single .select2-selection__rendered {
      line-height: 15px;
    }
    .select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
      cursor: pointer;
      display: contents;
      font-weight: bold;
      margin-right: 2px;
    }
    .cancelBtn {
      color: black;
    }
    input, textarea {
      background-color: white;
      border: 1px solid #aaa;
      border-radius: 4px;
    }
    input {
      text-align: center;
    }
    label {
      width: 100%;
    }
    .no-js #loader { display: none; }
    .js #loader { display: block; position: absolute; left: 100px; top: 0; }
    .se-pre-con {
      position: fixed;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      z-index: 100;
      background: url(dist/img/Preloader_3.gif) center no-repeat #fff;
    }
  </style>
</head>
<body class="hold-transition sidebar-mini layout-fixed layout-navbar-fixed layout-footer-fixed">
<div class="wrapper">
  <!-- Navbar -->
  <nav class="main-header navbar navbar-expand navbar-white navbar-light">
    <!-- Left navbar links -->
    <ul class="navbar-nav">
      <a class="nav-item nav-link" data-widget="pushmenu" href="#" role="button">
        <li class="nav-item fas fa-bars"></li>
      </a>
      <li class="nav-item d-none d-sm-inline-block">
        <a href="dashboard.php" class="nav-link">Home</a>
      </li>
    </ul>
  </nav>
  <!-- /.navbar -->

  <!-- Main Sidebar Container -->
  <aside class="main-sidebar sidebar-dark-primary elevation-4">
    <!-- Brand Logo -->
    <a href="dashboard.php" class="brand-link">
      <img src="dist/img/logo-wt.png" alt="healthpro.Ai" width="110%" class="brand-image elevation-1" style="opacity: 1">
    </a>
    <!-- Sidebar -->
    <div class="sidebar">
      <!-- Sidebar user panel (optional) -->
      <div class="user-panel mt-3 pb-3 mb-3 d-flex">
        <div class="image">
          <img src="dist/img/minilogo.png" class="img-circle elevation-2" alt="User Image">
        </div>
        <div class="info">
          <p style="color: white;font-weight: bolder;text-transform: capitalize;font-size: large;" class="d-block"><?php echo $username1; ?></p>
          <a href="profile.php" class="d-block">Edit Profile</a>
        </div>
      </div>
      <!-- Sidebar Menu -->
      <nav class="mt-2">
        <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu" data-accordion="false">
          <li class="nav-item">
            <a href="dashboard.php" class="nav-link">
              <i class="nav-icon fas fa-tachometer-alt"></i>
              <p>Dashboard</p>
            </a>
          </li>
          <li class="nav-item">
            <a href="active-list.php" target="_blank" class="nav-link">
              <i class="nav-icon fas fa-book-open"></i>
              <p>Active Patients</p>
            </a>
          </li>
          <li class="nav-item">
            <a href="tb-patients.php" class="nav-link">
              <i class="nav-icon fas fa-book"></i>
              <p>Active TB Patients</p>
            </a>
          </li>
          <li class="nav-item">
            <a href="dmc-patients.php" class="nav-link">
              <i class="nav-icon fas fa-edit"></i>
              <?php
              echo in_array($position, $access_PICU_endorsement) ? "<p>All Patients</p>" : "<p>My Patients</p>";
              ?>
            </a>
          </li>
          <?php if (in_array($position, $access_PICU_patients)): ?>
          <li class="nav-item">
            <a href="dmc-new-consultation.php" class="nav-link">
              <i class="nav-icon fas fa-edit"></i>
              <?php
              echo in_array($position, $access_PICU_endorsement) ? "<p>All Consultations</p>" : "<p>My Consultations</p>";
              ?>
            </a>
          </li>
          <li class="nav-item">
            <a href="dmc-new-admissions.php" class="nav-link">
              <i class="nav-icon fas fa-edit"></i>
              <p>New Admissions</p>
            </a>
          </li>
          <?php endif; ?>
          <?php if (in_array($position, $access_PICU_control)): ?>
          <li class="nav-item">
            <a class="nav-link" data-bs-toggle="collapse" href="#cmenu" role="button" aria-expanded="false" aria-controls="cmenu">
              <i class="nav-icon fas fa-book"></i>
              <p>Registry</p>
            </a>
          </li>
          <div class="collapse" id="cmenu" style="background-color: rgb(255 255 255 / 7%);">
            <li class="nav-item">
              <a href="search.php" class="nav-link">
                <i class="nav-icon fas fa-search"></i>
                <p>Registry Search</p>
              </a>
            </li>
            <li class="nav-item">
              <a href="48discharge.php" class="nav-link">
                <i class="nav-icon fas fa-book"></i>
                <p>Recent Discharges</p>
              </a>
            </li>
            <li class="nav-item">
              <a href="48consultation.php" class="nav-link">
                <i class="nav-icon fas fa-book"></i>
                <p>Recent Consultations</p>
              </a>
            </li>
            <li class="nav-item">
              <a href="registry-tb-patients.php" class="nav-link">
                <i class="nav-icon fas fa-book"></i>
                <p>TB Registry</p>
              </a>
            </li>
          </div>
          <li class="nav-item">
            <a class="nav-link" data-bs-toggle="collapse" href="#cmenu1" role="button" aria-expanded="false" aria-controls="cmenu1">
              <i class="nav-icon fas fa-chart-bar"></i>
              <p>Statistics</p>
            </a>
          </li>
          <div class="collapse" id="cmenu1" style="background-color: rgb(255 255 255 / 7%);">
            <li class="nav-item">
              <a href="statistics.php" class="nav-link">
                <i class="nav-icon fas fa-chart-bar"></i>
                <p>Physician Stats</p>
              </a>
            </li>
            <li class="nav-item">
              <a href="allstat.php" class="nav-link">
                <i class="nav-icon fas fa-chart-bar"></i>
                <p>Overall Stats</p>
              </a>
            </li>
            <li class="nav-item">
              <a href="extract_data.php" class="nav-link">
                <i class="nav-icon fas fa-chart-bar"></i>
                <p>Export to Excel</p>
              </a>
            </li>
          </div>
          <li class="nav-item">
            <a href="dmc-old-patients.php" class="nav-link">
              <i class="nav-icon fas fa-cog"></i>
              <p>Add Old Patients</p>
            </a>
          </li>
          <li class="nav-item">
            <a href="control.php" class="nav-link">
              <i class="nav-icon fas fa-cog"></i>
              <p>Control Page</p>
            </a>
          </li>
          <?php endif; ?>
          <li class="nav-item">
            <a href="logout.php" class="nav-link">
              <i class="nav-icon fas fa-sign-out-alt"></i>
              <p>Logout</p>
            </a>
          </li>
        </ul>
      </nav>
    </div>
  </aside>
<div class="se-pre-con"></div>