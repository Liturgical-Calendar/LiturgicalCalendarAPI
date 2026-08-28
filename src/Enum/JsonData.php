<?php

namespace LiturgicalCalendar\Api\Enum;

use LiturgicalCalendar\Api\Router;

enum JsonData: string
{
    /**
     * The base folder for JSON data.
     * Evaluates to 'jsondata'.
     */
    case FOLDER = JsonDataConstants::FOLDER;

    /**
     * The folder containing schema files.
     * Evaluates to 'jsondata/schemas'.
     */
    case SCHEMAS_FOLDER = JsonDataConstants::SCHEMAS_FOLDER;

    /**
     * The folder containing test files.
     * Evaluates to 'jsondata/tests'.
     */
    case TESTS_FOLDER = JsonDataConstants::TESTS_FOLDER;

    case ROMAN_TESTS_FOLDER     = JsonDataConstants::ROMAN_TESTS_FOLDER;
    case AMBROSIAN_TESTS_FOLDER = JsonDataConstants::AMBROSIAN_TESTS_FOLDER;

    /**
     * The folder containing source data.
     * Evaluates to 'jsondata/sourcedata'.
     */
    case SOURCEDATA_FOLDER = JsonDataConstants::SOURCEDATA_FOLDER;

    /**
     * The folder containing ecclesiastical decrees.
     * Evaluates to 'jsondata/sourcedata/rite/roman/decrees'.
     */
    case DECREES_FOLDER = JsonDataConstants::DECREES_FOLDER;

    /**
     * The file containing the data with the Decrees of the Dicastery for Divine Worship and the Discipline of the Sacraments.
     * Evaluates to 'jsondata/sourcedata/rite/roman/decrees/decrees.json'.
     */
    case DECREES_FILE = JsonDataConstants::DECREES_FILE;

    /**
     * The folder containing i18n files for ecclesiastical decrees.
     * Evaluates to 'jsondata/sourcedata/rite/roman/decrees/i18n'.
     */
    case DECREES_I18N_FOLDER = JsonDataConstants::DECREES_I18N_FOLDER;

    /**
     * The file containing the i18n data for the decrees of the Dicastery for Divine Worship and the Discipline of the Sacraments,
     * with a placeholder for the locale.
     * Evaluates to 'jsondata/sourcedata/rite/roman/decrees/i18n/{locale}.json'.
     */
    case DECREES_I18N_FILE = JsonDataConstants::DECREES_I18N_FILE;

    /**
     * The folder containing missal resources.
     * Evaluates to 'jsondata/sourcedata/rite/roman/missals'.
     */
    case MISSALS_FOLDER = JsonDataConstants::MISSALS_FOLDER;

    /**
     * The file containing the missal data, with a placeholder for the actual missal folder name.
     * Evaluates to 'jsondata/sourcedata/rite/roman/missals/{missal_folder}/{missal_folder}.json'.
     */
    case MISSAL_FILE = JsonDataConstants::MISSAL_FILE;

    /**
     * The folder containing i18n files for missals, with a placeholder for the actual missal folder name.
     * Evaluates to 'jsondata/sourcedata/rite/roman/missals/{missal_folder}/i18n'.
     */
    case MISSAL_I18N_FOLDER = JsonDataConstants::MISSAL_I18N_FOLDER;

    /**
     * The file containing the i18n data for the specified missal,
     * with placeholders for the actual missal folder name and locale.
     * Evaluates to 'jsondata/sourcedata/rite/roman/missals/{missal_folder}/i18n/{locale}.json'.
     */
    case MISSAL_I18N_FILE = JsonDataConstants::MISSAL_I18N_FILE;

    /**
     * The folder containing lectionary data for missals, with a placeholder for the actual missal folder name.
     * Evaluates to 'jsondata/sourcedata/rite/roman/missals/{missal_folder}/lectionary'.
     */
    case MISSAL_LECTIONARY_FOLDER = JsonDataConstants::MISSAL_LECTIONARY_FOLDER;

    /**
     * The file containing the lectionary data for the specified missal,
     * with placeholders for the actual missal folder name and locale.
     * Evaluates to 'jsondata/sourcedata/rite/roman/missals/{missal_folder}/lectionary/{locale}.json'.
     */
    case MISSAL_LECTIONARY_FILE = JsonDataConstants::MISSAL_LECTIONARY_FILE;

