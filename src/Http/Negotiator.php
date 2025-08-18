<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Http;

use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Http\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Http\Enum\ContentEncoding;
use Psr\Http\Message\ServerRequestInterface;

/**
 * RFC 9110–aware negotiation helpers for:
 *  - Accept
 *  - Accept-Language
 *  - Accept-Encoding
 *
 * Usage (PSR-7):
 *   $mime = Negotiator::pickMediaType($request->getHeaderLine('Accept'), ['application/json', 'text/html']);
 *   $lang = Negotiator::pickLanguage($request->getHeaderLine('Accept-Language'), ['it-IT','en-US','en'], 'en');
 *   $enc  = Negotiator::pickEncoding($request->getHeaderLine('Accept-Encoding'), ['br','gzip','identity']);
 */
final class Negotiator
{
    /** @var string[] */
    private static array $acceptValues = [];

    /** @var string[] */
    private static array $filteredAcceptValues = [];

    /* ========================= Common utilities ========================= */

    /** Split a header list on commas, honoring quoted strings. */
    private static function splitCommaSeparated(string $header): array
    {
        $items   = [];
        $buf     = '';
        $inQuote = false;
        $escape  = false;

        $len = strlen($header);
        for ($i = 0; $i < $len; $i++) {
            $ch = $header[$i];

            if ($inQuote) {
                if ($escape) {
                    $buf   .= $ch;
                    $escape = false;
                } elseif ($ch === '\\') {
                    $escape = true;
                } elseif ($ch === '"') {
                    $inQuote = false;
                    $buf    .= $ch;
                } else {
                    $buf .= $ch;
                }
                continue;
            }

            if ($ch === '"') {
                $inQuote = true;
                $buf    .= $ch;
                continue;
            }

            if ($ch === ',') {
                $trimmed = trim($buf, " \t");
                if ($trimmed !== '') {
                    $items[] = $trimmed;
                }
                $buf = '';
            } else {
                $buf .= $ch;
            }
        }

        $trimmed = trim($buf, " \t");
        if ($trimmed !== '') {
            $items[] = $trimmed;
        }

        return $items;
    }

    /** Split parameters on ';' outside quotes; return [value, params[]]. */
    private static function splitParameters(string $item): array
    {
        $parts   = [];
        $buf     = '';
        $inQuote = false;
        $escape  = false;

        $len = strlen($item);
        for ($i = 0; $i < $len; $i++) {
            $ch = $item[$i];

            if ($inQuote) {
                if ($escape) {
                    $buf   .= $ch;
                    $escape = false;
                } elseif ($ch === '\\') {
                    $escape = true;
                } elseif ($ch === '"') {
                    $inQuote = false;
                    $buf    .= $ch;
                } else {
                    $buf .= $ch;
                }
                continue;
            }

            if ($ch === '"') {
                $inQuote = true;
                $buf    .= $ch;
                continue;
            }

            if ($ch === ';') {
                $parts[] = trim($buf, " \t");
                $buf     = '';
            } else {
                $buf .= $ch;
            }
        }
        $parts[] = trim($buf, " \t");

        $value  = array_shift($parts) ?? '';
        $params = [];
        foreach ($parts as $p) {
            if ($p === '') {
                continue;
            }
            $eq = strpos($p, '=');
            if ($eq === false) {
                $params[strtolower($p)] = null;
                continue;
            }
            $k    = strtolower(trim(substr($p, 0, $eq)));
            $vRaw = trim(substr($p, $eq + 1));
            // Unquote quoted-string if present
            if (strlen($vRaw) >= 2 && $vRaw[0] === '"' && $vRaw[strlen($vRaw) - 1] === '"') {
                $v = stripcslashes(substr($vRaw, 1, -1));
            } else {
                $v = $vRaw;
            }
            $params[$k] = $v;
        }

        return [$value, $params];
    }

