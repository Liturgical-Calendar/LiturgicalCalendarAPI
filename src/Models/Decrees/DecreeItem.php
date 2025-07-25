<?php

namespace LiturgicalCalendar\Api\Models\Decrees;

use LiturgicalCalendar\Api\Enum\CalEventAction;
use LiturgicalCalendar\Api\Models\AbstractJsonSrcData;

final class DecreeItem extends AbstractJsonSrcData
{
    public readonly string $decree_id;
    public readonly string $decree_date;
    public readonly string $decree_protocol;
    public readonly string $description;
    public readonly DecreeEventData $liturgical_event;
    public readonly DecreeEventMetadata $metadata;

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
    }

    /**
     * Creates a new instance of DecreeItem from a decree object or an array of decree objects.
     *
     * The object or objects must have the following keys:
     * - `liturgical_event`: The data for the liturgical event.
     * - `metadata`: The metadata for the liturgical event.
     *
     * The `metadata` key must have an `action` key with a valid value of one of the following:
     * - `moveFestivity`
     * - `createNew`
     * - `setProperty`
     * - `makePatron`
     *
     * @param \stdClass $data
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
     * @param array{liturgical_event:array{event_key:string,name:string,grade:int,color:string[],common:string[],day?:int,month?:int,strtotime?:string},metadata:array{action:string,since_year:int|null,until_year?:int|null,url?:string|null,reason?:string|null,property?:string|null,url_lang_map?:array<string,string>}} $data The data to use to create the new instance.
     *                      Must have the following keys:
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
            false === array_key_exists('decree_id', $data)
            || false === array_key_exists('decree_date', $data)
            || false === array_key_exists('decree_protocol', $data)
            || false === array_key_exists('description', $data)
            || false === array_key_exists('liturgical_event', $data)
            || false === array_key_exists('metadata', $data)
        ) {
            throw new \ValueError('`liturgical_event` and `metadata` parameters are required');
        }

        if (false === array_key_exists('action', $data['metadata'])) {
            throw new \ValueError('metadata must have an `action` property');
        }

        $liturgicalEvent = json_encode($data['liturgical_event']);
        $metadata        = json_encode($data['metadata']);
        if (false === $liturgicalEvent || false === $metadata) {
            throw new \ValueError('`liturgical_event` or `metadata` parameter could not be re-encoded to JSON');
        }

        /** @var \stdClass */
        $liturgicalEvent = json_decode($liturgicalEvent);
        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new \ValueError('`liturgical_event` parameter could not be re-encoded to JSON: ' . json_last_error_msg());
        }

        /** @var \stdClass */
        $metadata = json_decode($metadata);
        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new \ValueError('`metadata` parameter could not be re-encoded to JSON: ' . json_last_error_msg());
        }
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
