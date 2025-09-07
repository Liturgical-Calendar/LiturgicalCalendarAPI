<?php

namespace LiturgicalCalendar\Api\Models\Calendar;

use LiturgicalCalendar\Api\Enum\LitCommon;

final class LitCommonItem implements \JsonSerializable
{
    public LitCommon $commonGeneral;
    public ?LitCommon $commonSpecific;

    public function __construct(LitCommon $commonGeneral, ?LitCommon $commonSpecific = null)
    {
        if (
            $commonGeneral !== LitCommon::NONE
            && false === in_array($commonGeneral, LitCommon::COMMUNES_GENERALIS)
        ) {
            throw new \InvalidArgumentException('Invalid General Common: ' . $commonGeneral->value . '. Must be one of: ' . implode(', ', array_column(LitCommon::COMMUNES_GENERALIS, 'value')));
        }

        $this->commonGeneral = $commonGeneral;

        if (null !== $commonSpecific) {
            switch ($commonGeneral) {
                case LitCommon::MARTYRUM:
                    if (false === in_array($commonSpecific, LitCommon::COMMUNE_MARTYRUM)) {
                        $validSpecificCommons = array_column(LitCommon::COMMUNE_MARTYRUM, 'value');

                        $errMessage = 'Invalid Specific Common: ' . $commonSpecific->value . '. Must be one of: ' . implode(', ', $validSpecificCommons);
                        throw new \InvalidArgumentException($errMessage);
                    }
                    break;
                case LitCommon::PASTORUM:
                    if (false === in_array($commonSpecific, LitCommon::COMMUNE_PASTORUM)) {
                        $validSpecificCommons = array_column(LitCommon::COMMUNE_PASTORUM, 'value');

                        $errMessage = 'Invalid Specific Common: ' . $commonSpecific->value . '. Must be one of: ' . implode(', ', $validSpecificCommons);
                        throw new \InvalidArgumentException($errMessage);
                    }
                    break;
                case LitCommon::VIRGINUM:
                    if (false === in_array($commonSpecific, LitCommon::COMMUNE_VIRGINUM)) {
                        $validSpecificCommons = array_column(LitCommon::COMMUNE_VIRGINUM, 'value');

                        $errMessage = 'Invalid Specific Common: ' . $commonSpecific->value . '. Must be one of: ' . implode(', ', $validSpecificCommons);
                        throw new \InvalidArgumentException($errMessage);
                    }
                    break;
                case LitCommon::SANCTORUM_ET_SANCTARUM:
                    if (false === in_array($commonSpecific, LitCommon::COMMUNE_SANCTORUM)) {
                        $validSpecificCommons = array_column(LitCommon::COMMUNE_SANCTORUM, 'value');

                        $errMessage = 'Invalid Specific Common: ' . $commonSpecific->value . '. Must be one of: ' . implode(', ', $validSpecificCommons);
                        throw new \InvalidArgumentException($errMessage);
                    }
                    break;
                default:
                    $errMessage = 'Specific Common not allowed for General Common: ' . $commonGeneral->value;
                    throw new \InvalidArgumentException($errMessage);
            }
        }

        $this->commonSpecific = $commonSpecific;
    }

    /**
     * Returns a string representation of the LitCommonItem object suitable for JSON serialization.
     *
     * The string will be in the format "GeneralCommon[:SpecificCommon]".
     *
     * @return string
     */
    public function jsonSerialize(): string
    {
        return $this->commonGeneral->value . ( $this->commonSpecific !== null ? ':' . $this->commonSpecific->value : '' );
    }
}
