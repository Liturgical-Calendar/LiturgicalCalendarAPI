<?php

include_once("./i18n.php");

/**
 * Define our translation strings
*/
$messages = array_merge($messages, [
    "Liturgical Calendar - About us" => [
        "de" => "Liturgischer Kalender - Über uns",
        "en" => "Liturgical Calendar - About us",
        "es" => "Calendario litúrgico - Sobre nosotros",
        "fr" => "Calendrier liturgique - À propos de nous",
        "it" => "Calendario liturgico - Chi siamo",
        "pt" => "Calendário litúrgico - Sobre nós",
    ],
    "DONJOHN" => [
        "de" => "<a href=\"https://www.johnromanodorazio.com\">Rev. John Romano D'Orazio</a> - Priester in der Diözese Rom, Autodidakt Programmierer, Autor des BibleGet-Projekts",
        "en" => "<a href=\"https://www.johnromanodorazio.com\">Fr. John Romano D'Orazio</a> - priest in the Diocese of Rome, self-taught programmer, author of the BibleGet project",
        "es" => "<a href=\"https://www.johnromanodorazio.com\">Rev. John Romano D'Orazio</a> - sacerdote de la diócesis de Roma, programador autodidacta, autor del proyecto BibleGet",
        "fr" => "<a href=\"https://www.johnromanodorazio.com\">Rev. John Romano D'Orazio</a> - prêtre du diocèse de Rome, programmeur autodidacte, auteur du projet BibleGet",
        "it" => "<a href=\"https://www.johnromanodorazio.com\">don John Romano D'Orazio</a> - sacerdote della Diocesi di Roma, programmatore auto-didatta, autore del progetto BibleGet",
        "pt" => "<a href=\"https://www.johnromanodorazio.com\">Rev. John Romano D'Orazio</a> - sacerdote da Diocese de Roma, programador autodidata, autor do projeto BibleGet"
    ],
    "MIKETRUSO" => [
        "de" => "<a href=\"https://www.miketruso.com/\">Mike Truso</a> - Softwareentwickler mit Sitz in St. Paul, MN (USA), Mitbegründer bei JobPost, Computeringenieur bei Agile Orbit, Gründer der Guild of Saint Isidore für katholische IT-Profis",
        "en" => "<a href=\"https://www.miketruso.com/\">Mike Truso</a> - Software Developer based in St. Paul, MN (USA), Co-Founder at JobPost, Senior Software Engineer at Agile Orbit, founder of the St. Isidore Guild for Catholic IT Professionals",
        "es" => "<a href=\"https://www.miketruso.com/\">Mike Truso</a> - Desarrollador de software con sede en St. Paul, MN (USA), Co-Fundador en JobPost, Ingeniero en Computación en Agile Orbit, Fundador del Gremio de San Isidoro para profesionales católicos de TI",
        "fr" => "<a href=\"https://www.miketruso.com/\">Mike Truso</a> - Développeur logiciel basé à St.Paul, MN (USA), Co-Fondateur chez JobPost, Software Engineer chez Agile Orbit, Fondateur de la Guilde de Saint Isidore pour les Professionnels Informatiques Catholiques",
        "it" => "<a href=\"https://www.miketruso.com/\">Mike Truso</a> - Sviluppatore software con sede a St. Paul, MN (USA), Co-Fondatore presso JobPost, Ingegnere Informatico presso Agile Orbit, Fondatore della Gilda di Sant'Isidoro per Professionisti IT Cattolici",
        "pt" => "<a href=\"https://www.miketruso.com/\">Mike Truso</a> - Desenvolvedor de software baseado em St. Paul, MN (USA), Cofundador da JobPost, engenheiro de computação da Agile Orbit, Fundador da Guilda de Santo Isidoro para Profissionais Católicos de TI"
    ],
    "MICHAELSHELTON" => [
        "de" => "Michael Shelton - Full-Stack-Webentwickler",
        "en" => "Michael Shelton - Full stack web developer",
        "es" => "Michael Shelton - Desarrollador web full stack",
        "fr" => "Michael Shelton - Développeur web full stack",
        "it" => "Michael Shelton - Sviluppatore web full stack",
        "pt" => "Michael Shelton - Desenvolvedor web full stack"
    ],
    "ABOUT_US" => [
        "de" => "Das liturgische Kalenderprojekt wird von einer Gruppe freiwilliger katholischer Programmierer kuratiert, die der Kirche dienen möchten.",
        "en" => "The Liturgical Calendar project is curated by a group of volunteer catholic programmers, seeking to serve the Church.",
        "es" => "El proyecto del Calendario Litúrgico está comisariado por un grupo de programadores católicos voluntarios que buscan servir a la Iglesia.",
        "fr" => "Le projet du calendrier liturgique est organisé par un groupe de programmeurs catholiques bénévoles, cherchant à servir l'Église.",
        "it" => "Il progetto del Calendario liturgico è curato da un gruppo di programmatori cattolici volontari, che cercano di servire la Chiesa.",
        "pt" => "O projeto do Calendário Litúrgico é organizado por um grupo de programadores católicos voluntários, que buscam servir a Igreja."
    ]
]);

?>

<!doctype html>
<html lang="<?php echo LITCAL_LOCALE; ?>">
<head>
    <title><?php _e("Liturgical Calendar - About us") ?></title>
    <?php include_once('./layout/head.php'); ?>
</head>
<body>

    <?php include_once('./layout/header.php'); ?>

        <!-- Page Heading -->
        <h1 class="h3 mb-2 text-gray-800"><?php _e("Liturgical Calendar - About us"); ?></h1>
        <p><?php _e("ABOUT_US"); ?></p>

        <div class="row">
            <div class="col-md-6">
                <div class="card border-left-success shadow m-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="row no-gutters align-items-center">
                                    <div class="col-auto mr-2"><img class="img-profile rounded-circle mx-auto img-fluid" src="./assets/img/donjohn_125x125.jpg"></div>
                                    <div class="col"><?php _e("DONJOHN") ?></div>
                                </div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-cross fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card border-left-success shadow m-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="row no-gutters align-items-center">
                                    <div class="col-auto mr-2"><img class="img-profile rounded-circle mx-auto img-fluid" src="./assets/img/miketruso_125x125.jpg"></div>
                                    <div class="col"><?php _e("MIKETRUSO") ?></div>
                                </div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-code fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <div class="row">
            <div class="col-md-6">
                <div class="card border-left-success shadow m-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="row no-gutters align-items-center">
                                    <div class="col-auto mr-2"><img class="img-profile rounded-circle mx-auto img-fluid" src="./assets/img/michaelshelton_125x125.jpg"></div>
                                    <div class="col"><?php _e("MICHAELSHELTON") ?></div>
                                </div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-code fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card border-left-success shadow m-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="row no-gutters align-items-center">
                                    <div class="col-auto mr-2"><img class="img-profile rounded-circle mx-auto img-fluid" src="./assets/img/easter-egg-5-120-279148.png"></div>
                                    <div class="col"><?php _e("ANOTHERVOLUNTEER") ?></div>
                                </div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-code fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>

    <?php include_once('./layout/footer.php'); ?>

</body>
</html>
