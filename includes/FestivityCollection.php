<?php

include_once( 'includes/enums/LitGrade.php' );
include_once( 'includes/Festivity.php' );
include_once( 'includes/LitMessages.php' );
include_once( 'includes/LitSettings.php' );

class FestivityCollection {

    private array $festivities      = [];
    private array $solemnities      = [];
    private array $feasts           = [];
    private array $memorials        = [];
    private array $WeekdayAdventChristmasLent   = [];
    private array $WeekdaysEpiphany                = [];
    private array $SolemnitiesLordBVM             = [];
    private array $SundaysAdventLentEaster       = [];
    private array $T                                = [];
    private IntlDateFormatter $dayOfTheWeek;
    private LitSettings $LitSettings;
    private LitGrade $LitGrade;
    const SUNDAY_CYCLE              = [ "A", "B", "C" ];
    const WEEKDAY_CYCLE             = [ "I", "II" ];

    public function __construct( LitSettings $LitSettings ) {
        $this->LitSettings = $LitSettings;
        $this->dayOfTheWeek = IntlDateFormatter::create( strtolower( $this->LitSettings->Locale ), IntlDateFormatter::FULL, IntlDateFormatter::NONE, 'UTC', IntlDateFormatter::GREGORIAN, "EEEE" );
        if( $this->LitSettings->Locale === LitLocale::LATIN ) {
            $this->T = [
                "YEAR"          => "ANNUM",
                "Vigil Mass"    => "Missa in Vigilia"
            ];
        } else {
            $this->T = [
                /**translators: in reference to the cycle of liturgical years (A, B, C; I, II) */
                "YEAR"          => _( "YEAR" ),
                "Vigil Mass"    => _( "Vigil Mass" )
            ];
        }
        $this->LitGrade = new LitGrade( $this->LitSettings->Locale );
    }

    private static function DateIsSunday( DateTime $dt ) : bool {
        return (int)$dt->format( 'N' ) === 7;
    }

    private static function DateIsNotSunday( DateTime $dt ) : bool {
        return (int)$dt->format( 'N' ) !== 7;
    }

    public function addFestivity( string $key, Festivity $festivity ) : void {
        $this->festivities[ $key ] = $festivity;
        if( $festivity->grade >= LitGrade::FEAST_LORD ) {
            $this->solemnities[ $key ]  = $festivity->date;
        }
        if( $festivity->grade === LitGrade::FEAST ) {
            $this->feasts[ $key ]       = $festivity->date;
        }
        if( $festivity->grade === LitGrade::MEMORIAL ) {
            $this->memorials[ $key ]    = $festivity->date;
        }
        // Weekday of Advent from 17 to 24 Dec.
        if ( str_starts_with( $key, "AdventWeekday" ) && $festivity->date->format( 'j' ) >= 17 && $festivity->date->format( 'j' ) <= 24 ) {
            $this->WeekdayAdventChristmasLent[ $key ] = $festivity->date;
        }
        else if( str_starts_with( $key, "ChristmasWeekday" ) ) {
            $this->WeekdayAdventChristmasLent[ $key ] = $festivity->date;
        }
        else if( str_starts_with( $key, "LentWeekday" ) ) {
            $this->WeekdayAdventChristmasLent[ $key ] = $festivity->date;
        }
        else if( str_starts_with( $key, "DayBeforeEpiphany" ) || str_starts_with( $key, "DayAfterEpiphany" ) ) {
            $this->WeekdaysEpiphany[ $key ] = $festivity->date;
        }
        //Sundays of Advent, Lent, Easter
        if( preg_match( '/(?:Advent|Lent|Easter)([1-7])/', $key, $matches ) === 1 ) {
            $this->SundaysAdventLentEaster[] = $festivity->date;
            $this->festivities[ $key ]->psalterWeek = self::psalterWeek( intval( $matches[1] ) );
        }
        //Ordinary Sunday Psalter Week
        if( preg_match( '/OrdSunday([1-9][0-9]*)/', $key, $matches ) === 1 ) {
            $this->festivities[ $key ]->psalterWeek = self::psalterWeek( intval( $matches[1] ) );
        }
    }

