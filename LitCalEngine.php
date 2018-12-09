<?php
/**
 * Liturgical Calendar PHP engine script
 * Author: John Romano D'Orazio 
 * Email: priest@johnromanodorazio.com
 * Licensed under the Apache 2.0 License
 * Version 2.1
 * Date Created: 27 December 2017
 * Note: it is necessary to set up the MySQL liturgy tables prior to using this script
 */


/**********************************************************************************
 *                          ABBREVIATIONS                                         *
 * CB     Cerimonial of Bishops                                                   *
 * CCL    Code of Canon Law                                                       *
 * IM     General Instruction of the Roman Missal                                 *
 * IH     General Instruction of the Liturgy of the Hours                         *
 * LH     Liturgy of the Hours                                                    *
 * LY     Universal Norms for the Liturgical Year and the Calendar (Roman Missal) *
 * OM     Order of Matrimony                                                      *
 * PC     Instruction regarding Proper Calendars                                  *
 * RM     Roman Missal                                                            *
 * SC     Sacrosanctum Concilium, Conciliar Constitution on the Sacred Liturgy    *
 *                                                                                *
 *********************************************************************************/

include "Festivity.php"; //this defines a "Festivity" class that can hold all the useful information about a single celebration

/**
 *  THE ENTIRE LITURGICAL CALENDAR DEPENDS MAINLY ON THE DATE OF EASTER
 *  THE FOLLOWING LITCALFUNCTIONS.PHP DEFINES AMONG OTHER THINGS THE FUNCTION 
 *  FOR CALCULATING GREGORIAN EASTER FOR A GIVEN YEAR AS USED BY THE LATIN RITE
 */

include "LitCalFunctions.php"; //a few useful functions e.g. calculate Easter...

/**
 * INITIATE CONNECTION TO THE DATABASE 
 * AND CHECK FOR CONNECTION ERRORS
 * THE DATABASECONNECT() FUNCTION IS DEFINED IN LITCALFUNCTIONS.PHP 
 * WHICH IN TURN LOADS DATABASE CONNECTION INFORMATION FROM LITCALCONFIG.PHP
 * IF THE CONNECTION SUCCEEDS, THE FUNCTION WILL RETURN THE MYSQLI CONNECTION RESOURCE
 * IN THE MYSQLI PROPERTY OF THE RETURNED OBJECT
 */

$dbConnect = databaseConnect();
if($dbConnect->retString != "" && preg_match("/^Connected to MySQL Database:/",$dbConnect->retString) == 0){
	die("There was an error in the database connection: \n" . $dbConnect->retString);
}
else{
	$mysqli = $dbConnect->mysqli;
}




/**
 *  ONCE WE HAVE A SUCCESSFUL CONNECTION TO THE DATABASE
 *  WE SET UP SOME CONFIGURATION RULES
 *  WE CHECK IF ANY PARAMETERS ARE BEING SENT TO THE ENGINE TO INSTRUCT IT HOW TO PROCESS CERTAIN CASES
 *  SUCH AS EPIPHANY, ASCENSION, CORPUS CHRISTI
 *  EACH EPISCOPAL CONFERENCE HAS THE FACULTY OF CHOOSING SUNDAY BETWEEN JAN 2 AND JAN 8 INSTEAD OF JAN 6 FOR EPIPHANY, AND SUNDAY INSTEAD OF THURSDAY FOR ASCENSION AND CORPUS CHRISTI
 *  DEFAULTS TO UNIVERSAL ROMAN CALENDAR: EPIPHANY = JAN 6, ASCENSION = THURSDAY, CORPUS CHRISTI = THURSDAY
 *  AND IN WHICH FORMAT TO RETURN THE PROCESSED DATA (JSON OR XML)
 */

