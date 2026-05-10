<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Http\Middleware;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Middleware to parse JSON request bodies into getParsedBody().
 *
 * PSR-7's getParsedBody() only returns data for form-encoded bodies
 * (populated by PHP's $_POST). This middleware parses JSON bodies
 * and sets them via withParsedBody() so all downstream handlers
 * can use getParsedBody() consistently.
 */
final class JsonBodyParserMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $contentType = $request->getHeaderLine('Content-Type');

        if (
            $request->getParsedBody() === null
            && stripos($contentType, 'application/json') !== false
        ) {
            $body    = $request->getBody();
            $rawBody = (string) $body;
            // Rewind so downstream handlers reading via getBody()->getContents()
            // (e.g., AbstractHandler::parseBodyParams) still see the body.
            if ($body->isSeekable()) {
                $body->rewind();
            }
            if ($rawBody !== '') {
                $decoded = json_decode($rawBody, true);
                if (is_array($decoded)) {
                    $request = $request->withParsedBody($decoded);
                }
            }
        }

        return $handler->handle($request);
    }
}