    /**
     * The folder containing Proprium de Tempore (temporale) data.
     * Evaluates to 'jsondata/sourcedata/rite/roman/missals/propriumdetempore'.
     */
    case TEMPORALE_FOLDER = JsonDataConstants::TEMPORALE_FOLDER;

    /**
     * The file containing the Proprium de Tempore (temporale) data.
     * Evaluates to 'jsondata/sourcedata/rite/roman/missals/propriumdetempore/propriumdetempore.json'.
     */
    case TEMPORALE_FILE = JsonDataConstants::TEMPORALE_FILE;

    /**
     * The folder containing i18n files for Proprium de Tempore (temporale).
     * Evaluates to 'jsondata/sourcedata/rite/roman/missals/propriumdetempore/i18n'.
     */
    case TEMPORALE_I18N_FOLDER = JsonDataConstants::TEMPORALE_I18N_FOLDER;

    /**
     * The file containing the i18n data for Proprium de Tempore (temporale),
     * with a placeholder for the locale.
     * Evaluates to 'jsondata/sourcedata/rite/roman/missals/propriumdetempore/i18n/{locale}.json'.
     */
    case TEMPORALE_I18N_FILE = JsonDataConstants::TEMPORALE_I18N_FILE;

    /**
     * The folder containing Ambrosian Proprium de Tempore (temporale) data.
     * Evaluates to 'jsondata/sourcedata/rite/ambrosian/missals/propriumdetempore'.
     */
    case AMBROSIAN_TEMPORALE_FOLDER = JsonDataConstants::AMBROSIAN_TEMPORALE_FOLDER;

    /**
     * The file containing the Ambrosian Proprium de Tempore (temporale) data.
     * Evaluates to 'jsondata/sourcedata/rite/ambrosian/missals/propriumdetempore/propriumdetempore.json'.
     */
    case AMBROSIAN_TEMPORALE_FILE = JsonDataConstants::AMBROSIAN_TEMPORALE_FILE;

    /**
     * The folder containing i18n files for Ambrosian Proprium de Tempore (temporale).
     * Evaluates to 'jsondata/sourcedata/rite/ambrosian/missals/propriumdetempore/i18n'.
     */
    case AMBROSIAN_TEMPORALE_I18N_FOLDER = JsonDataConstants::AMBROSIAN_TEMPORALE_I18N_FOLDER;

    /**
     * The file containing the i18n data for Ambrosian Proprium de Tempore (temporale),
     * with a placeholder for the locale.
     * Evaluates to 'jsondata/sourcedata/rite/ambrosian/missals/propriumdetempore/i18n/{locale}.json'.
     */
    case AMBROSIAN_TEMPORALE_I18N_FILE = JsonDataConstants::AMBROSIAN_TEMPORALE_I18N_FILE;

    /**
     * The folder containing Ambrosian Proprium de Sanctis (sanctorale) data, 2024 edition.
     * Evaluates to 'jsondata/sourcedata/rite/ambrosian/missals/propriumdesanctis_2024'.
     */
    case AMBROSIAN_SANCTORALE_FOLDER = JsonDataConstants::AMBROSIAN_SANCTORALE_FOLDER;

    /**
     * The file containing the Ambrosian Proprium de Sanctis (sanctorale) data, 2024 edition.
     * Evaluates to 'jsondata/sourcedata/rite/ambrosian/missals/propriumdesanctis_2024/propriumdesanctis.json'.
     */
    case AMBROSIAN_SANCTORALE_FILE = JsonDataConstants::AMBROSIAN_SANCTORALE_FILE;

    /**
     * The folder containing i18n files for the Ambrosian Proprium de Sanctis (sanctorale), 2024 edition.
     * Evaluates to 'jsondata/sourcedata/rite/ambrosian/missals/propriumdesanctis_2024/i18n'.
     */
    case AMBROSIAN_SANCTORALE_I18N_FOLDER = JsonDataConstants::AMBROSIAN_SANCTORALE_I18N_FOLDER;