    /** Parse q-value per RFC 9110; invalid → 0.0 */
    private static function parseQ(?string $q): float
    {
        if ($q === null || $q === '') {
            return 1.0;
        }
        $q = trim($q);
        // Valid: 0, 0.xxx, 1, 1.0, 1.00, 1.000 (≤3 decimals)
        if (!preg_match('/^(?:0(?:\.\d{0,3})?|1(?:\.0{0,3})?)$/', $q)) {
            return 0.0;
        }
        return (float) $q;
    }

    /* ========================= Accept (media types) ========================= */

    /** Parse Accept into structured entries. */
    public static function parseAccept(string $header): array
    {
        $items = self::splitCommaSeparated($header);
        $out   = [];
        $i     = 0;

        foreach ($items as $raw) {
            [$type, $params] = self::splitParameters($raw);
            if ($type === '') {
                continue;
            }

            $type = strtolower(trim($type));
            $q    = self::parseQ($params['q'] ?? null);
            unset($params['q']);

            // Specificity: 2 = type/subtype, 1 = type/*, 0 = */*
            $specificity = 2;
            if ($type === '*/*') {
                $specificity = 0;
            }
            elseif (str_ends_with($type, '/*')) {
                $specificity = 1;
            }

            // More params (besides q) → slightly more specific
            $paramBonus = max(0, count($params));

            $out[] = [
                'raw'         => $raw,
                'type'        => $type,
                'params'      => $params,
                'q'           => $q,
                'specificity' => $specificity,
                'paramBonus'  => $paramBonus,
                'index'       => $i++,
            ];
        }

        usort($out, function ($a, $b) {
            return [$b['q'], $b['specificity'], $b['paramBonus'], $a['index']]
                 <=> [$a['q'], $a['specificity'], $a['paramBonus'], $b['index']];
        });

        return $out;
    }

    /** Pick best media type from server-supported list (e.g., ['application/json','text/html']). */
    public static function pickMediaType(ServerRequestInterface $request, array $supported = []): ?string
    {
        $acceptHeader = $request->getHeaderLine('Accept');
        if (empty($supported)) {
            $supportedMediaTypes = AcceptHeader::values();
        } else {
            $supportedMediaTypes = array_values(array_unique(array_map('strtolower', $supported)));
        }

        if (self::isBrowserRequest($request)) {
            return $supportedMediaTypes[0] ?? null;
        }

        self::$acceptValues         = self::parseAccept($acceptHeader);
        self::$filteredAcceptValues = array_filter(self::$acceptValues, fn ($v) => in_array($v['type'], $supportedMediaTypes, true));

        // If no Accept header, RFC allows */* with q=1 → pick first supported.
        if ($acceptHeader === '' || $acceptHeader === null) {
            return $supportedMediaTypes[0] ?? null;
        }

        $best      = null;
        $bestScore = [-1, -1, -1, PHP_INT_MAX]; // q, specificity, paramBonus, index tie-breaker

        foreach ($supportedMediaTypes as $sup) {
            [$sType, $sSub] = array_pad(explode('/', strtolower($sup), 2), 2, '*');

            foreach (self::$acceptValues as $acc) {
                [$aType, $aSub] = array_pad(explode('/', $acc['type'], 2), 2, '*');

                $matches =
                    ( $aType === '*' || $aType === $sType ) &&
                    ( $aSub === '*' || $aSub === $sSub );

                if (!$matches) {
                    continue;
                }
                if ($acc['q'] <= 0.0) {
                    continue;
                }

                $score = [$acc['q'], $acc['specificity'], $acc['paramBonus'], -$acc['index']];

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best      = $sup;
                }
            }
        }

