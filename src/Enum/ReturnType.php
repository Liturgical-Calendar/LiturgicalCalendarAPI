<?php

namespace LiturgicalCalendar\Api\Enum;

/**
 * Represents possible Content Types for the Response
 *  as indicated in a parameter in the request
 *  rather than in the Accept header
 * The backed values must follow exactly the order of the backed values
 *  in the \LitCal\Enum\AcceptHeader class, so that conversions can be made
 */

enum ReturnType: string
{
    use EnumToArrayTrait;

    case ATTACHMENT = 'ATTACHMENT';
    case JSON       = 'JSON';
    case YAML       = 'YML';
    case XML        = 'XML';
    case PDF        = 'PDF';
    case HTML       = 'HTML';
    case ICS        = 'ICS';
    case TEXT       = 'TEXT';
    case CSV        = 'CSV';
    case CSS        = 'CSS';
    case JS         = 'JS';
    case MPEG       = 'MPEG';
    case VORBIS     = 'VORBIS';
    case OGG        = 'OGG';
    case WEBM       = 'WEBM';
    case JPG        = 'JPG';
    case PNG        = 'PNG';
    case APNG       = 'APNG';
    case AVIF       = 'AVIF';
    case GIF        = 'GIF';
    case SVG        = 'SVG';
    case WEBP       = 'WEBP';
    case MP4        = 'MP4';
    case VIDEO_OGG  = 'VIDEO_OGG';
    case VIDEO_WEBM = 'VIDEO_WEBM';
}
