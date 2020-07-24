-- phpMyAdmin SQL Dump
-- version 5.0.2
-- https://www.phpmyadmin.net/
--
-- Host: vps94844.ovh.net
-- Generation Time: Jul 24, 2020 at 06:12 PM
-- Server version: 5.7.25
-- PHP Version: 7.4.8

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `biblegetio`
--

-- --------------------------------------------------------

--
-- Table structure for table `LITURGY__calendar_propriumdesanctis`
--
-- Creation: Jan 06, 2019 at 06:34 PM
-- Last update: Jul 20, 2020 at 07:41 AM
--

CREATE TABLE `LITURGY__calendar_propriumdesanctis` (
  `RECURRENCE_ID` int(11) NOT NULL,
  `MONTH` int(11) NOT NULL,
  `DAY` int(11) NOT NULL,
  `TAG` varchar(50) NOT NULL,
  `NAME_LA` varchar(200) NOT NULL,
  `NAME_EN` varchar(200) NOT NULL,
  `NAME_IT` varchar(200) NOT NULL,
  `GRADE` int(11) NOT NULL,
  `COMMON` varchar(200) NOT NULL,
  `CALENDAR` varchar(50) NOT NULL,
  `COLOR` varchar(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- RELATIONSHIPS FOR TABLE `LITURGY__calendar_propriumdesanctis`:
--   `GRADE`
--       `LITURGY__festivity_grade` -> `IDX`
--   `MONTH`
--       `LITURGY__months` -> `IDX`
--

--
-- Dumping data for table `LITURGY__calendar_propriumdesanctis`
--

INSERT INTO `LITURGY__calendar_propriumdesanctis` (`RECURRENCE_ID`, `MONTH`, `DAY`, `TAG`, `NAME_LA`, `NAME_EN`, `NAME_IT`, `GRADE`, `COMMON`, `CALENDAR`, `COLOR`) VALUES
(2, 1, 2, 'StsBasilGreg', 'Sancti Basilii Magni et Gregorii Nazianzeni, episcoporum et Ecclesiae doctorum', 'Saints Basil the Great and Gregory Nazianzen, bishops and doctors', 'Santi Basilio Magno e Gregorio Nazianzeno, vescovi e dottori', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(4, 1, 7, 'StRayPenyafort', 'Sancti Raimundi de Penyafort, presbyteri', 'Saint Raymond of Penyafort, priest', 'San Raimondo di Peñafort, sacerdote', 2, 'Pastors:For One Pastor', 'GENERAL ROMAN', 'white'),
(5, 1, 13, 'StHilaryPoitiers', 'Sancti Hilarii, episcopi et Ecclesiæ doctoris', 'Saint Hilary of Poitiers, bishop and doctor', 'Sant\'Ilario di Poitiers, vescovo e dottore', 2, 'Pastors:For a Bishop|Doctors', 'GENERAL ROMAN', 'white'),
(6, 1, 17, 'StAnthonyEgypt', 'Sancti Antonii, abbatis', 'Saint Anthony of Egypt, abbot', 'Sant\'Antonio di Egitto, abate', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(7, 1, 20, 'StFabianPope', 'Sancti Fabiani, papae et martyris', 'Saint Fabian, pope and martyr', 'San Fabiano, papa e martire', 2, 'Martyrs:For One Martyr|Pastors:For a Pope', 'GENERAL ROMAN', 'red|white'),
(8, 1, 20, 'StSebastian', 'Sancti Sebastiani, martyris', 'Saint Sebastian, martyr', 'San Sebastiano, martire', 2, 'Martyrs:For One Martyr', 'GENERAL ROMAN', 'red'),
(9, 1, 21, 'StAgnes', 'S. Agnetis, virginis et martyris', 'Saint Agnes, virgin and martyr', 'Sant\'Agnese, vergine e martire', 3, 'Martyrs:For a Virgin Martyr|Virgins:For One Virgin', 'GENERAL ROMAN', 'red|white'),
(10, 1, 22, 'StVincentDeacon', 'S. Vincentii, diaconi et martyris', 'Saint Vincent, deacon and martyr', 'San Vincenzo, diacono e martire', 2, 'Martyrs:For One Martyr', 'GENERAL ROMAN', 'red'),
(11, 1, 24, 'StFrancisDeSales', 'S. Francisci de Sales, episcopi et Ecclesiæ doctoris', 'Saint Francis de Sales, bishop and doctor', 'San Francesco de Sales, vescovo e dottore', 3, 'Pastors:For a Bishop|Doctors', 'GENERAL ROMAN', 'white'),
(12, 1, 25, 'ConversionStPaul', 'In Conversione S. Pauli, Apostoli', 'The Conversion of Saint Paul, apostle', 'Conversione di San Paolo, apostolo', 4, 'Proper', 'GENERAL ROMAN', 'white'),
(13, 1, 26, 'StsTimothyTitus', 'Ss. Timothei et Titi, episcoporum', 'Saints Timothy and Titus, bishops', 'Santi Timoteo e Tito, vescovi', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(14, 1, 27, 'StAngelaMerici', 'S. Angelæ Merici, virginis', 'Saint Angela Merici, virgin', 'Sant\'Angela Merici, vergine', 2, 'Virgins:For One Virgin|Holy Men and Women:For Educators', 'GENERAL ROMAN', 'white'),
(15, 1, 28, 'StThomasAquinas', 'S. Thomæ de Aquino, presbyteri et Ecclesiæ doctoris', 'Saint Thomas Aquinas, priest and doctor', 'San Tommaso d\'Aquino, sacerdote e dottore', 3, 'Doctors|Pastors:For One Pastor', 'GENERAL ROMAN', 'white'),
(16, 1, 31, 'StJohnBosco', 'S. Ioannis Bosco, presbyteri', 'Saint John Bosco, priest', 'San Giovanni Bosco, sacerdote', 3, 'Pastors:For One Pastor|Holy Men and Women:For Educators', 'GENERAL ROMAN', 'white'),
(17, 2, 2, 'Presentation', 'In Præsentatione Domini', 'Presentation of the Lord', 'Presentazione del Signore', 5, 'Proper', 'GENERAL ROMAN', 'white'),
(18, 2, 3, 'StBlase', 'S. Blasii, episcopi et martyris', 'Saint Blase, bishop and martyr', 'San Biagio, vescovo e martire', 2, 'Martyrs:For One Martyr|Pastors:For a Bishop', 'GENERAL ROMAN', 'red|white'),
(19, 2, 3, 'StAnsgar', 'S. Ansgarii, episcopi', 'Saint Ansgar, bishop', 'Sant\'Oscar, vescovo', 2, 'Pastors:For Missionaries|Pastors:For a Bishop', 'GENERAL ROMAN', 'white'),
(20, 2, 5, 'StAgatha', 'S. Agathæ, virginis et martyris', 'Saint Agatha, virgin and martyr', 'Sant\'Agata, vergine e martire', 3, 'Martyrs:For a Virgin Martyr|Virgins:For One Virgin', 'GENERAL ROMAN', 'red'),
(21, 2, 6, 'StsPaulMiki', 'Ss. Pauli Miki et sociorum, martyrum', 'Saints Paul Miki and companions, martyrs', 'Santi Paolo Miki e soci, martiri', 3, 'Martyrs:For Several Martyrs', 'GENERAL ROMAN', 'red'),
(22, 2, 8, 'StJeromeEmiliani', 'S. Hieronymi Emiliani', 'Saint Jerome Emiliani, priest', 'San Girolamo Emiliani', 2, 'Holy Men and Women:For Educators', 'GENERAL ROMAN', 'white'),
(24, 2, 10, 'StScholastica', 'S. Scholasticæ, virginis', 'Saint Scholastica, virgin', 'Santa Scolastica, vergine', 3, 'Virgins:For One Virgin|Holy Men and Women:For a Nun', 'GENERAL ROMAN', 'white'),
(25, 2, 11, 'LadyLourdes', 'Beatæ Mariæ Virginis de Lourdes', 'Our Lady of Lourdes', 'Beata Maria Vergine di Lourdes', 2, 'Blessed Virgin Mary', 'GENERAL ROMAN', 'white'),
(26, 2, 14, 'StsCyrilMethodius', 'Ss. Cyrilli, monachi, et Methodii, episcopi', 'Saints Cyril, monk, and Methodius, bishop', 'Santi Cirillo, monaco, e Metodio, vescovo', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(27, 2, 17, 'SevenHolyFounders', 'Ss. septem Fundatorum Ordinis Servorum B. M. V.', 'Seven Holy Founders of the Servite Order', 'Santi Sette Fondatori dei Servi della Beata Maria Vergine', 2, 'Holy Men and Women:For Religious', 'GENERAL ROMAN', 'white'),
(28, 2, 21, 'StPeterDamian', 'S. Petri Damiani, episcopi et Ecclesiæ doctoris', 'Saint Peter Damian, bishop and doctor of the Church', 'San Pietro Damiani, vescovo e dottore della Chiesa', 2, 'Doctors|Pastors:For a Bishop', 'GENERAL ROMAN', 'white'),
(29, 2, 22, 'ChairStPeter', 'Cathedræ S. Petri, Apostoli', 'Chair of Saint Peter, apostle', 'Cattedra di San Pietro, apostolo', 4, 'Proper', 'GENERAL ROMAN', 'white'),
(30, 2, 23, 'StPolycarp', 'S. Polycarpi, episcopi et martyris', 'Saint Polycarp, bishop and martyr', 'San Policarpo, vescovo e martire', 3, 'Martyrs:For One Martyr|Pastors:For a Bishop', 'GENERAL ROMAN', 'red|white'),
(31, 3, 4, 'StCasimir', 'S. Casimiri', 'Saint Casimir', 'San Casimiro', 2, 'Holy Men and Women:For One Saint', 'GENERAL ROMAN', 'white'),
(32, 3, 7, 'StsPerpetuaFelicity', 'Ss.Perpetuæ et Felicitatis, martyrum', 'Saints Perpetua and Felicity, martyrs', 'Sante Perpetua e Felicita, martiri', 3, 'Proper', 'GENERAL ROMAN', 'red'),
(33, 3, 8, 'StJohnGod', 'S. Ioannis a Deo, religiosi', 'Saint John of God, religious', 'San Giovanni di Dio, religioso', 2, 'Holy Men and Women:For Religious|Holy Men and Women:For Those Who Practiced Works of Mercy', 'GENERAL ROMAN', 'white'),
(34, 3, 9, 'StFrancesRome', 'S. Franciscæ Romanæ, religiosæ', 'Saint Frances of Rome, religious', 'Santa Francesca Romana, religiosa', 2, 'Holy Men and Women:For Holy Women|Holy Men and Women:For Religious', 'GENERAL ROMAN', 'white'),
(35, 3, 17, 'StPatrick', 'S. Patricii, episcopi', 'Saint Patrick, bishop', 'San Patrizio, vescovo', 2, 'Pastors:For Missionaries|Pastors:For a Bishop', 'GENERAL ROMAN', 'white'),
(36, 3, 18, 'StCyrilJerusalem', 'S. Cyrilli Hierosolymitani, episcopi et Ecclesiæ doctoris', 'Saint Cyril of Jerusalem, bishop and doctor', 'San Cirillo di Gerusalemme, vescovo e dottore', 2, 'Pastors:For a Bishop|Doctors', 'GENERAL ROMAN', 'white'),
(37, 3, 19, 'StJoseph', 'S. Ioseph Sponsi Beatæ Mariæ Virginis', 'Saint Joseph Husband of the Blessed Virgin Mary', 'San Giuseppe Sposo della Beata Vergine Maria', 6, 'Proper', 'GENERAL ROMAN', 'white'),
(38, 3, 23, 'StTuribius', 'S. Turibii de Mongrovejo, episcopi', 'Saint Turibius of Mogrovejo, bishop', 'San Turibio di Mongrovejo, vescovo', 2, 'Pastors:For a Bishop', 'GENERAL ROMAN', 'white'),
(39, 3, 25, 'Annunciation', 'In Annuntiatione Domini', 'Annunciation of the Lord', 'Annunciazione del Signore', 6, 'Proper', 'GENERAL ROMAN', 'white'),
(40, 4, 2, 'StFrancisPaola', 'S. Francisci de Paola, eremitæ', 'Saint Francis of Paola, hermit', 'San Francesco di Paola, eremita', 2, 'Holy Men and Women:For Religious', 'GENERAL ROMAN', 'white'),
(41, 4, 4, 'StIsidore', 'S. Isidori, episcopi et Ecclesiæ doctoris', 'Saint Isidore, bishop and doctor of the Church', 'Sant\'Isidoro, vescovo e dottore della Chiesa', 2, 'Pastors:For a Bishop|Doctors', 'GENERAL ROMAN', 'white'),
(42, 4, 5, 'StVincentFerrer', 'S. Vincentii Ferrer, presbyteri', 'Saint Vincent Ferrer, priest', 'San Vincenzo Ferrer, sacerdote', 2, 'Pastors:For Missionaries', 'GENERAL ROMAN', 'white'),
(43, 4, 7, 'StJohnBaptistDeLaSalle', 'S. Ioannis Baptistæ de la Salle, presbyteri', 'Saint John Baptist de la Salle, priest', 'San Giovanni Battista de la Salle, sacerdote', 3, 'Pastors:For One Pastor|Holy Men and Women:For Educators', 'GENERAL ROMAN', 'white'),
(44, 4, 11, 'StStanislaus', 'S. Stanislai, episcopi et martyris', 'Saint Stanislaus, bishop and martyr', 'San Stanislao, vescovo e martire', 3, 'Martyrs:For One Martyr|Pastors:For a Bishop', 'GENERAL ROMAN', 'red|white'),
(45, 4, 13, 'StMartinPope', 'S. Martini I, papæ et martyris', 'Saint Martin I, pope and martyr', 'San Martino I, papa e martire', 2, 'Martyrs:For One Martyr|Pastors:For a Pope', 'GENERAL ROMAN', 'red|white'),
(46, 4, 21, 'StAnselm', 'S. Anselmi, episcopi et Ecclesiæ doctoris', 'Saint Anselm of Canterbury, bishop and doctor of the Church', 'Sant\'Anselmo di Canterbury, vescovo e dottore della Chiesa', 2, 'Pastors:For a Bishop|Doctors', 'GENERAL ROMAN', 'red|white'),
(47, 4, 23, 'StGeorge', 'S. Georgii, martyris', 'Saint George, martyr', 'San Giorgio, martire', 2, 'Martyrs:For One Martyr', 'GENERAL ROMAN', 'red|white'),
(49, 4, 24, 'StFidelisSigmaringen', 'S. Fidelis de Sigmaringen, presbyteri et martyris', 'Saint Fidelis of Sigmaringen, priest and martyr', 'San Fedele di Sigmaringen, sacerdote e martire', 2, 'Martyrs:For One Martyr|Pastors:For One Pastor', 'GENERAL ROMAN', 'red|white'),
(50, 4, 25, 'StMarkEvangelist', 'S. Marci, evangelistæ', 'Saint Mark the Evangelist', 'San Marco Evangelista', 4, 'Proper', 'GENERAL ROMAN', 'red'),
(51, 4, 28, 'StPeterChanel', 'S. Petri Chanel, presbyteri et martyris', 'Saint Peter Chanel, priest and martyr', 'San Pietro Chanel, sacerdote e martire', 2, 'Martyrs:For One Martyr|Pastors:For Missionaries', 'GENERAL ROMAN', 'red|white'),
(53, 4, 29, 'StCatherineSiena', 'S. Catharinæ Senensis, virginis', 'Saint Catherine of Siena, virgin and doctor of the Church', 'Santa Caterina di Siena, vergine e dottore della Chiesa', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(54, 4, 30, 'StPiusV', 'S. Pii V, papæ', 'Saint Pius V, pope', 'San Pio V, papa', 2, 'Pastors:For a Pope', 'GENERAL ROMAN', 'white'),
(55, 5, 1, 'StJosephWorker', 'S. Ioseph opificis', 'Saint Joseph the Worker', 'San Giuseppe Lavoratore', 2, 'Proper', 'GENERAL ROMAN', 'white'),
(56, 5, 2, 'StAthanasius', 'S. Athanasii, episcopi et Ecclesiæ doctoris', 'Saint Athanasius, bishop and doctor', 'Sant\'Atanasio, vescovo e dottore', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(57, 5, 3, 'StsPhilipJames', 'Ss. Philippi et Iacobi, Apostolorum', 'Saints Philip and James, Apostles', 'Santi Filippo e Giacomo, apostoli', 4, 'Proper', 'GENERAL ROMAN', 'red'),
(58, 5, 12, 'StsNereusAchilleus', 'Ss. Nerei et Achillei, martyrum', 'Saints Nereus and Achilleus, martyrs', 'Santi Nereo e Achille, martiri', 2, 'Martyrs:For Several Martyrs', 'GENERAL ROMAN', 'red'),
(59, 5, 12, 'StPancras', 'S. Pancratii, martyris', 'Saint Pancras, martyr', 'San Pancrazio, martire', 2, 'Martyrs:For One Martyr', 'GENERAL ROMAN', 'red'),
(61, 5, 14, 'StMatthiasAp', 'S. Matthiæ, Apostoli', 'Saint Matthias the Apostle', 'San Mattia, apostolo', 4, 'Proper', 'GENERAL ROMAN', 'red'),
(62, 5, 18, 'StJohnIPope', 'S. Ioannis I, papæ et martyris', 'Saint John I, pope and martyr', 'San Giovanni I, papa e martire', 2, 'Martyrs:For One Martyr|Pastors:For a Pope', 'GENERAL ROMAN', 'red|white'),
(63, 5, 20, 'StBernardineSiena', 'S. Bernardini Senensis, presbyteri', 'Saint Bernardine of Siena, priest', 'San Bernardino da Siena, sacerdote', 2, 'Pastor:For Missonaries|Holy Men and Women:For Religious', 'GENERAL ROMAN', 'white'),
(66, 5, 25, 'StBedeVenerable', 'S. Bedæ Venerabilis, presbyteri et Ecclesiæ doctoris', 'Saint Bede the Venerable, priest and doctor', 'San Beda il Venerabile, sacerdote e dottore', 2, 'Doctors|Holy Men and Women:For a Monk', 'GENERAL ROMAN', 'white'),
(67, 5, 25, 'StGregoryVII', 'S. Gregorii VII, papæ', 'Saint Gregory VII, pope', 'San Gregorio VII, papa', 2, 'Pastors:For a Pope', 'GENERAL ROMAN', 'white'),
(68, 5, 25, 'StMaryMagdalenePazzi', 'S. Mariæ Magdalenæ de\' Pazzi, virginis', 'Saint Mary Magdalene de Pazzi, virgin', 'Santa Maria Maddalena de Pazzi, vergine', 2, 'Virgins:For One Virgin|Holy Men and Women:For Religious', 'GENERAL ROMAN', 'white'),
(69, 5, 26, 'StPhilipNeri', 'S. Philippi Neri, presbyteri', 'Saint Philip Neri, priest', 'San Filippo Neri, sacerdote', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(70, 5, 27, 'StAugustineCanterbury', 'S. Augustini Cantuariensis, episcopi', 'Saint Augustine of Canterbury, bishop', 'Sant\'Agostino di Canterbury, vescovo', 2, 'Pastors:For Missionaries|Pastors:For a Bishop', 'GENERAL ROMAN', 'white'),
(71, 5, 31, 'Visitation', 'In Visitatione Beatæ Mariæ Virginis', 'Visitation of the Blessed Virgin Mary', 'Visitazione della Beata Vergine Maria', 4, 'Proper', 'GENERAL ROMAN', 'white'),
(72, 6, 1, 'StJustinMartyr', 'S. Iustini, martyris', 'Saint Justin Martyr', 'San Giustino martire', 3, 'Proper', 'GENERAL ROMAN', 'red'),
(73, 6, 2, 'StsMarcellinusPeter', 'Ss. Marcellini et Petri, martyrum', 'Saints Marcellinus and Peter, martyrs', 'Santi Marcellino e Pietro, martiri', 2, 'Martyrs:For Several Martyrs', 'GENERAL ROMAN', 'red'),
(74, 6, 3, 'StsCharlesLwanga', 'Ss. Caroli Lwanga et sociorum, martyrum', 'Saints Charles Lwanga and companions, martyrs', 'Santi Carlo Lwanga e compagni, martiri', 3, 'Proper', 'GENERAL ROMAN', 'red'),
(75, 6, 5, 'StBoniface', 'S. Bonifatii, episcopi et martyris', 'Saint Boniface, bishop and martyr', 'San Bonifacio, vescovo e martire', 3, 'Martyrs:For One Martyr|Pastors:For Missionaries', 'GENERAL ROMAN', 'red|white'),
(76, 6, 6, 'StNorbert', 'S. Norberti, episcopi', 'Saint Norbert, bishop', 'San Norberto', 2, 'Pastors:For a Bishop|Holy Men and Women:For Religious', 'GENERAL ROMAN', 'white'),
(77, 6, 9, 'StEphrem', 'S. Ephræm, diaconi et Ecclesiæ doctoris', 'Saint Ephrem, deacon and doctor', 'Sant\'Efrem, diacono e dottore', 2, 'Doctors', 'GENERAL ROMAN', 'white'),
(78, 6, 11, 'StBarnabasAp', 'S. Barnabæ, apostoli', 'Saint Barnabas the Apostle', 'San Barnaba, apostolo', 3, 'Proper', 'GENERAL ROMAN', 'red'),
(79, 6, 13, 'StAnthonyPadua', 'S. Antonii de Padova, presbyteri et Ecclesiæ doctoris', 'Saint Anthony of Padua, priest and doctor', 'Sant\'Antonio da Padova, sacerdote e dottore', 3, 'Pastors:For One Pastor|Doctors|Holy Men and Women:For Religious', 'GENERAL ROMAN', 'white'),
(80, 6, 19, 'StRomuald', 'S. Romualdi, abbatis', 'Saint Romuald, abbot', 'San Romualdo, abate', 2, 'Holy Men and Women:For an Abbot', 'GENERAL ROMAN', 'white'),
(81, 6, 21, 'StAloysiusGonzaga', 'S. Aloisii Gonzaga, religiosi', 'Saint Aloysius Gonzaga, religious', 'San Luigi Gonzaga, religioso', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(82, 6, 22, 'StPaulinusNola', 'S. Paulini Nolani, episcopi', 'Saint Paulinus of Nola, bishop', 'San Paolino da Nola, vescovo', 2, 'Pastors:For a Bishop', 'GENERAL ROMAN', 'white'),
(83, 6, 22, 'StsJohnFisherThomasMore', 'Ss. Ioannis Fisher, episcopi, et Thomæ More, martyrum', 'Saints John Fisher, bishop and martyr and Thomas More, martyr', 'Santi Giovanni Fisher, vescovo e martire e Tommaso Moro, martire', 2, 'Martyrs:For Several Martyrs', 'GENERAL ROMAN', 'red'),
(84, 6, 24, 'NativityJohnBaptist', 'In Nativitate S. Ioannis Baptistæ', 'Nativity of Saint John the Baptist', 'Natività di San Giovanni Battista', 6, 'Proper', 'GENERAL ROMAN', 'white'),
(85, 6, 27, 'StCyrilAlexandria', 'S. Cyrilli Alexandrini, episcopi et Ecclesiæ doctoris', 'Saint Cyril of Alexandria, bishop and doctor', 'San Cirillo di Alessandria, vescovo e dottore', 2, 'Pastors:For a Bishop|Doctors', 'GENERAL ROMAN', 'white'),
(86, 6, 28, 'StIrenaeus', 'S. Irenæi, episcopi et martyris', 'Saint Irenaeus, bishop and martyr', 'Sant\'Ireneo, vescovo e martire', 3, 'Proper', 'GENERAL ROMAN', 'red|white'),
(87, 6, 29, 'StsPeterPaulAp', 'Ss. Petri et Pauli, Apostolorum', 'Saints Peter and Paul, Apostles', 'Santi Pietro e Paolo, Apostoli', 6, 'Proper', 'GENERAL ROMAN', 'red'),
(88, 6, 30, 'FirstMartyrsRome', 'Ss. Protomartyrum sanctæ Romanæ Ecclesiæ', 'First Martyrs of the Church of Rome', 'Santi Protomartiri della Chiesa di Roma', 2, 'Martyrs:For Several Martyrs', 'GENERAL ROMAN', 'red'),
(89, 7, 3, 'StThomasAp', 'S. Thomæ, Apostoli', 'Saint Thomas the Apostle', 'San Tommaso Apostolo', 4, 'Proper', 'GENERAL ROMAN', 'red'),
(90, 7, 4, 'StElizabethPortugal', 'S. Elisabeth Lusitaniæ', 'Saint Elizabeth of Portugal', 'Sant\'Elisabetta da Portogallo', 2, 'Holy Men and Women:For Those Who Practiced Works of Mercy', 'GENERAL ROMAN', 'white'),
(91, 7, 5, 'StAnthonyZaccaria', 'S. Antonii Mariæ Zaccaria, presbyteri', 'Saint Anthony Zaccaria, priest', 'Sant\'Antonio Zaccaria, sacerdote', 2, 'Pastors:For One Pastor|Holy Men and Women:For Educators|Holy Men and Women:For Religious', 'GENERAL ROMAN', 'white'),
(92, 7, 6, 'StMariaGoretti', 'S. Mariæ Goretti, virginis et martyris', 'Saint Maria Goretti, virgin and martyr', 'Santa Maria Goretti, vergine e martire', 2, 'Martyrs:For a Virgin Martyr|Virgins:For One Virgin', 'GENERAL ROMAN', 'red|white'),
(94, 7, 11, 'StBenedict', 'S. Benedicti, abbatis', 'Saint Benedict, abbot', 'San Benedetto, abate', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(95, 7, 13, 'StHenry', 'S. Henrici', 'Saint Henry', 'Sant\'Enrico', 2, 'Holy Men and Women:For One Saint', 'GENERAL ROMAN', 'white'),
(96, 7, 14, 'StCamillusDeLellis', 'S. Camilli de Lellis, presbyteri', 'Saint Camillus de Lellis, priest', 'San Camillo de Lellis, sacerdote', 2, 'Holy Men and Women:For Those Who Practiced Works of Mercy', 'GENERAL ROMAN', 'white'),
(97, 7, 15, 'StBonaventure', 'S. Bonaventuræ, episcopi et Ecclesiæ doctoris', 'Saint Bonaventure, bishop and doctor', 'San Bonaventura, vescovo e dottore', 3, 'Pastors:For a Bishop|Doctors', 'GENERAL ROMAN', 'white'),
(98, 7, 16, 'LadyMountCarmel', 'Beatæ Mariæ Virginis de Monte Carmelo', 'Our Lady of Mount Carmel', 'Beata Maria Vergine del Monte Carmelo', 2, 'Blessed Virgin Mary', 'GENERAL ROMAN', 'white'),
(100, 7, 21, 'StLawrenceBrindisi', 'S. Laurentii de Brindisi, presbyteri et Ecclesiæ doctoris', 'Saint Lawrence of Brindisi, priest and doctor', 'San Lorenzo da Brindisi, sacerdote e dottore', 2, 'Pastors:For One Pastor|Doctors|Holy Men and Women:For Religious', 'GENERAL ROMAN', 'white'),
(101, 7, 22, 'StMaryMagdalene', 'S. Mariæ Magdalenæ', 'Saint Mary Magdalene', 'Santa Maria Maddalena', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(102, 7, 23, 'StBridget', 'S. Brigittæ, religiosæ', 'Saint Bridget, religious', 'Santa Brigida, religiosa', 2, 'Holy Men and Women:For Holy Women|Holy Men and Women:For Religious', 'GENERAL ROMAN', 'white'),
(104, 7, 25, 'StJamesAp', 'S. Iacobi, Apostoli', 'Saint James, apostle', 'San Giacomo, apostolo', 4, 'Proper', 'GENERAL ROMAN', 'red'),
(105, 7, 26, 'StsJoachimAnne', 'Ss. Ioachim et Annæ, parentum beatæ Mariæ Virginis', 'Saints Joachim and Anne', 'Santi Gioacchino e Anna', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(106, 7, 29, 'StMartha', 'S. Marthæ', 'Saint Martha', 'Santa Marta', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(107, 7, 30, 'StPeterChrysologus', 'S. Petri Chrysologui, episcopi et Ecclesiæ doctoris', 'Saint Peter Chrysologus, bishop and doctor', 'San Pietro Crisologo, vescovo e dottore', 2, 'Pastors:For a Bishop|Doctors', 'GENERAL ROMAN', 'white'),
(108, 7, 31, 'StIgnatiusLoyola', 'S. Ignatii de Loyola, presbyteri', 'Saint Ignatius of Loyola, priest', 'Sant\'Ignazio da Loyola, sacerdote', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(109, 8, 1, 'StAlphonsusMariaDeLiguori', 'S. Alfonsi Mariæ de\' Liguori, episcopi et Ecclesiæ doctoris', 'Saint Alphonsus Maria de Liguori, bishop and doctor of the Church', 'Sant\'Alfonso Maria de Liguori, vescovo e dottore', 3, 'Pastors:For a Bishop|Doctors', 'GENERAL ROMAN', 'white'),
(110, 8, 2, 'StEusebius', 'S. Eusebii Vercellensis, episcopi', 'Saint Eusebius of Vercelli, bishop', 'Sant\'Eusebio da Vercelli, vescovo', 2, 'Pastors:For a Bishop', 'GENERAL ROMAN', 'white'),
(112, 8, 4, 'StJeanVianney', 'S. Ioannis Mariæ Vianney, presbyteri', 'Saint Jean Vianney (the Curé of Ars), priest', 'San Giovanni Vianney (il Curato d\'Ars), sacerdote', 3, 'Pastors:For One Pastor', 'GENERAL ROMAN', 'white'),
(113, 8, 5, 'DedicationStMaryMajor', 'In Dedicatione basilicæ S. Mariæ', 'Dedication of the Basilica of Saint Mary Major', 'Dedicazione della Basilica di Santa Maria Maggiore', 2, 'Blessed Virgin Mary', 'GENERAL ROMAN', 'white'),
(114, 8, 6, 'Transfiguration', 'In Transfiguratione Domini', 'Transfiguration of the Lord', 'Trasfigurazione del Signore', 5, 'Proper', 'GENERAL ROMAN', 'white'),
(115, 8, 7, 'StSixtusIIPope', 'Ss. Xysti II, papæ, et sociorum, martyrum', 'Saint Sixtus II, pope, and companions, martyrs', 'Santi Sisto II, papa, e compagni martiri', 2, 'Martyrs:For Several Martyrs', 'GENERAL ROMAN', 'red'),
(116, 8, 7, 'StCajetan', 'S. Caietani, presbyteri', 'Saint Cajetan, priest', 'San Gaetano, sacerdote', 2, 'Pastors:For One Pastor|Holy Men and Women:For Religious', 'GENERAL ROMAN', 'white'),
(117, 8, 8, 'StDominic', 'S. Dominici, presbyteri', 'Saint Dominic, priest', 'San Domenico, sacerdote', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(119, 8, 10, 'StLawrenceDeacon', 'S. Laurentii, diaconi et martyris', 'Saint Lawrence, deacon and martyr', 'San Lorenzo, diacono e martire', 4, 'Proper', 'GENERAL ROMAN', 'red|white'),
(120, 8, 11, 'StClare', 'S. Claræ, virginis', 'Saint Clare, virgin', 'Santa Chiara, vergine', 3, 'Virgins:For One Virgin|Holy Men and Women:For a Nun', 'GENERAL ROMAN', 'white'),
(121, 12, 12, 'StJaneFrancesDeChantal', 'S. Ioannæ Franciscæ de Chantal, religiosæ', 'Saint Jane Frances de Chantal, religious', 'Santa Giovanna Francesca de Chantal, religiosa', 2, 'Holy Men and Women:For Religious', 'GENERAL ROMAN', 'white'),
(122, 8, 13, 'StsPontianHippolytus', 'Ss. Pontiani, papæ, et Hippolyti, presbyteri, martyrum', 'Saints Pontian, pope, and Hippolytus, priest, martyrs', 'Santi Ponziano, papa, e Ippolito, sacerdote, martiri', 2, 'Martyrs:For Several Martyrs|Pastors:For Several Pastors', 'GENERAL ROMAN', 'red|white'),
(124, 8, 15, 'Assumption', 'In Assumptione Beatæ Mariæ Virginis', 'Assumption of the Blessed Virgin Mary', 'Assunzione della Beata Vergine Maria', 6, 'Proper', 'GENERAL ROMAN', 'white'),
(125, 8, 16, 'StStephenHungary', 'S. Stephani Hunagariæ', 'Saint Stephen of Hungary', 'Santo Stefano di Ungheria', 2, 'Holy Men and Women:For One Saint', 'GENERAL ROMAN', 'white'),
(126, 8, 19, 'StJohnEudes', 'S. Ioannis Eudes, presbyteri', 'Saint John Eudes, priest', 'San Giovanni Eudes, sacerdote', 2, 'Pastors:For One Pastor|Holy Men and Women:For Religious', 'GENERAL ROMAN', 'white'),
(127, 8, 20, 'StBernardClairvaux', 'S. Bernardi, abbatis et Ecclesiæ doctoris', 'Saint Bernard of Clairvaux, abbot and doctor of the Church', 'San Bernardo di Chiaravalle, abate e dottore della Chiesa', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(128, 8, 21, 'StPiusX', 'S. Pii X, papæ', 'Saint Pius X, pope', 'San Pio X, papa', 3, 'Pastors:For a Pope', 'GENERAL ROMAN', 'white'),
(129, 8, 22, 'QueenshipMary', 'Beatæ Mariæ Virginis Reginæ', 'Queenship of Blessed Virgin Mary', 'Beata Maria Vergine Regina', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(130, 8, 23, 'StRoseLima', 'S. Rosæ de Lima, virginis', 'Saint Rose of Lima, virgin', 'Santa Rosa da Lima, vergine', 2, 'Virgins:For One Virgin', 'GENERAL ROMAN', 'white'),
(131, 8, 24, 'StBartholomewAp', 'S. Bartholomæi, Apostoli', 'Saint Bartholomew the Apostle', 'San Bartolomeo, apostolo', 4, 'Proper', 'GENERAL ROMAN', 'red'),
(132, 8, 25, 'StLouis', 'S. Ludovici', 'Saint Louis', 'San Luigi', 2, 'Holy Men and Women:For One Saint', 'GENERAL ROMAN', 'white'),
(133, 8, 25, 'StJosephCalasanz', 'S. Ioseph de Calasanz, presbyteri', 'Saint Joseph Calasanz, priest', 'San Giuseppe da Calasanzio, sacerdote', 2, 'Holy Men and Women:For Educators|Pastors:For One Pastor', 'GENERAL ROMAN', 'white'),
(134, 8, 27, 'StMonica', 'S. Monicæ', 'Saint Monica', 'Santa Monica', 3, 'Holy Men and Women:For Holy Women', 'GENERAL ROMAN', 'white'),
(135, 8, 28, 'StAugustineHippo', 'S. Augustini, episcopi et Ecclesiæ doctoris', 'Saint Augustine of Hippo, bishop and doctor of the Church', 'Sant\'Agostino di Ippona, vescovo e dottore della Chiesa', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(136, 8, 29, 'BeheadingJohnBaptist', 'In Passione S. Ioannis Baptistæ', 'The Beheading of Saint John the Baptist, martyr', 'Martirio di San Giovanni Battista', 3, 'Proper', 'GENERAL ROMAN', 'red'),
(137, 9, 3, 'StGregoryGreat', 'S. Gregorii Magni, papæ et Ecclesiæ doctoris', 'Saint Gregory the Great, pope and doctor', 'San Gregorio Magno, papa e dottore', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(138, 9, 8, 'NativityVirginMary', 'In Nativitate Beatæ Mariæ Virginis', 'Nativity of the Blessed Virgin Mary', 'Natività della Beata Vergine Maria', 4, 'Proper', 'GENERAL ROMAN', 'white'),
(141, 9, 13, 'StJohnChrysostom', 'S. Ioannis Chrysostomi, episcopi et Ecclesiæ doctoris', 'Saint John Chrysostom, bishop and doctor', 'San Giovanni Crisostomo, vescovo e dottore', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(142, 9, 14, 'ExaltationCross', 'In Exaltatione Sanctæ Crucis', 'Exaltation of the Holy Cross', 'Esaltazione della Santa Croce', 5, 'Proper', 'GENERAL ROMAN', 'red'),
(143, 9, 15, 'LadySorrows', 'Beatæ Mariæ Virginis Perdolentis', 'Our Lady of Sorrows', 'Beata Vergine Maria Addolorata', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(144, 9, 16, 'StsCorneliusCyprian', 'Ss. Cornelii, papæ, et Cypriani, episcopi, martyrum', 'Saints Cornelius, pope, and Cyprian, bishop, martyrs', 'Santi Cornelio, papa, e Cipriano, vescovo, martiri', 3, 'Martyrs:For Several Martyrs|Pastors:For a Bishop', 'GENERAL ROMAN', 'red|white'),
(145, 9, 17, 'StRobertBellarmine', 'S. Roberti Bellarmino, episcopi et Ecclesiæ doctoris', 'Saint Robert Bellarmine, bishop and doctor', 'San Roberto Bellarmino, vescovo e dottore', 2, 'Pastors:For a Bishop|Doctors', 'GENERAL ROMAN', 'white'),
(146, 9, 19, 'StJanuarius', 'S. Ianuarii, episcopi et martyris', 'Saint Januarius, bishop and martyr', 'San Gennaro, vescovo e martire', 2, 'Martyrs:For One Martyr|Pastors:For a Bishop', 'GENERAL ROMAN', 'red|white'),
(148, 9, 21, 'StMatthewEvangelist', 'S. Matthæi, apostoli et evangelistæ', 'Saint Matthew the Evangelist, Apostle', 'San Matteo apostolo ed evangelista', 4, 'Proper', 'GENERAL ROMAN', 'red'),
(150, 9, 26, 'StsCosmasDamian', 'Ss. Cosmæ et Damiani, martyrum', 'Saints Cosmas and Damian, martyrs', 'Santi Cosma e Damiano, martiri', 2, 'Martyrs:For Several Martyrs', 'GENERAL ROMAN', 'red'),
(151, 9, 27, 'StVincentDePaul', 'S. Vincentii de Paul, presbyteri', 'Saint Vincent de Paul, priest', 'San Vincenzo de Paoli, sacerdote', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(152, 9, 28, 'StWenceslaus', 'S. Venceslai, martyris', 'Saint Wenceslaus, martyr', 'San Venceslao, martire', 2, 'Martyrs:For One Martyr', 'GENERAL ROMAN', 'red'),
(154, 9, 29, 'StsArchangels', 'Ss. Michælis, Gabrielis et Raphælis, archangelorum', 'Saints Michael, Gabriel and Raphael, Archangels', 'Santi Michele, Gabriele e Raffaele, arcangeli', 4, 'Proper', 'GENERAL ROMAN', 'white'),
(155, 9, 30, 'StJerome', 'S. Hieronymi, presbyteri et Ecclesiæ doctoris', 'Saint Jerome, priest and doctor', 'San Girolamo, sacerdote e dottore', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(156, 10, 1, 'StThereseChildJesus', 'S. Teresiæ a Iesu Infante, virginis', 'Saint Thérèse of the Child Jesus, virgin and doctor', 'Santa Teresa del Bambino Gesù, vergine e dottore', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(157, 10, 2, 'GuardianAngels', 'Ss. Angelorum Custodum', 'Guardian Angels', 'Santi Angeli Custodi', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(158, 10, 4, 'StFrancisAssisi', 'S. Francisci Assisiensis', 'Saint Francis of Assisi', 'San Francesco d\'Assisi', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(159, 10, 6, 'StBruno', 'S. Brunonis, presbyteri', 'Saint Bruno, priest', 'San Bruno, sacerdote', 2, 'Holy Men and Women:For a Monk|Pastors:For One Pastor', 'GENERAL ROMAN', 'white'),
(160, 10, 7, 'LadyRosary', 'Beatæ Mariæ Virginis a Rosario', 'Our Lady of the Rosary', 'Beata Maria Vergine del Rosario', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(161, 10, 9, 'StDenis', 'Ss. Dionysii, episcopi, et sociorum, martyrum', 'Saint Denis, bishop, and companions, martyrs', 'San Dionigi, vescovo, e compagni, martiri', 2, 'Martyrs:For Several Martyrs', 'GENERAL ROMAN', 'red'),
(162, 10, 9, 'StJohnLeonardi', 'S. Ioannis Leonardi, presbyteri', 'Saint John Leonardi, priest', 'San Giovanni Leonardi, sacerdote', 2, 'Pastors:For Missionaries|Holy Men and Women:For Those Who Practiced Works of Mercy', 'GENERAL ROMAN', 'white'),
(164, 10, 14, 'StCallistusIPope', 'S. Callisti I, papæ et martyris', 'Saint Callistus I, pope and martyr', 'San Callisto I, papa e martire', 2, 'Martyrs:For One Martyr|Pastors:For a Pope', 'GENERAL ROMAN', 'red|white'),
(165, 10, 15, 'StTeresaJesus', 'S. Teresiæ de Avila, virginis', 'Saint Teresa of Jesus, virgin and doctor', 'Santa Teresa d\'Avila, vergine e dottore', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(166, 10, 16, 'StHedwig', 'S. Hedvigis, religiosæ', 'Saint Hedwig, religious', 'Sant\'Edvige, religiosa', 2, 'Holy Men and Women:For Religious|Holy Men and Women:For Holy Women', 'GENERAL ROMAN', 'white'),
(167, 10, 16, 'StMargaretAlacoque', 'S. Margaritæ Mariæ Alacoque, virginis', 'Saint Margaret Mary Alacoque, virgin', 'Santa Margherita Maria Alacoque, vergine', 2, 'Virgins:For One Virgin', 'GENERAL ROMAN', 'white'),
(168, 10, 17, 'StIgnatiusAntioch', 'S. Ignatii Antiocheni, episcopi et martyris', 'Saint Ignatius of Antioch, bishop and martyr', 'Sant\'Ignazio di Antiochia, vescovo e martire', 3, 'Proper', 'GENERAL ROMAN', 'red|white'),
(169, 10, 18, 'StLukeEvangelist', 'S. Lucæ, evangelistæ', 'Saint Luke the Evangelist', 'San Luca Evangelista', 4, 'Proper', 'GENERAL ROMAN', 'red'),
(170, 10, 19, 'StsJeanBrebeuf', 'Ss. Ioannis de Brébeuf et Isaac Jogues, presbyterorum, et sociorum, martyrum', 'Saints Jean de Brébeuf, Isaac Jogues, priests, and companions, martyrs', 'Santi Giovanni Brebeuf e Isacco Jogues, sacerdoti e compagni martiri', 2, 'Martyrs:For Missionary Martyrs', 'GENERAL ROMAN', 'red'),
(171, 10, 19, 'StPaulCross', 'S. Pauli a Cruce, presbyteri', 'Saint Paul of the Cross, priest', 'San Paolo della Croce, sacerdote', 2, 'Proper', 'GENERAL ROMAN', 'white'),
(173, 10, 23, 'StJohnCapistrano', 'S. Ioannis de Capestrano, presbyteri', 'Saint John of Capistrano, priest', 'San Giovanni da Capestrano, sacerdote', 2, 'Pastors:For Missionaries|Holy Men and Women:For Religious', 'GENERAL ROMAN', 'white'),
(174, 10, 24, 'StAnthonyMaryClaret', 'S. Antonii Mariæ Claret, episcopi', 'Saint Anthony Mary Claret, bishop', 'Sant\'Antonio Maria Claret, vescovo', 2, 'Pastors:For Missionaries|Pastors:For a Bishop', 'GENERAL ROMAN', 'white'),
(175, 10, 28, 'StSimonStJudeAp', 'Ss. Simonis et Iudæ, apostolorum', 'Saint Simon and Saint Jude, apostles', 'Santi Simone e Giuda, apostoli', 4, 'Proper', 'GENERAL ROMAN', 'red'),
(176, 11, 1, 'AllSaints', 'Omnium Sanctorum', 'All Saints Day', 'Tutti i Santi', 6, 'Proper', 'GENERAL ROMAN', 'white'),
(177, 11, 2, 'AllSouls', 'In Commemoratione Omnium Fidelium Defunctorum', 'Commemoration of all the Faithful Departed (All Souls\' Day)', 'Commemorazione di tutti i defunti', 6, 'Proper', 'GENERAL ROMAN', 'purple'),
(178, 11, 3, 'StMartinPorres', 'S. Martini de Porres, religiosi', 'Saint Martin de Porres, religious', 'San Martino de Porres, religioso', 2, 'Holy Men and Women:For Religious', 'GENERAL ROMAN', 'white'),
(179, 11, 4, 'StCharlesBorromeo', 'S. Caroli Borromeo, episcopi', 'Saint Charles Borromeo, bishop', 'San Carlo Borromeo, vescovo', 3, 'Pastors:For a Bishop', 'GENERAL ROMAN', 'white'),
(180, 11, 9, 'DedicationLateran', 'In Dedicatione Bascilicæ Lateranensis', 'Dedication of the Lateran basilica', 'Dedicazione della Basilica lateranense', 4, 'Proper', 'GENERAL ROMAN', 'white'),
(181, 11, 10, 'StLeoGreat', 'S. Leonis Magni, papæ et Ecclesiæ doctoris', 'Saint Leo the Great, pope and doctor', 'San Leone Magno, papa e dottore', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(182, 11, 11, 'StMartinTours', 'S. Martini, episcopi', 'Saint Martin of Tours, bishop', 'San Martino di Tours, vescovo', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(183, 11, 12, 'StJosaphat', 'S. Iosaphat, episcopi et martyris', 'Saint Josaphat, bishop and martyr', 'San Giosafat, vescovo e martire', 3, 'Proper', 'GENERAL ROMAN', 'red|white'),
(184, 11, 15, 'StAlbertGreat', 'S. Alberti Magni, episcopi et Ecclesiæ doctoris', 'Saint Albert the Great, bishop and doctor', 'Sant\'Alberto Magno, vescovo e dottore', 2, 'Pastors:For a Bishop|Doctors', 'GENERAL ROMAN', 'white'),
(185, 11, 16, 'StMargaretScotland', 'S. Margaritæ Scotiæ', 'Saint Margaret of Scotland', 'Santa Margherita di Scozia', 2, 'Holy Men and Women:For Those Who Practiced Works of Mercy', 'GENERAL ROMAN', 'white'),
(186, 11, 16, 'StGertrudeGreat', 'S. Gertrudis, virginis', 'Saint Gertrude the Great, virgin', 'Santa Gertrude, vergine', 2, 'Virgins:For One Virgin|Holy Men and Women:For a Nun', 'GENERAL ROMAN', 'white'),
(187, 11, 17, 'StElizabethHungary', 'S. Elisabeth Hungariæ', 'Saint Elizabeth of Hungary, religious', 'Sant\'Elisabetta di Ungheria, religiosa', 3, 'Holy Men and Women:For Those Who Practiced Works of Mercy', 'GENERAL ROMAN', 'white'),
(188, 11, 18, 'DedicationStsPeterPaul', 'In Dedicatione basilicarum Ss. Petri et Pauli, apostolorum', 'Dedication of the basilicas of Saints Peter and Paul, Apostles', 'Dedicazione delle basiliche dei Santi Pietro e Paolo, apostoli', 2, 'Proper', 'GENERAL ROMAN', 'white'),
(189, 11, 21, 'PresentationMary', 'In Præsentatione beatæ Mariæ Virginis', 'Presentation of the Blessed Virgin Mary', 'Presentazione della Beata Vergine Maria', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(190, 11, 22, 'StCecilia', 'S. Cæciliæ, virginis et martyris', 'Saint Cecilia, virgin and martyr', 'Santa Cecilia, vergine e martire', 3, 'Martyrs:For a Virgin Martyr|Virgins:For One Virgin', 'GENERAL ROMAN', 'red|white'),
(191, 11, 23, 'StClementIPope', 'S. Clementis I, papæ et martyris', 'Saint Clement I, pope and martyr', 'San Clemente I, papa e martire', 2, 'Martyrs:For One Martyr|Pastors:For a Pope', 'GENERAL ROMAN', 'red|white'),
(192, 11, 23, 'StColumban', 'S. Columbani, abbatis', 'Saint Columban, religious', 'San Colombano, abate', 2, 'Pastors:For Missionaries|Holy Men and Women:For an Abbot', 'GENERAL ROMAN', 'white'),
(195, 11, 30, 'StAndrewAp', 'S. Andreæ, apostoli', 'Saint Andrew the Apostle', 'Sant\'Andrea apostolo', 4, 'Proper', 'GENERAL ROMAN', 'red'),
(196, 12, 3, 'StFrancisXavier', 'S. Francisci Xavier, presbyteri', 'Saint Francis Xavier, priest', 'San Francesco Saverio, sacerdote', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(197, 12, 4, 'StJohnDamascene', 'S. Ioannis Damasceni, presbyteri et Ecclesiæ doctoris', 'Saint John Damascene, priest and doctor', 'San Giovanni Damasceno, sacerdote e dottore', 2, 'Pastors:For One Pastor|Doctors', 'GENERAL ROMAN', 'white'),
(198, 12, 6, 'StNicholas', 'S. Nicolai, episcopi', 'Saint Nicholas, bishop', 'San Nicola, vescovo', 2, 'Pastors:For a Bishop', 'GENERAL ROMAN', 'white'),
(199, 12, 7, 'StAmbrose', 'S. Ambrosii, episcopi et Ecclesiæ doctoris', 'Saint Ambrose, bishop and doctor', 'Sant\'Ambrogio, vescovo e dottore', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(200, 12, 8, 'ImmaculateConception', 'In Conceptione Immaculata Beatæ Mariæ Virginis', 'Immaculate Conception of the Blessed Virgin Mary', 'Immacolata Concezione della Beata Vergine Maria', 6, 'Proper', 'GENERAL ROMAN', 'white'),
(202, 12, 11, 'StDamasusIPope', 'S. Damasi I, papæ', 'Saint Damasus I, pope', 'San Damaso I, papa', 2, 'Pastors:For a Pope', 'GENERAL ROMAN', 'white'),
(204, 12, 13, 'StLucySyracuse', 'S. Luciæ, virginis et martyris', 'Saint Lucy of Syracuse, virgin and martyr', 'Santa Lucia, vergine e martire', 3, 'Martyrs:For a Virgin Martyr|Virgins:For One Virgin', 'GENERAL ROMAN', 'red|white'),
(205, 12, 14, 'StJohnCross', 'S. Ioannis a Cruce, presbyteri et Ecclesiæ doctoris', 'Saint John of the Cross, priest and doctor', 'San Giovanni della Croce, sacerdote e dottore', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(206, 12, 21, 'StPeterCanisius', 'S. Petri Canisii, presbyteri et Ecclesiæ doctoris', 'Saint Peter Canisius, priest and doctor', 'San Pietro Canisio, sacerdote e dottore', 2, 'Pastors:For One Pastor|Doctors', 'GENERAL ROMAN', 'white'),
(207, 12, 23, 'StJohnKanty', 'S. Ioannis de Kęty, presbyteri', 'Saint John of Kanty, priest', 'San Giovanni da Kanty, sacerdote', 2, 'Pastors:For One Pastor|Holy Men and Women:For Those Who Practiced Works of Mercy', 'GENERAL ROMAN', 'white'),
(209, 12, 26, 'StStephenProtomartyr', 'S. Stephani, protomartyris', 'Saint Stephen, the first martyr', 'Santo Stefano, protomartire', 4, 'Proper', 'GENERAL ROMAN', 'red'),
(210, 12, 27, 'StJohnEvangelist', 'S. Ioannis, apostoli et evangelistæ', 'Saint John, Apostle and Evangelist', 'San Giovanni apostolo ed evangelista', 4, 'Proper', 'GENERAL ROMAN', 'white'),
(211, 12, 28, 'HolyInnnocents', 'Ss. Innocentium, martyrum', 'Holy Innocents, martyrs', 'Santi Innocenti, martiri', 4, 'Proper', 'GENERAL ROMAN', 'red'),
(212, 12, 29, 'StThomasBecket', 'S. Thomæ Becket, episcopi et martyris', 'Saint Thomas Becket, bishop and martyr', 'San Tommaso Becket, vescovo e martire', 2, 'Martyrs:For One Martyr|Pastors:For a Bishop', 'GENERAL ROMAN', 'red|white'),
(213, 12, 31, 'StSylvesterIPope', 'S. Silvestri I, papæ', 'Saint Sylvester I, pope', 'San Silvestro I, papa', 2, 'Pastors:For a Pope', 'GENERAL ROMAN', 'white');

-- --------------------------------------------------------

--
-- Table structure for table `LITURGY__calendar_propriumdesanctis_2002`
--
-- Creation: Jan 06, 2019 at 06:39 PM
-- Last update: Jul 14, 2020 at 09:14 AM
--

CREATE TABLE `LITURGY__calendar_propriumdesanctis_2002` (
  `RECURRENCE_ID` int(11) NOT NULL,
  `MONTH` int(11) NOT NULL,
  `DAY` int(11) NOT NULL,
  `TAG` varchar(50) NOT NULL,
  `NAME_LA` varchar(200) NOT NULL,
  `NAME_EN` varchar(200) NOT NULL,
  `NAME_IT` varchar(200) NOT NULL,
  `GRADE` int(11) NOT NULL,
  `COMMON` varchar(200) NOT NULL,
  `CALENDAR` varchar(50) NOT NULL,
  `COLOR` varchar(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='reflects the new festivities added to the general calendar in the 2002 edition of the Roman Missal';

--
-- RELATIONSHIPS FOR TABLE `LITURGY__calendar_propriumdesanctis_2002`:
--   `GRADE`
--       `LITURGY__festivity_grade` -> `IDX`
--   `MONTH`
--       `LITURGY__months` -> `IDX`
--

--
-- Dumping data for table `LITURGY__calendar_propriumdesanctis_2002`
--

INSERT INTO `LITURGY__calendar_propriumdesanctis_2002` (`RECURRENCE_ID`, `MONTH`, `DAY`, `TAG`, `NAME_LA`, `NAME_EN`, `NAME_IT`, `GRADE`, `COMMON`, `CALENDAR`, `COLOR`) VALUES
(3, 1, 3, 'NameJesus', 'Sanctissimi Nominis Iesu', 'The Most Holy Name of Jesus', 'Santissimo Nome di Gesù', 2, 'Proper', 'GENERAL ROMAN', 'white'),
(23, 2, 8, 'StJosephineBakhita', 'Sanctæ Iosephinæ Bakhita, virginis', 'Saint Josephine Bakhita, virgin', 'Santa Giuseppina Bakhita, vergine', 2, 'Virgins:For One Virgin', 'GENERAL ROMAN', 'white'),
(48, 4, 23, 'StAdalbert', 'Sancti Adalberti, episcopi et martyris', 'Saint Adalbert, bishop and martyr', 'Sant\'Adalberto, vescovo e martire', 2, 'Martyrs:For One Martyr|Pastors:For a Bishop', 'GENERAL ROMAN', 'red|white'),
(52, 4, 28, 'StLouisGrignonMontfort', 'Sancti Ludovici Mariæ Grignion de Montfort, presbyteri', 'Saint Louis Grignon de Montfort, priest', 'San Luigi Grignon de Montfort', 2, 'Pastors:For One Pastor', 'GENERAL ROMAN', 'white'),
(60, 5, 13, 'LadyFatima', 'Beatæ Mariæ Virginis de Fatima', 'Our Lady of Fatima', 'Beata Vergine Maria di Fatima', 2, 'Blessed Virgin Mary', 'GENERAL ROMAN', 'white'),
(64, 5, 21, 'StChristopherMagallanes', 'Sanctorum Christophori Magallanes, presbyteri, et sociorum, martyrum', 'Saint Christopher Magallanes and companions, martyrs', 'San Cristoforo Magallanes e compagni martiri', 2, 'Martyrs:For Several Martyrs', 'GENERAL ROMAN', 'red'),
(65, 5, 22, 'StRitaCascia', 'Sanctæ Ritæ de Cascia, religiosæ', 'Saint Rita of Cascia', 'Santa Rita da Cascia, religiosa', 2, 'Holy Men and Women:For Religious', 'GENERAL ROMAN', 'white'),
(93, 7, 9, 'StAugustineZhaoRong', 'Sanctorum Augustini Zhao Rong, presbyteri et sociorum, martyrum', 'Saint Augustine Zhao Rong and companions, martyrs', 'Santi Agostino Zhao Rong e compagni martiri', 2, 'Martyrs:For Several Martyrs', 'GENERAL ROMAN', 'red'),
(99, 7, 20, 'StApollinaris', 'Sancti Apollinaris, episcopi et martyris', 'Saint Apollinaris, bishop and martyr', 'Sant\'Apollinare, vescovo e martire', 2, 'Martyrs:For One Martyr|Pastors:For a Bishop', 'GENERAL ROMAN', 'red|white'),
(103, 7, 24, 'StSharbelMakhluf', 'Sancti Sarbelii Makhluf, presbyteri', 'Saint Sharbel Makhluf, hermit', 'San Charbel Makhluf, eremita', 2, 'Pastors:For One Pastor|Holy Men and Women:For a Monk', 'GENERAL ROMAN', 'white'),
(111, 8, 2, 'StPeterJulianEymard', 'Sancti Petri Iuliani Eymard, presbyteri', 'Saint Peter Julian Eymard, priest', 'San Pietro Giuliani, sacerdote', 2, 'Holy Men and Women:For Religious|Pastors:For One Pastor', 'GENERAL ROMAN', 'white'),
(118, 8, 9, 'StEdithStein', 'Sanctæ Teresiæ Benedictæ a Cruce, virginis et martyris', 'Saint Teresa Benedicta of the Cross (Edith Stein), virgin and martyr', 'Santa Teresa Benedetta della Croce (Edith Stein), vergine e martire', 2, 'Martyrs:For a Virgin Martyr|Virgins:For One Virgin', 'GENERAL ROMAN', 'red|white'),
(123, 8, 14, 'StMaximilianKolbe', 'Sancti Maximiliani Mariæ Kolbe, presbyteri et martyris', 'Saint Maximilian Mary Kolbe, priest and martyr', 'San Massimiliano Kolbe, sacerdote e martire', 3, 'Proper', 'GENERAL ROMAN', 'red|white'),
(139, 9, 9, 'StPeterClaver', 'Sancti Petri Claver, presbyteri', 'Saint Peter Claver, priest', 'San Pietro Claver, sacerdote', 2, 'Pastors:For One Pastor|Holy Men and Women:For Those Who Practiced Works of Mercy', 'GENERAL ROMAN', 'white'),
(140, 9, 12, 'HolyNameMary', 'Sanctissimi Nominis Mariæ', 'Holy Name of the Blessed Virgin Mary', 'Santissimo Nome di Maria', 2, 'Proper', 'GENERAL ROMAN', 'white'),
(147, 9, 20, 'StAndrewKimTaegon', 'Sanctorum Andreæ Kim Tægon, presbyteri, et Pauli Chong Hasang et sociorum, martyrum', 'Saint Andrew Kim Taegon, priest, and Paul Chong Hasang and companions, martyrs', 'Santi Andrea Kim Taegon, sacerdote, Paolo Chong Hasang e compagni martiri', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(153, 9, 28, 'StsLawrenceRuiz', 'Sanctorum Laurentii Ruiz et sociorum, martyrum', 'Saints Lawrence Ruiz and companions, martyrs', 'Santi Lorenzo Ruiz e compagni martiri', 2, 'Martyrs:For Several Martyrs', 'GENERAL ROMAN', 'red'),
(193, 11, 24, 'StAndrewDungLac', 'Sanctorum Andreæ Dung-Lac, presbyteri, et sociorum, martyrum', 'Saint Andrew Dung-Lac and his companions, martyrs', 'Sant\'Andrea Dung-Lac e compagni martiri', 3, 'Proper', 'GENERAL ROMAN', 'red'),
(194, 11, 25, 'StCatherineAlexandria', 'Sanctæ Catharinæ Alexandrinæ, virginis et martyris', 'Saint Catherine of Alexandria, virgin and martyr', 'Santa Caterina da Alessandria, vergine e martire', 2, 'Martyrs:For a Virgin Martyr|Virgins:For One Virgin', 'GENERAL ROMAN', 'red|white');

-- --------------------------------------------------------

--
-- Table structure for table `LITURGY__calendar_propriumdetempore`
--
-- Creation: Jan 06, 2019 at 04:29 PM
--

CREATE TABLE `LITURGY__calendar_propriumdetempore` (
  `RECURRENCE_ID` int(11) NOT NULL,
  `TAG` varchar(50) NOT NULL,
  `NAME_LA` varchar(200) NOT NULL,
  `NAME_EN` varchar(200) NOT NULL,
  `NAME_IT` varchar(200) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- RELATIONSHIPS FOR TABLE `LITURGY__calendar_propriumdetempore`:
--

--
-- Dumping data for table `LITURGY__calendar_propriumdetempore`
--

INSERT INTO `LITURGY__calendar_propriumdetempore` (`RECURRENCE_ID`, `TAG`, `NAME_LA`, `NAME_EN`, `NAME_IT`) VALUES
(1, 'HolyThurs', 'Feria V Hebdomadae Sanctae', 'Holy Thursday', 'Giovedì Santo'),
(2, 'GoodFri', 'Feria VI in Passione Domini', 'Good Friday', 'Venerdì Santo'),
(3, 'EasterVigil', 'Vigilia Paschalis', 'Easter Vigil', 'Vigilia Pasquale'),
(4, 'Easter', 'Dominica Paschae in Resurrectione Domini', 'Easter Sunday', 'Domenica di Pasqua'),
(5, 'Christmas', 'In Nativitate Domini', 'Christmas', 'Natale'),
(6, 'MotherGod', 'SOLLEMNITAS SANCTAE DEI GENITRICIS MARIAE', 'Mary, Mother of God', 'Maria Ss.ma Madre di Dio'),
(7, 'Epiphany', 'in Epiphania Domini', 'Epiphany', 'Epifania'),
(8, 'Ascension', 'In Ascensione Domini', 'Ascension', 'Ascensione'),
(9, 'Pentecost', 'Dominica Pentecostes', 'Pentecost', 'Pentecoste'),
(10, 'Easter7', 'Dominica VII Paschae', 'Seventh Sunday of Easter', 'Settima Domenica della Pasqua'),
(11, 'Christmas2', 'Dominica II Post Nativitatem', '2nd Sunday after Christmas', 'Seconda Domenica dopo Natale'),
(12, 'Advent1', 'Dominica Prima Adventus', 'First Sunday of Advent', 'Prima Domenica dell\'Avvento'),
(13, 'Advent2', 'Dominica Secunda Adventus', 'Second Sunday of Advent', 'Seconda Domenica dell\'Avvento'),
(14, 'Advent3', 'Dominica Tertia Adventus (Gaudete)', 'Third Sunday of Advent (Gaudete)', 'Terza Domenica dell\'Avvento (Gaudete)'),
(15, 'Advent4', 'Dominica Quarta Adventus', 'Fourth Sunday of Advent', 'Quarta Domenica dell\'Avvento'),
(16, 'Lent1', 'Dominica I in Quadragesima', 'First Sunday of Lent', 'Prima Domenica della Quaresima'),
(17, 'Lent2', 'Dominica II in Quadragesima', 'Second Sunday of Lent', 'Seconda Domenica della Quaresima'),
(18, 'Lent3', 'Dominica III in Quadragesima', 'Third Sunday of Lent', 'Terza Domenica della Quaresima'),
(19, 'Lent4', 'Dominica IV in Quadragesima', 'Fourth Sunday of Lent', 'Quarta Domenica della Quaresima'),
(20, 'Lent5', 'Dominica V in Quadragesima', 'Fifth Sunday of Lent', 'Quinta Domenica della Quaresima'),
(21, 'PalmSun', 'Dominica in Palmis', 'Palm Sunday', 'Domenica delle Palme'),
(22, 'Easter2', 'Dominica II in Paschae', 'Second Sunday of Easter', 'Seconda Domenica della Pasqua'),
(23, 'Easter3', 'Dominica III in Paschae', 'Third Sunday of Easter', 'Terza Domenica della Pasqua'),
(24, 'Easter4', 'Dominica IV in Paschae', 'Fourth Sunday of Easter', 'Quarta Domenica della Pasqua'),
(25, 'Easter5', 'Dominica V in Paschae', 'Fifth Sunday of Easter', 'Quinta Domenica della Pasqua'),
(26, 'Easter6', 'Dominica VI in Paschae', 'Sixth Sunday of Easter', 'Sesta Domenica della Pasqua'),
(27, 'Trinity', 'Dominica post Pentecostem Sanctissimae Trinitatis', 'Holy Trinity Sunday', 'Domenica della Santissima Trinità'),
(28, 'CorpusChristi', 'Ss.mi Corporis et Sanguinis Christi', 'Corpus Christi', 'Santissimo Corpo e Sangue di Cristo'),
(29, 'AshWednesday', 'Feria IV Cinerum', 'Ash Wednesday', 'Mercoledì delle Ceneri'),
(30, 'MonHolyWeek', 'Feria II Hebdomadae Sanctae', 'Monday of Holy Week', 'Lunedì della Settimana Santa'),
(31, 'TueHolyWeek', 'Feria III Hebdomadae Sanctae', 'Tuesday of Holy Week', 'Martedì della Settimana Santa'),
(32, 'WedHolyWeek', 'Feria IV Hebdomadae Sanctae', 'Wednesday of Holy Week', 'Mercoledì della Settimana Santa'),
(33, 'MonOctaveEaster', 'Feria II infra Octavam Paschae', 'Monday of the Octave of Easter', 'Lunedì dell\'Ottava di Pasqua'),
(34, 'TueOctaveEaster', 'Feria III infra Octavam Paschae', 'Tuesday of the Octave of Easter', 'Martedì dell\'Ottava di Pasqua'),
(35, 'WedOctaveEaster', 'Feria IV infra Octavam Paschae', 'Wednesday of the Octave of Easter', 'Mercoledì dell\'Ottava di Pasqua'),
(36, 'ThuOctaveEaster', 'Feria V infra Octavam Paschae', 'Thursday of the Octave of Easter', 'Giovedì dell\'Ottava di Pasqua'),
(37, 'FriOctaveEaster', 'Feria VI infra Octavam Paschae', 'Friday of the Octave of Easter', 'Venerdì dell\'Ottava di Pasqua'),
(38, 'SatOctaveEaster', 'Sabbato infra Octavam Paschae', 'Saturday of the Octave of Easter', 'Sabato dell\'Ottava di Pasqua'),
(39, 'SacredHeart', 'Sacratissimi Cordis Iesu', 'Most Sacred Heart of Jesus', 'Sacratissimi Cordis Iesu'),
(40, 'ChristKing', 'Domini Nostri Iesu Christi Universorum Regis', 'Christ the King', 'Cristo Re dell\'Universo'),
(41, 'BaptismLord', 'In Festo Baptismatis Domini', 'Baptism of the Lord', 'Battesimo del Signore'),
(42, 'HolyFamily', 'S. Familiae Iesu, Mariae et Joseph', 'Holy Family of Jesus, Mary and Joseph', 'Sacra Famiglia di Gesù, Maria e Giuseppe'),
(43, 'OrdSunday2', 'Dominica II «Per Annum»', '2nd Sunday of Ordinary Time', 'II Domenica del Tempo Ordinario'),
(44, 'OrdSunday3', 'Dominica III «Per Annum»', '3rd Sunday of Ordinary Time', 'III Domenica del Tempo Ordinario'),
(45, 'OrdSunday4', 'Dominica IV «Per Annum»', '4th Sunday of Ordinary Time', 'IV Domenica del Tempo Ordinario'),
(46, 'OrdSunday5', 'Dominica V «Per Annum»', '5th Sunday of Ordinary Time', 'V Domenica del Tempo Ordinario'),
(47, 'OrdSunday6', 'Dominica VI «Per Annum»', '6th Sunday of Ordinary Time', 'VI Domenica del Tempo Ordinario'),
(48, 'OrdSunday7', 'Dominica VII «Per Annum»', '7th Sunday of Ordinary Time', 'VII Domenica del Tempo Ordinario'),
(49, 'OrdSunday8', 'Dominica VIII «Per Annum»', '8th Sunday of Ordinary Time', 'VIII Domenica del Tempo Ordinario'),
(50, 'OrdSunday9', 'Dominica IX «Per Annum»', '9th Sunday of Ordinary Time', 'IX Domenica del Tempo Ordinario'),
(51, 'OrdSunday10', 'Dominica X «Per Annum»', '10th Sunday of Ordinary Time', 'X Domenica del Tempo Ordinario'),
(52, 'OrdSunday11', 'Dominica XI «Per Annum»', '11th Sunday of Ordinary Time', 'XI Domenica del Tempo Ordinario'),
(53, 'OrdSunday12', 'Dominica XII «Per Annum»', '12th Sunday of Ordinary Time', 'XII Domenica del Tempo Ordinario'),
(54, 'OrdSunday13', 'Dominica XIII «Per Annum»', '13th Sunday of Ordinary Time', 'XIII Domenica del Tempo Ordinario'),
(55, 'OrdSunday14', 'Dominica XIV «Per Annum»', '14th Sunday of Ordinary Time', 'XIV Domenica del Tempo Ordinario'),
(56, 'OrdSunday15', 'Dominica XV «Per Annum»', '15th Sunday of Ordinary Time', 'XV Domenica del Tempo Ordinario'),
(57, 'OrdSunday16', 'Dominica XVI «Per Annum»', '16th Sunday of Ordinary Time', 'XVI Domenica del Tempo Ordinario'),
(58, 'OrdSunday17', 'Dominica XVII «Per Annum»', '17th Sunday of Ordinary Time', 'XVII Domenica del Tempo Ordinario'),
(59, 'OrdSunday18', 'Dominica XVIII «Per Annum»', '18th Sunday of Ordinary Time', 'XVIII Domenica del Tempo Ordinario'),
(60, 'OrdSunday19', 'Dominica XIX «Per Annum»', '19th Sunday of Ordinary Time', 'XIX Domenica del Tempo Ordinario'),
(61, 'OrdSunday20', 'Dominica XX «Per Annum»', '20th Sunday of Ordinary Time', 'XX Domenica del Tempo Ordinario'),
(62, 'OrdSunday21', 'Dominica XXI «Per Annum»', '21st Sunday of Ordinary Time', 'XXI Domenica del Tempo Ordinario'),
(63, 'OrdSunday22', 'Dominica XXII «Per Annum»', '22nd Sunday of Ordinary Time', 'XXII Domenica del Tempo Ordinario'),
(64, 'OrdSunday23', 'Dominica XXIII «Per Annum»', '23rd Sunday of Ordinary Time', 'XXIII Domenica del Tempo Ordinario'),
(65, 'OrdSunday24', 'Dominica XXIV «Per Annum»', '24th Sunday of Ordinary Time', 'XXIV Domenica del Tempo Ordinario'),
(66, 'OrdSunday25', 'Dominica XXV «Per Annum»', '25th Sunday of Ordinary Time', 'XXV Domenica del Tempo Ordinario'),
(67, 'OrdSunday26', 'Dominica XXVI «Per Annum»', '26th Sunday of Ordinary Time', 'XXVI Domenica del Tempo Ordinario'),
(68, 'OrdSunday27', 'Dominica XXVII «Per Annum»', '27th Sunday of Ordinary Time', 'XXVII Domenica del Tempo Ordinario'),
(69, 'OrdSunday28', 'Dominica XXVIII «Per Annum»', '28th Sunday of Ordinary Time', 'XXVIII Domenica del Tempo Ordinario'),
(70, 'OrdSunday29', 'Dominica XXIX «Per Annum»', '29th Sunday of Ordinary Time', 'XXIX Domenica del Tempo Ordinario'),
(71, 'OrdSunday30', 'Dominica XXX «Per Annum»', '30th Sunday of Ordinary Time', 'XXX Domenica del Tempo Ordinario'),
(72, 'OrdSunday31', 'Dominica XXXI «Per Annum»', '31st Sunday of Ordinary Time', 'XXXI Domenica del Tempo Ordinario'),
(73, 'OrdSunday32', 'Dominica XXXII «Per Annum»', '32nd Sunday of Ordinary Time', 'XXXII Domenica del Tempo Ordinario'),
(74, 'OrdSunday33', 'Dominica XXXIII «Per Annum»', '33rd Sunday of Ordinary Time', 'XXXIII Domenica del Tempo Ordinario'),
(75, 'OrdSunday34', 'Dominica XXXIV «Per Annum»', '34th Sunday of Ordinary Time', 'XXXIV Domenica del Tempo Ordinario');

-- --------------------------------------------------------

--
-- Table structure for table `LITURGY__colors`
--
-- Creation: Jan 06, 2019 at 03:10 PM
--

CREATE TABLE `LITURGY__colors` (
  `COLOR_EN` varchar(10) NOT NULL,
  `COLOR_LA` varchar(10) NOT NULL,
  `COLOR_IT` varchar(10) NOT NULL,
  `COLOR_ES` varchar(10) NOT NULL,
  `COLOR_FR` varchar(10) NOT NULL,
  `COLOR_DE` varchar(10) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- RELATIONSHIPS FOR TABLE `LITURGY__colors`:
--

--
-- Dumping data for table `LITURGY__colors`
--

INSERT INTO `LITURGY__colors` (`COLOR_EN`, `COLOR_LA`, `COLOR_IT`, `COLOR_ES`, `COLOR_FR`, `COLOR_DE`) VALUES
('green', 'viridis', 'verde', 'verde', 'vert', 'grün'),
('pink', 'roseus', 'rosa', 'rosa', 'rose', 'rosa'),
('purple', 'purpureus', 'viola', 'morado', 'violet', 'lila'),
('red', 'ruber', 'rosso', 'rojo', 'rouge', 'rot'),
('white', 'albus', 'bianco', 'blanco', 'blanche', 'weiß');

-- --------------------------------------------------------

--
-- Table structure for table `LITURGY__festivity_grade`
--
-- Creation: Jul 25, 2017 at 03:47 PM
--

CREATE TABLE `LITURGY__festivity_grade` (
  `IDX` int(11) NOT NULL,
  `NAME` varchar(60) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- RELATIONSHIPS FOR TABLE `LITURGY__festivity_grade`:
--

--
-- Dumping data for table `LITURGY__festivity_grade`
--

INSERT INTO `LITURGY__festivity_grade` (`IDX`, `NAME`) VALUES
(0, 'WEEKDAY'),
(1, 'COMMEMORATION'),
(2, 'OPTIONAL MEMORIAL'),
(3, 'MEMORIAL'),
(4, 'FEAST'),
(5, 'FEAST OF THE LORD'),
(6, 'SOLEMNITY'),
(7, 'HIGHER RANKING SOLEMNITY');

-- --------------------------------------------------------

--
-- Table structure for table `LITURGY__months`
--
-- Creation: Jul 25, 2017 at 03:48 PM
--

CREATE TABLE `LITURGY__months` (
  `IDX` int(11) NOT NULL,
  `NAME` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- RELATIONSHIPS FOR TABLE `LITURGY__months`:
--

--
-- Dumping data for table `LITURGY__months`
--

INSERT INTO `LITURGY__months` (`IDX`, `NAME`) VALUES
(1, 'JANUARY'),
(2, 'FEBRUARY'),
(3, 'MARCH'),
(4, 'APRIL'),
(5, 'MAY'),
(6, 'JUNE'),
(7, 'JULY'),
(8, 'AUGUST'),
(9, 'SEPTEMBER'),
(10, 'OCTOBER'),
(11, 'NOVEMBER'),
(12, 'DECEMBER');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `LITURGY__calendar_propriumdesanctis`
--
ALTER TABLE `LITURGY__calendar_propriumdesanctis`
  ADD PRIMARY KEY (`RECURRENCE_ID`),
  ADD UNIQUE KEY `TAG` (`TAG`),
  ADD KEY `MONTH_NAME` (`MONTH`),
  ADD KEY `FESTIVITY_GRADE` (`GRADE`);

--
-- Indexes for table `LITURGY__calendar_propriumdesanctis_2002`
--
ALTER TABLE `LITURGY__calendar_propriumdesanctis_2002`
  ADD PRIMARY KEY (`RECURRENCE_ID`),
  ADD UNIQUE KEY `TAG` (`TAG`),
  ADD KEY `FESTIVITY_GRADE2` (`GRADE`),
  ADD KEY `MONTH_NAME2` (`MONTH`);

--
-- Indexes for table `LITURGY__calendar_propriumdetempore`
--
ALTER TABLE `LITURGY__calendar_propriumdetempore`
  ADD PRIMARY KEY (`RECURRENCE_ID`),
  ADD UNIQUE KEY `TAG` (`TAG`);

--
-- Indexes for table `LITURGY__colors`
--
ALTER TABLE `LITURGY__colors`
  ADD PRIMARY KEY (`COLOR_EN`);

--
-- Indexes for table `LITURGY__festivity_grade`
--
ALTER TABLE `LITURGY__festivity_grade`
  ADD PRIMARY KEY (`IDX`);

--
-- Indexes for table `LITURGY__months`
--
ALTER TABLE `LITURGY__months`
  ADD PRIMARY KEY (`IDX`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `LITURGY__calendar_propriumdesanctis`
--
ALTER TABLE `LITURGY__calendar_propriumdesanctis`
  MODIFY `RECURRENCE_ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=214;

--
-- AUTO_INCREMENT for table `LITURGY__calendar_propriumdesanctis_2002`
--
ALTER TABLE `LITURGY__calendar_propriumdesanctis_2002`
  MODIFY `RECURRENCE_ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=195;

--
-- AUTO_INCREMENT for table `LITURGY__calendar_propriumdetempore`
--
ALTER TABLE `LITURGY__calendar_propriumdetempore`
  MODIFY `RECURRENCE_ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=76;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `LITURGY__calendar_propriumdesanctis`
--
ALTER TABLE `LITURGY__calendar_propriumdesanctis`
  ADD CONSTRAINT `FESTIVITY_GRADE` FOREIGN KEY (`GRADE`) REFERENCES `LITURGY__festivity_grade` (`IDX`),
  ADD CONSTRAINT `MONTH_NAME` FOREIGN KEY (`MONTH`) REFERENCES `LITURGY__months` (`IDX`);

--
-- Constraints for table `LITURGY__calendar_propriumdesanctis_2002`
--
ALTER TABLE `LITURGY__calendar_propriumdesanctis_2002`
  ADD CONSTRAINT `FESTIVITY_GRADE2` FOREIGN KEY (`GRADE`) REFERENCES `LITURGY__festivity_grade` (`IDX`),
  ADD CONSTRAINT `MONTH_NAME2` FOREIGN KEY (`MONTH`) REFERENCES `LITURGY__months` (`IDX`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
