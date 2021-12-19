<?php

class AcceptHeader {
    const ATTACHMENT= "application/octet-stream";
    const JSON      = "application/json";
    const XML       = "application/xml";
    const PDF       = "application/pdf";
    const HTML      = "text/html";
    const ICS       = "text/calendar";
    const TEXT      = "text/plain";
    const CSV       = "text/csv";
    const CSS       = "text/css";
    const JS        = "text/javascript";
    const MPEG      = "audio/mpeg";
    const VORBIS    = "audio/vorbis";
    const OGG       = "audio/ogg";
    const WEBM      = "audio/webm";
    const JPG       = "image/jpeg";
    const PNG       = "image/png";
    const APNG      = "image/apng";
    const AVIF      = "image/avif";
    const GIF       = "image/gif";
    const SVG       = "image/svg+xml";
    const WEBP      = "image/webp";
    const MP4       = "video/mp4";
    const VIDEO_OGG = "video/ogg";
    const VIDEO_WEBM= "video/webm";

    public static array $values = [
        "application/octet-stream",
        "application/json",
        "application/xml",
        "application/pdf",
        "text/html",
        "text/calendar",
        "text/plain",
        "text/csv",
        "text/css",
        "text/javascript",
        "audio/mpeg",
        "audio/vorbis",
        "audio/ogg",
        "audio/webm",
        "image/jpeg",
        "image/png",
        "image/apng",
        "image/avif",
        "image/gif",
        "image/svg+xml",
        "image/webp",
        "video/mp4",
        "video/ogg",
        "video/webm"
    ];

    public static function isValid( $value ) {
        return in_array( $value, self::$values );
    }
}
