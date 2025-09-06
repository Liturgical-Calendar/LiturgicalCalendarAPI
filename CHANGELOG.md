# CHANGELOG

## [v5.0](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/releases/tag/v5.0) (Unreleased)

* add support for `PUT`, `PATCH` and `DELETE` requests (issues #284, #265, and #220)
* fix "Feria VII is a bad translation for Sabbato" (issue #310)
* refactor "festivity" to "liturgical_event" (issue #239)
* implement lectionary readings (issues #321, #324, #326)
* fix Feasts of the Lord not suppressing Sundays (issues #324, #327)

## [v4.5](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/releases/tag/v4.5) (March 25th 2025)

* restore wider region calendar `PUT`, `PATCH` and `DELETE` requests with full support for all i18n languages (issues #284, #265, and #220)
Happy Feast of the Annunciation!

## [v4.4](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/releases/tag/v4.4) (March 22nd 2025)

* restore national calendar `PUT`, `PATCH` and `DELETE` requests with full support for all i18n languages (issues #284, #265, and #220)

## [v4.3](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/releases/tag/v4.3) (January 26th 2025)

* realign XML output with latest developments (issue #290)

## [v4.2](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/releases/tag/v4.2) (January 25th 2025)

* realign ICS output with latest developments (issue #288)

## [v4.1](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/releases/tag/v4.1) (January 22nd 2025)

* restore diocesan calendar `PUT`, `PATCH` and `DELETE` requests with full support for all i18n languages (issue #284)

## [v4.0](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/releases/tag/v4.0) (January 3rd 2025)

* package the API source as a composer library for autoload functionality
* move the endpoints from PHP scripts to resource paths, and create a router
* add `YAML` as a response media type
* use JSON resource files for the Tests server in place of PHP Unit defs (remove dependency on PHP Unit)
* fix bug where liturgical commons were not getting localized correctly
* fix XML response, implement XML and ICS validation
* update the OpenAPI schema
* add timing to response output and to response headers
* refactor responses to use snake_case properties and collections (this also fixes `SaintAndrewAp` bug for `year_type=LITURGICAL` in `year=2023`, see issue #249)
* add Calendars for Netherlands and for Canada, kudos to Steven van Roode and to Fr. @chrissherren for the contributions
* add a Dockerfile to easily spin up a Docker container with a local instance of the API
* fix for cases in which Immaculate Heart of Mary is suppressed, see commit 6f16f130fb2df88488f8ad9ddcc5c8961380f387
* fix calculation of weekdays between Epiphany and Baptism of the Lord, see issue [#237](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/237)
* fix Christmas weekdays should not be created when there is an obligatory memorial, see commit a8ca47744582d89aaed195658a40a22145659eee
* fix moving of celebrations by a National Calendar that were suppressed in the General Roman Calendar, see issue [#271](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/271)
* add abbreviated form of the liturgical rank / grade to the `Festivity` output, see issue [#251](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/251)
* use ISO 3166-1 Alpha-2 codes to identify nations, see issue [#231](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/231)
* created an interface that allows to create Unit Tests, see issue [#205](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/205)
* added an index of all dioceses of Latin rite, kudos to Gabriel Chow of gcatholic.org for the contribution
* add Decree of the Congregation for Divine Worship for Italy: Immaculate Conception suppresses 2nd Sunday of Advent, see commit 191d3247838a4da18ce1ab7c0ca2f16a1b2d516e
* add Decree of the Congregation for Divine Worship for Italy: Saint Nicholas obligatory memorial since 2020, see issue [#248](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/248)
* feature: discoverability of supported locales, see issue [#240](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/240)
* feature: all national and diocesan calendars are now multilingual by default, see issue [#150](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/150)
* and a number of other bugfixes, features, and improvements

## [v3.9](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/releases/tag/v3.9) (April 9th 2024)

* update PHP dependencies (`swaggest/json-schema`, `phpunit/phpunit`)
* add swagger validation badge to README
* [remove deprecated LitLocale enums](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/38c04753f3a0e9e7e5815c763029b379d94f9a73)
* better locale handling:
  * [implement Locale::getPrimaryLanguage](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/dec41a1f0a119c21819b3b6ddd66c6d8112ffb6c)
  * [simplify locale validity check](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/21a7b38381e234a577450381ec9a6c3caa7617d5)
  * [fix Locale class letter case](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/61f93879297704208dc388be50a7206d80639884)
* fix: wrong liturgical color for feast of Our Lord Jesus Christ King of the Universe
* add `liturgicalSeason` to `Festivity` output, and `DateTime` and `Timestamp` to the `Metadata` output
* remove unneeded request headers from `Metadata` output
* [avoid defining year cycle for holy week and octave easter](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/2adcd8f9bfab7532a0aa5fa1c8a8cbcec210670d)
* [make sure sat mem BVM only Ordinary Time](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/925241da0bde2b6f532f217412f22867ca70bd41)
* [add isVigilFor key to vigil Masses](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/f1ab902606736ae596480ace6a33479940ad5be9)
* [implement YearType with types LITURGICAL and CIVIL](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/1e3ed76cd273939039baf07d74e27334112d8253)
* output Liturgical Calendar as default (from first Sunday of Advent until Saturday of the 34th Week of Ordinary Time)
* track timing of calculations or read operations for pre-calculated / cached calendars, and add headers to Response:
  * [add X-LitCal-ExecutionTime header](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/0965b8c462447954c1c87f7ee174965c66f34420)
  * [add X-LitCal-Startime and -Endtime headers](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/870dd95d4978cb676db7f37972e8f43754d888cd)
  * [add X-LitCal-Generated header](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/482e641395ef355ae536ab10ebbe6834877bc8ec)
* fix rank Dedication Lateran Basilica
* allow national calendars to adhere to Feast of Jesus Christ Eternal High Priest:
  * [fix issue](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/200b4ddbf8b7e1c5f3f22f3b8e24961a2e1e545a)
  * [issue #96](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/96)
  * [enable Feast Eternal High Priest](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/200b4ddbf8b7e1c5f3f22f3b8e24961a2e1e545a)
  * [update EternalHighPriest setting based on national calendar](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/256409be041825a1acc22dcc6c204e0d760578eb)
* update schemas to account for new settings and new properties in response output

## [v3.8](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/releases/tag/v3.8) (February 1st 2023)

* fix language for iCal / ICS format
* fix bug in LitColor enum (static class variable)
* add empty `displayGrade` property for higher solemnities
* add localized info to Festivity output for liturgical color, common, grade...
* define Divine Mercy Sunday starting from the year 2000
* update OpenAPI schema to v3.1

## [v3.7](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/releases/tag/v3.7) (December 14th 2022)

* fix support for correct ordinal number spelling for any language
* fix "Year I", "Year II" references which should only concern weekdays of Ordinary Time
* fix: in cases where the diocesancalendar parameter was set, but the nationalcalendar parameter was not, the national calendar settings were not being picked up correctly
* fix for Netherlands national calendar data (Ascension on Thursday)

## [v3.6](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/releases/tag/v3.6) (December 13th 2022)

* allow Diocesan calendars to define mobile festivities
* allow Diocesan calendars to define `untilYear` properties for festivities that may have changes after a given year
* remove hardcoding of English, Latin and the 5 main European languages and allow for any possible language
* allow for geographic locales, in order to better identify source data for a given national or diocesan calendar
* remove hardcoding of supported national calendars, and automate the scan for existing calendars

## [v3.5](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/releases/tag/v3.5) (December 4th 2022)

* Fix days before / after Epiphany (handled differently in different national calendars!)
* Add Dutch translation for Netherlands, thanks to Steven van Roode

## [v3.4](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/releases/tag/v3.4) (June 6th 2022)

* Fix issue with Saint Vincent deacon in national calendar for USA • [c27289f](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/c27289f3c893a184d605e8b1a495a48e2e76669d)
* simplify calculation of Vigil Masses • [0afa39b](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/0afa39b838611554d32fd0d1c2a80a11a92ec696)
* add cache-control headers to the Metadata enpoint • [3d77f60](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/3d77f602f29d6a3afaacefb43b3b147ca1dedaef)
* complete move from MySQL tables to JSON source files • [d9c7344](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/d9c73447da1f9997eb0716a6591badc2a0e928ab)
* add DiocesanGroups info to the Metadata endpoint • [a378f16](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/a378f16e5072c4c298b5bf31263222f159148fbb)
* move National Calendar data and Wider Region calendar data to JSON source files:
  • [1716486](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/1716486704a51eca41cdc066e66744c9c832f05b)
  • [4e29877](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/4e298779201e49ab820453be2e8c3f1083082935)
  • [15f16c3](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/15f16c38cd4c1ba0ded4805760f8c369b9584b49)
  • [5b27417](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/5b27417ed32f782c50527f4d7a33df1318f60767)
  • [1247b98](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/1247b98d309a7d7eb936740a4094213b826a9ef5)
  • [cdf8bdb](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/cdf8bdb99f8ddaff52382259ec600a6ec33058a2)
  • [62ad946](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/62ad9461757c4e7e6b7c22a2ed5b0567f6052bd7)
  • [7395ef4](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/7395ef4e75ef0548fcf5dff916cc9b2e77d9f8f6)
  • [e5d010f](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/e5d010f093da4599d9b378396a0b89e7d2763a1f)
  • [9ada5aa](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/9ada5aa092888a1c5ab1df31bc19c185273634f5)
  • [c9646a6](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/c9646a6b24ee9998aed74d077b455231f67183de)
  • [25aa01a](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/25aa01a8ffd4abf2df97f4fd7ce035361310c6bb)
  • [ea5beac](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/ea5beacd4d21c050aaf0629596feef430f4faa5b)
  • [b81b868](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/b81b8681c1d2307d3ad95c0aefc15fca3cb92aa2)
  • [a837f7b](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/a837f7b739e215af6eba2337b513200af778d71c)
  • [7e9e894](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/7e9e894e5320ace19eadcdd656e90f5a7ac5e911)
  • [4d1eca2](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/4d1eca213b11a8231cfa4938908cbcca617b88df)
  • [e86ccda](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/e86ccdace21b6545a2f5c6ea6328ddafe849ba39)
  • [445d22c](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/445d22c865709f5621f1b4a3098d8471109650e0)
  • [a0e693f](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/a0e693f2abdd458c85d0526fbde09e85850be74c)
* add cache-control headers to the Main endpoint
  • [d2ae04d](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/d2ae04de03a2b08360c7ab62e04830c41a34a0f7)
  • [df7e17d](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/df7e17da9e9e331cfb84f6b7ccb4af4c5632bd41)
* add WiderRegions info to the Metadata endpoint  • [b3f567f](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/b3f567f71a7566da8ba20cc943bd4578534c3ac4)
* add methods to FestivityCollection • [a9e1760](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/a9e176092f44da4fe0fe505097bd69ddc5ea614a)
* add year limits to Roman Missals • [0f939ba](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/0f939ba6dcca1866b13721bafc5f519c47d8ed16)
* fix enum validations • [4c595c1](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/4c595c17e1bbf41e6c87e25aa043b97664cbb577) • [c3d2492](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/c3d2492f926faeaca5819e27e0b829369669b16a)
* various fixes
  • [8330fa0](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/8330fa096b7e0a4154dcc6e00a3d7100d81f1896)
  • [6cf80da](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/6cf80da677a203ccfdd0d6b0a97aeebd63b3e011)
  • [a7e85de](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/a7e85de029fec3f2952129b97fc0428c740232cc)
  • [4e0a597](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/4e0a5979552a79382f74edb052f4578333d2a007)
  • [8ee7065](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/8ee7065c334aa93e6fe0a078b312256cad7cf061)
  • [44df76f](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/44df76f230d2595c57a0abe44539efb543f0dc4f)
  • [5638ec6](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/5638ec6950ac5742175d7e6656c18af873b43cfd)
  • [4f7c11f](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/4f7c11f106496a7d31c504444927182676b567c4)
  • [4decc12](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/4decc12dd019a11c2fb0d632df9c34298750f5fa)
  • [5450010](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/54500104b1276c826796542919c53b86fb32c4e6)
  • [e2c1f6c](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/e2c1f6c71b5fbda4757ecd8cef82dda5c570d8f7)
  • [fcb1108](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/fcb11085ca5c7f3bf82b5dc36808e2e57b4cbbdb)
  • [ec772a0](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/ec772a0b228b76d652436ba2c221f4824808b6a6)
  • [d804c7e](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/d804c7e5d686e59d633ee50df76553291df477e9)
  • [a52019e](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/a52019ed070436b5d271b05591d5d2e7f5d93477)
  • [8eadab7](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/8eadab79ea5200535b21a6f7a37257a7a13305e7)
* add Roman Missal info to the Metadata endpoint • [715c28a](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/715c28ac7b8113df0aa2c8ad81092828a13c619b)
* output 404 error for unavailable resources • [0bcbd3b](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/0bcbd3b09bbe4f8e6d6ddae9cdc1c732a824091a)
* define JSON schemas
  • [b66f4d2](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/b66f4d243834a6e19d33505f7ce123ddee651c51)
  • [0db16a7](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/0db16a7748e4d10bd6c10e3491cf5325232080ca)
  • [1574c01](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/1574c01ab4c66bdbcad1f050ea9e2aa580e2d7a1)
  • [a70f383](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/a70f38350e6eca9e44979ad900c1b8e75787bd92)
  • [a9ac10e](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/a9ac10eb96a3a5c409d306812d4d65e98d4b4dd6)
  • [bd2ba8b](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/bd2ba8ba4a8ff99a25002cc6835b44280ee00e60)
  • [fabf7b6](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/fabf7b65a2996695e510768272d84741c2bf8c3e)
  • [8b8ae2a](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/8b8ae2a50ad926b4ebcb932aacc0d66d26eb969b)
  • [85066b8](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/85066b8e946117609c5a944987c943b127d084ea)
  • [8a66bf4](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/8a66bf4ab497f3c0e9dff64262c737a0f12bbb55)
  • [3f79b05](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/3f79b058b666061b71a44b8c3bc7a710c0a25f95)
  • [5e82f30](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/5e82f30e2ca0386a8445080a5173d36a7675dff3)
  • [552eaa3](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/552eaa3a8a08e1872865b030e6006a7d2e3f2ca9)
  • [f682300](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/f6823001940c4fb2b7bc8ad84c26073cc031c1ae)
  • [b48bb72](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/b48bb7274b3dd436ad22fb2187724cec3e665319)
  • [289f4c2](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/289f4c2caf21de6b646b699d6a2e7ae5af054fe1)
  • [1245cfc](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/1245cfcaf9b2014a3cdc63c9d021bd09a2525e31)
  • [a4d8bc4](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/a4d8bc42692d5609e0d4b4057d7f4c8c2ca2dbb7)
  • [1257b9b](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/1257b9b839408f9a7abb665828a2d5bae46ec356)
  • [baef32b](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/baef32b45f91bd7abc05845f1fcb0a91ec62ec3c)
  • [09edca1](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/09edca182dfb11dfd0ab81310a20e3494c931688)
  • [228af9c](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/228af9cbc07ec41004046af44b37101fab410f1f)
* create JSON schema validation
  • [3938a27](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/3938a278a7fe78bdda30d5d77de6766baa9c7ea1)
  • [85702b0](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/85702b023e78159d140cdb301f06d22f0cad4efe)
  • [a296cc5](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/a296cc556ef5d166e5cea4b66c2ddd2fd83334f0)
  • [21f2540](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/21f254090b4be79fef871ca02fb9c7fb4b2f5ebb)
  • [765bac9](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/765bac9eba97910f200d6cc6f0030f0ada17975f)
  • [10d7619](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/10d7619811bef7f55a6498cf3c3351ddfe1f6ae6)
  • [4828724](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/4828724ff4ce90ed66e1572ccec5ede20aa21004)
  • [9cbb8e6](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/9cbb8e637b3785ae2839a75c0eb63434b1232bcb)
  • [16809cb](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/16809cbc78ee9aa2b9155f0347f49af81f7322c3)
  • [a1de05c](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/a1de05c641b6d103b89577ca225da3d95073bb65)
* add more data for National Calendars to the Metadata endpoint • [34ef54f](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/commit/34ef54f06d5183b463cce0305f649c182edca2b3)
* update translations

## [v3.3](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/releases/tag/v3.3) (January 27th 2022)

* move liturgical event data from the 2008 Editio Typica Tertia emendata out from the `LitCalAPI.php`, to a JSON file
* move data for festivities from Decrees of the Congregation of Divine Worship out from the `LitCalAPI.php`, to a JSON file

## [v3.2](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/releases/tag/v3.2) (January 23rd 2022)

* allow full CORS requests from enabled domains
* allow Diocesan overrides for Epiphany, Ascension and Corpus Christi

## [v3.1](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/releases/tag/v3.1) (December 26th 2021)

* bugfix which was missed in the v3.0 release: 86ee62ad68d58736880da2b5b39117dec7386dfc

## [v3.0](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/releases/tag/v3.0) (December 26th 2021)

* all calendar data moved from a MySQL database to JSON files, that can be tracked in the repository
* the Calendar data for the Universal Calendar, as contained in the JSON files, is now translatable to other languages through a Weblate project
* the frontend and any implementations of the API have been moved to their own separate repositories,
   only the API code remains in this repository
* the PHP source code for the API has been completely rewritten, using classes and enum type classes
* all translatable strings in the PHP source code have been ported to `gettext`, and are now managed in a Weblate project
* parameters `diocesanpreset` and `nationalpreset` have been renamed to `diocesancalendar` and `nationalcalendar`
* API now supports POST requests that send JSON in the body instead of Form Data
* Data type can be set through the `Accept` header rather than the `returntype` parameter
* Language can be set through the `Accept-Language` header rather than the `locale` parameter

## [v2.9](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/releases/tag/v2.9) (November 12th 2020)

* adds Vigil Masses for Sundays and Solemnities, including occasional notes by the Congregation for Divine Worship
* add Patron Saints of Europe, applicable for Italian Calendar (and eventually any other national calendar in Europe that may be added in the future)
* add Saturday Memorial of the Blessed Virgin Mary

## [v2.8](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/releases/tag/v2.8) (August 11th 2020)

* adds `diocesanpreset` and `nationalpreset` parameters with relative calendar data
* adds all of the data from the recent Decrees of the Congregation for Divine Worship and verifies integrity with past Decrees
* ensures `Messages` returned are as specific as possible, while trying to keep the code as clean as possible
* adds FullCalendar example

## [v2.7](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/releases/tag/v2.7) (July 28th 2020)

* adds `Messages` array to the data that is generated by the endpoint, justifying the calculations made for the generation of the requested calendar
* fixes an issue with the memorial Saint Jane Frances de Chantal after 1999, when it was decided to move the memorial from Dec. 12 to Aug. 12
  in order to allow Our Lady of Guadalupe on Dec. 12
  (if another more important celebration took place on Dec. 12, Saint Jane Frances was being removed before it could be moved, this is now handled correctly)
* add translations for the Messages array in Italian, English and Latin (please forgive my macaronic latin, it's not at all perfect, it's mostly conjecture,
  I hope to have it proofread at some point)
* update PHP example to display `Messages` array in a table below the generated calendar
* update PHP example to fix parsing of Liturgical colors for memorials with more than one possible Common and more than one possible liturgical color
* fix a few errors in the database as regards liturgical colors for some memorials with more than one possible Common

## [v2.6](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/releases/tag/v2.6) (July 26th 2020)

* integrate the calculation of the liturgical cycle (YEAR A,B,C for festivities and YEAR I,II for weekdays) directly into the engine,
  so that applications that take care of elaborating the data for display don't have to worry about it
* update both examples, PHP and Javascript, to use the new `liturgicalyear` property returned in the JSON data,
  and bring Javascript example up to par with the PHP example (add month cell that spans all events for that month)

## [v2.5](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/releases/tag/v2.5) (July 25th 2020)

* make sure all endpoint parameters can have values with either uppercase or lowercase characters
* fix a few small issues with the ICS data generation

## [v2.4](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/releases/tag/v2.4) (July 24th 2020)

* move as many festivities as possible to the MySQL tables to allow for localization (mobile feasts will be calculated in the script, but still need to be localized)
* add ICS data generation (requires more localization strings, because it is already a form of final display of the data)

## [v2.0](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/releases/tag/2.0) (January 8th 2018)

* separate the display logic from the engine, so that the engine can act as an endpoint
* make the engine return JSON or XML data that the display logic can use to generate a user-friendly representation of the data

## [v1.0](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/releases/tag/1.0) (July 26th 2017)

* proof of concept for the correct generation of a liturgical calendar
* create MySQL table for the Proper of the Saints