    /**
     * The file containing the i18n data for the Ambrosian Proprium de Sanctis (sanctorale), 2024 edition,
     * with a placeholder for the locale.
     * Evaluates to 'jsondata/sourcedata/rite/ambrosian/missals/propriumdesanctis_2024/i18n/{locale}.json'.
     */
    case AMBROSIAN_SANCTORALE_I18N_FILE = JsonDataConstants::AMBROSIAN_SANCTORALE_I18N_FILE;

    /**
     * The folder containing Ambrosian diocesan calendars.
     * Evaluates to 'jsondata/sourcedata/rite/ambrosian/calendars/dioceses'.
     */
    case AMBROSIAN_DIOCESAN_CALENDARS_FOLDER = JsonDataConstants::AMBROSIAN_DIOCESAN_CALENDARS_FOLDER;

    /**
     * The file containing the Ambrosian diocesan calendar data, with placeholders for the actual nation and diocese names.
     * Evaluates to 'jsondata/sourcedata/rite/ambrosian/calendars/dioceses/{nation}/{diocese}/{diocese_name}.json'.
     */
    case AMBROSIAN_DIOCESAN_CALENDAR_FILE = JsonDataConstants::AMBROSIAN_DIOCESAN_CALENDAR_FILE;

    /**
     * The folder containing i18n files for Ambrosian diocesan calendars, with placeholders for the actual nation and diocese names.
     * Evaluates to 'jsondata/sourcedata/rite/ambrosian/calendars/dioceses/{nation}/{diocese}/i18n'.
     */
    case AMBROSIAN_DIOCESAN_CALENDAR_I18N_FOLDER = JsonDataConstants::AMBROSIAN_DIOCESAN_CALENDAR_I18N_FOLDER;

    /**
     * The file containing the i18n data for the specified Ambrosian diocesan calendar,
     * with placeholders for the actual nation name, diocese name, and locale.
     * Evaluates to 'jsondata/sourcedata/rite/ambrosian/calendars/dioceses/{nation}/{diocese}/i18n/{locale}.json'.
     */
    case AMBROSIAN_DIOCESAN_CALENDAR_I18N_FILE = JsonDataConstants::AMBROSIAN_DIOCESAN_CALENDAR_I18N_FILE;

    /**
     * The folder containing readings from the lectionary for every possible liturgical event in the General Roman Calendar.
     * Evaluates to 'jsondata/sourcedata/rite/roman/lectionary'.
     */
    case LECTIONARY_FOLDER = JsonDataConstants::LECTIONARY_FOLDER;

    /**
     * The folder containing readings from the lectionary for Sundays and Festivities - Year A (General Roman Calendar).
     * Evaluates to 'jsondata/sourcedata/rite/roman/lectionary/dominicale_et_festivum_A'.
     */
    case LECTIONARY_SUNDAYS_SOLEMNITIES_A_FOLDER = JsonDataConstants::LECTIONARY_SUNDAYS_SOLEMNITIES_A_FOLDER;

    /**
     * The folder containing readings from the lectionary for Sundays and Festivities - Year B (General Roman Calendar).
     * Evaluates to 'jsondata/sourcedata/rite/roman/lectionary/dominicale_et_festivum_B'.
     */
    case LECTIONARY_SUNDAYS_SOLEMNITIES_B_FOLDER = JsonDataConstants::LECTIONARY_SUNDAYS_SOLEMNITIES_B_FOLDER;

    /**
     * The folder containing readings from the lectionary for Sundays and Festivities - Year C (General Roman Calendar).
     * Evaluates to 'jsondata/sourcedata/rite/roman/lectionary/dominicale_et_festivum_C'.
     */
    case LECTIONARY_SUNDAYS_SOLEMNITIES_C_FOLDER = JsonDataConstants::LECTIONARY_SUNDAYS_SOLEMNITIES_C_FOLDER;

    /**
     * The folder containing readings from the lectionary for Weekdays of Ordinary Time - Year I (General Roman Calendar).
     * Evaluates to 'jsondata/sourcedata/rite/roman/lectionary/feriale_per_annum_I'.
     */
    case LECTIONARY_WEEKDAYS_ORDINARY_I_FOLDER = JsonDataConstants::LECTIONARY_WEEKDAYS_ORDINARY_I_FOLDER;

