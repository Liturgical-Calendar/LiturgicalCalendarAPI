<?php 

namespace LiturgicalCalendar\Api\Enum;

class JsonData
{
    public const FOLDER       = 'jsondata';

    public const SCHEMAS_FOLDER      = JsonData::FOLDER . '/schemas';
    public const TESTS_FOLDER        = JsonData::FOLDER . '/tests';
    public const SOURCEDATA_FOLDER   = JsonData::FOLDER . '/sourcedata';

    public const DECREES_FOLDER      = JsonData::SOURCEDATA_FOLDER . '/decrees';
    public const DECREES_I18N_FOLDER = JsonData::DECREES_FOLDER . '/i18n';

    public const MISSALS_FOLDER      = JsonData::SOURCEDATA_FOLDER . '/missals';
    public const MISSALS_I18N_FOLDER = JsonData::MISSALS_FOLDER . '/{missal_folder}/i18n'; //can use strtr to replace {missal_folder} with actual folder name

    public const CALENDARS_FOLDER    = JsonData::SOURCEDATA_FOLDER . '/calendars';
    
    public const WIDER_REGIONS_FOLDER           = JsonData::CALENDARS_FOLDER . '/wider_regions';
    public const WIDER_REGIONS_I18N_FOLDER      = JsonData::WIDER_REGIONS_FOLDER . '/{wider_region}/i18n'; // can use strtr to replace {wider_region} with actual folder name

    public const NATIONAL_CALENDARS_FOLDER      = JsonData::CALENDARS_FOLDER . '/nations';
    public const NATIONAL_CALENDARS_I18N_FOLDER = JsonData::NATIONAL_CALENDARS_FOLDER . '/{nation}/i18n'; 

    public const DIOCESAN_CALENDARS_FOLDER      = JsonData::CALENDARS_FOLDER . '/dioceses';
    public const DIOCESAN_CALENDARS_I18N_FOLDER = JsonData::DIOCESAN_CALENDARS_FOLDER . '/{nation}/{diocese}/i18n'; // can use strtr to replace {nation} and {diocese} with actual folder names
}