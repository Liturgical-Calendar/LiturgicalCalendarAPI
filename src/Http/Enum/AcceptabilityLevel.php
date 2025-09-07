<?php

namespace LiturgicalCalendar\Api\Http\Enum;

enum AcceptabilityLevel
{
    /**
     * Browser navigation typically has the following `Accept` request header value: `Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*​/*;q=0.8`,
     * even if we are expecting to get a JSON response.
     *
     * To accomodate these scenarios, it is recommended to set the `AcceptabilityLevel` to `LAX` for a `Request` method of `HTTP GET`.
     *
     * The API endpoint will accept any `Accept` header value that is not among the values that it declares as acceptable,
     * and return a `Response` with the first permissible `Content-Type` (usually `application/json`).
     * If however the `Accept` header value in the `Request` explicitly states a value that the API endpoint declares as acceptable,
     * the API endpoint will produce a `Response` with a `Content-Type` that corresponds to the requested `Accept` header value.
     */
    case LAX;

    /**
     * A number of command line tools such as `curl` and `wget` or other HTTP clients
     * will include an Accept header of `*​/*` in their requests when not explicitly set.
     *
     * To accomodate these scenarios, it is recommended to set the `AcceptabilityLevel` to `INTERMEDIATE`
     * for `Request` methods of HTTP `POST`, `PUT`, `PATCH`, and `DELETE`.
     *
     * If no `Accept` header is included in the `Request` or if the `Accept` header value is `*​/*`,
     * the API endpoint will return a `Response` with the first permissible `Content-Type` (`usually application/json`).
     */
    case INTERMEDIATE;

    /**
     * A `Request` MUST explicitly state an `Accept` header value that is among the values that the API endpoint declares as acceptable.
     *
     * For any `Request` that does not include an `Accept` header, or includes an `Accept` header with a value that the endpoint has not declared as acceptable,
     * the endpoint will reject the `Request` as non-acceptable.
     *
     * While an `AcceptabilityLevel` of `STRICT` would enforce clarity in any `Request` by explicitly requiring an acceptable `Accept` header value,
     * this could however produce non-expected results, and is therefore not recommended.
     */
    case STRICT;

    /**
     * Will default to `LAX` for `HTTP Request` methods of `GET`,
     * and to `INTERMEDIATE` for `HTTP Request` methods of `POST`, `PUT`, `PATCH`, and `DELETE`.
     */
    case DEFAULT;
}
