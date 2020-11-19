<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

    function ordinal($number) {
        $ends = array('th','st','nd','rd','th','th','th','th','th','th');
        if ((($number % 100) >= 11) && (($number%100) <= 13))
            return $number. 'th';
        else
            return $number. $ends[$number % 10];
    }

    function __($key)
    {
        global $messages;
        global $LOCALE;
        $lcl = strtolower($LOCALE);
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

    function _e($key)
    {
        global $messages;
        global $LOCALE;
        $lcl = strtolower($LOCALE);
        if (isset($messages)) {
            if (isset($messages[$key])) {
                if (isset($messages[$key][$lcl])) {
                    echo $messages[$key][$lcl];
                } else {
                    echo $messages[$key]["en"];
                }
            } else {
                echo $key;
            }
        } else {
            echo $key;
        }
    }

    $messages = [
        'Date of Easter from 1583 to 9999' => [
            "en" => "Date of Easter from 1583 to 9999",
            "it" => "Data della Pasqua dal 1583 al 9999",
            "la" => "Diem Paschae a MDLXXXIII ad I&#773;X&#773;CMXCIX"
        ],
        "Go back" => [
            "en" => "Go back",
            "it" => "Torna indietro",
            "la" => "Reverte"
        ],
        "Easter Day Calculation in PHP (Years in which Julian and Gregorian easter coincide are marked in yellow)" => [
            "en" => "Easter Day Calculation in PHP (Years in which Julian and Gregorian easter coincide are marked in yellow)",
            "it" => "Calcolo del Giorno della Pasqua usando PHP (marcati in giallo gli anni nei quali coincide la pasqua giuliana con quella gregoriana)",
            "la" => "Computatio Diei Paschae cum PHP (notati sunt in flavo anni quibus coincidit Pascha gregoriana cum Pascha Iuliana)"
        ],
        "Note how they gradually drift further apart, then from the year 2698 there is a gap until 4102 (1404 years); again a gap from 4197 to 5006 (809 years); from 5096 to 5902 (806 years); after 6095 there are no more coincidences until the end of the calculable year 9999" => [
            "en" => "Note how they gradually drift further apart, then from the year 2698 there is a gap until 4102 (1404 years); again a gap from 4197 to 5006 (809 years); from 5096 to 5902 (806 years); after 6095 there are no more coincidences until the end of the calculable year 9999",
            "it" => "Da notare il graduale distanziamento, poi dall'anno 2698 c'è un vuoto fino al 4102 (1404 anni); di nuovo un vuoto dal 4197 al 5006 (809 anni); dal 5096 al 5902 (806 anni); dopo il 6095 non ci sono più coincidenze registrate fino all'ultimo anno calcolabile 9999",
            "la" => "Nota intervallum crescente, post annum 2698 vacuum est usque ad anno 4102 (anni 1404); rursus vacuum est post annum 4197 usque ad anno 5006 (anni 809); post annum 5096 usque ad anno 5902 (anni 806); post annum 6095 non accidunt usque ad finem calendarii computabilis in anno 9999"
        ],
        "Gregorian Easter" => [
            "en" => "Gregorian Easter",
            "it" => "Pasqua Gregoriana",
            "la" => "Pascha Gregoriana"
        ],
        "Julian Easter" => [
            "en" => "Julian Easter",
            "it" => "Pasqua Giuliana",
            "la" => "Pascha Iuliana"
        ],
        "Julian Easter in Gregorian Calendar" => [
            "en" => "Julian Easter in Gregorian Calendar",
            "it" => "Pasqua Giuliana nel Calendario Gregoriano",
            "la" => "Pascha Iuliana in Calendario Gregoriano"
        ],
        "Century" => [
            "en" => "Century",
            "it" => "Secolo",
            "la" => "Saeculum"
        ],
        "Sunday" => [
            "en" => "Sunday",
            "it" => "Domenica",
            "la" => "Dies Domini"
        ]
    ];

    $monthsLatin = [
        "",
        "Ianuarius",
        "Februarius",
        "Martius",
        "Aprilis",
        "Maius",
        "Iunius",
        "Iulius",
        "Augustus",
        "September",
        "October",
        "November",
        "December"
    ];

    function integerToRoman($integer) {
        // Convert the integer into an integer (just to make sure)
        $integer = intval($integer);
        $result = '';
        
        // Create a lookup array that contains all of the Roman numerals.
        $lookup = array(
            'X&#773;'           => 10000,
            'I&#773;X&#773;'    => 9000,
            'V&#773;'           => 5000,
            'I&#773;V&#773;'    => 4000,
            'M'                 => 1000,
            'CM'                => 900,
            'D'                 => 500,
            'CD'                => 400,
            'C'                 => 100,
            'XC'                => 90,
            'L'                 => 50,
            'XL'                => 40,
            'X'                 => 10,
            'IX'                => 9,
            'V'                 => 5,
            'IV'                => 4,
            'I'                 => 1
        );
        
        foreach($lookup as $roman => $value){
            // Determine the number of matches
            $matches = intval($integer/$value);
            
            // Add the same number of characters to the string
            $result .= str_repeat($roman,$matches);
            
            // Set the integer to be the remainder of the integer and the value
            $integer = $integer % $value;
        }
        
        // The Roman numeral should be built, return it
        return $result;
    }

    $LOCALE = isset($_GET["locale"]) ? strtoupper($_GET["locale"]) : "LA"; //default to latin
    ini_set('date.timezone', 'Europe/Vatican');
    //ini_set('intl.default_locale', strtolower($LOCALE) . '_' . $LOCALE);
    setlocale(LC_TIME, strtolower($LOCALE) . '_' . $LOCALE);
    $error_msg = "";
    $ch = curl_init();

    $prefix = $_SERVER['HTTPS'] ? 'https://' : 'http://';
    $domain = $_SERVER['HTTP_HOST'];
    $query = $_SERVER['PHP_SELF'];
    $path_info = pathinfo($query);
    //$dir_level = explode("/",dirname($path_info['dirname']));
    $URL =  $prefix . $domain . $path_info['dirname'] . "/dateOfEaster.php?locale=".$LOCALE;
    curl_setopt($ch, CURLOPT_URL, $URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
    }
    curl_close($ch);
    $DatesOfEaster = json_decode($response);

?>
<!DOCTYPE html>
<head>
    <title><?php _e('Date of Easter from 1583 to 9999')?></title>
    <meta charset="UTF-8">
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <meta name="msapplication-TileColor" content="#ffffff" />
    <meta name="msapplication-TileImage" content="easter-egg-5-144-279148.png">
    <link rel="apple-touch-icon-precomposed" sizes="152x152" href="easter-egg-5-152-279148.png">
    <link rel="apple-touch-icon-precomposed" sizes="144x144" href="easter-egg-5-144-279148.png">
    <link rel="apple-touch-icon-precomposed" sizes="120x120" href="easter-egg-5-120-279148.png">
    <link rel="apple-touch-icon-precomposed" sizes="114x114" href="easter-egg-5-114-279148.png">
    <link rel="apple-touch-icon-precomposed" sizes="72x72" href="easter-egg-5-72-279148.png">    
    <link rel="apple-touch-icon-precomposed" href="easter-egg-5-57-279148.png">
    <link rel="icon" href="easter-egg-5-32-279148.png" sizes="32x32">
    <link rel="stylesheet" href="https://ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/themes/smoothness/jquery-ui.css">
    <style>
        .backNav {
            background-color:yellow;
            font-size:1.1em;
            text-align:center;
            cursor:pointer;
            text-decoration:none;
            width:95%;
            margin:0px auto;
            border-bottom:2px outset LightBlue;
            display:block;
            padding: 12px 24px;
            font-weight: bold;
        }
        .backNav:hover {
            background-color: gold;
            color: DarkBlue;
        }
        thead {
            display:block;position:sticky;top:40px;
        }
        tbody {
            display:block;
        }
        thead th {
            border-left: 1px groove White;
            border-right:1px ridge White;
            padding: 6px 0px;
            background-color:grey;
        }
        tbody td {
            padding-left: 1em;
        }
        #HistoryNavigationLeftSidebar {
            position: fixed;
            top: 100px;
            right: 25px;
            width: 150px;
            height: 80vh;
            border: 1px groove White;
            text-align: center;
            padding-top: 20px;
            padding-left: 10px;
            padding-right: 10px;
        }
        #slider-vertical {
            height:76vh;
            float:left;
            font-size: 1em;
        }
        #TimelineCenturiesContainer {
            height:77vh;
            float:right;
            overflow-y:hidden;
            position:relative;
            top:-8px;
        }
        #TimelineCenturiesContainer:after {
            content  : "";
            position : fixed;
            z-index  : 1;
            bottom   : 50px;
            right    : 35px;
            pointer-events   : none;
            background-image : linear-gradient(to bottom, 
                                rgba(255,255,255, 0), 
                                rgba(255,255,255, 1) 90%);
            width    : 50px;
            height   : 4em;
            transition: height 0.25s ease-out;
        }
        #TimelineCenturiesContainer.scrollBottom:after {
            content  : "";
            position : fixed;
            z-index  : 1;
            bottom   : 50px;
            right    : 35px;
            pointer-events   : none;
            background-image : linear-gradient(to bottom, 
                                rgba(255,255,255, 0), 
                                rgba(255,255,255, 1) 90%);
            width    : 50px;
            height   : 0em;
            transition: height 0.25s ease-in;
        }
        #EasterTableContainer:after {
            content  : "";
            position : absolute;
            z-index  : 1;
            bottom   : 30px;
            left     : 0;
            pointer-events   : none;
            background-image : linear-gradient(to bottom, 
                                rgba(255,255,255, 0), 
                                rgba(255,255,255, 1) 90%);
            width    : 80%;
            height   : 4em;
        }

        .TimelineCenturyMarker {
            color: DarkBlue;
            font-family:'Gill Sans', 'Gill Sans MT', Calibri, 'Trebuchet MS', sans-serif;
            font-size:.5em;
        }

        #EasterTableContainer {
            height: 76vh;
            overflow-y:hidden;
        }

        .highlight {
            background-color: yellow;
        }

        #langSelect {
            position: fixed;
            top: 75px;
            left: 30px;
        }

    </style>
