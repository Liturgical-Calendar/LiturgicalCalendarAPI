<?php

namespace LiturgicalCalendar\Api\Params;

use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\RomanMissal;
use LiturgicalCalendar\Api\Handlers\MissalsHandler;
use LiturgicalCalendar\Api\Http\Enum\StatusCode;
use LiturgicalCalendar\Api\Models\MissalsPath\MissalMetadata;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Class MissalsParams
 *
 * This class handles the parameters for the Missals API endpoint.
 * It validates and sets the locale, year, region, and whether to include empty entries.
 *
 * @package LiturgicalCalendar\Api\Params
 */
class MissalsParamsHandler implements RequestHandlerInterface
{
    public ResponseInterface $response;

    /**
     * @var array{locale?:string,year?:int,region?:string,include_empty?:bool,payload?:\stdClass}
     */
    private array $params;

    public bool $IncludeEmpty  = false;
    public ?string $Region     = null;
    public ?int $Year          = null;
    public ?string $Locale     = null;
    public ?string $baseLocale = null;
    public \stdClass $Payload;

    /**
     * Initializes the MissalsParams class.
     *
     * Calls the setParams method to set the parameters provided in the $params array, in any.
     *
     * @param array{
     *      locale?: string,
     *      year?: int,
     *      region?: string,
     *      include_empty?: bool,
     *      payload?: \stdClass
     * } $params an associative array of parameter keys to values
     */
    public function __construct(ResponseInterface $response, array $params = [])
    {
        $this->response = $response;
        $this->params   = $params;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (count($this->params) === 0) {
            // If no parameters are provided, we can just return
            return $this->response;
        }

        foreach ($this->params as $key => $value) {
            switch ($key) {
                case 'locale':
                    $value = \Locale::canonicalize($value);
                    if (null === $value) {
                        $description = "Invalid locale `{$value}`";
                        return $this->response
                            ->withStatus(StatusCode::BAD_REQUEST->value, StatusCode::BAD_REQUEST->reason())
                            ->withBody(Stream::create($description));
                    }

                    if (LitLocale::isValid($value)) {
                        $this->Locale     = $value;
                        $this->baseLocale = \Locale::getPrimaryLanguage($value);
                    } else {
                        $description = "Locale `$value` set in param `locale` is not supported by this server, supported locales are: la, la_VA, "
                            . implode(', ', LitLocale::$AllAvailableLocales);
                        return $this->response
                            ->withStatus(StatusCode::BAD_REQUEST->value, StatusCode::BAD_REQUEST->reason())
                            ->withBody(Stream::create($description));
                    }

                    if (count(MissalsHandler::$availableLangs) && false === in_array($this->baseLocale, MissalsHandler::$availableLangs)) {
                        $description = "Locale `$value` ({$this->baseLocale}) set in param `locale` is not a valid locale for the requested Missal, valid locales are: "
                                . implode(', ', MissalsHandler::$availableLangs);
                        return $this->response
                            ->withStatus(StatusCode::BAD_REQUEST->value, StatusCode::BAD_REQUEST->reason())
                            ->withBody(Stream::create($description));
                    }
                    break;
                case 'year':
                    if (gettype($value) === 'string') {
                        $value = intval($value);
                    }
                    if (in_array($value, MissalsHandler::$MissalYears)) {
                        $this->Year = $value;
                    } else {
                        $description = "Invalid value `$value` for param `year`, valid values are: "
                            . implode(', ', MissalsHandler::$MissalYears);
                        return $this->response
                            ->withStatus(StatusCode::BAD_REQUEST->value, StatusCode::BAD_REQUEST->reason())
                            ->withBody(Stream::create($description));
                    }
                    break;
                case 'region':
                    if (in_array($value, MissalsHandler::$MissalRegions)) {
                        $this->Region = $value;
                    } else {
                        $description = "Invalid value `$value` for param `region`, valid values are: "
                            . implode(', ', MissalsHandler::$MissalRegions);
                        return $this->response
                            ->withStatus(StatusCode::BAD_REQUEST->value, StatusCode::BAD_REQUEST->reason())
                            ->withBody(Stream::create($description));
                    }
                    break;
                case 'include_empty':
                    $boolVal = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if (null === $boolVal) {
                        $description = "Invalid value `$value` for param `include_empty`, valid values are boolean `true` and `false`";
                        return $this->response
                            ->withStatus(StatusCode::BAD_REQUEST->value, StatusCode::BAD_REQUEST->reason())
                            ->withBody(Stream::create($description));
                    }
                    $this->IncludeEmpty = $boolVal;

                    // If an explicit request is made to include all Missals defined in the RomanMissal enum,
                    // even if there is no data for them in the JsonData::MISSALS_FOLDER directory,
                    // we add them to the response.
                    if ($this->IncludeEmpty) {
                        /** @var array<string,MissalMetadata> */
                        $allMissals = RomanMissal::produceMetadata(true);
                        foreach ($allMissals as $missal) {
                            if (false === MissalsHandler::$missalsIndex->hasMissal($missal->missal_id)) {
                                MissalsHandler::$missalsIndex->addMissal($missal);
                                MissalsHandler::addMissalRegion($missal->region);
                                MissalsHandler::addMissalYear($missal->year_published);
                            }
                        }
                    }
                    break;
                case 'payload':
                    $this->Payload = $value;
                    break;
                default:
                    // do nothing
            }
        }

        // If all parameters have been set correctly, we return the response in its current status
        return $this->response;
    }
}
