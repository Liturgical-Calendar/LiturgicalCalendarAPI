<?php

namespace LiturgicalCalendar\Api\Enum;

enum LitMassVariousNeeds: string
{
    use EnumToArrayTrait;

    // I. Pro sancta Ecclesia
    case PRO_ECCLESIA                                      = 'For the Church';                                                      // 1.
    case PRO_PAPA                                          = 'For the Pope';                                                        // 2.
    case PRO_EPISCOPO                                      = 'For the Bishop';                                                      // 3.
    case PRO_ELIGENDO_PAPA_VEL_EPISCOPO                    = 'For the Election of a Pope or a Bishop';                              // 4.
    case PRO_CONCILIO_VEL_SYNODO                           = 'For a Council or a Synod';                                            // 5.
    case PRO_SACERDOTIBUS                                  = 'For Priests';                                                         // 6.
    case PRO_SEIPSO_SACERDOTE                              = 'For the Priest Himself';                                              // 7.
    case IN_ANNIVERSARIO_PROPRIAE_ORDINATIONIS             = 'On the Anniversary of His Ordination';                                // 7.b
    case PRO_MINISTRIS_ECCLESIAE                           = 'For Ministers of the Church';                                         // 8.
    case PRO_VOCATIONIBUS_AD_SACROS_ORDINES                = 'For Vocations to Holy Orders';                                        // 9.
    case PRO_LAICIS                                        = 'For the Laity';                                                       // 10.
    case IN_ANNIVERSARIIS_MATRIMONII_IN_ANNIVERSARIO       = 'On the Anniversaries of Marriage: On Any Anniversary';                // 11.a
    case IN_ANNIVERSARIIS_MATRIMONII_IN_XXV_ANNIVERSARIO   = 'On the Anniversaries of Marriage: On the Twenty-Fifth Anniversary';   // 11.b
    case IN_ANNIVERSARIIS_MATRIMONII_IN_L_ANNIVERSARIO     = 'On the Anniversaries of Marriage: On the Fiftieth Anniversary';       // 11.c
    case PRO_FAMILIA                                       = 'For the Family';                                                      // 12.
    case PRO_RELIGIOSIS                                    = 'For Religious';                                                       // 13.
    case IN_XXV_VEL_L_ANNIVERSARIO_PROFESSIONIS_RELIGIOSAE = 'On the Twenty-Fifth or Fiftieth Anniversary of Religious Profession'; // 13.b
    case PRO_VOCATIONIBUS_AD_VITAM_RELIGIOSAM              = 'For Vocations to Religious Life';                                     // 14.
    case PRO_CONCORDIA_FOVENDA                             = 'For Promoting Harmony';                                               // 15.
    case PRO_RECONCILIATIONE                               = 'For Reconciliation';                                                  // 16.
    case PRO_UNITATE_CHRISTIANORUM                         = 'For the Unity of Christians';                                         // 17.
    case PRO_EVANGELIZATIONE_POPULORUM                     = 'For the Evangelization of Peoples';                                   // 18.
    case PRO_CHRISTIANIS_PERSECUTIONE_VEXATIS              = 'For Persecuted Christians';                                           // 19.
    case IN_CONVENTU_SPIRITUALI_VEL_PASTORALI              = 'For a Spiritual or Pastoral Gathering';                               // 20.

    // II. Pro circumstantiis publicis
    case PRO_PATRIA_VEL_CIVITATE                  = 'For the Nation or State';                                                      // 21.
    case PRO_REM_PUBLICAM_MODERANTIBUS            = 'For Those in Public Office';                                                   // 22.
    case PRO_COETU_MODERATORUM_NATIONUM           = 'For a Governing Assembly';                                                     // 23.
    case PRO_SUPREMO_NATIONIS_MODERATORE_VEL_REGE = 'For the Head of State or Ruler';                                               // 24.
    case INITIO_ANNI_CIVILIS                      = 'At the Beginning of the Civil Year';                                           // 25.
    case PRO_HUMANO_LABORE_SANCTIFICANDO          = 'For the Sanctification of Human Labor';                                        // 26.
    case IN_AGRIS_CONSERENDIS                     = 'At Seedtime';                                                                  // 27.
    case POST_COLLECTOS_FRUCTUS_TERRÆ             = 'After the Harvest';                                                            // 28.
    case PRO_POPULORUM_PROGRESSIONE               = 'For the Progress of Peoples';                                                  // 29.
    case PRO_PACE_ET_IUSTITIA_SERVANDA            = 'For the Preservation of Peace and Justice';                                    // 30.
    case TEMPORE_BELLI_VEL_EVERSIONIS             = 'In Time of War or Civil Disturbance';                                          // 31.
    case PRO_PROFUGIS_ET_EXSULIBUS                = 'For Refugees and Exiles';                                                      // 32.
    case TEMPORE_FAMIS_VEL_PRO_FAME_LABORANTIBUS  = 'In Time of Famine or for Those Suffering Hunger';                              // 33.
    case TEMPORE_TERRAEMOTUS                      = 'In Time of Earthquake';                                                        // 34.
    case AD_PETENDAM_PLUVIAM                      = 'For Rain';                                                                     // 35.
    case AD_POSTULANDAM_AERIS_SERENITATEM         = 'For Fine Weather';                                                             // 36.
    case AD_REPELLENDAS_TEMPESTATES               = 'For an End to Storms';                                                         // 37.

