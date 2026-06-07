<?php require_once 'sidebar.php'; ?>
<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
  
  <div id="content" class="content" style="display: block;">
    <div class="container-fluid">
      <div class="row">
        <div id="mypresentersTable1" class="col-md-12"></div>
        <div id="mypresentersTable3" class="col-md-12"></div>
      </div>
          <div id="loadingSpinner" class="loading-spinner">
          <i class="fas fa-spinner fa-spin"></i> Loading...
          <div id="progressBarContainer" style="width: 100%; background-color: #f3f3f3; margin-top: 10px;">
            <div id="progressBar" style="width: 0%; height: 10px; background-color: #007bff;"></div>
          </div>
          <div id="loadingPercentage" style="text-align: center; margin-top: 5px;">0%</div>
        </div>  
    </div>

  </div>

  <!-- Chart.js -->
  <script src="vendor/Chart.js/4.4.2/chart.umd.min.js"></script>
  <!-- html2canvas -->
  <script src="vendor/html2canvas/1.4.1/html2canvas.min.js"></script>
  <?php require_once 'footer.php'; ?>

  
  <script>
   document.addEventListener('DOMContentLoaded', function () {
      var content = document.getElementById('content');
      var loadingSpinner = document.getElementById('loadingSpinner');
      var progressBar = document.getElementById('progressBar');
      var loadingPercentage = document.getElementById('loadingPercentage');

      // Function to update progress bar and percentage
      function updateProgress(percentage) {
        progressBar.style.width = percentage + '%';
        loadingPercentage.textContent = percentage + '%';
      }

      // Run a fragment's inline <script> blocks WITHOUT eval() (so the CSP needs no 'unsafe-eval'):
      // clone each into a fresh <script> element, which the browser executes on insertion.
      function runInlineScripts(containerId) {
        document.querySelectorAll('#' + containerId + ' script').forEach(function (old) {
          var s = document.createElement('script');
          if (old.src) { s.src = old.src; } else { s.textContent = old.textContent; }
          document.body.appendChild(s);
          if (old.parentNode) { old.parentNode.removeChild(old); }
        });
      }

      // Function to load content asynchronously
      function loadContent() {
        let totalSteps = 2; // Total number of steps to load content
        let completedSteps = 0;

        function updateAndCheckCompletion() {
          completedSteps++;
          let percentage = Math.round((completedSteps / totalSteps) * 100);
          updateProgress(percentage);

          if (completedSteps === totalSteps) {
            loadingSpinner.style.display = 'none';
          }
        }

        function downloadImage(elementId, filename) {
          var element = document.getElementById(elementId);
          if (!element) {
            return;
          }

          html2canvas(element).then(function (canvas) {
            var imageData = canvas.toDataURL("image/png");
            var a = document.createElement("a");
            a.href = imageData;
            a.download = filename;
            a.click();
          }).catch(function (error) {
            console.error('Error capturing element: ', error);
          });
        }


        fetch('dashboard/1.php')
          .then(response => {
            if (!response.ok) {
              throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.text();
          })
          .then(data => {
            document.getElementById('mypresentersTable1').innerHTML = data;

            // Execute the fragment's inline scripts without eval() (CSP-friendly).
            runInlineScripts('mypresentersTable1');

            updateAndCheckCompletion();

            return fetch('dashboard/3.php');
          })
          .then(response => {
            if (!response.ok) {
              throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.text();
          })
          .then(data => {
            document.getElementById('mypresentersTable3').innerHTML = data;

            // Execute the fragment's inline scripts without eval() (CSP-friendly).
            runInlineScripts('mypresentersTable3');

            updateAndCheckCompletion();

            document.getElementById('btn-Preview-Image').addEventListener('click', function () {
              downloadImage('mymonthlyChart', '30_Days_Overview.png');
            });

            document.getElementById('btn-Preview-Image1').addEventListener('click', function () {
              downloadImage('dailygraphs', 'Daily_Overview.png');
            });
          })
          .catch(error => {
            console.error('Error loading content:', error);
            loadingSpinner.innerHTML = 'Failed to load content. Please try again later.';
          });
      }

      // Start loading content
      loadContent();
    });
  </script>
