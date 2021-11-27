<?php 
$currentPage = basename($_SERVER["SCRIPT_FILENAME"], '.php');
$isStaging = (strpos(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH),"-staging") !== false);
?>

<!-- Page Wrapper -->
<div id="wrapper">

    <!-- Sidebar -->
    <ul class="navbar-nav bg-gradient-primary sidebar sidebar-dark accordion" id="accordionSidebar">

        <!-- Sidebar - Brand -->
        <li>
            <a class="sidebar-brand d-flex align-items-center justify-content-center" href="/LiturgicalCalendar<?php echo $isStaging ? "-staging" : "" ?>">
                <div class="sidebar-brand-text mx-3">Catholic Liturgical Calendar</div>
            </a>
        </li>

        <!-- Divider -->
        <li>
            <hr class="sidebar-divider my-0" />
        </li>

        <!-- Nav Item - Dashboard -->
        <li class="nav-item active">
            <a class="nav-link" href="/LiturgicalCalendar<?php echo $isStaging ? "-staging" : "" ?>">
                <i class="fas fa-fw fa-cross"></i>
                <span>Home</span>
            </a>
        </li>

        <!-- Divider -->
        <li>
            <hr class="sidebar-divider" />
        </li>

        <!-- Heading -->
        <li>
            <div class="sidebar-heading">
                Scripts
            </div>
        </li>

        <li class="nav-item">
            <a class="nav-link" href="/LiturgicalCalendar<?php echo $isStaging ? "-staging" : "" ?>/LitCalEngine.php">
                <i class="fas fa-fw fa-folder"></i>
                <span>API endpoint</span></a>
        </li>

        <li class="nav-item">
            <a class="nav-link" href="/LiturgicalCalendar<?php echo $isStaging ? "-staging" : "" ?>/dist/">
                <i class="fas fa-fw fa-folder"></i>
                <span>Swagger / Open API Docs</span></a>
        </li>

        <li class="nav-item">
            <a class="nav-link" href="/LiturgicalCalendar<?php echo $isStaging ? "-staging" : "" ?>/easter.php">
                <i class="fas fa-fw fa-folder"></i>
                <span>Date of Easter</span></a>
        </li>

        <!-- Divider -->
        <li>
            <hr class="sidebar-divider" />
        </li>

        <!-- Heading -->
        <li>
            <div class="sidebar-heading">
                Examples
            </div>
        </li>

        <li class="nav-item">
            <a class="nav-link" href="/LiturgicalCalendar<?php echo $isStaging ? "-staging" : "" ?>/examples/php/">
                <i class="fas fa-fw fa-folder"></i>
                <span>PHP + cURL</span></a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="/LiturgicalCalendar<?php echo $isStaging ? "-staging" : "" ?>/examples/javascript/">
                <i class="fas fa-fw fa-folder"></i>
                <span>HTML + AJAX</span></a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="/LiturgicalCalendar<?php echo $isStaging ? "-staging" : "" ?>/examples/fullcalendar/examples/month-view.html">
                <i class="fas fa-fw fa-folder"></i>
                <span>Full Calendar</span></a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="/LiturgicalCalendar<?php echo $isStaging ? "-staging" : "" ?>/examples/fullcalendar/examples/messages.html">
                <i class="fas fa-fw fa-folder"></i>
                <span>Full Calendar (messages first)</span></a>
        </li>

        <!-- Divider -->
        <li>
            <hr class="sidebar-divider d-none d-md-block" />
        </li>

        <!-- Sidebar Toggler (Sidebar) -->
        <li>
            <div class="text-center d-none d-md-block">
                <button class="rounded-circle border-0" id="sidebarToggle"></button>
            </div>
        </li>
    </ul>
    <!-- End of Sidebar -->

    <!-- Content Wrapper -->
    <div id="content-wrapper" class="d-flex flex-column">

        <!-- Main Content -->
        <div id="content">
            <!-- Topbar -->
            <nav class="navbar navbar-expand navbar-light bg-white mb-4 static-top shadow">

                <!-- Sidebar Toggle (Topbar) -->
                <button id="sidebarToggleTop" class="btn btn-link d-md-none rounded-circle mr-3">
                    <i class="fa fa-bars"></i>
                </button>

                <!-- Topbar Navbar -->
                <ul class="navbar-nav">
                    <li class="nav-item<?php echo $currentPage=="index" || $currentPage == "" ? " active" : ""; ?>" id="topNavBar_API"><a class="nav-link<?php echo $currentPage=="index" || $currentPage == "" ? " font-weight-bold" : ""; ?>" href="./index.php">API</a></li>
                    <li class="nav-item<?php echo $currentPage=="usage" ? " active" : ""; ?>" id="topNavBar_Usage"><a class="nav-link<?php echo $currentPage=="usage" ? " font-weight-bold" : ""; ?>" href="./usage.php"><?php _e("Usage"); ?></a></li>
                    <li class="nav-item dropdown<?php echo $currentPage=="extending" ? " active" : ""; ?>" id="topNavBar_Extending">
                        <a class="nav-link dropdown-toggle<?php echo $currentPage=="extending" ? " font-weight-bold" : ""; ?>" href="#" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" id="extendingChoicesDropdown"><?php _e("Extending the API"); ?></a>
                        <div class="dropdown-menu dropdown-menu-right shadow animated--grow-in" aria-labelledby="extendingChoicesDropdown" id="extendingChoicesDropdownItems">
                            <a class="dropdown-item<?php echo isset($_GET["choice"]) && $_GET["choice"]=="national"?" active":"" ?>" id="extendingChoiceNationalCalendar" href="./extending.php?choice=national"><?php _e("Create a National Calendar"); ?></a>
                            <a class="dropdown-item<?php echo isset($_GET["choice"]) && $_GET["choice"]=="diocesan"?" active":"" ?>" id="extendingChoiceDiocesanCalendar" href="./extending.php?choice=diocesan"><?php _e("Create a Diocesan Calendar"); ?></a>
                        </div>
                    </li>
                    <li class="nav-item<?php echo $currentPage=="about" ? " active" : ""; ?>" id="topNavBar_AboutUs"><a class="nav-link<?php echo $currentPage=="about" ? " font-weight-bold" : ""; ?>" href="./about.php"><?php _e("About us"); ?></a></li>
                </ul>                
                <ul class="navbar-nav ml-auto">
                    <li class="nav-item dropdown">
                        <!-- this should contain the value of the currently selected language, based on a cookie -->
                        <a class="nav-link dropdown-toggle" href="#" id="langChoicesDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                          English
                        </a>
                        <div class="dropdown-menu dropdown-menu-right shadow animated--grow-in" aria-labelledby="langChoicesDropdown" id="langChoicesDropdownItems">
                          <a class="dropdown-item<?php echo LITCAL_LOCALE=="en" ? " active" : "" ?>" id="langChoiceEnglish" href="#">English</a>
                          <a class="dropdown-item<?php echo LITCAL_LOCALE=="fr" ? " active" : "" ?>" id="langChoiceFrench" href="#">French</a>
                          <a class="dropdown-item<?php echo LITCAL_LOCALE=="de" ? " active" : "" ?>" id="langChoiceGerman" href="#">German</a>
                          <a class="dropdown-item<?php echo LITCAL_LOCALE=="it" ? " active" : "" ?>" id="langChoiceItalian" href="#">Italian</a>
                          <a class="dropdown-item<?php echo LITCAL_LOCALE=="lat" ? " active" : "" ?>" id="langChoiceLatin" href="#">Latin</a>
                          <a class="dropdown-item<?php echo LITCAL_LOCALE=="pt" ? " active" : "" ?>" id="langChoicePortuguese" href="#">Portuguese</a>
                          <a class="dropdown-item<?php echo LITCAL_LOCALE=="es" ? " active" : "" ?>" id="langChoiceSpanish" href="#">Spanish</a>
                        </div>
                      </li>
                </ul>

                <a class="btn btn-transparent-dark mr-2"
                    href="https://github.com/JohnRDOrazio/LiturgicalCalendar" target="_blank"
                    title="Fork me on GitHub">
                    <i class="fab fa-github"></i>
                </a>
            </nav>
            <div class="container-fluid">
