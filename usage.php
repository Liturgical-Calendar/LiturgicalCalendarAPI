<?php

include_once("./i18n.php");

/**
 * Define our translation strings
*/
$messages = array_merge($messages, [
    "General Roman Calendar" => [
        "de" => "Allgemeiner Römischer Kalender",
        "en" => "General Roman Calendar",
        "es" => "Calendario Romano General",
        "fr" => "Calendrier Général Romain",
        "it" => "Calendario Romano Generale",
        "pt" => "Calendário Romano Geral",
    ],
    "Catholic Liturgical Calendar" => [
        "de" => "Katholischer Liturgischer Kalender",
        "en" => "Catholic Liturgical Calendar",
        "es" => "Calendario Litúrgico Católico",
        "fr" => "Calendrier Liturgique Catholique",
        "it" => "Calendario Liturgico Cattolico",
        "pt" => "Calendário Litúrgico Católico",
    ],
    "API_DESCRIPTION" => [
        "de" => "Eine API für den liturgischen Kalender, aus der Sie Daten für die liturgischen Ereignisse eines bestimmten Jahres ab 1970 abrufen können, sei es für den universellen oder allgemeinen römischen Kalender oder für abgeleitete nationale und diözesane Kalender",
        "en" => "A Liturgical Calendar API from which you can retrieve data for the Liturgical events of any given year from 1970 onwards, whether for the Universal or General Roman Calendar or for derived National and Diocesan calendars",
        "es" => "Una API para el Calendario Litúrgico del cual puede recuperar datos para los eventos litúrgicos de cualquier año desde 1970 en adelante, ya sea para el Calendario Romano General o Universal o para los calendarios nacionales y diocesanos derivados.",
        "fr" => "Une API pour le calendrier liturgique à partir de laquelle vous pouvez récupérer des données pour les événements liturgiques d'une année donnée à partir de 1970, que ce soit pour le calendrier romain universel ou général ou pour les calendriers nationaux et diocésains dérivés",
        "it" => "Una API per il Calendario Liturgico, da cui estrarre i dati degli eventi liturgici di un qualsiasi anno dal 1970 in poi, sia per il Calendario Romano Universale che per i calendari nazionali e diocesani derivati",
        "pt" => "Uma API para o calendário litúrgico do qual você pode recuperar dados para os eventos litúrgicos de qualquer ano a partir de 1970, seja para o calendário romano universal ou geral ou para calendários nacionais e diocesanos derivados"
    ]
]);

?>

<!doctype html>
<html lang="<?php echo LITCAL_LOCALE; ?>">
<head>
    <title><?php _e("General Roman Calendar") ?></title>
    <?php include_once('./layout/head.php'); ?>
</head>
<body>

    <?php include_once('./layout/header.php'); ?>

        <!-- Page Heading -->
        <h1 class="h3 mb-2 text-gray-800"><?php _e("Catholic Liturgical Calendar"); ?></h1>
        <p class="mb-4"><?php _e("API_DESCRIPTION") ?></p>

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

    <?php include_once('./layout/footer.php'); ?>

</body>
</html>
