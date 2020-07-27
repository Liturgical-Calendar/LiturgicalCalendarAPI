<?php

function __($key,$locale="la"){
    global $MESSAGES;
    $locale = strtolower($locale);
    if(isset($MESSAGES[$key])){
        if(isset($MESSAGES[$key][$locale])){
            return $MESSAGES[$key][$locale];
        }
        else{
            return $key;
        }
    }
    else return $key;
}

/**
 * Function _G
 * Returns a translated string with the Grade (Rank) of the Festivity
 */
function _G($key,$locale="la",$html=true){
    $locale = strtolower($locale);
    $key = (int)$key;
    $grade = __("FERIA",$locale);
    switch($key){
        case 0: 
            $grade = __("FERIA",$locale);
        break;
        case 1: 
            $grade = __("COMMEMORATION",$locale);
        break;
        case 2: 
            $grade = __("OPTIONAL MEMORIAL",$locale);
        break;
        case 3: 
            $grade = __("MEMORIAL",$locale);
        break;
        case 4: 
            $grade = __("FEAST",$locale);
        break;
        case 5: 
            $grade = __("FEAST OF THE LORD",$locale);
        break;
        case 6: 
            $grade = __("SOLEMNITY",$locale);
        break;
        case 7: 
            $grade = __("HIGHER RANKING SOLEMNITY",$locale);
        break;
    }
    return $html === true ? $grade : strip_tags($grade);
}

/**
 * Function _C
 * Gets a translated human readable string with the Common or the Proper
 */
function _C($common,$locale="la"){
    $locale = strtolower($locale);
    if ($common !== "" && $common !== "Proper") {
        $commons = explode("|", $common);
        $commons = array_map(function ($txt) use ($locale) {
            $commonArr = explode(":", $txt);
            $commonGeneral = __($commonArr[0], $locale);
            $commonSpecific = isset($commonArr[1]) && $commonArr[1] != "" ? __($commonArr[1], $locale) : "";
            //$txt = str_replace(":", ": ", $txt);
            switch ($commonGeneral) {
                case __("Blessed Virgin Mary", $locale):
                    $commonKey = "of (SING_FEMM)";
                    break;
                case __("Virgins", $locale):
                    $commonKey = "of (PLUR_FEMM)";
                    break;
                case __("Martyrs", $locale):
                case __("Pastors", $locale):
                case __("Doctors", $locale):
                case __("Holy Men and Women", $locale):
                    $commonKey = "of (PLUR_MASC)";
                    break;
                default:
                    $commonKey = "of (SING_MASC)";
            }
            return __("From the Common", $locale) . " " . __($commonKey, $locale) . " " . $commonGeneral . ($commonSpecific != "" ? ": " . $commonSpecific : "");
        }, $commons);
        $common = implode("; " . __("or", $locale) . " ", $commons);
    } else if ($common == "Proper") {
        $common = __("Proper", $locale);
    }
    return $common;
}

function ColorToHex($color){
    $hex = "#";
    switch($color){
        case "red":
            $hex .= "FF0000";
        break;
        case "green":
            $hex .= "00AA00";
        break;
        case "white":
            $hex .= "AAAAAA";
        break;
        case "purple":
            $hex .= "AA00AA";
        break;
        case "pink":
            $hex .= "FFAAAA";
        break;
        default:
            $hex .= "000000";
    }
    return $hex;
}

function ParseColorString($string,$LOCALE,$html=false){
    if($html === true){
        if(strpos($string,"|")){
            $colors = explode("|",$string);
            $colors = array_map(function($txt) use ($LOCALE){
                return '<B><I><SPAN LANG=' . strtolower($LOCALE) . '><FONT FACE="Calibri" COLOR="' . ColorToHex($txt) . '">' . __($txt,$LOCALE) . '</FONT></SPAN></I></B>';
            },$colors);
            return implode(' <I><FONT FACE="Calibri">' . __("or",$LOCALE) . "</FONT></I> ", $colors);
        }
        else{
            return '<B><I><SPAN LANG=' . strtolower($LOCALE) . '><FONT FACE="Calibri" COLOR="' . ColorToHex($string) . '">' . __($string,$LOCALE) . '</FONT></SPAN></I></B>';
        }    
    }
    else{
        if(strpos($string,"|")){
            $colors = explode("|",$string);
            $colors = array_map(function($txt) use ($LOCALE){
                return __($txt,$LOCALE);
            },$colors);
            return implode(" " . __("or",$LOCALE) . " ",$colors);
        }
        else{
            return __($string,$LOCALE);
        }
    }
    return $string; //should never get here
}

