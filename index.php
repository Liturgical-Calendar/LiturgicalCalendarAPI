<!doctype html>
<head>
    <title>Generate Roman Calendar</title>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <meta name="msapplication-TileColor" content="#ffffff"/>
    <meta name="msapplication-TileImage" content="easter-egg-5-144-279148.png">
    <link rel="apple-touch-icon-precomposed" sizes="152x152" href="assets/img/easter-egg-5-152-279148.png">
    <link rel="apple-touch-icon-precomposed" sizes="144x144" href="assets/img/easter-egg-5-144-279148.png">
    <link rel="apple-touch-icon-precomposed" sizes="120x120" href="assets/img/easter-egg-5-120-279148.png">
    <link rel="apple-touch-icon-precomposed" sizes="114x114" href="assets/img/easter-egg-5-114-279148.png">
    <link rel="apple-touch-icon-precomposed" sizes="72x72" href="assets/img/easter-egg-5-72-279148.png">
    <link rel="apple-touch-icon-precomposed" href="assets/imgeaster-egg-5-57-279148.png">
    <link rel="icon" href="img/easter-egg-5-32-279148.png" sizes="32x32">

    <!-- Custom fonts for this template-->
    <link href="assets/vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i"
          rel="stylesheet">

    <!-- Custom styles for this template-->
    <link href="assets/css/sb-admin-2.min.css" rel="stylesheet">
</head>
<body>

<!-- Page Wrapper -->
<div id="wrapper">

    <!-- Sidebar -->
    <ul class="navbar-nav bg-gradient-primary sidebar sidebar-dark accordion" id="accordionSidebar">

        <!-- Sidebar - Brand -->
        <a class="sidebar-brand d-flex align-items-center justify-content-center" href="index.html">
            <div class="sidebar-brand-text mx-3">Liturgical Calendar Project</sup></div>
        </a>

        <!-- Divider -->
        <hr class="sidebar-divider my-0">

        <!-- Nav Item - Dashboard -->
        <li class="nav-item active">
            <a class="nav-link" href="/LiturgicalCalendar">
                <i class="fas fa-fw fa-tachometer-alt"></i>
                <span>Home</span></a>
        </li>

        <!-- Divider -->
        <hr class="sidebar-divider">

        <!-- Heading -->
        <div class="sidebar-heading">
            Examples
        </div>

        <li class="nav-item">
            <a class="nav-link" href="easter.php">
                <i class="fas fa-fw fa-chart-area"></i>
                <span>Easter</span></a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="easter.php">
                <i class="fas fa-fw fa-chart-area"></i>
                <span>PHP + cURL</span></a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="easter.php">
                <i class="fas fa-fw fa-chart-area"></i>
                <span>Easter</span></a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="easter.php">
                <i class="fas fa-fw fa-chart-area"></i>
                <span>HTML + AJAX</span></a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="easter.php">
                <i class="fas fa-fw fa-chart-area"></i>
                <span>Full Calendar</span></a>
        </li>

        <!-- Divider -->
        <hr class="sidebar-divider d-none d-md-block">

        <!-- Sidebar Toggler (Sidebar) -->
        <div class="text-center d-none d-md-inline">
            <button class="rounded-circle border-0" id="sidebarToggle"></button>
        </div>
    </ul>
    <!-- End of Sidebar -->

    <!-- Content Wrapper -->
    <div id="content-wrapper" class="d-flex flex-column">

        <!-- Main Content -->
        <div id="content">
            <!-- Topbar -->
            <nav class="navbar navbar-expand navbar-light bg-white topbar mb-4 static-top shadow"></nav>
            <div class="container-fluid">

                <!-- Page Heading -->
                <div class="d-sm-flex align-items-center justify-content-between mb-4">
                    <h1 class="h3 mb-0 text-gray-800">Liturgical Calendar Project</h1>
                </div>

                <!-- Content Row -->
                <div class="row">
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Examples</h6>
                        </div>
                        <div class="card-body">
                            <p>Liturgical Calendar engine with algorithm to generate the Universal or General Roman
                                Calendar for any given year</p>
                            <div><a href="LitCalEngine.php">Data
                                    generation Endpoint here (similar to a REST API)</a></div>
                            <div><a href="easter.php">Calculate Date of Easter, both Gregorian and Julian, from 1583
                                    (year of the adoption of the Gregorian Calendar) to 9999 (max date calculation in
                                    64bit PHP)</a></div>
                            <div><a href="examples/php/">HTML presentation elaborated by PHP using a CURL request</a>
                            </div>
                            <div><a href="examples/javascript/">HTML presentation elaborated by JAVASCRIPT using an AJAX
                                    request</a></div>
                            <div><a href="examples/fullcalendar/examples/month-view.html">Fullcalendar representation
                                    elaborated by JAVASCRIPT using an AJAX request</a></div>
                            <div><a href="examples/fullcalendar/examples/messages.html">Fullcalendar representation
                                    elaborated by JAVASCRIPT using an AJAX request (messages first)</a></div>
                        </div>
                    </div>
                </div>
                <!-- /.row -->
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

</body>
