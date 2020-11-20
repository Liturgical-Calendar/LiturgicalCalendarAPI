<?php 

class FormControls {

    private static $settings = [
        "nameField"     => true,
        "dayField"      => true,
        "monthField"    => true,
        "colorField"    => true,
        "properField"   => true
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
            $formRow .= "<div class=\"form-group col-sm-4\">" .
            "<label for=\"{$uniqid}Name\">" . __("Name") . "</label><input type=\"text\" class=\"form-control\" id=\"{$uniqid}Name\" />" .
            "</div>";
        }

        if(self::$settings["dayField"]){
            $formRow .= "<div class=\"form-group col-sm-1\">" .
            "<label for=\"{$uniqid}Day\">" . __("Day") . "</label><input type=\"number\" min=1 max=31 value=1 class=\"form-control\" id=\"{$uniqid}Day\" />" .
            "</div>";
        }

        if(self::$settings["monthField"]){
            $formRow .= "<div class=\"form-group col-sm-2\">" .
            "<label for=\"{$uniqid}Month\">" . __("Month") . "</label>" .
            "<select class=\"form-control\" id=\"{$uniqid}Month\">";
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
            "<select class=\"form-control\" id=\"{$uniqid}Color\" />" .
            "<option value=\"WHITE\" selected>" . strtoupper(__("white") ) . "</option>" .
            "<option value=\"RED\">" . strtoupper(__("red") ) . "</option>" .
            "<option value=\"PURPLE\">" . strtoupper(__("purple") ) . "</option>" .
            "<option value=\"GREEN\">" . strtoupper(__("green") ) . "</option>" .
            "</select>" .
            "</div>";
        }

        if(self::$settings["properField"]){
            $formRow .= "<div class=\"form-group col-sm-3\">" .
            "<label for=\"{$uniqid}Proper\">" . __("Common (or Proper)") . "</label>" .
            "<select class=\"form-control\" id=\"{$uniqid}Proper\" />" .
            "<option value=\"PROPER\" selected>" . __("Proper") . "</option>" .
            "<option value=\"COMMONBVM\">" . __("Common of the Blessed Virgin Mary") . "</option>" .
            "<option value=\"COMMONMARTYRS\">" . __("Common of Martyrs") . "</option>" .
            "<option value=\"COMMONPASTORS\">" . __("Common of Pastors") . "</option>" .
            "<option value=\"COMMONDOCTORS\">" . __("Common of Doctors") . "</option>" .
            "<option value=\"COMMONVIRGINS\">" . __("Common of Virgins") . "</option>" .
            "<option value=\"COMMONHOLYMENWOMEN\">" . __("Common of Holy Men and Women") . "</option>" .
            "</select>" .
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