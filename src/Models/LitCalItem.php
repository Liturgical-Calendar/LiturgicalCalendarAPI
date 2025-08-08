<?php

namespace LiturgicalCalendar\Api\Models;

use LiturgicalCalendar\Api\Enum\CalEventAction;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemCreateNewFixed;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemCreateNewMetadata;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemCreateNewMobile;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemMakePatron;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemMakePatronMetadata;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemMoveEvent;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemMoveEventMetadata;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemSetPropertyGrade;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemSetPropertyGradeMetadata;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemSetPropertyName;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemSetPropertyNameMetadata;

final class LitCalItem extends AbstractJsonSrcData
{
    public readonly LiturgicalEventData $liturgical_event;
    public readonly LiturgicalEventMetadata $metadata;

    private function __construct(\stdClass $liturgical_event, \stdClass $metadata)
    {
        // Cases in which we would need a `name` property: createNew, makePatron, and setProperty.name
        // We no longer use the `name` property, because we have translated strings in the i18n data
        // We should however check that the i18n data does actually exist for the litcalItem.event_key,
        // and it's simply easier to check that here rather than continue passing down the i18nData to each subclass
        if (
            in_array($metadata->action, ['createNew', 'makePatron'])
            ||
            ( $metadata->action === CalEventAction::SetProperty->value && $metadata->property === 'name' )
        ) {
            if (false === property_exists($liturgical_event, 'event_key')) {
                throw new \ValueError('litcalItem.liturgical_event must have an `event_key` property');
            }
        }

        switch ($metadata->action) {
            case CalEventAction::MoveEvent->value:
                $this->liturgical_event = LitCalItemMoveEvent::fromObject($liturgical_event);
                $this->metadata         = LitCalItemMoveEventMetadata::fromObject($metadata);
                break;
            case CalEventAction::CreateNew->value:
                if (property_exists($liturgical_event, 'day') && property_exists($liturgical_event, 'month')) {
                    $this->liturgical_event = LitCalItemCreateNewFixed::fromObject($liturgical_event);
                } elseif (property_exists($liturgical_event, 'strtotime')) {
                    $this->liturgical_event = LitCalItemCreateNewMobile::fromObject($liturgical_event);
                } else {
                    throw new \ValueError('when metadata.action is `createNew`, `liturgical_event` must have either `day` and `month` properties or `strtotime` property');
                }
                $this->metadata = LitCalItemCreateNewMetadata::fromObject($metadata);
                break;
            case CalEventAction::SetProperty->value:
                if (false === property_exists($metadata, 'property')) {
                    throw new \ValueError('when metadata.action is `setProperty`, the metadata `property` property must also be set');
                }
                switch ($metadata->property) {
                    case 'name':
                        $this->liturgical_event = LitCalItemSetPropertyName::fromObject($liturgical_event);
                        $this->metadata         = LitCalItemSetPropertyNameMetadata::fromObject($metadata);
                        break;
                    case 'grade':
                        $this->liturgical_event = LitCalItemSetPropertyGrade::fromObject($liturgical_event);
                        $this->metadata         = LitCalItemSetPropertyGradeMetadata::fromObject($metadata);
                        break;
                    default:
                        throw new \ValueError('when metadata.action is `setProperty`, the metadata `property` property must be either `name` or `grade`');
                }
                break;
            case CalEventAction::MakePatron->value:
                $this->liturgical_event = LitCalItemMakePatron::fromObject($liturgical_event);
                $this->metadata         = LitCalItemMakePatronMetadata::fromObject($metadata);
                break;
            default:
                throw new \ValueError('metadata.action must be one of `moveEvent`, `createNew`, `setProperty` or `makePatron`');
        }
    }

    /**
     * Creates a new instance of LitCalItem from an associative array.
     *
     * The associative array must have the following keys:
     * - `liturgical_event`: The data for the liturgical event.
     * - `metadata`: The metadata for the liturgical event.
     *
     * The `metadata` key must have an `action` key with a valid value of one of the following:
     * - `moveEvent`
     * - `createNew`
     * - `setProperty`
     * - `makePatron`
     *
     * @param \stdClass&object{liturgical_event:\stdClass&object{event_key:string,name:string,grade:int,color:string[],common:string[],day?:int,month?:int,strtotime?:string},metadata:\stdClass&object{action:string,since_year:int|null,until_year?:int|null,url?:string|null,reason?:string|null,property?:string|null,url_lang_map?:\stdClass&object<string,string>}} $data The associative array containing the data for the liturgical event.
     * @return static A new instance of LitCalItem.
     * @throws \ValueError If the required properties are not present in the associative array or if the properties have invalid types.
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        if (false === property_exists($data, 'liturgical_event') || false === property_exists($data, 'metadata')) {
            throw new \ValueError('`liturgical_event` and `metadata` parameters are required');
        }

        if (false === property_exists($data->metadata, 'action')) {
            throw new \ValueError('metadata must have an `action` property');
        }

        return new static($data->liturgical_event, $data->metadata);
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
        if (false === array_key_exists('liturgical_event', $data) || false === array_key_exists('metadata', $data)) {
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
        $liturgicalEvent = json_decode($liturgicalEvent, false, 512, JSON_THROW_ON_ERROR);
        /** @var \stdClass */
        $metadata = json_decode($metadata, false, 512, JSON_THROW_ON_ERROR);
        return new static($liturgicalEvent, $metadata);
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
