<?php

/**
 * class ReturnType
 * Represents possible Content Types for the Response
 *  as indicated in a parameter in the request
 *  rather than in the Accept header
 * The $values array must follow exactly the $values array
 *  in the ACCEPT_HEADER class, so that conversions can be made
 */

class ReturnType {
    const ATTACHMENT    = "ATTACHMENT";
    const JSON          = "JSON";
    const XML           = "XML";
    const PDF           = "PDF";
    const HTML          = "HTML";
    const ICS           = "ICS";
    const TEXT          = "TEXT";
    const CSV           = "CSV";
    const CSS           = "CSS";
    const JS            = "JS";
    const MPEG          = "MPEG";
    const VORBIS        = "VORBIS";
    const OGG           = "OGG";
    const WEBM          = "WEBM";
    const JPG           = "JPG";
    const PNG           = "PNG";
    const APNG          = "APNG";
    const AVIF          = "AVIF";
    const GIF           = "GIF";
    const SVG           = "SVG";
    const WEBP          = "WEBP";
    const MP4           = "MP4";
    const VIDEO_OGG     = "VIDEO_OGG";
    const VIDEO_WEBM    = "VIDEO_WEBM";

    public static array $values = [
        "ATTACHMENT",
        "JSON",
        "XML",
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

    public static function isValid( $value ) {
        return in_array( $value, self::$values );
    }
}
