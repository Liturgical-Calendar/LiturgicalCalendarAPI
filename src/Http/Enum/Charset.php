<?php

namespace LiturgicalCalendar\Api\Http\Enum;

use LiturgicalCalendar\Api\Enum\EnumToArrayTrait;

/**
 * Represents all possible Charset values
 * that are supported by modern browsers
 */
enum Charset: string
{
    use EnumToArrayTrait;

    case UTF8        = 'utf-8';
    case UTF16LE     = 'utf-16le';
    case UTF16BE     = 'utf-16be';
    case WINDOWS1252 = 'windows-1252';
    case WINDOWS1250 = 'windows-1250';
    case WINDOWS1251 = 'windows-1251';
    case WINDOWS1253 = 'windows-1253';
    case WINDOWS1254 = 'windows-1254';
    case WINDOWS1255 = 'windows-1255';
    case WINDOWS1256 = 'windows-1256';
    case WINDOWS1257 = 'windows-1257';
    case WINDOWS1258 = 'windows-1258';
    case MACINTOSH   = 'macintosh';
    case MACCYRILLIC = 'x-mac-cyrillic';
    case KOI8R       = 'koi8-r';
    case KOI8U       = 'koi8-u';
    case ISO8859_3   = 'iso-8859-3';
    case ISO8859_4   = 'iso-8859-4';
    case ISO8859_10  = 'iso-8859-10';
    case ISO8859_15  = 'iso-8859-15';
    case ISO8859_16  = 'iso-8859-16';
    case GBK         = 'gbk';
    case GB18030     = 'gb18030';
    case BIG5        = 'big5';
    case EUCJP       = 'euc-jp';
    case ISO2022JP   = 'iso-2022-jp';
    case SHIFTJIS    = 'shift_jis';
    case EUCKR       = 'euc-kr';

    /**
     * Normalizes a given label to the canonical enum case, or null if unsupported.
     */
    public static function fromLabel(string $label): ?self
    {
        static $map = [
            // === Unicode ===
            'utf-8'               => self::UTF8,
            'unicode-1-1-utf-8'   => self::UTF8,

            'utf-16'              => self::UTF16LE,
            'utf-16le'            => self::UTF16LE,
            'utf-16be'            => self::UTF16BE,

            // === Windows-1252 family ===
            'windows-1252'        => self::WINDOWS1252,
            'ansi_x3.4-1968'      => self::WINDOWS1252,
            'ascii'               => self::WINDOWS1252,
            'cp1252'              => self::WINDOWS1252,
            'iso-8859-1'          => self::WINDOWS1252,
            'iso-ir-100'          => self::WINDOWS1252,
            'iso8859-1'           => self::WINDOWS1252,
            'latin1'              => self::WINDOWS1252,
            'l1'                  => self::WINDOWS1252,
            'us-ascii'            => self::WINDOWS1252,

            // === Windows-1250 ===
            'windows-1250'        => self::WINDOWS1250,
            'cp1250'              => self::WINDOWS1250,
            'iso-8859-2'          => self::WINDOWS1250,
            'latin2'              => self::WINDOWS1250,
            'iso-ir-101'          => self::WINDOWS1250,

            // === Windows-1251 ===
            'windows-1251'        => self::WINDOWS1251,
            'cp1251'              => self::WINDOWS1251,
            'iso-8859-5'          => self::WINDOWS1251,
            'cyrillic'            => self::WINDOWS1251,
            'iso-ir-144'          => self::WINDOWS1251,

            // === Windows-1253 ===
            'windows-1253'        => self::WINDOWS1253,
            'cp1253'              => self::WINDOWS1253,
            'iso-8859-7'          => self::WINDOWS1253,
            'greek'               => self::WINDOWS1253,
            'greek8'              => self::WINDOWS1253,
            'iso-ir-126'          => self::WINDOWS1253,

            // === Windows-1254 ===
            'windows-1254'        => self::WINDOWS1254,
            'cp1254'              => self::WINDOWS1254,
            'iso-8859-9'          => self::WINDOWS1254,
            'latin5'              => self::WINDOWS1254,
            'iso-ir-148'          => self::WINDOWS1254,

            // === Windows-1255 ===
            'windows-1255'        => self::WINDOWS1255,
            'cp1255'              => self::WINDOWS1255,
            'iso-8859-8-i'        => self::WINDOWS1255,
            'iso-8859-8'          => self::WINDOWS1255,
            'visual'              => self::WINDOWS1255,

            // === Windows-1256 ===
            'windows-1256'        => self::WINDOWS1256,
            'cp1256'              => self::WINDOWS1256,
            'iso-8859-6'          => self::WINDOWS1256,
            'arabic'              => self::WINDOWS1256,
            'iso-ir-127'          => self::WINDOWS1256,

            // === Windows-1257 ===
            'windows-1257'        => self::WINDOWS1257,
            'cp1257'              => self::WINDOWS1257,
            'iso-8859-13'         => self::WINDOWS1257,

            // === Windows-1258 ===
            'windows-1258'        => self::WINDOWS1258,
            'cp1258'              => self::WINDOWS1258,

            // === Macintosh ===
            'macintosh'           => self::MACINTOSH,
            'mac'                 => self::MACINTOSH,
            'x-mac-roman'         => self::MACINTOSH,

            'x-mac-cyrillic'      => self::MACCYRILLIC,
            'mac-cyrillic'        => self::MACCYRILLIC,

            // === KOI8 ===
            'koi8-r'              => self::KOI8R,
            'cskoi8r'             => self::KOI8R,
            'koi8-u'              => self::KOI8U,

            // === Other ISO encodings ===
            'iso-8859-3'          => self::ISO8859_3,
            'latin3'              => self::ISO8859_3,
            'iso-ir-109'          => self::ISO8859_3,

            'iso-8859-4'          => self::ISO8859_4,
            'latin4'              => self::ISO8859_4,
            'iso-ir-110'          => self::ISO8859_4,

            'iso-8859-10'         => self::ISO8859_10,
            'latin6'              => self::ISO8859_10,
            'iso-ir-157'          => self::ISO8859_10,

            'iso-8859-15'         => self::ISO8859_15,
            'latin9'              => self::ISO8859_15,

            'iso-8859-16'         => self::ISO8859_16,
            'latin10'             => self::ISO8859_16,

            // === Chinese encodings ===
            'gbk'                 => self::GBK,
            'cp936'               => self::GBK,
            'gb2312'              => self::GBK,
            'chinese'             => self::GBK,
            'csiso58gb231280'     => self::GBK,

            'gb18030'             => self::GB18030,

            'big5'                => self::BIG5,
            'big5-hkscs'          => self::BIG5,
            'cn-big5'             => self::BIG5,
            'x-x-big5'            => self::BIG5,

            // === Japanese encodings ===
            'euc-jp'              => self::EUCJP,
            'cseucpkdfmtjapanese' => self::EUCJP,

            'iso-2022-jp'         => self::ISO2022JP,
            'csiso2022jp'         => self::ISO2022JP,

            'shift_jis'           => self::SHIFTJIS,
            'sjis'                => self::SHIFTJIS,
            'ms_kanji'            => self::SHIFTJIS,
            'csshiftjis'          => self::SHIFTJIS,

            // === Korean encodings ===
            'euc-kr'              => self::EUCKR,
            'cseuckr'             => self::EUCKR,
            'ks_c_5601-1987'      => self::EUCKR,
            'iso-ir-149'          => self::EUCKR,
            'korean'              => self::EUCKR
        ];

        $label = strtolower(trim($label));
        return $map[$label] ?? null;
    }
}
