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
    public const MISSAL_FILE = JsonData::MISSALS_FOLDER . '/{missal_folder}/{missal_folder}.json';

    /**
     * The folder containing i18n files for missals, with a placeholder for the actual missal folder name.
     * Evaluates to 'jsondata/sourcedata/missals/{missal_folder}/i18n'.
     */
    public const MISSAL_I18N_FOLDER = JsonData::MISSALS_FOLDER . '/{missal_folder}/i18n';

    /**
     * The file containing the i18n data for the specified missal,
     * with placeholders for the actual missal folder name and locale.
     * Evaluates to 'jsondata/sourcedata/missals/{missal_folder}/i18n/{locale}.json'.
     */
    public const MISSAL_I18N_FILE = JsonData::MISSAL_I18N_FOLDER . '/{locale}.json';

    /**
     * The folder containing lectionary data for missals, with a placeholder for the actual missal folder name.
     * Evaluates to 'jsondata/sourcedata/missals/{missal_folder}/lectionary'.
     */
    public const MISSAL_LECTIONARY_FOLDER = JsonData::MISSALS_FOLDER . '/{missal_folder}/lectionary';

    /**
     * The file containing the lectionary data for the specified missal,
     * with placeholders for the actual missal folder name and locale.
     * Evaluates to 'jsondata/sourcedata/missals/{missal_folder}/lectionary/{locale}.json'.
     */
    public const MISSAL_LECTIONARY_FILE = JsonData::MISSAL_LECTIONARY_FOLDER . '/{locale}.json';

    /**
     * The folder containing readings from the lectionary for every possible liturgical event in the General Roman Calendar.
     * Evaluates to 'jsondata/sourcedata/lectionarium'.
     */
    public const LECTIONARY_FOLDER = JsonData::SOURCEDATA_FOLDER . '/lectionary';

    /**
     * The folder containing readings from the lectionary for Sundays and Festivities - Year A (General Roman Calendar).
     * Evaluates to 'jsondata/sourcedata/lectionarium/dominicale_et_festivum_A'.
     */
    public const LECTIONARY_SUNDAYS_SOLEMNITIES_A_FOLDER = JsonData::LECTIONARY_FOLDER . '/dominicale_et_festivum_A';

    /**
     * The folder containing readings from the lectionary for Sundays and Festivities - Year B (General Roman Calendar).
     * Evaluates to 'jsondata/sourcedata/lectionary/dominicale_et_festivum_B'.
     */
    public const LECTIONARY_SUNDAYS_SOLEMNITIES_B_FOLDER = JsonData::LECTIONARY_FOLDER . '/dominicale_et_festivum_B';

    /**
     * The folder containing readings from the lectionary for Sundays and Festivities - Year C (General Roman Calendar).
     * Evaluates to 'jsondata/sourcedata/lectionary/dominicale_et_festivum_C'.
     */
    public const LECTIONARY_SUNDAYS_SOLEMNITIES_C_FOLDER = JsonData::LECTIONARY_FOLDER . '/dominicale_et_festivum_C';

    /**
     * The folder containing readings from the lectionary for Weekdays of Ordinary Time - Year I (General Roman Calendar).
     * Evaluates to 'jsondata/sourcedata/lectionary/feriale_per_annum_I'.
     */
    public const LECTIONARY_WEEKDAYS_ORDINARY_I_FOLDER = JsonData::LECTIONARY_FOLDER . '/feriale_per_annum_I';

    /**
     * The folder containing readings from the lectionary for Weekdays of Ordinary Time - Year II (General Roman Calendar).
     * Evaluates to 'jsondata/sourcedata/lectionary/feriale_per_annum_II'.
     */
    public const LECTIONARY_WEEKDAYS_ORDINARY_II_FOLDER = JsonData::LECTIONARY_FOLDER . '/feriale_per_annum_II';

    /**
     * The folder containing readings from the lectionary for Weekdays of Advent.
     * Evaluates to 'jsondata/sourcedata/lectionary/feriale_tempus_adventus'.
     */
    public const LECTIONARY_WEEKDAYS_ADVENT_FOLDER = JsonData::LECTIONARY_FOLDER . '/feriale_tempus_adventus';

    /**
     * The folder containing readings from the lectionary for Weekdays of Christmas.
     * Evaluates to 'jsondata/sourcedata/lectionary/feriale_tempus_nativitatis'.
     */
    public const LECTIONARY_WEEKDAYS_CHRISTMAS_FOLDER = JsonData::LECTIONARY_FOLDER . '/feriale_tempus_nativitatis';

    /**
     * The folder containing readings from the lectionary for Weekdays of Lent.
     * Evaluates to 'jsondata/sourcedata/lectionary/feriale_tempus_quadragesimae'.
     */
    public const LECTIONARY_WEEKDAYS_LENT_FOLDER = JsonData::LECTIONARY_FOLDER . '/feriale_tempus_quadragesimae';

    /**
     * The folder containing readings from the lectionary for Weekdays of Easter.
     * Evaluates to 'jsondata/sourcedata/lectionary/feriale_tempus_paschatis'.
     */
    public const LECTIONARY_WEEKDAYS_EASTER_FOLDER = JsonData::LECTIONARY_FOLDER . '/feriale_tempus_paschatis';

    /**
     * The folder containing readings from the lectionary for celebrations of the Saints.
     * Evaluates to 'jsondata/sourcedata/lectionary/sanctorum'.
     */
    public const LECTIONARY_SAINTS_FOLDER = JsonData::LECTIONARY_FOLDER . '/sanctorum';

    /**
     * The file containing readings from the lectionary for Sundays and Festivities - Year A (General Roman Calendar),
     * with placeholders for the locale.
     * Evaluates to 'jsondata/sourcedata/lectionary/dominicale_et_festivum_A/{locale}.json'.
     */
    public const LECTIONARY_SUNDAYS_SOLEMNITIES_A_FILE = JsonData::LECTIONARY_SUNDAYS_SOLEMNITIES_A_FOLDER . '/{locale}.json';

    /**
     * The file containing readings from the lectionary for Sundays and Festivities - Year B (General Roman Calendar),
     * with placeholders for the locale.
     * Evaluates to 'jsondata/sourcedata/lectionary/dominicale_et_festivum_B/{locale}.json'.
     */
    public const LECTIONARY_SUNDAYS_SOLEMNITIES_B_FILE = JsonData::LECTIONARY_SUNDAYS_SOLEMNITIES_B_FOLDER . '/{locale}.json';

    /**
     * The file containing readings from the lectionary for Sundays and Festivities - Year C (General Roman Calendar),
     * with placeholders for the locale.
     * Evaluates to 'jsondata/sourcedata/lectionary/dominicale_et_festivum_C/{locale}.json'.
     */
    public const LECTIONARY_SUNDAYS_SOLEMNITIES_C_FILE = JsonData::LECTIONARY_SUNDAYS_SOLEMNITIES_C_FOLDER . '/{locale}.json';

    /**
     * The file containing readings from the lectionary for Weekdays of Ordinary Time - Year I (General Roman Calendar),
     * with placeholders for the locale.
     * Evaluates to 'jsondata/sourcedata/lectionary/feriale_per_annum_I/{locale}.json'.
     */
    public const LECTIONARY_WEEKDAYS_ORDINARY_I_FILE = JsonData::LECTIONARY_WEEKDAYS_ORDINARY_I_FOLDER . '/{locale}.json';

    /**
     * The file containing readings from the lectionary for Weekdays of Ordinary Time - Year II (General Roman Calendar),
     * with placeholders for the locale.
     * Evaluates to 'jsondata/sourcedata/lectionary/feriale_per_annum_II/{locale}.json'.
     */
    public const LECTIONARY_WEEKDAYS_ORDINARY_II_FILE = JsonData::LECTIONARY_WEEKDAYS_ORDINARY_II_FOLDER . '/{locale}.json';

    /**
     * The file containing readings from the lectionary for Weekdays of Advent,
     * with placeholders for the locale.
     * Evaluates to 'jsondata/sourcedata/lectionary/feriale_tempus_adventus/{locale}.json'.
     */
    public const LECTIONARY_WEEKDAYS_ADVENT_FILE = JsonData::LECTIONARY_WEEKDAYS_ADVENT_FOLDER . '/{locale}.json';

    /**
     * The file containing readings from the lectionary for Weekdays of Christmas,
     * with placeholders for the locale.
     * Evaluates to 'jsondata/sourcedata/lectionary/feriale_tempus_nativitatis/{locale}.json'.
     */
    public const LECTIONARY_WEEKDAYS_CHRISTMAS_FILE = JsonData::LECTIONARY_WEEKDAYS_CHRISTMAS_FOLDER . '/{locale}.json';

    /**
     * The file containing readings from the lectionary for Weekdays of Lent,
     * with placeholders for the locale.
     * Evaluates to 'jsondata/sourcedata/lectionary/feriale_tempus_quadragesimae/{locale}.json'.
     */
    public const LECTIONARY_WEEKDAYS_LENT_FILE = JsonData::LECTIONARY_WEEKDAYS_LENT_FOLDER . '/{locale}.json';

    /**
     * The file containing readings from the lectionary for Weekdays of Easter,
     * with placeholders for the locale.
     * Evaluates to 'jsondata/sourcedata/lectionary/feriale_tempus_paschatis/{locale}.json'.
     */
    public const LECTIONARY_WEEKDAYS_EASTER_FILE = JsonData::LECTIONARY_WEEKDAYS_EASTER_FOLDER . '/{locale}.json';

    /**
     * The file containing readings from the lectionary for celebrations of the Saints,
     * with placeholders for the locale.
     * Evaluates to 'jsondata/sourcedata/lectionary/sanctorum/{locale}.json'.
     */
    public const LECTIONARY_SAINTS_FILE = JsonData::LECTIONARY_SAINTS_FOLDER . '/{locale}.json';

    /**
     * The folder containing readings for memorials created via Decrees of the Congregation of Divine Worship.
     * Evalates to 'jsondata/sourcedata/decrees/lectionary';
     */
    public const LECTIONARY_DECREES_FOLDER = JsonData::DECREES_FOLDER . '/lectionary';

    /**
     * The file containing readings for memorials created via Decrees of the Congregation of Divine Worship,
     * with placeholders for the locale.
     * Evaluates to 'jsondata/sourcedata/decrees/lectionary/{locale}.json'.
     */
    public const LECTIONARY_DECREES_FILE = JsonData::LECTIONARY_DECREES_FOLDER . '/{locale}.json';

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
    public const WIDER_REGION_FILE = JsonData::WIDER_REGIONS_FOLDER . '/{wider_region}/{wider_region}.json';

    /**
     * The folder containing i18n files for wider regions, with a placeholder for the actual region name.
     * Evaluates to 'jsondata/sourcedata/calendars/wider_regions/{wider_region}/i18n'.
     */
    public const WIDER_REGION_I18N_FOLDER = JsonData::WIDER_REGIONS_FOLDER . '/{wider_region}/i18n';

    /**
     * The file containing the i18n data for the specified Wider Region calendar,
     * with placeholders for the actual region name and locale.
     * Evaluates to 'jsondata/sourcedata/calendars/wider_regions/{wider_region}/i18n/{locale}.json'.
     */
    public const WIDER_REGION_I18N_FILE = JsonData::WIDER_REGION_I18N_FOLDER . '/{locale}.json';

    /**
     * The folder containing lectionary data for wider regions, with a placeholder for the actual region name.
     * Evaluates to 'jsondata/sourcedata/calendars/wider_regions/{wider_region}/lectionary'.
     */
    public const WIDER_REGION_LECTIONARY_FOLDER = JsonData::WIDER_REGIONS_FOLDER . '/{wider_region}/lectionary';

    /**
     * The file containing the lectionary data for the specified Wider Region calendar,
     * with placeholders for the actual region name and locale.
     * Evaluates to 'jsondata/sourcedata/calendars/wider_regions/{wider_region}/lectionary/{locale}.json'.
     */
    public const WIDER_REGION_LECTIONARY_FILE = JsonData::WIDER_REGION_LECTIONARY_FOLDER . '/{locale}.json';

    /**
     * The folder containing national calendars, with a placeholder for the actual nation name.
     * Evaluates to 'jsondata/sourcedata/calendars/nations'.
     */
    public const NATIONAL_CALENDARS_FOLDER = JsonData::CALENDARS_FOLDER . '/nations';

    /**
     * The file containing the national calendar data, with placeholders for the actual nation name.
     * Evaluates to 'jsondata/sourcedata/calendars/nations/{nation}/{nation}.json'.
     */
    public const NATIONAL_CALENDAR_FILE = JsonData::NATIONAL_CALENDARS_FOLDER . '/{nation}/{nation}.json';

    /**
     * The folder containing i18n files for national calendars, with placeholders for the actual nation and calendar names.
     * Evaluates to 'jsondata/sourcedata/calendars/nations/{nation}/i18n'.
     */
    public const NATIONAL_CALENDAR_I18N_FOLDER = JsonData::NATIONAL_CALENDARS_FOLDER . '/{nation}/i18n';

    /**
     * The file containing the i18n data for the specified national calendar,
     * with placeholders for the actual nation name and locale.
     * Evaluates to 'jsondata/sourcedata/calendars/nations/{nation}/i18n/{locale}.json'.
     */
    public const NATIONAL_CALENDAR_I18N_FILE = JsonData::NATIONAL_CALENDAR_I18N_FOLDER . '/{locale}.json';

    /**
     * The folder containing lectionary data for national calendars, with placeholders for the actual nation name.
     * Evaluates to 'jsondata/sourcedata/calendars/nations/{nation}/lectionary'.
     */
    public const NATIONAL_CALENDAR_LECTIONARY_FOLDER = JsonData::NATIONAL_CALENDARS_FOLDER . '/{nation}/lectionary';

    /**
     * The file containing the lectionary data for the specified national calendar,
     * with placeholders for the actual nation name and locale.
     * Evaluates to 'jsondata/sourcedata/calendars/nations/{nation}/lectionary/{locale}.json'.
     */
    public const NATIONAL_CALENDAR_LECTIONARY_FILE = JsonData::NATIONAL_CALENDAR_LECTIONARY_FOLDER . '/{locale}.json';

    /**
     * The folder containing diocesan calendars.
     * Evaluates to 'jsondata/sourcedata/calendars/dioceses'.
     */
    public const DIOCESAN_CALENDARS_FOLDER = JsonData::CALENDARS_FOLDER . '/dioceses';

    /**
     * The file containing the diocesan calendar data, with placeholders for the actual nation and diocese names.
     * Evaluates to 'jsondata/sourcedata/calendars/dioceses/{nation}/{diocese}/{diocese_name}.json'.
     */
    public const DIOCESAN_CALENDAR_FILE = JsonData::DIOCESAN_CALENDARS_FOLDER . '/{nation}/{diocese}/{diocese_name}.json';

    /**
     * The folder containing i18n files for diocesan calendars, with placeholders for the actual nation and diocese names.
     * Evaluates to 'jsondata/sourcedata/calendars/dioceses/{nation}/{diocese}/i18n'.
     */
    public const DIOCESAN_CALENDAR_I18N_FOLDER = JsonData::DIOCESAN_CALENDARS_FOLDER . '/{nation}/{diocese}/i18n';

    /**
     * The file containing the i18n data for the specified diocesan calendar,
     * with placeholders for the actual nation name, diocese name, and locale.
     * Evaluates to 'jsondata/sourcedata/calendars/dioceses/{nation}/{diocese}/i18n/{locale}.json'.
     */
    public const DIOCESAN_CALENDAR_I18N_FILE = JsonData::DIOCESAN_CALENDAR_I18N_FOLDER . '/{locale}.json';

    /**
     * The folder containing lectionary data for diocesan calendars, with placeholders for the actual nation and diocese names.
     * Evaluates to 'jsondata/sourcedata/calendars/dioceses/{nation}/{diocese}/lectionary'.
     */
    public const DIOCESAN_CALENDAR_LECTIONARY_FOLDER = JsonData::DIOCESAN_CALENDARS_FOLDER . '/{nation}/{diocese}/lectionary';

    /**
     * The file containing the lectionary data for the specified diocesan calendar,
     * with placeholders for the actual nation name, diocese name, and locale.
     * Evaluates to 'jsondata/sourcedata/calendars/dioceses/{nation}/{diocese}/lectionary/{locale}.json'.
     */
    public const DIOCESAN_CALENDAR_LECTIONARY_FILE = JsonData::DIOCESAN_CALENDAR_LECTIONARY_FOLDER . '/{locale}.json';

    /**
     * The file containing the data for the world dioceses of the Latin Rite.
     * Evaluates to 'jsondata/world_dioceses.json'.
     */
    public const WORLD_DIOCESES_LATIN_RITE = JsonData::FOLDER . '/world_dioceses.json';
}
