<?php

namespace LiturgicalCalendar\Api\Enum;

class JsonData
{
    /**
     * The base folder for JSON data.
     * Evaluates to 'jsondata'.
     */
    public const FOLDER = 'jsondata';

    /**
     * The folder containing schema files.
     * Evaluates to 'jsondata/schemas'.
     */
    public const SCHEMAS_FOLDER = JsonData::FOLDER . '/schemas';

    /**
     * The folder containing test files.
     * Evaluates to 'jsondata/tests'.
     */
    public const TESTS_FOLDER = JsonData::FOLDER . '/tests';

    /**
     * The folder containing source data.
     * Evaluates to 'jsondata/sourcedata'.
     */
    public const SOURCEDATA_FOLDER = JsonData::FOLDER . '/sourcedata';

    /**
     * The folder containing ecclesiastical decrees.
     * Evaluates to 'jsondata/sourcedata/decrees'.
     */
    public const DECREES_FOLDER = JsonData::SOURCEDATA_FOLDER . '/decrees';

    /**
     * The file containing the data with the Decrees of the Congregation for Divine Worship.
     * Evaluates to 'jsondata/sourcedata/decrees/decrees.json'.
     */
    public const DECREES_FILE = JsonData::DECREES_FOLDER . '/decrees.json';

    /**
     * The folder containing i18n files for ecclesiastical decrees.
     * Evaluates to 'jsondata/sourcedata/decrees/i18n'.
     */
    public const DECREES_I18N_FOLDER = JsonData::DECREES_FOLDER . '/i18n';

    /**
     * The file containing the i18n data for the decrees of the Congregation for Divine Worship,
     * with a placeholder for the locale.
     * Evaluates to 'jsondata/sourcedata/decrees/i18n/{locale}.json'.
     */
    public const DECREES_I18N_FILE = JsonData::DECREES_I18N_FOLDER . '/{locale}.json';

    /**
     * The folder containing missal resources.
     * Evaluates to 'jsondata/sourcedata/missals'.
     */
    public const MISSALS_FOLDER = JsonData::SOURCEDATA_FOLDER . '/missals';

    /**
     * The file containing the missal data, with a placeholder for the actual missal folder name.
     * Evaluates to 'jsondata/sourcedata/missals/{missal_folder}/{missal_folder}.json'.
     */
    public const MISSALS_FILE = JsonData::MISSALS_FOLDER . '/{missal_folder}/{missal_folder}.json';

    /**
     * The folder containing i18n files for missals, with a placeholder for the actual missal folder name.
     * Evaluates to 'jsondata/sourcedata/missals/{missal_folder}/i18n'.
     */
    public const MISSALS_I18N_FOLDER = JsonData::MISSALS_FOLDER . '/{missal_folder}/i18n';

    /**
     * The file containing the i18n data for the specified missal,
     * with placeholders for the actual missal folder name and locale.
     * Evaluates to 'jsondata/sourcedata/missals/{missal_folder}/i18n/{locale}.json'.
     */
    public const MISSALS_I18N_FILE = JsonData::MISSALS_I18N_FOLDER . '/{locale}.json';

    /**
     * The folder containing calendar data.
     * Evaluates to 'jsondata/sourcedata/calendars'.
     */
    public const CALENDARS_FOLDER = JsonData::SOURCEDATA_FOLDER . '/calendars';

    /**
     * The folder containing wider regions calendar data, with a placeholder for the actual region name.
     * Evaluates to 'jsondata/sourcedata/calendars/wider_regions'.
     */
    public const WIDER_REGIONS_FOLDER = JsonData::CALENDARS_FOLDER . '/wider_regions';

    /**
     * The file containing the Wider Region calendar data, with placeholders for the actual region name.
     * Evaluates to 'jsondata/sourcedata/calendars/wider_regions/{wider_region}/{wider_region}.json'.
     */
    public const WIDER_REGIONS_FILE = JsonData::WIDER_REGIONS_FOLDER . '/{wider_region}/{wider_region}.json';

