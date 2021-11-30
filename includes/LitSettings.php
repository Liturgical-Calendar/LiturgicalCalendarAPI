<?php

include_once( 'enums/Epiphany.php' );
include_once( 'enums/Ascension.php' );
include_once( 'enums/CorpusChristi.php' );
include_once( 'enums/LitLocale.php' );
include_once( 'enums/ReturnType.php' );

class LITSETTINGS {
    public int $YEAR;
    public string $EPIPHANY      = EPIPHANY::JAN6;
    public string $ASCENSION     = ASCENSION::THURSDAY;
    public string $CORPUSCHRISTI = CORPUSCHRISTI::THURSDAY;
    public string $LOCALE        = LIT_LOCALE::LA;
    public ?string $RETURNTYPE   = null;
    public ?string $NATIONAL     = null;
    public ?string $DIOCESAN     = null;

    const ALLOWED_PARAMS  = [
        "YEAR",
        "EPIPHANY",
        "ASCENSION",
        "CORPUSCHRISTI",
        "LOCALE",
        "RETURNTYPE",
        "NATIONAL",
        "DIOCESAN"
    ];

    const SUPPORTED_NATIONAL_PRESETS = [ "ITALY", "USA", "VATICAN" ];
  
    public function __construct( array $DATA ){
        $this->YEAR = (int)date("Y");
        foreach( $DATA as $key => $value ){
            $key = strtoupper( $key );
            if( in_array( $key, self::ALLOWED_PARAMS ) ){
                switch( $key ){
                    case "YEAR":
                        if( gettype( $value ) === 'string' ){
                            if( is_numeric( $value ) && ctype_digit( $value ) && strlen( $value ) === 4 ){
                                $value = (int)$value;
                                if( $value > 1582 && $value < 9999 ){
                                    $this->YEAR = $value;
                                }
                            }
                        } elseif( gettype( $value ) === 'integer' ) {
                            if( $value > 1582 && $value < 9999 ){
                                $this->YEAR = $value;
                            }
                        }
                        break;
                    case "EPIPHANY":
                        $this->EPIPHANY         = EPIPHANY::isValid( strtoupper( $value ) ) ? strtoupper( $value ) : EPIPHANY::JAN6;
                        break;
                    case "ASCENSION":
                        $this->ASCENSION        = ASCENSION::isValid( strtoupper( $value ) ) ? strtoupper( $value ) : ASCENSION::SUNDAY;
                        break;
                    case "CORPUSCHRISTI":
                        $this->CORPUSCHRISTI    = CORPUSCHRISTI::isValid( strtoupper( $value ) ) ? strtoupper( $value ) : CORPUSCHRISTI::SUNDAY;
                        break;
                    case "LOCALE":
                        $this->LOCALE           = LIT_LOCALE::isValid( strtoupper( $value ) ) ? strtoupper( $value ) : LIT_LOCALE::LA;
                        break;
                    case "RETURNTYPE":
                        $this->RETURNTYPE       = RETURN_TYPE::isValid( strtoupper( $value ) ) ? strtoupper( $value ) : RETURN_TYPE::JSON;
                        break;
                    case "NATIONALPRESET":
                        $this->NATIONAL         = in_array( strtoupper( $value ), self::SUPPORTED_NATIONAL_PRESETS ) ? strtoupper( $value ) : null;
                        break;
                    case "DIOCESANPRESET":
                        $this->DIOCESAN         = strtoupper( $value );
                }
            }
        }
    }

}
