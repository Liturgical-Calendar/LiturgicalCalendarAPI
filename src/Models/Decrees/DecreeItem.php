<?php

namespace LiturgicalCalendar\Api\Models\Decrees;

use LiturgicalCalendar\Api\Enum\CalEventAction;
use LiturgicalCalendar\Api\Enum\Route;
use LiturgicalCalendar\Api\Models\AbstractJsonSrcData;
use LiturgicalCalendar\Api\Router;

/**
 * @phpstan-type DecreeItemLiturgicalEventObject \stdClass&object{
 *      event_key:string,
 *      name:string,
 *      calendar:string,
 *      grade?:int,
 *      color?:string[],
 *      common?:string[],
 *      day?:int,
 *      month?:int,
 *      strtotime?:string
 * }
 * @phpstan-type UrlLangMapObject \stdClass&object<string,string>
 * @phpstan-type DecreeItemMetadataObject \stdClass&object{
 *      action:string,
 *      since_year:int,
 *      until_year?:int,
 *      url:string,
 *      reason?:string,
 *      property?:string,
 *      url_lang_map?:UrlLangMapObject
 * }
 * @phpstan-type DecreeItemFromObject \stdClass&object{
 *      decree_id:string,
 *      decree_date:string,
 *      decree_protocol:string,
 *      description:string,
 *      liturgical_event:DecreeItemLiturgicalEventObject,
 *      metadata:DecreeItemMetadataObject
 * }
 *
 * @phpstan-type DecreeItemLiturgicalEventArray array{
 *      event_key:string,
 *      name:string,
 *      calendar:string,
 *      grade?:int,
 *      color?:string[],
 *      common?:string[],
 *      day?:int,
 *      month?:int,
 *      strtotime?:string
 * }
 * @phpstan-type DecreeItemMetadataArray array{
 *      action:string,
 *      since_year:int,
 *      until_year?:int,
 *      url:string,
 *      reason?:string,
 *      property?:string,
 *      url_lang_map?:array<string,string>
 * }
 * @phpstan-type DecreeItemFromArray array{
 *      decree_id:string,
 *      decree_date:string,
 *      decree_protocol:string,
 *      description:string,
 *      liturgical_event:DecreeItemLiturgicalEventArray,
 *      metadata:DecreeItemMetadataArray
 * }
 */
final class DecreeItem extends AbstractJsonSrcData
{
    public readonly string $decree_id;
    public readonly string $decree_date;
    public readonly string $decree_protocol;
    public readonly string $description;
    public readonly DecreeEventData $liturgical_event;
    public readonly DecreeEventMetadata $metadata;
    public readonly string $api_path;

    private function __construct(
        string $decree_id,
        string $decree_date,
        string $decree_protocol,
        string $description,
        \stdClass $liturgical_event,
        \stdClass $metadata
    ) {

        $this->decree_id       = $decree_id;
        $this->decree_date     = $decree_date;
        $this->decree_protocol = $decree_protocol;
        $this->description     = $description;

        // Cases in which we would need a `name` property: createNew, makeDoctor, and setProperty.name
        // We no longer use the `name` property, because we have translated strings in the i18n data
        // We should however check that the i18n data does actually exist for the litcalItem.event_key,
        // and it's simply easier to check that here rather than continue passing down the i18nData to each subclass
        if (
            in_array($metadata->action, ['createNew', 'makeDoctor'])
            ||
            ( $metadata->action === CalEventAction::SetProperty->value && $metadata->property === 'name' )
        ) {
            if (false === property_exists($liturgical_event, 'name')) {
                throw new \ValueError('decreeItem.liturgical_event must have a `name` property. Did you call DecreeItemCollection::setNames() before creating the collection?');
            }
        }

        switch ($metadata->action) {
            /*
            case CalEventAction::MoveEvent->value:
                $this->liturgical_event = DecreeItemMoveEvent::fromObject($liturgical_event);
                $this->metadata         = DecreeItemMoveEventMetadata::fromObject($metadata);
                break;
            */
            case CalEventAction::CreateNew->value:
                if (property_exists($liturgical_event, 'day') && property_exists($liturgical_event, 'month')) {
                    $this->liturgical_event = DecreeItemCreateNewFixed::fromObject($liturgical_event);
                } elseif (property_exists($liturgical_event, 'strtotime')) {
                    $this->liturgical_event = DecreeItemCreateNewMobile::fromObject($liturgical_event);
                } else {
                    throw new \ValueError('when metadata.action is `createNew`, `liturgical_event` must have either `day` and `month` properties or `strtotime` property');
                }
                $this->metadata = DecreeItemCreateNewMetadata::fromObject($metadata);
                break;
            case CalEventAction::SetProperty->value:
                if (false === property_exists($metadata, 'property')) {
                    throw new \ValueError('when metadata.action is `setProperty`, the metadata `property` property must also be set');
                }
                switch ($metadata->property) {
                    case 'name':
                        $this->liturgical_event = DecreeItemSetPropertyName::fromObject($liturgical_event);
                        $this->metadata         = DecreeItemSetPropertyNameMetadata::fromObject($metadata);
                        break;
                    case 'grade':
                        $this->liturgical_event = DecreeItemSetPropertyGrade::fromObject($liturgical_event);
                        $this->metadata         = DecreeItemSetPropertyGradeMetadata::fromObject($metadata);
                        break;
                    default:
                        throw new \ValueError('when metadata.action is `setProperty`, the metadata `property` property must be either `name` or `grade`');
                }
                break;
            case CalEventAction::MakeDoctor->value:
                $this->liturgical_event = DecreeItemMakeDoctor::fromObject($liturgical_event);
                $this->metadata         = DecreeItemMakeDoctorMetadata::fromObject($metadata);
                break;
            default:
                throw new \ValueError('metadata.action must be one of `createNew`, `setProperty` or `makeDoctor`'); //`moveEvent`,
        }

        $this->api_path = Router::$apiPath . Route::DECREES->value . '/' . $this->decree_id;
    }

