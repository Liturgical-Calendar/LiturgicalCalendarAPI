-- phpMyAdmin SQL Dump
-- version 4.7.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Jul 26, 2017 at 12:04 AM
-- Server version: 5.7.18
-- PHP Version: 7.1.3

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `liturgy`
--

-- --------------------------------------------------------

--
-- Table structure for table `LITURGY__calendar_fixed`
--

CREATE TABLE `LITURGY__calendar_fixed` (
  `RECURRENCE_ID` int(11) NOT NULL,
  `MONTH` int(11) NOT NULL,
  `DAY` int(11) NOT NULL,
  `TAG` varchar(50) NOT NULL,
  `NAME` varchar(200) NOT NULL,
  `GRADE` int(11) NOT NULL,
  `COMMON` varchar(200) NOT NULL,
  `CALENDAR` varchar(50) NOT NULL,
  `COLOR` varchar(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Dumping data for table `LITURGY__calendar_fixed`
--

INSERT INTO `LITURGY__calendar_fixed` (`RECURRENCE_ID`, `MONTH`, `DAY`, `TAG`, `NAME`, `GRADE`, `COMMON`, `CALENDAR`, `COLOR`) VALUES
(1, 1, 1, 'MotherGod', 'Holy Mary, Mother of God', 6, 'Proper', 'GENERAL ROMAN', 'white'),
(2, 1, 2, 'StsBasilGreg', 'Saints Basil the Great and Gregory Nazianzen, bishops and doctors', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(3, 1, 3, 'NameJesus', 'The Most Holy Name of Jesus', 2, 'Proper', 'GENERAL ROMAN', 'white'),
(4, 1, 7, 'StRayPenyafort', 'Saint Raymond of Penyafort, priest', 2, 'Pastors:For One Pastor', 'GENERAL ROMAN', 'white'),
(5, 1, 13, 'StHilaryPoitiers', 'Saint Hilary of Poitiers, bishop and doctor', 2, 'Pastors:For a Bishop|Doctors', 'GENERAL ROMAN', 'white'),
(6, 1, 17, 'StAnthonyEgypt', 'Saint Anthony of Egypt, abbot', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(7, 1, 20, 'StFabianPope', 'Saint Fabian, pope and martyr', 2, 'Martyrs:For One Martyr|Pastors:For a Pope', 'GENERAL ROMAN', 'red|white'),
(8, 1, 20, 'StSebastian', 'Saint Sebastian, martyr', 2, 'Martyrs:For One Martyr', 'GENERAL ROMAN', 'red'),
(9, 1, 21, 'StAgnes', 'Saint Agnes, virgin and martyr', 3, 'Martyrs:For a Virgin Martyr|Virgins:For One Virgin', 'GENERAL ROMAN', 'red|white'),
(10, 1, 22, 'StVincentDeacon', 'Saint Vincent, deacon and martyr', 2, 'Martyrs:For One Martyr', 'GENERAL ROMAN', 'red'),
(11, 1, 24, 'StFrancisDeSales', 'Saint Francis de Sales, bishop and doctor', 3, 'Pastors:For a Bishop|Doctors', 'GENERAL ROMAN', 'white'),
(12, 1, 25, 'ConversionStPaul', 'The Conversion of Saint Paul, apostle', 4, 'Proper', 'GENERAL ROMAN', 'white'),
(13, 1, 26, 'StsTimothyTitus', 'Saints Timothy and Titus, bishops', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(14, 1, 27, 'StAngelaMerici', 'Saint Angela Merici, virgin', 2, 'Virgins:For One Virgin|Holy Men and Women:For Educators', 'GENERAL ROMAN', 'white'),
(15, 1, 28, 'StThomasAquinas', 'Saint Thomas Aquinas, priest and doctor', 3, 'Doctors|Pastors:For One Pastor', 'GENERAL ROMAN', 'white'),
(16, 1, 31, 'StJohnBosco', 'Saint John Bosco, priest', 3, 'Pastors:For One Pastor|Holy Men and Women:For Educators', 'GENERAL ROMAN', 'white'),
(17, 2, 2, 'Presentation', 'Presentation of the Lord', 5, 'Proper', 'GENERAL ROMAN', 'white'),
(18, 2, 3, 'StBlase', 'Saint Blase, bishop and martyr', 2, 'Martyrs:For One Martyr|Pastors:For a Bishop', 'GENERAL ROMAN', 'red|white'),
(19, 2, 3, 'StAnsgar', 'Saint Ansgar, bishop', 2, 'Pastors:For Missionaries|Pastors:For a Bishop', 'GENERAL ROMAN', 'white'),
(20, 2, 5, 'StAgatha', 'Saint Agatha, virgin and martyr', 3, 'Martyrs:For a Virgin Martyr|Virgins:For One Virgin', 'GENERAL ROMAN', 'red'),
(21, 2, 6, 'StsPaulMiki', 'Saints Paul Miki and companions, martyrs', 3, 'Martyrs:For Several Martyrs', 'GENERAL ROMAN', 'red'),
(22, 2, 8, 'StJeromeEmiliani', 'Saint Jerome Emiliani, priest', 2, 'Holy Men and Women:For Educators', 'GENERAL ROMAN', 'white'),
(23, 2, 8, 'StJosephineBakhita', 'Saint Josephine Bakhita, virgin', 2, 'Virgins:For One Virgin', 'GENERAL ROMAN', 'white'),
(24, 2, 10, 'StScholastica', 'Saint Scholastica, virgin', 3, 'Virgins:For One Virgin|Holy Men and Women:For a Nun', 'GENERAL ROMAN', 'white'),
(25, 2, 11, 'LadyLourdes', 'Our Lady of Lourdes', 2, 'Blessed Virgin Mary', 'GENERAL ROMAN', 'white'),
(26, 2, 14, 'StsCyrilMethodius', 'Saints Cyril, monk, and Methodius, bishop', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(27, 2, 17, 'SevenHolyFounders', 'Seven Holy Founders of the Servite Order', 2, 'Holy Men and Women:For Religious', 'GENERAL ROMAN', 'white'),
(28, 2, 21, 'StPeterDamian', 'Saint Peter Damian, bishop and doctor of the Church', 2, 'Doctors|Pastors:For a Bishop', 'GENERAL ROMAN', 'white'),
(29, 2, 22, 'ChairStPeter', 'Chair of Saint Peter, apostle', 4, 'Proper', 'GENERAL ROMAN', 'white'),
(30, 2, 23, 'StPolycarp', 'Saint Polycarp, bishop and martyr', 3, 'Martyrs:For One Martyr|Pastors:For a Bishop', 'GENERAL ROMAN', 'red|white'),
(31, 3, 4, 'StCasimir', 'Saint Casimir', 2, 'Holy Men and Women:For One Saint', 'GENERAL ROMAN', 'white'),
(32, 3, 7, 'StsPerpetuaFelicity', 'Saints Perpetua and Felicity, martyrs', 3, 'Proper', 'GENERAL ROMAN', 'red'),
(33, 3, 8, 'StJohnGod', 'Saint John of God, religious', 2, 'Holy Men and Women:For Religious|Holy Men and Women:For Those Who Practiced Works of Mercy', 'GENERAL ROMAN', 'white'),
(34, 3, 9, 'StFrancesRome', 'Saint Frances of Rome, religious', 2, 'Holy Men and Women:For Holy Women|Holy Men and Women:For Religious', 'GENERAL ROMAN', 'white'),
(35, 3, 17, 'StPatrick', 'Saint Patrick, bishop', 2, 'Pastors:For Missionaries|Pastors:For a Bishop', 'GENERAL ROMAN', 'white'),
(36, 3, 18, 'StCyrilJerusalem', 'Saint Cyril of Jerusalem, bishop and doctor', 2, 'Pastors:For a Bishop|Doctors', 'GENERAL ROMAN', 'white'),
(37, 3, 19, 'StJoseph', 'Saint Joseph Husband of the Blessed Virgin Mary', 6, 'Proper', 'GENERAL ROMAN', 'white'),
(38, 3, 23, 'StTuribius', 'Saint Turibius of Mogrovejo, bishop', 2, 'Pastors:For a Bishop', 'GENERAL ROMAN', 'white'),
(39, 3, 25, 'Annunciation', 'Annunciation of the Lord', 6, 'Proper', 'GENERAL ROMAN', 'white'),
(40, 4, 2, 'StFrancisPaola', 'Saint Francis of Paola, hermit', 2, 'Holy Men and Women:For Religious', 'GENERAL ROMAN', 'white'),
(41, 4, 4, 'StIsidore', 'Saint Isidore, bishop and doctor of the Church', 2, 'Pastors:For a Bishop|Doctors', 'GENERAL ROMAN', 'white'),
(42, 4, 5, 'StVincentFerrer', 'Saint Vincent Ferrer, priest', 2, 'Pastors:For Missionaries', 'GENERAL ROMAN', 'white'),
(43, 4, 7, 'StJohnBaptistDeLaSalle', 'Saint John Baptist de la Salle, priest', 3, 'Pastors:For One Pastor|Holy Men and Women:For Educators', 'GENERAL ROMAN', 'white'),
(44, 4, 11, 'StStanislaus', 'Saint Stanislaus, bishop and martyr', 3, 'Martyrs:For One Martyr|Pastors:For a Bishop', 'GENERAL ROMAN', 'red|white'),
(45, 4, 13, 'StMartinPope', 'Saint Martin I, pope and martyr', 2, 'Martyrs:For One Martyr|Pastors:For a Pope', 'GENERAL ROMAN', 'red|white'),
(46, 4, 21, 'StAnselm', 'Saint Anselm of Canterbury, bishop and doctor of the Church', 2, 'Pastors:For a Bishop|Doctors', 'GENERAL ROMAN', 'red|white'),
(47, 4, 23, 'StGeorge', 'Saint George, martyr', 2, 'Martyrs:For One Martyr', 'GENERAL ROMAN', 'red|white'),
(48, 4, 23, 'StAdalbert', 'Saint Adalbert, bishop and martyr', 2, 'Martyrs:For One Martyr|Pastors:For a Bishop', 'GENERAL ROMAN', 'red|white'),
(49, 4, 24, 'StFidelisSigmaringen', 'Saint Fidelis of Sigmaringen, priest and martyr', 2, 'Martyrs:For One Martyr|Pastors:For One Pastor', 'GENERAL ROMAN', 'red|white'),
(50, 4, 25, 'StMarkEvangelist', 'Saint Mark the Evangelist', 4, 'Proper', 'GENERAL ROMAN', 'red'),
(51, 4, 28, 'StPeterChanel', 'Saint Peter Chanel, priest and martyr', 2, 'Martyrs:For One Martyr|Pastors:For Missionaries', 'GENERAL ROMAN', 'red|white'),
(52, 4, 28, 'StLouisGrignonMontfort', 'Saint Louis Grignon de Montfort, priest', 2, 'Pastors:For One Pastor', 'GENERAL ROMAN', 'white'),
(53, 4, 29, 'StCatherineSiena', 'Saint Catherine of Siena, virgin and doctor of the Church', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(54, 4, 30, 'StPiusV', 'Saint Pius V, pope', 2, 'Pastors:For a Pope', 'GENERAL ROMAN', 'white'),
(55, 5, 1, 'StJosephWorker', 'Saint Joseph the Worker', 2, 'Proper', 'GENERAL ROMAN', 'white'),
(56, 5, 2, 'StAthanasius', 'Saint Athanasius, bishop and doctor', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(57, 5, 3, 'StsPhilipJames', 'Saints Philip and James, Apostles', 4, 'Proper', 'GENERAL ROMAN', 'red'),
(58, 5, 12, 'StsNereusAchilleus', 'Saints Nereus and Achilleus, martyrs', 2, 'Martyrs:For Several Martyrs', 'GENERAL ROMAN', 'red'),
(59, 5, 12, 'StPancras', 'Saint Pancras, martyr', 2, 'Martyrs:For One Martyr', 'GENERAL ROMAN', 'red'),
(60, 5, 13, 'LadyFatima', 'Our Lady of Fatima', 2, 'Blessed Virgin Mary', 'GENERAL ROMAN', 'white'),
(61, 5, 14, 'StMatthiasAp', 'Saint Matthias the Apostle', 4, 'Proper', 'GENERAL ROMAN', 'red'),
(62, 5, 18, 'StJohnIPope', 'Saint John I, pope and martyr', 2, 'Martyrs:For One Martyr|Pastors:For a Pope', 'GENERAL ROMAN', 'red|white'),
(63, 5, 20, 'StBernardineSiena', 'Saint Bernardine of Siena, priest', 2, 'Pastor:For Missonaries|Holy Men and Women:For Religious', 'GENERAL ROMAN', 'white'),
(64, 5, 21, 'StChristopherMagallanes', 'Saint Christopher Magallanes and companions, martyrs', 2, 'Martyrs:For Several Martyrs', 'GENERAL ROMAN', 'red'),
(65, 5, 22, 'StRitaCascia', 'Saint Rita of Cascia', 2, 'Holy Men and Women:For Religious', 'GENERAL ROMAN', 'white'),
(66, 5, 25, 'StBedeVenerable', 'Saint Bede the Venerable, priest and doctor', 2, 'Doctors|Holy Men and Women:For a Monk', 'GENERAL ROMAN', 'white'),
(67, 5, 25, 'StGregoryVII', 'Saint Gregory VII, pope', 2, 'Pastors:For a Pope', 'GENERAL ROMAN', 'white'),
(68, 5, 25, 'StMaryMagdalenePazzi', 'Saint Mary Magdalene de Pazzi, virgin', 2, 'Virgins:For One Virgin|Holy Men and Women:For Religious', 'GENERAL ROMAN', 'white'),
(69, 5, 26, 'StPhilipNeri', 'Saint Philip Neri, priest', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(70, 5, 27, 'StAugustineCanterbury', 'Saint Augustine of Canterbury, bishop', 2, 'Pastors:For Missionaries|Pastors:For a Bishop', 'GENERAL ROMAN', 'white'),
(71, 5, 31, 'Visitation', 'Visitation of the Blessed Virgin Mary', 4, 'Proper', 'GENERAL ROMAN', 'white'),
(72, 6, 1, 'StJustinMartyr', 'Saint Justin Martyr', 3, 'Proper', 'GENERAL ROMAN', 'red'),
(73, 6, 2, 'StsMarcellinusPeter', 'Saints Marcellinus and Peter, martyrs', 2, 'Martyrs:For Several Martyrs', 'GENERAL ROMAN', 'red'),
(74, 6, 3, 'StsCharlesLwanga', 'Saints Charles Lwanga and companions, martyrs', 3, 'Proper', 'GENERAL ROMAN', 'red'),
(75, 6, 5, 'StBoniface', 'Saint Boniface, bishop and martyr', 3, 'Martyrs:For One Martyr|Pastors:For Missionaries', 'GENERAL ROMAN', 'red|white'),
(76, 6, 6, 'StNorbert', 'Saint Norbert, bishop', 2, 'Pastors:For a Bishop|Holy Men and Women:For Religious', 'GENERAL ROMAN', 'white'),
(77, 6, 9, 'StEphrem', 'Saint Ephrem, deacon and doctor', 2, 'Doctors', 'GENERAL ROMAN', 'white'),
(78, 6, 11, 'StBarnabasAp', 'Saint Barnabas the Apostle', 3, 'Proper', 'GENERAL ROMAN', 'red'),
(79, 6, 13, 'StAnthonyPadua', 'Saint Anthony of Padua, priest and doctor', 3, 'Pastors:For One Pastor|Doctors|Holy Men and Women:For Religious', 'GENERAL ROMAN', 'white'),
(80, 6, 19, 'StRomuald', 'Saint Romuald, abbot', 2, 'Holy Men and Women:For an Abbot', 'GENERAL ROMAN', 'white'),
(81, 6, 21, 'StAloysiusGonzaga', 'Saint Aloysius Gonzaga, religious', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(82, 6, 22, 'StPaulinusNola', 'Saint Paulinus of Nola, bishop', 2, 'Pastors:For a Bishop', 'GENERAL ROMAN', 'white'),
(83, 6, 22, 'StsJohnFisherThomasMore', 'Saints John Fisher, bishop and martyr and Thomas More, martyr', 2, 'Martyrs:For Several Martyrs', 'GENERAL ROMAN', 'red'),
(84, 6, 24, 'NativityJohnBaptist', 'Nativity of Saint John the Baptist', 6, 'Proper', 'GENERAL ROMAN', 'white'),
(85, 6, 27, 'StCyrilAlexandria', 'Saint Cyril of Alexandria, bishop and doctor', 2, 'Pastors:For a Bishop|Doctors', 'GENERAL ROMAN', 'white'),
(86, 6, 28, 'StIrenaeus', 'Saint Irenaeus, bishop and martyr', 3, 'Proper', 'GENERAL ROMAN', 'red|white'),
(87, 6, 29, 'StsPeterPaulAp', 'Saints Peter and Paul, Apostles', 6, 'Proper', 'GENERAL ROMAN', 'red'),
(88, 6, 30, 'FirstMartyrsRome', 'First Martyrs of the Church of Rome', 2, 'Martyrs:For Several Martyrs', 'GENERAL ROMAN', 'red'),
(89, 7, 3, 'StThomasAp', 'Saint Thomas the Apostle', 4, 'Proper', 'GENERAL ROMAN', 'red'),
(90, 7, 4, 'StElizabethPortugal', 'Saint Elizabeth of Portugal', 2, 'Holy Men and Women:For Those Who Practiced Works of Mercy', 'GENERAL ROMAN', 'white'),
(91, 7, 5, 'StAnthonyZaccaria', 'Saint Anthony Zaccaria, priest', 2, 'Pastors:For One Pastor|Holy Men and Women:For Educators|Holy Men and Women:For Religious', 'GENERAL ROMAN', 'white'),
(92, 7, 6, 'StMariaGoretti', 'Saint Maria Goretti, virgin and martyr', 2, 'Martyrs:For a Virgin Martyr|Virgins:For One Virgin', 'GENERAL ROMAN', 'red|white'),
(93, 7, 9, 'StAugustineZhaoRong', 'Saint Augustine Zhao Rong and companions, martyrs', 2, 'Martyrs:For Several Martyrs', 'GENERAL ROMAN', 'red'),
(94, 7, 11, 'StBenedict', 'Saint Benedict, abbot', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(95, 7, 13, 'StHenry', 'Saint Henry', 2, 'Holy Men and Women:For One Saint', 'GENERAL ROMAN', 'white'),
(96, 7, 14, 'StCamillusDeLellis', 'Saint Camillus de Lellis, priest', 2, 'Holy Men and Women:For Those Who Practiced Works of Mercy', 'GENERAL ROMAN', 'white'),
(97, 7, 15, 'StBonaventure', 'Saint Bonaventure, bishop and doctor', 3, 'Pastors:For a Bishop|Doctors', 'GENERAL ROMAN', 'white'),
(98, 7, 16, 'LadyMountCarmel', 'Our Lady of Mount Carmel', 2, 'Blessed Virgin Mary', 'GENERAL ROMAN', 'white'),
(99, 7, 20, 'StApollinaris', 'Saint Apollinaris, bishop and martyr', 2, 'Martyrs:For One Martyr|Pastors:For a Bishop', 'GENERAL ROMAN', 'red|white'),
(100, 7, 21, 'StLawrenceBrindisi', 'Saint Lawrence of Brindisi, priest and doctor', 2, 'Pastors:For One Pastor|Doctors|Holy Men and Women:For Religious', 'GENERAL ROMAN', 'white'),
(101, 7, 22, 'StMaryMagdalene', 'Saint Mary Magdalene', 4, 'Proper', 'GENERAL ROMAN', 'white'),
(102, 7, 23, 'StBridget', 'Saint Bridget, religious', 2, 'Holy Men and Women:For Holy Women|Holy Men and Women:For Religious', 'GENERAL ROMAN', 'white'),
(103, 7, 24, 'StSharbelMakhluf', 'Saint Sharbel Makhluf, hermit', 2, 'Pastors:For One Pastor|Holy Men and Women:For a Monk', 'GENERAL ROMAN', 'white'),
(104, 7, 25, 'StJamesAp', 'Saint James, apostle', 4, 'Proper', 'GENERAL ROMAN', 'red'),
(105, 7, 26, 'StsJoachimAnne', 'Saints Joachim and Anne', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(106, 7, 29, 'StMartha', 'Saint Martha', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(107, 7, 30, 'StPeterChrysologus', 'Saint Peter Chrysologus, bishop and doctor', 2, 'Pastors:For a Bishop|Doctors', 'GENERAL ROMAN', 'white'),
(108, 7, 31, 'StIgnatiusLoyola', 'Saint Ignatius of Loyola, priest', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(109, 8, 1, 'StAlphonsusMariaDeLiguori', 'Saint Alphonsus Maria de Liguori, bishop and doctor of the Church', 3, 'Pastors:For a Bishop|Doctors', 'GENERAL ROMAN', 'white'),
(110, 8, 2, 'StEusebius', 'Saint Eusebius of Vercelli, bishop', 2, 'Pastors:For a Bishop', 'GENERAL ROMAN', 'white'),
(111, 8, 2, 'StPeterJulianEymard', 'Saint Peter Julian Eymard, priest', 2, 'Holy Men and Women:For Religious|Pastors:For One Pastor', 'GENERAL ROMAN', 'white'),
(112, 8, 4, 'StJeanVianney', 'Saint Jean Vianney (the Curé of Ars), priest', 3, 'Pastors:For One Pastor', 'GENERAL ROMAN', 'white'),
(113, 8, 5, 'DedicationStMaryMajor', 'Dedication of the Basilica of Saint Mary Major', 2, 'Blessed Virgin Mary', 'GENERAL ROMAN', 'white'),
(114, 8, 6, 'Transfiguration', 'Transfiguration of the Lord', 5, 'Proper', 'GENERAL ROMAN', 'white'),
(115, 8, 7, 'StSixtusIIPope', 'Saint Sixtus II, pope, and companions, martyrs', 2, 'Martyrs:For Several Martyrs', 'GENERAL ROMAN', 'red'),
(116, 8, 7, 'StCajetan', 'Saint Cajetan, priest', 2, 'Pastors:For One Pastor|Holy Men and Women:For Religious', 'GENERAL ROMAN', 'white'),
(117, 8, 8, 'StDominic', 'Saint Dominic, priest', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(118, 8, 9, 'StEdithStein', 'Saint Teresa Benedicta of the Cross (Edith Stein), virgin and martyr', 2, 'Martyrs:For a Virgin Martyr|Virgins:For One Virgin', 'GENERAL ROMAN', 'red|white'),
(119, 8, 10, 'StLawrenceDeacon', 'Saint Lawrence, deacon and martyr', 4, 'Proper', 'GENERAL ROMAN', 'red|white'),
(120, 8, 11, 'StClare', 'Saint Clare, virgin', 3, 'Virgins:For One Virgin|Holy Men and Women:For a Nun', 'GENERAL ROMAN', 'white'),
(121, 8, 12, 'StJaneFrancesDeChantal', 'Saint Jane Frances de Chantal, religious', 2, 'Holy Men and Women:For Religious', 'GENERAL ROMAN', 'white'),
(122, 8, 13, 'StsPontianHippolytus', 'Saints Pontian, pope, and Hippolytus, priest, martyrs', 2, 'Martyrs:For Several Martyrs|Pastors:For Several Pastors', 'GENERAL ROMAN', 'red|white'),
(123, 8, 14, 'StMaximilianKolbe', 'Saint Maximilian Mary Kolbe, priest and martyr', 3, 'Proper', 'GENERAL ROMAN', 'red|white'),
(124, 8, 15, 'Assumption', 'Assumption of the Blessed Virgin Mary', 6, 'Proper', 'GENERAL ROMAN', 'white'),
(125, 8, 16, 'StStephenHungary', 'Saint Stephen of Hungary', 2, 'Holy Men and Women:For One Saint', 'GENERAL ROMAN', 'white'),
(126, 8, 19, 'StJohnEudes', 'Saint John Eudes, priest', 2, 'Pastors:For One Pastor|Holy Men and Women:For Religious', 'GENERAL ROMAN', 'white'),
(127, 8, 20, 'StBernardClairvaux', 'Saint Bernard of Clairvaux, abbot and doctor of the Church', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(128, 8, 21, 'StPiusX', 'Saint Pius X, pope', 3, 'Pastors:For a Pope', 'GENERAL ROMAN', 'white'),
(129, 8, 22, 'QueenshipMary', 'Queenship of Blessed Virgin Mary', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(130, 8, 23, 'StRoseLima', 'Saint Rose of Lima, virgin', 2, 'Virgins:For One Virgin', 'GENERAL ROMAN', 'white'),
(131, 8, 24, 'StBartholomewAp', 'Saint Bartholomew the Apostle', 4, 'Proper', 'GENERAL ROMAN', 'red'),
(132, 8, 25, 'StLouis', 'Saint Louis', 2, 'Holy Men and Women:For One Saint', 'GENERAL ROMAN', 'white'),
(133, 8, 25, 'StJosephCalasanz', 'Saint Joseph Calasanz, priest', 2, 'Holy Men and Women:For Educators|Pastors:For One Pastor', 'GENERAL ROMAN', 'white'),
(134, 8, 27, 'StMonica', 'Saint Monica', 3, 'Holy Men and Women:For Holy Women', 'GENERAL ROMAN', 'white'),
(135, 8, 28, 'StAugustineHippo', 'Saint Augustine of Hippo, bishop and doctor of the Church', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(136, 8, 29, 'BeheadingJohnBaptist', 'The Beheading of Saint John the Baptist, martyr', 3, 'Proper', 'GENERAL ROMAN', 'red'),
(137, 9, 3, 'StGregoryGreat', 'Saint Gregory the Great, pope and doctor', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(138, 9, 8, 'NativityVirginMary', 'Nativity of the Blessed Virgin Mary', 4, 'Proper', 'GENERAL ROMAN', 'white'),
(139, 9, 9, 'StPeterClaver', 'Saint Peter Claver, priest', 2, 'Pastors:For One Pastor|Holy Men and Women:For Those Who Practiced Works of Mercy', 'GENERAL ROMAN', 'white'),
(140, 9, 12, 'HolyNameMary', 'Holy Name of the Blessed Virgin Mary', 2, 'Proper', 'GENERAL ROMAN', 'white'),
(141, 9, 13, 'StJohnChrysostom', 'Saint John Chrysostom, bishop and doctor', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(142, 9, 14, 'ExaltationCross', 'Exaltation of the Holy Cross', 5, 'Proper', 'GENERAL ROMAN', 'red'),
(143, 9, 15, 'LadySorrows', 'Our Lady of Sorrows', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(144, 9, 16, 'StsCorneliusCyprian', 'Saints Cornelius, pope, and Cyprian, bishop, martyrs', 3, 'Martyrs:For Several Martyrs|Pastors:For a Bishop', 'GENERAL ROMAN', 'red|white'),
(145, 9, 17, 'StRobertBellarmine', 'Saint Robert Bellarmine, bishop and doctor', 2, 'Pastors:For a Bishop|Doctors', 'GENERAL ROMAN', 'white'),
(146, 9, 19, 'StJanuarius', 'Saint Januarius, bishop and martyr', 2, 'Martyrs:For One Martyr|Pastors:For a Bishop', 'GENERAL ROMAN', 'red|white'),
(147, 9, 20, 'StAndrewKimTaegon', 'Saint Andrew Kim Taegon, priest, and Paul Chong Hasang and companions, martyrs', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(148, 9, 21, 'StMatthewEvangelist', 'Saint Matthew the Evangelist, Apostle', 4, 'Proper', 'GENERAL ROMAN', 'red'),
(149, 9, 23, 'StPadrePio', 'Saint Pius of Pietrelcina (Padre Pio), priest', 3, 'Pastors:For One Pastor|Holy Men and Women:For Religious', 'GENERAL ROMAN', 'white'),
(150, 9, 26, 'StsCosmasDamian', 'Saints Cosmas and Damian, martyrs', 2, 'Martyrs:For Several Martyrs', 'GENERAL ROMAN', 'red'),
(151, 9, 27, 'StVincentDePaul', 'Saint Vincent de Paul, priest', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(152, 9, 28, 'StWenceslaus', 'Saint Wenceslaus, martyr', 2, 'Martyrs:For One Martyr', 'GENERAL ROMAN', 'red'),
(153, 9, 28, 'StsLawrenceRuiz', 'Saints Lawrence Ruiz and companions, martyrs', 2, 'Martyrs:For Several Martyrs', 'GENERAL ROMAN', 'red'),
(154, 9, 29, 'StsArchangels', 'Saints Michael, Gabriel and Raphael, Archangels', 4, 'Proper', 'GENERAL ROMAN', 'white'),
(155, 9, 30, 'StJerome', 'Saint Jerome, priest and doctor', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(156, 10, 1, 'StThereseChildJesus', 'Saint Thérèse of the Child Jesus, virgin and doctor', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(157, 10, 2, 'GuardianAngels', 'Guardian Angels', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(158, 10, 4, 'StFrancisAssisi', 'Saint Francis of Assisi', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(159, 10, 6, 'StBruno', 'Saint Bruno, priest', 2, 'Holy Men and Women:For a Monk|Pastors:For One Pastor', 'GENERAL ROMAN', 'white'),
(160, 10, 7, 'LadyRosary', 'Our Lady of the Rosary', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(161, 10, 9, 'StDenis', 'Saint Denis, bishop, and companions, martyrs', 2, 'Martyrs:For Several Martyrs', 'GENERAL ROMAN', 'red'),
(162, 10, 9, 'StJohnLeonardi', 'Saint John Leonardi, priest', 2, 'Pastors:For Missionaries|Holy Men and Women:For Those Who Practiced Works of Mercy', 'GENERAL ROMAN', 'white'),
(163, 10, 11, 'StJohnXXIII', 'Saint John XXIII, pope', 2, '', 'GENERAL ROMAN', 'white'),
(164, 10, 14, 'StCallistusIPope', 'Saint Callistus I, pope and martyr', 2, 'Martyrs:For One Martyr|Pastors:For a Pope', 'GENERAL ROMAN', 'red|white'),
(165, 10, 15, 'StTeresaJesus', 'Saint Teresa of Jesus, virgin and doctor', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(166, 10, 16, 'StHedwig', 'Saint Hedwig, religious', 2, 'Holy Men and Women:For Religious|Holy Men and Women:For Holy Women', 'GENERAL ROMAN', 'white'),
(167, 10, 16, 'StMargaretAlacoque', 'Saint Margaret Mary Alacoque, virgin', 2, 'Virgins:For One Virgin', 'GENERAL ROMAN', 'white'),
(168, 10, 17, 'StIgnatiusAntioch', 'Saint Ignatius of Antioch, bishop and martyr', 3, 'Proper', 'GENERAL ROMAN', 'red|white'),
(169, 10, 18, 'StLukeEvangelist', 'Saint Luke the Evangelist', 4, 'Proper', 'GENERAL ROMAN', 'red'),
(170, 10, 19, 'StsJeanBrebeuf', 'Saints Jean de Brébeuf, Isaac Jogues, priests, and companions, martyrs', 2, 'Martyrs:For Missionary Martyrs', 'GENERAL ROMAN', 'red'),
(171, 10, 19, 'StPaulCross', 'Saint Paul of the Cross, priest', 2, 'Proper', 'GENERAL ROMAN', 'white'),
(172, 10, 22, 'StJohnPaulIIPope', 'Saint John Paul II, pope', 2, '', 'GENERAL ROMAN', 'white'),
(173, 10, 23, 'StJohnCapistrano', 'Saint John of Capistrano, priest', 2, 'Pastors:For Missionaries|Holy Men and Women:For Religious', 'GENERAL ROMAN', 'white'),
(174, 10, 24, 'StAnthonyMaryClaret', 'Saint Anthony Mary Claret, bishop', 2, 'Pastors:For Missionaries|Pastors:For a Bishop', 'GENERAL ROMAN', 'white'),
(175, 10, 28, 'StSimonStJudeAp', 'Saint Simon and Saint Jude, apostles', 4, 'Proper', 'GENERAL ROMAN', 'red'),
(176, 11, 1, 'AllSaints', 'All Saints\' Day', 6, 'Proper', 'GENERAL ROMAN', 'white'),
(177, 11, 2, 'AllSouls', 'Commemoration of all the Faithful Departed (All Souls\' Day)', 6, 'Proper', 'GENERAL ROMAN', 'purple'),
(178, 11, 3, 'StMartinPorres', 'Saint Martin de Porres, religious', 2, 'Holy Men and Women:For Religious', 'GENERAL ROMAN', 'white'),
(179, 11, 4, 'StCharlesBorromeo', 'Saint Charles Borromeo, bishop', 3, 'Pastors:For a Bishop', 'GENERAL ROMAN', 'white'),
(180, 11, 9, 'DedicationLateran', 'Dedication of the Lateran basilica', 4, 'Proper', 'GENERAL ROMAN', 'white'),
(181, 11, 10, 'StLeoGreat', 'Saint Leo the Great, pope and doctor', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(182, 11, 11, 'StMartinTours', 'Saint Martin of Tours, bishop', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(183, 11, 12, 'StJosaphat', 'Saint Josaphat, bishop and martyr', 3, 'Proper', 'GENERAL ROMAN', 'red|white'),
(184, 11, 15, 'StAlbertGreat', 'Saint Albert the Great, bishop and doctor', 2, 'Pastors:For a Bishop|Doctors', 'GENERAL ROMAN', 'white'),
(185, 11, 16, 'StMargaretScotland', 'Saint Margaret of Scotland', 2, 'Holy Men and Women:For Those Who Practiced Works of Mercy', 'GENERAL ROMAN', 'white'),
(186, 11, 16, 'StGertrudeGreat', 'Saint Gertrude the Great, virgin', 2, 'Virgins:For One Virgin|Holy Men and Women:For a Nun', 'GENERAL ROMAN', 'white'),
(187, 11, 17, 'StElizabethHungary', 'Saint Elizabeth of Hungary, religious', 3, 'Holy Men and Women:For Those Who Practiced Works of Mercy', 'GENERAL ROMAN', 'white'),
(188, 11, 18, 'DedicationStsPeterPaul', 'Dedication of the basilicas of Saints Peter and Paul, Apostles', 2, 'Proper', 'GENERAL ROMAN', 'white'),
(189, 11, 21, 'PresentationMary', 'Presentation of the Blessed Virgin Mary', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(190, 11, 22, 'StCecilia', 'Saint Cecilia, virgin and martyr', 3, 'Martyrs:For a Virgin Martyr|Virgins:For One Virgin', 'GENERAL ROMAN', 'red|white'),
(191, 11, 23, 'StClementIPope', 'Saint Clement I, pope and martyr', 2, 'Martyrs:For One Martyr|Pastors:For a Pope', 'GENERAL ROMAN', 'red|white'),
(192, 11, 23, 'StColumban', 'Saint Columban, religious', 2, 'Pastors:For Missionaries|Holy Men and Women:For an Abbot', 'GENERAL ROMAN', 'white'),
(193, 11, 24, 'StAndrewDungLac', 'Saint Andrew Dung-Lac and his companions, martyrs', 3, 'Proper', 'GENERAL ROMAN', 'red'),
(194, 11, 25, 'StCatherineAlexandria', 'Saint Catherine of Alexandria, virgin and martyr', 2, 'Martyrs:For a Virgin Martyr|Virgins:For One Virgin', 'GENERAL ROMAN', 'red|white'),
(195, 11, 30, 'StAndrewAp', 'Saint Andrew the Apostle', 4, 'Proper', 'GENERAL ROMAN', 'red'),
(196, 12, 3, 'StFrancisXavier', 'Saint Francis Xavier, priest', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(197, 12, 4, 'StJohnDamascene', 'Saint John Damascene, priest and doctor', 2, 'Pastors:For One Pastor|Doctors', 'GENERAL ROMAN', 'white'),
(198, 12, 6, 'StNicholas', 'Saint Nicholas, bishop', 2, 'Pastors:For a Bishop', 'GENERAL ROMAN', 'white'),
(199, 12, 7, 'StAmbrose', 'Saint Ambrose, bishop and doctor', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(200, 12, 8, 'ImmaculateConception', 'Immaculate Conception of the Blessed Virgin Mary', 6, 'Proper', 'GENERAL ROMAN', 'white'),
(201, 12, 9, 'StJuanDiego', 'Saint Juan Diego', 2, 'Holy Men and Women:For One Saint', 'GENERAL ROMAN', 'white'),
(202, 12, 11, 'StDamasusIPope', 'Saint Damasus I, pope', 2, 'Pastors:For a Pope', 'GENERAL ROMAN', 'white'),
(203, 12, 12, 'LadyGuadalupe', 'Our Lady of Guadalupe', 2, '', 'GENERAL ROMAN', 'white'),
(204, 12, 13, 'StLucySyracuse', 'Saint Lucy of Syracuse, virgin and martyr', 3, 'Martyrs:For a Virgin Martyr|Virgins:For One Virgin', 'GENERAL ROMAN', 'red|white'),
(205, 12, 14, 'StJohnCross', 'Saint John of the Cross, priest and doctor', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(206, 12, 21, 'StPeterCanisius', 'Saint Peter Canisius, priest and doctor', 2, 'Pastors:For One Pastor|Doctors', 'GENERAL ROMAN', 'white'),
(207, 12, 23, 'StJohnKanty', 'Saint John of Kanty, priest', 2, 'Pastors:For One Pastor|Holy Men and Women:For Those Who Practiced Works of Mercy', 'GENERAL ROMAN', 'white'),
(208, 12, 25, 'Christmas', 'Nativity of the Lord', 6, 'Proper', 'GENERAL ROMAN', 'white'),
(209, 12, 26, 'StStephenProtomartyr', 'Saint Stephen, the first martyr', 4, 'Proper', 'GENERAL ROMAN', 'red'),
(210, 12, 27, 'StJohnEvangelist', 'Saint John, Apostle and Evangelist', 4, 'Proper', 'GENERAL ROMAN', 'white'),
(211, 12, 28, 'HolyInnnocents', 'Holy Innocents, martyrs', 4, 'Proper', 'GENERAL ROMAN', 'red'),
(212, 12, 29, 'StThomasBecket', 'Saint Thomas Becket, bishop and martyr', 2, 'Martyrs:For One Martyr|Pastors:For a Bishop', 'GENERAL ROMAN', 'red|white'),
(213, 12, 31, 'StSylvesterIPope', 'Saint Sylvester I, pope', 2, 'Pastors:For a Pope', 'GENERAL ROMAN', 'white');

-- --------------------------------------------------------

--
-- Table structure for table `LITURGY__festivity_grade`
--

CREATE TABLE `LITURGY__festivity_grade` (
  `IDX` int(11) NOT NULL,
  `NAME` varchar(60) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Dumping data for table `LITURGY__festivity_grade`
--

INSERT INTO `LITURGY__festivity_grade` (`IDX`, `NAME`) VALUES
(1, 'COMMEMORATION'),
(2, 'OPTIONAL MEMORIAL'),
(3, 'MEMORIAL'),
(4, 'FEAST'),
(5, 'FEAST OF THE LORD'),
(6, 'SOLEMNITY'),
(7, 'HIGHER SOLEMNITY');

-- --------------------------------------------------------

--
-- Table structure for table `LITURGY__months`
--

CREATE TABLE `LITURGY__months` (
  `IDX` int(11) NOT NULL,
  `NAME` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

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
-- Indexes for table `LITURGY__calendar_fixed`
--
ALTER TABLE `LITURGY__calendar_fixed`
  ADD PRIMARY KEY (`RECURRENCE_ID`),
  ADD UNIQUE KEY `TAG` (`TAG`),
  ADD KEY `MONTH_NAME` (`MONTH`),
  ADD KEY `FESTIVITY_GRADE` (`GRADE`);

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
-- AUTO_INCREMENT for table `LITURGY__calendar_fixed`
--
ALTER TABLE `LITURGY__calendar_fixed`
  MODIFY `RECURRENCE_ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=214;
--
-- Constraints for dumped tables
--

--
-- Constraints for table `LITURGY__calendar_fixed`
--
ALTER TABLE `LITURGY__calendar_fixed`
  ADD CONSTRAINT `FESTIVITY_GRADE` FOREIGN KEY (`GRADE`) REFERENCES `LITURGY__festivity_grade` (`IDX`),
  ADD CONSTRAINT `MONTH_NAME` FOREIGN KEY (`MONTH`) REFERENCES `LITURGY__months` (`IDX`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
