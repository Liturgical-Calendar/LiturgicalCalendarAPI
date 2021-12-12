<?php

include_once( 'includes/enums/LitGrade.php' );
include_once( 'includes/Festivity.php' );
include_once( 'includes/LitCalMessages.php' );
include_once( 'includes/LitSettings.php' );

class FestivityCollection {

    private array $festivities      = [];
    private array $solemnities      = [];
    private array $feasts           = [];
    private array $memorials        = [];
    private array $WEEKDAYS_ADVENT_CHRISTMAS_LENT   = [];
    private array $WEEKDAYS_EPIPHANY                = [];
    private array $SOLEMNITIES_LORD_BVM             = [];
    private array $SUNDAYS_ADVENT_LENT_EASTER       = [];
    const SUNDAY_CYCLE              = [ "A", "B", "C" ];
    const WEEKDAY_CYCLE             = [ "I", "II" ];

    public function __construct() {
        //nothing particular about this
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
            $this->WEEKDAYS_ADVENT_CHRISTMAS_LENT[ $key ] = $festivity->date;
        }
        else if( str_starts_with( $key, "ChristmasWeekday" ) ) {
            $this->WEEKDAYS_ADVENT_CHRISTMAS_LENT[ $key ] = $festivity->date;
        }
        else if( str_starts_with( $key, "LentWeekday" ) ) {
            $this->WEEKDAYS_ADVENT_CHRISTMAS_LENT[ $key ] = $festivity->date;
        }
        else if( str_starts_with( $key, "DayBeforeEpiphany" ) || str_starts_with( $key, "DayAfterEpiphany" ) ) {
            $this->WEEKDAYS_EPIPHANY[ $key ] = $festivity->date;
        }
        //Sundays of Advent, Lent, Easter
        if( preg_match( '/(?:Advent|Lent|Easter)([1-7])/', $key, $matches ) === 1 ) {
            $this->SUNDAYS_ADVENT_LENT_EASTER[] = $festivity->date;
            $this->festivities[ $key ]->psalterWeek = intval( $matches[1] ) % 4 === 0 ? 4 : intval( $matches[1] ) % 4;
        }
        //Ordinary Sunday Psalter Week
        if( preg_match( '/OrdSunday([1-9][0-9]*)/', $key, $matches ) === 1 ) {
            $this->festivities[ $key ]->psalterWeek = intval( $matches[1] ) % 4 === 0 ? 4 : intval( $matches[1] ) % 4;
        }
    }

    public function addSolemnitiesLordBVM( array $keys ) : void {
        array_push( $this->SOLEMNITIES_LORD_BVM, $keys );
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
        return in_array( $key, $this->SOLEMNITIES_LORD_BVM );
    }

    public function isSundayAdventLentEaster( DateTime $date ) {
        return in_array( $date, $this->SUNDAYS_ADVENT_LENT_EASTER );
    }

    public function inSolemnities( DateTime $date ) : bool {
        return in_array( $date, $this->solemnities );
    }

    public function inFeasts( DateTime $date ) : bool {
        return in_array( $date, $this->feasts );
    }

    public function inMemorials( DateTime $date ) : bool {
        return in_array( $date, $this->memorials );
    }

    public function inFeastsOrMemorials( DateTime $date ) : bool {
        return $this->inFeasts( $date ) || $this->inMemorials( $date );
    }

    public function inWeekdaysAdventChristmasLent( DateTime $date ) : bool {
        return in_array( $date, $this->WEEKDAYS_ADVENT_CHRISTMAS_LENT );
    }

    public function inWeekdaysEpiphany( DateTime $date ) : bool {
        return in_array( $date, $this->WEEKDAYS_EPIPHANY );
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
        return array_search( $date, $this->WEEKDAYS_EPIPHANY );
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
                if( $reflect->getProperty( $property )->getType() instanceof ReflectionNamedType && $reflect->getProperty( $property )->getType()->getName() === gettype( $value ) ) {
                    $this->festivities[ $key ]->{$property} = $value;
                }
                elseif( $reflect->getProperty( $property )->getType() instanceof ReflectionUnionType && in_array( gettype( $value ), $reflect->getProperty( $property )->getType()->getTypes() ) ) {
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

    public function setCyclesAndVigils( LitSettings $LITSETTINGS ) {
        foreach( $this->festivities as $key => $festivity ) {
            if ( self::DateIsNotSunday( $festivity->date ) && (int)$festivity->grade === LitGrade::WEEKDAY ) {
                if ( $festivity->date < $this->festivities[ "Advent1" ]->date ) {
                    $this->festivities[ $key ]->liturgicalYear = LITCAL_MESSAGES::__( "YEAR", $LITSETTINGS->LOCALE ) . " " . ( self::WEEKDAY_CYCLE[ ( $LITSETTINGS->YEAR - 1 ) % 2 ] );
                } else if ( $festivity->date >= $this->festivities[ "Advent1" ]->date ) {
                    $this->festivities[ $key ]->liturgicalYear = LITCAL_MESSAGES::__( "YEAR", $LITSETTINGS->LOCALE ) . " " . ( self::WEEKDAY_CYCLE[ $LITSETTINGS->YEAR % 2 ] );
                }
            }
            //if we're dealing with a Sunday or a Solemnity or a Feast of the Lord, then we calculate the Sunday/Festive Cycle
            else if( self::DateIsSunday( $festivity->date ) || (int)$festivity->grade > LitGrade::FEAST ) {
                if ( $festivity->date < $this->festivities[ "Advent1" ]->date ) {
                    $this->festivities[ $key ]->liturgicalYear = LITCAL_MESSAGES::__( "YEAR", $LITSETTINGS->LOCALE ) . " " . ( self::SUNDAY_CYCLE[ ( $LITSETTINGS->YEAR - 1 ) % 3 ] );
                } else if ( $festivity->date >= $this->festivities[ "Advent1" ]->date ) {
                    $this->festivities[ $key ]->liturgicalYear = LITCAL_MESSAGES::__( "YEAR", $LITSETTINGS->LOCALE ) . " " . ( self::SUNDAY_CYCLE[ $LITSETTINGS->YEAR % 3 ] );
                }
                $this->calculateVigilMass( $key, $festivity, $LITSETTINGS );
            }
        }
    }

    private function calculateVigilMass( string $key, Festivity $festivity, LITSETTINGS $LITSETTINGS ) {

        //Let's calculate Vigil Masses while we're at it
        //We'll create new events and add metadata
        $VigilDate = clone( $festivity->date );
        $VigilDate->sub( new DateInterval( 'P1D' ) );
        $festivityGrade = '';
        if( self::DateIsSunday( $festivity->date ) && $festivity->grade < LitGrade::SOLEMNITY ) {
            $festivityGrade = $LITSETTINGS->LOCALE === 'LA' ? 'Die Domini' : ucfirst( utf8_encode( strftime( '%A', $festivity->date->format( 'U' ) ) ) );
        } else {
            if( $festivity->grade > LitGrade::SOLEMNITY ) {
                $festivityGrade = '<i>' . LITCAL_MESSAGES::_G( $festivity->grade, $LITSETTINGS->LOCALE, false ) . '</i>';
            } else {
                $festivityGrade = LITCAL_MESSAGES::_G( $festivity->grade, $LITSETTINGS->LOCALE, false );
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
                    $festivity->name . " " . LITCAL_MESSAGES::__( "Vigil Mass", $LITSETTINGS->LOCALE ),
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
                        $coincidingFestivity->grade = $LITSETTINGS->LOCALE === 'LA' ? 'Die Domini' : ucfirst( utf8_encode( strftime( '%A', $VigilDate->format( 'U' ) ) ) );
                    } else{
                        //it's a Feast of the Lord or a Solemnity
                        $coincidingFestivity->grade = ( $coincidingFestivity->event->grade > LitGrade::SOLEMNITY ? '<i>' . LITCAL_MESSAGES::_G( $coincidingFestivity->event->grade, $LITSETTINGS->LOCALE, false ) . '</i>' : LITCAL_MESSAGES::_G( $coincidingFestivity->event->grade, $LITSETTINGS->LOCALE, false ) );
                    }

                    //suppress warning messages for known situations, like the Octave of Easter
                    if( $festivity->grade !== LitGrade::HIGHER_SOLEMNITY ){
                        if( $festivity->grade < $coincidingFestivity->event->grade ){
                            $festivity->hasVigilMass = false;
                            $festivity->hasVesperI = false;
                            $coincidingFestivity->event->hasVesperII = true;
                            $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                                LITCAL_MESSAGES::__( "The Vigil Mass for the %s '%s' coincides with the %s '%s' in the year %d. This last Solemnity takes precedence, therefore it will maintain Vespers II and an evening Mass, while  the first Solemnity will not have a Vigil Mass or Vespers I.", $LITSETTINGS->LOCALE ),
                                $festivityGrade,
                                $festivity->name,
                                $coincidingFestivity->grade,
                                $coincidingFestivity->event->name,
                                $LITSETTINGS->YEAR
                            );
                        }
                        else if( $festivity->grade > $coincidingFestivity->event->grade || ( $this->isSolemnityLordBVM( $key ) && !$this->isSolemnityLordBVM( $coincidingFestivity->key ) ) ) {
                            $festivity->hasVigilMass = true;
                            $festivity->hasVesperI = true;
                            $coincidingFestivity->event->hasVesperII = false;
                            $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                                LITCAL_MESSAGES::__( "The Vigil Mass for the %s '%s' coincides with the %s '%s' in the year %d. Since the first Solemnity has precedence, it will have Vespers I and a vigil Mass, whereas the last Solemnity will not have either Vespers II or an evening Mass.", $LITSETTINGS->LOCALE ),
                                $festivityGrade,
                                $festivity->name,
                                $coincidingFestivity->grade,
                                $coincidingFestivity->event->name,
                                $LITSETTINGS->YEAR
                            );
                        }
                        else if( in_array( $coincidingFestivity->key, $this->SOLEMNITIES_LORD_BVM ) && !in_array( $key, $this->SOLEMNITIES_LORD_BVM ) ){
                            $coincidingFestivity->event->hasVesperII = true;
                            $festivity->hasVesperI = false;
                            $festivity->hasVigilMass = false;
                            unset( $this->festivities[ $key . "_vigil" ] );
                            $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                                LITCAL_MESSAGES::__( "The Vigil Mass for the %s '%s' coincides with the %s '%s' in the year %d. This last Solemnity takes precedence, therefore it will maintain Vespers II and an evening Mass, while  the first Solemnity will not have a Vigil Mass or Vespers I.", $LITSETTINGS->LOCALE ),
                                $festivityGrade,
                                $festivity->name,
                                $coincidingFestivity->grade,
                                $coincidingFestivity->event->name,
                                $LITSETTINGS->YEAR
                            );
                        } else {
                            if( $LITSETTINGS->YEAR === 2022 ){
                                if( $key === 'SacredHeart' || $key === 'Lent3' || $key === 'Assumption' ){
                                    $coincidingFestivity->event->hasVesperII = false;
                                    $festivity->hasVesperI = true;
                                    $festivity->hasVigilMass = true;
                                    $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                                        LITCAL_MESSAGES::__( "The Vigil Mass for the %s '%s' coincides with the %s '%s' in the year %d. As per %s, the first has precedence, therefore the Vigil Mass is confirmed as are I Vespers.", $LITSETTINGS->LOCALE ),
                                        $festivityGrade,
                                        $festivity->name,
                                        $coincidingFestivity->grade,
                                        $coincidingFestivity->event->name,
                                        $LITSETTINGS->YEAR,
                                        '<a href="http://www.cultodivino.va/content/cultodivino/it/documenti/responsa-ad-dubia/2020/de-calendario-liturgico-2022.html">' . LITCAL_MESSAGES::__( "Decree of the Congregation for Divine Worship", $LITSETTINGS->LOCALE ) . '</a>'
                                    );
                                }
                            }
                            else {
                                $this->Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                                    LITCAL_MESSAGES::__( "The Vigil Mass for the %s '%s' coincides with the %s '%s' in the year %d. We should ask the Congregation for Divine Worship what to do about this!", $LITSETTINGS->LOCALE ),
                                    $festivityGrade,
                                    $festivity->name,
                                    $coincidingFestivity->grade,
                                    $coincidingFestivity->event->name,
                                    $LITSETTINGS->YEAR
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
                                LITCAL_MESSAGES::__( "The Vigil Mass for the %s '%s' coincides with the %s '%s' in the year %d. Since the first Solemnity has precedence, it will have Vespers I and a vigil Mass, whereas the last Solemnity will not have either Vespers II or an evening Mass.", $LITSETTINGS->LOCALE ),
                                $festivityGrade,
                                $festivity->name,
                                $coincidingFestivity->grade,
                                $coincidingFestivity->event->name,
                                $LITSETTINGS->YEAR
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

}
