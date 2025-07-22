<?php

namespace LiturgicalCalendar\Api\Enum;

/**
 * An enumeration of possible values for the "Common" field of a liturgical event.
 * The $values array of a "Common" field must contain only values from the $values array
 *  in the current LiturgicalCalendar\Api\Enum\LitCommon class.
 */
enum LitCommon: string
{
    use EnumToArrayTrait;

    case PROPRIO                = 'Proper';
    case DEDICATIONIS_ECCLESIAE = 'Dedication of a Church';
    case BEATAE_MARIAE_VIRGINIS = 'Blessed Virgin Mary';
    case MARTYRUM               = 'Martyrs';
    case PASTORUM               = 'Pastors';
    case DOCTORUM               = 'Doctors';
    case VIRGINUM               = 'Virgins';
    case SANCTORUM_ET_SANCTARUM = 'Holy Men and Women';

    /** MARTYRUM */
    case PRO_UNO_MARTYRE                      = 'For One Martyr';
    case PRO_PLURIBUS_MARTYRIBUS              = 'For Several Martyrs';
    case PRO_MISSIONARIIS_MARTYRIBUS          = 'For Missionary Martyrs';
    case PRO_UNO_MISSIONARIO_MARTYRE          = 'For One Missionary Martyr';
    case PRO_PLURIBUS_MISSIONARIIS_MARTYRIBUS = 'For Several Missionary Martyrs';
    case PRO_VIRGINE_MARTYRE                  = 'For a Virgin Martyr';
    case PRO_SANCTA_MULIERE_MARTYRE           = 'For a Holy Woman Martyr';

    /** PASTORUM */
    case PRO_PAPA                     = 'For a Pope';
    case PRO_EPISCOPO                 = 'For a Bishop';
    case PRO_UNO_PASTORE              = 'For One Pastor';
    case PRO_PLURIBUS_PASTORIBUS      = 'For Several Pastors';
    case PRO_FUNDATORIBUS_ECCLESIARUM = 'For Founders of a Church';
    case PRO_UNO_FUNDATORE            = 'For One Founder';
    case PRO_PLURIBUS_FUNDATORIBUS    = 'For Several Founders';
    case PRO_MISSIONARIIS             = 'For Missionaries';

    /** VIRGINUM */
    case PRO_UNA_VIRGINE         = 'For One Virgin';
    case PRO_PLURIBUS_VIRGINIBUS = 'For Several Virgins';

    /** SANCTORUM_ET_SANCTARUM */
    case PRO_PLURIBUS_SANCTIS                        = 'For Several Saints';
    case PRO_UNO_SANCTO                              = 'For One Saint';
    case PRO_ABBATE                                  = 'For an Abbot';
    case PRO_MONACHO                                 = 'For a Monk';
    case PRO_MONIALI                                 = 'For a Nun';
    case PRO_RELIGIOSIS                              = 'For Religious';
    case PRO_IIS_QUI_OPERA_MISERICORDIAE_EXERCUERUNT = 'For Those Who Practiced Works of Mercy';
    case PRO_EDUCATORIBUS                            = 'For Educators';
    case PRO_SANCTIS_MULIERIBUS                      = 'For Holy Women';

    case NONE = '';