    /**
     * The folder containing readings from the lectionary for Weekdays of Ordinary Time - Year II (General Roman Calendar).
     * Evaluates to 'jsondata/sourcedata/rite/roman/lectionary/feriale_per_annum_II'.
     */
    case LECTIONARY_WEEKDAYS_ORDINARY_II_FOLDER = JsonDataConstants::LECTIONARY_WEEKDAYS_ORDINARY_II_FOLDER;

    /**
     * The folder containing readings from the lectionary for Weekdays of Advent.
     * Evaluates to 'jsondata/sourcedata/rite/roman/lectionary/feriale_tempus_adventus'.
     */
    case LECTIONARY_WEEKDAYS_ADVENT_FOLDER = JsonDataConstants::LECTIONARY_WEEKDAYS_ADVENT_FOLDER;

    /**
     * The folder containing readings from the lectionary for Weekdays of Christmas.
     * Evaluates to 'jsondata/sourcedata/rite/roman/lectionary/feriale_tempus_nativitatis'.
     */
    case LECTIONARY_WEEKDAYS_CHRISTMAS_FOLDER = JsonDataConstants::LECTIONARY_WEEKDAYS_CHRISTMAS_FOLDER;

    /**
     * The folder containing readings from the lectionary for Weekdays of Lent.
     * Evaluates to 'jsondata/sourcedata/rite/roman/lectionary/feriale_tempus_quadragesimae'.
     */
    case LECTIONARY_WEEKDAYS_LENT_FOLDER = JsonDataConstants::LECTIONARY_WEEKDAYS_LENT_FOLDER;

    /**
     * The folder containing readings from the lectionary for Weekdays of Easter.
     * Evaluates to 'jsondata/sourcedata/rite/roman/lectionary/feriale_tempus_paschatis'.
     */
    case LECTIONARY_WEEKDAYS_EASTER_FOLDER = JsonDataConstants::LECTIONARY_WEEKDAYS_EASTER_FOLDER;

    /**
     * The folder containing readings from the lectionary for celebrations of the Saints.
     * Evaluates to 'jsondata/sourcedata/rite/roman/lectionary/sanctorum'.
     */
    case LECTIONARY_SAINTS_FOLDER = JsonDataConstants::LECTIONARY_SAINTS_FOLDER;

    /**
     * The file containing readings from the lectionary for Sundays and Festivities - Year A (General Roman Calendar),
     * with placeholders for the locale.
     * Evaluates to 'jsondata/sourcedata/rite/roman/lectionary/dominicale_et_festivum_A/{locale}.json'.
     */
    case LECTIONARY_SUNDAYS_SOLEMNITIES_A_FILE = JsonDataConstants::LECTIONARY_SUNDAYS_SOLEMNITIES_A_FILE;

    /**
     * The file containing readings from the lectionary for Sundays and Festivities - Year B (General Roman Calendar),
     * with placeholders for the locale.
     * Evaluates to 'jsondata/sourcedata/rite/roman/lectionary/dominicale_et_festivum_B/{locale}.json'.
     */
    case LECTIONARY_SUNDAYS_SOLEMNITIES_B_FILE = JsonDataConstants::LECTIONARY_SUNDAYS_SOLEMNITIES_B_FILE;

    /**
     * The file containing readings from the lectionary for Sundays and Festivities - Year C (General Roman Calendar),
     * with placeholders for the locale.
     * Evaluates to 'jsondata/sourcedata/rite/roman/lectionary/dominicale_et_festivum_C/{locale}.json'.
     */
    case LECTIONARY_SUNDAYS_SOLEMNITIES_C_FILE = JsonDataConstants::LECTIONARY_SUNDAYS_SOLEMNITIES_C_FILE;

    /**
     * The file containing readings from the lectionary for Weekdays of Ordinary Time - Year I (General Roman Calendar),
     * with placeholders for the locale.
     * Evaluates to 'jsondata/sourcedata/rite/roman/lectionary/feriale_per_annum_I/{locale}.json'.
     */
    case LECTIONARY_WEEKDAYS_ORDINARY_I_FILE = JsonDataConstants::LECTIONARY_WEEKDAYS_ORDINARY_I_FILE;

