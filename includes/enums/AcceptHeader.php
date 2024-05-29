<?php

namespace LitCal\enum;

class AcceptHeader
{
    public const ATTACHMENT = "application/octet-stream";
    public const JSON      = "application/json";
    public const XML       = "application/xml";
    public const YML       = "application/yaml";
    public const PDF       = "application/pdf";
    public const HTML      = "text/html";
    public const ICS       = "text/calendar";
    public const TEXT      = "text/plain";
    public const CSV       = "text/csv";
    public const CSS       = "text/css";
    public const JS        = "text/javascript";
    public const MPEG      = "audio/mpeg";
    public const VORBIS    = "audio/vorbis";
    public const OGG       = "audio/ogg";
    public const WEBM      = "audio/webm";
    public const JPG       = "image/jpeg";
    public const PNG       = "image/png";
    public const APNG      = "image/apng";
    public const AVIF      = "image/avif";
    public const GIF       = "image/gif";
    public const SVG       = "image/svg+xml";
    public const WEBP      = "image/webp";
    public const MP4       = "video/mp4";
    public const VIDEO_OGG = "video/ogg";
    public const VIDEO_WEBM = "video/webm";

    public static array $values = [
        "application/octet-stream",
        "application/json",
        "application/xml",
        "application/yaml",
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

    public static function isValid($value)
    {
        return in_array($value, self::$values);
    }
}
