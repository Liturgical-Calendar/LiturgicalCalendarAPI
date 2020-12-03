</div>
<!-- /.container-fluid -->

</div>
<!-- End of Main Content -->

<!-- Footer -->
<footer class="sticky-footer bg-white">
    <div class="container my-auto">
        <div class="copyright text-center my-auto">
            <span>Copyright &copy; John D'Orazio 2020</span>
        </div>
    </div>
</footer>
<!-- End of Footer -->

</div>
<!-- End of Content Wrapper -->

</div>
<!-- End of Page Wrapper -->

<!-- Bootstrap core JavaScript-->
<script src="assets/vendor/jquery/jquery.min.js"></script>
<script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

<!-- Core plugin JavaScript-->
<script src="assets/vendor/jquery-easing/jquery.easing.min.js"></script>

<!-- Custom scripts for all pages-->
<script src="assets/js/sb-admin-2.min.js"></script>
<script src="assets/js/bootstrap4-toggle.min.js"></script>
<script src="assets/vendor/jscookie/v3.0.0-rc.1/js.cookie.min.js"></script>
<script>
  if(Cookies.get("currentLocale") === undefined){
    Cookies.set("currentLocale", navigator.language );
  }
</script>

<script src="assets/js/homepage.js"></script>
<!-- current PHP script detected as: <?php echo basename($_SERVER["SCRIPT_FILENAME"], '.php') ?> -->
<?php 
    //some assets are only needed on certain pages
    switch(basename($_SERVER["SCRIPT_FILENAME"], '.php')){
        case 'extending':
            echo '<script src="assets/js/bootstrap-multiselect.js"></script>';
            //echo '<script src="assets/js/i18n.js"></script>';
            echo '<script src="assets/js/extending.js"></script>';
        break;
    }
?>