    /**
     * The file containing readings from the lectionary for Weekdays of Ordinary Time - Year II (General Roman Calendar),
     * with placeholders for the locale.
     * Evaluates to 'jsondata/sourcedata/rite/roman/lectionary/feriale_per_annum_II/{locale}.json'.
     */
    case LECTIONARY_WEEKDAYS_ORDINARY_II_FILE = JsonDataConstants::LECTIONARY_WEEKDAYS_ORDINARY_II_FILE;

    /**
     * The file containing readings from the lectionary for Weekdays of Advent,
     * with placeholders for the locale.
     * Evaluates to 'jsondata/sourcedata/rite/roman/lectionary/feriale_tempus_adventus/{locale}.json'.
     */
    case LECTIONARY_WEEKDAYS_ADVENT_FILE = JsonDataConstants::LECTIONARY_WEEKDAYS_ADVENT_FILE;

    /**
     * The file containing readings from the lectionary for Weekdays of Christmas,
     * with placeholders for the locale.
     * Evaluates to 'jsondata/sourcedata/rite/roman/lectionary/feriale_tempus_nativitatis/{locale}.json'.
     */
    case LECTIONARY_WEEKDAYS_CHRISTMAS_FILE = JsonDataConstants::LECTIONARY_WEEKDAYS_CHRISTMAS_FILE;

    /**
     * The file containing readings from the lectionary for Weekdays of Lent,
     * with placeholders for the locale.
     * Evaluates to 'jsondata/sourcedata/rite/roman/lectionary/feriale_tempus_quadragesimae/{locale}.json'.
     */
    case LECTIONARY_WEEKDAYS_LENT_FILE = JsonDataConstants::LECTIONARY_WEEKDAYS_LENT_FILE;

    /**
     * The file containing readings from the lectionary for Weekdays of Easter,
     * with placeholders for the locale.
     * Evaluates to 'jsondata/sourcedata/rite/roman/lectionary/feriale_tempus_paschatis/{locale}.json'.
     */
    case LECTIONARY_WEEKDAYS_EASTER_FILE = JsonDataConstants::LECTIONARY_WEEKDAYS_EASTER_FILE;

    /**
     * The file containing readings from the lectionary for celebrations of the Saints,
     * with placeholders for the locale.
     * Evaluates to 'jsondata/sourcedata/rite/roman/lectionary/sanctorum/{locale}.json'.
     */
    case LECTIONARY_SAINTS_FILE = JsonDataConstants::LECTIONARY_SAINTS_FILE;

    /**
     * The folder containing readings for memorials created via Decrees of the Dicastery for Divine Worship and the Discipline of the Sacraments.
     * Evaluates to 'jsondata/sourcedata/rite/roman/decrees/lectionary';
     */
    case LECTIONARY_DECREES_FOLDER = JsonDataConstants::LECTIONARY_DECREES_FOLDER;

    /**
     * The file containing readings for memorials created via Decrees of the Dicastery for Divine Worship and the Discipline of the Sacraments,
     * with placeholders for the locale.
     * Evaluates to 'jsondata/sourcedata/rite/roman/decrees/lectionary/{locale}.json'.
     */
    case LECTIONARY_DECREES_FILE = JsonDataConstants::LECTIONARY_DECREES_FILE;

    /**
     * The folder containing calendar data.
     * Evaluates to 'jsondata/sourcedata/rite/roman/calendars'.
     */
    case CALENDARS_FOLDER = JsonDataConstants::CALENDARS_FOLDER;

    /**
     * The folder containing wider regions calendar data, with a placeholder for the actual region name.
     * Evaluates to 'jsondata/sourcedata/rite/roman/calendars/wider_regions'.
     */
    case WIDER_REGIONS_FOLDER = JsonDataConstants::WIDER_REGIONS_FOLDER;

    /**
     * The file containing the Wider Region calendar data, with placeholders for the actual region name.
     * Evaluates to 'jsondata/sourcedata/rite/roman/calendars/wider_regions/{wider_region}/{wider_region}.json'.
     */
    case WIDER_REGION_FILE = JsonDataConstants::WIDER_REGION_FILE;

    /**
     * The folder containing i18n files for wider regions, with a placeholder for the actual region name.
     * Evaluates to 'jsondata/sourcedata/rite/roman/calendars/wider_regions/{wider_region}/i18n'.
     */
    case WIDER_REGION_I18N_FOLDER = JsonDataConstants::WIDER_REGION_I18N_FOLDER;

