<?php 

class FormControls {

    //public function __construct()
    public static function CreateFestivityRow($title){
        $uniqid = uniqid();
        $formRow = "<h4>" . $title . "</h4>" .
        "<div class=\"form-row\">" . 
        "<div class=\"form-group col-sm-5\">" .
        "<label for=\"{$uniqid}Name\">" . __("Name") . "</label><input type=\"text\" class=\"form-control\" id=\"{$uniqid}Name\" />" .
        "</div>" .
        "<div class=\"form-group col-sm-1\">" .
        "<label for=\"{$uniqid}Day\">" . __("Day") . "</label><input type=\"number\" min=1 max=31 value=1 class=\"form-control\" id=\"{$uniqid}Day\" />" .
        "</div>" .
        "<div class=\"form-group col-sm-2\">" .
        "<label for=\"{$uniqid}Month\">" . __("Month") . "</label>" .
        "<select class=\"form-control\" id=\"{$uniqid}Month\">";
        $formatter = new IntlDateFormatter(LITCAL_LOCALE, IntlDateFormatter::FULL, IntlDateFormatter::NONE);
        $formatter->setPattern("MMMM");
        for($i=1;$i<=12;$i++){
            $month = DateTime::createFromFormat("n",$i, new DateTimeZone('UTC') );
            $formRow .= "<option value={$i}>" . $formatter->format($month) . "</option>";
        }

        $formRow .= "</select>" .
        "</div>" .
        "<div class=\"form-group col-sm-3\">" .
        "<label for=\"{$uniqid}Color\">" . __("Liturgical color") . "</label>" .
        "<select class=\"form-control\" id=\"{$uniqid}Color\" />" .
        "<option value=\"WHITE\" selected>" . strtoupper(__("white") ) . "</option>" .
        "<option value=\"RED\">" . strtoupper(__("red") ) . "</option>" .
        "<option value=\"PURPLE\">" . strtoupper(__("purple") ) . "</option>" .
        "<option value=\"GREEN\">" . strtoupper(__("green") ) . "</option>" .
        "</select>" .
        "</div>" .
        "</div>";

        echo $formRow;
    }
}

?>