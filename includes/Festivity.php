<?php
ini_set('date.timezone', 'Europe/Vatican');

include_once( 'enums/LitColor.php' );
include_once( 'enums/LitCommon.php' );
include_once( 'enums/LitFeastType.php' );
include_once( 'enums/LitGrade.php' );
include_once( 'enums/LitLocale.php' );
include_once( 'enums/LitSeason.php' );
include_once( 'LitDateTime.php' );

class Festivity implements JsonSerializable
{
    public static $eventIdx = 0;

    public int      $idx;

    /** The following properties are generally passed in the constructor */
    public string   $name;
    public LitDateTime $date;
    public array    $color = [];
    public string   $type;
    public int      $grade;
    public string   $displayGrade;
    public array    $common;  //"Proper" or specified common(s) of saints...

    /** The following properties are set externally, but may be optional and therefore may remain null */
    public ?string  $liturgicalYear = null;
    public ?bool    $isVigilMass    = null;
    public ?bool    $hasVigilMass   = null;
    public ?bool    $hasVesperI     = null;
    public ?bool    $hasVesperII    = null;
    public ?int     $psalterWeek    = null;
    public ?string  $liturgicalSeason=null;

    /** The following properties are set based on properties passed in the constructor or on properties set externally*/
    public array    $colorLcl;
    public string   $gradeLcl;
    public string   $commonLcl;

    private static string $locale   = LitLocale::LATIN;
    private static LitGrade $LitGrade;
    private static LitSeason $LitSeason;
    private static LitCommon $LitCommon;
    private static IntlDateFormatter $dayOfTheWeekShort;
    private static IntlDateFormatter $dayOfTheWeekLong;
    private static IntlDateFormatter $monthShort;
    private static IntlDateFormatter $monthLong;

    function __construct(string $name, LitDateTime $date, string|array $color = [ '???' ], string $type = '???', int $grade = LitGrade::WEEKDAY, string|array $common = [ '???' ], string $displayGrade='')
    {
        $this->idx          = self::$eventIdx++;
        $this->name         = $name;
        $this->date         = $date; //DateTime object
        if( is_array( $color ) ) {
            if( LitColor::areValid( $color ) ) {
                $this->color = $color;
            }
        }
        else if ( is_string( $color ) ) {
            $_color             = strtolower( $color );
            //the color string can contain multiple colors separated by a comma, when there are multiple commons to choose from for that festivity
            $this->color        = strpos( $_color, "," ) && LitColor::areValid( explode(",", $_color) ) ? explode(",", $_color) : ( LitColor::isValid( $_color ) ? [ $_color ] : [ '???' ] );
        }
        $this->colorLcl     = array_map( fn($item) => LitColor::i18n($item, self::$locale), $this->color );
        $_type              = strtolower( $type );
        $this->type         = LitFeastType::isValid( $_type ) ? $_type : '???';
        $this->grade        = $grade >= LitGrade::WEEKDAY && $grade <= LitGrade::HIGHER_SOLEMNITY ? $grade : -1;
        $this->displayGrade = $displayGrade;
        $this->gradeLcl     = self::$LitGrade->i18n( $this->grade, false );
        //Festivity::debugWrite( "*** Festivity.php *** common vartype = " . gettype( $common ) );
        if( is_string( $common ) ) {
            //Festivity::debugWrite( "*** Festivity.php *** common vartype is string, value = $common" );
            $this->common       = LitCommon::areValid( explode(",", $common) ) ? explode(",", $common) : [];
        }
        else if( is_array( $common ) ) {
            //Festivity::debugWrite( "*** Festivity.php *** common vartype is array, value = " . implode( ', ', $common ) );
            if( LitCommon::areValid( $common ) ) {
                $this->common = $common;
            } else {
                //Festivity::debugWrite( "*** Festivity.php *** common values have not passed the validity test!" );
                $this->common = [];
            }
        }
        $this->commonLcl = self::$LitCommon->C( $this->common );
    }
    /*
    private static function debugWrite( string $string ) {
        file_put_contents( "debug.log", $string . PHP_EOL, FILE_APPEND );
    }
    */

