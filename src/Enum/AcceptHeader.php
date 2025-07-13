<?php

namespace LiturgicalCalendar\Api\Enum;

enum AcceptHeader: string
{
    use EnumToArrayTrait;

    case ATTACHMENT = 'application/octet-stream';
    case JSON       = 'application/json';
    case YAML       = 'application/yaml';
    case XML        = 'application/xml';
    case PDF        = 'application/pdf';
    case HTML       = 'text/html';
    case ICS        = 'text/calendar';
    case TEXT       = 'text/plain';
    case CSV        = 'text/csv';
    case CSS        = 'text/css';
    case JS         = 'text/javascript';
    case MPEG       = 'audio/mpeg';
    case VORBIS     = 'audio/vorbis';
    case OGG        = 'audio/ogg';
    case WEBM       = 'audio/webm';
    case JPG        = 'image/jpeg';
    case PNG        = 'image/png';
    case APNG       = 'image/apng';
    case AVIF       = 'image/avif';
    case GIF        = 'image/gif';
    case SVG        = 'image/svg+xml';
    case WEBP       = 'image/webp';
    case MP4        = 'video/mp4';
    case VIDEO_OGG  = 'video/ogg';
    case VIDEO_WEBM = 'video/webm';
}