        return $best;
    }

    /* ========================= Accept-Language ========================= */

    /** Parse Accept-Language entries. */
    public static function parseAcceptLanguage(string $header): array
    {
        $items = self::splitCommaSeparated($header);
        $out   = [];
        $i     = 0;

        foreach ($items as $raw) {
            [$tag, $params] = self::splitParameters($raw);
            if ($tag === '') {
                continue;
            }

            $tagNorm = strtolower(trim($tag)); // language tags are case-insensitive for matching
            $q       = self::parseQ($params['q'] ?? null);
            unset($params['q']);

            // Specificity: number of subtags (en-us → 2) ; '*' → 0
            $specificity = ( $tagNorm === '*' ) ? 0 : substr_count($tagNorm, '-') + 1;

            $out[] = [
                'raw'         => $raw,
                'tag'         => $tagNorm,
                'q'           => $q,
                'specificity' => $specificity,
                'index'       => $i++,
            ];
        }

        usort($out, function ($a, $b) {
            return [$b['q'], $b['specificity'], $a['index']]
                 <=> [$a['q'], $a['specificity'], $b['index']];
        });

        return $out;
    }

    /**
     * Pick best language tag from supported (e.g., ['it-IT','en-US','en']).
     * Matching rules:
     *  - Exact match wins over prefix match (en-US vs en).
     *  - Prefix match: requested 'en' matches 'en-US' and 'en' (more specific supported preferred).
     *  - '*' matches anything.
     */
    public static function pickLanguage(ServerRequestInterface $request, array $supported = [], ?string $fallback = null): ?string
    {
        $acceptLangHeader = $request->getHeaderLine('Accept-Language');
        if (empty($supported)) {
            $supportedLocales = LitLocale::$AllAvailableLocales;
        } else {
            $supportedLocales = array_values(array_unique(array_map('strtolower', $supported)));
        }

        self::$acceptValues         = self::parseAcceptLanguage($acceptLangHeader);
        self::$filteredAcceptValues = array_filter(self::$acceptValues, fn ($v) => in_array($v['tag'], $supportedLocales, true));

        if ($acceptLangHeader === '' || $acceptLangHeader === null) {
            return $fallback ?? $supportedLocales[0];
        }

        $best      = null;
        $bestScore = [-1, -1, -1]; // q, matchSpecificity, -index

        foreach ($supportedLocales as $sIdx => $sup) {
            foreach (self::$acceptValues as $acc) {
                if ($acc['q'] <= 0.0) {
                    continue;
                }

                $matchSpecificity = -1;

                if ($acc['tag'] === '*') {
                    $matchSpecificity = 0;
                } else {
                    // Exact?
                    if ($sup === $acc['tag']) {
                        $matchSpecificity = 100; // stronger than any prefix
                    } else {
                        // Prefix match: 'en' matches 'en-US' (supported more specific)
                        if (str_starts_with($sup, $acc['tag'] . '-')) {
                            // higher specificity if supported has more subtags
                            $matchSpecificity = substr_count($sup, '-'); // en-us-x → 2, etc.
                        }
                        // Or requested more specific than supported: 'en-US' vs 'en'
                        if ($matchSpecificity < 0 && str_starts_with($acc['tag'], $sup . '-')) {
                            $matchSpecificity = 1; // weak match (server more generic)
                        }
                    }
                }

                if ($matchSpecificity < 0) {
                    continue;
                }

                $score = [$acc['q'], $matchSpecificity, -$acc['index']];
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best      = $supported[$sIdx]; // preserve original casing
                }
            }
        }

        return $best;
    }

    /* ========================= Accept-Encoding ========================= */

    /** Parse Accept-Encoding entries. */
    public static function parseAcceptEncoding(string $header): array
    {
        $items = self::splitCommaSeparated($header);
        $out   = [];
        $i     = 0;

        foreach ($items as $raw) {
            [$token, $params] = self::splitParameters($raw);
            $token            = strtolower(trim($token));
            if ($token === '') {
                continue;
            }

            $q = self::parseQ($params['q'] ?? null);

            // Specificity: exact token > '*' ; identity handled specially in pick
            $specificity = ( $token === '*' ) ? 0 : 1;

            $out[] = [
                'raw'         => $raw,
                'token'       => $token,
                'q'           => $q,
                'specificity' => $specificity,
                'index'       => $i++,
            ];
        }

        usort($out, function ($a, $b) {
            return [$b['q'], $b['specificity'], $a['index']]
                 <=> [$a['q'], $a['specificity'], $b['index']];
        });

        return $out;
    }

    /**
     * Pick best encoding from supported (e.g., ['br','gzip','deflate','identity']).
     * RFC notes:
     *  - If header is absent: act as if "identity;q=1.0, *;q=0" (identity allowed).
     *  - If 'identity' not present, it's implicitly q=1 unless explicitly q=0.
     */
    public static function pickEncoding(ServerRequestInterface $request, array $supported = []): ?string
    {
        $acceptEncHeader = $request->getHeaderLine('Accept-Encoding');
        if (empty($supported)) {
            $supportedEncodings = ContentEncoding::values();
        } else {
            $supportedEncodings = array_values(array_unique(array_map('strtolower', $supported)));
        }

        // Defaults when header missing: identity preferred if available.
        if ($acceptEncHeader === '' || $acceptEncHeader === null) {
            // Prefer non-identity if you want compression by default; here we keep it literal:
            return in_array('identity', $supportedEncodings, true) ? 'identity' : $supportedEncodings[0];
        }

        self::$acceptValues         = self::parseAcceptEncoding($acceptEncHeader);
        self::$filteredAcceptValues = array_filter(self::$acceptValues, fn ($v) => in_array($v['token'], $supportedEncodings, true));

        // Determine implicit q for 'identity'
        $identityQ = 1.0;
        foreach (self::$acceptValues as $a) {
            if ($a['token'] === 'identity') {
                $identityQ = $a['q'];
                break;
            }
        }

        $best      = null;
        $bestScore = [-1, -1, -1]; // q, specificity, -index

        foreach ($supportedEncodings as $sup) {
            $bestForSup      = null;
            $bestForSupScore = [-1, -1, -PHP_INT_MAX];

            foreach (self::$acceptValues as $acc) {
                $q = $acc['q'];

                $matches = false;
                if ($acc['token'] === '*') {
                    $matches = true;
                } elseif ($acc['token'] === $sup) {
                    $matches = true;
                } elseif ($sup === 'identity' && $acc['token'] === 'identity') {
                    $matches = true;
                }

                if ($matches && $q > 0.0) {
                    $score = [$q, $acc['specificity'], -$acc['index']];
                    if ($score > $bestForSupScore) {
                        $bestForSupScore = $score;
                        $bestForSup      = $sup;
                    }
                }
            }

            // Handle implicit identity
            if ($bestForSup === null && $sup === 'identity' && $identityQ > 0.0) {
                $bestForSup      = 'identity';
                $bestForSupScore = [$identityQ, 1, 0];
            }

            if ($bestForSup !== null && $bestForSupScore > $bestScore) {
                $bestScore = $bestForSupScore;
                $best      = $bestForSup;
            }
        }

        return $best;
    }

    /**
     * Return the list of accepted values (without weights) from the parsed Accept header.
     *
     * @return string[] The list of accepted values.
     */
    public static function getAcceptValues(): array
    {
        return array_column(self::$acceptValues, 'type');
    }

    public static function getFilteredAcceptValues(): array
    {
        return array_values(self::$filteredAcceptValues);
        //return array_column(self::$filteredAcceptValues, 'type');
    }

    public static function isBrowserRequest(ServerRequestInterface $request): bool
    {
        $acceptHeader = $request->getHeaderLine('Accept');
        $userAgent    = $request->getHeaderLine('User-Agent');

        // Quick check for HTML preference in Accept
        if (strpos($acceptHeader, 'text/html') !== false) {
            return true;
        }

        // Optional: fallback to User-Agent check for common browsers
        $browsers = ['Mozilla', 'Chrome', 'Safari', 'Edge', 'Firefox', 'Opera'];
        foreach ($browsers as $name) {
            if (stripos($userAgent, $name) !== false) {
                return true;
            }
        }

        return false;
    }
}