    /**
     * The folder containing i18n files for wider regions, with a placeholder for the actual region name.
     * Evaluates to 'jsondata/sourcedata/calendars/wider_regions/{wider_region}/i18n'.
     */
    public const WIDER_REGIONS_I18N_FOLDER = JsonData::WIDER_REGIONS_FOLDER . '/{wider_region}/i18n';

    /**
     * The file containing the i18n data for the specified Wider Region calendar,
     * with placeholders for the actual region name and locale.
     * Evaluates to 'jsondata/sourcedata/calendars/wider_regions/{wider_region}/i18n/{locale}.json'.
     */
    public const WIDER_REGIONS_I18N_FILE = JsonData::WIDER_REGIONS_I18N_FOLDER . '/{locale}.json';

    /**
     * The folder containing national calendars, with a placeholder for the actual nation name.
     * Evaluates to 'jsondata/sourcedata/calendars/nations'.
     */
    public const NATIONAL_CALENDARS_FOLDER = JsonData::CALENDARS_FOLDER . '/nations';

    /**
     * The file containing the national calendar data, with placeholders for the actual nation name.
     * Evaluates to 'jsondata/sourcedata/calendars/nations/{nation}/{nation}.json'.
     */
    public const NATIONAL_CALENDARS_FILE = JsonData::NATIONAL_CALENDARS_FOLDER . '/{nation}/{nation}.json';

    /**
     * The folder containing i18n files for national calendars, with placeholders for the actual nation and calendar names.
     * Evaluates to 'jsondata/sourcedata/calendars/nations/{nation}/i18n'.
     */
    public const NATIONAL_CALENDARS_I18N_FOLDER = JsonData::NATIONAL_CALENDARS_FOLDER . '/{nation}/i18n';

    /**
     * The file containing the i18n data for the specified national calendar,
     * with placeholders for the actual nation name and locale.
     * Evaluates to 'jsondata/sourcedata/calendars/nations/{nation}/i18n/{locale}.json'.
     */
    public const NATIONAL_CALENDARS_I18N_FILE = JsonData::NATIONAL_CALENDARS_I18N_FOLDER . '/{locale}.json';

    /**
     * The folder containing diocesan calendars, with a placeholder for the actual nation and diocese names.
     * Evaluates to 'jsondata/sourcedata/calendars/dioceses'.
     */
    public const DIOCESAN_CALENDARS_FOLDER = JsonData::CALENDARS_FOLDER . '/dioceses';

    /**
     * The file containing the diocesan calendar data, with placeholders for the actual nation and diocese names.
     * Evaluates to 'jsondata/sourcedata/calendars/dioceses/{nation}/{diocese}/{diocese_name}.json'.
     */
    public const DIOCESAN_CALENDARS_FILE = JsonData::DIOCESAN_CALENDARS_FOLDER . '/{nation}/{diocese}/{diocese_name}.json';

    /**
     * The folder containing i18n files for diocesan calendars, with placeholders for the actual nation and diocese names.
     * Evaluates to 'jsondata/sourcedata/calendars/dioceses/{nation}/{diocese}/i18n'.
     */
    public const DIOCESAN_CALENDARS_I18N_FOLDER = JsonData::DIOCESAN_CALENDARS_FOLDER . '/{nation}/{diocese}/i18n';

    /**
     * The file containing the i18n data for the specified diocesan calendar,
     * with placeholders for the actual nation name, diocese name, and locale.
     * Evaluates to 'jsondata/sourcedata/calendars/dioceses/{nation}/{diocese}/i18n/{locale}.json'.
     */
    public const DIOCESAN_CALENDARS_I18N_FILE = JsonData::DIOCESAN_CALENDARS_I18N_FOLDER . '/{locale}.json';

    /**
     * The file containing the data for the world dioceses of the Latin Rite.
     * Evaluates to 'jsondata/world_dioceses.json'.
     */
    public const WORLD_DIOCESES_LATIN_RITE = JsonData::FOLDER . '/world_dioceses.json';
}
