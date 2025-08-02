<?php

/**
 *  DEFINE THE ORDER OF PRECEDENCE OF THE LITURGICAL DAYS AS INDICATED IN THE
 *  UNIVERSAL NORMS FOR THE LITURGICAL YEAR AND THE GENERAL ROMAN CALENDAR
 *  PROMULGATED BY THE MOTU PROPRIO "MYSTERII PASCHALIS" BY POPE PAUL VI ON FEBRUARY 14 1969
 *  https://w2.vatican.va/content/paul-vi/en/motu_proprio/documents/hf_p-vi_motu-proprio_19690214_mysterii-paschalis.html
 *  A COPY OF THE DOCUMENT IS INCLUDED ALONGSIDE THIS ENGINE, SEEING THAT THERE IS NO DIRECT ONLINE LINK TO THE ACTUAL NORMS
 */

namespace LiturgicalCalendar\Api\Enum;

/*****************************************************
 * DEFINE THE ORDER OF IMPORTANCE OF THE FESTIVITIES *
 ****************************************************/

enum LitGrade: int
{
    use EnumToArrayTrait;

    /**
     * HIGHER SOLEMNITIES
     *
     * I. HIGHER RANKING SOLEMNITIES, THAT HAVE PRECEDENCE OVER ALL OTHERS:
     *    1. EASTER TRIDUUM
     *    2. ↴
     *       * CHRISTMAS, EPIPHANY, ASCENSION, PENTECOST
     *       * SUNDAYS OF ADVENT, LENT AND EASTER
     *       * ASH WEDNESDAY
     *       * DAYS OF HOLY WEEK, FROM MONDAY TO THURSDAY
     *       * DAYS OF THE OCTAVE OF EASTER
     */
    case HIGHER_SOLEMNITY = 7;

    /**
     * SOLEMNITIES
     *
     *    3. SOLEMNITIES OF THE LORD, OF THE BLESSED VIRGIN MARY, OF THE SAINTS LISTED IN THE GENERAL CALENDAR
     *       COMMEMORATION OF THE FAITHFUL DEPARTED
     *    4. PARTICULAR SOLEMNITIES:
     *       * PATRON OF THE PLACE, OF THE COUNTRY OR OF THE CITY (CELEBRATION REQUIRED ALSO FOR RELIGIOUS COMMUNITIES);
     *       * SOLEMNITY OF THE DEDICATION AND OF THE ANNIVERSARY OF THE DEDICATION OF A CHURCH
     *       * SOLEMNITY OF THE TITLE OF A CHURCH
     *       * SOLEMNITY OF THE TITLE OR OF THE FOUNDER OR OF THE MAIN PATRON OF AN ORDER OR OF A CONGREGATION
     */
    case SOLEMNITY = 6;

    /**
     * FEASTS OF THE LORD
     *
     * II. ↴
     *
     *    5. FEASTS OF THE LORD LISTED IN THE GENERAL CALENDAR
     *    6. SUNDAYS OF CHRISTMAS AND OF ORDINARY TIME
     */
    case FEAST_LORD = 5;

    /**
     * FEASTS
     *
     *    7. FEASTS OF THE BLESSED VIRGIN MARY AND OF THE SAINTS IN THE GENERAL CALENDAR
     *    8. PARTICULAR FEASTS:
     *       * MAIN PATRON OF THE DIOCESE
     *       * FEAST OF THE ANNIVERSARY OF THE DEDICATION OF THE CATHEDRAL
     *       * FEAST OF THE MAIN PATRON OF THE REGION OR OF THE PROVINCE, OF THE NATION, OF A LARGER TERRITORY
     *       * FEAST OF THE TITLE, OF THE FOUNDER, OF THE MAIN PATRON OF AN ORDER OR OF A CONGREGATION AND OF A RELIGIOUS PROVINCE
     *       * OTHER PARTICULAR FEASTS OF SOME CHURCH
     *       * OTHER FEASTS LISTED IN THE CALENDAR OF EACH DIOCESE, ORDER OR CONGREGATION
     *    9. ↴
     *       * WEEKDAYS OF ADVENT FROM THE 17th TO THE 24th OF DECEMBER
     *       * DAYS OF THE OCTAVE OF CHRISTMAS
     *       * WEEKDAYS OF LENT
     */
    case FEAST = 4;

    /**
     * OBLIGATORY MEMORIALS
     *
     * III. ↴
     *    10. MEMORIALS OF THE GENERAL CALENDAR
     *    11. PARTICULAR MEMORIALS:
     *        * MEMORIALS OF THE SECONDARY PATRON OF A PLACE, OF A DIOCESE, OF A REGION OR A RELIGIOUS PROVINCE
     *        * OTHER MEMORIALS LISTED IN THE CALENDAR OF EACH DIOCESE, ORDER OR CONGREGATION
     */
    case MEMORIAL = 3;

    /**
     * OPTIONAL MEMORIALS
     *
     *    12. OPTIONAL MEMORIALS, WHICH CAN HOWEVER BE OBSERVED IN DAYS INDICATED AT N. 9,
     *        ACCORDING TO THE NORMS DESCRIBED IN "PRINCIPLES AND NORMS" FOR THE LITURGY OF THE HOURS AND THE USE OF THE MISSAL
     */
    case MEMORIAL_OPT = 2;

    /**
     * COMMEMORATIONS
     *
     *        SIMILARLY MEMORIALS THAT SHOULD FALL DURING THE WEEKDAYS OF LENT CAN BE OBSERVED AS OPTIONAL MEMORIALS
     */
    case COMMEMORATION = 1;

