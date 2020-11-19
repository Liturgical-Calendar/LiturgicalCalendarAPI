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
<?php
    if(isset($_GET["choice"])){
        switch($_GET["choice"]){
            case "national":
                ?>
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
                <?php
            break;
            case "diocesan":
                ?>
                <nav aria-label="Diocesan calendar definition">
                <ul class="pagination pagination-lg justify-content-center">
                    <li class="page-item disabled">
                        <a class="page-link" href="#" tabindex="-1" aria-disabled="true" aria-labeled="Previous"><span aria-hidden="true">&laquo;</span></a>
                    </li>
                    <li class="page-item active"><a class="page-link" href="#">Solemnities</a></li>
                    <li class="page-item"><a class="page-link" href="#">Feasts</a></li>
                    <li class="page-item"><a class="page-link" href="#">Memorials</a></li>
                    <li class="page-item"><a class="page-link" href="#">Optional Memorials</a></li>
                    <li class="page-item">
                        <a class="page-link" href="#" aria-label="Next"><span aria-hidden="true">&raquo;</span></a>
                    </li>
                </ul>
                </nav>

                <div id="carouselExampleIndicators" class="carousel slide" data-interval="false">
                    <ol class="carousel-indicators">
                        <li data-target="#carouselExampleIndicators" data-slide-to="0" class="active"></li>
                        <li data-target="#carouselExampleIndicators" data-slide-to="1" class=""></li>
                        <li data-target="#carouselExampleIndicators" data-slide-to="2" class=""></li>
                        <li data-target="#carouselExampleIndicators" data-slide-to="3" class=""></li>
                    </ol>
                    <div class="carousel-inner">
                        <div class="carousel-item active">
                            <div class="container">
                                <div class="card border-left-primary m-5">
                                    <div class="card-header py-3">
                                        <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-place-of-worship fa-2x text-gray-300 mr-4"></i><?php _e("Generate Diocesan Calendar"); ?>: <?php _e("Define the Solemnities"); ?></h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="row no-gutters align-items-center">
                                            <div class="col mr-2">
                                                <form>
                                                    <h4><?php _e("Principal Patron(s) of the Place, Diocese, Region, Province or Territory"); ?></h4>
                                                    <div class="form-row">
                                                        <div class="form-group col-sm-5">
                                                            <label for="diocesanPatronSolemnityName">Name</label><input type="text" class="form-control" id="diocesanPatronSolemnityName" />
                                                        </div>
                                                        <div class="form-group col-sm-1">
                                                            <label for="diocesanPatronSolemnityDay">Day</label><input type="number" min=1 max=31 value=1 class="form-control" id="diocesanPatronSolemnityDay" />
                                                        </div>
                                                        <div class="form-group col-sm-2">
                                                            <label for="diocesanPatronSolemnityDay">Month</label>
                                                            <select class="form-control" id="diocesanPatronSolemnityMonth">
                                                                <option value=1>January</option>
                                                                <option value=2>February</option>
                                                                <option value=3>March</option>
                                                                <option value=4>April</option>
                                                                <option value=5>May</option>
                                                                <option value=6>June</option>
                                                                <option value=7>July</option>
                                                                <option value=8>August</option>
                                                                <option value=9>September</option>
                                                                <option value=10>October</option>
                                                                <option value=11>November</option>
                                                                <option value=12>December</option>
                                                            </select>
                                                        </div>
                                                        <div class="form-group col-sm-3">
                                                            <label for="diocesanPatronSolemnityColor">Liturgical color</label>
                                                            <select class="form-control" id="diocesanPatronSolemnityColor" />
                                                                <option value="WHITE">WHITE</option>
                                                                <option value="RED">RED</option>
                                                                <option value="PURPLE">PURPLE</option>
                                                                <option value="GREEN">GREEN</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <h4><?php _e("Dedication of the Cathedral"); ?></h4>
                                                    <div class="form-row">
                                                        <div class="form-group col-sm-5">
                                                            <label for="diocesanCathedralSolemnityName">Name</label><input type="text" class="form-control" id="diocesanCathedralSolemnityName" />
                                                        </div>
                                                        <div class="form-group col-sm-1">
                                                            <label for="diocesanCathedralSolemnityDay">Day</label><input type="number" min=1 max=31 value=1 class="form-control" id="diocesanCathedralSolemnityDay" />
                                                        </div>
                                                        <div class="form-group col-sm-2">
                                                            <label for="diocesanCathedralSolemnityMonth">Month</label>
                                                            <select class="form-control" id="diocesanCathedralSolemnityMonth">
                                                                <option value=1>January</option>
                                                                <option value=2>February</option>
                                                                <option value=3>March</option>
                                                                <option value=4>April</option>
                                                                <option value=5>May</option>
                                                                <option value=6>June</option>
                                                                <option value=7>July</option>
                                                                <option value=8>August</option>
                                                                <option value=9>September</option>
                                                                <option value=10>October</option>
                                                                <option value=11>November</option>
                                                                <option value=12>December</option>
                                                            </select>
                                                        </div>
                                                        <div class="form-group col-sm-3">
                                                            <label for="diocesanCathedralSolemnityColor">Liturgical color</label>
                                                            <select class="form-control" id="diocesanCathedralSolemnityColor" disabled />
                                                                <option value="WHITE" selected>WHITE</option>
                                                                <option value="RED">RED</option>
                                                                <option value="PURPLE">PURPLE</option>
                                                                <option value="GREEN">GREEN</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="carousel-item">
                            <div class="container">
                                <div class="card border-left-primary m-5">
                                    <div class="card-header py-3">
                                        <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-place-of-worship fa-2x text-gray-300 mr-4"></i><?php _e("Generate Diocesan Calendar"); ?>: <?php _e("Define the Feasts"); ?></h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="row no-gutters align-items-center">
                                            <div class="col mr-2">
                                                <form>
                                                    <h4>:</h4>
                                                    <label><?php _e("Patron of the Diocese"); ?><br><input type="text" id="diocesanPatronFeast" /></label>
                                                    <label><?php _e("Dedication of the Cathedral"); ?><br><input type="text" id="diocesanCathedralFeast" /></label>
                                                    <label><?php _e("Patron of the Region or Province or Territory"); ?><br><input type="text" id="diocesanCathedralFeast" /></label>
                                                    <label><?php _e("Other Feast"); ?><br><input type="text" id="diocesanCathedralFeast" /></label>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="carousel-item">
                            <div class="container">
                                <div class="card border-left-primary m-5">
                                    <div class="card-header py-3">
                                        <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-place-of-worship fa-2x text-gray-300 mr-4"></i><?php _e("Generate Diocesan Calendar"); ?>: <?php _e("Define the Memorials"); ?></h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="row no-gutters align-items-center">
                                            <div class="col mr-2">
                                                <form>
                                                    <h4>:</h4>
                                                    <label><?php _e("Secondary patron of the place, the diocese, the region"); ?><br><input type="text" id="diocesanPatronMemorial" /></label>
                                                    <label><?php _e("Other Memorial"); ?><br><input type="text" id="diocesanOtherMemorial" /></label>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="carousel-item">
                            <div class="container">
                                <div class="card border-left-primary m-5">
                                    <div class="card-header py-3">
                                        <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-place-of-worship fa-2x text-gray-300 mr-4"></i><?php _e("Generate Diocesan Calendar"); ?>: <?php _e("Define the Optional Memorials"); ?></h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="row no-gutters align-items-center">
                                            <div class="col mr-2">
                                                <form>
                                                    <h4>:</h4>
                                                    <label><?php _e("Saints the veneration of which is local to the place, the diocese, the region"); ?><br><input type="text" id="diocesanOptMemorial" /></label>
                                                    <label><?php _e("Other Optional Memorial"); ?><br><input type="text" id="diocesanOtherOptMemorial" /></label>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <a class="carousel-control-prev" href="#carouselExampleIndicators" role="button" data-slide="prev">
                        <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                        <span class="sr-only">Previous</span>
                    </a>
                    <a class="carousel-control-next" href="#carouselExampleIndicators" role="button" data-slide="next">
                        <span class="carousel-control-next-icon" aria-hidden="true"></span>
                        <span class="sr-only">Next</span>
                    </a>
                </div>                
                <form>
                    <div class="form-group">
                        <label for="exampleInputEmail1">Email address</label>
                        <input type="email" class="form-control" id="exampleInputEmail1" aria-describedby="emailHelp" placeholder="Enter email">
                        <small id="emailHelp" class="form-text text-muted">We'll never share your email with anyone else.</small>
                    </div>
                    <div class="form-group">
                        <label for="exampleInputPassword1">Password</label>
                        <input type="password" class="form-control" id="exampleInputPassword1" placeholder="Password">
                    </div>
                    <div class="form-group form-check">
                        <input type="checkbox" class="form-check-input" id="exampleCheck1">
                        <label class="form-check-label" for="exampleCheck1">Check me out</label>
                    </div>
                    <button type="submit" class="btn btn-primary">Submit</button>
                </form>
                <?php
            break;
        }
    }
?>
<?php include_once('./layout/footer.php'); ?>
<script>
    jQuery(document).ready(function(){
        $('.carousel').carousel();
    });
</script>
</body>
</html>
