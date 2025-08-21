<?php

namespace LiturgicalCalendar\Api\Http\Enum;

use LiturgicalCalendar\Api\Enum\EnumToArrayTrait;

/**
 * Represents possible Content Types for the Response
 *  as indicated in a parameter in the request
 *  rather than in the Accept header
 */

enum ReturnTypeParam: string
{
    use EnumToArrayTrait;

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
    case ATTACHMENT = 'ATTACHMENT';

    public function toResponseContentType(): AcceptHeader
    {
        return match ($this) {
            ReturnTypeParam::JSON       => AcceptHeader::JSON,
            ReturnTypeParam::YAML       => AcceptHeader::YAML,
            ReturnTypeParam::XML        => AcceptHeader::XML,
            ReturnTypeParam::PDF        => AcceptHeader::PDF,
            ReturnTypeParam::HTML       => AcceptHeader::HTML,
            ReturnTypeParam::ICS        => AcceptHeader::ICS,
            ReturnTypeParam::TEXT       => AcceptHeader::TEXT,
            ReturnTypeParam::CSV        => AcceptHeader::CSV,
            ReturnTypeParam::CSS        => AcceptHeader::CSS,
            ReturnTypeParam::JS         => AcceptHeader::JS,
            ReturnTypeParam::MPEG       => AcceptHeader::MPEG,
            ReturnTypeParam::VORBIS     => AcceptHeader::VORBIS,
            ReturnTypeParam::OGG        => AcceptHeader::OGG,
            ReturnTypeParam::WEBM       => AcceptHeader::WEBM,
            ReturnTypeParam::JPG        => AcceptHeader::JPG,
            ReturnTypeParam::PNG        => AcceptHeader::PNG,
            ReturnTypeParam::APNG       => AcceptHeader::APNG,
            ReturnTypeParam::AVIF       => AcceptHeader::AVIF,
            ReturnTypeParam::GIF        => AcceptHeader::GIF,
            ReturnTypeParam::SVG        => AcceptHeader::SVG,
            ReturnTypeParam::WEBP       => AcceptHeader::WEBP,
            ReturnTypeParam::MP4        => AcceptHeader::MP4,
            ReturnTypeParam::VIDEO_OGG  => AcceptHeader::VIDEO_OGG,
            ReturnTypeParam::VIDEO_WEBM => AcceptHeader::VIDEO_WEBM,
            ReturnTypeParam::ATTACHMENT => AcceptHeader::ATTACHMENT
        };
    }
}
