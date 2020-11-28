<?php 

class FormControls {

    private static $settings = [
        "nameField"     => true,
        "dayField"      => true,
        "monthField"    => true,
        "colorField"    => true,
        "properField"   => true,
        "fromYearField" => true
    ];

    //public function __construct()
    public static function CreateFestivityRow($title=null){
        $uniqid = uniqid();
        $formRow = "";

        if($title !== null){
            $formRow .= "<h4>" . $title . "</h4>";
        }

        $formRow .= "<div class=\"form-row\">";

        if(self::$settings["nameField"]){
            $formRow .= "<div class=\"form-group col-sm-3\">" .
            "<label for=\"{$uniqid}Name\">" . __("Name") . "</label><input type=\"text\" class=\"form-control litEvent litEventName\" id=\"{$uniqid}Name\" data-valuewas=\"\" />" .
            "<div class=\"invalid-feedback\">This same celebration was already defined elsewhere. Please remove it first where it is defined, then you can define it here.</div>" .
            "</div>";
        }

        if(self::$settings["dayField"]){
            $formRow .= "<div class=\"form-group col-sm-1\">" .
            "<label for=\"{$uniqid}Day\">" . __("Day") . "</label><input type=\"number\" min=1 max=31 value=1 class=\"form-control litEvent litEventDay\" id=\"{$uniqid}Day\" />" .
            "</div>";
        }

        if(self::$settings["monthField"]){
            $formRow .= "<div class=\"form-group col-sm-2\">" .
            "<label for=\"{$uniqid}Month\">" . __("Month") . "</label>" .
            "<select class=\"form-control litEvent litEventMonth\" id=\"{$uniqid}Month\">";
            $formatter = new IntlDateFormatter(LITCAL_LOCALE, IntlDateFormatter::FULL, IntlDateFormatter::NONE);
            $formatter->setPattern("MMMM");
            for($i=1;$i<=12;$i++){
                $month = DateTime::createFromFormat("n",$i, new DateTimeZone('UTC') );
                $formRow .= "<option value={$i}>" . $formatter->format($month) . "</option>";
            }
    
            $formRow .= "</select>" .
            "</div>";
        }

        if(self::$settings["properField"]){
            $formRow .= "<div class=\"form-group col-sm-3\">" .
            "<label style=\"display:block;\" for=\"{$uniqid}Proper\">" . __("Common (or Proper)") . "</label>" .
            "<select class=\"form-control litEvent litEventProper\" id=\"{$uniqid}Proper\" multiple=\"multiple\" />" .
            "<option value=\"Proper\" selected>" . _C("Proper") . "</option>" .
            "<option value=\"Blessed Virgin Mary\">" . _C("Blessed Virgin Mary") . "</option>" .
            //"<optgroup label=\"" . _C("Common of Martyrs") . "\">" .
            "<option value=\"Martyrs\">" . _C("Martyrs") . "</option>" .
            "<option value=\"Martyrs:For One Martyr\">" . _C("Martyrs:For One Martyr") . "</option>" .
            "<option value=\"Martyrs:For Several Martyrs\">" . _C("Martyrs:For Several Martyrs") . "</option>" .
            "<option value=\"Martyrs:For Missionary Martyrs\">" . _C("Martyrs:For Missionary Martyrs") . "</option>" .
            "<option value=\"Martyrs:For One Missionary Martyr\">" . _C("Martyrs:For One Missionary Martyr") . "</option>" .
            "<option value=\"Martyrs:For Several Missionary Martyrs\">" . _C("Martyrs:For Several Missionary Martyrs") . "</option>" .
            "<option value=\"Martyrs:For a Virgin Martyr\">" . _C("Martyrs:For a Virgin Martyr") . "</option>" .
            "<option value=\"Martyrs:For a Holy Woman Martyr\">" . _C("Martyrs:For a Holy Woman Martyr") . "</option>" .
            //"<optgroup label=\"" . _C("Pastors") . "\">" .
            "<option value=\"Pastors\">" . _C("Pastors") . "</option>" .
            "<option value=\"Pastors:For a Pope\">" . _C("Pastors:For a Pope") . "</option>" .
            "<option value=\"Pastors:For a Bishop\">" . _C("Pastors:For a Bishop") . "</option>" .
            "<option value=\"Pastors:For One Pastor\">" . _C("Pastors:For One Pastor") . "</option>" .
            "<option value=\"Pastors:For Several Pastors\">" . _C("Pastors:For Several Pastors") . "</option>" .
            "<option value=\"Pastors:Missionaries\">" . _C("Pastors:For Missionaries") . "</option>" .
            "<option value=\"Pastors:For Founders of a Church\">" . _C("Pastors:For Founders of a Church") . "</option>" .
            "<option value=\"Pastors:For Several Founders\">" . _C("Pastors:For Several Founders") . "</option>" .
            "<option value=\"Pastors:For One Founder\">" . _C("Pastors:For One Founder") . "</option>" .
            "<option value=\"Doctors\">" . _C("Doctors") . "</option>" .
            //"<optgroup label=\"" . _C("Virgins") . "\">" .
            "<option value=\"Virgins\">" . _C("Virgins") . "</option>" .
            "<option value=\"Virgins:For One Virgin\">" . _C("Virgins:For One Virgin") . "</option>" .
            "<option value=\"Virgins:For Several Virgins\">" . _C("Virgins:For Several Virgins") . "</option>" .
            //"<optgroup label=\"" . _C("Holy Men and Women") . "\">" .
            "<option value=\"Holy Men and Women\">" . _C("Holy Men and Women") . "</option>" .
            "<option value=\"Holy Men and Women:For One Saint\">" . _C("Holy Men and Women:For One Saint") . "</option>" .
            "<option value=\"Holy Men and Women:For Several Saints\">" . _C("Holy Men and Women:For Several Saints") . "</option>" .
            "<option value=\"Holy Men and Women:For Religious\">" . _C("Holy Men and Women:For Religious") . "</option>" .
            "<option value=\"Holy Men and Women:For an Abbot\">" . _C("Holy Men and Women:For an Abbot") . "</option>" .
            "<option value=\"Holy Men and Women:For a Monk\">" . _C("Holy Men and Women:For a Monk") . "</option>" .
            "<option value=\"Holy Men and Women:For a Nun\">" . _C("Holy Men and Women:For a Nun") . "</option>" .
            "<option value=\"Holy Men and Women:For Educators\">" . _C("Holy Men and Women:For Educators") . "</option>" .
            "<option value=\"Holy Men and Women:For Holy Women\">" . _C("Holy Men and Women:For Holy Women") . "</option>" .
            "<option value=\"Holy Men and Women:For Those Who Practiced Works of Mercy\">" . _C("Holy Men and Women:For Those Who Practiced Works of Mercy") . "</option>" .
            "<option value=\"Dedication of a Church\">" . _C("Dedication of a Church") . "</option>" .
            "</select>" .
            "</div>";
        }

        if(self::$settings["colorField"]){
            $formRow .= "<div class=\"form-group col-sm-2\">" .
            "<label for=\"{$uniqid}Color\">" . __("Liturgical color") . "</label>" .
            "<select class=\"form-control litEvent litEventColor\" id=\"{$uniqid}Color\" multiple=\"multiple\" />" .
            "<option value=\"white\" selected>" . strtoupper(__("white") ) . "</option>" .
            "<option value=\"red\">" . strtoupper(__("red") ) . "</option>" .
            "<option value=\"purple\">" . strtoupper(__("purple") ) . "</option>" .
            "<option value=\"green\">" . strtoupper(__("green") ) . "</option>" .
            "</select>" .
            "</div>";
        }

        if(self::$settings["fromYearField"]){
            $formRow .= "<div class=\"form-group col-sm-1\">" .
            "<label for=\"{$uniqid}FromYear\">" . __("Since") . "</label>" .
            "<input type=\"number\" min=1970 max=9999 class=\"form-control litEvent litEventFromYear\" id=\"{$uniqid}FromYear\" value=1970 />" .
            "</div>";
        }

        $formRow .= "</div>";

        echo $formRow;
    }

    public static function setOption($option,$value){
        if(isset(self::$settings[$option]) ){
            if(gettype($value) === 'boolean' ){
                self::$settings[$option] = $value;
            }
        }
    }

    public static function setOptions($options){
        foreach($options as $option => $value){
            if(isset(self::$settings[$option]) ){
                if(gettype($value) === 'boolean' ){
                    self::$settings[$option] = $value;
                }
            }
        }
    }

}

?>