    public function addSolemnitiesLordBVM( array $keys ) : void {
        array_push( $this->SolemnitiesLordBVM, $keys );
    }

    public function getFestivity( string $key ) : ?Festivity {
        if( array_key_exists( $key, $this->festivities ) ) {
            return $this->festivities[ $key ];
        }
        return null;
    }

    public function getCalEventsFromDate( DateTime $date ) : array {
        return array_filter( $this->festivities, function( $el ) use ( $date ) { return $el->date == $date; } );
    }

    public function isSolemnityLordBVM( string $key ) {
        return in_array( $key, $this->SolemnitiesLordBVM );
    }

    public function isSundayAdventLentEaster( DateTime $date ) {
        return in_array( $date, $this->SundaysAdventLentEaster );
    }

    public function inSolemnities( DateTime $date ) : bool {
        return in_array( $date, $this->solemnities );
    }

    public function inFeasts( DateTime $date ) : bool {
        return in_array( $date, $this->feasts );
    }

    public function inSolemnitiesOrFeasts( DateTime $date ) : bool {
        return $this->inSolemnities( $date ) || $this->inFeasts( $date );
    }

    public function inMemorials( DateTime $date ) : bool {
        return in_array( $date, $this->memorials );
    }

    public function inFeastsOrMemorials( DateTime $date ) : bool {
        return $this->inFeasts( $date ) || $this->inMemorials( $date );
    }

    public function inSolemnitiesFeastsOrMemorials( DateTime $date ) : bool {
        return $this->inSolemnities( $date ) || $this->inFeastsOrMemorials( $date );
    }

    public function notInSolemnitiesFeastsOrMemorials( DateTime $date ) : bool {
        return !$this->inSolemnitiesFeastsOrMemorials( $date );
    }

    public function inWeekdaysAdventChristmasLent( DateTime $date ) : bool {
        return in_array( $date, $this->WeekdayAdventChristmasLent );
    }

    public function inWeekdaysEpiphany( DateTime $date ) : bool {
        return in_array( $date, $this->WeekdaysEpiphany );
    }

    public function inCalendar( DateTime $date ) : bool {
        return count( array_filter( $this->festivities, function( $el ) use( $date ) { $el->date == $date; } ) ) > 0;
    }

    public function solemnityFromDate( DateTime $date ) : ?Festivity {
        $key = array_search( $date, $this->solemnities );
        if( $key && array_key_exists( $key, $this->festivities ) ) {
            return $this->festivities[ $key ];
        }
        return null;
    }

    public function solemnityKeyFromDate( DateTime $date ) : string|int|false {
        return array_search( $date, $this->solemnities );
    }

    public function weekdayEpiphanyKeyFromDate( DateTime $date ) : string|int|false {
        return array_search( $date, $this->WeekdaysEpiphany );
    }

    public function feastOrMemorialFromDate( DateTime $date ) : ?Festivity {
        $key = array_search( $date, $this->feasts );
        if( $key && array_key_exists( $key, $this->festivities ) ) {
            return $this->festivities[ $key ];
        }
        $key = array_search( $date, $this->memorials );
        if( $key && array_key_exists( $key, $this->festivities ) ) {
            return $this->festivities[ $key ];
        }
        return null;
    }

    public function feastOrMemorialKeyFromDate( DateTime $date ) : string|int|false {
        $key = array_search( $date, $this->feasts );
        if( $key ){
            return $key;
        }
        return array_search( $date, $this->memorials );
    }

    public function moveFestivityDate( string $key, DateTime $newDate ) : void {
        if( array_key_exists( $key, $this->festivities ) ) {
            $this->festivities[ $key ]->date = $newDate;
        }
    }

