<?php
//turn on error reporting for the staging site
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$LOCALE = Locale::acceptFromHttp($_SERVER['HTTP_ACCEPT_LANGUAGE']);
if($LOCALE !== null){
    //we only need the two letter ISO code, not the national extension
    if(strpos($LOCALE,"_")){
        $LOCALE = explode("_", $LOCALE)[0];
    }
} else {
    $LOCALE = "en";
}
define("LITCAL_LOCALE", isset($_COOKIE("currentLocale")) ? $_COOKIE("currentLocale") : $LOCALE );

/**
 * Translation function __()
 */

function __($key, $locale)
{
    global $messages;
    $lcl = strtolower($locale);
    if (isset($messages)) {
        if (isset($messages[$key])) {
            if (isset($messages[$key][$lcl])) {
                return $messages[$key][$lcl];
            } else {
                return $messages[$key]["en"];
            }
        } else {
            return $key;
        }
    } else {
        return $key;
    }
}

/**
 * Define our translation strings
*/
$messages = [
    "General Roman Calendar" => [
        "de" => "Allgemeiner Römischer Kalender",
        "en" => "General Roman Calendar",
        "es" => "Calendario Romano General",
        "fr" => "Calendrier Général Romain",
        "it" => "Calendario Romano Generale",
        "pt" => "Calendário Romano Geral",
    ]
];

?>

<!doctype html>
<html lang="en">
<head>
    <title><?php __("General Roman Calendar") ?></title>
    <?php echo  file_get_contents('layout/head.php'); ?>
</head>
<body>

    <?php echo  file_get_contents('layout/header.php'); ?>

        <!-- Page Heading -->
        <h1 class="h3 mb-2 text-gray-800">Catholic Liturgical Calendar</h1>
        <p class="mb-4">An API for the Liturgical Calendar for any given year, based on the Universal or General Roman Calendar and for derived National and Diocesan calendars</p>

        <!-- Page Heading -->
        <h3 class="h3 mb-2 text-gray-800">SCRIPTS</h3>
    
        <!-- Content Row -->
        <div class="row">
            <div class="col-md-6">
                <div class="card shadow m-2">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Data Generation Endpoint</h6>
                    </div>
                    <div class="card-body">
                        <div><a href="LitCalEngine.php" class="btn btn-primary">View Data Example</a></div>
                        <div><a href="dist/" class="btn btn-primary mt-2">Swagger / Open API Documentation</a></div>
                        <p><i>A sample request to the endpoint could look like this:</i></p>
                        <code>/LitCalEngine.php?year=2020&amp;epiphany=SUNDAY_JAN2_JAN8&amp;ascension=SUNDAY&amp;corpuschristi=SUNDAY&amp;returntype=JSON&amp;locale=EN</code>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card shadow m-2">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Easter</h6>
                    </div>
                    <div class="card-body">
                        <p>Calculation of the Date of Easter, both Gregorian and Julian, from 1583
                            (year of the adoption of the Gregorian Calendar) to 9999 (max date calculation in
                            64bit PHP)</p>
                        <div><a href="easter.php" class="btn btn-primary">View Easter Example</a></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Page Heading -->
        <h3 class="h3 mb-2 text-gray-800">EXAMPLES</h3>

        <div class="row">

            <div class="col-md-6">
                <div class="card shadow m-2">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">PHP</h6>
                    </div>
                    <div class="card-body">
                        <p>HTML presentation elaborated by PHP using a CURL request</p>
                        <div><a href="examples/php/" class="btn btn-primary">View PHP Example</a></div>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card shadow m-2">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">JavaScript</h6>
                    </div>
                    <div class="card-body">
                        <p>HTML presentation elaborated by JAVASCRIPT using an AJAX
                            request</p>
                        <div><a href="examples/javascript/" class="btn btn-primary">View JavaScript Example</a></div>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card shadow m-2">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Calendar</h6>
                    </div>
                    <div class="card-body">
                        <p>Fullcalendar representation
                            elaborated by JAVASCRIPT using an AJAX request</p>
                        <div><a href="examples/fullcalendar/examples/month-view.html" class="btn btn-primary">View Full Calendar</a></div>
                        <div><a href="examples/fullcalendar/examples/messages.html" class="btn btn-primary mt-2">View Full Calendar (messages first)</a></div>
                    </div>
                </div>
            </div>
        </div>
        <!-- /.row -->

    <?php echo  file_get_contents('layout/footer.php'); ?>

</body>
</html>
