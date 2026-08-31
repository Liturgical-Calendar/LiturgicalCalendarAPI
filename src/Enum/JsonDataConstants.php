<?php

namespace LiturgicalCalendar\Api\Enum;

class JsonDataConstants
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
    public const SCHEMAS_FOLDER = JsonDataConstants::FOLDER . '/schemas';

    /**
     * The folder containing test files.
     * Evaluates to 'jsondata/tests'.
     */
    public const TESTS_FOLDER = JsonDataConstants::FOLDER . '/tests';

    /**
     * The test corpus is partitioned by rite, so that a test name is unique within a
     * rite rather than globally: a Roman `StIgnatiusOfLoyolaTest` can coexist with the
     * Ambrosian one. Unlike the sourcedata partitions this lives under `jsondata/tests`
     * rather than `sourcedata/rite`, because tests are not source data.
     *
     * Evaluates to 'jsondata/tests/roman'.
     */
    public const ROMAN_TESTS_FOLDER = JsonDataConstants::TESTS_FOLDER . '/roman';

    /** Evaluates to 'jsondata/tests/ambrosian'. */
    public const AMBROSIAN_TESTS_FOLDER = JsonDataConstants::TESTS_FOLDER . '/ambrosian';

    /**
     * The folder containing source data.
     * Evaluates to 'jsondata/sourcedata'.
     */
    public const SOURCEDATA_FOLDER = JsonDataConstants::FOLDER . '/sourcedata';

    /**
     * The base folder for Roman-rite source data.
     * Evaluates to 'jsondata/sourcedata/rite/roman'.
     */
    public const ROMAN_RITE_FOLDER = JsonDataConstants::SOURCEDATA_FOLDER . '/rite/roman';

    /**
     * The base folder for Ambrosian-rite source data.
     * Evaluates to 'jsondata/sourcedata/rite/ambrosian'.
     */
    public const AMBROSIAN_RITE_FOLDER = JsonDataConstants::SOURCEDATA_FOLDER . '/rite/ambrosian';

    /**
     * The folder containing ecclesiastical decrees.
     * Evaluates to 'jsondata/sourcedata/rite/roman/decrees'.
     */
    public const DECREES_FOLDER = JsonDataConstants::ROMAN_RITE_FOLDER . '/decrees';

    /**
     * The file containing the data with the Decrees of the Dicastery for Divine Worship and the Discipline of the Sacraments.
     * Evaluates to 'jsondata/sourcedata/rite/roman/decrees/decrees.json'.
     */
    public const DECREES_FILE = JsonDataConstants::DECREES_FOLDER . '/decrees.json';

    /**
     * The folder containing i18n files for ecclesiastical decrees.
     * Evaluates to 'jsondata/sourcedata/rite/roman/decrees/i18n'.
     */
    public const DECREES_I18N_FOLDER = JsonDataConstants::DECREES_FOLDER . '/i18n';

    /**
     * The file containing the i18n data for the decrees of the Dicastery for Divine Worship and the Discipline of the Sacraments,
     * with a placeholder for the locale.
     * Evaluates to 'jsondata/sourcedata/rite/roman/decrees/i18n/{locale}.json'.
     */
    public const DECREES_I18N_FILE = JsonDataConstants::DECREES_I18N_FOLDER . '/{locale}.json';

    /**
     * The folder containing missal resources.
     * Evaluates to 'jsondata/sourcedata/rite/roman/missals'.
     */
    public const MISSALS_FOLDER = JsonDataConstants::ROMAN_RITE_FOLDER . '/missals';

    /**
     * The file containing the missal data, with a placeholder for the actual missal folder name.
     * Evaluates to 'jsondata/sourcedata/rite/roman/missals/{missal_folder}/{missal_folder}.json'.
     */
    public const MISSAL_FILE = JsonDataConstants::MISSALS_FOLDER . '/{missal_folder}/{missal_folder}.json';

    /**
     * The folder containing i18n files for missals, with a placeholder for the actual missal folder name.
     * Evaluates to 'jsondata/sourcedata/rite/roman/missals/{missal_folder}/i18n'.
     */
    public const MISSAL_I18N_FOLDER = JsonDataConstants::MISSALS_FOLDER . '/{missal_folder}/i18n';

    /**
     * The file containing the i18n data for the specified missal,
     * with placeholders for the actual missal folder name and locale.
     * Evaluates to 'jsondata/sourcedata/rite/roman/missals/{missal_folder}/i18n/{locale}.json'.
     */
    public const MISSAL_I18N_FILE = JsonDataConstants::MISSAL_I18N_FOLDER . '/{locale}.json';

    /**
     * The folder containing lectionary data for missals, with a placeholder for the actual missal folder name.
     * Evaluates to 'jsondata/sourcedata/rite/roman/missals/{missal_folder}/lectionary'.
     */
    public const MISSAL_LECTIONARY_FOLDER = JsonDataConstants::MISSALS_FOLDER . '/{missal_folder}/lectionary';

    /**
     * The file containing the lectionary data for the specified missal,
     * with placeholders for the actual missal folder name and locale.
     * Evaluates to 'jsondata/sourcedata/rite/roman/missals/{missal_folder}/lectionary/{locale}.json'.
     */
    public const MISSAL_LECTIONARY_FILE = JsonDataConstants::MISSAL_LECTIONARY_FOLDER . '/{locale}.json';

    /**
     * The folder containing Proprium de Tempore (temporale) data.
     * Evaluates to 'jsondata/sourcedata/rite/roman/missals/propriumdetempore'.
     */
    public const TEMPORALE_FOLDER = JsonDataConstants::MISSALS_FOLDER . '/propriumdetempore';

    /**
     * The file containing the Proprium de Tempore (temporale) data.
     * Evaluates to 'jsondata/sourcedata/rite/roman/missals/propriumdetempore/propriumdetempore.json'.
     */
    public const TEMPORALE_FILE = JsonDataConstants::TEMPORALE_FOLDER . '/propriumdetempore.json';

    /**
     * The folder containing i18n files for Proprium de Tempore (temporale).
     * Evaluates to 'jsondata/sourcedata/rite/roman/missals/propriumdetempore/i18n'.
     */
    public const TEMPORALE_I18N_FOLDER = JsonDataConstants::TEMPORALE_FOLDER . '/i18n';

    /**
     * The file containing the i18n data for Proprium de Tempore (temporale),
     * with a placeholder for the locale.
     * Evaluates to 'jsondata/sourcedata/rite/roman/missals/propriumdetempore/i18n/{locale}.json'.
     */
    public const TEMPORALE_I18N_FILE = JsonDataConstants::TEMPORALE_I18N_FOLDER . '/{locale}.json';

    /**
     * The folder containing Ambrosian missal resources.
     * Evaluates to 'jsondata/sourcedata/rite/ambrosian/missals'.
     */
    public const AMBROSIAN_MISSALS_FOLDER = JsonDataConstants::AMBROSIAN_RITE_FOLDER . '/missals';

    /**
     * The file containing the Ambrosian missal data, with a placeholder for the actual missal folder name.
     * Evaluates to 'jsondata/sourcedata/rite/ambrosian/missals/{missal_folder}/{missal_folder}.json'.
     */
    public const AMBROSIAN_MISSAL_FILE = JsonDataConstants::AMBROSIAN_MISSALS_FOLDER . '/{missal_folder}/{missal_folder}.json';

    /**
     * The folder containing Ambrosian Proprium de Tempore (temporale) data.
     * Evaluates to 'jsondata/sourcedata/rite/ambrosian/missals/propriumdetempore'.
     */
    public const AMBROSIAN_TEMPORALE_FOLDER = JsonDataConstants::AMBROSIAN_MISSALS_FOLDER . '/propriumdetempore';

    /**
     * The file containing the Ambrosian Proprium de Tempore (temporale) data.
     * Evaluates to 'jsondata/sourcedata/rite/ambrosian/missals/propriumdetempore/propriumdetempore.json'.
     */
    public const AMBROSIAN_TEMPORALE_FILE = JsonDataConstants::AMBROSIAN_TEMPORALE_FOLDER . '/propriumdetempore.json';

    /**
     * The folder containing i18n files for Ambrosian Proprium de Tempore (temporale).
     * Evaluates to 'jsondata/sourcedata/rite/ambrosian/missals/propriumdetempore/i18n'.
     */
    public const AMBROSIAN_TEMPORALE_I18N_FOLDER = JsonDataConstants::AMBROSIAN_TEMPORALE_FOLDER . '/i18n';

    /**
     * The file containing the i18n data for Ambrosian Proprium de Tempore (temporale),
     * with a placeholder for the locale.
     * Evaluates to 'jsondata/sourcedata/rite/ambrosian/missals/propriumdetempore/i18n/{locale}.json'.
     */
    public const AMBROSIAN_TEMPORALE_I18N_FILE = JsonDataConstants::AMBROSIAN_TEMPORALE_I18N_FOLDER . '/{locale}.json';

    /**
     * The folder containing Ambrosian Proprium de Sanctis (sanctorale) data, 2024 edition.
     * Evaluates to 'jsondata/sourcedata/rite/ambrosian/missals/propriumdesanctis_2024'.
     *
     * PINNED to the 2024 edition: `AmbrosianMissal::$jsonFiles` carries a per-id map as if each
     * Ambrosian missal id had its own folder, but this constant is the only folder that exists on
     * disk today, so every id resolves here regardless of which one the caller asked for. A future
     * `EDITIO_TYPICA_1976` (#957) would silently resolve into the 2024 folder rather than failing —
     * that per-id resolution is not wired up yet. Not restructured here; tracked as a known
     * limitation until #957 needs a second Ambrosian edition folder.
     */
    public const AMBROSIAN_SANCTORALE_FOLDER = JsonDataConstants::AMBROSIAN_MISSALS_FOLDER . '/propriumdesanctis_2024';

    /**
     * The file containing the Ambrosian Proprium de Sanctis (sanctorale) data, 2024 edition.
     * Evaluates to 'jsondata/sourcedata/rite/ambrosian/missals/propriumdesanctis_2024/propriumdesanctis_2024.json'.
     */
    public const AMBROSIAN_SANCTORALE_FILE = JsonDataConstants::AMBROSIAN_SANCTORALE_FOLDER . '/propriumdesanctis_2024.json';

    /**
     * The folder containing i18n files for the Ambrosian Proprium de Sanctis (sanctorale), 2024 edition.
     * Evaluates to 'jsondata/sourcedata/rite/ambrosian/missals/propriumdesanctis_2024/i18n'.
     */
    public const AMBROSIAN_SANCTORALE_I18N_FOLDER = JsonDataConstants::AMBROSIAN_SANCTORALE_FOLDER . '/i18n';

    /**
     * The file containing the i18n data for the Ambrosian Proprium de Sanctis (sanctorale), 2024 edition,
     * with a placeholder for the locale.
     * Evaluates to 'jsondata/sourcedata/rite/ambrosian/missals/propriumdesanctis_2024/i18n/{locale}.json'.
     */
    public const AMBROSIAN_SANCTORALE_I18N_FILE = JsonDataConstants::AMBROSIAN_SANCTORALE_I18N_FOLDER . '/{locale}.json';

    /**
     * The folder containing Ambrosian diocesan calendars.
     * Evaluates to 'jsondata/sourcedata/rite/ambrosian/calendars/dioceses'.
     */
    public const AMBROSIAN_DIOCESAN_CALENDARS_FOLDER = JsonDataConstants::AMBROSIAN_RITE_FOLDER . '/calendars/dioceses';

    /**
     * The file containing the Ambrosian diocesan calendar data, with placeholders for the actual nation and diocese names.
     * Evaluates to 'jsondata/sourcedata/rite/ambrosian/calendars/dioceses/{nation}/{diocese}/{diocese_name}.json'.
     */
    public const AMBROSIAN_DIOCESAN_CALENDAR_FILE = JsonDataConstants::AMBROSIAN_DIOCESAN_CALENDARS_FOLDER . '/{nation}/{diocese}/{diocese_name}.json';

    /**
     * The folder containing i18n files for Ambrosian diocesan calendars, with placeholders for the actual nation and diocese names.
     * Evaluates to 'jsondata/sourcedata/rite/ambrosian/calendars/dioceses/{nation}/{diocese}/i18n'.
     */
    public const AMBROSIAN_DIOCESAN_CALENDAR_I18N_FOLDER = JsonDataConstants::AMBROSIAN_DIOCESAN_CALENDARS_FOLDER . '/{nation}/{diocese}/i18n';

    /**
     * The file containing the i18n data for the specified Ambrosian diocesan calendar,
     * with placeholders for the actual nation name, diocese name, and locale.
     * Evaluates to 'jsondata/sourcedata/rite/ambrosian/calendars/dioceses/{nation}/{diocese}/i18n/{locale}.json'.
     */
    public const AMBROSIAN_DIOCESAN_CALENDAR_I18N_FILE = JsonDataConstants::AMBROSIAN_DIOCESAN_CALENDAR_I18N_FOLDER . '/{locale}.json';

    /**
     * The folder containing readings from the lectionary for every possible liturgical event in the General Roman Calendar.
     * Evaluates to 'jsondata/sourcedata/rite/roman/lectionary'.
     */
    public const LECTIONARY_FOLDER = JsonDataConstants::ROMAN_RITE_FOLDER . '/lectionary';

    /**
     * The folder containing readings from the lectionary for Sundays and Festivities - Year A (General Roman Calendar).
     * Evaluates to 'jsondata/sourcedata/rite/roman/lectionary/dominicale_et_festivum_A'.
     */
    public const LECTIONARY_SUNDAYS_SOLEMNITIES_A_FOLDER = JsonDataConstants::LECTIONARY_FOLDER . '/dominicale_et_festivum_A';

    /**
     * The folder containing readings from the lectionary for Sundays and Festivities - Year B (General Roman Calendar).
     * Evaluates to 'jsondata/sourcedata/rite/roman/lectionary/dominicale_et_festivum_B'.
     */
    public const LECTIONARY_SUNDAYS_SOLEMNITIES_B_FOLDER = JsonDataConstants::LECTIONARY_FOLDER . '/dominicale_et_festivum_B';

    /**
     * The folder containing readings from the lectionary for Sundays and Festivities - Year C (General Roman Calendar).
     * Evaluates to 'jsondata/sourcedata/rite/roman/lectionary/dominicale_et_festivum_C'.
     */
    public const LECTIONARY_SUNDAYS_SOLEMNITIES_C_FOLDER = JsonDataConstants::LECTIONARY_FOLDER . '/dominicale_et_festivum_C';

    /**
     * The folder containing readings from the lectionary for Weekdays of Ordinary Time - Year I (General Roman Calendar).
     * Evaluates to 'jsondata/sourcedata/rite/roman/lectionary/feriale_per_annum_I'.
     */
    public const LECTIONARY_WEEKDAYS_ORDINARY_I_FOLDER = JsonDataConstants::LECTIONARY_FOLDER . '/feriale_per_annum_I';

    /**
     * The folder containing readings from the lectionary for Weekdays of Ordinary Time - Year II (General Roman Calendar).
     * Evaluates to 'jsondata/sourcedata/rite/roman/lectionary/feriale_per_annum_II'.
     */
    public const LECTIONARY_WEEKDAYS_ORDINARY_II_FOLDER = JsonDataConstants::LECTIONARY_FOLDER . '/feriale_per_annum_II';

    /**
     * The folder containing readings from the lectionary for Weekdays of Advent.
     * Evaluates to 'jsondata/sourcedata/rite/roman/lectionary/feriale_tempus_adventus'.
     */
    public const LECTIONARY_WEEKDAYS_ADVENT_FOLDER = JsonDataConstants::LECTIONARY_FOLDER . '/feriale_tempus_adventus';

    /**
     * The folder containing readings from the lectionary for Weekdays of Christmas.
     * Evaluates to 'jsondata/sourcedata/rite/roman/lectionary/feriale_tempus_nativitatis'.
     */
    public const LECTIONARY_WEEKDAYS_CHRISTMAS_FOLDER = JsonDataConstants::LECTIONARY_FOLDER . '/feriale_tempus_nativitatis';

    /**
     * The folder containing readings from the lectionary for Weekdays of Lent.
     * Evaluates to 'jsondata/sourcedata/rite/roman/lectionary/feriale_tempus_quadragesimae'.
     */
    public const LECTIONARY_WEEKDAYS_LENT_FOLDER = JsonDataConstants::LECTIONARY_FOLDER . '/feriale_tempus_quadragesimae';

    /**
     * The folder containing readings from the lectionary for Weekdays of Easter.
     * Evaluates to 'jsondata/sourcedata/rite/roman/lectionary/feriale_tempus_paschatis'.
     */
    public const LECTIONARY_WEEKDAYS_EASTER_FOLDER = JsonDataConstants::LECTIONARY_FOLDER . '/feriale_tempus_paschatis';

    /**
     * The folder containing readings from the lectionary for celebrations of the Saints.
     * Evaluates to 'jsondata/sourcedata/rite/roman/lectionary/sanctorum'.
     */
    public const LECTIONARY_SAINTS_FOLDER = JsonDataConstants::LECTIONARY_FOLDER . '/sanctorum';

    /**
     * The file containing readings from the lectionary for Sundays and Festivities - Year A (General Roman Calendar),
     * with placeholders for the locale.
     * Evaluates to 'jsondata/sourcedata/rite/roman/lectionary/dominicale_et_festivum_A/{locale}.json'.
     */
    public const LECTIONARY_SUNDAYS_SOLEMNITIES_A_FILE = JsonDataConstants::LECTIONARY_SUNDAYS_SOLEMNITIES_A_FOLDER . '/{locale}.json';

    /**
     * The file containing readings from the lectionary for Sundays and Festivities - Year B (General Roman Calendar),
     * with placeholders for the locale.
     * Evaluates to 'jsondata/sourcedata/rite/roman/lectionary/dominicale_et_festivum_B/{locale}.json'.
     */
    public const LECTIONARY_SUNDAYS_SOLEMNITIES_B_FILE = JsonDataConstants::LECTIONARY_SUNDAYS_SOLEMNITIES_B_FOLDER . '/{locale}.json';

    /**
     * The file containing readings from the lectionary for Sundays and Festivities - Year C (General Roman Calendar),
     * with placeholders for the locale.
     * Evaluates to 'jsondata/sourcedata/rite/roman/lectionary/dominicale_et_festivum_C/{locale}.json'.
     */
    public const LECTIONARY_SUNDAYS_SOLEMNITIES_C_FILE = JsonDataConstants::LECTIONARY_SUNDAYS_SOLEMNITIES_C_FOLDER . '/{locale}.json';

    /**
     * The file containing readings from the lectionary for Weekdays of Ordinary Time - Year I (General Roman Calendar),
     * with placeholders for the locale.
     * Evaluates to 'jsondata/sourcedata/rite/roman/lectionary/feriale_per_annum_I/{locale}.json'.
     */
    public const LECTIONARY_WEEKDAYS_ORDINARY_I_FILE = JsonDataConstants::LECTIONARY_WEEKDAYS_ORDINARY_I_FOLDER . '/{locale}.json';

    /**
     * The file containing readings from the lectionary for Weekdays of Ordinary Time - Year II (General Roman Calendar),
     * with placeholders for the locale.
     * Evaluates to 'jsondata/sourcedata/rite/roman/lectionary/feriale_per_annum_II/{locale}.json'.
     */
    public const LECTIONARY_WEEKDAYS_ORDINARY_II_FILE = JsonDataConstants::LECTIONARY_WEEKDAYS_ORDINARY_II_FOLDER . '/{locale}.json';

    /**
     * The file containing readings from the lectionary for Weekdays of Advent,
     * with placeholders for the locale.
     * Evaluates to 'jsondata/sourcedata/rite/roman/lectionary/feriale_tempus_adventus/{locale}.json'.
     */
    public const LECTIONARY_WEEKDAYS_ADVENT_FILE = JsonDataConstants::LECTIONARY_WEEKDAYS_ADVENT_FOLDER . '/{locale}.json';

    /**
     * The file containing readings from the lectionary for Weekdays of Christmas,
     * with placeholders for the locale.
     * Evaluates to 'jsondata/sourcedata/rite/roman/lectionary/feriale_tempus_nativitatis/{locale}.json'.
     */
    public const LECTIONARY_WEEKDAYS_CHRISTMAS_FILE = JsonDataConstants::LECTIONARY_WEEKDAYS_CHRISTMAS_FOLDER . '/{locale}.json';

    /**
     * The file containing readings from the lectionary for Weekdays of Lent,
     * with placeholders for the locale.
     * Evaluates to 'jsondata/sourcedata/rite/roman/lectionary/feriale_tempus_quadragesimae/{locale}.json'.
     */
    public const LECTIONARY_WEEKDAYS_LENT_FILE = JsonDataConstants::LECTIONARY_WEEKDAYS_LENT_FOLDER . '/{locale}.json';

    /**
     * The file containing readings from the lectionary for Weekdays of Easter,
     * with placeholders for the locale.
     * Evaluates to 'jsondata/sourcedata/rite/roman/lectionary/feriale_tempus_paschatis/{locale}.json'.
     */
    public const LECTIONARY_WEEKDAYS_EASTER_FILE = JsonDataConstants::LECTIONARY_WEEKDAYS_EASTER_FOLDER . '/{locale}.json';

    /**
     * The file containing readings from the lectionary for celebrations of the Saints,
     * with placeholders for the locale.
     * Evaluates to 'jsondata/sourcedata/rite/roman/lectionary/sanctorum/{locale}.json'.
     */
    public const LECTIONARY_SAINTS_FILE = JsonDataConstants::LECTIONARY_SAINTS_FOLDER . '/{locale}.json';

    /**
     * The folder containing readings for memorials created via Decrees of the Dicastery for Divine Worship and the Discipline of the Sacraments.
     * Evaluates to 'jsondata/sourcedata/rite/roman/decrees/lectionary';
     */
    public const LECTIONARY_DECREES_FOLDER = JsonDataConstants::DECREES_FOLDER . '/lectionary';

    /**
     * The file containing readings for memorials created via Decrees of the Dicastery for Divine Worship and the Discipline of the Sacraments,
     * with placeholders for the locale.
     * Evaluates to 'jsondata/sourcedata/rite/roman/decrees/lectionary/{locale}.json'.
     */
    public const LECTIONARY_DECREES_FILE = JsonDataConstants::LECTIONARY_DECREES_FOLDER . '/{locale}.json';

    /**
     * The folder containing calendar data.
     * Evaluates to 'jsondata/sourcedata/rite/roman/calendars'.
     */
    public const CALENDARS_FOLDER = JsonDataConstants::ROMAN_RITE_FOLDER . '/calendars';

    /**
     * The folder containing wider regions calendar data, with a placeholder for the actual region name.
     * Evaluates to 'jsondata/sourcedata/rite/roman/calendars/wider_regions'.
     */
    public const WIDER_REGIONS_FOLDER = JsonDataConstants::CALENDARS_FOLDER . '/wider_regions';

    /**
     * The file containing the Wider Region calendar data, with placeholders for the actual region name.
     * Evaluates to 'jsondata/sourcedata/rite/roman/calendars/wider_regions/{wider_region}/{wider_region}.json'.
     */
    public const WIDER_REGION_FILE = JsonDataConstants::WIDER_REGIONS_FOLDER . '/{wider_region}/{wider_region}.json';

    /**
     * The folder containing i18n files for wider regions, with a placeholder for the actual region name.
     * Evaluates to 'jsondata/sourcedata/rite/roman/calendars/wider_regions/{wider_region}/i18n'.
     */
    public const WIDER_REGION_I18N_FOLDER = JsonDataConstants::WIDER_REGIONS_FOLDER . '/{wider_region}/i18n';

    /**
     * The file containing the i18n data for the specified Wider Region calendar,
     * with placeholders for the actual region name and locale.
     * Evaluates to 'jsondata/sourcedata/rite/roman/calendars/wider_regions/{wider_region}/i18n/{locale}.json'.
     */
    public const WIDER_REGION_I18N_FILE = JsonDataConstants::WIDER_REGION_I18N_FOLDER . '/{locale}.json';

    /**
     * The folder containing lectionary data for wider regions, with a placeholder for the actual region name.
     * Evaluates to 'jsondata/sourcedata/rite/roman/calendars/wider_regions/{wider_region}/lectionary'.
     */
    public const WIDER_REGION_LECTIONARY_FOLDER = JsonDataConstants::WIDER_REGIONS_FOLDER . '/{wider_region}/lectionary';

    /**
     * The file containing the lectionary data for the specified Wider Region calendar,
     * with placeholders for the actual region name and locale.
     * Evaluates to 'jsondata/sourcedata/rite/roman/calendars/wider_regions/{wider_region}/lectionary/{locale}.json'.
     */
    public const WIDER_REGION_LECTIONARY_FILE = JsonDataConstants::WIDER_REGION_LECTIONARY_FOLDER . '/{locale}.json';

    /**
     * The folder containing national calendars, with a placeholder for the actual nation name.
     * Evaluates to 'jsondata/sourcedata/rite/roman/calendars/nations'.
     */
    public const NATIONAL_CALENDARS_FOLDER = JsonDataConstants::CALENDARS_FOLDER . '/nations';

    /**
     * The file containing the national calendar data, with placeholders for the actual nation name.
     * Evaluates to 'jsondata/sourcedata/rite/roman/calendars/nations/{nation}/{nation}.json'.
     */
    public const NATIONAL_CALENDAR_FILE = JsonDataConstants::NATIONAL_CALENDARS_FOLDER . '/{nation}/{nation}.json';

    /**
     * The folder containing i18n files for national calendars, with placeholders for the actual nation and calendar names.
     * Evaluates to 'jsondata/sourcedata/rite/roman/calendars/nations/{nation}/i18n'.
     */
    public const NATIONAL_CALENDAR_I18N_FOLDER = JsonDataConstants::NATIONAL_CALENDARS_FOLDER . '/{nation}/i18n';

    /**
     * The file containing the i18n data for the specified national calendar,
     * with placeholders for the actual nation name and locale.
     * Evaluates to 'jsondata/sourcedata/rite/roman/calendars/nations/{nation}/i18n/{locale}.json'.
     */
    public const NATIONAL_CALENDAR_I18N_FILE = JsonDataConstants::NATIONAL_CALENDAR_I18N_FOLDER . '/{locale}.json';

    /**
     * The folder containing lectionary data for national calendars, with placeholders for the actual nation name.
     * Evaluates to 'jsondata/sourcedata/rite/roman/calendars/nations/{nation}/lectionary'.
     */
    public const NATIONAL_CALENDAR_LECTIONARY_FOLDER = JsonDataConstants::NATIONAL_CALENDARS_FOLDER . '/{nation}/lectionary';

    /**
     * The file containing the lectionary data for the specified national calendar,
     * with placeholders for the actual nation name and locale.
     * Evaluates to 'jsondata/sourcedata/rite/roman/calendars/nations/{nation}/lectionary/{locale}.json'.
     */
    public const NATIONAL_CALENDAR_LECTIONARY_FILE = JsonDataConstants::NATIONAL_CALENDAR_LECTIONARY_FOLDER . '/{locale}.json';

    /**
     * The folder containing diocesan calendars.
     * Evaluates to 'jsondata/sourcedata/rite/roman/calendars/dioceses'.
     */
    public const DIOCESAN_CALENDARS_FOLDER = JsonDataConstants::CALENDARS_FOLDER . '/dioceses';

    /**
     * The file containing the diocesan calendar data, with placeholders for the actual nation and diocese names.
     * Evaluates to 'jsondata/sourcedata/rite/roman/calendars/dioceses/{nation}/{diocese}/{diocese_name}.json'.
     */
    public const DIOCESAN_CALENDAR_FILE = JsonDataConstants::DIOCESAN_CALENDARS_FOLDER . '/{nation}/{diocese}/{diocese_name}.json';

    /**
     * The folder containing i18n files for diocesan calendars, with placeholders for the actual nation and diocese names.
     * Evaluates to 'jsondata/sourcedata/rite/roman/calendars/dioceses/{nation}/{diocese}/i18n'.
     */
    public const DIOCESAN_CALENDAR_I18N_FOLDER = JsonDataConstants::DIOCESAN_CALENDARS_FOLDER . '/{nation}/{diocese}/i18n';

    /**
     * The file containing the i18n data for the specified diocesan calendar,
     * with placeholders for the actual nation name, diocese name, and locale.
     * Evaluates to 'jsondata/sourcedata/rite/roman/calendars/dioceses/{nation}/{diocese}/i18n/{locale}.json'.
     */
    public const DIOCESAN_CALENDAR_I18N_FILE = JsonDataConstants::DIOCESAN_CALENDAR_I18N_FOLDER . '/{locale}.json';

    /**
     * The folder containing lectionary data for diocesan calendars, with placeholders for the actual nation and diocese names.
     * Evaluates to 'jsondata/sourcedata/rite/roman/calendars/dioceses/{nation}/{diocese}/lectionary'.
     */
    public const DIOCESAN_CALENDAR_LECTIONARY_FOLDER = JsonDataConstants::DIOCESAN_CALENDARS_FOLDER . '/{nation}/{diocese}/lectionary';

    /**
     * The file containing the lectionary data for the specified diocesan calendar,
     * with placeholders for the actual nation name, diocese name, and locale.
     * Evaluates to 'jsondata/sourcedata/rite/roman/calendars/dioceses/{nation}/{diocese}/lectionary/{locale}.json'.
     */
    public const DIOCESAN_CALENDAR_LECTIONARY_FILE = JsonDataConstants::DIOCESAN_CALENDAR_LECTIONARY_FOLDER . '/{locale}.json';

    /**
     * The file containing the data for the world dioceses of the Latin Rite.
     * Evaluates to 'jsondata/world_dioceses.json'.
     */
    public const CATHOLIC_DIOCESES_LATIN_RITE = JsonDataConstants::FOLDER . '/world_dioceses.json';

    /**
     * The curated list of officially supported locales.
     * Evaluates to 'jsondata/supportedLocales.json'.
     */
    public const SUPPORTED_LOCALES_FILE = JsonDataConstants::FOLDER . '/supportedLocales.json';
}