    /**
     * Latin values of the Commons of Saints.
     *
     * @var array<string,string>
     */
    public const LATIN = [
        LitCommon::PROPRIO->name                                     => 'Proprio',
        LitCommon::DEDICATIONIS_ECCLESIAE->name                      => 'Dedicationis ecclesiæ',
        LitCommon::BEATAE_MARIAE_VIRGINIS->name                      => 'Beatæ Mariæ Virginis',
        LitCommon::MARTYRUM->name                                    => 'Martyrum',
        LitCommon::PASTORUM->name                                    => 'Pastorum',
        LitCommon::DOCTORUM->name                                    => 'Doctorum',
        LitCommon::VIRGINUM->name                                    => 'Virginum',
        LitCommon::SANCTORUM_ET_SANCTARUM->name                      => 'Sanctorum et Sanctarum',
        LitCommon::PRO_UNO_MARTYRE->name                             => 'Pro uno martyre',
        LitCommon::PRO_PLURIBUS_MARTYRIBUS->name                     => 'Pro pluribus martyribus',
        LitCommon::PRO_MISSIONARIIS_MARTYRIBUS->name                 => 'Pro missionariis martyribus',
        LitCommon::PRO_UNO_MISSIONARIO_MARTYRE->name                 => 'Pro uno missionario martyre',
        LitCommon::PRO_PLURIBUS_MISSIONARIIS_MARTYRIBUS->name        => 'Pro pluribus missionariis martyribus',
        LitCommon::PRO_VIRGINE_MARTYRE->name                         => 'Pro virgine martyre',
        LitCommon::PRO_SANCTA_MULIERE_MARTYRE->name                  => 'Pro sancta muliere martyre',
        LitCommon::PRO_PAPA->name                                    => 'Pro papa',
        LitCommon::PRO_EPISCOPO->name                                => 'Pro episcopo',
        LitCommon::PRO_UNO_PASTORE->name                             => 'Pro uno pastore',
        LitCommon::PRO_PLURIBUS_PASTORIBUS->name                     => 'Pro pluribus pastoribus',
        LitCommon::PRO_FUNDATORIBUS_ECCLESIARUM->name                => 'Pro fundatoribus ecclesiarum',
        LitCommon::PRO_UNO_FUNDATORE->name                           => 'Pro uno fundatore',
        LitCommon::PRO_PLURIBUS_FUNDATORIBUS->name                   => 'Pro pluribus fundatoribus',
        LitCommon::PRO_MISSIONARIIS->name                            => 'Pro missionariis',
        LitCommon::PRO_UNA_VIRGINE->name                             => 'Pro una virgine',
        LitCommon::PRO_PLURIBUS_VIRGINIBUS->name                     => 'Pro pluribus virginibus',
        LitCommon::PRO_PLURIBUS_SANCTIS->name                        => 'Pro pluribus sanctis',
        LitCommon::PRO_UNO_SANCTO->name                              => 'Pro uno sancto',
        LitCommon::PRO_ABBATE->name                                  => 'Pro abbate',
        LitCommon::PRO_MONACHO->name                                 => 'Pro monacho',
        LitCommon::PRO_MONIALI->name                                 => 'Pro moniali',
        LitCommon::PRO_RELIGIOSIS->name                              => 'Pro religiosis',
        LitCommon::PRO_IIS_QUI_OPERA_MISERICORDIAE_EXERCUERUNT->name => 'Pro iis qui opera misericordiae exercuerunt',
        LitCommon::PRO_EDUCATORIBUS->name                            => 'Pro educatoribus',
        LitCommon::PRO_SANCTIS_MULIERIBUS->name                      => 'Pro sanctis mulieribus',
        LitCommon::NONE->name                                        => '',
    ];


    /** @var LitCommon[] */
    public const COMMUNES_GENERALIS = [
        LitCommon::PROPRIO,
        LitCommon::DEDICATIONIS_ECCLESIAE,
        LitCommon::BEATAE_MARIAE_VIRGINIS,
        LitCommon::MARTYRUM,
        LitCommon::PASTORUM,
        LitCommon::DOCTORUM,
        LitCommon::VIRGINUM,
        LitCommon::SANCTORUM_ET_SANCTARUM
    ];

    /** @var LitCommon[] */
    public const COMMUNE_MARTYRUM = [
        LitCommon::PRO_UNO_MARTYRE,
        LitCommon::PRO_PLURIBUS_MARTYRIBUS,
        LitCommon::PRO_MISSIONARIIS_MARTYRIBUS,
        LitCommon::PRO_UNO_MISSIONARIO_MARTYRE,
        LitCommon::PRO_PLURIBUS_MISSIONARIIS_MARTYRIBUS,
        LitCommon::PRO_VIRGINE_MARTYRE,
        LitCommon::PRO_SANCTA_MULIERE_MARTYRE
    ];

    /** @var LitCommon[] */
    public const COMMUNE_PASTORUM = [
        LitCommon::PRO_PAPA,
        LitCommon::PRO_EPISCOPO,
        LitCommon::PRO_UNO_PASTORE,
        LitCommon::PRO_PLURIBUS_PASTORIBUS,
        LitCommon::PRO_FUNDATORIBUS_ECCLESIARUM,
        LitCommon::PRO_UNO_FUNDATORE,
        LitCommon::PRO_PLURIBUS_FUNDATORIBUS,
        LitCommon::PRO_MISSIONARIIS
    ];

