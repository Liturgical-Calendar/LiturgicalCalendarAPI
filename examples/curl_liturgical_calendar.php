<?php
/**
 * Liturgical Calendar display script using CURL and PHP
 * Author: John Romano D'Orazio 
 * Email: priest@johnromanodorazio.com
 * Licensed under the Apache 2.0 License
 * Version 2.0
 * Date Created: 27 December 2017
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
    
    /**************************
     * BEGIN DISPLAY LOGIC
     * 
     *************************/
    function countSameDayEvents($currentKeyIndex,$EventsArray,&$cc){
      $Keys = array_keys($EventsArray);
      $currentFestivity = $EventsArray[$Keys[$currentKeyIndex]];
      if($currentKeyIndex < count($Keys)-1){
          $nextFestivity = $EventsArray[$Keys[$currentKeyIndex+1]];          
          if( $nextFestivity->date == $currentFestivity->date ){
            $cc++;
            countSameDayEvents($currentKeyIndex+1,$EventsArray,$cc);
          }
      }
    }
    
    $YEAR = (isset($_GET["year"]) && is_numeric($_GET["year"]) && ctype_digit($_GET["year"]) && strlen($_GET["year"])===4) ? (int)$_GET["year"] : (int)date("Y");
    
    $EPIPHANY = (isset($_GET["epiphany"]) && ($_GET["epiphany"] === "JAN6" || $_GET["epiphany"] === "SUNDAY_JAN2_JAN8") ) ? $_GET["epiphany"] : "JAN6";
    $ASCENSION = (isset($_GET["ascension"]) && ($_GET["ascension"] === "THURSDAY" || $_GET["ascension"] === "SUNDAY") ) ? $_GET["ascension"] : "SUNDAY";
    $CORPUSCHRISTI = (isset($_GET["corpuschristi"]) && ($_GET["corpuschristi"] === "THURSDAY" || $_GET["corpuschristi"] === "SUNDAY") ) ? $_GET["corpuschristi"] : "SUNDAY";

    define("EPIPHANY",$EPIPHANY);
    //define(EPIPHANY,"SUNDAY_JAN2_JAN8");
    //define(EPIPHANY,"JAN6");

    define("ASCENSION",$ASCENSION);
    //define(ASCENSION,"THURSDAY");
    //define(ASCENSION,"SUNDAY");

    define("CORPUSCHRISTI",$CORPUSCHRISTI);
    //define(CORPUSCHRISTI,"THURSDAY");
    //define(CORPUSCHRISTI,"SUNDAY");
        
    //  Initiate curl
    $ch = curl_init();

    $prefix = $_SERVER['HTTPS'] ? 'https://' : 'http://';
    $domain = $_SERVER['HTTP_HOST'];
    $URL =  $prefix . $domain . "/" . basename(dirname(__FILE__,2)) . "/LitCalEngine.php";

    // Disable SSL verification
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    // Will return the response, if false it print the response
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // Set the url
    curl_setopt($ch, CURLOPT_URL, $URL);
    // Set request method to POST
    curl_setopt($ch, CURLOPT_POST, 1);
    // Define the POST field data    
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array("year"=>$YEAR,"epiphany"=>$EPIPHANY,"ascension"=>$ASCENSION,"corpuschristi"=>$CORPUSCHRISTI)));    
    // Execute
    $result=curl_exec($ch);
    
    if (curl_errno($ch)) {
        // this would be your first hint that something went wrong
        die("Could not send request. Curl error: " . curl_error($ch));
    } else {
        // check the HTTP status code of the request
        $resultStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($resultStatus != 200) {
            // the request did not complete as expected. common errors are 4xx
            // (not found, bad request, etc.) and 5xx (usually concerning
            // errors/exceptions in the remote script execution)
    
            die("Request failed. HTTP status code: " . $resultStatus);
        }
    }

    class Festivity {
        public $name;
        public $date;
        public $color;
        public $type;
        public $grade; //1=Optional memorial,2=Obligatory memorial,3=Feast,4=Holy Day of Obligation,5=Feast of the Lord,6=Solemnity
        public $common;
    
        function __construct($name,$date,$color,$type,$grade=0,$common='') 
        {
            $this->name = $name;
            $this->date = DateTime::createFromFormat('U', $date);
            $this->color = $color;
            $this->type = $type;
            $this->grade = $grade;
            $this->common = $common;
        }
    
        /* * * * * * * * * * * * * * * * * * * * * * * * *
         * Funzione statica di comparazione
         * in vista dell'ordinamento di un array di oggetti Festivity
         * Tiene conto non soltanto del valore della data,
         * ma anche del grado della festa qualora ci fosse una concomitanza
         * * * * * * * * * * * * * * * * * * * * * * * * * */
        public static function comp_date($a, $b) 
        {
            if ($a->date == $b->date) {
                if($a->grade == $b->grade){
                    return 0;
                }
                return ($a->grade > $b->grade) ? +1 : -1;
            }
            return ($a->date > $b->date) ? +1 : -1;
        }
        
    }

    $GRADE = array("","COMMEMORATION","OPTIONAL MEMORIAL","MEMORIAL","FEAST","FEAST OF THE LORD","SOLEMNITY","HOLY DAY OF OBLIGATION");
    
    $SUNDAY_CYCLE = array("A","B","C");
    $WEEKDAY_CYCLE = array("I","II");
    
    $LitCal = array();    

    $LitCalData = json_decode($result,true); // decode as associative array rather than stdClass object
    if(isset($LitCalData["Settings"]) ){ $YEAR = $LitCalData["Settings"]["YEAR"]; }
    if(isset($LitCalData["LitCal"]) ){ $LitCal = $LitCalData["LitCal"]; }
    else{
        die("We do not have enough information. Returned data has no LitCal property:" . var_dump($LitCalData) );
    }

    // Closing
    curl_close($ch);
    
    foreach($LitCal as $key => $value){
        $LitCal[$key] = new Festivity($LitCal[$key]["name"],$LitCal[$key]["date"],$LitCal[$key]["color"],$LitCal[$key]["type"],$LitCal[$key]["grade"],$LitCal[$key]["common"]);
    }