    /**
     * WEEKDAYS
     *
     *   13. ↴
     *
     *       - WEEKDAYS OF ADVENT UNTIL DECEMBER 16th
     *       - WEEKDAYS OF CHRISTMAS, FROM JANUARY 2nd UNTIL THE SATURDAY AFTER EPIPHANY
     *       - WEEKDAYS OF THE EASTER SEASON, FROM THE MONDAY AFTER THE OCTAVE OF EASTER UNTIL THE SATURDAY BEFORE PENTECOST
     *       - WEEKDAYS OF ORDINARY TIME
     */
    case WEEKDAY = 0;

    case INVALID = -1;

    /**
     * Translates a liturgical grade value into a localized string, optionally wrapped in HTML tags.
     *
     * @param LitGrade $value The liturgical grade value to be translated.
     * @param string $locale The locale to use for the translation.
     * @param bool $html Optional parameter to determine if the output should be wrapped in HTML tags.
     *                   Defaults to true.
     * @param bool $abbreviate Optional parameter to determine if the output should be abbreviated.
     *                         Defaults to false.
     * @return string The localized string representing the liturgical grade, potentially wrapped in HTML.
     */
    public static function i18n(LitGrade $value, string $locale, bool $html = true, bool $abbreviate = false): string
    {
        switch ($value) {
            case self::WEEKDAY:
                /**translators: liturgical rank. Keep lowercase  */
                $grade = $locale === LitLocale::LATIN ? 'feria'                 : _('weekday');
                /**translators: liturgical rank 'WEEKDAY' in abbreviated form */
                $gradeAbbr = $locale === LitLocale::LATIN ? 'f'                 : _('w');
                $tags      = ['<I>','</I>'];
                break;
            case self::COMMEMORATION:
                /**translators: liturgical rank. Keep lowercase  */
                $grade = $locale === LitLocale::LATIN ? 'commemoratio'          : _('commemoration');
                /**translators: liturgical rank 'COMMEMORATION' in abbreviated form */
                $gradeAbbr = $locale === LitLocale::LATIN ? 'm*'                : _('m*');
                $tags      = ['<I>','</I>'];
                break;
            case self::MEMORIAL_OPT:
                /**translators: liturgical rank. Keep lowercsase  */
                $grade = $locale === LitLocale::LATIN ? 'memoria ad libitum'    : _('optional memorial');
                /**translators: liturgical rank 'OPTIONAL MEMORIAL' in abbreviated form */
                $gradeAbbr = $locale === LitLocale::LATIN ? 'm'                 : _('m');
                $tags      = ['',''];
                break;
            case self::MEMORIAL:
                /**translators: liturgical rank. Keep Capitalized  */
                $grade = $locale === LitLocale::LATIN ? 'Memoria obligatoria'   : _('Memorial');
                /**translators: liturgical rank 'MEMORIAL' in abbreviated form */
                $gradeAbbr = $locale === LitLocale::LATIN ? 'M'                 : _('M');
                $tags      = ['',''];
                break;
            case self::FEAST:
                /**translators: liturgical rank. Keep UPPERCASE  */
                $grade = $locale === LitLocale::LATIN ? 'FESTUM'                : _('FEAST');
                /**translators: liturgical rank 'FEAST' in abbreviated form */
                $gradeAbbr = $locale === LitLocale::LATIN ? 'F'                 : _('F');
                $tags      = ['',''];
                break;
            case self::FEAST_LORD:
                /**translators: liturgical rank. Keep UPPERCASE  */
                $grade = $locale === LitLocale::LATIN ? 'FESTUM DOMINI'         : _('FEAST OF THE LORD');
                /**translators: liturgical rank 'FEAST OF THE LORD' in abbreviated form */
                $gradeAbbr = $locale === LitLocale::LATIN ? 'F✝'                : _('F✝');
                $tags      = ['<B>','</B>'];
                break;
            case self::SOLEMNITY:
                /**translators: liturgical rank. Keep UPPERCASE  */
                $grade = $locale === LitLocale::LATIN ? 'SOLLEMNITAS'           : _('SOLEMNITY');
                /**translators: liturgical rank 'SOLEMNITY' in abbreviated form */
                $gradeAbbr = $locale === LitLocale::LATIN ? 'S'                 : _('S');
                $tags      = ['<B>','</B>'];
                break;
            case self::HIGHER_SOLEMNITY:
                /**translators: liturgical rank. Keep lowercase  */
                $grade = $locale === LitLocale::LATIN ? 'celebratio altioris ordinis quam sollemnitatis' : _('celebration with precedence over solemnities');
                /**translators: liturgical rank 'HIGHER SOLEMNITY' in abbreviated form */
                $gradeAbbr = $locale === LitLocale::LATIN ? 'S✝'                : _('S✝');
                $tags      = ['<B><I>','</I></B>'];
                break;
            default:
                /**translators: liturgical rank. Keep lowercase  */
                $grade = $locale === LitLocale::LATIN ? 'feria'                 : _('weekday');
                /**translators: liturgical rank 'WEEKDAY' in abbreviated form */
                $gradeAbbr = $locale === LitLocale::LATIN ? 'f'                 : _('w');
                $tags      = ['',''];
        }
        if ($abbreviate) {
            return $html ? $tags[0] . $gradeAbbr . $tags[1] : $gradeAbbr;
        }
        return $html ? $tags[0] . $grade . $tags[1] : $grade;
    }
}
