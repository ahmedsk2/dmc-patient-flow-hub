
<?php require_once __DIR__ . '/csrf.php'; ?>
  <!-- Main Footer -->
  <footer class="main-footer">
  <strong>Designed by <a href="mailto: ahmedsk2@gmail.com">Dr. Ahmed Al-Khalifah</a> and <a href="mailto: alih6234@gmail.com">Dr. Ali Al-Saeed</a></strong>
    All rights reserved.
    <div class="float-right d-none d-sm-inline-block">
      <b>Version</b> 2.5
    </div>
  </footer>
</div>
<!-- ./wrapper -->


   <!-- jQuery -->
  <script src="vendor/jquery-3.7.1.min.js"></script>
  <!-- CSRF: attach the synchronizer token to every state-changing AJAX request -->
  <script>
    window.DMC_CSRF = <?php echo json_encode(csrf_token()); ?>;
    if (window.jQuery) {
      $(document).ajaxSend(function (e, xhr, settings) {
        var m = (settings.type || 'GET').toUpperCase();
        if (m !== 'GET' && m !== 'HEAD' && m !== 'OPTIONS') {
          xhr.setRequestHeader('X-CSRF-Token', window.DMC_CSRF);
        }
      });
    }
  </script>

  <!-- Patient-safety helpers (W1/W4): identity-bearing confirmations + response-aware saves -->
  <script>
    // dmcOk: true only when a server action endpoint reports success.
    // Convention across the action endpoints: success responses contain "successfully";
    // error responses do not (they say "Error ...").
    window.dmcOk = function (resp) {
      return /successfully/i.test(String(resp == null ? '' : resp));
    };

    // dmcConfirm: confirm a destructive/clinical action, showing patient identity when known.
    window.dmcConfirm = function (actionLabel, name, mrn) {
      var who = '';
      var n = (name == null ? '' : String(name)).trim();
      var m = (mrn == null ? '' : String(mrn)).trim();
      if (n || m) {
        who = '\n\nPatient: ' + (n || '(unknown)') + '\nMRN: ' + (m || '(unknown)');
      }
      return window.confirm(actionLabel + who);
    };

    // dmcRowIdentity: best-effort {name, mrn} from the patient row/card containing `el`,
    // for row-based actions (delete / reverse / sign-off / undo) where only an ID is passed.
    window.dmcRowIdentity = function (el) {
      var out = { name: '', mrn: '' };
      if (!window.jQuery || !el) { return out; }
      var row = jQuery(el).closest('.eachrow, tr');
      if (!row.length) { return out; }
      function pick(sel) {
        var cell = row.find(sel).first();
        if (!cell.length) { return ''; }
        var fld = cell.find('input, select, textarea').first();
        var v = fld.length ? fld.val() : cell.text();
        return (v == null ? '' : String(v)).trim();
      }
      out.name = pick('.name');
      out.mrn  = pick('.mrn');
      return out;
    };
  </script>

  <!-- AdminLTE App -->
<script src="dist/js/adminlte.js"></script>

<!-- OPTIONAL SCRIPTS -->
<script src="dist/js/demo.js"></script>

  <!-- Bootstrap JS -->
  <script src="vendor/bootstrap-5.3.3-dist/js/bootstrap.bundle.min.js"></script>
  <!-- Select2 JS -->

    <script>
  $(window).on('load', function() {
    $(".se-pre-con").fadeOut("slow");
  });
  </script>

  
</body>
</html>