<?php

namespace LiturgicalCalendar\Api\Models\RegionalData;

use LiturgicalCalendar\Api\Enum\CalEventAction;
use LiturgicalCalendar\Api\Models\AbstractJsonSrcData;
use LiturgicalCalendar\Api\Models\RegionalData\LiturgicalEventData;
use LiturgicalCalendar\Api\Models\RegionalData\LiturgicalEventMetadata;
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

    public function __construct(\stdClass $liturgical_event, \stdClass $metadata)
    {
        // Cases in which we would need a `name` property: createNew, makePatron, and setProperty.name
        // We no longer use the `name` property, because we have translated strings in the i18n data
        // We should however check that the i18n data does actually exist for the litcalItem.event_key,
        // and it's simply easier to check that here rather than continue passing down the i18nData to each subclass
        if (
            in_array($metadata->action, ['createNew', 'makePatron'])
            ||
            ($metadata->action === CalEventAction::SetProperty->value && $metadata->property === 'name')
        ) {
            if (false === property_exists($liturgical_event, 'event_key')) {
                throw new \ValueError('litcalItem.liturgical_event must have an `event_key` property');
            }
        }

        switch ($metadata->action) {
            case CalEventAction::MoveEvent->value:
                $this->liturgical_event = LitCalItemMoveEvent::fromObject($liturgical_event);
                $this->metadata         = new LitCalItemMoveEventMetadata($metadata);
                break;
            case CalEventAction::CreateNew->value:
                if (property_exists($liturgical_event, 'day') && property_exists($liturgical_event, 'month')) {
                    $this->liturgical_event = LitCalItemCreateNewFixed::fromObject($liturgical_event);
                } elseif (property_exists($liturgical_event, 'strtotime')) {
                    $this->liturgical_event = LitCalItemCreateNewMobile::fromObject($liturgical_event);
                } else {
                    throw new \ValueError('when metadata.action is `createNew`, `liturgical_event` must have either `day` and `month` properties or `strtotime` property');
                }
                $this->metadata = new LitCalItemCreateNewMetadata($metadata->since_year, $metadata->until_year ?? null);
                break;
            case CalEventAction::SetProperty->value:
                if (false === property_exists($metadata, 'property')) {
                    throw new \ValueError('when metadata.action is `setProperty`, the metadata `property` property must also be set');
                }
                switch ($metadata->property) {
                    case 'name':
                        $this->liturgical_event = LitCalItemSetPropertyName::fromObject($liturgical_event);
                        $this->metadata         = new LitCalItemSetPropertyNameMetadata($metadata);
                        break;
                    case 'grade':
                        $this->liturgical_event = LitCalItemSetPropertyGrade::fromObject($liturgical_event);
                        $this->metadata         = new LitCalItemSetPropertyGradeMetadata($metadata);
                        break;
                    default:
                        throw new \ValueError('when metadata.action is `setProperty`, the metadata `property` property must be either `name` or `grade`');
                }
                break;
            case CalEventAction::MakePatron->value:
                $this->liturgical_event = LitCalItemMakePatron::fromObject($liturgical_event);
                $this->metadata         = new LitCalItemMakePatronMetadata($metadata);
                break;
            default:
                throw new \ValueError('metadata.action must be one of `moveFestivity`, `createNew`, `setProperty` or `makePatron`');
        }
    }

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

    protected static function fromArrayInternal(array $data): static
    {
        if (false === array_key_exists('liturgical_event', $data) || false === array_key_exists('metadata', $data)) {
            throw new \ValueError('`liturgical_event` and `metadata` parameters are required');
        }

        if (false === array_key_exists('action', $data['metadata'])) {
            throw new \ValueError('metadata must have an `action` property');
        }
        $liturgicalEvent = json_decode(json_encode($data['liturgical_event']));
        $metadata        = json_decode(json_encode($data['metadata']));
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