    // III. Ad diversa
    case PRO_REMISSIONE_PECCATORUM                           = 'For the Forgiveness of Sins';                                       // 38.
    case AD_POSTULANDAM_CONTINENTIAM                         = 'For Chastity';                                                      // 39.
    case AD_POSTULANDAM_CARITATEM                            = 'For Charity';                                                       // 40.
    case PRO_FAMILIARIBUS_ET_AMICIS                          = 'For Relatives and Friends';                                         // 41.
    case PRO_AFFLIGENTIBUS_NOS                               = 'For Our Oppressors';                                                // 42.
    case PRO_CAPTIVITATE_DETENTIS                            = 'For Those Held in Captivity';                                       // 43.
    case PRO_DETENTIS_IN_CARCERE                             = 'For Those in Prison';                                               // 44.
    case PRO_INFIRMIS                                        = 'For the Sick';                                                      // 45.
    case PRO_MORIENTIBUS                                     = 'For the Dying';                                                     // 46.
    case AD_POSTULANDAM_GRATIAM_BENE_MORIENDI                = 'For the Grace of a Happy Death';                                    // 47.
    case IN_QUACUMQUE_NECESSITATE                            = 'In Any Need';                                                       // 48.
    case GIVING_THANKS_TO_GOD_FOR_THE_GIFT_OF_HUMAN_LIFE_USA = 'For Giving Thanks to God for the Gift of Human Life [USA]';         // 48./1.
    case PRO_GRATIIS_DEO_REDDENDIS                           = 'For Giving Thanks to God';                                          // 49.

    /** @var LitMassVariousNeeds[] */
    public const PRO_SANCTA_ECCLESIA = [
        LitMassVariousNeeds::PRO_ECCLESIA,
        LitMassVariousNeeds::PRO_PAPA,
        LitMassVariousNeeds::PRO_EPISCOPO,
        LitMassVariousNeeds::PRO_ELIGENDO_PAPA_VEL_EPISCOPO,
        LitMassVariousNeeds::PRO_CONCILIO_VEL_SYNODO,
        LitMassVariousNeeds::PRO_SACERDOTIBUS,
        LitMassVariousNeeds::PRO_SEIPSO_SACERDOTE,
        LitMassVariousNeeds::IN_ANNIVERSARIO_PROPRIAE_ORDINATIONIS,
        LitMassVariousNeeds::PRO_MINISTRIS_ECCLESIAE,
        LitMassVariousNeeds::PRO_VOCATIONIBUS_AD_SACROS_ORDINES,
        LitMassVariousNeeds::PRO_LAICIS,
        LitMassVariousNeeds::IN_ANNIVERSARIIS_MATRIMONII_IN_ANNIVERSARIO,
        LitMassVariousNeeds::IN_ANNIVERSARIIS_MATRIMONII_IN_XXV_ANNIVERSARIO,
        LitMassVariousNeeds::IN_ANNIVERSARIIS_MATRIMONII_IN_L_ANNIVERSARIO,
        LitMassVariousNeeds::PRO_FAMILIA,
        LitMassVariousNeeds::PRO_RELIGIOSIS,
        LitMassVariousNeeds::IN_XXV_VEL_L_ANNIVERSARIO_PROFESSIONIS_RELIGIOSAE,
        LitMassVariousNeeds::PRO_VOCATIONIBUS_AD_VITAM_RELIGIOSAM,
        LitMassVariousNeeds::PRO_CONCORDIA_FOVENDA,
        LitMassVariousNeeds::PRO_RECONCILIATIONE,
        LitMassVariousNeeds::PRO_UNITATE_CHRISTIANORUM,
        LitMassVariousNeeds::PRO_EVANGELIZATIONE_POPULORUM,
        LitMassVariousNeeds::PRO_CHRISTIANIS_PERSECUTIONE_VEXATIS,
        LitMassVariousNeeds::IN_CONVENTU_SPIRITUALI_VEL_PASTORALI
    ];

