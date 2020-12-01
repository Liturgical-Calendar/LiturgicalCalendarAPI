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
    ],
    "Calculation of the Date of Easter" => [
        "de" => "Berechnung des Osterdatums",
        "en" => "Calculation of the Date of Easter",
        "es" => "Cálculo de la fecha de Pascua",
        "fr" => "Calcul de la date de Pâques",
        "it" => "Calcolo della data della Pasqua",
        "pt" => "Cálculo da Data da Páscoa",
    ],
    "Calculate the Date of Easter" => [
        "de" => "Berechnen Sie das Osterdatums",
        "en" => "Calculate the Date of Easter",
        "es" => "Calcula la fecha de Pascua",
        "fr" => "Calculez la date de Pâques",
        "it" => "Calcola la data della Pasqua",
        "pt" => "Calcule a data da Páscoa",
    ],
    "EASTER_CALCULATOR_API" => [
        "de" => "Ein einfacher API-Endpunkt, der Daten zum Osterdatum zurückgibt, sowohl Gregorianisch als auch Julianisch, " .
                "aus dem Jahr 1583 (Jahr der Annahme des Gregorianischen Kalenders) bis 9999 (maximal mögliche Datumsberechnung in 64-Bit-PHP) " .
                "unter Verwendung einer PHP-Anpassung des Meeus / Jones / Butcher-Algorithmus für Gregorianische Ostern (beobachtet von der römisch-katholischen Kirche) " .
                "und des Meeus-Algorithmus für Julian Easter (von orthodoxen Kirchen beobachtet)",
        "en" => "A simple API endpoint that returns data about the Date of Easter, both Gregorian and Julian, " .
                "from 1583 (year of the adoption of the Gregorian Calendar) to 9999 (maximum possible date calculation in 64bit PHP), " .
                "using a PHP adaptation of the Meeus/Jones/Butcher algorithm for Gregorian easter (observed by the Roman Catholic church) " .
                "and of the Meeus algorithm for Julian easter (observed by orthodox churches)",
        "es" => "Un punto final de API simple que devuelve datos sobre la fecha de Pascua, tanto gregoriana como juliana, " .
                "desde 1583 (año de la adopción del calendario gregoriano) hasta 9999 (cálculo de la fecha máxima posible en PHP de 64 bits), " .
                "utilizando una adaptación PHP del algoritmo Meeus / Jones / Butcher para la Pascua gregoriana (observado por la iglesia católica romana) " .
                "y del algoritmo Meeus para la Pascua juliana (observado por las iglesias ortodoxas)",
        "fr" => "Un point de terminaison d'API simple qui renvoie des données sur la date de Pâques, à la fois grégorienne et julienne, " .
                "de 1583 (année d'adoption du calendrier grégorien) à 9999 (calcul de la date maximale possible en PHP 64 bits) , " .
                "en utilisant une adaptation PHP de l'algorithme Meeus / Jones / Butcher pour les Pâques grégoriens (observé par l'église catholique romaine) " .
                "et de l'algorithme Meeus pour les Pâques de Julian (observé par les églises orthodoxes)",
        "it" => "Una API che semplicemente restituisce dati riguardo alla data della Pasqua, sia Gregoriana che Giuliana, " .
                "dall'anno 1583 (anno di adozione del Calendario Gregoriano) al 9999 (massima data calcolabile nel PHP a 64 bit), " .
                "utilizzando un adattamento PHP dell'algoritmo Meeus/Jones/Butcher per il calcolo della Pasqua Gregoriana (celebrata dalla Chiesa Romana) " .
                "e dell'algoritmo di Meeus per il calcolo della Pasqua Giuliana (celebrata dalle chiese ortodosse)",
        "pt" => "Um endpoint de API simples que retorna dados sobre a data da Páscoa, tanto Gregoriana quanto Juliana, " .
                "de 1583 (ano de adoção do Calendário Gregoriano) a 9999 (cálculo de data máxima possível em PHP de 64 bits), " .
                "usando uma adaptação PHP do algoritmo Meeus / Jones / Butcher para a Páscoa Gregoriana (observada pela Igreja Católica Romana) " .
                "e do algoritmo Meeus para a Páscoa Juliana (observada por igrejas ortodoxas)"
    ],
    "Data Generation Endpoint" => [
        "de" => "Datengenerierungsendpunkt",
        "en" => "Data Generation Endpoint",
        "es" => "URL de generación de datos",
        "fr" => "URL de génération de données",
        "it" => "Endpoint di generazione dei dati",
        "pt" => "Ponto final de geração de dados"
    ],
    "View API endpoint" => [
        "de" => "Zeigen Sie den API-Endpunkt an",
        "en" => "Data Generation Endpoint",
        "es" => "Ispecciona el punto final de la API",
        "fr" => "Affiche le point de terminaison de l'API",
        "it" => "Visualizza l'endpoint dell'API",
        "pt" => "Veja o endpoint da API"
    ],
    "Date of Easter API endpoint" => [
        "de" => "Osterdatum API-Endpunkt",
        "en" => "Date of Easter API endpoint",
        "es" => "Fecha de Pascua API",
        "fr" => "Date de Pâques API",
        "it" => "API per la Data della Pasqua",
        "pt" => "Data da Páscoa API"
    ],
    "EASTER_CALCULATOR_EXAMPLE" => [
        "de" => "Beispielanzeige des Osterdatums von 1583 bis 9999",
        "en" => "Example display of the date of Easter from 1583 to 9999",
        "es" => "Ejemplo de visualización de la fecha de Pascua de 1583 a 9999",
        "fr" => "Exemple d'affichage de la date de Pâques de 1583 à 9999",
        "it" => "Esempio di visualizzazione della Data di Pasqua dal 1583 al 9999",
        "pt" => "Exemplo de exibição da data da Páscoa de 1583 a 9999"
    ]
]);

