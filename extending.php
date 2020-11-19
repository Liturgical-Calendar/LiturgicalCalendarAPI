<?php

include_once("./i18n.php");
include_once("./layout/formcontrols.php");
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
                <div class="container">
                    <form class="row justify-content-center">
                        <div class="form-group col col-md-3">
                            <label for="diocesanCalendarNationalDependency" class="font-weight-bold">Depends on national calendar:</label>
                            <select class="form-control" id="diocesanCalendarNationalDependency" aria-describedby="emailHelp" required>
                                <option value=""></option>
                                <option value="ITALIA">Italy</option>
                                <option value="USA">USA</option>
                            </select>
                            <!-- <small id="emailHelp" class="form-text text-muted">We'll never share your email with anyone else.</small> -->
                        </div>
                        <div class="form-group col col-md-4">
                            <label for="diocesanCalendarBehaviour" class="font-weight-bold">Overwrites universal / national calendar</label>
                            <input type="checkbox" class="form-control" data-toggle="toggle" id="diocesanCalendarBehaviour" aria-describedby="diocesanCalendarBehaviourHelp">
                            <small id="diocesanCalendarBehaviourHelp" class="form-text text-muted">The default behaviour for a diocesan calendar is to juxtapose the local celebrations alongside those of the universal and the national calendar. If instead the diocesan calendar should override the universal calendar, turn this option on.</small>
                        </div>
                    </form>
                </div>
                <nav aria-label="Diocesan calendar definition" id="diocesanCalendarDefinitionCardLinks">
                    <ul class="pagination pagination-lg justify-content-center">
                        <li class="page-item disabled">
                            <a class="page-link diocesan-carousel-prev" href="#" tabindex="-1" aria-disabled="true" aria-labeled="Previous"><span aria-hidden="true">&laquo;</span></a>
                        </li>
                        <li class="page-item active"><a class="page-link" href="#" data-slide-to="0"><?php _e("Solemnities"); ?></a></li>
                        <li class="page-item"><a class="page-link" href="#" data-slide-to="1"><?php _e("Feasts"); ?></a></li>
                        <li class="page-item"><a class="page-link" href="#" data-slide-to="2"><?php _e("Memorials"); ?></a></li>
                        <li class="page-item"><a class="page-link" href="#" data-slide-to="3"><?php _e("Optional memorials"); ?></a></li>
                        <li class="page-item">
                            <a class="page-link diocesan-carousel-next" href="#" aria-label="Next"><span aria-hidden="true">&raquo;</span></a>
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
                        <div class="carousel-item active" id="carouselItemSolemnities">
                            <div class="container">
                                <div class="card border-left-primary m-5">
                                    <div class="card-header py-3">
                                        <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-place-of-worship fa-2x text-gray-300 mr-4"></i><?php _e("Generate Diocesan Calendar"); ?>: <?php _e("Define the Solemnities"); ?></h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="row no-gutters align-items-center">
                                            <div class="col mr-2">
                                                <form>
                                                    <?php FormControls::CreateFestivityRow(__("Principal Patron(s) of the Place, Diocese, Region, Province or Territory")) ?>
                                                    <?php FormControls::CreateFestivityRow(__("Dedication of the Cathedral")) ?>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="carousel-item" id="carouselItemFeasts">
                            <div class="container">
                                <div class="card border-left-primary m-5">
                                    <div class="card-header py-3">
                                        <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-place-of-worship fa-2x text-gray-300 mr-4"></i><?php _e("Generate Diocesan Calendar"); ?>: <?php _e("Define the Feasts"); ?></h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="row no-gutters align-items-center">
                                            <div class="col mr-2">
                                                <form>
                                                    <?php FormControls::CreateFestivityRow(__("Patron(s) of the Place, Diocese, Region, Province or Territory")) ?>
                                                    <?php FormControls::CreateFestivityRow(__("Dedication of the Cathedral")) ?>
                                                    <?php FormControls::CreateFestivityRow(__("Other Feast")) ?>
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
                                                    <?php FormControls::CreateFestivityRow(__("Secondary patron(s) of the Place, Diocese, Region, Province or Territory")) ?>
                                                    <?php FormControls::CreateFestivityRow(__("Other Memorial")) ?>
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
                                                    <?php FormControls::CreateFestivityRow(__("Saints whos veneration is local to the Place, Diocese, Region, Province or Territory")) ?>
                                                    <?php FormControls::CreateFestivityRow(__("Other Optional Memorial")) ?>
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
        let $carousel = $('.carousel').carousel();
        $(document).on('click','#diocesanCalendarDefinitionCardLinks a.page-link', function(event){
            event.preventDefault();
            $('#diocesanCalendarDefinitionCardLinks li').removeClass('active');
            //console.log("you clicked " + $(this).text());
            if( $(this).hasClass('diocesan-carousel-next') ){
                $carousel.carousel('next');
            } else if ( $(this).hasClass('diocesan-carousel-prev') ){
                $carousel.carousel('prev');
            } else {
                $(this).parent('li').addClass('active');
                $carousel.carousel(parseInt( $(this).attr('data-slide-to') ));
            }
        });

        $carousel.on('slide.bs.carousel', function(event){
            $('#diocesanCalendarDefinitionCardLinks li').removeClass('active');
            if(event.to == 0){
                $('#diocesanCalendarDefinitionCardLinks li:first-child').addClass('disabled');
                $('#diocesanCalendarDefinitionCardLinks li:last-child').removeClass('disabled');
            } else if (event.to == 3){
                $('#diocesanCalendarDefinitionCardLinks li:last-child').addClass('disabled');
                $('#diocesanCalendarDefinitionCardLinks li:first-child').removeClass('disabled');
            } else {
                $('#diocesanCalendarDefinitionCardLinks li:first-child').removeClass('disabled');
                $('#diocesanCalendarDefinitionCardLinks li:last-child').removeClass('disabled');
            }
            $('#diocesanCalendarDefinitionCardLinks li').find('[data-slide-to='+event.to+']').parent('li').addClass('active');
        });
    });
</script>
</body>
</html>