    /**
     * The file containing the i18n data for the specified Wider Region calendar,
     * with placeholders for the actual region name and locale.
     * Evaluates to 'jsondata/sourcedata/rite/roman/calendars/wider_regions/{wider_region}/i18n/{locale}.json'.
     */
    case WIDER_REGION_I18N_FILE = JsonDataConstants::WIDER_REGION_I18N_FILE;

    /**
     * The folder containing lectionary data for wider regions, with a placeholder for the actual region name.
     * Evaluates to 'jsondata/sourcedata/rite/roman/calendars/wider_regions/{wider_region}/lectionary'.
     */
    case WIDER_REGION_LECTIONARY_FOLDER = JsonDataConstants::WIDER_REGION_LECTIONARY_FOLDER;

    /**
     * The file containing the lectionary data for the specified Wider Region calendar,
     * with placeholders for the actual region name and locale.
     * Evaluates to 'jsondata/sourcedata/rite/roman/calendars/wider_regions/{wider_region}/lectionary/{locale}.json'.
     */
    case WIDER_REGION_LECTIONARY_FILE = JsonDataConstants::WIDER_REGION_LECTIONARY_FILE;

    /**
     * The folder containing national calendars, with a placeholder for the actual nation name.
     * Evaluates to 'jsondata/sourcedata/rite/roman/calendars/nations'.
     */
    case NATIONAL_CALENDARS_FOLDER = JsonDataConstants::NATIONAL_CALENDARS_FOLDER;

    /**
     * The file containing the national calendar data, with placeholders for the actual nation name.
     * Evaluates to 'jsondata/sourcedata/rite/roman/calendars/nations/{nation}/{nation}.json'.
     */
    case NATIONAL_CALENDAR_FILE = JsonDataConstants::NATIONAL_CALENDAR_FILE;

    /**
     * The folder containing i18n files for national calendars, with placeholders for the actual nation and calendar names.
     * Evaluates to 'jsondata/sourcedata/rite/roman/calendars/nations/{nation}/i18n'.
     */
    case NATIONAL_CALENDAR_I18N_FOLDER = JsonDataConstants::NATIONAL_CALENDAR_I18N_FOLDER;

    /**
     * The file containing the i18n data for the specified national calendar,
     * with placeholders for the actual nation name and locale.
     * Evaluates to 'jsondata/sourcedata/rite/roman/calendars/nations/{nation}/i18n/{locale}.json'.
     */
    case NATIONAL_CALENDAR_I18N_FILE = JsonDataConstants::NATIONAL_CALENDAR_I18N_FILE;

    /**
     * The folder containing lectionary data for national calendars, with placeholders for the actual nation name.
     * Evaluates to 'jsondata/sourcedata/rite/roman/calendars/nations/{nation}/lectionary'.
     */
    case NATIONAL_CALENDAR_LECTIONARY_FOLDER = JsonDataConstants::NATIONAL_CALENDAR_LECTIONARY_FOLDER;

    /**
     * The file containing the lectionary data for the specified national calendar,
     * with placeholders for the actual nation name and locale.
     * Evaluates to 'jsondata/sourcedata/rite/roman/calendars/nations/{nation}/lectionary/{locale}.json'.
     */
    case NATIONAL_CALENDAR_LECTIONARY_FILE = JsonDataConstants::NATIONAL_CALENDAR_LECTIONARY_FILE;

    /**
     * The folder containing diocesan calendars.
     * Evaluates to 'jsondata/sourcedata/rite/roman/calendars/dioceses'.
     */
    case DIOCESAN_CALENDARS_FOLDER = JsonDataConstants::DIOCESAN_CALENDARS_FOLDER;

    /**
     * The file containing the diocesan calendar data, with placeholders for the actual nation and diocese names.
     * Evaluates to 'jsondata/sourcedata/rite/roman/calendars/dioceses/{nation}/{diocese}/{diocese_name}.json'.
     */
    case DIOCESAN_CALENDAR_FILE = JsonDataConstants::DIOCESAN_CALENDAR_FILE;

    /**
     * The folder containing i18n files for diocesan calendars, with placeholders for the actual nation and diocese names.
     * Evaluates to 'jsondata/sourcedata/rite/roman/calendars/dioceses/{nation}/{diocese}/i18n'.
     */
    case DIOCESAN_CALENDAR_I18N_FOLDER = JsonDataConstants::DIOCESAN_CALENDAR_I18N_FOLDER;