</head>
<body>
    <div id="clipDiv" style="position:absolute;top:-500px;height:7em;z-index:0;background-image:linear-gradient(to bottom, rgba(255,255,255, 1), rgba(255,255,255, 1), rgba(255,255,255, 0) );left: 0px;width: 100%;"></div>
    <div><a class="backNav" href="/LiturgicalCalendar">↩      <?php _e('Go back')?>      ↩</a></div>
    <select id="langSelect">
        <option value="en"<?php echo strtolower($LOCALE) === "en" ? " selected" : "" ?>>English</option>
        <option value="it"<?php echo strtolower($LOCALE) === "it" ? " selected" : "" ?>>Italiano</option>
        <option value="la"<?php echo strtolower($LOCALE) === "la" ? " selected" : "" ?>>Latine</option>
    </select>
    <div id="HistoryNavigationLeftSidebar">
        <div id="slider-vertical"></div>
        <div id="TimelineCenturiesContainer">
        <?php 
            for($i=16;$i<=100;$i++){
                $century = strtolower($LOCALE) === "en" ? ordinal($i) : integerToRoman($i);
                echo "<div class=\"TimelineCenturyMarker\">" . $century . " " . __("Century") . "</div>";
            }
        ?>
        </div>
    </div>
<?php

    echo '<h3 style="text-align:center;">' . __("Easter Day Calculation in PHP (Years in which Julian and Gregorian easter coincide are marked in yellow)") . '</h3>';

    $EasterTableContainer = '<div id="EasterTableContainer">';
    $EasterTableContainer .= '<table style="width:60%;margin:30px auto;border:1px solid Blue;border-radius: 6px; padding:10px;background:LightBlue;">';
    $EasterTableContainer .= '<thead><tr><th width="300">' . __('Gregorian Easter') . '</th><th width="300">' . __('Julian Easter') . '</th><th width="300">' . __('Julian Easter in Gregorian Calendar') . '</th></tr></thead>';
    $EasterTableContainer .= '<tbody>';
    //$Y = (int)date("Y");
    //for($i=1997;$i<=2037;$i++){
    for($i=1583;$i<=9999;$i++){
        $gregDateString = $DatesOfEaster->DatesArray[$i-1583]->gregorianDateString;
        $julianDateString = $DatesOfEaster->DatesArray[$i-1583]->julianDateString;
        $westernJulianDateString = $DatesOfEaster->DatesArray[$i-1583]->westernJulianDateString;

        $style_str = $DatesOfEaster->DatesArray[$i-1583]->coinciding ? ' style="background-color:Yellow;font-weight:bold;color:Blue;"' : '';
        $EasterTableContainer .= '<tr'.$style_str.'><td width="300">'.$gregDateString.'</td><td width="300">' . $julianDateString . '</td><td width="300">'.$westernJulianDateString.'</td></tr>';
    }
    $EasterTableContainer .= '</tbody></table>';
    $EasterTableContainer .= '</div>';

    echo '<div style="text-align:center;width:40%;margin:0px auto;font-size:.7em;z-index:10;position:relative;"><i>The last coinciding Easter will be: ' . $DatesOfEaster->lastCoincidenceString . '</i></div>';
    echo $EasterTableContainer;
