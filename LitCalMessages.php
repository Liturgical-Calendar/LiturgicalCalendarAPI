<?php

function __($key,$locale="LA"){
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
function _G($key,$locale="LA",$html=true){
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
        $commons = explode(",", $common);
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
        if(strpos($string,",")){
            $colors = explode(",",$string);
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
        if(strpos($string,",")){
            $colors = explode(",",$string);
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
    "The Solemnity '%s' falls on %s in the year %d, the celebration has been transferred to %s (%s) as per the %s." => [
        "en" => "The Solemnity <i>'%s'</i> falls on <b>%s</b> in the year %d, the celebration has been transferred to %s (%s) as per the %s.",
        "it" => "La Solennità <i>'%s'</i> coincide con <b>%s</b> nell'anno %d, pertanto la celebrazione è stata trasferita al %s (%s) in accordo con il %s.",
        "la" => "Coincidet enim Sollemnitas <i>'%s'</i> cum <b>%s</b> in anno %d, ergo traslata est celebratio ad %s (%s) secundum %s."
    ],
    "'%s' falls on a Sunday in the year %d, therefore the Feast '%s' is celebrated on %s rather than on the Sunday after Christmas." => [
        "en" => "'%s' falls on a Sunday in the year %d, therefore the Feast <i>'%s'</i> is celebrated on %s rather than on the Sunday after Christmas.",
        "it" => "'%s' coincide con una Domenica nell'anno %d, pertanto la Festa <i>'%s'</i> viene celebrata il %s anziché la Domenica dopo Natale.",
        "la" => "'%s' coincidet cum Dominica in anno %d, ergo Festum <i>'%s'</i> celebrentur die %s quam Dominica post Nativitate."
    ],
    "The %s '%s', usually celebrated on %s, is suppressed by the %s '%s' in the year %d." => [
        "en" => "The %s <i>'%s'</i>, usually celebrated on <b>%s</b>, is suppressed by the %s <i>'%s'</i> in the year %d.",
        "it" => "La %s  <i>'%s'</i>, che di solito sarebbe celebrata il giorno <b>%s</b>, viene soppiantata dalla %s <i>'%s'</i> nell'anno %d.",
        "la" => "%s <i>'%s'</i> quo plerumque celebratur in die <b>%s</b> subplantata est ab %s <i>'%s'</i> in anno %d."
    ],
    "The %s '%s' falls within the Lenten season in the year %d, rank reduced to Commemoration." => [
        "en" => "The %s <i>'%s'</i> falls within the Lenten season in the year %d, rank reduced to Commemoration.",
        "it" => "La %s <i>'%s'</i> cade nel periodo della Quaresima nell'anno %d, pertanto è stata ridotta di grado a Commemorazione.",
        "la" => "Accidit %s <i>'%s'</i> in temporis Quadragesimæ in anno %d, ergo reductus est gradus ad Commemorationem."
    ],
    "'%s' is superseded by the %s '%s' in the year %d." => [
        "en" => "<i>'%s'</i> is superseded by the %s <i>'%s'</i> in the year %d.",
        "it" => "<i>'%s'</i> è soppiantata dalla %s <i>'%s'</i> nell'anno %d.",
        "la" => "<i>'%s'</i> subplantata est ab %s <i>'%s'</i> in anno %d."
    ],
    "The Memorial '%s' coincides with another Memorial '%s' in the year %d. They are both reduced in rank to optional memorials (%s)." => [
        "en" => "The Memorial <i>'%s'</i> coincides with another Memorial <i>'%s'</i> in the year %d. They are both reduced in rank to optional memorials (%s).",
        "it" => "La Memoria obbligatoria <i>'%s'</i> coincide con l'altra Memoria obbligatoria <i>'%s'</i> nell'anno %d. Pertanto tutte e due sono ridotte di grado a Memoria facoltativa (%s).",
        "la" => "Memoria <i>'%s'</i> coincidet cum alia Memoria <i>'%s'</i> in anno %d. Ergo ambo simul redunctur in gradu Memoriæ ad libitum (%s)."
    ],
    "The %s '%s' has been raised to the rank of %s since the year %d, applicable to the year %d (%s)." => [
        "en" => "The %s <i>'%s'</i> has been raised to the rank of %s since the year %d, applicable to the year %d (%s).",
        "it" => "La %s <i>'%s'</i> è stata elevata al grado di %s dall'anno %d, applicabile pertanto all'anno %d (%s).",
        "la" => "%s <i>'%s'</i> elevata est in gradu %s ab anno %d, ergo applicatur ad anno %d (%s)."
    ],
    "The %s '%s', added on %s since the year %d (%s), falls within the Lenten season in the year %d, rank reduced to Commemoration." => [
        "en" => "The %s <i>'%s'</i>, added on <b>%s</b> since the year %d (%s), falls within the Lenten season in the year %d, rank reduced to Commemoration.",
        "it" => "La %s <i>'%s'</i>, aggiunta il giorno <b>%s</b> a partire dall'anno %d (%s), cade nel periodo quaresimale nell'anno %d, pertanto è stata ridotta di grado a Commemorazione.",
        "la" => "%s <i>'%s'</i> aggregata in die <b>%s</b> ab anno %d (%s) accidit in tempore Quadragesimæ in anno %d. Reducta est in gradu Commemorationis."
    ],
    "In the year %d '%s' is superseded by the %s '%s', added on %s since the year %d (%s)." => [
        "en" => "In the year %d <i>'%s'</i> is superseded by the %s <i>'%s'</i>, added on <b>%s</b> since the year 2002 (%s).",
        "it" => "Nell'anno %d, <i>'%s'</i> è soppiantata dalla %s <i>'%s'</i>, aggiunta il giorno <b>%s</b> dall'anno %d (%s).",
        "la" => "In anno %d <i>'%s'</i> subplantata est ab %s <i>'%s'</i> aggregata in die <b>%s</b> ab anno %d (%s)."
    ],
    "The %s '%s', added in the Tertia Editio Typica of the Roman Missal since the year 2002 (%s) and usually celebrated on %s, is suppressed by the %s '%s' in the year %d." => [
        "en" => "The %s <i>'%s'</i>, added in the Tertia Editio Typica of the Roman Missal since the year 2002 (%s) and usually celebrated on %s, is suppressed by the %s <i>'%s'</i> in the year %d.",
        "it" => "La %s <i>'%s'</i>, aggiunta nella Terza Edizione Tipica del Messale Romano dall'anno 2002 (%s) e celebrata solitamente il giorno %s, è soppressa dalla %s <i>'%s'</i> nell'anno %d.",
        "la" => "%s <i>'%s'</i> aggregata in Editione Typica Tertia Missalis Romani ab anno 2002 (%s) et plerumque celebrata in die %s subplantata est ab %s <i>'%s'</i> in anno %d."
    ],
    "The optional memorial '%s' either falls between 17 Dec. and 24 Dec., or during the Octave of Christmas, or on the weekdays of the Lenten season in the year %d, rank reduced to Commemoration." => [
        "en" => "The optional memorial <i>'%s'</i> either falls between 17 Dec. and 24 Dec., or during the Octave of Christmas, or on the weekdays of the Lenten season in the year %d, rank reduced to Commemoration.",
        "it" => "La memoria facoltativa <i>'%s'</i> cade o tra il 17 Dic. e il 24 Dic., o durante l'Ottava di Natale, o tra le ferie della Quaresima nell'anno %d, pertanto il grado è stato ridotto a Commemorazione.",
        "la" => "Memoria ad libitum <i>'%s'</i> accidit aut infra 17 Dec. et 24 Dec. aut infra Octavam Nativitatis aut infra feriae Quadragesimae in anno %d, ergo reductus est gradus ad Commemorationem."
    ],
    "The optional memorial '%s', added in the Tertia Editio Typica of the Roman Missal since the year 2002 (%s), either falls between 17 Dec. and 24 Dec., during the Octave of Christmas, or on a weekday of the Lenten season in the year %d, rank reduced to Commemoration." => [
        "en" => "The optional memorial <i>'%s'</i>, added in the Tertia Editio Typica of the Roman Missal since the year 2002 (%s), either falls between 17 Dec. and 24 Dec., during the Octave of Christmas, or on a weekday of the Lenten season in the year %d, rank reduced to Commemoration.",
        "it" => "La memoria facoltativa <i>'%s'</i>, aggiunta nella Terza Edizione Tipica del Messale Romano dall'anno 2002 (%s), cade o tra il 17 Dic. e il 24 Dic., o durante l'Ottava di Natale, o tra le ferie della Quaresima nell'anno %d, il grado è stato pertanto ridotto a Commemorazione.",
        "la" => "Memoria ad libitum <i>'%s'</i> aggregata in Editione Typica Tertia Missalis Romani ab anno 2002 (%s) accidit aut infra 17 Dec. et 24 Dec. aut infra Octavam Nativitatis aut infra feriae Quadragesimae in anno %d, ergo reductus est gradus ad Commemorationem."
    ],
    "The optional memorial '%s' has been transferred from Dec. 12 to Aug. 12 since the year 2002 (%s), applicable to the year %d." => [
        "en" => "The optional memorial <i>'%s'</i> has been transferred from Dec. 12 to Aug. 12 since the year 2002 (%s), applicable to the year %d.",
        "it" => "La memoria facoltativa <i>'%s'</i> è stata trasferita dal 12 Dic. al 12 Agosto a partire dall'anno 2002 (%s), applicabile pertanto all'anno %d.",
        "la" => "Memoria ad libitum <i>'%s'</i> traslata est de 12 Dec. ad 12 Aug. ab anno 2002 (%s), ergo viget in anno %d."
    ],
    "The optional memorial '%s', which would have been superseded this year by a Sunday or Solemnity were it on Dec. 12, has however been transferred to Aug. 12 since the year 2002 (%s), applicable to the year %d." => [
        "en" => "The optional memorial <i>'%s'</i>, which would have been superseded this year by a Sunday or Solemnity were it on Dec. 12, has however been transferred to Aug. 12 since the year 2002 (%s), applicable to the year %d.",
        "it" => "La memoria facoltativa <i>'%s'</i>, che sarebbe stata soppressa quest'anno da una Domenica o da una Solennità se fosse celebrata il 12 dic., è stata tuttavia trasferita al 12 Agosto a partire dall'anno 2002 (%s), applicabile pertanto all'anno %d.",
        "la" => "Memoria ad libitum <i>'%s'</i> qua subplantata fuisset ab Dominica aut Sollemnitate si celebrata fuisset in die 12 Dec., nihilominus traslata est ad 12 Aug. ab anno 2002 (%s), ergo viget in anno %d."
    ],
    'The optional memorial \'%1$s\' has been transferred from Dec. 12 to Aug. 12 since the year 2002 (%2$s), applicable to the year %3$d. However, it is superseded by a Sunday, a Solemnity, or a Feast \'%4$s\' in the year %3$d.' => [
        "en" => 'The optional memorial <i>\'%1$s\'</i> has been transferred from Dec. 12 to Aug. 12 since the year 2002 (%2$s), applicable to the year %3$d. However, it is superseded by a Sunday, a Solemnity, or a Feast  <i>\'%4$s\'</i> in the year %3$d.',
        "it" => 'La memoria facoltativa <i>\'%1$s\'</i> è stata trasferita dal 12 Dic. al 12 Agosto a partire dall\'anno 2002 (%2$s), applicabile pertanto all\'anno %3$d. Tuttavia è soppressa da una Domenica, una Solennità o una Festa  <i>\'%4$s\'</i> nell\'anno %3$d.',
        "la" => 'Memoria ad libitum <i>\'%1$s\'</i> traslata est de 12 Dec. ad 12 Aug. ab anno 2002 (%2$s), ergo viget in anno %3$d. Nihilominus subplantata est ab Dominica, aut Sollemnitate, aut Festu  <i>\'%4$s\'</i> in anno %3$d.'
    ],
    "The %s '%s' has been added on %s since the year %d (%s), applicable to the year %d." => [
        "en" => "The %s <i>'%s'</i> has been added on <b>%s</b> since the year %d (%s), applicable to the year %d.",
        "it" => "La %s <i>'%s'</i> è stata inserita il giorno <b>%s</b> a partire dall'anno %d (%s), applicabile pertanto all'anno %d.",
        "la" => "%s <i>'%s'</i> aggregata est igitur in die <b>%s</b> ab anno %d (%s), ergo viget in anno %d. "
    ],
    "The optional memorial '%s', added on %s since the year %d (%s), is however superseded by a Sunday, a Solemnity or a Feast '%s' in the year %d." => [
        "en" => "The optional memorial <i>'%s'</i>, added on %s since the year %d (%s), is however superseded by a Sunday, a Solemnity or a Feast <i>'%s'</i> in the year %d.",
        "it" => "La memoria facoltativa <i>'%s'</i>, è stata inserita il giorno %s a partire dall'anno %d (%s), tuttavia è soppressa da una Domenica, una Solennità o una Festa <i>'%s'</i> nell'anno %d.",
        "la" => "Memoria ad libitum <i>'%s'</i>, aggregata est igitur in die %s ab anno %d (%s). Nihilominus subplantata est ab Dominica, aut Sollemnitate, aut Festu <i>'%s'</i> in anno %d. "
    ],
    "The Memorial '%s', added on %s since the year %d (%s), is however superseded by a Solemnity or a Feast '%s' in the year %d." => [
        "en" => "The Memorial <i>'%s'</i> has been added on %s since the year %d (%s), is however superseded by a Solemnity or a Feast <i>'%s'</i> in the year %d.",
        "it" => "La Memoria <i>'%s'</i> è stata inserita il giorno %s a partire dall'anno %d (%s), tuttavia è soppressa da una Solennità o una Festa <i>'%s'</i> nell'anno %d.",
        "la" => "Memoria <i>'%s'</i> aggregata est igitur in die %s ab anno %d (%s). Nihilominus subplantata est ab Sollemnitate aut Festu <i>'%s'</i> in anno %d. "
    ],
    "the Monday after Pentecost" => [
        "en" => "the Monday after Pentecost",
        "it" => "il lunedì dopo la Pentecoste",
        "la" => "dies Lunae post Pentecostem"
    ],
    "The %s '%s' has been suppressed by the Memorial '%s', added on %s since the year %d (%s)." => [
        "en" => "The %s <i>'%s'</i> has been suppressed by the Memorial <i>'%s'</i>, added on %s since the year %d (%s).",
        "it" => "La %s <i>'%s'</i> è stata soppressa dalla Memoria <i>'%s'</i>, aggiunta %s a partire dall'anno %d (%s).",
        "la" => "%s <i>'%s'</i> subplantata est ab Memoria <i>'%s'</i>, aggregata in %s ab anno %d (%s)."
    ],
    "The Solemnity '%s' coincides with the Solemnity '%s' in the year %d. We should ask the Congregation for Divine Worship what to do about this!" => [
        "en" => "The Solemnity <i>'%s'</i> coincides with the Solemnity <i>'%s'</i> in the year %d. We should ask the Congregation for Divine Worship what to do about this!",
        "it" => "La Solennità <i>'%s'</i> coincide con la Solennità <i>'%s'</i> nell'anno %d. Dovremmo chiedere alla Congregazione del Culto Divino cosa fare a riguardo!",
        "la" => "Sollemnitas <i>'%s'</i> coincidet cum Sollemnitate <i>'%s'</i> in anno %d. Oportet quaerere a Congregatione Cultu Divino quid facere!"
    ],
    "Seeing that the Solemnity '%s' coincides with the Solemnity '%s' in the year %d, it has been anticipated by one day as per %s." => [
        "en" => "Seeing that the Solemnity <i>'%s'</i> coincides with the Solemnity <i>'%s'</i> in the year %d, the prior has been moved forward by one day as per %s.",
        "it" => "Visto che la Solennità <i>'%s'</i> coincide con la Solennità <i>'%s'</i> nell'anno %d, la prima è stata anticipata di un giorno per %s.",
        "la" => "Quod ratione Sollemnitas <i>'%s'</i> coincidet cum Sollemnitate <i>'%s'</i> in anno %d, sollemnitas prima ??? anticipata est ab uno die ??? (%s)"
    ],
    "the following Monday" => [
        "en" => "the following Monday",
        "it" => "lunedì seguente",
        "la" => "diem Lunæ proximum"
    ],
    "the Saturday preceding Palm Sunday" => [
        "en" => "the Saturday preceding Palm Sunday",
        "it" => "sabato che precede la Domenica delle Palme",
        "la" => "sabbatum ante Dominicam in Palmis"
    ],
    "the Monday following the Second Sunday of Easter" => [
        "en" => "the Monday following the Second Sunday of Easter",
        "it" => "lunedì che segue la Seconda Domenica di Pasqua",
        "la" => "diem Lunæ post Dominicam Secundam Paschæ"
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