    /**
     * The file containing the i18n data for the specified diocesan calendar,
     * with placeholders for the actual nation name, diocese name, and locale.
     * Evaluates to 'jsondata/sourcedata/rite/roman/calendars/dioceses/{nation}/{diocese}/i18n/{locale}.json'.
     */
    case DIOCESAN_CALENDAR_I18N_FILE = JsonDataConstants::DIOCESAN_CALENDAR_I18N_FILE;

    /**
     * The folder containing lectionary data for diocesan calendars, with placeholders for the actual nation and diocese names.
     * Evaluates to 'jsondata/sourcedata/rite/roman/calendars/dioceses/{nation}/{diocese}/lectionary'.
     */
    case DIOCESAN_CALENDAR_LECTIONARY_FOLDER = JsonDataConstants::DIOCESAN_CALENDAR_LECTIONARY_FOLDER;

    /**
     * The file containing the lectionary data for the specified diocesan calendar,
     * with placeholders for the actual nation name, diocese name, and locale.
     * Evaluates to 'jsondata/sourcedata/rite/roman/calendars/dioceses/{nation}/{diocese}/lectionary/{locale}.json'.
     */
    case DIOCESAN_CALENDAR_LECTIONARY_FILE = JsonDataConstants::DIOCESAN_CALENDAR_LECTIONARY_FILE;

    /**
     * The file containing the data for the world dioceses of the Latin Rite.
     * Evaluates to 'jsondata/world_dioceses.json'.
     */
    case CATHOLIC_DIOCESES_LATIN_RITE = JsonDataConstants::CATHOLIC_DIOCESES_LATIN_RITE;

    /**
     * The curated list of officially supported locales.
     * Evaluates to 'jsondata/supportedLocales.json'.
     */
    case SUPPORTED_LOCALES_FILE = JsonDataConstants::SUPPORTED_LOCALES_FILE;

    public function path(): string
    {
        return Router::$apiFilePath . $this->value;
    }

    /**
     * The diocesan-calendar data file template for the given rite.
     *
     * The source tree is partitioned by rite, and only the diocesan tier exists under
     * more than one: there are no `AMBROSIAN_NATIONAL_*` or `AMBROSIAN_WIDER_REGION_*`
     * constants because the Ambrosian rite has neither tier. These resolvers let a call
     * site ask for "the diocesan file for this rite" instead of repeating the branch.
     */
    public static function diocesanCalendarFileFor(Rite $rite): self
    {
        return $rite === Rite::AMBROSIAN
            ? self::AMBROSIAN_DIOCESAN_CALENDAR_FILE
            : self::DIOCESAN_CALENDAR_FILE;
    }

    public static function diocesanCalendarsFolderFor(Rite $rite): self
    {
        return $rite === Rite::AMBROSIAN
            ? self::AMBROSIAN_DIOCESAN_CALENDARS_FOLDER
            : self::DIOCESAN_CALENDARS_FOLDER;
    }

    public static function diocesanCalendarI18nFolderFor(Rite $rite): self
    {
        return $rite === Rite::AMBROSIAN
            ? self::AMBROSIAN_DIOCESAN_CALENDAR_I18N_FOLDER
            : self::DIOCESAN_CALENDAR_I18N_FOLDER;
    }

    public static function diocesanCalendarI18nFileFor(Rite $rite): self
    {
        return $rite === Rite::AMBROSIAN
            ? self::AMBROSIAN_DIOCESAN_CALENDAR_I18N_FILE
            : self::DIOCESAN_CALENDAR_I18N_FILE;
    }

    /**
     * The test-corpus folder for the given rite.
     *
     * Unlike the diocesan resolvers, both partitions always exist: every rite has a test
     * folder, because a rite-level test (`applies_to: {"rite": "ambrosian"}`) needs
     * somewhere to live even where that rite has no national or diocesan tier.
     */
    public static function testsFolderFor(Rite $rite): self
    {
        return $rite === Rite::AMBROSIAN
            ? self::AMBROSIAN_TESTS_FOLDER
            : self::ROMAN_TESTS_FOLDER;
    }
}