$allowed_returntypes = array("JSON","XML");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $YEAR = (isset($_POST["year"]) && is_numeric($_POST["year"]) && ctype_digit($_POST["year"]) && strlen($_POST["year"])===4) ? (int)$_POST["year"] : (int)date("Y");
    
    $EPIPHANY = (isset($_POST["epiphany"]) && ($_POST["epiphany"] === "JAN6" || $_POST["epiphany"] === "SUNDAY_JAN2_JAN8") ) ? $_POST["epiphany"] : "JAN6";
    $ASCENSION = (isset($_POST["ascension"]) && ($_POST["ascension"] === "THURSDAY" || $_POST["ascension"] === "SUNDAY") ) ? $_POST["ascension"] : "SUNDAY";
    $CORPUSCHRISTI = (isset($_POST["corpuschristi"]) && ($_POST["corpuschristi"] === "THURSDAY" || $_POST["corpuschristi"] === "SUNDAY") ) ? $_POST["corpuschristi"] : "SUNDAY";
    
    $returntype = isset($_POST["returntype"]) && in_array(strtoupper($_POST["returntype"]), $allowed_returntypes) ? strtoupper($_POST["returntype"]) : $allowed_returntypes[0]; // default to JSON
}
else if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $YEAR = (isset($_GET["year"]) && is_numeric($_GET["year"]) && ctype_digit($_GET["year"]) && strlen($_GET["year"])===4) ? (int)$_GET["year"] : (int)date("Y");
    
    $EPIPHANY = (isset($_GET["epiphany"]) && ($_GET["epiphany"] === "JAN6" || $_GET["epiphany"] === "SUNDAY_JAN2_JAN8") ) ? $_GET["epiphany"] : "JAN6";
    $ASCENSION = (isset($_GET["ascension"]) && ($_GET["ascension"] === "THURSDAY" || $_GET["ascension"] === "SUNDAY") ) ? $_GET["ascension"] : "SUNDAY";
    $CORPUSCHRISTI = (isset($_GET["corpuschristi"]) && ($_GET["corpuschristi"] === "THURSDAY" || $_GET["corpuschristi"] === "SUNDAY") ) ? $_GET["corpuschristi"] : "SUNDAY";

    $returntype = isset($_GET["returntype"]) && in_array(strtoupper($_GET["returntype"]), $allowed_returntypes) ? strtoupper($_GET["returntype"]) : $allowed_returntypes[0]; // default to JSON
}


    define("EPIPHANY",$EPIPHANY);
    //define(EPIPHANY,"SUNDAY_JAN2_JAN8");
    //define(EPIPHANY,"JAN6");

    define("ASCENSION",$ASCENSION);
    //define(ASCENSION,"THURSDAY");
    //define(ASCENSION,"SUNDAY");

    define("CORPUSCHRISTI",$CORPUSCHRISTI);
    //define(CORPUSCHRISTI,"THURSDAY");
    //define(CORPUSCHRISTI,"SUNDAY");


    /**
     *	DEFINE THE ORDER OF PRECEDENCE OF THE LITURGICAL DAYS AS INDICATED IN THE
     *  UNIVERSAL NORMS FOR THE LITURGICAL YEAR AND THE GENERAL ROMAN CALENDAR
     *  PROMULGATED BY THE MOTU PROPRIO "MYSTERII PASCHALIS" BY POPE PAUL VI ON FEBRUARY 14 1969
     *	https://w2.vatican.va/content/paul-vi/en/motu_proprio/documents/hf_p-vi_motu-proprio_19690214_mysterii-paschalis.html
     *  A COPY OF THE DOCUMENT IS INCLUDED ALONGSIDE THIS ENGINE, SEEING THAT THERE IS NO DIRECT ONLINE LINK TO THE ACTUAL NORMS
     */
    
    /*****************************************************
     * DEFINE THE ORDER OF IMPORTANCE OF THE FESTIVITIES *
     ****************************************************/

	// 				I.
    define("HIGHERSOLEMNITY",7);		// HIGHER RANKING SOLEMNITIES, THAT HAVE PRECEDENCE OVER ALL OTHERS:
						// 1. EASTER TRIDUUM
						// 2. CHRISTMAS, EPIPHANY, ASCENSION, PENTECOST
						//    SUNDAYS OF ADVENT, LENT AND EASTER
						//    ASH WEDNESDAY
						//    DAYS OF THE HOLY WEEK, FROM MONDAY TO THURSDAY
						//    DAYS OF THE OCTAVE OF EASTER
									
    define("SOLEMNITY",6);			// 3. SOLEMNITIES OF THE LORD, OF THE BLESSED VIRGIN MARY, OF THE SAINTS LISTED IN THE GENERAL CALENDAR
    						//    COMMEMORATION OF THE FAITHFUL DEPARTED
    						// 4. PARTICULAR SOLEMNITIES:	
						//		a) PATRON OF THE PLACE, OF THE COUNTRY OR OF THE CITY (CELEBRATION REQUIRED ALSO FOR RELIGIOUS COMMUNITIES);
    						//		b) SOLEMNITY OF THE DEDICATION AND OF THE ANNIVERSARY OF THE DEDICATION OF A CHURCH
    						//		c) SOLEMNITY OF THE TITLE OF A CHURCH
    						//		d) SOLEMNITY OF THE TITLE OR OF THE FOUNDER OR OF THE MAIN PATRON OF AN ORDER OR OF A CONGREGATION
    								
	// 				II.    								
    define("FEASTLORD",5);			// 5. FEASTS OF THE LORD LISTED IN THE GENERAL CALENDAR
    						// 6. SUNDAYS OF CHRISTMAS AND OF ORDINARY TIME
    define("FEAST",4);				// 7. FEASTS OF THE BLESSED VIRGIN MARY AND OF THE SAINTS IN THE GENERAL CALENDAR
    						// 8. PARTICULAR FEASTS:	
						//		a) MAIN PATRON OF THE DIOCESE
    						//		b) FEAST OF THE ANNIVERSARY OF THE DEDICATION OF THE CATHEDRAL
    						//		c) FEAST OF THE MAIN PATRON OF THE REGION OR OF THE PROVINCE, OF THE NATION, OF A LARGER TERRITORY
    						//		d) FEAST OF THE TITLE, OF THE FOUNDER, OF THE MAIN PATRON OF AN ORDER OR OF A CONGREGATION AND OF A RELIGIOUS PROVINCE
    						//		e) OTHER PARTICULAR FEASTS OF SOME CHURCH
						//		f) OTHER FEASTS LISTED IN THE CALENDAR OF EACH DIOCESE, ORDER OR CONGREGATION
						// 9. WEEKDAYS OF ADVENT FROM THE 17th TO THE 24th OF DECEMBER
						//    DAYS OF THE OCTAVE OF CHRISTMAS
						//    WEEKDAYS OF LENT 
    								
	// 				III.    								
    define("MEMORIAL",3);			// 10. MEMORIALS OF THE GENERAL CALENDAR
    						// 11. PARTICULAR MEMORIALS:	
						//		a) MEMORIALS OF THE SECONDARY PATRON OF A PLACE, OF A DIOCESE, OF A REGION OR A RELIGIOUS PROVINCE
    						//		b) OTHER MEMORIALS LISTED IN THE CALENDAR OF EACH DIOCESE, ORDER OR CONGREGATION
    define("MEMORIALOPT",2);			// 12. OPTIONAL MEMORIALS, WHICH CAN HOWEVER BE OBSERVED IN DAYS INDICATED AT N. 9, 
						//     ACCORDING TO THE NORMS DESCRIBED IN "PRINCIPLES AND NORMS" FOR THE LITURGY OF THE HOURS AND THE USE OF THE MISSAL
									
    define("COMMEMORATION",1);			//     SIMILARLY MEMORIALS CAN BE OBSERVED AS OPTIONAL MEMORIALS THAT SHOULD FALL DURING THE WEEKDAYS OF LENT
    
    define("WEEKDAY",0);			// 13. WEEKDAYS OF ADVENT UNTIL DECEMBER 16th
    						//     WEEKDAYS OF CHRISTMAS, FROM JANUARY 2nd UNTIL THE SATURDAY AFTER EPIPHANY
    						//     WEEKDAYS OF THE EASTER SEASON, FROM THE MONDAY AFTER THE OCTAVE OF EASTER UNTIL THE SATURDAY BEFORE PENTECOST
    						//     WEEKDAYS OF ORDINARY TIME
    								    
    //TODO: implement interface for adding Proper feasts and memorials...


    /**
     *  LET'S DEFINE SOME GLOBAL VARIABLES
     *  THAT WILL BE NEEDED THROUGHOUT THE ENGINE
     */

    $LitCal = array();

    $FIXED_DATE_SOLEMNITIES = array();

    //Let's create a Weekdays of Epiphany array 
    //so that later on when we add our Memorials 
    //we can remove a weekday of Epiphany that is overriden by a memorial
    $WeekdaysOfEpiphany = array();

	
    /**
     *  START FILLING OUR FESTIVITY OBJECT BASED ON THE ORDER OF PRECEDENCE OF LITURGICAL DAYS (LY 59)
     */
    