?>
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.2.1/jquery.min.js"></script>
    <script src="https://ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/jquery-ui.min.js"></script>
    <script>
        const scale = (num, in_min, in_max, out_min, out_max) => {
            return (num - in_min) * (out_max - out_min) / (in_max - in_min) + out_min;
        }

        $(document).ready(function(){
            let tableH = $('#EasterTableContainer table').height();
            let timelineH = $("#TimelineCenturiesContainer").children().last().offset().top - $("#TimelineCenturiesContainer").children().first().offset().top;
            timelineH /= 2;
            timelineH -= $("#TimelineCenturiesContainer").children().first().outerHeight();
            $('#TimelineCenturiesContainer div:first-child').addClass('highlight');
            let distFromTop = Math.round($('thead tr').offset().top) + 'px';
            $('#clipDiv').css({'top':'calc('+distFromTop+' - 3em)'});

            $("html,body").animate({scrollTop: 0}, 1000);
            $( "#slider-vertical" ).slider({
                orientation: "vertical",
                range: "min",
                step: 1,
                min: 0,
                max: 1000,
                value: 1000,
                slide: function( event, ui ) {
                    let slideVal = 1000 - ui.value;
                    let scrollAmount = scale(slideVal, 0, 1000, 0, tableH);
                    let scrollAmount2 = scale(slideVal, 0, 1000, 0, timelineH);
                    let nthCentury = Math.ceil(scale(slideVal, 0, 1000, 0, 84)) + 1;
                    $('#EasterTableContainer').scrollTop(scrollAmount);
                    $('#TimelineCenturiesContainer').scrollTop(scrollAmount2);
                    $('.highlight').removeClass('highlight');
                    $('#TimelineCenturiesContainer div:nth-child('+nthCentury+')').addClass('highlight');
                    if(nthCentury > 80 && $('#TimelineCenturiesContainer').hasClass('scrollBottom') === false ){
                        $('#TimelineCenturiesContainer').addClass('scrollBottom');
                    }
                    else if(nthCentury < 81 && $('#TimelineCenturiesContainer').hasClass('scrollBottom') ){
                        $('#TimelineCenturiesContainer').removeClass('scrollBottom');
                    }
                    //$( "#amount" ).val( ui.value );
                }
            });

            $('#langSelect').on('change', function(){
                let lcl = $(this).val();
                location.href = '?locale='+lcl;
            });

        });

    </script>
</body>