?>
<!doctype html>
<head>
    <title>Generate Roman Calendar</title>
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
    <link rel="icon" href="/path/to/easter-egg-5-32-279148.png" sizes="32x32">
    <!-- <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.2.1/jquery.min.js"></script> -->
</head>
<body>

<?php
    
    echo '<h1 style="text-align:center;">Liturgical Calendar Calculation for a Given Year ('.$YEAR.')</h1>';
    echo '<h2 style="text-align:center;">HTML presentation elaborated by PHP using a CURL request to a <a href="../LitCalEngine.php">PHP engine</a></h2>';
    
    if($YEAR < 1969){
        echo '<div style="text-align:center;border:3px ridge Green;background-color:LightBlue;width:75%;margin:10px auto;padding:10px;">';
        echo 'You are viewing a year prior to 1969: the calendar produced will not reflect the calendar used that year, but rather how the current Roman calendar would have been applied in that year.';
        echo '</div>';        
    }

    
    echo '<fieldset style="margin-bottom:6px;"><legend>Customize options for generating the Roman Calendar</legend>';
    echo '<form method="GET">';
    echo '<table style="width:100%;"><tr>';
    echo '<td><label>YEAR: <input type="number" name="year" id="year" min="1969" value="'.$YEAR.'" /></label></td>';
    echo '<td><label>EPIPHANY: <select name="epiphany" id="epiphany"><option value="JAN6" '.(EPIPHANY==="JAN6"?" SELECTED":"").'>January 6</option><option value="SUNDAY_JAN2_JAN8" '.(EPIPHANY==="SUNDAY_JAN2_JAN8"?" SELECTED":"").'>Sunday between January 2 and January 8</option></select></label></td>';
    echo '<td><label>ASCENSION: <select name="ascension" id="ascension"><option value="THURSDAY" '.(ASCENSION==="THURSDAY"?" SELECTED":"").'>Thursday</option><option value="SUNDAY" '.(ASCENSION==="SUNDAY"?" SELECTED":"").'>Sunday</option></select></label></td>';
    echo '<td><label>CORPUS CHRISTI (CORPUS DOMINI): <select name="corpuschristi" id="corpuschristi"><option value="THURSDAY" '.(CORPUSCHRISTI==="THURSDAY"?" SELECTED":"").'>Thursday</option><option value="SUNDAY" '.(CORPUSCHRISTI==="SUNDAY"?" SELECTED":"").'>Sunday</option></select></label></td>';
    echo '<td><input type="SUBMIT" value="GENERATE CALENDAR" /></td>';
    echo '</tr></table>';
    echo '</form>';
    echo '</fieldset>';

    echo '<div style="text-align:center;border:2px groove White;border-radius:6px;width:60%;margin:0px auto;padding-bottom:6px;">';

    echo '<h3>Configurations being used to generate this calendar:</h3>';
    echo '<span>YEAR = '.$YEAR.', EPIPHANY = '.EPIPHANY.', ASCENSION = '.ASCENSION.', CORPUS CHRISTI = '.CORPUSCHRISTI.'</span>';
    
    echo '</div>';
    
    echo '<table id="LitCalTable" style="width:75%;margin:30px auto;border:1px solid Blue;border-radius: 6px; padding:10px;background:LightBlue;">';
    echo '<thead><tr><th>Date in Gregorian Calendar</th><th>General Roman Calendar Festivity</th><th>Grade of the Festivity</th></tr></thead>';
    echo '<tbody>';
    
    
    $dayCnt = 0;
    //for($i=1997;$i<=2037;$i++){
    $highContrast = array('purple','red','green');
    
    $LitCalKeys = array_keys($LitCal);
    //print_r($LitCalKeys);
    //echo count($LitCalKeys);
    for($keyindex=0; $keyindex < count($LitCalKeys); $keyindex++){
      $dayCnt++;
      $keyname = $LitCalKeys[$keyindex];
      $festivity = $LitCal[$keyname];
      
      //LET'S CALCULATE THE LITURGICAL YEAR CYCLE
      $currentCycle = '';
      //if we're dealing with a Sunday or a Solemnity or a Feast of the Lord, then we calculate the Sunday/Festive Cycle
      if((int)$festivity->date->format('N') === 7 || $festivity->grade > 4){
        if($festivity->date >= DateTime::createFromFormat('!j-n-Y', '1-1-'.$YEAR) && $festivity->date <= $LitCal["ChristKing"]->date){
            $currentCycle = "YEAR ".($SUNDAY_CYCLE[($YEAR-1) % 3]);
        }
        else if($festivity->date >= $LitCal["Advent1"]->date && $festivity->date <= DateTime::createFromFormat('!j-n-Y', '31-12-'.$YEAR)){
            $currentCycle = "YEAR ".($SUNDAY_CYCLE[$YEAR % 3]);
        }
      }
      //otherwise we calculate the weekday cycle
      else{
        if($festivity->date >= DateTime::createFromFormat('!j-n-Y', '1-1-'.$YEAR) && $festivity->date <= $LitCal["ChristKing"]->date){
            $currentCycle = "YEAR ".($WEEKDAY_CYCLE[($YEAR-1) % 2]);
        }
        else if($festivity->date >= $LitCal["Advent1"]->date && $festivity->date <= DateTime::createFromFormat('!j-n-Y', '31-12-'.$YEAR)){
            $currentCycle = "YEAR ".($WEEKDAY_CYCLE[$YEAR % 2]);
        }      
      }
      
      
      //Let's check if we have more than one event on the same day, such as optional memorials...
      $cc = 0; 
      countSameDayEvents($keyindex,$LitCal,$cc);
      if($cc>0){
      
        for($ev=0;$ev<=$cc;$ev++){
            $keyname = $LitCalKeys[$keyindex];
            $festivity = $LitCal[$keyname];
            // LET'S DO SOME MORE MANIPULATION ON THE FESTIVITY->COMMON STRINGS AND THE FESTIVITY->COLOR...
            if($festivity->common !== "" && $festivity->common !== "Proper"){
              $commons = explode("|",$festivity->common);
              $commons = array_map(function($txt){ $txt = str_replace(":",": ",$txt); return "from the Common of ".$txt; },$commons);
              $festivity->common = implode("; or ",$commons);
            }
            $festivity->color = explode("|",$festivity->color)[0];
           
            //check which liturgical season we are in, to use the right color for that season...
            $color = "green";
            if(($festivity->date > $LitCal["Advent1"]->date  && $festivity->date < $LitCal["Christmas"]->date) || ($festivity->date > $LitCal["AshWednesday"]->date && $festivity->date < $LitCal["Easter"]->date )){
                $color = "purple";
            }
            else if($festivity->date > $LitCal["Easter"]->date && $festivity->date < $LitCal["Pentecost"]->date){
                $color = "white";
            }
            else if($festivity->date > $LitCal["Christmas"]->date || $festivity->date < $LitCal["BaptismLord"]->date ){
                $color = "white";
            }
            
            
            echo '<tr style="background-color:'.$color.';'.(in_array($color,$highContrast)?'color:white;':'').'">';
            if($ev==0){
                $rwsp = $cc+1;
                echo '<td rowspan="'.$rwsp.'" style="font-family:\'DejaVu Sans Mono\';font-size:.7em;font-weight:bold;">'.$festivity->date->format('D, F jS, Y').'</td>';
            }
            echo '<td>'.$festivity->name.' ('.$currentCycle.') - <i>'.$festivity->color.'</i><br /><i>'.$festivity->common.'</i></td>';
            echo '<td>'.$GRADE[$festivity->grade].'</td>';
            echo '</tr>';
            $keyindex++;        
        }
        $keyindex--;
      }
      
      else{
        // LET'S DO SOME MORE MANIPULATION ON THE FESTIVITY->COMMON STRINGS AND THE FESTIVITY->COLOR...
        if($festivity->common !== "" && $festivity->common !== "Proper"){
          $commons = explode("|",$festivity->common);
          $commons = array_map(function($txt){ $txt = str_replace(":",": ",$txt); return "From the Common of ".$txt; },$commons);
          $festivity->common = implode("; or ",$commons);
        }
        $festivity->color = explode("|",$festivity->color)[0];
        echo '<tr style="background-color:'.$festivity->color.';'.(in_array($festivity->color,$highContrast)?'color:white;':'').'">';
        echo '<td style="font-family:\'DejaVu Sans Mono\';font-size:.7em;font-weight:bold;">'.$festivity->date->format('D, F jS, Y').'</td>';
        echo '<td>'.$festivity->name.' ('.$currentCycle.') - <i>'.$festivity->color.'</i><br /><i>'.$festivity->common.'</i></td>';
        echo '<td>'.$GRADE[$festivity->grade].'</td>';
        echo '</tr>';      
      }
     
      
    }
    
    echo '</tbody></table>';
    
    echo '<div style="text-align:center;border:3px ridge Green;background-color:LightBlue;width:75%;margin:10px auto;padding:10px;">'.$dayCnt.' event days created</div>';

    
?>
</body>
