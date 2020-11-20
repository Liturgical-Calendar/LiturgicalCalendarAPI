<?php

include_once("./i18n.php");
include_once("./layout/formcontrols.php");
$USDiocesesJSON = '{"Alabama":["Archdiocese of Mobile","Diocese of Birmingham"],"Alaska":["Archdiocese of Anchorage-Juneau","Diocese of Fairbanks"],"Arizona":["Holy Protection of Mary Byzantine Catholic Eparchy of Phoenix","Diocese of Phoenix","Diocese of Tucson"],"Arkansas":["Diocese of Little Rock"],"California":["Armenian Catholic Eparchy of Our Lady of Nareg in the USA & Canada","Chaldean Catholic Eparchy of St. Peter the Apostle","Archdiocese of Los Angeles","Archdiocese of San Francisco","Diocese of Fresno","Diocese of Monterey","Diocese of Oakland","Diocese of Orange","Diocese of Sacramento","Diocese of San Bernardino","Diocese of San Diego","Diocese of San Jose","Diocese of Santa Rosa","Diocese of Stockton"],"Colorado":["Archdiocese of Denver","Diocese of Colorado Springs","Diocese of Pueblo"],"Connecticut":["Ukrainian Catholic Eparchy of Stamford","Archdiocese of Hartford","Diocese of Bridgeport","Diocese of Norwich"],"Delaware":["Diocese of Wilmington"],"Florida":["Archdiocese of Miami","Diocese of Orlando","Diocese of Palm Beach","Diocese of Pensacola-Tallahassee","Diocese of St. Augustine","Diocese of St. Petersburg","Diocese of Venice"],"Georgia":["Archdiocese of Atlanta","Diocese of Savannah"],"Hawaii":["Diocese of Honolulu"],"Idaho":["Diocese of Boise"],"Illinois":["St. Nicholas of Chicago for Ukrainians","St. Thomas Syro Malabar Diocese of Chicago","Archdiocese of Chicago","Diocese of Belleville","Diocese of Joliet","Diocese of Peoria","Diocese of Rockford","Diocese of Springfield in Illinois"],"Indiana":["Archdiocese of Indianapolis","Diocese of Evansville","Diocese of Fort Wayne-South Bend","Diocese of Gary","Diocese of Lafayette in Indiana"],"Iowa":["Archdiocese of Dubuque","Diocese of Davenport","Diocese of Des Moines","Diocese of Sioux City"],"Kansas":["Archdiocese of Kansas City in Kansas","Diocese of Dodge City","Diocese of Salina","Diocese of Wichita"],"Kentucky":["Archdiocese of Louisville","Diocese of Covington","Diocese of Lexington","Diocese of Owensboro"],"Louisiana":["Archdiocese of New Orleans","Diocese of Alexandria","Diocese of Baton Rouge","Diocese of Houma-Thibodaux","Diocese of Lafayette in Louisiana","Diocese of Lake Charles","Diocese of Shreveport"],"Maine":["Diocese of Portland in Maine"],"Maryland":["Archdiocese of Baltimore"],"Massachusetts":["Eparchy of Newton","Archdiocese of Boston","Diocese of Fall River","Diocese of Springfield in Massachusetts","Diocese of Worcester"],"Michigan":["Chaldean Eparchy of Saint Thomas the Apostle","Archdiocese of Detroit","Diocese of Gaylord","Diocese of Grand Rapids","Diocese of Kalamazoo","Diocese of Lansing","Diocese of Marquette","Diocese of Saginaw"],"Minnesota":["Archdiocese of St. Paul and Minneapolis","Diocese of Crookston","Diocese of Duluth","Diocese of New Ulm","Diocese of St. Cloud","Diocese of Winona-Rochester"],"Mississippi":["Diocese of Biloxi","Diocese of Jackson"],"Missouri":["Maronite Eparchy of Our Lady of Lebanon","Archdiocese of St. Louis","Diocese of Jefferson City","Diocese of Kansas City-St. Joseph","Diocese of Springfield-Cape Girardeau"],"Montana":["Diocese of Great Falls-Billings","Diocese of Helena"],"Nebraska":["Archdiocese of Omaha","Diocese of Grand Island","Diocese of Lincoln"],"Nevada":["Diocese of Las Vegas","Diocese of Reno"],"New Hampshire":["Diocese of Manchester"],"New Jersey":["Byzantine Catholic Eparchy of Passaic","Eparchy of Our Lady of Deliverance Syriac Catholic Diocese in the USA","Archdiocese of Newark","Diocese of Camden","Diocese of Metuchen","Diocese of Paterson","Diocese of Trenton"],"New Mexico":["Archdiocese of Santa Fe","Diocese of Gallup","Diocese of Las Cruces"],"New York":["Eparchy of St. Maron of Brooklyn","Syro-Malankara Catholic Eparchy in the USA","Archdiocese of New York","Diocese of Albany","Diocese of Brooklyn","Diocese of Buffalo","Diocese of Ogdensburg","Diocese of Rochester","Diocese of Rockville Centre","Diocese of Syracuse"],"North Carolina":["Diocese of Charlotte","Diocese of Raleigh"],"North Dakota":["Diocese of Bismarck","Diocese of Fargo"],"Ohio":["Eparchy of Parma","Eparchy of St. George in Canton for the Romanians","Ukrainian Catholic Eparchy of St. Josaphat-Parma, OH","Archdiocese of Cincinnati","Diocese of Cleveland","Diocese of Columbus","Diocese of Steubenville","Diocese of Toledo","Diocese of Youngstown"],"Oklahoma":["Archdiocese of Oklahoma City","Diocese of Tulsa"],"Oregon":["Archdiocese of Portland in Oregon","Diocese of Baker"],"Pennsylvania":["Byzantine Catholic Archeparchy of Pittsburgh","Ukrainian Catholic Archeparchy of Philadelphia","Archdiocese of Philadelphia","Diocese of Allentown","Diocese of Altoona-Johnstown","Diocese of Erie","Diocese of Greensburg","Diocese of Harrisburg","Diocese of Pittsburgh","Diocese of Scranton"],"Rhode Island":["Diocese of Providence"],"South Carolina":["Diocese of Charleston"],"South Dakota":["Diocese of Rapid City","Diocese of Sioux Falls"],"Tennessee":["Diocese of Knoxville","Diocese of Memphis","Diocese of Nashville"],"Texas":["Archdiocese of Galveston-Houston","Archdiocese of San Antonio","Diocese of Amarillo","Diocese of Austin","Diocese of Beaumont","Diocese of Brownsville","Diocese of Corpus Christi","Diocese of Dallas","Diocese of El Paso","Diocese of Fort Worth","Diocese of Laredo","Diocese of Lubbock","Diocese of San Angelo","Diocese of The Personal Ordinariate of the Chair of St. Peter","Diocese of Tyler","Diocese of Victoria"],"Utah":["Diocese of Salt Lake City"],"Vermont":["Diocese of Burlington"],"Virgin Islands":["Diocese of St. Thomas, VI"],"Virginia":["Diocese of Arlington","Diocese of Richmond"],"Washington":["Archdiocese of Seattle","Diocese of Spokane","Diocese of Yakima"],"Washington DC":["Archdiocese of the Military Services","Archdiocese of Washington"],"West Virginia":["Diocese of Wheeling-Charleston"],"Wisconsin":["Archdiocese of Milwaukee","Diocese of Green Bay","Diocese of La Crosse","Diocese of Madison","Diocese of Superior"],"Wyoming":["Diocese of Cheyenne"]}';
$USStates = json_decode($USDiocesesJSON);
$USDioceses = [];
foreach($USStates as $state => $arr){
    foreach($arr as $idx => $diocese){
        $USDioceses[] = $diocese . " (" . $state . ")";
    }
}
sort($USDioceses);