    /** @var LitMassVariousNeeds[] */
    public const PRO_CIRCUMSTANTIIS_PUBLICIS = [
        LitMassVariousNeeds::PRO_PATRIA_VEL_CIVITATE,
        LitMassVariousNeeds::PRO_REM_PUBLICAM_MODERANTIBUS,
        LitMassVariousNeeds::PRO_COETU_MODERATORUM_NATIONUM,
        LitMassVariousNeeds::PRO_SUPREMO_NATIONIS_MODERATORE_VEL_REGE,
        LitMassVariousNeeds::INITIO_ANNI_CIVILIS,
        LitMassVariousNeeds::PRO_HUMANO_LABORE_SANCTIFICANDO,
        LitMassVariousNeeds::IN_AGRIS_CONSERENDIS,
        LitMassVariousNeeds::POST_COLLECTOS_FRUCTUS_TERRÆ,
        LitMassVariousNeeds::PRO_POPULORUM_PROGRESSIONE,
        LitMassVariousNeeds::PRO_PACE_ET_IUSTITIA_SERVANDA,
        LitMassVariousNeeds::TEMPORE_BELLI_VEL_EVERSIONIS,
        LitMassVariousNeeds::PRO_PROFUGIS_ET_EXSULIBUS,
        LitMassVariousNeeds::TEMPORE_FAMIS_VEL_PRO_FAME_LABORANTIBUS,
        LitMassVariousNeeds::TEMPORE_TERRAEMOTUS,
        LitMassVariousNeeds::AD_PETENDAM_PLUVIAM,
        LitMassVariousNeeds::AD_POSTULANDAM_AERIS_SERENITATEM,
        LitMassVariousNeeds::AD_REPELLENDAS_TEMPESTATES
    ];

    /** @var LitMassVariousNeeds[] */
    public const AD_DIVERSA = [
        LitMassVariousNeeds::PRO_REMISSIONE_PECCATORUM,
        LitMassVariousNeeds::AD_POSTULANDAM_CONTINENTIAM,
        LitMassVariousNeeds::AD_POSTULANDAM_CARITATEM,
        LitMassVariousNeeds::PRO_FAMILIARIBUS_ET_AMICIS,
        LitMassVariousNeeds::PRO_AFFLIGENTIBUS_NOS,
        LitMassVariousNeeds::PRO_CAPTIVITATE_DETENTIS,
        LitMassVariousNeeds::PRO_DETENTIS_IN_CARCERE,
        LitMassVariousNeeds::PRO_INFIRMIS,
        LitMassVariousNeeds::PRO_MORIENTIBUS,
        LitMassVariousNeeds::AD_POSTULANDAM_GRATIAM_BENE_MORIENDI,
        LitMassVariousNeeds::IN_QUACUMQUE_NECESSITATE,
        LitMassVariousNeeds::GIVING_THANKS_TO_GOD_FOR_THE_GIFT_OF_HUMAN_LIFE_USA,
        LitMassVariousNeeds::PRO_GRATIIS_DEO_REDDENDIS
    ];