    /* * * * * * * * * * * * * * * * * * * * * * * * *
     * Funzione statica di comparazione
     * in vista dell'ordinamento di un array di oggetti Festivity
     * Tiene conto non soltanto del valore della data,
     * ma anche del grado della festa qualora ci fosse una concomitanza
     * * * * * * * * * * * * * * * * * * * * * * * * * */
    public static function comp_date(Festivity $a, Festivity $b) {
        if ( $a->date == $b->date ) {
            if ( $a->grade == $b->grade ) {
                return 0;
            }
            return ( $a->grade > $b->grade ) ? +1 : -1;
        }
        return ( $a->date > $b->date ) ? +1 : -1;
    }

    /* Per trasformare i dati in JSON, dobbiamo indicare come trasformare soprattutto l'oggetto DateTime */
    public function jsonSerialize() : mixed {
        $returnArr = [
            'eventIdx'      => $this->idx,
            'name'          => $this->name,
            //serialize the DateTime object as a PHP timestamp (seconds since the Unix Epoch)
            'date'          => (int) $this->date->format('U'),
            'color'         => $this->color,
            'colorLcl'      => $this->colorLcl,
            'type'          => $this->type,
            'grade'         => $this->grade,
            'gradeLcl'      => $this->gradeLcl,
            'displayGrade'  => $this->displayGrade,
            'common'        => $this->common,
            'commonLcl'     => $this->commonLcl,
            'dayOfTheWeek_ISO8601'  => (int) $this->date->format('N'), //1 for Monday, 7 for Sunday
            'month'                 => (int) $this->date->format('n'), //1 for January, 12 for December
            'day'                   => (int) $this->date->format('j'),
            'year'                  => (int) $this->date->format('Y'),
            'monthShort'            => self::$monthShort->format( $this->date->format('U') ),
            'monthLong'             => self::$monthLong->format( $this->date->format('U') ),
            'dayOfTheWeekShort'     => self::$dayOfTheWeekShort->format( $this->date->format('U') ),
            'dayOfTheWeekLong'      => self::$dayOfTheWeekLong->format( $this->date->format('U') )
        ];
        if ( $this->liturgicalYear !== null ) {
            $returnArr['liturgicalYear']    = $this->liturgicalYear;
        }
        if ( $this->isVigilMass !== null ) {
            $returnArr['isVigilMass']       = $this->isVigilMass;
        }
        if ( $this->hasVigilMass !== null ) {
            $returnArr['hasVigilMass']      = $this->hasVigilMass;
        }
        if ( $this->hasVesperI !== null ) {
            $returnArr['hasVesperI']        = $this->hasVesperI;
        }
        if ( $this->hasVesperII !== null ) {
            $returnArr['hasVesperII']       = $this->hasVesperII;
        }
        if ( $this->psalterWeek !== null ) {
            $returnArr['psalterWeek']       = $this->psalterWeek;
        }
        if ( $this->liturgicalSeason !== null ) {
            $returnArr['liturgicalSeason']  = $this->liturgicalSeason;
            $returnArr['liturgicalSeasonLcl'] = LitSeason::i18n($this->liturgicalSeason, self::$locale);
        }
        return $returnArr;
    }

    public static function setLocale( string $locale ) : void {
        if( LitLocale::isValid( $locale ) ) {
            self::$locale               = $locale;
            self::$LitGrade             = new LitGrade( $locale );
            self::$LitCommon            = new LitCommon( $locale );
            self::$dayOfTheWeekShort    = IntlDateFormatter::create( $locale, IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE, 'UTC', IntlDateFormatter::GREGORIAN, "EEE" );
            self::$dayOfTheWeekLong     = IntlDateFormatter::create( $locale, IntlDateFormatter::FULL,   IntlDateFormatter::NONE, 'UTC', IntlDateFormatter::GREGORIAN, "EEEE" );
            self::$monthShort           = IntlDateFormatter::create( $locale, IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE, 'UTC', IntlDateFormatter::GREGORIAN, "MMM" );
            self::$monthLong            = IntlDateFormatter::create( $locale, IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE, 'UTC', IntlDateFormatter::GREGORIAN, "MMMM" );
        }
    }

}