$ITALYDioceses = ["Acerenza","Acerra","Acireale","Acqui","Adria - Rovigo","Agrigento","Alba","Albano","Albenga - Imperia","Ales - Terralba","Alessandria","Alghero - Bosa","Alife - Caiazzo","Altamura - Gravina - Acquaviva delle Fonti","Amalfi - Cava de' Tirreni","Anagni - Alatri","Ancona - Osimo","Andria","Aosta","Arezzo - Cortona - Sansepolcro","Ariano Irpino - Lacedonia","Ascoli Piceno","Assisi - Nocera Umbra - Gualdo Tadino","Asti","Avellino","Aversa","Avezzano","Bari - Bitonto","Belluno - Feltre","Benevento","Bergamo","Biella","Bologna","Bolzano - Bressanone, Bozen - Brixen","Brescia","Brindisi - Ostuni","Cagliari","Caltagirone","Caltanissetta","Camerino - San Severino Marche","Campobasso - Boiano","Capua","Carpi","Casale Monferrato","Caserta","Cassano all'Jonio","Castellaneta","Catania","Catanzaro - Squillace","Cefalù","Cerignola - Ascoli Satriano","Cerreto Sannita - Telese - Sant'Agata de' Goti","Cesena - Sarsina","Chiavari","Chieti - Vasto","Chioggia","Città di Castello","Civita Castellana","Civitavecchia - Tarquinia","Como","Concordia - Pordenone","Conversano - Monopoli","Cosenza - Bisignano","Crema","Cremona","Crotone - Santa Severina","Cuneo","Esarcato Apostolico per i fedeli cattolici ucraini di rito bizantino residenti in Italia","Fabriano - Matelica","Faenza - Modigliana","Fano - Fossombrone - Cagli - Pergola","Fermo","Ferrara - Comacchio","Fidenza","Fiesole","Firenze","Foggia - Bovino","Foligno","Forlì - Bertinoro","Fossano","Frascati","Frosinone - Veroli - Ferentino","Gaeta","Genova","Gorizia","Grosseto","Gubbio","Iglesias","Imola","Ischia","Isernia - Venafro","Ivrea","Jesi","La Spezia - Sarzana - Brugnato","Lamezia Terme","Lanciano - Ortona","Lanusei","L'Aquila","Latina - Terracina - Sezze - Priverno","Lecce","Livorno","Locri - Gerace","Lodi","Loreto","Lucca","Lucera - Troia","Lungro","Macerata - Tolentino - Recanati - Cingoli - Treia","Manfredonia - Vieste - San Giovanni Rotondo","Mantova","Massa Carrara - Pontremoli","Massa Marittima - Piombino","Matera - Irsina","Mazara del Vallo","Melfi - Rapolla - Venosa","Messina - Lipari - Santa Lucia del Mela","Milano","Mileto - Nicotera - Tropea","Modena - Nonantola","Molfetta - Ruvo - Giovinazzo - Terlizzi","Mondovì","Monreale","Monte Oliveto Maggiore","Montecassino","Montepulciano - Chiusi - Pienza","Montevergine","Napoli","Nardò - Gallipoli","Nicosia","Nocera Inferiore - Sarno","Nola","Noto","Novara","Nuoro","Oppido Mamertina - Palmi","Ordinariato Militare","Oria","Oristano","Orvieto - Todi","Ostia","Otranto","Ozieri","Padova","Palermo","Palestrina","Parma","Patti","Pavia","Perugia - Città della Pieve","Pesaro","Pescara - Penne","Pescia","Piacenza - Bobbio","Piana degli Albanesi","Piazza Armerina","Pinerolo","Pisa","Pistoia","Pitigliano - Sovana - Orbetello","Pompei","Porto - Santa Rufina","Potenza - Muro Lucano - Marsico Nuovo","Pozzuoli","Prato","Ragusa","Ravenna - Cervia","Reggio Calabria - Bova","Reggio Emilia - Guastalla","Rieti","Rimini","Roma","Rossano - Cariati","Sabina - Poggio Mirteto","Salerno - Campagna - Acerno","Saluzzo","San Benedetto del Tronto - Ripatransone - Montalto","San Marco Argentano - Scalea","San Marino - Montefeltro","San Miniato","San Severo","Santa Maria di Grottaferrata","Sant'Angelo dei Lombardi - Conza - Nusco - Bisaccia","Santissima Trinità di Cava de' Tirreni","Sassari","Savona - Noli","Senigallia","Sessa Aurunca","Siena - Colle di Val d'Elsa - Montalcino","Siracusa","Sora - Cassino - Aquino - Pontecorvo","Sorrento - Castellammare di Stabia","Spoleto - Norcia","Subiaco","Sulmona - Valva","Susa","Taranto","Teano - Calvi","Teggiano - Policastro","Tempio - Ampurias","Teramo - Atri","Termoli - Larino","Terni - Narni - Amelia","Tivoli","Torino","Tortona","Trani - Barletta - Bisceglie","Trapani","Trento","Treviso","Tricarico","Trieste","Trivento","Tursi - Lagonegro","Udine","Ugento - Santa Maria di Leuca","Urbino - Urbania - Sant'Angelo in Vado","Vallo della Lucania","Velletri - Segni","Venezia","Ventimiglia - San Remo","Vercelli","Verona","Vicenza","Vigevano","Viterbo","Vittorio Veneto","Volterra"];

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
    ],
    "Depends on national calendar" => [
        "de" => "Hängt vom nationalen Kalender ab",
        "en" => "Depends on national calendar",
        "es" => "Depende del calendario nacional",
        "fr" => "Dépend du calendrier national",
        "it" => "Dipende dal calendario nazionale",
        "pt" => "Depende do calendário nacional"
    ],
    "Diocese" => [
        "de" => "Diözese",
        "en" => "Diocese",
        "es" => "Diócesis",
        "fr" => "Diocèse",
        "it" => "Diocesi",
        "pt" => "Diocese"
    ],
    "Overwrites universal / national calendar" => [
        "de" => "Überschreibt den universellen / nationalen Kalender",
        "en" => "Overwrites universal / national calendar",
        "es" => "Sobrescribe el calendario nacional / universal",
        "fr" => "Remplace le calendrier universel / national",
        "it" => "Sovrascrive il calendario universale / nazionale",
        "pt" => "Substitui o calendário universal / nacional"
    ],
    "Define the Solemnities" => [
        "de" => "Definieren Sie die Feierlichkeiten",
        "en" => "Define the Solemnities",
        "es" => "Definir las Solemnidades",
        "fr" => "Définissez les Solennités",
        "it" => "Definisci le Solennità",
        "pt" => "Defina as Solenidades"
    ],
    "Define the Feasts" => [
        "de" => "Definieren Sie die Feste",
        "en" => "Define the Feasts",
        "es" => "Definir las Fiestas",
        "fr" => "Définissez les Fêtes",
        "it" => "Definisci le Feste",
        "pt" => "Defina as Festas"
    ],
    "Define the Memorials" => [
        "de" => "Definieren Sie die Gedenkfeiern",
        "en" => "Define the Memorials",
        "es" => "Definir las Memoriales",
        "fr" => "Définissez les Mémoriaux",
        "it" => "Definisci le Memorie",
        "pt" => "Defina as Memoriais"
    ],
    "Define the Optional Memorials" => [
        "de" => "Definieren Sie die Optionale Gedenkfeiern",
        "en" => "Define the Optional Memorials",
        "es" => "Definir las Memoriales opcionales",
        "fr" => "Définissez les Mémoriaux facultatifs",
        "it" => "Definisci le Memorie facoltative",
        "pt" => "Defina as Memoriais opcionais"
    ],
    "Principal Patron(s) of the Place, Diocese, Region, Province or Territory" => [
        "de" => "",
        "en" => "Principal Patron(s) of the Place, Diocese, Region, Province or Territory",
        "es" => "",
        "fr" => "",
        "it" => "Patrono/i Principale/i del Luogo, Diocesi, Regione, Provincia o Territorio",
        "pt" => ""
    ],
    "Patron(s) of the Place, Diocese, Region, Province or Territory" => [
        "de" => "",
        "en" => "Patron(s) of the Place, Diocese, Region, Province or Territory",
        "es" => "",
        "fr" => "",
        "it" => "Patrono/i del Luogo, Diocesi, Regione, Provincia o Territorio",
        "pt" => ""
    ],
    "Secondary Patron(s) of the Place, Diocese, Region, Province or Territory" => [
        "de" => "",
        "en" => "Secondary Patron(s) of the Place, Diocese, Region, Province or Territory",
        "es" => "",
        "fr" => "",
        "it" => "Patrono/i Secondario/i del Luogo, Diocesi, Regione, Provincia o Territorio",
        "pt" => ""
    ],
    "Dedication of the Cathedral" => [
        "de" => "",
        "en" => "Dedication of the Cathedral",
        "es" => "",
        "fr" => "",
        "it" => "Dedicazione della Cattedrale",
        "pt" => ""
    ],
    "Other Feast" => [
        "de" => "",
        "en" => "Other Feast",
        "es" => "",
        "fr" => "",
        "it" => "Altra Festa",
        "pt" => ""
    ],
    "Other Memorial" => [
        "de" => "",
        "en" => "Other Memorial",
        "es" => "",
        "fr" => "",
        "it" => "Altra Memoria",
        "pt" => ""
    ],
    "Other Optional Memorial" => [
        "de" => "",
        "en" => "Other Optional Memorial",
        "es" => "",
        "fr" => "",
        "it" => "Altra Memoria Facoltativa",
        "pt" => ""
    ],
    "Saints whos veneration is local to the Place, Diocese, Region, Province or Territory" => [
        "de" => "Heilige, deren Verehrung für den Ort, die Diözese, die Region, die Provinz oder das Territorium gilt",
        "en" => "Saints whos veneration is local to the Place, Diocese, Region, Province or Territory",
        "es" => "Santos cuya veneración es local del lugar, diócesis, región, provincia o territorio",
        "fr" => "Saints dont la vénération est locale au lieu, au diocèse, à la région, à la province ou au territoire",
        "it" => "Santi la venerazione dei quali è locale al Luogo, Diocesi, Regione, Provincia o Territorio",
        "pt" => "Santos cuja veneração é local no lugar, diocese, região, província ou território"
    ],
    "Common (or Proper)" => [
        "de" => "Common (oder Proper)",
        "en" => "Common (or Proper)",
        "es" => "Común (o Propio)",
        "fr" => "Commun (ou Propre)",
        "it" => "Comune (o Proprio)",
        "pt" => "Comum (ou Próprio)"
    ],