    /** @var LitCommon[] */
    public const COMMUNE_VIRGINUM = [
        LitCommon::PRO_UNA_VIRGINE,
        LitCommon::PRO_PLURIBUS_VIRGINIBUS
    ];

    /** @var LitCommon[] */
    public const COMMUNE_SANCTORUM = [
        LitCommon::PRO_PLURIBUS_SANCTIS,
        LitCommon::PRO_UNO_SANCTO,
        LitCommon::PRO_ABBATE,
        LitCommon::PRO_MONACHO,
        LitCommon::PRO_MONIALI,
        LitCommon::PRO_RELIGIOSIS,
        LitCommon::PRO_IIS_QUI_OPERA_MISERICORDIAE_EXERCUERUNT,
        LitCommon::PRO_EDUCATORIBUS,
        LitCommon::PRO_SANCTIS_MULIERIBUS
    ];

    /**
     * Translates a LitCommon enumeration value to its corresponding localized string representation.
     *
     * @return string The translated string representation of the given LitCommon case.
     */
    public function translate(): string
    {
        return match ($this) {
            /**translators: context = from the Proper of the festivity (as opposed to a Common) */
            LitCommon::PROPRIO => _('Proper'),
            /**translators: context = from the Common of the Dedication of a Church */
            LitCommon::DEDICATIONIS_ECCLESIAE => _('Dedication of a Church'),
            /**translators: context = from the Common of the Blessed Virgin Mary */
            LitCommon::BEATAE_MARIAE_VIRGINIS => _('Blessed Virgin Mary'),
            /**translators: context = from the Common of Martyrs */
            LitCommon::MARTYRUM => _('Martyrs'),
            /**translators: context = from the Common of Pastors */
            LitCommon::PASTORUM => _('Pastors'),
            /**translators: context = from the Common of Doctors */
            LitCommon::DOCTORUM => _('Doctors'),
            /**translators: context = from the Common of Virgins */
            LitCommon::VIRGINUM => _('Virgins'),
            /**translators: context = from the Common of Holy Men and Women */
            LitCommon::SANCTORUM_ET_SANCTARUM => _('Holy Men and Women'),
            /**translators: context = from the Common of Martyrs: For One Martyr */
            LitCommon::PRO_UNO_MARTYRE => _('For One Martyr'),
            /**translators: context = from the Common of Martyrs: For Several Martyrs */
            LitCommon::PRO_PLURIBUS_MARTYRIBUS => _('For Several Martyrs'),
            /**translators: context = from the Common of Martyrs: For Missionary Martyrs */
            LitCommon::PRO_MISSIONARIIS_MARTYRIBUS => _('For Missionary Martyrs'),
            /**translators: context = from the Common of Martyrs: For One Missionary Martyr */
            LitCommon::PRO_UNO_MISSIONARIO_MARTYRE => _('For One Missionary Martyr'),
            /**translators: context = from the Common of Martyrs: For Several Missionary Martyrs */
            LitCommon::PRO_PLURIBUS_MISSIONARIIS_MARTYRIBUS => _('For Several Missionary Martyrs'),
            /**translators: context = from the Common of Martyrs: For a Virgin Martyr */
            LitCommon::PRO_VIRGINE_MARTYRE => _('For a Virgin Martyr'),
            /**translators: context = from the Common of Martyrs: For a Holy Woman Martyr */
            LitCommon::PRO_SANCTA_MULIERE_MARTYRE => _('For a Holy Woman Martyr'),
            /**translators: context = from the Common of Pastors: For a Pope */
            LitCommon::PRO_PAPA => _('For a Pope'),
            /**translators: context = from the Common of Pastors: For a Bishop */
            LitCommon::PRO_EPISCOPO => _('For a Bishop'),
            /**translators: context = from the Common of Pastors: For One Pastor */
            LitCommon::PRO_UNO_PASTORE => _('For One Pastor'),
            /**translators: context = from the Common of Pastors: For Several Pastors */
            LitCommon::PRO_PLURIBUS_PASTORIBUS => _('For Several Pastors'),
            /**translators: context = from the Common of Pastors: For Founders of a Church */
            LitCommon::PRO_FUNDATORIBUS_ECCLESIARUM => _('For Founders of a Church'),
            /**translators: context = from the Common of Pastors: For One Founder */
            LitCommon::PRO_UNO_FUNDATORE => _('For One Founder'),
            /**translators: context = from the Common of Pastors: For Several Founders */
            LitCommon::PRO_PLURIBUS_FUNDATORIBUS => _('For Several Founders'),
            /**translators: context = from the Common of Pastors: For Missionaries */
            LitCommon::PRO_MISSIONARIIS => _('For Missionaries'),
            /**translators: context = from the Common of Virgins: For One Virgin */
            LitCommon::PRO_UNA_VIRGINE => _('For One Virgin'),
            /**translators: context = from the Common of Virgins: For Several Virgins */
            LitCommon::PRO_PLURIBUS_VIRGINIBUS => _('For Several Virgins'),
            /**translators: context = from the Common of Holy Men and Women: For Several Saints */
            LitCommon::PRO_PLURIBUS_SANCTIS => _('For Several Saints'),
            /**translators: context = from the Common of Holy Men and Women: For One Saint */
            LitCommon::PRO_UNO_SANCTO => _('For One Saint'),
            /**translators: context = from the Common of Holy Men and Women: For an Abbot */
            LitCommon::PRO_ABBATE => _('For an Abbot'),
            /**translators: context = from the Common of Holy Men and Women: For a Monk */
            LitCommon::PRO_MONACHO => _('For a Monk'),
            /**translators: context = from the Common of Holy Men and Women: For a Nun */
            LitCommon::PRO_MONIALI => _('For a Nun'),
            /**translators: context = from the Common of Holy Men and Women: For Religious */
            LitCommon::PRO_RELIGIOSIS => _('For Religious'),
            /**translators: context = from the Common of Holy Men and Women: For Those Who Practiced Works of Mercy */
            LitCommon::PRO_IIS_QUI_OPERA_MISERICORDIAE_EXERCUERUNT => _('For Those Who Practiced Works of Mercy'),
            /**translators: context = from the Common of Holy Men and Women: For Educators */
            LitCommon::PRO_EDUCATORIBUS => _('For Educators'),
            /**translators: context = from the Common of Holy Men and Women: For Holy Women */
            LitCommon::PRO_SANCTIS_MULIERIBUS => _('For Holy Women'),
            LitCommon::NONE => LitCommon::NONE->value
        };
    }

