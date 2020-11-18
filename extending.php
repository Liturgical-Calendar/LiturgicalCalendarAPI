<?php

include_once("./i18n.php");

/**
 * Define our translation strings
*/
$messages = array_merge($messages, [
    "General Roman Calendar - Extending" => [
        "de" => "Allgemeiner Römischer Kalender - Erweiterung",
        "en" => "General Roman Calendar - Extending",
        "es" => "Calendario Romano General - Extensión",
        "fr" => "Calendrier Général Romain - Extension",
        "it" => "Calendario Romano Generale - Estensione",
        "pt" => "Calendário Romano Geral - Extensão"
    ],
    "Extend the General Roman Calendar with National or Diocesan data" => [
        "de" => "Erweitern Sie den Allgemeinen Römischen Kalender um nationale oder diözesane Daten",
        "en" => "Extend the General Roman Calendar with National or Diocesan data",
        "es" => "Ampliar el Calendario Romano General con datos nacionales o diocesanos",
        "fr" => "Étendre le calendrier général romain avec des données nationales ou diocésaines",
        "it" => "Estendi il calendario romano generale con dati nazionali o diocesani",
        "pt" => "Amplie o calendário romano geral com dados nacionais ou diocesanos"
    ],
    "API_EXTEND_HOWTO" => [
        "de" => "<p>Der Allgemeine Römische Kalender kann erweitert werden, um einen Nationalen oder Diözesankalender zu erstellen. Diözesankalender hängen von nationalen Kalendern ab, daher muss zuerst der nationale Kalender erstellt werden.</p><p>Nationale Kalender müssen anhand von Daten aus der Übersetzung des in der Region verwendeten römischen Messbuchs oder in jedem Fall aus Dekreten der Bischofskonferenz der Region definiert werden.</p>",
        "en" => "<p>The General Roman Calendar can be extended so as to create a National or Diocesan calendar. Diocesan calendars depend on National calendars, so the National calendar must first be created.</p><p>National calendars must be defined using data from the translation of the Roman Missal used in the Region or in any case from decrees of the Episcopal Conference of the Region.</p>",
        "es" => "<p>El Calendario Romano General se puede ampliar para crear un calendario Nacional o Diocesano. Los calendarios diocesanos dependen de los calendarios nacionales, por lo que primero se debe crear el calendario nacional.</p><p>Los calendarios nacionales deben definirse utilizando datos de la traducción del Misal Romano utilizado en la Región o en cualquier caso de los decretos de la Conferencia Episcopal de la Región.</p>",
        "fr" => "<p>Le calendrier général romain peut être prolongé de manière à créer un calendrier national ou diocésain. Les calendriers diocésains dépendent des calendriers nationaux, de sorte que le calendrier national doit d'abord être créé.</p><p>Les calendriers nationaux doivent être définis à partir des données de la traduction du Missel romain utilisé dans la Région ou en tout cas des décrets de la Conférence épiscopale de la Région.</p>",
        "it" => "<p>Il Calendario Romano Generale può essere esteso in modo da creare un calendario nazionale o diocesano. I calendari diocesani dipendono dai calendari nazionali, quindi è necessario prima creare il calendario nazionale.</p><p>I calendari nazionali devono essere definiti utilizzando i dati della traduzione del Messale Romano utilizzata nella Regione o comunque da decreti della Conferenza Episcopale della Regione.</p>",
        "pt" => "<p>O calendário romano geral pode ser ampliado para criar um calendário nacional ou diocesano. Os calendários diocesanos dependem dos calendários nacionais, portanto, o calendário nacional deve primeiro ser criado.</p><p>Os calendários nacionais devem ser definidos a partir de dados da tradução do Missal Romano usado na Região ou em qualquer caso de decretos da Conferência Episcopal da Região</p>."
    ],
    "Generate National Calendar" => [
        "de" => "Generieren Sie einen nationalen Kalender",
        "en" => "Generate National Calendar",
        "es" => "Genera Calendario Nacional",
        "fr" => "Génére un calendrier national",
        "it" => "Genera Calendario Nazionale",
        "pt" => "Gera calendário nacional"
    ],
    "Generate Diocesan Calendar" => [
        "de" => "Generieren Sie einen nationalen Kalender",
        "en" => "Generate diocesan calendar",
        "es" => "Genera calendario diocesano",
        "fr" => "Génére un calendrier diocésain",
        "it" => "Genera calendario diocesano",
        "pt" => "Gera calendário diocesano"
    ]
]);

?>

<!doctype html>
<html lang="<?php echo LITCAL_LOCALE; ?>">
<head>
    <title><?php _e("General Roman Calendar - Extending") ?></title>
    <?php include_once('./layout/head.php'); ?>
</head>
<body>

    <?php include_once('./layout/header.php'); ?>

        <!-- Page Heading -->
        <h1 class="h3 mb-2 text-gray-800"><?php _e("Extend the General Roman Calendar with National or Diocesan data"); ?></h1>
        <p class="mb-4"><?php _e("API_EXTEND_HOWTO") ?></p>

        <!-- Content Row -->
        <div class="row">
            <div class="col-md-6">
                <div class="card border-left-primary shadow m-2">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary"><?php _e("Generate National Calendar"); ?></h6>
                    </div>
                    <div class="card-body">
                        <div class="row no-gutters align-items-center"></div>
                        <div class="col-auto">
                            <i class="fas fa-flag fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card border-left-primary shadow m-2">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary"><?php _e("Generate Diocesan Calendar"); ?></h6>
                    </div>
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <form>
                                    <h4><?php _e("Define the Solemnities"); ?>:</h4>
                                    <label><?php _e("Patron of the Place"); ?><br><input type="text" id="diocesanPatronSolemnity" /></label>
                                    <label><?php _e("Dedication of the Cathedral"); ?><br><input type="text" id="diocesanCathedralSolemnity" /></label>

                                    <h4><?php _e("Define the Feasts"); ?>:</h4>
                                    <label><?php _e("Patron of the Diocese"); ?><br><input type="text" id="diocesanPatronFeast" /></label>
                                    <label><?php _e("Dedication of the Cathedral"); ?><br><input type="text" id="diocesanCathedralFeast" /></label>
                                    <label><?php _e("Patron of the Region or Province or Territory"); ?><br><input type="text" id="diocesanCathedralFeast" /></label>
                                    <label><?php _e("Other Feast"); ?><br><input type="text" id="diocesanCathedralFeast" /></label>
                                    
                                    <h4><?php _e("Define the Memorials"); ?>:</h4>
                                    <label><?php _e("Secondary patron of the place, the diocese, the region"); ?><br><input type="text" id="diocesanPatronFeast" /></label>
                                    <label><?php _e("Other Memorial"); ?><br><input type="text" id="diocesanCathedralFeast" /></label>
                                </form>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-place-of-worship fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>


    <?php include_once('./layout/footer.php'); ?>

</body>
</html>