?>

<!doctype html>
<html lang="<?php echo LITCAL_LOCALE; ?>">
<head>
    <title><?php _e("General Roman Calendar") ?></title>
    <?php include_once('layout/head.php'); ?>
</head>
<body>

    <?php include_once('layout/header.php'); ?>

        <!-- Page Heading -->
        <h1 class="h3 mb-2 text-gray-800"><?php _e("Catholic Liturgical Calendar"); ?></h1>
        <p class="mb-4"><?php _e("API_DESCRIPTION") ?></p>

        <!-- Content Row -->
        <div class="row">
            <div class="col-md-6">
                <div class="card shadow m-2">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary"><?php _e("Data Generation Endpoint"); ?></h6>
                    </div>
                    <div class="card-body">
                        <p><i>A sample request to the endpoint could look like this:</i>
                        <code>/LitCalEngine.php?year=2020&amp;epiphany=SUNDAY_JAN2_JAN8&amp;ascension=SUNDAY&amp;corpuschristi=SUNDAY&amp;returntype=JSON&amp;locale=EN</code></p>
                        <div class="text-center"><a href="LitCalEngine.php" class="btn btn-primary"><?php _e("View API endpoint"); ?></a></div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card shadow m-2">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary"><?php _e("Calculation of the Date of Easter"); ?></h6>
                    </div>
                    <div class="card-body">
                        <p><?php _e("EASTER_CALCULATOR_API"); ?></p>
                        <div class="text-center"><a href="dateOfEaster.php" class="btn btn-primary m-2"><?php _e("Date of Easter API endpoint"); ?></a></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <div class="card shadow m-2">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary"><?php _e("Data Generation Endpoint"); ?></h6>
                    </div>
                    <div class="card-body">
                        <div class="text-center"><a href="dist/" class="btn btn-primary mt-2">Swagger / Open API Documentation</a></div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card shadow m-2">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary"><?php _e("Calculation of the Date of Easter"); ?></h6>
                    </div>
                    <div class="card-body">
                        <p><?php _e("EASTER_CALCULATOR_EXAMPLE"); ?></p>
                        <div class="text-center"><a href="easter.php" class="btn btn-primary m-2"><?php _e("Calculate the Date of Easter"); ?></a></div>
                    </div>
                </div>
            </div>
        </div>

    <?php include_once('layout/footer.php'); ?>

</body>
</html>
