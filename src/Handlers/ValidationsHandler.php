<?php

/**
 * Advertise the source data this API can validate.
 *
 * Clients used to hardcode this repo's on-disk layout and had to be edited in lockstep with every
 * change to it — see #806. They now read this list and send back an opaque id, so no filesystem
 * path crosses the wire.
 *
 * This endpoint deliberately does not touch the filesystem. Advertising is not verification:
 * `exists` is the first check, not a precondition for being listed, so a missing file surfaces as
 * a failed check rather than as a silent absence from the list.
 *
 * @author    John Romano D'Orazio <priest@johnromanodorazio.com>
 * @license   https://www.apache.org/licenses/LICENSE-2.0.txt Apache License 2.0
 * @link      https://litcal.johnromanodorazio.com
 */

namespace LiturgicalCalendar\Api\Handlers;

use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Models\ValidationsPath\CheckableInventory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ValidationsHandler extends AbstractHandler
{
    /** @param string[] $requestPathParams */
    public function __construct(array $requestPathParams = [])
    {
        parent::__construct($requestPathParams);

        // Read-only, JSON-only: the same restriction Router applies, held here too
        // so the handler is self-consistent when instantiated directly (as in tests).
        $this->allowedRequestMethods = [RequestMethod::GET];
        $this->allowedAcceptHeaders  = [AcceptHeader::JSON];
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $response = static::initResponse($request);

        $method = RequestMethod::from($request->getMethod());

        if ($method === RequestMethod::OPTIONS) {
            return $this->handlePreflightRequest($request, $response);
        }

        $response = $this->setAccessControlAllowOriginHeader($request, $response);
        $this->validateRequestMethod($request);

        $mime     = $this->validateAcceptHeader($request, AcceptabilityLevel::LAX);
        $response = $response->withHeader('Content-Type', $mime);

        $pathParamCount = count($this->requestPathParams);
        if ($pathParamCount > 0) {
            throw new ValidationException(
                'Invalid number of path parameters, expected 0, received ' . $pathParamCount
            );
        }

        $payload                     = new \stdClass();
        $payload->litcal_validations = CheckableInventory::all();

        return $this->encodeResponseBody($response, $payload);
    }
}