    /**
     * Returns glue string for use between "From the Common" and the actual common.
     *
     * If the LitCommon case is not one of the General Commons, an exception will be thrown.
     *
     * For LitCommon::BEATAE_MARIAE_VIRGINIS, returns the singular feminine form "of the".
     *
     * For LitCommon::VIRGINUM, returns the plural feminine form "of".
     *
     * For LitCommon::MARTYRUM, LitCommon::PASTORUM, LitCommon::DOCTORUM, LitCommon::SANCTORUM_ET_SANCTARUM, returns the plural maculine form "of".
     *
     * For LitCommon::DEDICATIONIS_ECCLESIAE, returns the singular feminine form "of the".
     *
     * For all other cases (?), returns the singular masculine form "of the".
     * @return string
     */
    public function possessive(): string
    {
        if (false === in_array($this, LitCommon::COMMUNES_GENERALIS)) {
            throw new \InvalidArgumentException('cannot get a possessive form for LitCommon::' . $this->name . ', only General Commons are supported');
        }

        return match ($this) {
            /**translators: "From the Common [of the] Blessed Virgin Mary|Dedication of a Church" (singular feminine). Latin: leave empty! */
            LitCommon::BEATAE_MARIAE_VIRGINIS, LitCommon::DEDICATIONIS_ECCLESIAE => pgettext('(SING_FEMM)', 'of the'),
            /**translators: "From the Common [of] Virgins" (plural feminine). Latin: leave empty! */
            LitCommon::VIRGINUM => pgettext('(PLUR_FEMM)', 'of'),
            /**translators: "From the Common [of] Martyrs|Pastors|Doctors|Holy Men and Women" (plural masculine). Latin: leave empty! */
            LitCommon::MARTYRUM, LitCommon::PASTORUM, LitCommon::DOCTORUM, LitCommon::SANCTORUM_ET_SANCTARUM => pgettext('(PLUR_MASC)', 'of'),
            /**translators: "From the Common [of the] (no current cases)" (singular masculine). Latin: leave empty! */
            default => pgettext('(SING_MASC)', 'of the')
        };
    }
}
