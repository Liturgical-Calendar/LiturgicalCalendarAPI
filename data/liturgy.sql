-- phpMyAdmin SQL Dump
-- version 5.0.2
-- https://www.phpmyadmin.net/
--
-- Host: vps94844.ovh.net
-- Generation Time: Nov 12, 2020 at 08:23 AM
-- Server version: 5.7.25
-- PHP Version: 7.4.12

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
-- Creation: Aug 08, 2020 at 01:48 AM
-- Last update: Oct 29, 2020 at 04:03 PM
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
  `COMMON` set('Proper','Dedication of a Church','Blessed Virgin Mary','Martyrs','Martyrs:For Several Martyrs','Martyrs:For One Martyr','Martyrs:For Missionary Martyrs','Martyrs:For Several Missionary Martyrs','Martyrs:For One Missionary Martyr','Martyrs:For a Virgin Martyr','Martyrs:For a Holy Woman Martyr','Pastors','Pastors:For a Pope','Pastors:For a Bishop','Pastors:For Several Pastors','Pastors:For One Pastor','Pastors:For Founders of a Church','Pastors:For Several Founders','Pastors:For One Founder','Pastors:For Missionaries','Doctors','Virgins','Virgins:For Several Virgins','Virgins:For One Virgin','Holy Men and Women','Holy Men and Women:For Several Saints','Holy Men and Women:For One Saint','Holy Men and Women:For an Abbot','Holy Men and Women:For a Monk','Holy Men and Women:For a Nun','Holy Men and Women:For Religious','Holy Men and Women:For Those Who Practiced Works of Mercy','Holy Men and Women:For Educators','Holy Men and Women:For Holy Women') NOT NULL,
  `CALENDAR` varchar(50) NOT NULL,
  `COLOR` set('green','purple','white','red','pink') NOT NULL
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
(2, 1, 2, 'StsBasilGreg', 'Sancti Basilii Magni et Gregorii Nazianzeni, episcoporum et Ecclesiæ doctorum', 'Saints Basil the Great and Gregory Nazianzen, bishops and doctors', 'Santi Basilio Magno e Gregorio Nazianzeno, vescovi e dottori', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(4, 1, 7, 'StRayPenyafort', 'Sancti Raimundi de Penyafort, presbyteri', 'Saint Raymond of Penyafort, priest', 'San Raimondo di Peñafort, sacerdote', 2, 'Pastors:For One Pastor', 'GENERAL ROMAN', 'white'),
(5, 1, 13, 'StHilaryPoitiers', 'Sancti Hilarii, episcopi et Ecclesiæ doctoris', 'Saint Hilary of Poitiers, bishop and doctor', 'Sant\'Ilario di Poitiers, vescovo e dottore', 2, 'Pastors:For a Bishop,Doctors', 'GENERAL ROMAN', 'white'),
(6, 1, 17, 'StAnthonyEgypt', 'Sancti Antonii, abbatis', 'Saint Anthony of Egypt, abbot', 'Sant\'Antonio di Egitto, abate', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(7, 1, 20, 'StFabianPope', 'Sancti Fabiani, papæ et martyris', 'Saint Fabian, pope and martyr', 'San Fabiano, papa e martire', 2, 'Martyrs:For One Martyr,Pastors:For a Pope', 'GENERAL ROMAN', 'white,red'),
(8, 1, 20, 'StSebastian', 'Sancti Sebastiani, martyris', 'Saint Sebastian, martyr', 'San Sebastiano, martire', 2, 'Martyrs:For One Martyr', 'GENERAL ROMAN', 'red'),
(9, 1, 21, 'StAgnes', 'Sanctæ Agnetis, virginis et martyris', 'Saint Agnes, virgin and martyr', 'Sant\'Agnese, vergine e martire', 3, 'Martyrs:For a Virgin Martyr,Virgins:For One Virgin', 'GENERAL ROMAN', 'white,red'),
(10, 1, 22, 'StVincentDeacon', 'Sancti Vincentii, diaconi et martyris', 'Saint Vincent, deacon and martyr', 'San Vincenzo, diacono e martire', 2, 'Martyrs:For One Martyr', 'GENERAL ROMAN', 'red'),
(11, 1, 24, 'StFrancisDeSales', 'Sancti Francisci de Sales, episcopi et Ecclesiæ doctoris', 'Saint Francis de Sales, bishop and doctor', 'San Francesco de Sales, vescovo e dottore', 3, 'Pastors:For a Bishop,Doctors', 'GENERAL ROMAN', 'white'),
(12, 1, 25, 'ConversionStPaul', 'In Conversione Sancti Pauli, Apostoli', 'The Conversion of Saint Paul, apostle', 'Conversione di San Paolo, apostolo', 4, 'Proper', 'GENERAL ROMAN', 'white'),
(13, 1, 26, 'StsTimothyTitus', 'Sanctorum Timothei et Titi, episcoporum', 'Saints Timothy and Titus, bishops', 'Santi Timoteo e Tito, vescovi', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(14, 1, 27, 'StAngelaMerici', 'Sanctæ Angelæ Merici, virginis', 'Saint Angela Merici, virgin', 'Sant\'Angela Merici, vergine', 2, 'Virgins:For One Virgin,Holy Men and Women:For Educators', 'GENERAL ROMAN', 'white'),
(15, 1, 28, 'StThomasAquinas', 'Sancti Thomæ de Aquino, presbyteri et Ecclesiæ doctoris', 'Saint Thomas Aquinas, priest and doctor', 'San Tommaso d\'Aquino, sacerdote e dottore', 3, 'Pastors:For One Pastor,Doctors', 'GENERAL ROMAN', 'white'),
(16, 1, 31, 'StJohnBosco', 'Sancti Ioannis Bosco, presbyteri', 'Saint John Bosco, priest', 'San Giovanni Bosco, sacerdote', 3, 'Pastors:For One Pastor,Holy Men and Women:For Educators', 'GENERAL ROMAN', 'white'),
(17, 2, 2, 'Presentation', 'In Præsentatione Domini', 'Presentation of the Lord', 'Presentazione del Signore', 5, 'Proper', 'GENERAL ROMAN', 'white'),
(18, 2, 3, 'StBlase', 'Sancti Blasii, episcopi et martyris', 'Saint Blase, bishop and martyr', 'San Biagio, vescovo e martire', 2, 'Martyrs:For One Martyr,Pastors:For a Bishop', 'GENERAL ROMAN', 'white,red'),
(19, 2, 3, 'StAnsgar', 'Sancti Ansgarii, episcopi', 'Saint Ansgar, bishop', 'Sant\'Ansgario (Oscar), vescovo', 2, 'Pastors:For a Bishop,Pastors:For Missionaries', 'GENERAL ROMAN', 'white'),
(20, 2, 5, 'StAgatha', 'Sanctæ Agathæ, virginis et martyris', 'Saint Agatha, virgin and martyr', 'Sant\'Agata, vergine e martire', 3, 'Martyrs:For a Virgin Martyr,Virgins:For One Virgin', 'GENERAL ROMAN', 'white,red'),
(21, 2, 6, 'StsPaulMiki', 'Sanctorum Pauli Miki et sociorum, martyrum', 'Saints Paul Miki and companions, martyrs', 'Santi Paolo Miki e compagni, martiri', 3, 'Martyrs:For Several Martyrs', 'GENERAL ROMAN', 'red'),
(22, 2, 8, 'StJeromeEmiliani', 'Sancti Hieronymi Emiliani', 'Saint Jerome Emiliani, priest', 'San Girolamo Emiliani', 2, 'Holy Men and Women:For Educators', 'GENERAL ROMAN', 'white'),
(24, 2, 10, 'StScholastica', 'Sanctæ Scholasticæ, virginis', 'Saint Scholastica, virgin', 'Santa Scolastica, vergine', 3, 'Virgins:For One Virgin,Holy Men and Women:For a Nun', 'GENERAL ROMAN', 'white'),
(25, 2, 11, 'LadyLourdes', 'Beatæ Mariæ Virginis de Lourdes', 'Our Lady of Lourdes', 'Beata Maria Vergine di Lourdes', 2, 'Blessed Virgin Mary', 'GENERAL ROMAN', 'white'),
(26, 2, 14, 'StsCyrilMethodius', 'Sancti Cyrilli, monachi, et Methodii, episcopi', 'Saints Cyril, monk, and Methodius, bishop', 'Santi Cirillo, monaco, e Metodio, vescovo', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(27, 2, 17, 'SevenHolyFounders', 'Sanctorum Septem Fundatorum Ordinis Servorum B. M. V.', 'Seven Holy Founders of the Servite Order', 'Santi Sette Fondatori dei Servi della Beata Maria Vergine', 2, 'Holy Men and Women:For Religious', 'GENERAL ROMAN', 'white'),
(28, 2, 21, 'StPeterDamian', 'Sancti Petri Damiani, episcopi et Ecclesiæ doctoris', 'Saint Peter Damian, bishop and doctor of the Church', 'San Pier Damiani, vescovo e dottore della Chiesa', 2, 'Pastors:For a Bishop,Doctors', 'GENERAL ROMAN', 'white'),
(29, 2, 22, 'ChairStPeter', 'Cathedræ S. Petri, Apostoli', 'Chair of Saint Peter, apostle', 'Cattedra di San Pietro, apostolo', 4, 'Proper', 'GENERAL ROMAN', 'white'),
(30, 2, 23, 'StPolycarp', 'Sancti Polycarpi, episcopi et martyris', 'Saint Polycarp, bishop and martyr', 'San Policarpo, vescovo e martire', 3, 'Martyrs:For One Martyr,Pastors:For a Bishop', 'GENERAL ROMAN', 'white,red'),
(31, 3, 4, 'StCasimir', 'Sancti Casimiri', 'Saint Casimir', 'San Casimiro', 2, 'Holy Men and Women:For One Saint', 'GENERAL ROMAN', 'white'),
(32, 3, 7, 'StsPerpetuaFelicity', 'Sanctarum Perpetuæ et Felicitatis, martyrum', 'Saints Perpetua and Felicity, martyrs', 'Sante Perpetua e Felicita, martiri', 3, 'Proper', 'GENERAL ROMAN', 'red'),
(33, 3, 8, 'StJohnGod', 'Sancti Ioannis a Deo, religiosi', 'Saint John of God, religious', 'San Giovanni di Dio, religioso', 2, 'Holy Men and Women:For Religious,Holy Men and Women:For Those Who Practiced Works of Mercy', 'GENERAL ROMAN', 'white'),
(34, 3, 9, 'StFrancesRome', 'Sanctæ Franciscæ Romanæ, religiosæ', 'Saint Frances of Rome, religious', 'Santa Francesca Romana, religiosa', 2, 'Holy Men and Women:For Religious,Holy Men and Women:For Holy Women', 'GENERAL ROMAN', 'white'),
(35, 3, 17, 'StPatrick', 'Sancti Patricii, episcopi', 'Saint Patrick, bishop', 'San Patrizio, vescovo', 2, 'Pastors:For a Bishop,Pastors:For Missionaries', 'GENERAL ROMAN', 'white'),
(36, 3, 18, 'StCyrilJerusalem', 'Sancti Cyrilli Hierosolymitani, episcopi et Ecclesiæ doctoris', 'Saint Cyril of Jerusalem, bishop and doctor', 'San Cirillo di Gerusalemme, vescovo e dottore', 2, 'Pastors:For a Bishop,Doctors', 'GENERAL ROMAN', 'white'),
(37, 3, 19, 'StJoseph', 'Sancti Ioseph Sponsi Beatæ Mariæ Virginis', 'Saint Joseph Husband of the Blessed Virgin Mary', 'San Giuseppe Sposo della Beata Vergine Maria', 6, 'Proper', 'GENERAL ROMAN', 'white'),
(38, 3, 23, 'StTuribius', 'Sancti Turibii de Mongrovejo, episcopi', 'Saint Turibius of Mogrovejo, bishop', 'San Turibio di Mongrovejo, vescovo', 2, 'Pastors:For a Bishop', 'GENERAL ROMAN', 'white'),
(39, 3, 25, 'Annunciation', 'In Annuntiatione Domini', 'Annunciation of the Lord', 'Annunciazione del Signore', 6, 'Proper', 'GENERAL ROMAN', 'white'),
(40, 4, 2, 'StFrancisPaola', 'Sancti Francisci de Paola, eremitæ', 'Saint Francis of Paola, hermit', 'San Francesco di Paola, eremita', 2, 'Holy Men and Women:For Religious', 'GENERAL ROMAN', 'white'),
(41, 4, 4, 'StIsidore', 'Sancti Isidori, episcopi et Ecclesiæ doctoris', 'Saint Isidore, bishop and doctor of the Church', 'Sant\'Isidoro, vescovo e dottore della Chiesa', 2, 'Pastors:For a Bishop,Doctors', 'GENERAL ROMAN', 'white'),
(42, 4, 5, 'StVincentFerrer', 'Sancti Vincentii Ferrer, presbyteri', 'Saint Vincent Ferrer, priest', 'San Vincenzo Ferrer, sacerdote', 2, 'Pastors:For Missionaries', 'GENERAL ROMAN', 'white'),
(43, 4, 7, 'StJohnBaptistDeLaSalle', 'S. Ioannis Baptistæ de la Salle, presbyteri', 'Saint John Baptist de la Salle, priest', 'San Giovanni Battista de la Salle, sacerdote', 3, 'Pastors:For One Pastor,Holy Men and Women:For Educators', 'GENERAL ROMAN', 'white'),
(44, 4, 11, 'StStanislaus', 'Sancti Stanislai, episcopi et martyris', 'Saint Stanislaus, bishop and martyr', 'San Stanislao, vescovo e martire', 3, 'Martyrs:For One Martyr,Pastors:For a Bishop', 'GENERAL ROMAN', 'white,red'),
(45, 4, 13, 'StMartinPope', 'Sancti Martini I, papæ et martyris', 'Saint Martin I, pope and martyr', 'San Martino I, papa e martire', 2, 'Martyrs:For One Martyr,Pastors:For a Pope', 'GENERAL ROMAN', 'white,red'),
(46, 4, 21, 'StAnselm', 'Sancti Anselmi, episcopi et Ecclesiæ doctoris', 'Saint Anselm of Canterbury, bishop and doctor of the Church', 'Sant\'Anselmo di Canterbury, vescovo e dottore della Chiesa', 2, 'Pastors:For a Bishop,Doctors', 'GENERAL ROMAN', 'white'),
(47, 4, 23, 'StGeorge', 'Sancti Georgii, martyris', 'Saint George, martyr', 'San Giorgio, martire', 2, 'Martyrs:For One Martyr', 'GENERAL ROMAN', 'red'),
(49, 4, 24, 'StFidelisSigmaringen', 'Sancti Fidelis de Sigmaringen, presbyteri et martyris', 'Saint Fidelis of Sigmaringen, priest and martyr', 'San Fedele di Sigmaringen, sacerdote e martire', 2, 'Martyrs:For One Martyr,Pastors:For One Pastor', 'GENERAL ROMAN', 'white,red'),
(50, 4, 25, 'StMarkEvangelist', 'Sancti Marci, evangelistæ', 'Saint Mark the Evangelist', 'San Marco Evangelista', 4, 'Proper', 'GENERAL ROMAN', 'red'),
(51, 4, 28, 'StPeterChanel', 'Sancti Petri Chanel, presbyteri et martyris', 'Saint Peter Chanel, priest and martyr', 'San Pietro Chanel, sacerdote e martire', 2, 'Martyrs:For One Martyr,Pastors:For Missionaries', 'GENERAL ROMAN', 'white,red'),
(53, 4, 29, 'StCatherineSiena', 'Sanctæ Catharinæ Senensis, virginis', 'Saint Catherine of Siena, virgin and doctor of the Church', 'Santa Caterina da Siena, vergine e dottore della Chiesa', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(54, 4, 30, 'StPiusV', 'Sancti Pii V, papæ', 'Saint Pius V, pope', 'San Pio V, papa', 2, 'Pastors:For a Pope', 'GENERAL ROMAN', 'white'),
(55, 5, 1, 'StJosephWorker', 'Sancti Ioseph opificis', 'Saint Joseph the Worker', 'San Giuseppe Lavoratore', 2, 'Proper', 'GENERAL ROMAN', 'white'),
(56, 5, 2, 'StAthanasius', 'Sancti Athanasii, episcopi et Ecclesiæ doctoris', 'Saint Athanasius, bishop and doctor', 'Sant\'Atanasio, vescovo e dottore', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(57, 5, 3, 'StsPhilipJames', 'Sanctorum Philippi et Iacobi, Apostolorum', 'Saints Philip and James, Apostles', 'Santi Filippo e Giacomo, apostoli', 4, 'Proper', 'GENERAL ROMAN', 'red'),
(58, 5, 12, 'StsNereusAchilleus', 'Sanctorum Nerei et Achillei, martyrum', 'Saints Nereus and Achilleus, martyrs', 'Santi Nereo e Achille, martiri', 2, 'Martyrs:For Several Martyrs', 'GENERAL ROMAN', 'red'),
(59, 5, 12, 'StPancras', 'Sancti Pancratii, martyris', 'Saint Pancras, martyr', 'San Pancrazio, martire', 2, 'Martyrs:For One Martyr', 'GENERAL ROMAN', 'red'),
(61, 5, 14, 'StMatthiasAp', 'Sancti Matthiæ, Apostoli', 'Saint Matthias the Apostle', 'San Mattia, apostolo', 4, 'Proper', 'GENERAL ROMAN', 'red'),
(62, 5, 18, 'StJohnIPope', 'Sancti Ioannis I, papæ et martyris', 'Saint John I, pope and martyr', 'San Giovanni I, papa e martire', 2, 'Martyrs:For One Martyr,Pastors:For a Pope', 'GENERAL ROMAN', 'white,red'),
(63, 5, 20, 'StBernardineSiena', 'Sancti Bernardini Senensis, presbyteri', 'Saint Bernardine of Siena, priest', 'San Bernardino da Siena, sacerdote', 2, 'Pastors:For Missionaries,Holy Men and Women:For Religious', 'GENERAL ROMAN', 'white'),
(66, 5, 25, 'StBedeVenerable', 'Sancti Bedæ Venerabilis, presbyteri et Ecclesiæ doctoris', 'Saint Bede the Venerable, priest and doctor', 'San Beda il Venerabile, sacerdote e dottore', 2, 'Doctors,Holy Men and Women:For a Monk', 'GENERAL ROMAN', 'white'),
(67, 5, 25, 'StGregoryVII', 'Sancti Gregorii VII, papæ', 'Saint Gregory VII, pope', 'San Gregorio VII, papa', 2, 'Pastors:For a Pope', 'GENERAL ROMAN', 'white'),
(68, 5, 25, 'StMaryMagdalenePazzi', 'Sanctæ Mariæ Magdalenæ de\' Pazzi, virginis', 'Saint Mary Magdalene de Pazzi, virgin', 'Santa Maria Maddalena de Pazzi, vergine', 2, 'Virgins:For One Virgin,Holy Men and Women:For Religious', 'GENERAL ROMAN', 'white'),
(69, 5, 26, 'StPhilipNeri', 'Sancti Philippi Neri, presbyteri', 'Saint Philip Neri, priest', 'San Filippo Neri, sacerdote', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(70, 5, 27, 'StAugustineCanterbury', 'Sancti Augustini Cantuariensis, episcopi', 'Saint Augustine of Canterbury, bishop', 'Sant\'Agostino di Canterbury, vescovo', 2, 'Pastors:For a Bishop,Pastors:For Missionaries', 'GENERAL ROMAN', 'white'),
(71, 5, 31, 'Visitation', 'In Visitatione Beatæ Mariæ Virginis', 'Visitation of the Blessed Virgin Mary', 'Visitazione della Beata Vergine Maria', 4, 'Proper', 'GENERAL ROMAN', 'white'),
(72, 6, 1, 'StJustinMartyr', 'Sancti Iustini, martyris', 'Saint Justin Martyr', 'San Giustino martire', 3, 'Proper', 'GENERAL ROMAN', 'red'),
(73, 6, 2, 'StsMarcellinusPeter', 'Sanctorum Marcellini et Petri, martyrum', 'Saints Marcellinus and Peter, martyrs', 'Santi Marcellino e Pietro, martiri', 2, 'Martyrs:For Several Martyrs', 'GENERAL ROMAN', 'red'),
(74, 6, 3, 'StsCharlesLwanga', 'Sanctorum Caroli Lwanga et sociorum, martyrum', 'Saints Charles Lwanga and companions, martyrs', 'Santi Carlo Lwanga e compagni, martiri', 3, 'Proper', 'GENERAL ROMAN', 'red'),
(75, 6, 5, 'StBoniface', 'Sancti Bonifatii, episcopi et martyris', 'Saint Boniface, bishop and martyr', 'San Bonifacio, vescovo e martire', 3, 'Martyrs:For One Martyr,Pastors:For Missionaries', 'GENERAL ROMAN', 'white,red'),
(76, 6, 6, 'StNorbert', 'Sancti Norberti, episcopi', 'Saint Norbert, bishop', 'San Norberto', 2, 'Pastors:For a Bishop,Holy Men and Women:For Religious', 'GENERAL ROMAN', 'white'),
(77, 6, 9, 'StEphrem', 'Sancti Ephræm, diaconi et Ecclesiæ doctoris', 'Saint Ephrem, deacon and doctor', 'Sant\'Efrem, diacono e dottore', 2, 'Doctors', 'GENERAL ROMAN', 'white'),
(78, 6, 11, 'StBarnabasAp', 'Sancti Barnabæ, apostoli', 'Saint Barnabas the Apostle', 'San Barnaba, apostolo', 3, 'Proper', 'GENERAL ROMAN', 'red'),
(79, 6, 13, 'StAnthonyPadua', 'Sancti Antonii de Padova, presbyteri et Ecclesiæ doctoris', 'Saint Anthony of Padua, priest and doctor', 'Sant\'Antonio da Padova, sacerdote e dottore', 3, 'Pastors:For One Pastor,Doctors,Holy Men and Women:For Religious', 'GENERAL ROMAN', 'white'),
(80, 6, 19, 'StRomuald', 'Sancti Romualdi, abbatis', 'Saint Romuald, abbot', 'San Romualdo, abate', 2, 'Holy Men and Women:For an Abbot', 'GENERAL ROMAN', 'white'),
(81, 6, 21, 'StAloysiusGonzaga', 'Sancti Aloisii Gonzaga, religiosi', 'Saint Aloysius Gonzaga, religious', 'San Luigi Gonzaga, religioso', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(82, 6, 22, 'StPaulinusNola', 'Sancti Paulini Nolani, episcopi', 'Saint Paulinus of Nola, bishop', 'San Paolino da Nola, vescovo', 2, 'Pastors:For a Bishop', 'GENERAL ROMAN', 'white'),
(83, 6, 22, 'StsJohnFisherThomasMore', 'Sanctorum Ioannis Fisher, episcopi, et Thomæ More, martyrum', 'Saints John Fisher, bishop and martyr and Thomas More, martyr', 'Santi Giovanni Fisher, vescovo e martire e Tommaso Moro, martire', 2, 'Martyrs:For Several Martyrs', 'GENERAL ROMAN', 'red'),
(84, 6, 24, 'NativityJohnBaptist', 'In Nativitate Sancti Ioannis Baptistæ', 'Nativity of Saint John the Baptist', 'Natività di San Giovanni Battista', 6, 'Proper', 'GENERAL ROMAN', 'white'),
(85, 6, 27, 'StCyrilAlexandria', 'Sancti Cyrilli Alexandrini, episcopi et Ecclesiæ doctoris', 'Saint Cyril of Alexandria, bishop and doctor', 'San Cirillo di Alessandria, vescovo e dottore', 2, 'Pastors:For a Bishop,Doctors', 'GENERAL ROMAN', 'white'),
(86, 6, 28, 'StIrenaeus', 'Sancti Irenæi, episcopi et martyris', 'Saint Irenaeus, bishop and martyr', 'Sant\'Ireneo, vescovo e martire', 3, 'Proper', 'GENERAL ROMAN', 'white,red'),
(87, 6, 29, 'StsPeterPaulAp', 'Sanctorum Petri et Pauli, Apostolorum', 'Saints Peter and Paul, Apostles', 'Santi Pietro e Paolo, Apostoli', 6, 'Proper', 'GENERAL ROMAN', 'red'),
(88, 6, 30, 'FirstMartyrsRome', 'Sanctorum Protomartyrum sanctæ Romanæ Ecclesiæ', 'First Martyrs of the Church of Rome', 'Santi Primi Martiri della Chiesa Romana', 2, 'Martyrs:For Several Martyrs', 'GENERAL ROMAN', 'red'),
(89, 7, 3, 'StThomasAp', 'Sancti Thomæ, Apostoli', 'Saint Thomas the Apostle', 'San Tommaso Apostolo', 4, 'Proper', 'GENERAL ROMAN', 'red'),
(90, 7, 4, 'StElizabethPortugal', 'Sanctæ Elisabeth Lusitaniæ', 'Saint Elizabeth of Portugal', 'Sant\'Elisabetta da Portogallo', 2, 'Holy Men and Women:For Those Who Practiced Works of Mercy', 'GENERAL ROMAN', 'white'),
(91, 7, 5, 'StAnthonyZaccaria', 'Sancti.. Antonii Mariæ Zaccaria, presbyteri', 'Saint Anthony Zaccaria, priest', 'Sant\'Antonio Zaccaria, sacerdote', 2, 'Pastors:For One Pastor,Holy Men and Women:For Religious,Holy Men and Women:For Educators', 'GENERAL ROMAN', 'white'),
(92, 7, 6, 'StMariaGoretti', 'Sanctæ Mariæ Goretti, virginis et martyris', 'Saint Maria Goretti, virgin and martyr', 'Santa Maria Goretti, vergine e martire', 2, 'Martyrs:For a Virgin Martyr,Virgins:For One Virgin', 'GENERAL ROMAN', 'white,red'),
(94, 7, 11, 'StBenedict', 'Sancti Benedicti, abbatis', 'Saint Benedict, abbot', 'San Benedetto, abate', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(95, 7, 13, 'StHenry', 'Sancti Henrici', 'Saint Henry', 'Sant\'Enrico', 2, 'Holy Men and Women:For One Saint', 'GENERAL ROMAN', 'white'),
(96, 7, 14, 'StCamillusDeLellis', 'Sancti Camilli de Lellis, presbyteri', 'Saint Camillus de Lellis, priest', 'San Camillo de Lellis, sacerdote', 2, 'Holy Men and Women:For Those Who Practiced Works of Mercy', 'GENERAL ROMAN', 'white'),
(97, 7, 15, 'StBonaventure', 'Sancti Bonaventuræ, episcopi et Ecclesiæ doctoris', 'Saint Bonaventure, bishop and doctor', 'San Bonaventura, vescovo e dottore', 3, 'Pastors:For a Bishop,Doctors', 'GENERAL ROMAN', 'white'),
(98, 7, 16, 'LadyMountCarmel', 'Beatæ Mariæ Virginis de Monte Carmelo', 'Our Lady of Mount Carmel', 'Beata Maria Vergine del Monte Carmelo', 2, 'Blessed Virgin Mary', 'GENERAL ROMAN', 'white'),
(100, 7, 21, 'StLawrenceBrindisi', 'Sancti Laurentii de Brindisi, presbyteri et Ecclesiæ doctoris', 'Saint Lawrence of Brindisi, priest and doctor', 'San Lorenzo da Brindisi, sacerdote e dottore', 2, 'Pastors:For One Pastor,Doctors,Holy Men and Women:For Religious', 'GENERAL ROMAN', 'white'),
(101, 7, 22, 'StMaryMagdalene', 'Sanctæ Mariæ Magdalenæ', 'Saint Mary Magdalene', 'Santa Maria Maddalena', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(102, 7, 23, 'StBridget', 'Sanctæ Brigittæ, religiosæ', 'Saint Bridget, religious', 'Santa Brigida, religiosa', 2, 'Holy Men and Women:For Religious,Holy Men and Women:For Holy Women', 'GENERAL ROMAN', 'white'),
(104, 7, 25, 'StJamesAp', 'Sancti Iacobi, Apostoli', 'Saint James, apostle', 'San Giacomo, apostolo', 4, 'Proper', 'GENERAL ROMAN', 'red'),
(105, 7, 26, 'StsJoachimAnne', 'Sanctorum Ioachim et Annæ, parentum beatæ Mariæ Virginis', 'Saints Joachim and Anne', 'Santi Gioacchino e Anna', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(106, 7, 29, 'StMartha', 'Sanctæ Marthæ', 'Saint Martha', 'Santa Marta', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(107, 7, 30, 'StPeterChrysologus', 'Sancti Petri Chrysologui, episcopi et Ecclesiæ doctoris', 'Saint Peter Chrysologus, bishop and doctor', 'San Pietro Crisologo, vescovo e dottore', 2, 'Pastors:For a Bishop,Doctors', 'GENERAL ROMAN', 'white'),
(108, 7, 31, 'StIgnatiusLoyola', 'Sancti Ignatii de Loyola, presbyteri', 'Saint Ignatius of Loyola, priest', 'Sant\'Ignazio da Loyola, sacerdote', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(109, 8, 1, 'StAlphonsusMariaDeLiguori', 'Sancti Alfonsi Mariæ de\' Liguori, episcopi et Ecclesiæ doctoris', 'Saint Alphonsus Maria de Liguori, bishop and doctor of the Church', 'Sant\'Alfonso Maria de Liguori, vescovo e dottore', 3, 'Pastors:For a Bishop,Doctors', 'GENERAL ROMAN', 'white'),
(110, 8, 2, 'StEusebius', 'Sancti Eusebii Vercellensis, episcopi', 'Saint Eusebius of Vercelli, bishop', 'Sant\'Eusebio da Vercelli, vescovo', 2, 'Pastors:For a Bishop', 'GENERAL ROMAN', 'white'),
(112, 8, 4, 'StJeanVianney', 'Sancti Ioannis Mariæ Vianney, presbyteri', 'Saint Jean Vianney (the Curé of Ars), priest', 'San Giovanni Vianney (il Curato d\'Ars), sacerdote', 3, 'Pastors:For One Pastor', 'GENERAL ROMAN', 'white'),
(113, 8, 5, 'DedicationStMaryMajor', 'In Dedicatione basilicæ Sanctæ Mariæ', 'Dedication of the Basilica of Saint Mary Major', 'Dedicazione della Basilica di Santa Maria Maggiore', 2, 'Blessed Virgin Mary', 'GENERAL ROMAN', 'white'),
(114, 8, 6, 'Transfiguration', 'In Transfiguratione Domini', 'Transfiguration of the Lord', 'Trasfigurazione del Signore', 5, 'Proper', 'GENERAL ROMAN', 'white'),
(115, 8, 7, 'StSixtusIIPope', 'Sanctorum Xysti II, papæ, et sociorum, martyrum', 'Saint Sixtus II, pope, and companions, martyrs', 'Santi Sisto II, papa, e compagni martiri', 2, 'Martyrs:For Several Martyrs', 'GENERAL ROMAN', 'red'),
(116, 8, 7, 'StCajetan', 'Sancti Caietani, presbyteri', 'Saint Cajetan, priest', 'San Gaetano, sacerdote', 2, 'Pastors:For One Pastor,Holy Men and Women:For Religious', 'GENERAL ROMAN', 'white'),
(117, 8, 8, 'StDominic', 'Sancti Dominici, presbyteri', 'Saint Dominic, priest', 'San Domenico, sacerdote', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(119, 8, 10, 'StLawrenceDeacon', 'Sancti Laurentii, diaconi et martyris', 'Saint Lawrence, deacon and martyr', 'San Lorenzo, diacono e martire', 4, 'Proper', 'GENERAL ROMAN', 'white,red'),
(120, 8, 11, 'StClare', 'Sanctæ Claræ, virginis', 'Saint Clare, virgin', 'Santa Chiara, vergine', 3, 'Virgins:For One Virgin,Holy Men and Women:For a Nun', 'GENERAL ROMAN', 'white'),
(121, 12, 12, 'StJaneFrancesDeChantal', 'Sanctæ Ioannæ Franciscæ de Chantal, religiosæ', 'Saint Jane Frances de Chantal, religious', 'Santa Giovanna Francesca de Chantal, religiosa', 2, 'Holy Men and Women:For Religious', 'GENERAL ROMAN', 'white'),
(122, 8, 13, 'StsPontianHippolytus', 'Sanctorum Pontiani, papæ, et Hippolyti, presbyteri, martyrum', 'Saints Pontian, pope, and Hippolytus, priest, martyrs', 'Santi Ponziano, papa, e Ippolito, sacerdote, martiri', 2, 'Martyrs:For Several Martyrs,Pastors:For Several Pastors', 'GENERAL ROMAN', 'white,red'),
(124, 8, 15, 'Assumption', 'In Assumptione Beatæ Mariæ Virginis', 'Assumption of the Blessed Virgin Mary', 'Assunzione della Beata Vergine Maria', 6, 'Proper', 'GENERAL ROMAN', 'white'),
(125, 8, 16, 'StStephenHungary', 'Sancti Stephani Hunagariæ', 'Saint Stephen of Hungary', 'Santo Stefano di Ungheria', 2, 'Holy Men and Women:For One Saint', 'GENERAL ROMAN', 'white'),
(126, 8, 19, 'StJohnEudes', 'Sancti Ioannis Eudes, presbyteri', 'Saint John Eudes, priest', 'San Giovanni Eudes, sacerdote', 2, 'Pastors:For One Pastor,Holy Men and Women:For Religious', 'GENERAL ROMAN', 'white'),
(127, 8, 20, 'StBernardClairvaux', 'Sancti Bernardi, abbatis et Ecclesiæ doctoris', 'Saint Bernard of Clairvaux, abbot and doctor of the Church', 'San Bernardo di Chiaravalle, abate e dottore della Chiesa', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(128, 8, 21, 'StPiusX', 'Sancti Pii X, papæ', 'Saint Pius X, pope', 'San Pio X, papa', 3, 'Pastors:For a Pope', 'GENERAL ROMAN', 'white'),
(129, 8, 22, 'QueenshipMary', 'Beatæ Mariæ Virginis Reginæ', 'Queenship of Blessed Virgin Mary', 'Beata Maria Vergine Regina', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(130, 8, 23, 'StRoseLima', 'Sanctæ Rosæ de Lima, virginis', 'Saint Rose of Lima, virgin', 'Santa Rosa da Lima, vergine', 2, 'Virgins:For One Virgin', 'GENERAL ROMAN', 'white'),
(131, 8, 24, 'StBartholomewAp', 'Sancti Bartholomæi, Apostoli', 'Saint Bartholomew the Apostle', 'San Bartolomeo, apostolo', 4, 'Proper', 'GENERAL ROMAN', 'red'),
(132, 8, 25, 'StLouis', 'Sancti Ludovici', 'Saint Louis', 'San Luigi', 2, 'Holy Men and Women:For One Saint', 'GENERAL ROMAN', 'white'),
(133, 8, 25, 'StJosephCalasanz', 'Sancti Ioseph de Calasanz, presbyteri', 'Saint Joseph Calasanz, priest', 'San Giuseppe da Calasanzio, sacerdote', 2, 'Pastors:For One Pastor,Holy Men and Women:For Educators', 'GENERAL ROMAN', 'white'),
(134, 8, 27, 'StMonica', 'Sanctæ Monicæ', 'Saint Monica', 'Santa Monica', 3, 'Holy Men and Women:For Holy Women', 'GENERAL ROMAN', 'white'),
(135, 8, 28, 'StAugustineHippo', 'Sancti Augustini, episcopi et Ecclesiæ doctoris', 'Saint Augustine of Hippo, bishop and doctor of the Church', 'Sant\'Agostino di Ippona, vescovo e dottore della Chiesa', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(136, 8, 29, 'BeheadingJohnBaptist', 'In Passione Sancti Ioannis Baptistæ', 'The Beheading of Saint John the Baptist, martyr', 'Martirio di San Giovanni Battista', 3, 'Proper', 'GENERAL ROMAN', 'red'),
(137, 9, 3, 'StGregoryGreat', 'Sancti Gregorii Magni, papæ et Ecclesiæ doctoris', 'Saint Gregory the Great, pope and doctor', 'San Gregorio Magno, papa e dottore', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(138, 9, 8, 'NativityVirginMary', 'In Nativitate Beatæ Mariæ Virginis', 'Nativity of the Blessed Virgin Mary', 'Natività della Beata Vergine Maria', 4, 'Proper', 'GENERAL ROMAN', 'white'),
(141, 9, 13, 'StJohnChrysostom', 'Sancti Ioannis Chrysostomi, episcopi et Ecclesiæ doctoris', 'Saint John Chrysostom, bishop and doctor', 'San Giovanni Crisostomo, vescovo e dottore', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(142, 9, 14, 'ExaltationCross', 'In Exaltatione Sanctæ Crucis', 'Exaltation of the Holy Cross', 'Esaltazione della Santa Croce', 5, 'Proper', 'GENERAL ROMAN', 'red'),
(143, 9, 15, 'LadySorrows', 'Beatæ Mariæ Virginis Perdolentis', 'Our Lady of Sorrows', 'Beata Vergine Maria Addolorata', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(144, 9, 16, 'StsCorneliusCyprian', 'Sanctorum Cornelii, papæ, et Cypriani, episcopi, martyrum', 'Saints Cornelius, pope, and Cyprian, bishop, martyrs', 'Santi Cornelio, papa, e Cipriano, vescovo, martiri', 3, 'Martyrs:For Several Martyrs,Pastors:For a Bishop', 'GENERAL ROMAN', 'white,red'),
(145, 9, 17, 'StRobertBellarmine', 'Sancti Roberti Bellarmino, episcopi et Ecclesiæ doctoris', 'Saint Robert Bellarmine, bishop and doctor', 'San Roberto Bellarmino, vescovo e dottore', 2, 'Pastors:For a Bishop,Doctors', 'GENERAL ROMAN', 'white'),
(146, 9, 19, 'StJanuarius', 'Sancti Ianuarii, episcopi et martyris', 'Saint Januarius, bishop and martyr', 'San Gennaro, vescovo e martire', 2, 'Martyrs:For One Martyr,Pastors:For a Bishop', 'GENERAL ROMAN', 'white,red'),
(148, 9, 21, 'StMatthewEvangelist', 'Sancti Matthæi, apostoli et evangelistæ', 'Saint Matthew the Evangelist, Apostle', 'San Matteo apostolo ed evangelista', 4, 'Proper', 'GENERAL ROMAN', 'red'),
(150, 9, 26, 'StsCosmasDamian', 'Ss. Cosmæ et Damiani, martyrum', 'Saints Cosmas and Damian, martyrs', 'Santi Cosma e Damiano, martiri', 2, 'Martyrs:For Several Martyrs', 'GENERAL ROMAN', 'red'),
(151, 9, 27, 'StVincentDePaul', 'Sancti Vincentii de Paul, presbyteri', 'Saint Vincent de Paul, priest', 'San Vincenzo de Paoli, sacerdote', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(152, 9, 28, 'StWenceslaus', 'Sancti Venceslai, martyris', 'Saint Wenceslaus, martyr', 'San Venceslao, martire', 2, 'Martyrs:For One Martyr', 'GENERAL ROMAN', 'red'),
(154, 9, 29, 'StsArchangels', 'Sanctorum Michælis, Gabrielis et Raphælis, archangelorum', 'Saints Michael, Gabriel and Raphael, Archangels', 'Santi Michele, Gabriele e Raffaele, arcangeli', 4, 'Proper', 'GENERAL ROMAN', 'white'),
(155, 9, 30, 'StJerome', 'Sancti Hieronymi, presbyteri et Ecclesiæ doctoris', 'Saint Jerome, priest and doctor', 'San Girolamo, sacerdote e dottore', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(156, 10, 1, 'StThereseChildJesus', 'Sanctæ Teresiæ a Iesu Infante, virginis', 'Saint Thérèse of the Child Jesus, virgin', 'Santa Teresa di Gesù Bambino, vergine', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(157, 10, 2, 'GuardianAngels', 'Sanctorum Angelorum Custodum', 'Guardian Angels', 'Santi Angeli Custodi', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(158, 10, 4, 'StFrancisAssisi', 'Sancti Francisci Assisiensis', 'Saint Francis of Assisi', 'San Francesco d\'Assisi', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(159, 10, 6, 'StBruno', 'Sancti Brunonis, presbyteri', 'Saint Bruno, priest', 'San Bruno, sacerdote', 2, 'Pastors:For One Pastor,Holy Men and Women:For a Monk', 'GENERAL ROMAN', 'white'),
(160, 10, 7, 'LadyRosary', 'Beatæ Mariæ Virginis a Rosario', 'Our Lady of the Rosary', 'Beata Maria Vergine del Rosario', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(161, 10, 9, 'StDenis', 'Sanctorum Dionysii, episcopi, et sociorum, martyrum', 'Saint Denis, bishop, and companions, martyrs', 'San Dionigi, vescovo, e compagni, martiri', 2, 'Martyrs:For Several Martyrs', 'GENERAL ROMAN', 'red'),
(162, 10, 9, 'StJohnLeonardi', 'Sancti Ioannis Leonardi, presbyteri', 'Saint John Leonardi, priest', 'San Giovanni Leonardi, sacerdote', 2, 'Pastors:For Missionaries,Holy Men and Women:For Those Who Practiced Works of Mercy', 'GENERAL ROMAN', 'white'),
(164, 10, 14, 'StCallistusIPope', 'Sancti Callisti I, papæ et martyris', 'Saint Callistus I, pope and martyr', 'San Callisto I, papa e martire', 2, 'Martyrs:For One Martyr,Pastors:For a Pope', 'GENERAL ROMAN', 'white,red'),
(165, 10, 15, 'StTeresaJesus', 'Sanctæ Teresiæ de Avila, virginis', 'Saint Teresa of Jesus, virgin and doctor', 'Santa Teresa d\'Avila, vergine e dottore', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(166, 10, 16, 'StHedwig', 'Sancti Hedvigis, religiosæ', 'Saint Hedwig, religious', 'Sant\'Edvige, religiosa', 2, 'Holy Men and Women:For Religious,Holy Men and Women:For Holy Women', 'GENERAL ROMAN', 'white'),
(167, 10, 16, 'StMargaretAlacoque', 'Sanctæ Margaritæ Mariæ Alacoque, virginis', 'Saint Margaret Mary Alacoque, virgin', 'Santa Margherita Maria Alacoque, vergine', 2, 'Virgins:For One Virgin', 'GENERAL ROMAN', 'white'),
(168, 10, 17, 'StIgnatiusAntioch', 'Sancti Ignatii Antiocheni, episcopi et martyris', 'Saint Ignatius of Antioch, bishop and martyr', 'Sant\'Ignazio di Antiochia, vescovo e martire', 3, 'Proper', 'GENERAL ROMAN', 'white,red'),
(169, 10, 18, 'StLukeEvangelist', 'Sancti Lucæ, evangelistæ', 'Saint Luke the Evangelist', 'San Luca Evangelista', 4, 'Proper', 'GENERAL ROMAN', 'red'),
(170, 10, 19, 'StsJeanBrebeuf', 'Sanctorum Ioannis de Brébeuf et Isaac Jogues, presbyterorum, et sociorum, martyrum', 'Saints John de Brébeuf and Isaac Jogues, Priests, and Companions, Martyrs', 'Santi Giovanni Brebeuf e Isacco Jogues, sacerdoti e compagni martiri', 2, 'Martyrs:For Missionary Martyrs', 'GENERAL ROMAN', 'red'),
(171, 10, 19, 'StPaulCross', 'Sancti Pauli a Cruce, presbyteri', 'Saint Paul of the Cross, Priest', 'San Paolo della Croce, sacerdote', 2, 'Proper', 'GENERAL ROMAN', 'white'),
(173, 10, 23, 'StJohnCapistrano', 'Sancti Ioannis de Capestrano, presbyteri', 'Saint John of Capistrano, priest', 'San Giovanni da Capestrano, sacerdote', 2, 'Pastors:For Missionaries,Holy Men and Women:For Religious', 'GENERAL ROMAN', 'white'),
(174, 10, 24, 'StAnthonyMaryClaret', 'Sancti Antonii Mariæ Claret, episcopi', 'Saint Anthony Mary Claret, bishop', 'Sant\'Antonio Maria Claret, vescovo', 2, 'Pastors:For a Bishop,Pastors:For Missionaries', 'GENERAL ROMAN', 'white'),
(175, 10, 28, 'StSimonStJudeAp', 'Sanctorum Simonis et Iudæ, apostolorum', 'Saint Simon and Saint Jude, apostles', 'Santi Simone e Giuda, apostoli', 4, 'Proper', 'GENERAL ROMAN', 'red'),
(176, 11, 1, 'AllSaints', 'Omnium Sanctorum', 'All Saints Day', 'Tutti i Santi', 6, 'Proper', 'GENERAL ROMAN', 'white'),
(177, 11, 2, 'AllSouls', 'In Commemoratione Omnium Fidelium Defunctorum', 'Commemoration of all the Faithful Departed (All Souls\' Day)', 'Commemorazione di tutti i defunti', 6, 'Proper', 'GENERAL ROMAN', 'purple'),
(178, 11, 3, 'StMartinPorres', 'Sancti Martini de Porres, religiosi', 'Saint Martin de Porres, religious', 'San Martino de Porres, religioso', 2, 'Holy Men and Women:For Religious', 'GENERAL ROMAN', 'white'),
(179, 11, 4, 'StCharlesBorromeo', 'Sancti Caroli Borromeo, episcopi', 'Saint Charles Borromeo, bishop', 'San Carlo Borromeo, vescovo', 3, 'Pastors:For a Bishop', 'GENERAL ROMAN', 'white'),
(180, 11, 9, 'DedicationLateran', 'In Dedicatione Bascilicæ Lateranensis', 'Dedication of the Lateran basilica', 'Dedicazione della Basilica lateranense', 4, 'Proper', 'GENERAL ROMAN', 'white'),
(181, 11, 10, 'StLeoGreat', 'Sancti Leonis Magni, papæ et Ecclesiæ doctoris', 'Saint Leo the Great, pope and doctor', 'San Leone Magno, papa e dottore', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(182, 11, 11, 'StMartinTours', 'Sancti Martini, episcopi', 'Saint Martin of Tours, bishop', 'San Martino di Tours, vescovo', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(183, 11, 12, 'StJosaphat', 'Sancti Iosaphat, episcopi et martyris', 'Saint Josaphat, bishop and martyr', 'San Giosafat, vescovo e martire', 3, 'Proper', 'GENERAL ROMAN', 'white,red'),
(184, 11, 15, 'StAlbertGreat', 'Sancti Alberti Magni, episcopi et Ecclesiæ doctoris', 'Saint Albert the Great, bishop and doctor', 'Sant\'Alberto Magno, vescovo e dottore', 2, 'Pastors:For a Bishop,Doctors', 'GENERAL ROMAN', 'white'),
(185, 11, 16, 'StMargaretScotland', 'Sanctæ Margaritæ Scotiæ', 'Saint Margaret of Scotland', 'Santa Margherita di Scozia', 2, 'Holy Men and Women:For Those Who Practiced Works of Mercy', 'GENERAL ROMAN', 'white'),
(186, 11, 16, 'StGertrudeGreat', 'Sanctæ Gertrudis, virginis', 'Saint Gertrude the Great, virgin', 'Santa Geltrude, vergine', 2, 'Virgins:For One Virgin,Holy Men and Women:For a Nun', 'GENERAL ROMAN', 'white'),
(187, 11, 17, 'StElizabethHungary', 'Sanctæ Elisabeth Hungariæ', 'Saint Elizabeth of Hungary, religious', 'Sant\'Elisabetta di Ungheria, religiosa', 3, 'Holy Men and Women:For Those Who Practiced Works of Mercy', 'GENERAL ROMAN', 'white'),
(188, 11, 18, 'DedicationStsPeterPaul', 'In Dedicatione basilicarum Sanctorum Petri et Pauli, apostolorum', 'Dedication of the basilicas of Saints Peter and Paul, Apostles', 'Dedicazione delle basiliche dei Santi Pietro e Paolo, apostoli', 2, 'Proper', 'GENERAL ROMAN', 'white'),
(189, 11, 21, 'PresentationMary', 'In Præsentatione Beatæ Mariæ Virginis', 'Presentation of the Blessed Virgin Mary', 'Presentazione della Beata Vergine Maria', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(190, 11, 22, 'StCecilia', 'Sanctæ Cæciliæ, virginis et martyris', 'Saint Cecilia, virgin and martyr', 'Santa Cecilia, vergine e martire', 3, 'Martyrs:For a Virgin Martyr,Virgins:For One Virgin', 'GENERAL ROMAN', 'white,red'),
(191, 11, 23, 'StClementIPope', 'Sancti Clementis I, papæ et martyris', 'Saint Clement I, pope and martyr', 'San Clemente I, papa e martire', 2, 'Martyrs:For One Martyr,Pastors:For a Pope', 'GENERAL ROMAN', 'white,red'),
(192, 11, 23, 'StColumban', 'Sancti Columbani, abbatis', 'Saint Columban, religious', 'San Colombano, abate', 2, 'Pastors:For Missionaries,Holy Men and Women:For an Abbot', 'GENERAL ROMAN', 'white'),
(195, 11, 30, 'StAndrewAp', 'Sancti Andreæ, apostoli', 'Saint Andrew the Apostle', 'Sant\'Andrea apostolo', 4, 'Proper', 'GENERAL ROMAN', 'red'),
(196, 12, 3, 'StFrancisXavier', 'Sancti Francisci Xavier, presbyteri', 'Saint Francis Xavier, priest', 'San Francesco Saverio, sacerdote', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(197, 12, 4, 'StJohnDamascene', 'Sancti Ioannis Damasceni, presbyteri et Ecclesiæ doctoris', 'Saint John Damascene, priest and doctor', 'San Giovanni Damasceno, sacerdote e dottore', 2, 'Pastors:For One Pastor,Doctors', 'GENERAL ROMAN', 'white'),
(198, 12, 6, 'StNicholas', 'Sancti Nicolai, episcopi', 'Saint Nicholas, bishop', 'San Nicola, vescovo', 2, 'Pastors:For a Bishop', 'GENERAL ROMAN', 'white'),
(199, 12, 7, 'StAmbrose', 'Sancti Ambrosii, episcopi et Ecclesiæ doctoris', 'Saint Ambrose, bishop and doctor', 'Sant\'Ambrogio, vescovo e dottore', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(200, 12, 8, 'ImmaculateConception', 'In Conceptione Immaculata Beatæ Mariæ Virginis', 'Immaculate Conception of the Blessed Virgin Mary', 'Immacolata Concezione della Beata Vergine Maria', 6, 'Proper', 'GENERAL ROMAN', 'white'),
(202, 12, 11, 'StDamasusIPope', 'Sancti Damasi I, papæ', 'Saint Damasus I, pope', 'San Damaso I, papa', 2, 'Pastors:For a Pope', 'GENERAL ROMAN', 'white'),
(204, 12, 13, 'StLucySyracuse', 'Sanctæ Luciæ, virginis et martyris', 'Saint Lucy of Syracuse, virgin and martyr', 'Santa Lucia, vergine e martire', 3, 'Martyrs:For a Virgin Martyr,Virgins:For One Virgin', 'GENERAL ROMAN', 'white,red'),
(205, 12, 14, 'StJohnCross', 'Sancti Ioannis a Cruce, presbyteri et Ecclesiæ doctoris', 'Saint John of the Cross, priest and doctor', 'San Giovanni della Croce, sacerdote e dottore', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(206, 12, 21, 'StPeterCanisius', 'Sancti Petri Canisii, presbyteri et Ecclesiæ doctoris', 'Saint Peter Canisius, priest and doctor', 'San Pietro Canisio, sacerdote e dottore', 2, 'Pastors:For One Pastor,Doctors', 'GENERAL ROMAN', 'white'),
(207, 12, 23, 'StJohnKanty', 'Sancti Ioannis de Kęty, presbyteri', 'Saint John of Kanty, priest', 'San Giovanni da Kęty, sacerdote', 2, 'Pastors:For One Pastor,Holy Men and Women:For Those Who Practiced Works of Mercy', 'GENERAL ROMAN', 'white'),
(209, 12, 26, 'StStephenProtomartyr', 'Sancti Stephani, protomartyris', 'Saint Stephen, the first martyr', 'Santo Stefano, protomartire', 4, 'Proper', 'GENERAL ROMAN', 'red'),
(210, 12, 27, 'StJohnEvangelist', 'Sancti Ioannis, apostoli et evangelistæ', 'Saint John, Apostle and Evangelist', 'San Giovanni apostolo ed evangelista', 4, 'Proper', 'GENERAL ROMAN', 'white'),
(211, 12, 28, 'HolyInnnocents', 'Sanctorum Innocentium, martyrum', 'Holy Innocents, martyrs', 'Santi Innocenti, martiri', 4, 'Proper', 'GENERAL ROMAN', 'red'),
(212, 12, 29, 'StThomasBecket', 'Sancti Thomæ Becket, episcopi et martyris', 'Saint Thomas Becket, bishop and martyr', 'San Tommaso Becket, vescovo e martire', 2, 'Martyrs:For One Martyr,Pastors:For a Bishop', 'GENERAL ROMAN', 'white,red'),
(213, 12, 31, 'StSylvesterIPope', 'Sancti Silvestri I, papæ', 'Saint Sylvester I, pope', 'San Silvestro I, papa', 2, 'Pastors:For a Pope', 'GENERAL ROMAN', 'white');

-- --------------------------------------------------------

--
-- Table structure for table `LITURGY__calendar_propriumdesanctis_2002`
--
-- Creation: Aug 08, 2020 at 01:12 AM
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
  `COMMON` set('Proper','Dedication of a Church','Blessed Virgin Mary','Martyrs','Martyrs:For Several Martyrs','Martyrs:For One Martyr','Martyrs:For Missionary Martyrs','Martyrs:For Several Missionary Martyrs','Martyrs:For One Missionary Martyr','Martyrs:For a Virgin Martyr','Martyrs:For a Holy Woman Martyr','Pastors','Pastors:For a Pope','Pastors:For a Bishop','Pastors:For Several Pastors','Pastors:For One Pastor','Pastors:For Founders of a Church','Pastors:For Several Founders','Pastors:For One Founder','Pastors:For Missionaries','Doctors','Virgins','Virgins:For Several Virgins','Virgins:For One Virgin','Holy Men and Women','Holy Men and Women:For Several Saints','Holy Men and Women:For One Saint','Holy Men and Women:For an Abbot','Holy Men and Women:For a Monk','Holy Men and Women:For a Nun','Holy Men and Women:For Religious','Holy Men and Women:For Those Who Practiced Works of Mercy','Holy Men and Women:For Educators','Holy Men and Women:For Holy Women') NOT NULL,
  `CALENDAR` varchar(50) NOT NULL,
  `COLOR` set('green','purple','white','red','pink') NOT NULL
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
(48, 4, 23, 'StAdalbert', 'Sancti Adalberti, episcopi et martyris', 'Saint Adalbert, bishop and martyr', 'Sant\'Adalberto, vescovo e martire', 2, 'Martyrs:For One Martyr,Pastors:For a Bishop', 'GENERAL ROMAN', 'white,red'),
(52, 4, 28, 'StLouisGrignonMontfort', 'Sancti Ludovici Mariæ Grignion de Montfort, presbyteri', 'Saint Louis Grignon de Montfort, priest', 'San Luigi Grignon de Montfort', 2, 'Pastors:For One Pastor', 'GENERAL ROMAN', 'white'),
(60, 5, 13, 'LadyFatima', 'Beatæ Mariæ Virginis de Fatima', 'Our Lady of Fatima', 'Beata Vergine Maria di Fatima', 2, 'Blessed Virgin Mary', 'GENERAL ROMAN', 'white'),
(64, 5, 21, 'StChristopherMagallanes', 'Sanctorum Christophori Magallanes, presbyteri, et sociorum, martyrum', 'Saint Christopher Magallanes and companions, martyrs', 'San Cristoforo Magallanes e compagni martiri', 2, 'Martyrs:For Several Martyrs', 'GENERAL ROMAN', 'red'),
(65, 5, 22, 'StRitaCascia', 'Sanctæ Ritæ de Cascia, religiosæ', 'Saint Rita of Cascia', 'Santa Rita da Cascia, religiosa', 2, 'Holy Men and Women:For Religious', 'GENERAL ROMAN', 'white'),
(93, 7, 9, 'StAugustineZhaoRong', 'Sanctorum Augustini Zhao Rong, presbyteri et sociorum, martyrum', 'Saint Augustine Zhao Rong and companions, martyrs', 'Santi Agostino Zhao Rong e compagni martiri', 2, 'Martyrs:For Several Martyrs', 'GENERAL ROMAN', 'red'),
(99, 7, 20, 'StApollinaris', 'Sancti Apollinaris, episcopi et martyris', 'Saint Apollinaris, bishop and martyr', 'Sant\'Apollinare, vescovo e martire', 2, 'Martyrs:For One Martyr,Pastors:For a Bishop', 'GENERAL ROMAN', 'white,red'),
(103, 7, 24, 'StSharbelMakhluf', 'Sancti Sarbelii Makhluf, presbyteri', 'Saint Sharbel Makhluf, hermit', 'San Charbel Makhluf, eremita', 2, 'Pastors:For One Pastor,Holy Men and Women:For a Monk', 'GENERAL ROMAN', 'white'),
(111, 8, 2, 'StPeterJulianEymard', 'Sancti Petri Iuliani Eymard, presbyteri', 'Saint Peter Julian Eymard, priest', 'San Pietro Giuliani, sacerdote', 2, 'Pastors:For One Pastor,Holy Men and Women:For Religious', 'GENERAL ROMAN', 'white'),
(118, 8, 9, 'StEdithStein', 'Sanctæ Teresiæ Benedictæ a Cruce, virginis et martyris', 'Saint Teresa Benedicta of the Cross (Edith Stein), virgin and martyr', 'Santa Teresa Benedetta della Croce (Edith Stein), vergine e martire', 2, 'Martyrs:For a Virgin Martyr,Virgins:For One Virgin', 'GENERAL ROMAN', 'white,red'),
(123, 8, 14, 'StMaximilianKolbe', 'Sancti Maximiliani Mariæ Kolbe, presbyteri et martyris', 'Saint Maximilian Mary Kolbe, priest and martyr', 'San Massimiliano Kolbe, sacerdote e martire', 3, 'Proper', 'GENERAL ROMAN', 'white,red'),
(139, 9, 9, 'StPeterClaver', 'Sancti Petri Claver, presbyteri', 'Saint Peter Claver, priest', 'San Pietro Claver, sacerdote', 2, 'Pastors:For One Pastor,Holy Men and Women:For Those Who Practiced Works of Mercy', 'GENERAL ROMAN', 'white'),
(140, 9, 12, 'HolyNameMary', 'Sanctissimi Nominis Mariæ', 'Holy Name of the Blessed Virgin Mary', 'Santissimo Nome di Maria', 2, 'Proper', 'GENERAL ROMAN', 'white'),
(147, 9, 20, 'StAndrewKimTaegon', 'Sanctorum Andreæ Kim Tægon, presbyteri, et Pauli Chong Hasang et sociorum, martyrum', 'Saint Andrew Kim Taegon, priest, and Paul Chong Hasang and companions, martyrs', 'Santi Andrea Kim Taegon, sacerdote, Paolo Chong Hasang e compagni martiri', 3, 'Proper', 'GENERAL ROMAN', 'white'),
(153, 9, 28, 'StsLawrenceRuiz', 'Sanctorum Laurentii Ruiz et sociorum, martyrum', 'Saints Lawrence Ruiz and companions, martyrs', 'Santi Lorenzo Ruiz e compagni martiri', 2, 'Martyrs:For Several Martyrs', 'GENERAL ROMAN', 'red'),
(193, 11, 24, 'StAndrewDungLac', 'Sanctorum Andreæ Dung-Lac, presbyteri, et sociorum, martyrum', 'Saint Andrew Dung-Lac and his companions, martyrs', 'Sant\'Andrea Dung-Lac e compagni martiri', 3, 'Proper', 'GENERAL ROMAN', 'red'),
(194, 11, 25, 'StCatherineAlexandria', 'Sanctæ Catharinæ Alexandrinæ, virginis et martyris', 'Saint Catherine of Alexandria, virgin and martyr', 'Santa Caterina da Alessandria, vergine e martire', 2, 'Martyrs:For a Virgin Martyr,Virgins:For One Virgin', 'GENERAL ROMAN', 'white,red');

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
(1, 'HolyThurs', 'Feria V Hebdomadæ Sanctæ', 'Holy Thursday', 'Giovedì Santo'),
(2, 'GoodFri', 'Feria VI in Passione Domini', 'Good Friday', 'Venerdì Santo'),
(3, 'EasterVigil', 'Vigilia Paschalis', 'Easter Vigil', 'Vigilia Pasquale'),
(4, 'Easter', 'Dominica Paschæ in Resurrectione Domini', 'Easter Sunday', 'Domenica di Pasqua'),
(5, 'Christmas', 'In Nativitate Domini', 'Christmas', 'Natale'),
(6, 'MotherGod', 'SOLLEMNITAS SANCTÆ DEI GENITRICIS MARIÆ', 'Mary, Mother of God', 'Maria Ss.ma Madre di Dio'),
(7, 'Epiphany', 'in Epiphania Domini', 'Epiphany', 'Epifania'),
(8, 'Ascension', 'In Ascensione Domini', 'Ascension', 'Ascensione'),
(9, 'Pentecost', 'Dominica Pentecostes', 'Pentecost', 'Pentecoste'),
(10, 'Easter7', 'Dominica VII Paschæ', 'Seventh Sunday of Easter', 'Settima Domenica della Pasqua'),
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
(22, 'Easter2', 'Dominica II in Paschæ', 'Second Sunday of Easter', 'Seconda Domenica della Pasqua'),
(23, 'Easter3', 'Dominica III in Paschæ', 'Third Sunday of Easter', 'Terza Domenica della Pasqua'),
(24, 'Easter4', 'Dominica IV in Paschæ', 'Fourth Sunday of Easter', 'Quarta Domenica della Pasqua'),
(25, 'Easter5', 'Dominica V in Paschæ', 'Fifth Sunday of Easter', 'Quinta Domenica della Pasqua'),
(26, 'Easter6', 'Dominica VI in Paschæ', 'Sixth Sunday of Easter', 'Sesta Domenica della Pasqua'),
(27, 'Trinity', 'Dominica post Pentecostem Sanctissimæ Trinitatis', 'Holy Trinity Sunday', 'Domenica della Santissima Trinità'),
(28, 'CorpusChristi', 'Ss.mi Corporis et Sanguinis Christi', 'Corpus Christi', 'Santissimo Corpo e Sangue di Cristo'),
(29, 'AshWednesday', 'Feria IV Cinerum', 'Ash Wednesday', 'Mercoledì delle Ceneri'),
(30, 'MonHolyWeek', 'Feria II Hebdomadæ Sanctæ', 'Monday of Holy Week', 'Lunedì della Settimana Santa'),
(31, 'TueHolyWeek', 'Feria III Hebdomadæ Sanctæ', 'Tuesday of Holy Week', 'Martedì della Settimana Santa'),
(32, 'WedHolyWeek', 'Feria IV Hebdomadæ Sanctæ', 'Wednesday of Holy Week', 'Mercoledì della Settimana Santa'),
(33, 'MonOctaveEaster', 'Feria II infra Octavam Paschæ', 'Monday of the Octave of Easter', 'Lunedì dell\'Ottava di Pasqua'),
(34, 'TueOctaveEaster', 'Feria III infra Octavam Paschæ', 'Tuesday of the Octave of Easter', 'Martedì dell\'Ottava di Pasqua'),
(35, 'WedOctaveEaster', 'Feria IV infra Octavam Paschæ', 'Wednesday of the Octave of Easter', 'Mercoledì dell\'Ottava di Pasqua'),
(36, 'ThuOctaveEaster', 'Feria V infra Octavam Paschæ', 'Thursday of the Octave of Easter', 'Giovedì dell\'Ottava di Pasqua'),
(37, 'FriOctaveEaster', 'Feria VI infra Octavam Paschæ', 'Friday of the Octave of Easter', 'Venerdì dell\'Ottava di Pasqua'),
(38, 'SatOctaveEaster', 'Sabbato infra Octavam Paschæ', 'Saturday of the Octave of Easter', 'Sabato dell\'Ottava di Pasqua'),
(39, 'SacredHeart', 'Sacratissimi Cordis Iesu', 'Most Sacred Heart of Jesus', 'Sacratissimo Cuore di Gesù'),
(40, 'ChristKing', 'Domini Nostri Iesu Christi Universorum Regis', 'Christ the King', 'Cristo Re dell\'Universo'),
(41, 'BaptismLord', 'In Festo Baptismatis Domini', 'Baptism of the Lord', 'Battesimo del Signore'),
(42, 'HolyFamily', 'S. Familiæ Iesu, Mariæ et Joseph', 'Holy Family of Jesus, Mary and Joseph', 'Sacra Famiglia di Gesù, Maria e Giuseppe'),
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
(75, 'OrdSunday34', 'Dominica XXXIV «Per Annum»', '34th Sunday of Ordinary Time', 'XXXIV Domenica del Tempo Ordinario'),
(76, 'ImmaculateHeart', 'Immaculati Cordis Beatæ Mariæ Virginis', 'The Immaculate Heart of the Blessed Virgin Mary', 'Cuore Immacolato della Beata Vergine Maria');

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
-- Table structure for table `LITURGY__DIOCESILAZIO_calendar_propriumdesanctis_1973`
--
-- Creation: Aug 08, 2020 at 05:14 AM
--

CREATE TABLE `LITURGY__DIOCESILAZIO_calendar_propriumdesanctis_1973` (
  `RECURRENCE_ID` int(11) NOT NULL,
  `MONTH` int(11) NOT NULL,
  `DAY` int(11) NOT NULL,
  `TAG` varchar(50) NOT NULL,
  `NAME_IT` varchar(200) NOT NULL,
  `GRADE` int(11) NOT NULL,
  `DISPLAYGRADE` varchar(50) DEFAULT '',
  `COMMON` set('Proper','Dedication of a Church','Blessed Virgin Mary','Martyrs','Martyrs:For Several Martyrs','Martyrs:For One Martyr','Martyrs:For Missionary Martyrs','Martyrs:For Several Missionary Martyrs','Martyrs:For One Missionary Martyr','Martyrs:For a Virgin Martyr','Martyrs:For a Holy Woman Martyr','Pastors','Pastors:For a Pope','Pastors:For a Bishop','Pastors:For Several Pastors','Pastors:For One Pastor','Pastors:For Founders of a Church','Pastors:For Several Founders','Pastors:For One Founder','Doctors','Virgins','Virgins:For Several Virgins','Virgins:For One Virgin','Holy Men and Women','Holy Men and Women:For Several Saints','Holy Men and Women:For One Saint','Holy Men and Women:For an Abbot','Holy Men and Women:For a Monk','Holy Men and Women:For a Nun','Holy Men and Women:For Religious','Holy Men and Women:For Those Who Practiced Works of Mercy','Holy Men and Women:For Educators','Holy Men and Women:For Holy Women') NOT NULL,
  `CALENDAR` enum('DIOCESIDIROMA') NOT NULL DEFAULT 'DIOCESIDIROMA',
  `COLOR` set('green','purple','white','red','pink') NOT NULL DEFAULT 'white'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- RELATIONSHIPS FOR TABLE `LITURGY__DIOCESILAZIO_calendar_propriumdesanctis_1973`:
--   `GRADE`
--       `LITURGY__festivity_grade` -> `IDX`
--   `MONTH`
--       `LITURGY__months` -> `IDX`
--

--
-- Dumping data for table `LITURGY__DIOCESILAZIO_calendar_propriumdesanctis_1973`
--

INSERT INTO `LITURGY__DIOCESILAZIO_calendar_propriumdesanctis_1973` (`RECURRENCE_ID`, `MONTH`, `DAY`, `TAG`, `NAME_IT`, `GRADE`, `DISPLAYGRADE`, `COMMON`, `CALENDAR`, `COLOR`) VALUES
(1, 1, 9, 'GregorioX', 'Beato Gregorio X, papa', 2, '', 'Pastors:For a Pope', 'DIOCESIDIROMA', 'white'),
(2, 1, 10, 'StAgatone', 'Sant\'Agatone, papa', 3, '', 'Pastors:For a Pope', 'DIOCESIDIROMA', 'white'),
(3, 1, 22, 'StVincenzoPallotti', 'San Vincenzo Pallotti, sacerdote', 3, '', 'Proper', 'DIOCESIDIROMA', 'white'),
(4, 2, 1, 'LudovicaAlbertoni', 'Beata Ludovica Albertoni', 2, '', 'Holy Men and Women:For Those Who Practiced Works of Mercy', 'DIOCESIDIROMA', 'white'),
(5, 2, 28, 'StIlaro', 'Santo Ilaro, papa', 2, '', 'Pastors:For a Pope', 'DIOCESIDIROMA', 'white'),
(8, 3, 1, 'StFeliceIII', 'San Felice III, papa', 2, '', 'Pastors:For a Pope', 'DIOCESIDIROMA', 'white'),
(9, 4, 13, 'StMartinPope', 'San Martino I, papa e martire', 3, '', 'Martyrs:For One Martyr,Pastors:For a Pope', 'DIOCESIDIROMA', 'white,red'),
(10, 4, 16, 'StBenedettoLabre', 'San Benedetto Giuseppe Labre', 3, '', 'Proper', 'DIOCESIDIROMA', 'white'),
(11, 4, 19, 'StLeoIX', 'San Leone IX, papa', 3, '', 'Proper', 'DIOCESIDIROMA', 'white'),
(12, 4, 30, 'StPiusV', 'San Pio V, papa', 3, '', 'Pastors:For a Pope', 'DIOCESIDIROMA', 'white'),
(13, 5, 18, 'StFeliceCantalice', 'San Felice da Cantalice, religioso', 2, '', 'Proper', 'DIOCESIDIROMA', 'white'),
(14, 5, 23, 'StGiovanniBDeRossi', 'San Giovanni Battista de Rossi, sacerdote', 3, '', 'Proper', 'DIOCESIDIROMA', 'white'),
(15, 5, 24, 'AuxiliumChristianorum', 'Beata Maria Vergine «Auxilium Christianorum»', 2, '', 'Proper', 'DIOCESIDIROMA', 'white'),
(16, 5, 25, 'StGregoryVII', 'San Gregorio VII, papa', 3, '', 'Pastors:For a Pope', 'DIOCESIDIROMA', 'white'),
(17, 6, 2, 'StsMarcellinusPeter', 'Santi Marcellino e Pietro, martiri', 3, '', 'Martyrs:For Several Martyrs', 'DIOCESIDIROMA', 'red'),
(18, 6, 9, 'AnnaMTaigi', 'Beata Anna Maria Taigi', 3, '', 'Holy Men and Women:For Holy Women', 'DIOCESIDIROMA', 'white'),
(19, 6, 26, 'StsGiovanniPaolo', 'Santi Giovanni e Paolo, martiri', 2, '', 'Martyrs', 'DIOCESIDIROMA', 'red'),
(20, 6, 29, 'StsPeterPaulAp', 'Santi Pietro e Paolo, Apostoli,\r\nPatroni principali di Roma', 6, '', 'Proper', 'DIOCESIDIROMA', 'red'),
(21, 6, 30, 'FirstMartyrsRome', 'Santi Primi Martiri della Chiesa Romna', 3, '', 'Martyrs:For Several Martyrs', 'DIOCESIDIROMA', 'red'),
(22, 7, 23, 'StBridget', 'Santa Brigida, religiosa', 3, '', 'Holy Men and Women:For Religious,Holy Men and Women:For Holy Women', 'DIOCESIDIROMA', 'white'),
(23, 7, 28, 'UrbanoII', 'Beato Urbano II, papa', 2, '', 'Pastors:For a Pope', 'DIOCESIDIROMA', 'white'),
(24, 8, 12, 'InnocenzoXI', 'Beato Innocenzo XI, papa', 2, '', 'Pastors:For a Pope', 'DIOCESIDIROMA', 'white'),
(25, 8, 19, 'StSistoIII', 'San Sisto III, papa', 2, '', 'Pastors:For a Pope', 'DIOCESIDIROMA', 'white'),
(26, 10, 14, 'StCallistusIPope', 'San Callisto I, papa e martire', 3, '', 'Martyrs:For One Martyr,Pastors:For a Pope', 'DIOCESIDIROMA', 'white,red'),
(27, 10, 21, 'StGaspareBufalo', 'San Gaspare del Bufalo, sacerdote', 3, '', 'Proper', 'DIOCESIDIROMA', 'white'),
(28, 11, 13, 'StNicolaI', 'San Nicola I, papa', 3, '', 'Pastors:For a Pope', 'DIOCESIDIROMA', 'white'),
(29, 11, 20, 'StGelasioI', 'San Gelasio I, papa', 2, '', 'Pastors:For a Pope', 'DIOCESIDIROMA', 'white'),
(30, 11, 23, 'StClementIPope', 'San Clemente I, papa e martire', 3, '', 'Martyrs:For One Martyr,Pastors:For a Pope', 'DIOCESIDIROMA', 'white,red'),
(31, 11, 26, 'StSiricio', 'San Siricio, papa', 2, '', 'Pastors:For a Pope', 'DIOCESIDIROMA', 'white'),
(32, 11, 26, 'StLeonardoPMaurizio', 'San Leonardo da Porto Maurizio, sacerdote', 2, '', 'Holy Men and Women:For Religious', 'DIOCESIDIROMA', 'white'),
(33, 12, 11, 'StDamasusIPope', 'San Damaso I, papa', 3, '', 'Pastors:For a Pope', 'DIOCESIDIROMA', 'white'),
(34, 11, 9, 'DedicationLateran', 'Dedicazione della Basilica Papale del SS. Salvatore, Cattedrale di Roma', 4, 'in Basilica: Solennità', 'Dedication of a Church', 'DIOCESIDIROMA', 'white');

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
-- Table structure for table `LITURGY__ITALY_calendar_propriumdesanctis_1983`
--
-- Creation: Aug 08, 2020 at 01:13 AM
--

CREATE TABLE `LITURGY__ITALY_calendar_propriumdesanctis_1983` (
  `RECURRENCE_ID` int(11) NOT NULL,
  `MONTH` int(11) NOT NULL,
  `DAY` int(11) NOT NULL,
  `TAG` varchar(50) NOT NULL,
  `NAME_IT` varchar(200) NOT NULL,
  `GRADE` int(11) NOT NULL,
  `DISPLAYGRADE` varchar(50) DEFAULT '',
  `COMMON` set('Proper','Dedication of a Church','Blessed Virgin Mary','Martyrs','Martyrs:For Several Martyrs','Martyrs:For One Martyr','Martyrs:For Missionary Martyrs','Martyrs:For Several Missionary Martyrs','Martyrs:For One Missionary Martyr','Martyrs:For a Virgin Martyr','Martyrs:For a Holy Woman Martyr','Pastors','Pastors:For a Pope','Pastors:For a Bishop','Pastors:For Several Pastors','Pastors:For One Pastor','Pastors:For Founders of a Church','Pastors:For Several Founders','Pastors:For One Founder','Pastors:For Missionaries','Doctors','Virgins','Virgins:For Several Virgins','Virgins:For One Virgin','Holy Men and Women','Holy Men and Women:For Several Saints','Holy Men and Women:For One Saint','Holy Men and Women:For an Abbot','Holy Men and Women:For a Monk','Holy Men and Women:For a Nun','Holy Men and Women:For Religious','Holy Men and Women:For Those Who Practiced Works of Mercy','Holy Men and Women:For Educators','Holy Men and Women:For Holy Women') NOT NULL,
  `CALENDAR` varchar(50) DEFAULT '',
  `COLOR` set('green','purple','white','red','pink') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- RELATIONSHIPS FOR TABLE `LITURGY__ITALY_calendar_propriumdesanctis_1983`:
--   `GRADE`
--       `LITURGY__festivity_grade` -> `IDX`
--   `MONTH`
--       `LITURGY__months` -> `IDX`
--

--
-- Dumping data for table `LITURGY__ITALY_calendar_propriumdesanctis_1983`
--

INSERT INTO `LITURGY__ITALY_calendar_propriumdesanctis_1983` (`RECURRENCE_ID`, `MONTH`, `DAY`, `TAG`, `NAME_IT`, `GRADE`, `DISPLAYGRADE`, `COMMON`, `CALENDAR`, `COLOR`) VALUES
(1, 4, 23, 'StAdalbert', 'Sant\'Adalberto, vescovo e martire', 2, '', 'Martyrs:For One Martyr,Pastors:For a Bishop', '', 'white,red'),
(2, 4, 28, 'StLouisGrignonMontfort', 'San Luigi Grignon de Montfort', 2, '', 'Pastors:For One Pastor', '', 'white'),
(3, 8, 2, 'StPeterJulianEymard', 'San Pietro Giuliani, sacerdote', 2, '', 'Pastors:For One Pastor,Holy Men and Women:For Religious', '', 'white'),
(4, 8, 14, 'StMaximilianKolbe', 'San Massimiliano Kolbe, sacerdote e martire', 3, '', 'Proper', '', 'white,red'),
(5, 9, 9, 'StPeterClaver', 'San Pietro Claver, sacerdote', 2, '', 'Pastors:For One Pastor,Holy Men and Women:For Those Who Practiced Works of Mercy', '', 'white'),
(6, 9, 20, 'StAndrewKimTaegon', 'Santi Andrea Kim Taegon, sacerdote, Paolo Chong Hasang e compagni martiri', 3, '', 'Proper', '', 'white'),
(7, 9, 28, 'StsLawrenceRuiz', 'Santi Lorenzo Ruiz e compagni martiri', 2, '', 'Martyrs:For Several Martyrs', '', 'red'),
(8, 11, 24, 'StAndrewDungLac', 'Sant\'Andrea Dung-Lac e compagni martiri', 3, '', 'Proper', '', 'red');

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

-- --------------------------------------------------------

--
-- Table structure for table `LITURGY__USA_calendar_propriumdesanctis_2011`
--
-- Creation: Aug 08, 2020 at 01:58 AM
--

CREATE TABLE `LITURGY__USA_calendar_propriumdesanctis_2011` (
  `RECURRENCE_ID` int(11) NOT NULL,
  `MONTH` int(11) NOT NULL,
  `DAY` int(11) NOT NULL,
  `TAG` varchar(50) NOT NULL,
  `NAME_EN` varchar(200) NOT NULL,
  `GRADE` int(11) NOT NULL,
  `DISPLAYGRADE` varchar(50) NOT NULL,
  `COMMON` set('Proper','Dedication of a Church','Blessed Virgin Mary','Martyrs','Martyrs:For Several Martyrs','Martyrs:For One Martyr','Martyrs:For Missionary Martyrs','Martyrs:For Several Missionary Martyrs','Martyrs:For One Missionary Martyr','Martyrs:For a Virgin Martyr','Martyrs:For a Holy Woman Martyr','Pastors','Pastors:For a Pope','Pastors:For a Bishop','Pastors:For Several Pastors','Pastors:For One Pastor','Pastors:For Founders of a Church','Pastors:For Several Founders','Pastors:For One Founder','Pastors:For Missionaries','Doctors','Virgins','Virgins:For Several Virgins','Virgins:For One Virgin','Holy Men and Women','Holy Men and Women:For Several Saints','Holy Men and Women:For One Saint','Holy Men and Women:For an Abbot','Holy Men and Women:For a Monk','Holy Men and Women:For a Nun','Holy Men and Women:For Religious','Holy Men and Women:For Those Who Practiced Works of Mercy','Holy Men and Women:For Educators','Holy Men and Women:For Holy Women','Masses and Prayers for Various Needs and Occasions:For Giving Thanks to God for the Gift of Human Life','Preservation of Peace and Justice') NOT NULL,
  `CALENDAR` varchar(50) NOT NULL,
  `COLOR` set('green','purple','white','red','pink') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- RELATIONSHIPS FOR TABLE `LITURGY__USA_calendar_propriumdesanctis_2011`:
--   `GRADE`
--       `LITURGY__festivity_grade` -> `IDX`
--   `MONTH`
--       `LITURGY__months` -> `IDX`
--

--
-- Dumping data for table `LITURGY__USA_calendar_propriumdesanctis_2011`
--

INSERT INTO `LITURGY__USA_calendar_propriumdesanctis_2011` (`RECURRENCE_ID`, `MONTH`, `DAY`, `TAG`, `NAME_EN`, `GRADE`, `DISPLAYGRADE`, `COMMON`, `CALENDAR`, `COLOR`) VALUES
(1, 1, 4, 'StElizabethSeton', 'Saint Elizabeth Ann Seton, Religious', 3, '', 'Proper', '', 'white'),
(2, 1, 5, 'StJohnNeumann', 'Saint John Neumann, Bishop', 3, '', 'Proper', '', 'white'),
(3, 1, 6, 'StAndreBessette', 'Saint André Bessette, Religious', 2, '', 'Holy Men and Women:For Religious', '', 'white'),
(4, 1, 22, 'PrayerUnborn', 'Day of Prayer for the Legal Protection of Unborn Children', 3, 'National Day of Prayer', 'Masses and Prayers for Various Needs and Occasions:For Giving Thanks to God for the Gift of Human Life,Preservation of Peace and Justice', '', 'purple,white'),
(5, 3, 3, 'StKatharineDrexel', 'Saint Katharine Drexel, Virgin', 2, '', 'Virgins:For One Virgin', '', 'white'),
(6, 5, 10, 'StDamienVeuster', 'Saint Damien de Veuster, Priest', 2, '', 'Pastors:For Missionaries', '', 'white'),
(7, 5, 15, 'StIsidore', 'Saint Isidore', 2, '', 'Holy Men and Women:For One Saint', '', 'white'),
(8, 7, 1, 'JuniperoSerra', 'Blessed Junípero Serra, Priest', 2, '', 'Pastors:For One Pastor,Pastors:For Missionaries', '', 'white'),
(9, 7, 4, 'IndependenceDay', 'Independence Day', 3, 'National Holiday', '', '', 'white'),
(11, 7, 14, 'KateriTekakwitha', 'Blessed Kateri Tekakwitha, Virgin', 3, '', 'Virgins:For One Virgin', '', 'white'),
(13, 9, 9, 'PeterClaver', 'Saint Peter Claver, Priest', 3, '', 'Pastors:For One Pastor,Holy Men and Women:For Those Who Practiced Works of Mercy', '', 'white'),
(14, 10, 6, 'MarieDurocher', 'Blessed Marie Rose Durocher, Virgin', 2, '', 'Virgins:For One Virgin', '', 'white'),
(15, 11, 13, 'StFrancesXCabrini', 'Saint Frances Xavier Cabrini, Virgin', 3, '', 'Virgins:For One Virgin,Holy Men and Women:For Those Who Practiced Works of Mercy', '', 'white'),
(16, 11, 18, 'StRoseDuchesne', 'Saint Rose Philippine Duchesne, Virgin', 2, '', 'Virgins:For One Virgin', '', 'white'),
(17, 11, 23, 'MiguelPro', 'Blessed Miguel Agustín Pro, Priest and Martyr', 2, '', 'Martyrs:For One Martyr,Pastors:For One Pastor', '', 'white,red'),
(18, 12, 12, 'LadyGuadalupe', 'Our Lady of Guadalupe', 4, '', 'Proper', '', 'white');

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
-- Indexes for table `LITURGY__DIOCESILAZIO_calendar_propriumdesanctis_1973`
--
ALTER TABLE `LITURGY__DIOCESILAZIO_calendar_propriumdesanctis_1973`
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
-- Indexes for table `LITURGY__ITALY_calendar_propriumdesanctis_1983`
--
ALTER TABLE `LITURGY__ITALY_calendar_propriumdesanctis_1983`
  ADD PRIMARY KEY (`RECURRENCE_ID`),
  ADD UNIQUE KEY `TAG` (`TAG`),
  ADD KEY `MONTH_NAME` (`MONTH`),
  ADD KEY `FESTIVITY_GRADE` (`GRADE`);

--
-- Indexes for table `LITURGY__months`
--
ALTER TABLE `LITURGY__months`
  ADD PRIMARY KEY (`IDX`);

--
-- Indexes for table `LITURGY__USA_calendar_propriumdesanctis_2011`
--
ALTER TABLE `LITURGY__USA_calendar_propriumdesanctis_2011`
  ADD PRIMARY KEY (`RECURRENCE_ID`),
  ADD UNIQUE KEY `TAG` (`TAG`),
  ADD KEY `MONTH_NAME` (`MONTH`),
  ADD KEY `FESTIVITY_GRADE` (`GRADE`);

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
  MODIFY `RECURRENCE_ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=77;

--
-- AUTO_INCREMENT for table `LITURGY__DIOCESILAZIO_calendar_propriumdesanctis_1973`
--
ALTER TABLE `LITURGY__DIOCESILAZIO_calendar_propriumdesanctis_1973`
  MODIFY `RECURRENCE_ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35;

--
-- AUTO_INCREMENT for table `LITURGY__ITALY_calendar_propriumdesanctis_1983`
--
ALTER TABLE `LITURGY__ITALY_calendar_propriumdesanctis_1983`
  MODIFY `RECURRENCE_ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `LITURGY__USA_calendar_propriumdesanctis_2011`
--
ALTER TABLE `LITURGY__USA_calendar_propriumdesanctis_2011`
  MODIFY `RECURRENCE_ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

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

--
-- Constraints for table `LITURGY__DIOCESILAZIO_calendar_propriumdesanctis_1973`
--
ALTER TABLE `LITURGY__DIOCESILAZIO_calendar_propriumdesanctis_1973`
  ADD CONSTRAINT `FESTIVITY_GRADE4` FOREIGN KEY (`GRADE`) REFERENCES `LITURGY__festivity_grade` (`IDX`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  ADD CONSTRAINT `MONTH_NAME4` FOREIGN KEY (`MONTH`) REFERENCES `LITURGY__months` (`IDX`);

--
-- Constraints for table `LITURGY__ITALY_calendar_propriumdesanctis_1983`
--
ALTER TABLE `LITURGY__ITALY_calendar_propriumdesanctis_1983`
  ADD CONSTRAINT `FESTIVITY_GRADE3` FOREIGN KEY (`GRADE`) REFERENCES `LITURGY__festivity_grade` (`IDX`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  ADD CONSTRAINT `MONTH_NAME3` FOREIGN KEY (`MONTH`) REFERENCES `LITURGY__months` (`IDX`) ON DELETE NO ACTION ON UPDATE NO ACTION;

--
-- Constraints for table `LITURGY__USA_calendar_propriumdesanctis_2011`
--
ALTER TABLE `LITURGY__USA_calendar_propriumdesanctis_2011`
  ADD CONSTRAINT `GRADE` FOREIGN KEY (`GRADE`) REFERENCES `LITURGY__festivity_grade` (`IDX`),
  ADD CONSTRAINT `MONTH` FOREIGN KEY (`MONTH`) REFERENCES `LITURGY__months` (`IDX`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
