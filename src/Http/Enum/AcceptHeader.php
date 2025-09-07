<?php

namespace LiturgicalCalendar\Api\Http\Enum;

use LiturgicalCalendar\Api\Enum\EnumToArrayTrait;

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

    public function toReturnTypeParam(): ReturnTypeParam
    {
        return match ($this) {
            AcceptHeader::ATTACHMENT => ReturnTypeParam::ATTACHMENT,
            AcceptHeader::JSON       => ReturnTypeParam::JSON,
            AcceptHeader::YAML       => ReturnTypeParam::YAML,
            AcceptHeader::XML        => ReturnTypeParam::XML,
            AcceptHeader::PDF        => ReturnTypeParam::PDF,
            AcceptHeader::HTML       => ReturnTypeParam::HTML,
            AcceptHeader::ICS        => ReturnTypeParam::ICS,
            AcceptHeader::TEXT       => ReturnTypeParam::TEXT,
            AcceptHeader::CSV        => ReturnTypeParam::CSV,
            AcceptHeader::CSS        => ReturnTypeParam::CSS,
            AcceptHeader::JS         => ReturnTypeParam::JS,
            AcceptHeader::MPEG       => ReturnTypeParam::MPEG,
            AcceptHeader::VORBIS     => ReturnTypeParam::VORBIS,
            AcceptHeader::OGG        => ReturnTypeParam::OGG,
            AcceptHeader::WEBM       => ReturnTypeParam::WEBM,
            AcceptHeader::JPG        => ReturnTypeParam::JPG,
            AcceptHeader::PNG        => ReturnTypeParam::PNG,
            AcceptHeader::APNG       => ReturnTypeParam::APNG,
            AcceptHeader::AVIF       => ReturnTypeParam::AVIF,
            AcceptHeader::GIF        => ReturnTypeParam::GIF,
            AcceptHeader::SVG        => ReturnTypeParam::SVG,
            AcceptHeader::WEBP       => ReturnTypeParam::WEBP,
            AcceptHeader::MP4        => ReturnTypeParam::MP4,
            AcceptHeader::VIDEO_OGG  => ReturnTypeParam::VIDEO_OGG,
            AcceptHeader::VIDEO_WEBM => ReturnTypeParam::VIDEO_WEBM
        };
    }
}