    /**
     * Creates a new instance of DecreeItem from a decree object or an array of decree objects.
     *
     * The object or objects must have the following keys:
     * - `liturgical_event`: The data for the liturgical event.
     * - `metadata`: The metadata for the liturgical event.
     *
     * The `metadata` key must have an `action` key with a valid value of one of the following:
     * - `moveEvent`
     * - `createNew`
     * - `setProperty`
     * - `makePatron`
     *
     * @param DecreeItemFromObject $data
     * @return static A new instance of DecreeItem.
     * @throws \ValueError If the required properties are not present in the associative array or if the properties have invalid types.
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        if (
            false === property_exists($data, 'decree_id')
            || false === property_exists($data, 'decree_date')
            || false === property_exists($data, 'decree_protocol')
            || false === property_exists($data, 'description')
            || false === property_exists($data, 'liturgical_event')
            || false === property_exists($data, 'metadata')
        ) {
            throw new \ValueError('required parameters are missing');
        }

        if (false === property_exists($data->metadata, 'action')) {
            throw new \ValueError('metadata must have an `action` property');
        }

        return new static(
            $data->decree_id,
            $data->decree_date,
            $data->decree_protocol,
            $data->description,
            $data->liturgical_event,
            $data->metadata
        );
    }

    /**
     * Creates a new instance from an array.
     *
     * @param DecreeItemFromArray $data The data to use to create the new instance.
     *                      Must have the following keys:
     *                          - `decree_id`: The ID of the decree.
     *                          - `decree_date`: The date of the decree.
     *                          - `decree_protocol`: The protocol of the decree.
     *                          - `description`: The description of the decree.
     *                          - `liturgical_event`: The liturgical event data. Must have the following keys:
     *                              -> `event_key`: The event key.
     *                              -> `name`: The name of the liturgical event.
     *                              -> `grade`: The grade of the liturgical event.
     *                              -> `color`: The color of the liturgical event.
     *                              -> `common`: The common of the liturgical event.
     *                              May have either `day` and `month`, or `strtotime`.
     *                          - `metadata`: The metadata for the liturgical event. Must have the following keys:
     *                                  -> `action`: The action to take for the liturgical event.
     *                                  -> `since_year`: The year since when the liturgical event was added.
     *                              May have the following keys:
     *                                  -> `url_lang_map`: The URL of the document that introduces the liturgical event for each language.
     *                                  -> `property`: The property to set for the liturgical event.
     *                                  -> `until_year`: The year until when the liturgical event was added.
     *                                  -> `url`: The URL of the document that introduces the liturgical event.
     *                                  -> `reason`: The reason why the liturgical event was introduced.
     * @return static A new instance.
     */
    protected static function fromArrayInternal(array $data): static
    {
        if (
            false === isset($data['decree_id'])
            || false === isset($data['decree_date'])
            || false === isset($data['decree_protocol'])
            || false === isset($data['description'])
            || false === isset($data['liturgical_event'])
            || false === isset($data['metadata'])
        ) {
            throw new \ValueError('`liturgical_event` and `metadata` parameters are required');
        }

        if (false === isset($data['metadata']['action'])) {
            throw new \ValueError('metadata must have an `action` property');
        }

        $liturgicalEvent = json_encode($data['liturgical_event'], JSON_THROW_ON_ERROR);
        $metadata        = json_encode($data['metadata'], JSON_THROW_ON_ERROR);

        /** @var \stdClass */
        $liturgicalEvent = json_decode($liturgicalEvent, false, 512, JSON_THROW_ON_ERROR);

        /** @var \stdClass */
        $metadata = json_decode($metadata, false, 512, JSON_THROW_ON_ERROR);
        return new static(
            $data['decree_id'],
            $data['decree_date'],
            $data['decree_protocol'],
            $data['description'],
            $liturgicalEvent,
            $metadata
        );
    }

    /**
     * Set the name of the liturgical event.
     *
     * @param string $name The new name for the liturgical event.
     */
    public function setName(string $name): void
    {
        $this->unlock();
        $this->liturgical_event->name = $name;
        $this->lock();
    }

    /**
     * Return the event_key of the liturgical event.
     *
     * @return string The event_key of the liturgical event.
     */
    public function getEventKey(): string
    {
        return $this->liturgical_event->event_key;
    }
}