$MESSAGES = [
    "%s day before Epiphany" => [
        "en" => "%s day before Epiphany",
        "it" => "%s giorno prima dell'Epifania",
        "la" => "Dies %s ante Epiphaniam"
    ],
    "%s day after Epiphany" => [
        "en" => "%s day after Epiphany",
        "it" => "%s giorno dopo l'Epifania",
        "la" => "Dies %s post Epiphaniam"
    ],
    "of the %s Week of Ordinary Time" => [
        "en" => "of the %s Week of Ordinary Time",
        "it" => "della %s Settimana del Tempo Ordinario",
        "la" => "Hebdomadæ %s Temporis Ordinarii"
    ],
    "of the %s Week of Easter" => [
        "en" => "of the %s Week of Easter",
        "it" => "della %s Settimana di Pasqua",
        "la" => "Hebdomadæ %s Temporis Paschali"
    ],
    "of the %s Week of Advent" => [
        "en" => "of the %s Week of Advent",
        "it" => "della %s Settimana dell'Avvento",
        "la" => "Hebdomadæ %s Adventus"
    ],
    "%s Day of the Octave of Christmas" => [
        "en" => "%s Day of the Octave of Christmas",
        "it" => "%s Giorno dell'Ottava di Natale",
        "la" => "Dies %s Octavæ Nativitatis"
    ],
    "of the %s Week of Lent" => [
        "en" => "of the %s Week of Lent",
        "it" => "della %s Settimana di Quaresima",
        "la" => "Hebdomadæ %s Quadragesimæ"
    ],
    "after Ash Wednesday" => [
        "en" => "after Ash Wednesday",
        "it" => "dopo il Mercoledì delle Ceneri",
        "la" => "post Feria IV Cinerum"
    ],
    /* The following strings would usually be used by a user-facing application, 
     *  however I decided to add them here seeing they are just as useful for generating
     *  the ICS calendar output, which is pretty final as it is...
     */
    "YEAR" => [
        "en" => "YEAR",
        "it" => "ANNO",
        "la" => "ANNUM"
    ],
    "From the Common" => [
        "en" => "From the Common",
        "it" => "Dal Comune",
        "la" => "De Communi"
    ],
    "of (SING_MASC)" => [
        "en" => "of",
        "it" => "del",
        "la" => ""
    ],
    "of (SING_FEMM)" => [
        "en" => "of the",
        "it" => "della",
        "la" => "" //latin expresses the genitive in the declination of the noun, no need for a preposition, leave empty
    ],
    "of (PLUR_MASC)" => [
        "en" => "of",
        "it" => "dei",
        "la" => "" //latin expresses the genitive in the declination of the noun, no need for a preposition, leave empty
    ],
    "of (PLUR_MASC_ALT)" => [
        "en" => "of",
        "it" => "degli",
        "la" => "" //latin expresses the genitive in the declination of the noun, no need for a preposition, leave empty
    ],
    "of (PLUR_FEMM)" => [
        "en" => "of",
        "it" => "delle",
        "la" => "" //latin expresses the genitive in the declination of the noun, no need for a preposition, leave empty
    ],
    /*translators: in reference to the Common of the Blessed Virgin Mary */
    "Blessed Virgin Mary" => [
        "en" => "Blessed Virgin Mary",
        "it" => "Beata Vergine Maria",
        "la" => "Beatæ Virginis Mariæ"
    ],
    /*translators: all of the following are in the genitive case, in reference to "from the Common of %s" */
    "Martyrs" => [
        "en" => "Martyrs",
        "it" => "Martiri",
        "la" => "Martyrum"
    ],
    "Pastors" => [
        "en" => "Pastors",
        "it" => "Pastori",
        "la" => "Pastorum"
    ],
    "Doctors" => [
        "en" => "Doctors",
        "it" => "Dottori della Chiesa",
        "la" => "Doctorum Ecclesiæ"
    ],
    "Virgins" => [
        "en" => "Virgins",
        "it" => "Vergini",
        "la" => "Virginum"
    ],
    "Holy Men and Women" => [
        "en" => "Holy Men and Women",
        "it" => "Santi e delle Sante",
        "la" => "Sanctorum et Sanctarum"
    ],
    "For One Martyr" => [
        "en" => "For One Martyr",
        "it" => "Per un martire",
        "la" => "Pro uno martyre"
    ],
    "For Several Martyrs" => [
        "en" => "For Several Martyrs",
        "it" => "Per più martiri",
        "la" => "Pro pluribus martyribus"
    ],
    "For Missionary Martyrs" => [
        "en" => "For Missionary Martyrs",
        "it" => "Per i martiri missionari",
        "la" => "Pro missionariis martyribus"
    ],
    "For a Virgin Martyr" => [
        "en" => "For a Virgin Martyr",
        "it" => "Per una vergine martire",
        "la" => "Pro virgine martyre"
    ],
    "For Several Pastors" => [
        "en" => "For Several Pastors",
        "it" => "Per i pastori",
        "la" => "Pro Pastoribus"
    ],
    "For a Pope" => [
        "en" => "For a Pope",
        "it" => "Per i papi",
        "la" => "Pro Papa"
    ],
    "For a Bishop" => [
        "en" => "For a Bishop",
        "it" => "Per i vescovi",
        "la" => "Pro Episcopis"
    ],
    "For One Pastor" => [
        "en" => "For One Pastor",
        "it" => "Per un Pastore",
        "la" => "Pro Pastoribus"
    ],
    "For Missionaries" => [
        "en" => "For Missionaries",
        "it" => "Per i missionari",
        "la" => "Pro missionariis"
    ],
    "For One Virgin" => [
        "en" => "For One Virgin",
        "it" => "Per una vergine",
        "la" => "Pro una virgine"
    ],
    "For Several Virgins" => [
        "en" => "For Several Virgins",
        "it" => "Per più vergini",
        "la" => "Pro pluribus virginibus"
    ],
    "For Religious" => [
        "en" => "For Religious",
        "it" => "Per i religiosi",
        "la" => "Pro Religiosis"
    ],
    "For Those Who Practiced Works of Mercy" => [
        "en" => "For Those Who Practiced Works of Mercy",
        "it" => "Per gli operatori di misericordia",
        "la" => "Pro iis qui opera Misericordiæ Exercuerunt"
    ],
    "For an Abbot" => [
        "en" => "For an Abbot",
        "it" => "Per un abate",
        "la" => "Pro abbate"
    ],
    "For a Monk" => [
        "en" => "For a Monk",
        "it" => "Per un monaco",
        "la" => "Pro monacho"
    ],
    "For a Nun" => [
        "en" => "For a Nun",
        "it" => "Per i religiosi",
        "la" => "Pro moniali"
    ],
    "For Educators" => [
        "en" => "For Educators",
        "it" => "Per gli educatori",
        "la" => "Pro Educatoribus"
    ],
    "For Holy Women" => [
        "en" => "For Holy Women",
        "it" => "Per le sante",
        "la" => "Pro Sanctis Mulieribus"
    ],
    "For One Saint" => [
        "en" => "For One Saint",
        "it" => "Per un Santo",
        "la" => "Pro uno Sancto"
    ],
    "or" => [
        "en" => "or",
        "it" => "oppure",
        "la" => "vel"
    ],
    "Proper" => [
        "en" => "Proper",
        "it" => "Proprio",
        "la" => "Proprium"
    ],
    "green" => [
        "en" => "green",
        "it" => "verde",
        "la" => "viridis"
    ],
    "purple" => [
        "en" => "purple",
        "it" => "viola",
        "la" => "purpura"
    ],
    "white" => [
        "en" => "white",
        "it" => "bianco",
        "la" => "albus"
    ],
    "red" => [
        "en" => "red",
        "it" => "rosso",
        "la" => "ruber"
    ],
    "pink" => [
        "en" => "pink",
        "it" => "rosa",
        "la" => "rosea"
    ],
    "Month" => [
        "en" => "Month",
        "it" => "Mese",
        "la" => "Mensis"
    ],
    "FERIA" => [
        "en" => "<I>weekday</I>",
        "it" => "<I>feria</I>",
        "la" => "<I>feria</I>"
    ],
    "COMMEMORATION" => [
        "en" => "<I>Commemoration</I>",
        "it" => "<I>Commemorazione</I>",
        "la" => "<I>Commemoratio</I>"
    ],
    "OPTIONAL MEMORIAL" => [
        "en" => "Optional memorial",
        "it" => "Memoria facoltativa",
        "la" => "Memoria ad libitum"
    ],
    "MEMORIAL" => [
        "en" => "Memorial",
        "it" => "Memoria",
        "la" => "Memoria"
    ],
    "FEAST" => [
        "en" => "FEAST",
        "it" => "FESTA",
        "la" => "FESTUM"
    ],
    "FEAST OF THE LORD" => [
        "en" => "<B>FEAST OF THE LORD</B>",
        "it" => "<B>FESTA DEL SIGNORE</B>",
        "la" => "<B>FESTUM DOMINI</B>"
    ],
    "SOLEMNITY" => [
        "en" => "<B>SOLEMNITY</B>",
        "it" => "<B>SOLENNITÀ</B>",
        "la" => "<B>SOLEMNITAS</B>"
    ],
    "HIGHER RANKING SOLEMNITY" => [
        "en" => "<B><I>precedence over solemnities</I></B>",
        "it" => "<B><I>precedenza sulle solennità</I></B>",
        "la" => "<B><I>præcellentia ante sollemnitates</I></B>"
    ],
    "Decree of the Congregation for Divine Worship" => [
        "en" => "Decree of the Congregation for Divine Worship",
        "it" => "Decreto della Congregazione per il Culto Divino",
        "la" => "Decretum Congregationis pro Cultu Divino"
    ],
    "Only years from 1970 and after are supported. You tried requesting the year %d." => [
        "en" => "Only years from 1970 and after are supported. You tried requesting the year %d.",
        "it" => "Soltanto anni dal 1970 in poi sono supportati. Hai provato a richiedere l'anno %d.",
        "la" => "Tantum ab anno MCMLXX et ultra præsto sunt. Tu anno %d conatus est petere."
    ],
    "The Solemnity '%s' falls on the Second Sunday of Advent in the year %d, the celebration has been transferred to the following Monday (%s)." => [
        "en" => "The Solemnity <i>'%s'</i> falls on the Second Sunday of Advent in the year %d, the celebration has been transferred to the following Monday (%s).",
        "it" => "La Solennità <i>'%s'</i> coincide con la Seconda Domenica d'Avvento nell'anno %d, pertanto la celebrazione è stata trasferita al lunedì seguente (%s).",
        "la" => "Coincidit enim Sollemnitas <i>'%s'</i> cum Dominica Secunda Temporis Adventi in anno %d, ergo translata est celebratio ad diem Lunæ proximum (%s)."
    ],
    "The Solemnity '%s' falls on a Sunday of Lent in the year %d, the celebration has been transferred to the following Monday (%s)." => [
        "en" => "The Solemnity <i>'%s'</i> falls on a Sunday of Lent in the year %d, the celebration has been transferred to the following Monday (%s).",
        "it" => "La Solennità <i>'%s'</i> coincide con una Domenica di Quaresima nell'anno %d, pertanto la celebrazione è stata trasferita al lunedì seguente (%s).",
        "la" => "Coincidit enim Sollemnitas <i>'%s'</i> cum Dominica Temporis Quadragesimæ in anno %d, ergo traslata est celebratio ad diem Lunæ proximum (%s)."
    ],
    "The Solemnity '%s' falls either on Palm Sunday or during Holy Week in the year %d, the celebration has been transferred to the Saturday preceding Palm Sunday (%s)." => [
        "en" => "The Solemnity <i>'%s'</i> falls either on Palm Sunday or during Holy Week in the year %d, the celebration has been transferred to the Saturday preceding Palm Sunday (%s).",
        "it" => "La Solennità <i>'%s'</i> o coincide con la Domenica delle Palme, o cade durante la Settimana Santa nell'anno %d, pertanto la celebrazione è stata trasferita al sabato che precede la Domenica delle Palme (%s).",
        "la" => "Aut enim coincidit Sollemnitas <i>'%s'</i> cum Dominica in Palmis aut coincidit cum Hebdomada Sancta in anno %d, ergo traslata est celebratio ad diem Saturni ante Dominicam in Palmis (%s)."
    ],
    "The Solemnity '%s' falls either on Palm Sunday or during Holy Week in the year %d, the celebration has been transferred to the Monday following the Second Sunday of Easter (%s)." => [
        "en" => "The Solemnity <i>'%s'</i> falls either on Palm Sunday or during Holy Week in the year %d, the celebration has been transferred to the Monday following the Second Sunday of Easter (%s).",
        "it" => "La Solennità <i>'%s'</i> o coincide con la Domenica delle Palme, o cade durante la Settimana Santa nell'anno %d, pertanto la celebrazione è stata trasferita al lunedì che segue la Seconda Domenica di Pasqua (%s).",
        "la" => "Aut enim coincidit Sollemnitas <i>'%s'</i> cum Dominica in Palmis aut coincidit cum Hebdomada Sancta in anno %d, ergo traslata est celebratio ad diem Lunæ post Dominicam Secundam Paschæ (%s)."
    ],
    "'%s' falls on a Sunday in the year %d, therefore the Feast '%s' is celebrated on %s rather than on the Sunday after Christmas." => [
        "en" => "'%s' falls on a Sunday in the year %d, therefore the Feast <i>'%s'</i> is celebrated on %s rather than on the Sunday after Christmas.",
        "it" => "'%s' coincide con una Domenica nell'anno %d, pertanto la Festa <i>'%s'</i> viene celebrata il %s anziché la Domenica dopo Natale.",
        "la" => "'%s' coincidit cum Dominica in anno %d, ergo Festum <i>'%s'</i> celebrentur die %s quam Dominica post Nativitate."
    ],
    "'%s' is superseded by a Solemnity in the year %d." => [
        "en" => "<i>'%s'</i> is superseded by a Solemnity in the year %d.",
        "it" => "<i>'%s'</i> è soppiantata da una Solennità nell'anno %d.",
        "la" => "<i>'%s'</i> subplantata est ab Sollemnitate in anno %d."
    ],
    "The Feast '%s', usually celebrated on %s, is suppressed by a Sunday or a Solemnity in the year %d." => [
        "en" => "The Feast <i>'%s'</i>, usually celebrated on %s, is suppressed by a Sunday or a Solemnity in the year %d.",
        "it" => "La Festa  <i>'%s'</i>, che di solito sarebbe celebrata il giorno %s, viene soppiantata da una Domenica o una Solennità nell'anno %d.",
        "la" => "Festum <i>'%s'</i> quo plerumque celebratur in die %s subplantata est ab Dominica aut Sollemnitate in anno %d."
    ],
    "The Memorial '%s', which would have been celebrated on %s, is suppressed by a Solemnity or Feast Day in the year %d." => [
        "en" => "The Memorial <i>'%s'</i>, which would have been celebrated on %s, is suppressed by a Solemnity or Feast Day in the year %d.",
        "it" => "La Memoria obbligatoria <i>'%s'</i> che sarebbe stata celebrata il giorno %s, è stata soppiantata da una Solennità o una Festa nell'anno %d.",
        "la" => "Memoria <i>'%s'</i> qua celebrata fuisset in die %s subplantata est ab Sollemnitate aut ab Festu in anno %d."
    ],
    "The Memorial '%s' falls within the Lenten season in the year %d, rank reduced to Commemoration." => [
        "en" => "The Memorial <i>'%s'</i> falls within the Lenten season in the year %d, rank reduced to Commemoration.",
        "it" => "La Memoria obbligatoria <i>'%s'</i> cade nel periodo della Quaresima nell'anno %d, pertanto è stata ridotta di grado a Commemorazione.",
        "la" => "Accidit Memoria <i>'%s'</i> in temporis Quadragesimæ in anno %d, ergo reductus est gradus ad Commemorationem."
    ],
    "'%s' is superseded by the Memorial '%s' in the year %d." => [
        "en" => "<i>'%s'</i> is superseded the Memorial <i>'%s'</i> in the year %d.",
        "it" => "<i>'%s'</i> è soppiantata dalla Memoria obbligatoria <i>'%s'</i> nell'anno %d.",
        "la" => "<i>'%s'</i> subplantata est ab Memoria <i>'%s'</i> in anno %d."
    ],
    "The Memorial '%s' coincides with another Memorial '%s' in the year %d. They are both reduced in rank to Optional Memorials (%s)." => [
        "en" => "The Memorial <i>'%s'</i> coincides with another Memorial <i>'%s'</i> in the year %d. They are both reduced in rank to Optional Memorials (%s).",
        "it" => "La Memoria obbligatoria <i>'%s'</i> coincide con l'altra Memoria obbligatoria <i>'%s'</i> nell'anno %d. Pertanto tutte e due sono ridotte di grado a Memoria facoltativa (%s).",
        "la" => "Memoria <i>'%s'</i> coincidit cum alia Memoria <i>'%s'</i> in anno %d. Ergo ambo simul redunctur in gradu Memoriæ ad libitum (%s)."
    ],
    "The Memorial '%s', usually celebrated on %s, is suppressed by a Solemnity or Feast Day in the year %d." => [
        "en" => "The Memorial <i>'%s'</i>, usually celebrated on %s, is suppressed by a Solemnity or Feast Day in the year %d.",
        "it" => "La Memoria obbligatoria <i>'%s'</i>, celebrata solitamente il giorno %s, è soppiantata da una Solennità o da una Festa nell'anno %d.",
        "la" => "Memoria <i>'%s'</i> qua plerumque celebrata est in die %s subplantata est ab Sollemnitate aut ab Festu in anno %d."
    ],
    "The Memorial '%s' has been raised to the rank of Feast since the year 2016, applicable to the year %d (%s)." => [
        "en" => "The Memorial <i>'%s'</i> has been raised to the rank of Feast since the year 2016, applicable to the year %d (%s).",
        "it" => "La Memoria obbligatoria <i>'%s'</i> è stata elevata al grado di Festa dall'anno 2016, applicabile pertanto all'anno %d (%s).",
        "la" => "Memoria <i>'%s'</i> elevata est in gradu Festuus ab anno 2016, ergo applicatur ad anno %d (%s)."
    ],
    "The Memorial '%s' was added in the Tertia Editio Typica of the Roman Missal since the year 2002 (%s), applicable for the year %d." => [
        "en" => "The Memorial <i>'%s'</i> was added in the Tertia Editio Typica of the Roman Missal since the year 2002 (%s), applicable for the year %d.",
        "it" => "La Memoria obbligatoria <i>'%s'</i> è stata aggiunta nella Terza Edizione Tipica del Messale Romano dall'anno 2002 (%s), applicabile pertanto all'anno %d.",
        "la" => "Memoria <i>'%s'</i> aggregata est ab Tertia Editione Typica Missalis Romani ab anno 2002 (%s), ergo applicatur ad anno %d."
    ],
    "The Memorial '%s', added in the Tertia Editio Typica of the Roman Missal since the year 2002 (%s), falls within the Lenten season in the year %d, rank reduced to Commemoration." => [
        "en" => "The Memorial <i>'%s'</i>, added in the Tertia Editio Typica of the Roman Missal since the year 2002 (%s), falls within the Lenten season in the year %d, rank reduced to Commemoration.",
        "it" => "La Memoria obbligatoria <i>'%s'</i>, aggiunta nella Terza Edizione Tipica del Messale Romano dall'anno 2002 (%s), cade nel periodo quaresimale nell'anno %d, pertanto è stata ridotta di grado a Commemorazione.",
        "la" => "Memoria <i>'%s'</i> aggregata in Editione Typica Tertia Missalis Romani ab anno 2002 (%s) accidit in tempore Quadragesimæ in anno %d. Reducta est in gradu Commemorationis."
    ],
    "In the year %d '%s' is superseded by the Memorial '%s', added in the Tertia Editio Typica of the Roman Missal since the year 2002 (%s)." => [
        "en" => "In the year %d <i>'%s'</i> is superseded by the Memorial <i>'%s'</i>, added in the Tertia Editio Typica of the Roman Missal since the year 2002 (%s).",
        "it" => "Nell'anno %d, <i>'%s'</i> è soppiantata dalla Memoria obbligatoria <i>'%s'</i>, aggiunta nella Terza Edizione Tipica del Messale Romano dall'anno 2002 (%s).",
        "la" => "In anno %d <i>'%s'</i> subplantata est ab Memoria <i>'%s'</i> aggregata in Editione Typica Tertia Missalis Romani ab anno 2002 (%s)."
    ],
    "The Memorial '%s', added in the Tertia Editio Typica of the Roman Missal since the year 2002 (%s) and usually celebrated on %s, is suppressed by a Sunday or a Solemnity in the year %d." => [
        "en" => "The Memorial <i>'%s'</i>, added in the Tertia Editio Typica of the Roman Missal since the year 2002 (%s) and usually celebrated on %s, is suppressed by a Sunday or a Solemnity in the year %d.",
        "it" => "La Memoria obbligatoria <i>'%s'</i>, aggiunta nella Terza Edizione Tipica del Messale Romano dall'anno 2002 (%s) e celebrata solitamente il giorno %s, è soppressa da una Domenica o da una Solennità nell'anno %d.",
        "la" => "Memoria <i>'%s'</i> aggregata in Editione Typica Tertia Missalis Romani ab anno 2002 (%s) et plerumque celebrata in die %s subplantata est ab Dominica aut ab Sollemnitate in anno %d."
    ]
];