    /**
     * Latin values of the Masses and Prayers for Various Needs and Occasions.
     *
     * @var array<string,string>
     */
    public const LATIN = [
        LitMassVariousNeeds::PRO_ECCLESIA->name                                        => 'Pro Ecclesia',
        LitMassVariousNeeds::PRO_PAPA->name                                            => 'Pro Papa',
        LitMassVariousNeeds::PRO_EPISCOPO->name                                        => 'Pro Episcopo',
        LitMassVariousNeeds::PRO_ELIGENDO_PAPA_VEL_EPISCOPO->name                      => 'Pro eligendo Papa vel Episcopo',
        LitMassVariousNeeds::PRO_CONCILIO_VEL_SYNODO->name                             => 'Pro Concilio vel Synodo',
        LitMassVariousNeeds::PRO_SACERDOTIBUS->name                                    => 'Pro sacerdotibus',
        LitMassVariousNeeds::PRO_SEIPSO_SACERDOTE->name                                => 'Pro seipso sacerdote',
        LitMassVariousNeeds::IN_ANNIVERSARIO_PROPRIAE_ORDINATIONIS->name               => 'In anniversario propriæ ordinationis',
        LitMassVariousNeeds::PRO_MINISTRIS_ECCLESIAE->name                             => 'Pro ministris Ecclesiæ',
        LitMassVariousNeeds::PRO_VOCATIONIBUS_AD_SACROS_ORDINES->name                  => 'Pro vocationibus ad sacros Ordines',
        LitMassVariousNeeds::PRO_LAICIS->name                                          => 'Pro laicis',
        LitMassVariousNeeds::IN_ANNIVERSARIIS_MATRIMONII_IN_ANNIVERSARIO->name         => 'In anniversariis matrimonii: In anniversario',
        LitMassVariousNeeds::IN_ANNIVERSARIIS_MATRIMONII_IN_XXV_ANNIVERSARIO->name     => 'In anniversariis matrimonii: In XXV anniversario',
        LitMassVariousNeeds::IN_ANNIVERSARIIS_MATRIMONII_IN_L_ANNIVERSARIO->name       => 'In anniversariis matrimonii: In L anniversario',
        LitMassVariousNeeds::PRO_FAMILIA->name                                         => 'Pro familia',
        LitMassVariousNeeds::PRO_RELIGIOSIS->name                                      => 'Pro religiosis',
        LitMassVariousNeeds::IN_XXV_VEL_L_ANNIVERSARIO_PROFESSIONIS_RELIGIOSAE->name   => 'In XXV vel L anniversario professionis religiosae',
        LitMassVariousNeeds::PRO_VOCATIONIBUS_AD_VITAM_RELIGIOSAM->name                => 'Pro vocationibus ad vitam religiosam',
        LitMassVariousNeeds::PRO_CONCORDIA_FOVENDA->name                               => 'Pro concordia fovenda',
        LitMassVariousNeeds::PRO_RECONCILIATIONE->name                                 => 'Pro reconciliatione',
        LitMassVariousNeeds::PRO_UNITATE_CHRISTIANORUM->name                           => 'Pro unitate christianorum',
        LitMassVariousNeeds::PRO_EVANGELIZATIONE_POPULORUM->name                       => 'Pro evangelizatione populorum',
        LitMassVariousNeeds::PRO_CHRISTIANIS_PERSECUTIONE_VEXATIS->name                => 'Pro christianis persecutione vexatis',
        LitMassVariousNeeds::IN_CONVENTU_SPIRITUALI_VEL_PASTORALI->name                => 'In conventu spirituali vel pastorali',
        LitMassVariousNeeds::PRO_PATRIA_VEL_CIVITATE->name                             => 'Pro patria vel civitate',
        LitMassVariousNeeds::PRO_REM_PUBLICAM_MODERANTIBUS->name                       => 'Pro rem publicam moderantibus',
        LitMassVariousNeeds::PRO_COETU_MODERATORUM_NATIONUM->name                      => 'Pro cœtu moderatorum nationum',
        LitMassVariousNeeds::PRO_SUPREMO_NATIONIS_MODERATORE_VEL_REGE->name            => 'Pro supremo nationis moderatore vel rege',
        LitMassVariousNeeds::INITIO_ANNI_CIVILIS->name                                 => 'Initio anni civilis',
        LitMassVariousNeeds::PRO_HUMANO_LABORE_SANCTIFICANDO->name                     => 'Pro humano labore sanctificando',
        LitMassVariousNeeds::IN_AGRIS_CONSERENDIS->name                                => 'In agris conserendis',
        LitMassVariousNeeds::POST_COLLECTOS_FRUCTUS_TERRÆ->name                        => 'Post collectos fructus terræ',
        LitMassVariousNeeds::PRO_POPULORUM_PROGRESSIONE->name                          => 'Pro populorum progressione',
        LitMassVariousNeeds::PRO_PACE_ET_IUSTITIA_SERVANDA->name                       => 'Pro pace et iustitia servanda',
        LitMassVariousNeeds::TEMPORE_BELLI_VEL_EVERSIONIS->name                        => 'Tempore belli vel eversionis',
        LitMassVariousNeeds::PRO_PROFUGIS_ET_EXSULIBUS->name                           => 'Pro profugis et exsulibus',
        LitMassVariousNeeds::TEMPORE_FAMIS_VEL_PRO_FAME_LABORANTIBUS->name             => 'Tempore famis, vel pro fame laborantibus',
        LitMassVariousNeeds::TEMPORE_TERRAEMOTUS->name                                 => 'Tempore terræmotus',
        LitMassVariousNeeds::AD_PETENDAM_PLUVIAM->name                                 => 'Ad petendam pluviam',
        LitMassVariousNeeds::AD_POSTULANDAM_AERIS_SERENITATEM->name                    => 'Ad postulandam æris serenitatem',
        LitMassVariousNeeds::AD_REPELLENDAS_TEMPESTATES->name                          => 'Ad repellendas tempestates',
        LitMassVariousNeeds::PRO_REMISSIONE_PECCATORUM->name                           => 'Pro remissione peccatorum',
        LitMassVariousNeeds::AD_POSTULANDAM_CONTINENTIAM->name                         => 'Ad postulandam continentiam',
        LitMassVariousNeeds::AD_POSTULANDAM_CARITATEM->name                            => 'Ad postulandam caritatem',
        LitMassVariousNeeds::PRO_FAMILIARIBUS_ET_AMICIS->name                          => 'Pro familiaribus et amicis',
        LitMassVariousNeeds::PRO_AFFLIGENTIBUS_NOS->name                               => 'Pro affligentibus nos',
        LitMassVariousNeeds::PRO_CAPTIVITATE_DETENTIS->name                            => 'Pro captivitate detentis',
        LitMassVariousNeeds::PRO_DETENTIS_IN_CARCERE->name                             => 'Pro detentis in carcere',
        LitMassVariousNeeds::PRO_INFIRMIS->name                                        => 'Pro infirmis',
        LitMassVariousNeeds::PRO_MORIENTIBUS->name                                     => 'Pro morientibus',
        LitMassVariousNeeds::AD_POSTULANDAM_GRATIAM_BENE_MORIENDI->name                => 'Ad postulandam gratiam bene moriendi',
        LitMassVariousNeeds::IN_QUACUMQUE_NECESSITATE->name                            => 'In quacumque necessitate',
        LitMassVariousNeeds::GIVING_THANKS_TO_GOD_FOR_THE_GIFT_OF_HUMAN_LIFE_USA->name => 'For Giving Thanks to God for the Gift of Human Life [USA]',
        LitMassVariousNeeds::PRO_GRATIIS_DEO_REDDENDIS->name                           => 'Pro gratiis Deo reddendis'
    ];