    private function handleGradeProperty( string $key, int $value, int $oldValue ) : void {
        if( $value >= LitGrade::FEAST_LORD ) {
            $this->solemnities[ $key ] = $this->festivities[ $key ]->date;
            if( $oldValue < LitGrade::FEAST_LORD && $this->feastOrMemorialKeyFromDate( $this->festivities[ $key ]->date ) === $key ) {
                if( $this->inFeasts( $this->festivities[ $key ]->date ) ) {
                    unset( $this->feasts[ $key ] );
                }
                elseif( $this->inMemorials( $this->festivities[ $key ]->date ) ) {
                    unset( $this->memorials[ $key ] );
                }
            }
        }
        else if( $value === LitGrade::FEAST ) {
            $this->feasts[ $key ] = $this->festivities[ $key ]->date;
            if( $oldValue > LitGrade::FEAST ) {
                unset( $this->solemnities[ $key ] );
            }
            else if( $oldValue === LitGrade::MEMORIAL ) {
                unset( $this->memorials[ $key ] );
            }
        }
        else if( $value === LitGrade::MEMORIAL ) {
            $this->memorials[ $key ] = $this->festivities[ $key ]->date;
            if( $oldValue > LitGrade::FEAST ) {
                unset( $this->solemnities[ $key ] );
            }
            elseif( $oldValue > LitGrade::MEMORIAL ) {
                unset ( $this->feasts[ $key ] );
            }
        }
    }

    public function setProperty( string $key, string $property, string|int|bool $value ) : bool {
        $reflect = new ReflectionClass( new Festivity("test", new DateTime('NOW')) );
        if( array_key_exists( $key, $this->festivities ) ) {
            $oldValue = $this->festivities[ $key ]->{$property};
            if( $reflect->hasProperty( $property ) ) {
                if( $reflect->getProperty( $property )->getType() instanceof ReflectionNamedType && $reflect->getProperty( $property )->getType()->getName() === get_debug_type( $value ) ) {
                    $this->festivities[ $key ]->{$property} = $value;
                }
                elseif( $reflect->getProperty( $property )->getType() instanceof ReflectionUnionType && in_array( get_debug_type( $value ), $reflect->getProperty( $property )->getType()->getTypes() ) ) {
                    $this->festivities[ $key ]->{$property} = $value;
                }
                if( $key === "grade" ) {
                    $this->handleGradeProperty( $key, $value, $oldValue );
                }
                return true;
            }
        }
        return false;
    }

    public function removeFestivity( string $key ) :void {
        $date = $this->festivities[ $key ]->date;
        if( $this->inSolemnities( $date ) && $this->solemnityKeyFromDate( $date ) === $key ) {
            unset( $this->solemnities[ $key ] );
        }
        if( $this->inFeasts( $date ) && $this->feastOrMemorialKeyFromDate( $date ) === $key ) {
            unset( $this->feasts[ $key ] );
        }
        if( $this->inMemorials( $date ) && $this->feastOrMemorialKeyFromDate( $date ) === $key ) {
            unset( $this->memorials[ $key ] );
        }
        unset( $this->festivities[ $key ] );
    }

    public function setCyclesAndVigils() {
        foreach( $this->festivities as $key => $festivity ) {
            if ( self::DateIsNotSunday( $festivity->date ) && (int)$festivity->grade === LitGrade::WEEKDAY ) {
                if ( $festivity->date < $this->festivities[ "Advent1" ]->date ) {
                    $this->festivities[ $key ]->liturgicalYear = $this->T[ "YEAR" ] . " " . ( self::WEEKDAY_CYCLE[ ( $this->LitSettings->Year - 1 ) % 2 ] );
                } else if ( $festivity->date >= $this->festivities[ "Advent1" ]->date ) {
                    $this->festivities[ $key ]->liturgicalYear = $this->T[ "YEAR" ] . " " . ( self::WEEKDAY_CYCLE[ $this->LitSettings->Year % 2 ] );
                }
            }
            //if we're dealing with a Sunday or a Solemnity or a Feast of the Lord, then we calculate the Sunday/Festive Cycle
            else if( self::DateIsSunday( $festivity->date ) || (int)$festivity->grade > LitGrade::FEAST ) {
                if ( $festivity->date < $this->festivities[ "Advent1" ]->date ) {
                    $this->festivities[ $key ]->liturgicalYear = $this->T[ "YEAR" ] . " " . ( self::SUNDAY_CYCLE[ ( $this->LitSettings->Year - 1 ) % 3 ] );
                } else if ( $festivity->date >= $this->festivities[ "Advent1" ]->date ) {
                    $this->festivities[ $key ]->liturgicalYear = $this->T[ "YEAR" ] . " " . ( self::SUNDAY_CYCLE[ $this->LitSettings->Year % 3 ] );
                }
                $this->calculateVigilMass( $key, $festivity );
            }
        }
    }

