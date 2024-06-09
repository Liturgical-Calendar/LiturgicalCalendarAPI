<?php

namespace LitCal\enum;

/**
 * class ReturnType
 * Represents possible Content Types for the Response
 *  as indicated in a parameter in the request
 *  rather than in the Accept header
 * The $values array must follow exactly the $values array
 *  in the ACCEPT_HEADER class, so that conversions can be made
 */

class ReturnType
{
    public const ATTACHMENT    = "ATTACHMENT";
    public const JSON          = "JSON";
    public const XML           = "XML";
    public const YML           = "YML";
    public const PDF           = "PDF";
    public const HTML          = "HTML";
    public const ICS           = "ICS";
    public const TEXT          = "TEXT";
    public const CSV           = "CSV";
    public const CSS           = "CSS";
    public const JS            = "JS";
    public const MPEG          = "MPEG";
    public const VORBIS        = "VORBIS";
    public const OGG           = "OGG";
    public const WEBM          = "WEBM";
    public const JPG           = "JPG";
    public const PNG           = "PNG";
    public const APNG          = "APNG";
    public const AVIF          = "AVIF";
    public const GIF           = "GIF";
    public const SVG           = "SVG";
    public const WEBP          = "WEBP";
    public const MP4           = "MP4";
    public const VIDEO_OGG     = "VIDEO_OGG";
    public const VIDEO_WEBM    = "VIDEO_WEBM";

    public static array $values = [
        "ATTACHMENT",
        "JSON",
        "XML",
        "YML",
        "PDF",
        "HTML",
        "ICS",
        "TEXT",
        "CSV",
        "CSS",
        "JS",
        "MPEG",
        "VORBIS",
        "OGG",
        "WEBM",
        "JPG",
        "PNG",
        "APNG",
        "AVIF",
        "GIF",
        "SVG",
        "WEBP",
        "MP4",
        "VIDEO_OGG",
        "VIDEO_WEBM"
    ];

    public static function isValid($value)
    {
        return in_array($value, self::$values);
    }
}
