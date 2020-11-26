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

        if(self::$settings["colorField"]){
            $formRow .= "<div class=\"form-group col-sm-2\">" .
            "<label for=\"{$uniqid}Color\">" . __("Liturgical color") . "</label>" .
            "<select class=\"form-control litEvent litEventColor\" id=\"{$uniqid}Color\" />" .
            "<option value=\"WHITE\" selected>" . strtoupper(__("white") ) . "</option>" .
            "<option value=\"RED\">" . strtoupper(__("red") ) . "</option>" .
            "<option value=\"PURPLE\">" . strtoupper(__("purple") ) . "</option>" .
            "<option value=\"GREEN\">" . strtoupper(__("green") ) . "</option>" .
            "</select>" .
            "</div>";
        }

        if(self::$settings["properField"]){
            $formRow .= "<div class=\"form-group col-sm-3\">" .
            "<label style=\"display:block;\" for=\"{$uniqid}Proper\">" . __("Common (or Proper)") . "</label>" .
            "<select class=\"form-control litEvent litEventProper\" id=\"{$uniqid}Proper\" multiple=\"multiple\" />" .
            "<option value=\"PROPER\" selected>" . __("Proper") . "</option>" .
            "<option value=\"COMMONBVM\">" . __("Common of the Blessed Virgin Mary") . "</option>" .
            //"<optgroup label=\"" . __("Common of Martyrs") . "\">" .
            "<option value=\"COMMONMARTYRS\">" . __("Common of Martyrs") . "</option>" .
            "<option value=\"COMMONMARTYRS_ONE\">" . __("Common of Martyrs: For One Martyr") . "</option>" .
            "<option value=\"COMMONMARTYRS_SEVERAL\">" . __("Common of Martyrs: For Several Martyrs") . "</option>" .
            "<option value=\"COMMONMARTYRS_MISSIONARY\">" . __("Common of Martyrs: For Missionary Martyrs") . "</option>" .
            "<option value=\"COMMONMARTYRS_VIRGIN\">" . __("Common of Martyrs: For a Virgin Martyr") . "</option>" .
            //"<optgroup label=\"" . __("Common of Pastors") . "\">" .
            "<option value=\"COMMONPASTORS\">" . __("Common of Pastors") . "</option>" .
            "<option value=\"COMMONPASTORS_POPE\">" . __("Common of Pastors: For a Pope") . "</option>" .
            "<option value=\"COMMONPASTORS_BISHOP\">" . __("Common of Pastors: For a Bishop") . "</option>" .
            "<option value=\"COMMONPASTORS_ONE\">" . __("Common of Pastors: For One Pastor") . "</option>" .
            "<option value=\"COMMONPASTORS_SEVERAL\">" . __("Common of Pastors: For Several Pastors") . "</option>" .
            "<option value=\"COMMONPASTORS_MISSIONARY\">" . __("Common of Pastors: For Missionaries") . "</option>" .
            "<option value=\"COMMONDOCTORS\">" . __("Common of Doctors") . "</option>" .
            //"<optgroup label=\"" . __("Common of Virgins") . "\">" .
            "<option value=\"COMMONVIRGINS\">" . __("Common of Virgins") . "</option>" .
            "<option value=\"COMMONVIRGINS_ONE\">" . __("Common of Virgins: For One Virgin") . "</option>" .
            "<option value=\"COMMONVIRGINS_SEVERAL\">" . __("Common of Virgins: For Several Virgins") . "</option>" .
            //"<optgroup label=\"" . __("Common of Holy Men and Women") . "\">" .
            "<option value=\"COMMONHOLYMENWOMEN\">" . __("Common of Holy Men and Women") . "</option>" .
            "<option value=\"COMMONHOLYMENWOMEN_ONE\">" . __("Common of Holy Men and Women: For One Saint") . "</option>" .
            "<option value=\"COMMONHOLYMENWOMEN_RELIGIOUS\">" . __("Common of Holy Men and Women: For Religious") . "</option>" .
            "<option value=\"COMMONHOLYMENWOMEN_ABBOT\">" . __("Common of Holy Men and Women: For an Abbot") . "</option>" .
            "<option value=\"COMMONHOLYMENWOMEN_MONK\">" . __("Common of Holy Men and Women: For a Monk") . "</option>" .
            "<option value=\"COMMONHOLYMENWOMEN_NUN\">" . __("Common of Holy Men and Women: For a Nun") . "</option>" .
            "<option value=\"COMMONHOLYMENWOMEN_EDUCATORS\">" . __("Common of Holy Men and Women: For Educators") . "</option>" .
            "<option value=\"COMMONHOLYMENWOMEN_WOMEN\">" . __("Common of Holy Men and Women: For Holy Women") . "</option>" .
            "<option value=\"COMMONHOLYMENWOMEN_MERCY\">" . __("Common of Holy Men and Women: For Those Who Practiced Works of Mercy") . "</option>" .
            "<option value=\"COMMONDEDICATIONCHURCH\">" . __("Common of the Dedication of a Church") . "</option>" .
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