<?php

namespace LiturgicalCalendar\Api\Http\Enum;

use LiturgicalCalendar\Api\Enum\EnumToArrayTrait;

enum ContentEncoding: string
{
    use EnumToArrayTrait;

    case BR       = 'br';        // Brotli
    case GZIP     = 'gzip';      // Gzip
    case DEFLATE  = 'deflate';   // Zlib DEFLATE
    case IDENTITY = 'identity';  // No compression

    /**
     * Normalizes a given encoding string to the enum, or null if unsupported.
     */
    public static function fromValueOrNull(string $encoding): ?self
    {
        $encoding = strtolower(trim($encoding));
        return match ($encoding) {
            'br'       => self::BR,
            'gzip'     => self::GZIP,
            'x-gzip'   => self::GZIP,   // some servers send x-gzip
            'deflate'  => self::DEFLATE,
            'identity' => self::IDENTITY,
            default    => null,
        };
    }
}