$LATIN_ORDINAL = [
    "",
    "primus",
    "secundus",
    "tertius",
    "quartus",
    "quintus",
    "sextus",
    "septimus",
    "octavus",
    "nonus",
    "decimus",
    "undecimus",
    "duodecimus",
    "decimus tertius",
    "decimus quartus",
    "decimus quintus",
    "decimus sextus",
    "decimus septimus",
    "duodevicesimus",
    "undevicesimus",
    "vigesimus",
    "vigesimus primus",
    "vigesimus secundus",
    "vigesimus tertius",
    "vigesimus quartus",
    "vigesimus quintus",
    "vigesimus sextus",
    "vigesimus septimus",
    "vigesimus octavus",
    "vigesimus nonus",
    "trigesimus",
    "trigesimus primus",
    "trigesimus secundus",
    "trigesimus tertius",
    "trigesimus quartus",
];

$LATIN_ORDINAL_FEM_GEN = [
    "",
    "primæ",
    "secundæ",
    "tertiæ",
    "quartæ",
    "quintæ",
    "sextæ",
    "septimæ",
    "octavæ",
    "nonæ",
    "decimæ",
    "undecimæ",
    "duodecimæ",
    "decimæ tertiæ",
    "decimæ quartæ",
    "decimæ quintæ",
    "decimæ sextæ",
    "decimæ septimæ",
    "duodevicesimæ",
    "undevicesimæ",
    "vigesimæ",
    "vigesimæ primæ",
    "vigesimæ secundæ",
    "vigesimæ tertiæ",
    "vigesimæ quartæ",
    "vigesimæ quintæ",
    "vigesimæ sextæ",
    "vigesimæ septimæ",
    "vigesimæ octavæ",
    "vigesimæ nonæ",
    "trigesimæ",
    "trigesimæ primæ",
    "trigesimæ secundæ",
    "trigesimæ tertiæ",
    "trigesimæ quartæ",
];

$LATIN_DAYOFTHEWEEK = [
    "Feria I",     //0=Sunday
    "Feria II",    //1=Monday
    "Feria III",   //2=Tuesday
    "Feria IV",    //3=Wednesday
    "Feria V",     //4=Thursday
    "Feria VI",    //5=Friday
    "Feria VII"    //6=Saturday
];

$LATIN_MONTHS = [
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

?>