    private function calculateVigilMass( string $key, Festivity $festivity ) {

        //Let's calculate Vigil Masses while we're at it
        //We'll both create new events and add metadata to existing events
        $VigilDate = clone( $festivity->date );
        $VigilDate->sub( new DateInterval( 'P1D' ) );
        $festivityGrade = '';
        if( self::DateIsSunday( $festivity->date ) && $festivity->grade < LitGrade::SOLEMNITY ) {
            $festivityGrade = $this->LitSettings->Locale === LitLocale::LATIN ? 'Die Domini' : ucfirst( $this->dayOfTheWeek->format( $festivity->date->format( 'U' ) ) );
        } else {
            if( $festivity->grade > LitGrade::SOLEMNITY ) {
                $festivityGrade = '<i>' . $this->LitGrade->i18n( $festivity->grade, false ) . '</i>';
            } else {
                $festivityGrade = $this->LitGrade->i18n( $festivity->grade, false );
            }
        }

        //conditions for which the festivity SHOULD have a vigil
        if( self::DateIsSunday( $festivity->date ) || true === ( $festivity->grade >= LitGrade::SOLEMNITY ) ){
            //filter out cases in which the festivity should NOT have a vigil
            if(
                false === ( $key === 'AllSouls' )
                && false === ( $key === 'AshWednesday' )
                && false === ( $festivity->date > $this->festivities[ "PalmSun" ]->date && $festivity->date < $this->festivities[ "Easter" ]->date )
                && false === ( $festivity->date > $this->festivities[ "Easter" ]->date && $festivity->date < $this->festivities[ "Easter2" ]->date )
            ){
                $this->festivities[ $key . "_vigil" ] = new Festivity( 
                    $festivity->name . " " . $this->T[ "Vigil Mass" ],
                    $VigilDate,
                    $festivity->color,
                    $festivity->type,
                    $festivity->grade,
                    $festivity->common
                );
                $this->festivities[ $key ]->hasVigilMass                 = true;
                $this->festivities[ $key ]->hasVesperI                   = true;
                $this->festivities[ $key ]->hasVesperII                  = true;
                $this->festivities[ $key . "_vigil" ]->isVigilMass       = true;
                $this->festivities[ $key . "_vigil" ]->liturgicalYear    = $this->festivities[ $key ]->liturgicalYear;
                //if however the Vigil coincides with another Solemnity let's make a note of it!
                if( $this->inSolemnities( $VigilDate ) ) {
                    $coincidingFestivity = new stdClass();
                    $coincidingFestivity->grade = '';
                    $coincidingFestivity->key = $this->solemnityKeyFromDate( $VigilDate );
                    $coincidingFestivity->event = $this->festivities[ $coincidingFestivity->key ];
                    if( self::DateIsSunday( $VigilDate ) && $coincidingFestivity->event->grade < LitGrade::SOLEMNITY ){
                        //it's a Sunday
                        $coincidingFestivity->grade = $this->LitSettings->Locale === LitLocale::LATIN ? 'Die Domini' : ucfirst( $this->dayOfTheWeek->format( $VigilDate->format( 'U' ) ) );
                    } else{
                        //it's a Feast of the Lord or a Solemnity
                        $coincidingFestivity->grade = ( $coincidingFestivity->event->grade > LitGrade::SOLEMNITY ? '<i>' . $this->LitGrade->i18n( $coincidingFestivity->event->grade, false ) . '</i>' : $this->LitGrade->i18n( $coincidingFestivity->event->grade, false ) );
                    }

                    //suppress warning messages for known situations, like the Octave of Easter
                    if( $festivity->grade !== LitGrade::HIGHER_SOLEMNITY ){
                        if( $festivity->grade < $coincidingFestivity->event->grade ){
                            $festivity->hasVigilMass = false;
                            $festivity->hasVesperI = false;
                            $coincidingFestivity->event->hasVesperII = true;
                            unset( $this->festivities[ $key . "_vigil" ] );
                            $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                                _( "The Vigil Mass for the %s '%s' coincides with the %s '%s' in the year %d. This last Solemnity takes precedence, therefore it will maintain Vespers II and an evening Mass, while the first Solemnity will not have a Vigil Mass or Vespers I." ),
                                $festivityGrade,
                                $festivity->name,
                                $coincidingFestivity->grade,
                                $coincidingFestivity->event->name,
                                $this->LitSettings->Year
                            );
                        }
                        else if( $festivity->grade > $coincidingFestivity->event->grade || ( $this->isSolemnityLordBVM( $key ) && !$this->isSolemnityLordBVM( $coincidingFestivity->key ) ) ) {
                            $festivity->hasVigilMass = true;
                            $festivity->hasVesperI = true;
                            $coincidingFestivity->event->hasVesperII = false;
                            $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                                _( "The Vigil Mass for the %s '%s' coincides with the %s '%s' in the year %d. Since the first Solemnity has precedence, it will have Vespers I and a vigil Mass, whereas the last Solemnity will not have either Vespers II or an evening Mass." ),
                                $festivityGrade,
                                $festivity->name,
                                $coincidingFestivity->grade,
                                $coincidingFestivity->event->name,
                                $this->LitSettings->Year
                            );
                        }
                        else if( $this->isSolemnityLordBVM( $coincidingFestivity->key ) && !$this->isSolemnityLordBVM( $key ) ){
                            $coincidingFestivity->event->hasVesperII = true;
                            $festivity->hasVesperI = false;
                            $festivity->hasVigilMass = false;
                            unset( $this->festivities[ $key . "_vigil" ] );
                            $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                                _( "The Vigil Mass for the %s '%s' coincides with the %s '%s' in the year %d. This last Solemnity takes precedence, therefore it will maintain Vespers II and an evening Mass, while the first Solemnity will not have a Vigil Mass or Vespers I." ),
                                $festivityGrade,
                                $festivity->name,
                                $coincidingFestivity->grade,
                                $coincidingFestivity->event->name,
                                $this->LitSettings->Year
                            );
                        } else {
                            if( $this->LitSettings->Year === 2022 ){
                                if( $key === 'SacredHeart' || $key === 'Lent3' || $key === 'Assumption' ){
                                    $coincidingFestivity->event->hasVesperII = false;
                                    $festivity->hasVesperI = true;
                                    $festivity->hasVigilMass = true;
                                    $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                                        _( "The Vigil Mass for the %s '%s' coincides with the %s '%s' in the year %d. As per %s, the first has precedence, therefore the Vigil Mass is confirmed as are I Vespers." ),
                                        $festivityGrade,
                                        $festivity->name,
                                        $coincidingFestivity->grade,
                                        $coincidingFestivity->event->name,
                                        $this->LitSettings->Year,
                                        '<a href="http://www.cultodivino.va/content/cultodivino/it/documenti/responsa-ad-dubia/2020/de-calendario-liturgico-2022.html">' . _( "Decree of the Congregation for Divine Worship" ) . '</a>'
                                    );
                                }
                            }
                            else {
                                $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                                    _( "The Vigil Mass for the %s '%s' coincides with the %s '%s' in the year %d. We should ask the Congregation for Divine Worship what to do about this!" ),
                                    $festivityGrade,
                                    $festivity->name,
                                    $coincidingFestivity->grade,
                                    $coincidingFestivity->event->name,
                                    $this->LitSettings->Year
                                );
                            }
                        }
                    } else {
                        if(
                            //false === ( $key === 'AllSouls' )
                            //&& false === ( $key === 'AshWednesday' )
                            false === ( $coincidingFestivity->event->date > $this->festivities[ "PalmSun" ]->date && $coincidingFestivity->event->date < $this->festivities[ "Easter" ]->date )
                            && false === ( $coincidingFestivity->event->date > $this->festivities[ "Easter" ]->date && $coincidingFestivity->event->date < $this->festivities[ "Easter2" ]->date )
                        ){

                            $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                                _( "The Vigil Mass for the %s '%s' coincides with the %s '%s' in the year %d. Since the first Solemnity has precedence, it will have Vespers I and a vigil Mass, whereas the last Solemnity will not have either Vespers II or an evening Mass." ),
                                $festivityGrade,
                                $festivity->name,
                                $coincidingFestivity->grade,
                                $coincidingFestivity->event->name,
                                $this->LitSettings->Year
                            );
                        }
                    }
                }
            } else {
                $this->festivities[ $key ]->hasVigilMass = false;
                $this->festivities[ $key ]->hasVesperI = false;
            }
        }

    }

    //$this->getFestivities() returns an associative array, who's keys are a string that identifies the event created ( ex. ImmaculateConception )
    //So in order to sort by date we have to be sure to maintain the association with the proper key, uasort allows us to do this
    public function sortFestivities() : void {
        uasort( $this->festivities, array( "Festivity", "comp_date" ) );
    }

    public function getFestivities() : array {
        return $this->festivities;
    }

    public function getSolemnities() : array {
        return $this->solemnities;
    }

    public function getFeastsAndMemorials() : array {
        return array_merge( $this->feasts, $this->memorials );
    }

    public function determineSundaySolemnityOrFeast( DateTime $currentFeastDate ) : stdClass {
        $coincidingFestivity = new stdClass();
        $coincidingFestivity->grade = '';
        if( self::DateIsSunday( $currentFeastDate ) && $this->solemnityFromDate( $currentFeastDate )->grade < LitGrade::SOLEMNITY ){
            //it's a Sunday
            $coincidingFestivity->event = $this->solemnityFromDate( $currentFeastDate );
            $coincidingFestivity->grade = $this->LitSettings->Locale === LitLocale::LATIN ? 'Die Domini' : ucfirst( $this->dayOfTheWeek->format( $currentFeastDate->format( 'U' ) ) );
        } else if ( $this->inSolemnities( $currentFeastDate ) ) {
            //it's a Feast of the Lord or a Solemnity
            $coincidingFestivity->event = $this->solemnityFromDate( $currentFeastDate );
            $coincidingFestivity->grade = ( $coincidingFestivity->event->grade > LitGrade::SOLEMNITY ? '<i>' . $this->LitGrade->i18n( $coincidingFestivity->event->grade, false ) . '</i>' : $this->LitGrade->i18n( $coincidingFestivity->event->grade, false ) );
        } else if( $this->inFeastsOrMemorials( $currentFeastDate ) ) {
            $coincidingFestivity->event = $this->feastOrMemorialFromDate( $currentFeastDate );
            $coincidingFestivity->grade = $this->LitGrade->i18n( $coincidingFestivity->event->grade, false );
        }
        return $coincidingFestivity;
    }

    /**
     * psalterWeek function
     * Calculates the current Week of the Psalter (from 1 to 4)
     * based on the week of Ordinary Time
     * OR the week of Advent, Christmas, Lent, or Easter
     */
    public static function psalterWeek( int $weekOfOrdinaryTimeOrSeason ) : int {
        return $weekOfOrdinaryTimeOrSeason % 4 === 0 ? 4 : $weekOfOrdinaryTimeOrSeason % 4;
    }

}