    /**
     * Translates a LitMassVariousNeeds enumeration value to its corresponding localized string representation.
     *
     * @return string The translated string representation of the given LitMassVariousNeeds case.
     */
    public function translate(): string
    {
        return match ($this) {
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::PRO_ECCLESIA => _('For the Church'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::PRO_PAPA => _('For the Pope'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::PRO_EPISCOPO => _('For the Bishop'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::PRO_ELIGENDO_PAPA_VEL_EPISCOPO => _('For the Election of a Pope or a Bishop'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::PRO_CONCILIO_VEL_SYNODO => _('For a Council or a Synod'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::PRO_SACERDOTIBUS => _('For Priests'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::PRO_SEIPSO_SACERDOTE => _('For the Priest Himself'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::IN_ANNIVERSARIO_PROPRIAE_ORDINATIONIS => _('On the Anniversary of His Ordination'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::PRO_MINISTRIS_ECCLESIAE => _('For Ministers of the Church'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::PRO_VOCATIONIBUS_AD_SACROS_ORDINES => _('For Vocations to Holy Orders'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::PRO_LAICIS => _('For the Laity'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::IN_ANNIVERSARIIS_MATRIMONII_IN_ANNIVERSARIO => _('On the Anniversaries of Marriage: On Any Anniversary'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::IN_ANNIVERSARIIS_MATRIMONII_IN_XXV_ANNIVERSARIO => _('On the Anniversaries of Marriage: On the Twenty-Fifth Anniversary'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::IN_ANNIVERSARIIS_MATRIMONII_IN_L_ANNIVERSARIO => _('On the Anniversaries of Marriage: On the Fiftieth Anniversary'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::PRO_FAMILIA => _('For the Family'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::PRO_RELIGIOSIS => _('For Religious'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::IN_XXV_VEL_L_ANNIVERSARIO_PROFESSIONIS_RELIGIOSAE => _('On the Twenty-Fifth or Fiftieth Anniversary of Religious Profession'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::PRO_VOCATIONIBUS_AD_VITAM_RELIGIOSAM => _('For Vocations to Religious Life'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::PRO_CONCORDIA_FOVENDA => _('For Promoting Harmony'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::PRO_RECONCILIATIONE => _('For Reconciliation'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::PRO_UNITATE_CHRISTIANORUM => _('For the Unity of Christians'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::PRO_EVANGELIZATIONE_POPULORUM => _('For the Evangelization of Peoples'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::PRO_CHRISTIANIS_PERSECUTIONE_VEXATIS => _('For Persecuted Christians'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::IN_CONVENTU_SPIRITUALI_VEL_PASTORALI => _('For a Spiritual or Pastoral Gathering'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::PRO_PATRIA_VEL_CIVITATE => _('For the Nation or State'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::PRO_REM_PUBLICAM_MODERANTIBUS => _('For Those in Public Office'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::PRO_COETU_MODERATORUM_NATIONUM => _('For a Governing Assembly'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::PRO_SUPREMO_NATIONIS_MODERATORE_VEL_REGE => _('For the Head of State or Ruler'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::INITIO_ANNI_CIVILIS => _('At the Beginning of the Civil Year'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::PRO_HUMANO_LABORE_SANCTIFICANDO => _('For the Sanctification of Human Labor'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::IN_AGRIS_CONSERENDIS => _('At Seedtime'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::POST_COLLECTOS_FRUCTUS_TERRÆ => _('After the Harvest'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::PRO_POPULORUM_PROGRESSIONE => _('For the Progress of Peoples'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::PRO_PACE_ET_IUSTITIA_SERVANDA => _('For the Preservation of Peace and Justice'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::TEMPORE_BELLI_VEL_EVERSIONIS => _('In Time of War or Civil Disturbance'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::PRO_PROFUGIS_ET_EXSULIBUS => _('For Refugees and Exiles'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::TEMPORE_FAMIS_VEL_PRO_FAME_LABORANTIBUS => _('In Time of Famine or for Those Suffering Hunger'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::TEMPORE_TERRAEMOTUS => _('In Time of Earthquake'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::AD_PETENDAM_PLUVIAM => _('For Rain'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::AD_POSTULANDAM_AERIS_SERENITATEM => _('For Fine Weather'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::AD_REPELLENDAS_TEMPESTATES => _('For an End to Storms'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::PRO_REMISSIONE_PECCATORUM => _('For the Forgiveness of Sins'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::AD_POSTULANDAM_CONTINENTIAM => _('For Chastity'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::AD_POSTULANDAM_CARITATEM => _('For Charity'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::PRO_FAMILIARIBUS_ET_AMICIS => _('For Relatives and Friends'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::PRO_AFFLIGENTIBUS_NOS => _('For Our Oppressors'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::PRO_CAPTIVITATE_DETENTIS => _('For Those Held in Captivity'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::PRO_DETENTIS_IN_CARCERE => _('For Those in Prison'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::PRO_INFIRMIS => _('For the Sick'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::PRO_MORIENTIBUS => _('For the Dying'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::AD_POSTULANDAM_GRATIAM_BENE_MORIENDI => _('For the Grace of a Happy Death'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::IN_QUACUMQUE_NECESSITATE => _('In Any Need'),
            /**translators: context = Masses and Prayers for Various Needs and Occasions */
            LitMassVariousNeeds::PRO_GRATIIS_DEO_REDDENDIS => _('For Giving Thanks to God'),
            // Intentionally English only, as this is a USA-specific category
            LitMassVariousNeeds::GIVING_THANKS_TO_GOD_FOR_THE_GIFT_OF_HUMAN_LIFE_USA => LitMassVariousNeeds::GIVING_THANKS_TO_GOD_FOR_THE_GIFT_OF_HUMAN_LIFE_USA->value
        };
    }

    /**
     * Translates the value of the enumeration, prefixed with "MISSÆ ET ORATIONES PRO VARIIS NECESSITATIBUS VEL AD DIVERSA: " in Latin
     * or "Masses and Prayers for Various Needs and Occasions: " in the current language.
     *
     * @param bool $latin Whether to return the Latin version.
     * @return string The translated value.
     */
    public function fullTranslate(bool $latin = false): string
    {
        return $latin
            ? 'MISSÆ ET ORATIONES PRO VARIIS NECESSITATIBUS VEL AD DIVERSA: ' . LitMassVariousNeeds::LATIN[$this->name]
            : _('Masses and Prayers for Various Needs and Occasions') . ': ' . $this->translate();
    }
}
