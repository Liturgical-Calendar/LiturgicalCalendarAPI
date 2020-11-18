<?php

include_once("../i18n.php");

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
];

?>

<!doctype html>
<html lang="<?php echo LITCAL_LOCALE; ?>">
<head>
    <title><?php _e("General Roman Calendar",LITCAL_LOCALE) ?></title>
    <?php include_once('layout/head.php'); ?>
</head>
<body>

    <?php include_once('layout/header.php'); ?>

        <!-- Page Heading -->
        <h1 class="h3 mb-2 text-gray-800"><?php _e("Extend the Calendar with National or Diocesan data",LITCAL_LOCALE); ?></h1>
        <p class="mb-4"><?php _e("API_EXTEND_HOWTO",LITCAL_LOCALE) ?></p>

    <?php include_once('layout/footer.php'); ?>

</body>
</html>