/*
    "" => [
        "de" => "",
        "en" => "",
        "es" => "",
        "fr" => "",
        "it" => "",
        "pt" => ""
    ],
*/
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
                            <label for="diocesanCalendarNationalDependency" class="font-weight-bold"><?php _e("Depends on national calendar"); ?>:</label>
                            <select class="form-control" id="diocesanCalendarNationalDependency" required>
                                <option value=""></option>
                                <option value="ITALIA">Italy</option>
                                <option value="USA">USA</option>
                            </select>
                        </div>
                        <div class="form-group col col-md-3">
                            <label for="diocesanCalendarDioceseName" class="font-weight-bold"><?php _e("Diocese"); ?>:</label>
                            <input list="DiocesesList" class="form-control" id="diocesanCalendarDioceseName" required>
                            <datalist id="DiocesesList">
                                <option value=""></option>
                            </datalist>
                        </div>
                        <div class="form-group col col-md-4">
                            <label for="diocesanCalendarBehaviour" class="font-weight-bold"><?php _e("Overwrites universal / national calendar"); ?></label>
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
                            <div class="container-fluid">
                                <div class="card border-left-primary m-5">
                                    <div class="card-header py-3">
                                        <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-place-of-worship fa-2x text-gray-300 mr-4"></i><?php _e("Generate Diocesan Calendar"); ?>: <?php _e("Define the Solemnities"); ?></h6>
                                    </div>
                                    <div class="card-body">
                                        <!--<div class="row no-gutters align-items-center">
                                            <div class="col mr-2">-->
                                                <form>
                                                    <?php FormControls::CreateFestivityRow(__("Principal Patron(s) of the Place, Diocese, Region, Province or Territory")) ?>
                                                    <?php FormControls::CreateFestivityRow(__("Dedication of the Cathedral")) ?>
                                                </form>
                                            <!--</div>
                                        </div>-->
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="carousel-item" id="carouselItemFeasts">
                            <div class="container-fluid">
                                <div class="card border-left-primary m-5">
                                    <div class="card-header py-3">
                                        <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-place-of-worship fa-2x text-gray-300 mr-4"></i><?php _e("Generate Diocesan Calendar"); ?>: <?php _e("Define the Feasts"); ?></h6>
                                    </div>
                                    <div class="card-body">
                                        <!--<div class="row no-gutters align-items-center">
                                            <div class="col mr-2">-->
                                                <form>
                                                    <?php FormControls::CreateFestivityRow(__("Patron(s) of the Place, Diocese, Region, Province or Territory")) ?>
                                                    <?php FormControls::CreateFestivityRow(__("Dedication of the Cathedral")) ?>
                                                    <?php FormControls::CreateFestivityRow(__("Other Feast")) ?>
                                                </form>
                                            <!--</div>
                                        </div>-->
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="carousel-item" id="carouselItemMemorials">
                            <div class="container-fluid">
                                <div class="card border-left-primary m-5">
                                    <div class="card-header py-3">
                                        <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-place-of-worship fa-2x text-gray-300 mr-4"></i><?php _e("Generate Diocesan Calendar"); ?>: <?php _e("Define the Memorials"); ?></h6>
                                    </div>
                                    <div class="card-body">
                                        <!--<div class="row no-gutters align-items-center">
                                            <div class="col mr-2">-->
                                                <form>
                                                    <?php FormControls::CreateFestivityRow(__("Secondary Patron(s) of the Place, Diocese, Region, Province or Territory")) ?>
                                                    <?php FormControls::CreateFestivityRow(__("Other Memorial")) ?>
                                                </form>
                                            <!--</div>
                                        </div>-->
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="carousel-item" id="carouselItemOptionalMemorials">
                            <div class="container-fluid">
                                <div class="card border-left-primary m-5">
                                    <div class="card-header py-3">
                                        <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-place-of-worship fa-2x text-gray-300 mr-4"></i><?php _e("Generate Diocesan Calendar"); ?>: <?php _e("Define the Optional Memorials"); ?></h6>
                                    </div>
                                    <div class="card-body">
                                        <!--<div class="row no-gutters align-items-center">
                                            <div class="col mr-2">-->
                                                <form>
                                                    <?php FormControls::CreateFestivityRow(__("Saints whos veneration is local to the Place, Diocese, Region, Province or Territory")) ?>
                                                    <?php FormControls::CreateFestivityRow(__("Other Optional Memorial")) ?>
                                                </form>
                                            <!--</div>
                                        </div>-->
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

    $USDiocesesObj = <?php echo $USDiocesesJSON; ?>;
    $USDiocesesArr = [];
    var c=0;
    for(const [state, arr] of Object.entries($USDiocesesObj)){
        arr.forEach(diocese => $USDiocesesArr[c++] = diocese + " (" + state + ")");
    }
    $ITALYDiocesesArr = ["Acerenza","Acerra","Acireale","Acqui","Adria - Rovigo","Agrigento","Alba","Albano","Albenga - Imperia","Ales - Terralba","Alessandria","Alghero - Bosa","Alife - Caiazzo","Altamura - Gravina - Acquaviva delle Fonti","Amalfi - Cava de' Tirreni","Anagni - Alatri","Ancona - Osimo","Andria","Aosta","Arezzo - Cortona - Sansepolcro","Ariano Irpino - Lacedonia","Ascoli Piceno","Assisi - Nocera Umbra - Gualdo Tadino","Asti","Avellino","Aversa","Avezzano","Bari - Bitonto","Belluno - Feltre","Benevento","Bergamo","Biella","Bologna","Bolzano - Bressanone, Bozen - Brixen","Brescia","Brindisi - Ostuni","Cagliari","Caltagirone","Caltanissetta","Camerino - San Severino Marche","Campobasso - Boiano","Capua","Carpi","Casale Monferrato","Caserta","Cassano all'Jonio","Castellaneta","Catania","Catanzaro - Squillace","Cefalù","Cerignola - Ascoli Satriano","Cerreto Sannita - Telese - Sant'Agata de' Goti","Cesena - Sarsina","Chiavari","Chieti - Vasto","Chioggia","Città di Castello","Civita Castellana","Civitavecchia - Tarquinia","Como","Concordia - Pordenone","Conversano - Monopoli","Cosenza - Bisignano","Crema","Cremona","Crotone - Santa Severina","Cuneo","Esarcato Apostolico per i fedeli cattolici ucraini di rito bizantino residenti in Italia","Fabriano - Matelica","Faenza - Modigliana","Fano - Fossombrone - Cagli - Pergola","Fermo","Ferrara - Comacchio","Fidenza","Fiesole","Firenze","Foggia - Bovino","Foligno","Forlì - Bertinoro","Fossano","Frascati","Frosinone - Veroli - Ferentino","Gaeta","Genova","Gorizia","Grosseto","Gubbio","Iglesias","Imola","Ischia","Isernia - Venafro","Ivrea","Jesi","La Spezia - Sarzana - Brugnato","Lamezia Terme","Lanciano - Ortona","Lanusei","L'Aquila","Latina - Terracina - Sezze - Priverno","Lecce","Livorno","Locri - Gerace","Lodi","Loreto","Lucca","Lucera - Troia","Lungro","Macerata - Tolentino - Recanati - Cingoli - Treia","Manfredonia - Vieste - San Giovanni Rotondo","Mantova","Massa Carrara - Pontremoli","Massa Marittima - Piombino","Matera - Irsina","Mazara del Vallo","Melfi - Rapolla - Venosa","Messina - Lipari - Santa Lucia del Mela","Milano","Mileto - Nicotera - Tropea","Modena - Nonantola","Molfetta - Ruvo - Giovinazzo - Terlizzi","Mondovì","Monreale","Monte Oliveto Maggiore","Montecassino","Montepulciano - Chiusi - Pienza","Montevergine","Napoli","Nardò - Gallipoli","Nicosia","Nocera Inferiore - Sarno","Nola","Noto","Novara","Nuoro","Oppido Mamertina - Palmi","Ordinariato Militare","Oria","Oristano","Orvieto - Todi","Ostia","Otranto","Ozieri","Padova","Palermo","Palestrina","Parma","Patti","Pavia","Perugia - Città della Pieve","Pesaro","Pescara - Penne","Pescia","Piacenza - Bobbio","Piana degli Albanesi","Piazza Armerina","Pinerolo","Pisa","Pistoia","Pitigliano - Sovana - Orbetello","Pompei","Porto - Santa Rufina","Potenza - Muro Lucano - Marsico Nuovo","Pozzuoli","Prato","Ragusa","Ravenna - Cervia","Reggio Calabria - Bova","Reggio Emilia - Guastalla","Rieti","Rimini","Roma","Rossano - Cariati","Sabina - Poggio Mirteto","Salerno - Campagna - Acerno","Saluzzo","San Benedetto del Tronto - Ripatransone - Montalto","San Marco Argentano - Scalea","San Marino - Montefeltro","San Miniato","San Severo","Santa Maria di Grottaferrata","Sant'Angelo dei Lombardi - Conza - Nusco - Bisaccia","Santissima Trinità di Cava de' Tirreni","Sassari","Savona - Noli","Senigallia","Sessa Aurunca","Siena - Colle di Val d'Elsa - Montalcino","Siracusa","Sora - Cassino - Aquino - Pontecorvo","Sorrento - Castellammare di Stabia","Spoleto - Norcia","Subiaco","Sulmona - Valva","Susa","Taranto","Teano - Calvi","Teggiano - Policastro","Tempio - Ampurias","Teramo - Atri","Termoli - Larino","Terni - Narni - Amelia","Tivoli","Torino","Tortona","Trani - Barletta - Bisceglie","Trapani","Trento","Treviso","Tricarico","Trieste","Trivento","Tursi - Lagonegro","Udine","Ugento - Santa Maria di Leuca","Urbino - Urbania - Sant'Angelo in Vado","Vallo della Lucania","Velletri - Segni","Venezia","Ventimiglia - San Remo","Vercelli","Verona","Vicenza","Vigevano","Viterbo","Vittorio Veneto","Volterra"];

    class litEvent {
        constructor(name="",color="",grade=0,common="",day=1,month=1){
            this.name = name;
            this.color = color;
            this.grade = grade;
            this.common = common;
            this.day = day;
            this.month = month;
        }
    }

    $CALENDAR = {LitCal:{}};

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

        $('#diocesanCalendarNationalDependency').on('change',function(){
            switch($(this).val()){
                case "ITALIA":
                    $('#DiocesesList').empty();
                    $ITALYDiocesesArr.forEach(diocese => $('#DiocesesList').append('<option data-value="' + diocese.replace(/[^a-zA-Z]/gi, '') + '" value="' + diocese + '">') );
                    break;
                case "USA":
                    $('#DiocesesList').empty();
                    $USDiocesesArr.forEach(diocese => $('#DiocesesList').append('<option data-value="' + diocese.replace(/[^a-zA-Z]/gi, '') + '" value="' + diocese + '">') );
                    break;
            }
        });

        $(document).on('change','.litEvent',function(event){
            $row = $(this).closest('.form-row');
            if($(this).hasClass('litEventName')){
                if($(this).val() == ''){
                    //empty value probably means we are trying to delete an already defined event
                    //so let's find the key and remove it
                    oldEventKey = $(this).attr('data-valuewas');
                    if($CALENDAR.LitCal.hasOwnProperty(oldEventKey)){
                        delete $CALENDAR.LitCal[oldEventKey];
                    }
                    /*
                    //so let's go back over all the name fields and recreate a clean LitCal object
                    $CALENDAR = {LitCal:{}};
                    $('#carouselItemSolemnities .form-row').each(function(idx,el){
                        if($(el).find('.litEventName').val() != ''){
                            eventKey = $(el).find('.litEventName').val().replace(/[^a-zA-Z]/gi, '');

                        }
                    });
                    */
                } else {
                    eventKey = $(this).val().replace(/[^a-zA-Z]/gi, '');
                    if( $(this).attr('data-valuewas') == '' && $CALENDAR.LitCal.hasOwnProperty(eventKey) === false ){
                        $CALENDAR.LitCal[eventKey] = new litEvent();
                        $CALENDAR.LitCal[eventKey].name = $(this).val();
                        //let's initialize defaults just in case the default input values happen to be correct, so no change events are fired
                        $CALENDAR.LitCal[eventKey].day = parseInt($row.find('.litEventDay').val());
                        $CALENDAR.LitCal[eventKey].month = parseInt($row.find('.litEventMonth').val());
                        $CALENDAR.LitCal[eventKey].color = $row.find('.litEventColor').val();
                        $CALENDAR.LitCal[eventKey].common = $row.find('.litEventProper').val();
                        $(this).attr('data-valuewas',eventKey);
                        $(this).removeClass('is-invalid');
                    } else if ( $(this).attr('data-valuewas') != '' ) {
                        oldEventKey = $(this).attr('data-valuewas');
                        if($CALENDAR.LitCal.hasOwnProperty(oldEventKey)){
                            if (oldEventKey !== eventKey) {
                                Object.defineProperty($CALENDAR.LitCal, eventKey,
                                    Object.getOwnPropertyDescriptor($CALENDAR.LitCal, oldEventKey));
                                delete $CALENDAR.LitCal[oldEventKey];
                                $(this).attr('data-valuewas',eventKey);
                                $(this).removeClass('is-invalid');
                            }
                        }
                    } else if ( $CALENDAR.LitCal.hasOwnProperty(eventKey) ){
                        //this exact same festivity name was already defined elsewhere!
                        $(this).val('');
                        $(this).addClass('is-invalid');
                    }
                    switch($(this).closest('.carousel-item').attr('id')){
                        case 'carouselItemSolemnities':
                            $CALENDAR.LitCal[eventKey].grade = 6;
                            break;
                        case 'carouselItemFeasts':
                            $CALENDAR.LitCal[eventKey].grade = 4;
                            break;
                        case 'carouselItemMemorials':
                            $CALENDAR.LitCal[eventKey].grade = 3;
                            break;
                        case 'carouselItemOptionalMemorials':
                            $CALENDAR.LitCal[eventKey].grade = 2;
                            break;
                    }
                }
            } else if ($(this).hasClass('litEventDay')) {
                if($row.find('.litEventName').val() != ""){
                    eventKey = $row.find('.litEventName').val().replace(/[^a-zA-Z]/gi, '');
                    if( $CALENDAR.LitCal.hasOwnProperty(eventKey) ){
                        $CALENDAR.LitCal[eventKey].day = parseInt($(this).val());
                    }
                }
            } else if ($(this).hasClass('litEventMonth')) {
                if($row.find('.litEventName').val() != ""){
                    eventKey = $row.find('.litEventName').val().replace(/[^a-zA-Z]/gi, '');
                    if( $CALENDAR.LitCal.hasOwnProperty(eventKey) ){
                        $CALENDAR.LitCal[eventKey].month = parseInt($(this).val());
                    }
                }
            } else if ($(this).hasClass('litEventColor')) {
                if($row.find('.litEventName').val() != ""){
                    eventKey = $row.find('.litEventName').val().replace(/[^a-zA-Z]/gi, '');
                    if( $CALENDAR.LitCal.hasOwnProperty(eventKey) ){
                        $CALENDAR.LitCal[eventKey].color = $(this).val();
                    }
                }
            } else if ($(this).hasClass('litEventProper')) {
                if($row.find('.litEventName').val() != ""){
                    eventKey = $row.find('.litEventName').val().replace(/[^a-zA-Z]/gi, '');
                    if( $CALENDAR.LitCal.hasOwnProperty(eventKey) ){
                        $CALENDAR.LitCal[eventKey].common = $(this).val();
                    }
                }
            }
        });

    });
</script>
</body>
</html>
