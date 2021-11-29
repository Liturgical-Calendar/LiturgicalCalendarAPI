<?php
ini_set('date.timezone', 'Europe/Vatican');
class Festivity implements JsonSerializable
{
    private static array $liturgical_colors = [ "green", "purple", "white", "red", "pink" ];
    private static array $feast_type = [ "fixed", "mobile" ];
    public static $eventIdx = 0;
    public int $idx;
    public string $name;
    public DateTime $date;
    public string $color; //"green","purple","white","red","pink"
    public string $type;  //"mobile" or "fixed"
    public int $grade; //0=Weekday,1=Commemoration,2=Optional memorial,3=Obligatory memorial,4=Feast,5=Feast of the Lord,6=Solemnity,7=Higher Solemnity
    public string $displayGrade;
    public string $common;  //"Proper" or specified common(s) of saints...

    /** The following properties are not used in construction, they are only set externally */
    public string $liturgicalyear;
    public ?bool $isVigilMass = null;
    public ?bool $hasVigilMass = null;
    public ?bool $hasVesperI = null;
    public ?bool $hasVesperII = null;
    public ?int $psalterWeek = null;

    function __construct(string $name, DateTime $date, string $color, string $type, int $grade = 0, string $common = '', string $displayGrade='')
    {
        //enforce typecasting
        $this->name = (string) $name;
        $this->date = $date; //DateTime object
        $_color = (string) strtolower($color);
        //the color string can contain multiple colors separated by a pipe character, which correspond with the multiple commons to choose from for that festivity
        $this->color = strpos($_color, ",") && empty(array_diff(explode(",", $_color), self::$liturgical_colors)) ? $_color : (in_array($_color, self::$liturgical_colors) ? $_color : "???");
        $this->type = in_array((string) strtolower($type), self::$feast_type) ? (string) strtolower($type) : "???";
        $this->grade = (int) $grade >= 0 && (int) $grade <= 7 ? (int) $grade : -1;
        $this->common = (string) $common;
        $this->displayGrade = (string) $displayGrade;
        $this->idx = self::$eventIdx++;
    }

    /* * * * * * * * * * * * * * * * * * * * * * * * *
     * Funzione statica di comparazione
     * in vista dell'ordinamento di un array di oggetti Festivity
     * Tiene conto non soltanto del valore della data,
     * ma anche del grado della festa qualora ci fosse una concomitanza
     * * * * * * * * * * * * * * * * * * * * * * * * * */
    public static function comp_date(Festivity $a, Festivity $b)
    {
        if ($a->date == $b->date) {
            if ($a->grade == $b->grade) {
                return 0;
            }
            return ($a->grade > $b->grade) ? +1 : -1;
        }
        return ($a->date > $b->date) ? +1 : -1;
    }

    /* Per trasformare i dati in JSON, dobbiamo indicare come trasformare soprattutto l'oggetto DateTime */
    public function jsonSerialize()
    {
        $returnArr = [
            'name'          => $this->name,
            'color'         => $this->color,
            'type'          => $this->type,
            'grade'         => $this->grade,
            'common'        => $this->common,
            'date'          => $this->date->format('U'), //serialize the DateTime object as a PHP timestamp
            'displaygrade'  => $this->displayGrade,
            'eventidx'      => $this->idx
        ];
        if($this->liturgicalyear !== null){
            $returnArr['liturgicalyear']    = $this->liturgicalyear;
        }
        if($this->isVigilMass !== null){
            $returnArr['isVigilMass']       = $this->isVigilMass;
        }
        if($this->hasVigilMass !== null){
            $returnArr['hasVigilMass']      = $this->hasVigilMass;
        }
        if($this->hasVesperI !== null){
            $returnArr['hasVesperI']        = $this->hasVesperI;
        }
        if($this->hasVesperII !== null){
            $returnArr['hasVesperII']       = $this->hasVesperII;
        }
        if($this->psalterWeek !== null){
            $returnArr['psalterWeek']       = $this->psalterWeek;
        }
        return $returnArr;
    }
}
