<?php
    class Festivity implements JsonSerializable {
        public $name;
        public $date;
        public $color; //"green","purple","white","red","pink"
        public $type;  //"mobile" or "fixed"
        public $grade; //0=Weekday,1=Commemoration,2=Optional memorial,3=Obligatory memorial,4=Feast,5=Feast of the Lord,6=Solemnity,7=Higher Solemnity
        public $common;//"Proper" or specified common(s) of saints...
    	private static $liturgical_colors = array("green","purple","white","red","pink");
	private static $feast_type = array("fixed","mobile");
	    
        function __construct($name,$date,$color,$type,$grade=0,$common='') 
        {
            //enforce typecasting
            $this->name = (string) $name;
            $this->date = (object) $date; //DateTime object
            $_color = (string) strtolower($color);
            //the color string can contain multiple colors separated by a pipe character, which correspond with the multiple commons to choose from for that festivity
            $this->color = strpos($_color,"|") && empty( array_diff( explode("|",$_color), self::$liturgical_colors ) ) ? $_color : (in_array($_color,self::$liturgical_colors) ? $_color : "???");
            $this->type = in_array((string) strtolower($type),self::$feast_type) ? (string) strtolower($type) : "???";
            $this->grade = (int) $grade >= 0 && (int) $grade <= 6 ? (int) $grade : -1;
            $this->common = (string) $common;
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
        
        /* Per trasformare i dati in JSON, dobbiamo indicare come trasformare soprattutto l'oggetto DateTime */
        public function jsonSerialize() {
            return [
                'name'      => $this->name,
                'color'     => $this->color,
                'type'      => $this->type,
                'grade'     => $this->grade,
                'common'    => $this->common,
                'date'      => $this->date->format('U') //serialize the DateTime object as a PHP timestamp
            ];
        }

    }