// I.
    //1. Easter Triduum of the Lord's Passion and Resurrection
    $LitCal["HolyThurs"]        = new Festivity("Holy Thursday",                      calcGregEaster($YEAR)->sub(new DateInterval('P3D')),            "white",    "mobile", HIGHERSOLEMNITY);
    $LitCal["GoodFri"]          = new Festivity("Good Friday",                        calcGregEaster($YEAR)->sub(new DateInterval('P2D')),            "red",      "mobile", HIGHERSOLEMNITY);
    $LitCal["EasterVigil"]      = new Festivity("Easter Vigil",                       calcGregEaster($YEAR)->sub(new DateInterval('P1D')),            "white",    "mobile", HIGHERSOLEMNITY);
    $LitCal["Easter"]           = new Festivity("Easter Sunday",                      calcGregEaster($YEAR),                                          "white",    "mobile", HIGHERSOLEMNITY);
    
    //2. Christmas, Epiphany, Ascension, and Pentecost
    $LitCal["Christmas"]        = new Festivity("Christmas",                          DateTime::createFromFormat('!j-n-Y', '25-12-'.$YEAR),           "white",    "fixed", HIGHERSOLEMNITY);
    
    if(EPIPHANY === "JAN6"){

        $LitCal["Epiphany"]     = new Festivity("Epiphany",                           DateTime::createFromFormat('!j-n-Y', '6-1-'.$YEAR),             "white",    "fixed", HIGHERSOLEMNITY);
        
        //If a Sunday occurs on a day from Jan. 2 through Jan. 5, it is called the "Second Sunday of Christmas"
        //Weekdays from Jan. 2 through Jan. 5 are called "*day before Epiphany"
        $nth=0;
        for($i=2;$i<=5;$i++){
            if((int)DateTime::createFromFormat('!j-n-Y', $i.'-1-'.$YEAR)->format('N') === 7){
                $LitCal["Christmas2"] = new Festivity("2".(ordSuffix(2))." Sunday of Christmas", DateTime::createFromFormat('!j-n-Y', $i.'-1-'.$YEAR), "white",     "mobile", FEAST);
            }
            else{
                $nth++;
                $LitCal["DayBeforeEpiphany".$nth] = new Festivity($nth.(ordSuffix($nth))." day before Epiphany", DateTime::createFromFormat('!j-n-Y', $i.'-1-'.$YEAR), "white",     "mobile");
                $WeekdaysOfEpiphany["DayBeforeEpiphany".$nth] = $LitCal["DayBeforeEpiphany".$nth]->date; 
            }
        }

        //Weekdays from Jan. 7 until the following Sunday are called "*day after Epiphany"
        $SundayAfterEpiphany = (int) DateTime::createFromFormat('!j-n-Y', '6-1-'.$YEAR)->modify('next Sunday')->format('j');
        if( $SundayAfterEpiphany !== 7 ){
            $nth=0;
            for($i=7;$i<$SundayAfterEpiphany;$i++){
                $nth++;
                $LitCal["DayAfterEpiphany".$nth] = new Festivity($nth.(ordSuffix($nth))." day after Epiphany", DateTime::createFromFormat('!j-n-Y', $i.'-1-'.$YEAR), "white",     "mobile");            
                $WeekdaysOfEpiphany["DayAfterEpiphany".$nth] = $LitCal["DayAfterEpiphany".$nth]->date; 
            }
        }
    }
    else if (EPIPHANY === "SUNDAY_JAN2_JAN8"){
        //If January 2nd is a Sunday, then go with Jan 2nd
        if((int)DateTime::createFromFormat('!j-n-Y', '2-1-'.$YEAR)->format('N') === 7){
            $LitCal["Epiphany"] = new Festivity("Epiphany",                           DateTime::createFromFormat('!j-n-Y', '2-1-'.$YEAR),             "white",    "mobile", HIGHERSOLEMNITY);        
        }
        //otherwise find the Sunday following Jan 2nd
        else{
            $SundayOfEpiphany = DateTime::createFromFormat('!j-n-Y', '2-1-'.$YEAR)->modify('next Sunday');
            $LitCal["Epiphany"] = new Festivity("Epiphany",                           $SundayOfEpiphany,                                              "white",    "mobile", HIGHERSOLEMNITY);
            
            //Weekdays from Jan. 2 until the following Sunday are called "*day before Epiphany"
            //echo $SundayOfEpiphany->format('j');
            $DayOfEpiphany = (int) $SundayOfEpiphany->format('j');
            
            $nth=0;
            
            for($i=2;$i<$DayOfEpiphany;$i++){
                $nth++;
                $LitCal["DayBeforeEpiphany".$nth] = new Festivity($nth.ordSuffix($nth)." day before Epiphany", DateTime::createFromFormat('!j-n-Y', $i.'-1-'.$YEAR), "white",     "mobile");
                $WeekdaysOfEpiphany["DayBeforeEpiphany".$nth] = $LitCal["DayBeforeEpiphany".$nth]->date; 
            }
            
            //If Epiphany occurs on or before Jan. 6, then the days of the week following Epiphany are called "*day after Epiphany" and the Sunday following Epiphany is the Baptism of the Lord.
            if($DayOfEpiphany < 7){                
                $SundayAfterEpiphany = (int)DateTime::createFromFormat('!j-n-Y', '2-1-'.$YEAR)->modify('next Sunday')->modify('next Sunday')->format('j');
                $nth = 0;
                for($i = $DayOfEpiphany+1; $i < $SundayAfterEpiphany;$i++){
                  $nth++;
                  $LitCal["DayAfterEpiphany".$nth] = new Festivity($nth.ordSuffix($nth)." day after Epiphany", DateTime::createFromFormat('!j-n-Y', $i.'-1-'.$YEAR), "white",     "mobile");            
                  $WeekdaysOfEpiphany["DayAfterEpiphany".$nth] = $LitCal["DayAfterEpiphany".$nth]->date; 
                }
            }
                
        }
        
    }
    
    if(ASCENSION === "THURSDAY"){
        $LitCal["Ascension"]    = new Festivity("Ascension",                          calcGregEaster($YEAR)->add(new DateInterval('P39D')),           "white",    "mobile", HIGHERSOLEMNITY);
        $LitCal["Easter7"]      = new Festivity("Seventh Sunday of Easter",           calcGregEaster($YEAR)->add(new DateInterval('P'.(7*6).'D')),    "white",    "mobile", HIGHERSOLEMNITY);
    }
    else if(ASCENSION === "SUNDAY"){
        $LitCal["Ascension"]    = new Festivity("Ascension",                          calcGregEaster($YEAR)->add(new DateInterval('P'.(7*6).'D')),    "white",    "mobile", HIGHERSOLEMNITY);
    }
    $LitCal["Pentecost"]        = new Festivity("Pentecost",                          calcGregEaster($YEAR)->add(new DateInterval('P'.(7*7).'D')),    "red",      "mobile", HIGHERSOLEMNITY);
    
    //Sundays of Advent, Lent, and Easter Time
    $LitCal["Advent4"]          = new Festivity("Fourth Sunday of Advent",            DateTime::createFromFormat('!j-n-Y', '25-12-'.$YEAR)->modify('last Sunday'),                                          "purple",   "mobile", HIGHERSOLEMNITY);
    $LitCal["Advent3"]          = new Festivity("Third Sunday of Advent / Gaudete",   DateTime::createFromFormat('!j-n-Y', '25-12-'.$YEAR)->modify('last Sunday')->sub(new DateInterval('P7D')),            "pink",     "mobile", HIGHERSOLEMNITY);
    $LitCal["Advent2"]          = new Festivity("Second Sunday of Advent",            DateTime::createFromFormat('!j-n-Y', '25-12-'.$YEAR)->modify('last Sunday')->sub(new DateInterval('P'.(2*7).'D')),    "purple",   "mobile", HIGHERSOLEMNITY);
    $LitCal["Advent1"]          = new Festivity("First Sunday of Advent",             DateTime::createFromFormat('!j-n-Y', '25-12-'.$YEAR)->modify('last Sunday')->sub(new DateInterval('P'.(3*7).'D')),    "purple",   "mobile", HIGHERSOLEMNITY);
    $LitCal["Lent1"]            = new Festivity("First Sunday of Lent",               calcGregEaster($YEAR)->sub(new DateInterval('P'.(6*7).'D')),    "purple",   "mobile", HIGHERSOLEMNITY);
    $LitCal["Lent2"]            = new Festivity("Second Sunday of Lent",              calcGregEaster($YEAR)->sub(new DateInterval('P'.(5*7).'D')),    "purple",   "mobile", HIGHERSOLEMNITY);
    $LitCal["Lent3"]            = new Festivity("Third Sunday of Lent",               calcGregEaster($YEAR)->sub(new DateInterval('P'.(4*7).'D')),    "purple",   "mobile", HIGHERSOLEMNITY);
    $LitCal["Lent4"]            = new Festivity("Fourth Sunday of Lent (Laetare)",    calcGregEaster($YEAR)->sub(new DateInterval('P'.(3*7).'D')),    "pink",     "mobile", HIGHERSOLEMNITY);
    $LitCal["Lent5"]            = new Festivity("Fifth Sunday of Lent",               calcGregEaster($YEAR)->sub(new DateInterval('P'.(2*7).'D')),    "purple",   "mobile", HIGHERSOLEMNITY);
    $LitCal["PalmSun"]          = new Festivity("Palm Sunday",                        calcGregEaster($YEAR)->sub(new DateInterval('P7D')),            "red",      "mobile", HIGHERSOLEMNITY);
    $LitCal["Easter2"]          = new Festivity("Second Sunday of Easter",            calcGregEaster($YEAR)->add(new DateInterval('P7D')),            "white",    "mobile", HIGHERSOLEMNITY);
    $LitCal["Easter3"]          = new Festivity("Third Sunday of Easter",             calcGregEaster($YEAR)->add(new DateInterval('P'.(7*2).'D')),    "white",    "mobile", HIGHERSOLEMNITY);
    $LitCal["Easter4"]          = new Festivity("Fourth Sunday of Easter",            calcGregEaster($YEAR)->add(new DateInterval('P'.(7*3).'D')),    "white",    "mobile", HIGHERSOLEMNITY);
    $LitCal["Easter5"]          = new Festivity("Fifth Sunday of Easter",             calcGregEaster($YEAR)->add(new DateInterval('P'.(7*4).'D')),    "white",    "mobile", HIGHERSOLEMNITY);
    $LitCal["Easter6"]          = new Festivity("Sixth Sunday of Easter",             calcGregEaster($YEAR)->add(new DateInterval('P'.(7*5).'D')),    "white",    "mobile", HIGHERSOLEMNITY);
    $LitCal["Trinity"]          = new Festivity("Holy Trinity Sunday",                calcGregEaster($YEAR)->add(new DateInterval('P'.(7*8).'D')),    "white",    "mobile", HIGHERSOLEMNITY);
    if(CORPUSCHRISTI === "THURSDAY"){
        $LitCal["CorpusChristi"]= new Festivity("Corpus Christi",                     calcGregEaster($YEAR)->add(new DateInterval('P'.(7*8+4).'D')),  "white",    "mobile", HIGHERSOLEMNITY);
    }
    else if(CORPUSCHRISTI === "SUNDAY"){
        $LitCal["CorpusChristi"]= new Festivity("Corpus Christi",                     calcGregEaster($YEAR)->add(new DateInterval('P'.(7*9).'D')),    "white",    "mobile", HIGHERSOLEMNITY);
    }
                
    //Ash Wednesday
    $LitCal["AshWednesday"]     = new Festivity("Ash Wednesday",                      calcGregEaster($YEAR)->sub(new DateInterval('P46D')),           "purple",   "mobile", HIGHERSOLEMNITY);

    //Weekdays of Holy Week from Monday to Thursday inclusive (that is, thursday morning chrism mass... the In Coena Domini mass begins the Easter Triduum)
    $LitCal["MonHolyWeek"]      = new Festivity("Monday of Holy Week",                calcGregEaster($YEAR)->sub(new DateInterval('P6D')),            "purple",   "mobile", HIGHERSOLEMNITY);
    $LitCal["TueHolyWeek"]      = new Festivity("Tuesday of Holy Week",               calcGregEaster($YEAR)->sub(new DateInterval('P5D')),            "purple",   "mobile", HIGHERSOLEMNITY);
    $LitCal["WedHolyWeek"]      = new Festivity("Wednesday of Holy Week",             calcGregEaster($YEAR)->sub(new DateInterval('P4D')),            "purple",   "mobile", HIGHERSOLEMNITY);
    
    //Days within the octave of Easter
    $LitCal["MonOctaveEaster"]  = new Festivity("Monday of the Octave of Easter",     calcGregEaster($YEAR)->add(new DateInterval('P1D')),            "white",    "mobile", HIGHERSOLEMNITY);
    $LitCal["TueOctaveEaster"]  = new Festivity("Tuesday of the Octave of Easter",    calcGregEaster($YEAR)->add(new DateInterval('P2D')),            "white",    "mobile", HIGHERSOLEMNITY);
    $LitCal["WedOctaveEaster"]  = new Festivity("Wednesday of the Octave of Easter",  calcGregEaster($YEAR)->add(new DateInterval('P3D')),            "white",    "mobile", HIGHERSOLEMNITY);
    $LitCal["ThuOctaveEaster"]  = new Festivity("Thursday of the Octave of Easter",   calcGregEaster($YEAR)->add(new DateInterval('P4D')),            "white",    "mobile", HIGHERSOLEMNITY);
    $LitCal["FriOctaveEaster"]  = new Festivity("Friday of the Octave of Easter",     calcGregEaster($YEAR)->add(new DateInterval('P5D')),            "white",    "mobile", HIGHERSOLEMNITY);
    $LitCal["SatOctaveEaster"]  = new Festivity("Saturday of the Octave of Easter",   calcGregEaster($YEAR)->add(new DateInterval('P6D')),            "white",    "mobile", HIGHERSOLEMNITY);
    

    array_push($FIXED_DATE_SOLEMNITIES,$LitCal["Advent1"]->date,$LitCal["Christmas"]->date);
    array_push($FIXED_DATE_SOLEMNITIES,$LitCal["AshWednesday"]->date,$LitCal["HolyThurs"]->date,$LitCal["GoodFri"]->date,$LitCal["EasterVigil"]->date);
    array_push($FIXED_DATE_SOLEMNITIES,$LitCal["MonOctaveEaster"]->date,$LitCal["TueOctaveEaster"]->date,$LitCal["WedOctaveEaster"]->date,$LitCal["ThuOctaveEaster"]->date,$LitCal["FriOctaveEaster"]->date,$LitCal["SatOctaveEaster"]->date);
    array_push($FIXED_DATE_SOLEMNITIES,$LitCal["Ascension"]->date,$LitCal["Pentecost"]->date,$LitCal["Trinity"]->date,$LitCal["CorpusChristi"]->date);
    
    
    //3. Solemnities of the Lord, of the Blessed Virgin Mary, and of saints listed in the General Calendar
    $LitCal["SacredHeart"]      = new Festivity("Sacred Heart of Jesus",              calcGregEaster($YEAR)->add(new DateInterval('P'.(7*9+5).'D')),  "red",      "mobile", SOLEMNITY);

    //depends on first sunday of advent
    $LitCal["ChristKing"]       = new Festivity("Christ the King",                    DateTime::createFromFormat('!j-n-Y', '25-12-'.$YEAR)->modify('last Sunday')->sub(new DateInterval('P'.(4*7).'D')),    "red",  "mobile", SOLEMNITY);
    array_push($FIXED_DATE_SOLEMNITIES,$LitCal["SacredHeart"]->date,$LitCal["ChristKing"]->date);
    //END MOBILE SOLEMNITIES
    
    //START FIXED SOLEMNITIES
    $LitCal["MotherGod"]        = new Festivity("Mary, Mother of God",                DateTime::createFromFormat('!j-n-Y', '1-1-'.$YEAR),             "white",    "fixed", SOLEMNITY);
    $LitCal["StJoseph"]         = new Festivity("Joseph, Husband of Mary",            DateTime::createFromFormat('!j-n-Y', '19-3-'.$YEAR),            "white",    "fixed", SOLEMNITY);
    $LitCal["Annunciation"]     = new Festivity("Annunciation",                       DateTime::createFromFormat('!j-n-Y', '25-3-'.$YEAR),            "white",    "fixed", SOLEMNITY);
    
    $LitCal["ImmConception"]    = new Festivity("Immaculate Conception",              DateTime::createFromFormat('!j-n-Y', '8-12-'.$YEAR),            "white",    "fixed", SOLEMNITY);
    
    $LitCal["BirthJohnBapt"]    = new Festivity("Birth of John the Baptist",          DateTime::createFromFormat('!j-n-Y', '24-6-'.$YEAR),            "white",    "fixed", SOLEMNITY);
    $LitCal["PeterPaulAp"]      = new Festivity("Peter and Paul, Ap",                 DateTime::createFromFormat('!j-n-Y', '29-6-'.$YEAR),            "red",      "fixed", SOLEMNITY);
    $LitCal["Assumption"]       = new Festivity("Assumption",                         DateTime::createFromFormat('!j-n-Y', '15-8-'.$YEAR),            "white",    "fixed", SOLEMNITY);
    $LitCal["AllSaints"]        = new Festivity("All Saints",                         DateTime::createFromFormat('!j-n-Y', '1-11-'.$YEAR),            "white",    "fixed", SOLEMNITY);
    
    //All Souls is treated like a Solemnity, in that in can over-rank a Sunday of Ordinary Time
    $LitCal["AllSouls"]        = new Festivity("All Souls",                           DateTime::createFromFormat('!j-n-Y', '2-11-'.$YEAR),            "purple",   "fixed", SOLEMNITY);


    
    //ENFORCE RULES FOR FIXED DATE SOLEMNITIES
    
    //If a fixed date Solemnity occurs on a Sunday of Lent or Advent, the Solemnity is transferred to the following Monday.  
    //This affects Joseph, Husband of Mary (Mar 19), Annunciation (Mar 25), and Immaculate Conception (Dec 8).  
    //It is not possible for a fixed date Solemnity to fall on a Sunday of Easter. 
    //(See the special case of a Solemnity during Holy Week below.)
    
    if($LitCal["ImmConception"]->date == $LitCal["Advent2"]->date){
        $LitCal["ImmConception"]->date->add(new DateInterval('P1D'));
    }
    
    if($LitCal["StJoseph"]->date == $LitCal["Lent1"]->date || $LitCal["StJoseph"]->date == $LitCal["Lent2"]->date || $LitCal["StJoseph"]->date == $LitCal["Lent3"]->date || $LitCal["StJoseph"]->date == $LitCal["Lent4"]->date || $LitCal["StJoseph"]->date == $LitCal["Lent5"]->date){
        $LitCal["StJoseph"]->date->add(new DateInterval('P1D'));
    }
    //If Joseph, Husband of Mary (Mar 19) falls on Palm Sunday or during Holy Week, it is moved to the Saturday preceding Palm Sunday.
    else if($LitCal["StJoseph"]->date >= $LitCal["PalmSun"]->date && $LitCal["StJoseph"]->date <= $LitCal["Easter"]->date){
        $LitCal["StJoseph"]->date = calcGregEaster($YEAR)->sub(new DateInterval('P8D'));
    }
    
    if($LitCal["Annunciation"]->date == $LitCal["Lent2"]->date || $LitCal["Annunciation"]->date == $LitCal["Lent3"]->date || $LitCal["Annunciation"]->date == $LitCal["Lent4"]->date || $LitCal["Annunciation"]->date == $LitCal["Lent5"]->date){
        $LitCal["Annunciation"]->date->add(new DateInterval('P1D'));
    }
    //If the Annunciation (Mar 25) falls on Palm Sunday, it is celebrated on the Saturday preceding.
    //Actually this seems not to be the case, it seems to simply be a tradition of some German churches:
    //In some German churches it was the custom to keep its office the Saturday before Palm Sunday if the 25th of March fell in Holy Week.
    //source: http://www.newadvent.org/cathen/01542a.htm
    /*
    else if($LitCal["Annunciation"]->date == $LitCal["PalmSun"]->date){
        $LitCal["Annunciation"]->date->add(new DateInterval('P15D'));
        //$LitCal["Annunciation"]->date->sub(new DateInterval('P1D'));
    }
    */
    //If it falls during Holy Week or within the Octave of Easter, the Annunciation is transferred to the Monday of the Second Week of Easter.
    else if($LitCal["Annunciation"]->date >= $LitCal["PalmSun"]->date && $LitCal["Annunciation"]->date <= $LitCal["Easter2"]->date){
        $LitCal["Annunciation"]->date = calcGregEaster($YEAR)->add(new DateInterval('P8D'));
    }

    array_push($FIXED_DATE_SOLEMNITIES,$LitCal["BirthJohnBapt"]->date,$LitCal["PeterPaulAp"]->date,$LitCal["Assumption"]->date,$LitCal["AllSaints"]->date,$LitCal["AllSouls"]->date);
    array_push($FIXED_DATE_SOLEMNITIES,$LitCal["StJoseph"]->date,$LitCal["Annunciation"]->date,$LitCal["ImmConception"]->date);
    
    //4. Proper solemnities
    //TODO: Intregrate proper solemnities
    // END SOLEMNITIES, BOTH MOBILE AND FIXED

    if(!in_array(calcGregEaster($YEAR)->add(new DateInterval('P'.(7*9+6).'D')),$FIXED_DATE_SOLEMNITIES) ){
        $LitCal["ImmaculateHeart"]  = new Festivity("Immaculate Heart of Mary",       calcGregEaster($YEAR)->add(new DateInterval('P'.(7*9+6).'D')),  "red",      "mobile", MEMORIAL);
        //In years when this memorial coincides with another obligatory memorial, as happened in 2014 [28 June, Saint Irenaeus] and 2015 [13 June, Saint Anthony of Padua], both must be considered optional for that year
        //source: http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000630_memoria-immaculati-cordis-mariae-virginis_lt.html
    }
    
    //II.
    //5. FEASTS OF THE LORD IN THE GENERAL CALENDAR
    
    //Baptism of the Lord is celebrated the Sunday after Epiphany, for exceptions see immediately below... 
    $BaptismLordFmt = '6-1-'.$YEAR;
    $BaptismLordMod = 'next Sunday';
    //If Epiphany is celebrated on Sunday between Jan. 2 - Jan 8, and Jan. 7 or Jan. 8 is Sunday, then Baptism of the Lord is celebrated on the Monday immediately following that Sunday
    if(EPIPHANY === "SUNDAY_JAN2_JAN8"){
        if((int)DateTime::createFromFormat('!j-n-Y', '7-1-'.$YEAR)->format('N') === 7){
            $BaptismLordFmt = '7-1-'.$YEAR;
            $BaptismLordMod = 'next Monday';
        }
        else if((int)DateTime::createFromFormat('!j-n-Y', '8-1-'.$YEAR)->format('N') === 7){
            $BaptismLordFmt = '8-1-'.$YEAR;
            $BaptismLordMod = 'next Monday';
        }
    }
    $LitCal["BaptismLord"]      = new Festivity("Baptism of the Lord",                DateTime::createFromFormat('!j-n-Y', $BaptismLordFmt)->modify($BaptismLordMod),"white","mobile", FEASTLORD);



    
    $LitCal["Presentation"]     = new Festivity("Presentation of the Lord",           DateTime::createFromFormat('!j-n-Y', '2-2-'.$YEAR),             "white",    "fixed", FEASTLORD);
    $LitCal["Transfiguration"]  = new Festivity("Transfiguration of the Lord",        DateTime::createFromFormat('!j-n-Y', '6-8-'.$YEAR),             "white",    "fixed", FEASTLORD);
    $LitCal["HolyCross"]        = new Festivity("Triumph of the Cross",               DateTime::createFromFormat('!j-n-Y', '14-9-'.$YEAR),            "red",      "fixed", FEASTLORD);
    
    //Sunday after Christmas, unless Christmas fall on Sunday then Dec. 30
    if((int)DateTime::createFromFormat('!j-n-Y', '25-12-'.$YEAR)->format('N') === 7){
        $LitCal["HolyFamily"]   = new Festivity("Holy Family",                        DateTime::createFromFormat('!j-n-Y', '30-12-'.$YEAR),           "white",    "mobile", FEASTLORD);
    }
    else{
        $LitCal["HolyFamily"]   = new Festivity("Holy Family",                        DateTime::createFromFormat('!j-n-Y', '25-12-'.$YEAR)->modify('next Sunday'),                                          "white","mobile", FEASTLORD);
    }
    //END FEASTS OF OUR LORD
    
    
    //If a fixed date Solemnity occurs on a Sunday of Ordinary Time or on a Sunday of Christmas, the Solemnity is celebrated in place of the Sunday. (e.g., Birth of John the Baptist, 1990)
    //If a fixed date Feast of the Lord occurs on a Sunday in Ordinary Time, the feast is celebrated in place of the Sunday
    array_push($FIXED_DATE_SOLEMNITIES,$LitCal["Presentation"]->date,$LitCal["Transfiguration"]->date,$LitCal["HolyCross"]->date);


    
    //6. SUNDAYS OF CHRISTMAS TIME AND SUNDAYS IN ORDINARY TIME
    
    //Sundays of Ordinary Time in the First part of the year are numbered from after the Baptism of the Lord (which begins the 1st week of Ordinary Time) until Ash Wednesday
    $firstOrdinary = DateTime::createFromFormat('!j-n-Y', $BaptismLordFmt)->modify($BaptismLordMod);
    //Basically we take Ash Wednesday as the limit... 
    //Here is (Ash Wednesday - 7) since one more cycle will complete...
    $firstOrdinaryLimit = calcGregEaster($YEAR)->sub(new DateInterval('P53D'));
    $ordSun = 1;
    while($firstOrdinary >= $LitCal["BaptismLord"]->date && $firstOrdinary < $firstOrdinaryLimit){
        $firstOrdinary = DateTime::createFromFormat('!j-n-Y', $BaptismLordFmt)->modify($BaptismLordMod)->modify('next Sunday')->add(new DateInterval('P'.(($ordSun-1)*7).'D'));
        $ordSun++;
        if(!in_array($firstOrdinary,$FIXED_DATE_SOLEMNITIES) ){
            $LitCal["OrdSunday".$ordSun] = new Festivity($ordSun.ordSuffix($ordSun)." Sunday of Ordinary Time",$firstOrdinary,"green","mobile");
            //add Sundays to our priority list for next checking against ordinary Feasts not of Our Lord
            array_push($FIXED_DATE_SOLEMNITIES,$firstOrdinary);
        }
    }
    

    //Sundays of Ordinary Time in the Latter part of the year are numbered backwards from Christ the King (34th) to Pentecost
    $lastOrdinary = DateTime::createFromFormat('!j-n-Y', '25-12-'.$YEAR)->modify('last Sunday')->sub(new DateInterval('P'.(4*7).'D'));
    //We take Trinity Sunday as the limit...
    //Here is (Trinity Sunday + 7) since one more cycle will complete...
    $lastOrdinaryLowerLimit = calcGregEaster($YEAR)->add(new DateInterval('P'.(7*9).'D'));
    $ordSun = 34;
    $ordSunCycle = 4;
    
    while($lastOrdinary <= $LitCal["ChristKing"]->date && $lastOrdinary > $lastOrdinaryLowerLimit){
        $lastOrdinary = DateTime::createFromFormat('!j-n-Y', '25-12-'.$YEAR)->modify('last Sunday')->sub(new DateInterval('P'.(++$ordSunCycle * 7).'D'));
        $ordSun--;
        if(!in_array($lastOrdinary,$FIXED_DATE_SOLEMNITIES) ){
            $LitCal["OrdSunday".$ordSun] = new Festivity($ordSun.ordSuffix($ordSun)." Sunday of Ordinary Time",$lastOrdinary,"green","mobile");
            //add Sundays to our priority list for next checking against ordinary Feasts not of Our Lord
            array_push($FIXED_DATE_SOLEMNITIES,$lastOrdinary);
        }
    }

    //END SUNDAYS OF CHRISTMAS TIME AND SUNDAYS IN ORDINARY TIME
    
    
    //7. FEASTS OF THE BLESSED VIRGIN MARY AND OF THE SAINTS IN THE GENERAL CALENDAR
    
    //MEMORIALS AND OPTIONAL MEMORIALS

    //If a Feast (not of the Lord) occurs on a Sunday in Ordinary Time, the Sunday is celebrated.  (e.g., St. Luke, 1992)
    //We will look up Feasts, Memorials and Optional Memorials from the MySQL table of festivities of the Roman Calendar
    if($result = $mysqli->query("SELECT * FROM LITURGY__calendar_fixed WHERE GRADE < ".FEASTLORD." AND GRADE > ".COMMEMORATION)){
        while($row = mysqli_fetch_assoc($result)){
            
            //If it doesn't occur on a Sunday or a Solemnity or a Feast of the Lord, then go ahead and create the Festivity
            $currentFeastDate = DateTime::createFromFormat('!j-n-Y', $row["DAY"].'-'.$row["MONTH"].'-'.$YEAR);
            if((int)$currentFeastDate->format('N') !== 7 && !in_array($currentFeastDate,$FIXED_DATE_SOLEMNITIES) ){
                $LitCal[$row["TAG"]] = new Festivity($row["NAME"],$currentFeastDate,$row["COLOR"],"fixed",$row["GRADE"],$row["COMMON"]);
                
                //If a fixed date Memorial or Optional Memorial falls within the Lenten season, it is reduced in rank to a Commemoration.
                //TODO: exclude or include Ash Wednesday? In order to exclude, change ">=" to ">"
                if($currentFeastDate >= $LitCal["AshWednesday"]->date && $currentFeastDate < $LitCal["HolyThurs"]->date ){
                    $LitCal[$row["TAG"]]->grade = COMMEMORATION;
                }
                
                //Add Feasts and Memorials to the HIGHER GRADE SOLEMNITIES array, since they will override the weekdays of ordinary time...
                if($LitCal[$row["TAG"]]->grade > MEMORIALOPT){
                    array_push($FIXED_DATE_SOLEMNITIES,$currentFeastDate);
                    //Also, while we're add it, let's remove the weekdays of Epiphany that get overriden by memorials
                    if(false !== $key = array_search($LitCal[$row["TAG"]]->date,$WeekdaysOfEpiphany) ){
                        unset($LitCal[$key]);
                    }
                    //Also while we're at it, in years when the memorial of the Immaculate Heart of Mary coincides with another obligatory memorial, 
                    //as happened in 2014 [28 June, Saint Irenaeus] and 2015 [13 June, Saint Anthony of Padua], both must be considered optional for that year
                    //source: http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000630_memoria-immaculati-cordis-mariae-virginis_lt.html
                    if(isset($LitCal["ImmaculateHeart"]) && $currentFeastDate == $LitCal["ImmaculateHeart"]->date){
                        $LitCal["ImmaculateHeart"]->grade = MEMORIALOPT;
                        $LitCal[$row["TAG"]]->grade = MEMORIALOPT;
                    }
                }
            }
        }
    }
        
    //END FEASTS NOT OF THE LORD, MEMORIALS AND OPTIONAL MEMORIALS

    //9. WEEKDAYS of ADVENT FROM 17 DECEMBER TO 24 DECEMBER INCLUSIVE
    
    $DoMAdvent1 = $LitCal["Advent1"]->date->format('j');
    $MonthAdvent1 = $LitCal["Advent1"]->date->format('n');    
    $weekdayAdvent = DateTime::createFromFormat('!j-n-Y', $DoMAdvent1.'-'.$MonthAdvent1.'-'.$YEAR);
    $weekdayAdventCnt = 1;
    while($weekdayAdvent >= $LitCal["Advent1"]->date && $weekdayAdvent < $LitCal["Christmas"]->date){
        $weekdayAdvent = DateTime::createFromFormat('!j-n-Y', $DoMAdvent1.'-'.$MonthAdvent1.'-'.$YEAR)->add(new DateInterval('P'.$weekdayAdventCnt.'D'));
        
        if(!in_array($weekdayAdvent,$FIXED_DATE_SOLEMNITIES) && (int)$weekdayAdvent->format('N') !== 7 ){
            $upper = (int)$weekdayAdvent->format('z');
            $diff = $upper - (int)$LitCal["Advent1"]->date->format('z'); //day count between current day and First Sunday of Advent
            $currentAdvWeek = (($diff - $diff % 7) / 7) + 1; //week count between current day and First Sunday of Advent
        
            $LitCal["AdventWeekday".$weekdayAdventCnt] = new Festivity($weekdayAdvent->format('l')." of the ".$currentAdvWeek.ordSuffix($currentAdvWeek)." Week of Advent",$weekdayAdvent,"purple","mobile");
            
        }  
        
        $weekdayAdventCnt++;
    }
    
    //DAYS WITHIN THE OCTAVE OF CHRISTMAS

    //WEEKDAYS of LENT
    
    $DoMAshWednesday = $LitCal["AshWednesday"]->date->format('j');
    $MonthAshWednesday = $LitCal["AshWednesday"]->date->format('n');    
    $weekdayLent = DateTime::createFromFormat('!j-n-Y', $DoMAshWednesday.'-'.$MonthAshWednesday.'-'.$YEAR);
    $weekdayLentCnt = 1;
    while($weekdayLent >= $LitCal["AshWednesday"]->date && $weekdayLent < $LitCal["PalmSun"]->date){
        $weekdayLent = DateTime::createFromFormat('!j-n-Y', $DoMAshWednesday.'-'.$MonthAshWednesday.'-'.$YEAR)->add(new DateInterval('P'.$weekdayLentCnt.'D'));
        
        if(!in_array($weekdayLent,$FIXED_DATE_SOLEMNITIES) && (int)$weekdayLent->format('N') !== 7 ){
            
            if($weekdayLent > $LitCal["Lent1"]->date){
              $upper = (int)$weekdayLent->format('z');
              $diff = $upper - (int)$LitCal["Lent1"]->date->format('z'); //day count between current day and First Sunday of Lent
              $currentLentWeek = (($diff - $diff % 7) / 7) + 1; //week count between current day and First Sunday of Lent
              $LitCal["LentWeekday".$weekdayLentCnt] = new Festivity($weekdayLent->format('l')." of the ".$currentLentWeek.ordSuffix($currentLentWeek)." Week of Lent",$weekdayLent,"purple","mobile");
            }
            else{
              $LitCal["LentWeekday".$weekdayLentCnt] = new Festivity($weekdayLent->format('l')." after Ash Wednesday",$weekdayLent,"purple","mobile");
            }
            
        }  
        
        $weekdayLentCnt++;
    }
    
    
    //WEEKDAYS of the EASTER Season
    $DoMEaster = $LitCal["Easter"]->date->format('j');
    $MonthEaster = $LitCal["Easter"]->date->format('n');    
    $weekdayEaster = DateTime::createFromFormat('!j-n-Y', $DoMEaster.'-'.$MonthEaster.'-'.$YEAR);
    $weekdayEasterCnt = 1;
    while($weekdayEaster >= $LitCal["Easter"]->date && $weekdayEaster < $LitCal["Pentecost"]->date){
        $weekdayEaster = DateTime::createFromFormat('!j-n-Y', $DoMEaster.'-'.$MonthEaster.'-'.$YEAR)->add(new DateInterval('P'.$weekdayEasterCnt.'D'));
        
        if(!in_array($weekdayEaster,$FIXED_DATE_SOLEMNITIES) && (int)$weekdayEaster->format('N') !== 7 ){
            
            $upper = (int)$weekdayEaster->format('z');
            $diff = $upper - (int)$LitCal["Easter"]->date->format('z'); //day count between current day and Easter Sunday
            $currentEasterWeek = (($diff - $diff % 7) / 7) + 1; //week count between current day and Easter Sunday
            $LitCal["EasterWeekday".$weekdayEasterCnt] = new Festivity($weekdayEaster->format('l')." of the ".$currentEasterWeek.ordSuffix($currentEasterWeek)." Week of Easter",$weekdayEaster,"white","mobile");
            
        }  
        
        $weekdayEasterCnt++;
    }
        
    //WEEKDAYS of the CHRISTMAS Season
    //Now this is interesting because in the same YEAR we are dealing with two separate liturgical years for the Christmas season
    //From Jan. 1st until Epiphany is Christmas time of one liturgical year, 
    //while from Christmas day until December 31st is Christmas time of the next liturgical year
    //However all we really need to deal with is days from Christmas to New Year, 
    //since those following New Year are already dealt with by Epiphany
    $weekdayChristmas = DateTime::createFromFormat('!j-n-Y', '25-12-'.$YEAR);
    $weekdayChristmasCnt = 1;
    while($weekdayChristmas >= $LitCal["Christmas"]->date && $weekdayChristmas < DateTime::createFromFormat('!j-n-Y', '31-12-'.$YEAR)){
        $weekdayChristmas = DateTime::createFromFormat('!j-n-Y', '25-12-'.$YEAR)->add(new DateInterval('P'.$weekdayChristmasCnt.'D'));
        
        if(!in_array($weekdayChristmas,$FIXED_DATE_SOLEMNITIES) && (int)$weekdayChristmas->format('N') !== 7 ){
            
            //$upper = (int)$weekdayChristmas->format('z');
            //$diff = $upper - (int)$LitCal["Easter"]->date->format('z'); //day count between current day and Easter Sunday
            //$currentEasterWeek = (($diff - $diff % 7) / 7) + 1; //week count between current day and Easter Sunday
            $LitCal["ChristmasWeekday".$weekdayChristmasCnt] = new Festivity(($weekdayChristmasCnt+1).ordSuffix($weekdayChristmasCnt+1)." Day of the Octave of Christmas",$weekdayChristmas,"white","mobile");
            
        }  
        
        $weekdayChristmasCnt++;
    }

    
    //WEEKDAYS of ORDINARY TIME
    //In the first part of the year, weekdays of ordinary time begin the day after the Baptism of the Lord
    $FirstWeekdaysLowerLimit = $LitCal["BaptismLord"]->date;
    //and end with Ash Wednesday
    $FirstWeekdaysUpperLimit = $LitCal["AshWednesday"]->date;
    
    $ordWeekday = 1;
    $currentOrdWeek = 1;
    $firstOrdinary = DateTime::createFromFormat('!j-n-Y', $BaptismLordFmt)->modify($BaptismLordMod);
    $firstSunday = DateTime::createFromFormat('!j-n-Y', $BaptismLordFmt)->modify($BaptismLordMod)->modify('next Sunday');
    $dayFirstSunday = (int)$firstSunday->format('z');

    while($firstOrdinary >= $FirstWeekdaysLowerLimit && $firstOrdinary < $FirstWeekdaysUpperLimit){
        $firstOrdinary = DateTime::createFromFormat('!j-n-Y', $BaptismLordFmt)->modify($BaptismLordMod)->add(new DateInterval('P'.$ordWeekday.'D'));
        if(!in_array($firstOrdinary,$FIXED_DATE_SOLEMNITIES) ){
            //The Baptism of the Lord is the First Sunday, so the weekdays following are of the First Week of Ordinary Time
            //After the Second Sunday, let's calculate which week of Ordinary Time we're in
            if($firstOrdinary > $firstSunday){
                $upper = (int) $firstOrdinary->format('z');
                $diff = $upper - $dayFirstSunday;
                $currentOrdWeek = (($diff - $diff % 7) / 7) + 2; 
            }
            $LitCal["FirstOrdWeekday".$ordWeekday] = new Festivity($firstOrdinary->format('l')." of the ".$currentOrdWeek.ordSuffix($currentOrdWeek)." Week of Ordinary Time",$firstOrdinary,"green","mobile");
            //add Sundays to our priority list for next checking against ordinary Feasts not of Our Lord
            //array_push($FIXED_DATE_SOLEMNITIES,$firstOrdinary);
        }
        $ordWeekday++;
    }
    
    
    //In the second part of the year, weekdays of ordinary time begin the day after Pentecost
    $SecondWeekdaysLowerLimit = $LitCal["Pentecost"]->date;
    //and end with the Feast of Christ the King
    $SecondWeekdaysUpperLimit = DateTime::createFromFormat('!j-n-Y', '25-12-'.$YEAR)->modify('last Sunday')->sub(new DateInterval('P'.(3*7).'D'));
    
    $ordWeekday = 1;
    //$currentOrdWeek = 1;
    $lastOrdinary = calcGregEaster($YEAR)->add(new DateInterval('P'.(7*7).'D'));
    $dayLastSunday = (int)DateTime::createFromFormat('!j-n-Y', '25-12-'.$YEAR)->modify('last Sunday')->sub(new DateInterval('P'.(3*7).'D'))->format('z');

    while($lastOrdinary >= $SecondWeekdaysLowerLimit && $lastOrdinary < $SecondWeekdaysUpperLimit){
        $lastOrdinary = calcGregEaster($YEAR)->add(new DateInterval('P'.(7*7+$ordWeekday).'D'));
        if(!in_array($lastOrdinary,$FIXED_DATE_SOLEMNITIES) ){
            $lower = (int) $lastOrdinary->format('z');
            $diff = $dayLastSunday - $lower; //day count between current day and Christ the King Sunday
            $weekDiff = (($diff - $diff % 7) / 7); //week count between current day and Christ the King Sunday;
            $currentOrdWeek = 34 - $weekDiff; 
                
            $LitCal["LastOrdWeekday".$ordWeekday] = new Festivity($lastOrdinary->format('l')." of the ".$currentOrdWeek.ordSuffix($currentOrdWeek)." Week of Ordinary Time",$lastOrdinary,"green","mobile");
            //add Sundays to our priority list for next checking against ordinary Feasts not of Our Lord
            //array_push($FIXED_DATE_SOLEMNITIES,$firstOrdinary);
        }
        $ordWeekday++;
    }


    //END WEEKDAYS of ORDINARY TIME
    
    uasort($LitCal,array("Festivity", "comp_date"));

    $SerializeableLitCal = new StdClass();
    $SerializeableLitCal->LitCal = $LitCal;
    $SerializeableLitCal->Settings = new stdClass();
    $SerializeableLitCal->Settings->YEAR = $YEAR;
    $SerializeableLitCal->Settings->EPIPHANY = EPIPHANY;
    $SerializeableLitCal->Settings->ASCENSION = ASCENSION;
    $SerializeableLitCal->Settings->CORPUSCHRISTI = CORPUSCHRISTI;

    switch($returntype){
        case "JSON":
            header('Content-Type: application/json');
            echo json_encode($SerializeableLitCal);
            break;
        case "XML": 
            //header("Content-type: text/html");
            header('Content-Type: application/xml; charset=utf-8');
            $jsonStr = json_encode($SerializeableLitCal);
            $jsonObj = json_decode($jsonStr,true);
            $root = "<?xml version=\"1.0\" encoding=\"UTF-8\"?"."><LitCalRoot/>";
            $xml = new SimpleXMLElement($root);
            convertArray2XML($xml,$jsonObj);  
            print $xml->asXML();
            break;
        /*
        case "HTML":
            header("Content-type: text/html");
            break;
        */
        default:
            header('Content-Type: application/json');
            echo json_encode($SerializeableLitCal);
            break;            
    }
?>
