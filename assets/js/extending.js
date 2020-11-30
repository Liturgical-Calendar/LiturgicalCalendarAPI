
$USDiocesesByState = {
    "Alabama": ["Archdiocese of Mobile", "Diocese of Birmingham"],
    "Alaska": ["Archdiocese of Anchorage-Juneau", "Diocese of Fairbanks"],
    "Arizona": ["Holy Protection of Mary Byzantine Catholic Eparchy of Phoenix", "Diocese of Phoenix", "Diocese of Tucson"],
    "Arkansas": ["Diocese of Little Rock"],
    "California": ["Armenian Catholic Eparchy of Our Lady of Nareg in the USA & Canada", "Chaldean Catholic Eparchy of St. Peter the Apostle", "Archdiocese of Los Angeles", "Archdiocese of San Francisco", "Diocese of Fresno", "Diocese of Monterey", "Diocese of Oakland", "Diocese of Orange", "Diocese of Sacramento", "Diocese of San Bernardino", "Diocese of San Diego", "Diocese of San Jose", "Diocese of Santa Rosa", "Diocese of Stockton"],
    "Colorado": ["Archdiocese of Denver", "Diocese of Colorado Springs", "Diocese of Pueblo"],
    "Connecticut": ["Ukrainian Catholic Eparchy of Stamford", "Archdiocese of Hartford", "Diocese of Bridgeport", "Diocese of Norwich"],
    "Delaware": ["Diocese of Wilmington"],
    "Florida": ["Archdiocese of Miami", "Diocese of Orlando", "Diocese of Palm Beach", "Diocese of Pensacola-Tallahassee", "Diocese of St. Augustine", "Diocese of St. Petersburg", "Diocese of Venice"],
    "Georgia": ["Archdiocese of Atlanta", "Diocese of Savannah"],
    "Hawaii": ["Diocese of Honolulu"],
    "Idaho": ["Diocese of Boise"],
    "Illinois": ["St. Nicholas of Chicago for Ukrainians", "St. Thomas Syro Malabar Diocese of Chicago", "Archdiocese of Chicago", "Diocese of Belleville", "Diocese of Joliet", "Diocese of Peoria", "Diocese of Rockford", "Diocese of Springfield in Illinois"],
    "Indiana": ["Archdiocese of Indianapolis", "Diocese of Evansville", "Diocese of Fort Wayne-South Bend", "Diocese of Gary", "Diocese of Lafayette in Indiana"],
    "Iowa": ["Archdiocese of Dubuque", "Diocese of Davenport", "Diocese of Des Moines", "Diocese of Sioux City"],
    "Kansas": ["Archdiocese of Kansas City in Kansas", "Diocese of Dodge City", "Diocese of Salina", "Diocese of Wichita"],
    "Kentucky": ["Archdiocese of Louisville", "Diocese of Covington", "Diocese of Lexington", "Diocese of Owensboro"],
    "Louisiana": ["Archdiocese of New Orleans", "Diocese of Alexandria", "Diocese of Baton Rouge", "Diocese of Houma-Thibodaux", "Diocese of Lafayette in Louisiana", "Diocese of Lake Charles", "Diocese of Shreveport"],
    "Maine": ["Diocese of Portland in Maine"],
    "Maryland": ["Archdiocese of Baltimore"],
    "Massachusetts": ["Eparchy of Newton", "Archdiocese of Boston", "Diocese of Fall River", "Diocese of Springfield in Massachusetts", "Diocese of Worcester"],
    "Michigan": ["Chaldean Eparchy of Saint Thomas the Apostle", "Archdiocese of Detroit", "Diocese of Gaylord", "Diocese of Grand Rapids", "Diocese of Kalamazoo", "Diocese of Lansing", "Diocese of Marquette", "Diocese of Saginaw"],
    "Minnesota": ["Archdiocese of St. Paul and Minneapolis", "Diocese of Crookston", "Diocese of Duluth", "Diocese of New Ulm", "Diocese of St. Cloud", "Diocese of Winona-Rochester"],
    "Mississippi": ["Diocese of Biloxi", "Diocese of Jackson"],
    "Missouri": ["Maronite Eparchy of Our Lady of Lebanon", "Archdiocese of St. Louis", "Diocese of Jefferson City", "Diocese of Kansas City-St. Joseph", "Diocese of Springfield-Cape Girardeau"],
    "Montana": ["Diocese of Great Falls-Billings", "Diocese of Helena"],
    "Nebraska": ["Archdiocese of Omaha", "Diocese of Grand Island", "Diocese of Lincoln"],
    "Nevada": ["Diocese of Las Vegas", "Diocese of Reno"],
    "New Hampshire": ["Diocese of Manchester"],
    "New Jersey": ["Byzantine Catholic Eparchy of Passaic", "Eparchy of Our Lady of Deliverance Syriac Catholic Diocese in the USA", "Archdiocese of Newark", "Diocese of Camden", "Diocese of Metuchen", "Diocese of Paterson", "Diocese of Trenton"],
    "New Mexico": ["Archdiocese of Santa Fe", "Diocese of Gallup", "Diocese of Las Cruces"],
    "New York": ["Eparchy of St. Maron of Brooklyn", "Syro-Malankara Catholic Eparchy in the USA", "Archdiocese of New York", "Diocese of Albany", "Diocese of Brooklyn", "Diocese of Buffalo", "Diocese of Ogdensburg", "Diocese of Rochester", "Diocese of Rockville Centre", "Diocese of Syracuse"],
    "North Carolina": ["Diocese of Charlotte", "Diocese of Raleigh"],
    "North Dakota": ["Diocese of Bismarck", "Diocese of Fargo"],
    "Ohio": ["Eparchy of Parma", "Eparchy of St. George in Canton for the Romanians", "Ukrainian Catholic Eparchy of St. Josaphat-Parma, OH", "Archdiocese of Cincinnati", "Diocese of Cleveland", "Diocese of Columbus", "Diocese of Steubenville", "Diocese of Toledo", "Diocese of Youngstown"],
    "Oklahoma": ["Archdiocese of Oklahoma City", "Diocese of Tulsa"],
    "Oregon": ["Archdiocese of Portland in Oregon", "Diocese of Baker"],
    "Pennsylvania": ["Byzantine Catholic Archeparchy of Pittsburgh", "Ukrainian Catholic Archeparchy of Philadelphia", "Archdiocese of Philadelphia", "Diocese of Allentown", "Diocese of Altoona-Johnstown", "Diocese of Erie", "Diocese of Greensburg", "Diocese of Harrisburg", "Diocese of Pittsburgh", "Diocese of Scranton"],
    "Rhode Island": ["Diocese of Providence"],
    "South Carolina": ["Diocese of Charleston"],
    "South Dakota": ["Diocese of Rapid City", "Diocese of Sioux Falls"],
    "Tennessee": ["Diocese of Knoxville", "Diocese of Memphis", "Diocese of Nashville"],
    "Texas": ["Archdiocese of Galveston-Houston", "Archdiocese of San Antonio", "Diocese of Amarillo", "Diocese of Austin", "Diocese of Beaumont", "Diocese of Brownsville", "Diocese of Corpus Christi", "Diocese of Dallas", "Diocese of El Paso", "Diocese of Fort Worth", "Diocese of Laredo", "Diocese of Lubbock", "Diocese of San Angelo", "Diocese of The Personal Ordinariate of the Chair of St. Peter", "Diocese of Tyler", "Diocese of Victoria"],
    "Utah": ["Diocese of Salt Lake City"],
    "Vermont": ["Diocese of Burlington"],
    "Virgin Islands": ["Diocese of St. Thomas, VI"],
    "Virginia": ["Diocese of Arlington", "Diocese of Richmond"],
    "Washington": ["Archdiocese of Seattle", "Diocese of Spokane", "Diocese of Yakima"],
    "Washington DC": ["Archdiocese of the Military Services", "Archdiocese of Washington"],
    "West Virginia": ["Diocese of Wheeling-Charleston"],
    "Wisconsin": ["Archdiocese of Milwaukee", "Diocese of Green Bay", "Diocese of La Crosse", "Diocese of Madison", "Diocese of Superior"],
    "Wyoming": ["Diocese of Cheyenne"]
};
$USDiocesesArr = [];
var c = 0;
for (const [state, arr] of Object.entries($USDiocesesByState)) {
    arr.forEach(diocese => $USDiocesesArr[c++] = diocese + " (" + state + ")");
}
$ITALYDiocesesArr = [
    "Arcidiocesi di Acerenza",
    "Diocesi di Acerra",
    "Diocesi di Acireale",
    "Diocesi di Acqui",
    "Diocesi di Adria - Rovigo",
    "Arcidiocesi di Agrigento",
    "Diocesi di Alba",
    "Sede suburbicaria di Albano",
    "Diocesi di Albenga - Imperia",
    "Diocesi di Ales - Terralba",
    "Diocesi di Alessandria",
    "Diocesi di Alghero - Bosa",
    "Diocesi di Alife - Caiazzo",
    "Diocesi di Altamura - Gravina - Acquaviva delle Fonti",
    "Arcidiocesi di Amalfi - Cava de' Tirreni",
    "Diocesi di Anagni - Alatri",
    "Arcidiocesi di Ancona - Osimo",
    "Diocesi di Andria",
    "Diocesi di Aosta",
    "Diocesi di Arezzo - Cortona - Sansepolcro",
    "Diocesi di Ariano Irpino - Lacedonia",
    "Diocesi di Ascoli Piceno",
    "Diocesi di Assisi - Nocera Umbra - Gualdo Tadino",
    "Diocesi di Asti",
    "Diocesi di Avellino",
    "Diocesi di Aversa",
    "Diocesi di Avezzano",
    "Arcidiocesi di Bari - Bitonto",
    "Diocesi di Belluno - Feltre",
    "Arcidiocesi di Benevento",
    "Diocesi di Bergamo",
    "Diocesi di Biella",
    "Arcidiocesi di Bologna",
    "Diocesi di Bolzano - Bressanone, Bozen - Brixen",
    "Diocesi di Brescia",
    "Arcidiocesi di Brindisi - Ostuni",
    "Arcidiocesi di Cagliari",
    "Diocesi di Caltagirone",
    "Diocesi di Caltanissetta",
    "Arcidiocesi di Camerino - San Severino Marche",
    "Arcidiocesi di Campobasso - Boiano",
    "Arcidiocesi di Capua",
    "Diocesi di Carpi",
    "Diocesi di Casale Monferrato",
    "Diocesi di Caserta",
    "Diocesi di Cassano all'Jonio",
    "Diocesi di Castellaneta",
    "Arcidiocesi di Catania",
    "Arcidiocesi di Catanzaro - Squillace",
    "Diocesi di Cefalù",
    "Diocesi di Cerignola - Ascoli Satriano",
    "Diocesi di Cerreto Sannita - Telese - Sant'Agata de' Goti",
    "Diocesi di Cesena - Sarsina",
    "Diocesi di Chiavari",
    "Arcidiocesi di Chieti - Vasto",
    "Diocesi di Chioggia",
    "Diocesi di Città di Castello",
    "Diocesi di Civita Castellana",
    "Diocesi di Civitavecchia - Tarquinia",
    "Diocesi di Como",
    "Diocesi di Concordia - Pordenone",
    "Diocesi di Conversano - Monopoli",
    "Diocesi di Cosenza - Bisignano",
    "Diocesi di Crema",
    "Diocesi di Cremona",
    "Diocesi di Crotone - Santa Severina",
    "Diocesi di Cuneo",
    "Diocesi di Esarcato Apostolico per i fedeli cattolici ucraini di rito bizantino residenti in ITALY",
    "Diocesi di Fabriano - Matelica",
    "Diocesi di Faenza - Modigliana",
    "Diocesi di Fano - Fossombrone - Cagli - Pergola",
    "Arcidiocesi di Fermo",
    "Arcidiocesi di Ferrara - Comacchio",
    "Diocesi di Fidenza",
    "Diocesi di Fiesole",
    "Arcidiocesi di Firenze",
    "Arcidiocesi di Foggia - Bovino",
    "Diocesi di Foligno",
    "Diocesi di Forlì - Bertinoro",
    "Diocesi di Fossano",
    "Sede suburbicaria di Frascati",
    "Diocesi di Frosinone - Veroli - Ferentino",
    "Arcidiocesi di Gaeta",
    "Arcidiocesi di Genova",
    "Arcidiocesi di Gorizia",
    "Diocesi di Grosseto",
    "Diocesi di Gubbio",
    "Diocesi di Iglesias",
    "Diocesi di Imola",
    "Diocesi di Ischia",
    "Diocesi di Isernia - Venafro",
    "Diocesi di Ivrea",
    "Diocesi di Jesi",
    "Diocesi di La Spezia - Sarzana - Brugnato",
    "Diocesi di Lamezia Terme",
    "Arcidiocesi di Lanciano - Ortona",
    "Diocesi di Lanusei",
    "Arcidiocesi di L'Aquila",
    "Diocesi di Latina - Terracina - Sezze - Priverno",
    "Arcidiocesi di Lecce",
    "Diocesi di Livorno",
    "Diocesi di Locri - Gerace",
    "Diocesi di Lodi",
    "Prelatura territoriale di Loreto",
    "Arcidiocesi di Lucca",
    "Diocesi di Lucera - Troia",
    "Eparchia di Lungro",
    "Diocesi di Macerata - Tolentino - Recanati - Cingoli - Treia",
    "Arcidiocesi di Manfredonia - Vieste - San Giovanni Rotondo",
    "Diocesi di Mantova",
    "Diocesi di Massa Carrara - Pontremoli",
    "Diocesi di Massa Marittima - Piombino",
    "Arcidiocesi di Matera - Irsina",
    "Diocesi di Mazara del Vallo",
    "Diocesi di Melfi - Rapolla - Venosa",
    "Arcidiocesi di Messina - Lipari - Santa Lucia del Mela",
    "Arcidiocesi di Milano",
    "Diocesi di Mileto - Nicotera - Tropea",
    "Arcidiocesi di Modena - Nonantola",
    "Diocesi di Molfetta - Ruvo - Giovinazzo - Terlizzi",
    "Diocesi di Mondovì",
    "Arcidiocesi di Monreale",
    "Abbazia territoriale di Monte Oliveto Maggiore",
    "Abbazia territoriale di Montecassino",
    "Diocesi di Montepulciano - Chiusi - Pienza",
    "Abbazia territoriale di Montevergine",
    "Arcidiocesi di Napoli",
    "Diocesi di Nardò - Gallipoli",
    "Diocesi di Nicosia",
    "Diocesi di Nocera Inferiore - Sarno",
    "Diocesi di Nola",
    "Diocesi di Noto",
    "Diocesi di Novara",
    "Diocesi di Nuoro",
    "Diocesi di Oppido Mamertina - Palmi",
    "Diocesi di Ordinariato Militare",
    "Diocesi di Oria",
    "Arcidiocesi di Oristano",
    "Diocesi di Orvieto - Todi",
    "Sede suburbicaria di Ostia",
    "Arcidiocesi di Otranto",
    "Diocesi di Ozieri",
    "Diocesi di Padova",
    "Arcidiocesi di Palermo",
    "Sede suburbicaria di Palestrina",
    "Diocesi di Parma",
    "Diocesi di Patti",
    "Diocesi di Pavia",
    "Arcidiocesi di Perugia - Città della Pieve",
    "Arcidiocesi di Pesaro",
    "Arcidiocesi di Pescara - Penne",
    "Diocesi di Pescia",
    "Diocesi di Piacenza - Bobbio",
    "Eparchia di Piana degli Albanesi",
    "Diocesi di Piazza Armerina",
    "Diocesi di Pinerolo",
    "Arcidiocesi di Pisa",
    "Diocesi di Pistoia",
    "Diocesi di Pitigliano - Sovana - Orbetello",
    "Prelatura territoriale di Pompei",
    "Sede suburbicaria di Porto - Santa Rufina",
    "Arcidiocesi di Potenza - Muro Lucano - Marsico Nuovo",
    "Diocesi di Pozzuoli",
    "Diocesi di Prato",
    "Diocesi di Ragusa",
    "Arcidiocesi di Ravenna - Cervia",
    "Arcidiocesi di Reggio Calabria - Bova",
    "Diocesi di Reggio Emilia - Guastalla",
    "Diocesi di Rieti",
    "Diocesi di Rimini",
    "Diocesi di Roma",
    "Arcidiocesi di Rossano - Cariati",
    "Sede suburbicaria di Sabina - Poggio Mirteto",
    "Arcidiocesi di Salerno - Campagna - Acerno",
    "Diocesi di Saluzzo",
    "Diocesi di San Benedetto del Tronto - Ripatransone - Montalto",
    "Diocesi di San Marco Argentano - Scalea",
    "Diocesi di San Marino - Montefeltro",
    "Diocesi di San Miniato",
    "Diocesi di San Severo",
    "Abbazia territoriale di Santa Maria di Grottaferrata",
    "Arcidiocesi di Sant'Angelo dei Lombardi - Conza - Nusco - Bisaccia",
    "Abbazia territoriale della Santissima Trinità di Cava de' Tirreni",
    "Arcidiocesi di Sassari",
    "Diocesi di Savona - Noli",
    "Diocesi di Senigallia",
    "Diocesi di Sessa Aurunca",
    "Arcidiocesi di Siena - Colle di Val d'Elsa - Montalcino",
    "Arcidiocesi di Siracusa",
    "Diocesi di Sora - Cassino - Aquino - Pontecorvo",
    "Arcidiocesi di Sorrento - Castellammare di Stabia",
    "Arcidiocesi di Spoleto - Norcia",
    "Abbazia territoriale di Subiaco",
    "Diocesi di Sulmona - Valva",
    "Diocesi di Susa",
    "Arcidiocesi di Taranto",
    "Diocesi di Teano - Calvi",
    "Diocesi di Teggiano - Policastro",
    "Diocesi di Tempio - Ampurias",
    "Diocesi di Teramo - Atri",
    "Diocesi di Termoli - Larino",
    "Diocesi di Terni - Narni - Amelia",
    "Diocesi di Tivoli",
    "Arcidiocesi di Torino",
    "Diocesi di Tortona",
    "Arcidiocesi di Trani - Barletta - Bisceglie",
    "Diocesi di Trapani",
    "Arcidiocesi di Trento",
    "Diocesi di Treviso",
    "Diocesi di Tricarico",
    "Diocesi di Trieste",
    "Diocesi di Trivento",
    "Diocesi di Tursi - Lagonegro",
    "Arcidiocesi di Udine",
    "Diocesi di Ugento - Santa Maria di Leuca",
    "Arcidiocesi di Urbino - Urbania - Sant'Angelo in Vado",
    "Diocesi di Vallo della Lucania",
    "Sede suburbicaria di Velletri - Segni",
    "Patriarcato di Venezia",
    "Diocesi di Ventimiglia - San Remo",
    "Arcidiocesi di Vercelli",
    "Diocesi di Verona",
    "Diocesi di Vicenza",
    "Diocesi di Vigevano",
    "Diocesi di Viterbo",
    "Diocesi di Vittorio Veneto",
    "Diocesi di Volterra"
];

const JANUARY = 1;
const FEBRUARY = 2;
const MARCH = 3;
const APRIL = 4;
const MAY = 5;
const JUNE = 6;
const JULY = 7;
const AUGUST = 8;
const SEPTEMBER = 9;
const OCTOBER = 10;
const NOVEMBER = 11;
const DECEMBER = 12;

const monthsOfThirty = [SEPTEMBER, APRIL, JUNE, NOVEMBER];

const RANK = {
    HIGHERSOLEMNITY: 7,
    SOLEMNITY: 6,
    FEASTLORD: 5,
    FEAST: 4,
    MEMORIAL: 3,
    OPTIONALMEMORIAL: 2,
    WEEKDAY: 1
}

class litEvent {
    constructor(name = "", color = "", grade = 0, common = "", day = 1, month = 1, formRowNum = -1, sinceYear = 1970) {
        this.name = name;
        this.color = color;
        this.grade = grade;
        this.common = common;
        this.day = day;
        this.month = month;
        this.formRowNum = formRowNum;
        this.sinceYear = sinceYear;
    }
}

$CALENDAR = { LitCal: {} };
$index = {};
jQuery.ajax({
    url: "nations/index.json",
    dataType: 'json',
    statusCode: {
        404: function () {
            console.log('The JSON definition "nations/index.json" does not exist yet.');
        }
    },
    success: function (data) {
        console.log('retrieved data from index file:');
        console.log(data);
        $index = data;
    }
});

let __ = function ($key) {
    $lcl = LITCAL_LOCALE.toLowerCase();
    if ($messages !== undefined && $messages !== null && typeof $messages == 'object') {
        if ($messages.hasOwnProperty($key) && typeof $messages[$key] == 'object') {
            if ($messages[$key].hasOwnProperty($lcl)) {
                return $messages[$key][$lcl];
            } else {
                return $messages[$key]["en"];
            }
        } else {
            return $key;
        }
    } else {
        return $key;
    }
};

class FormControls {
    static uniqid = 0;
    static settings = {
        nameField: true,
        dayField: true,
        monthField: true,
        colorField: true,
        properField: true,
        fromYearField: true
    }

    static CreateFestivityRow(title = null) {
        let formRow = '';

        if (title !== null) {
            formRow += `<h4>${__(title)}</h4>`;
        }

        formRow += `<div class="form-row">`;

        if (FormControls.settings.nameField) {
            formRow += `<div class="form-group col-sm-3">
            <label for="onTheFly${FormControls.uniqid}Name">${__("Name")}</label><input type="text" class="form-control litEvent litEventName" id="onTheFly${FormControls.uniqid}Name" data-valuewas="" />
            <div class="invalid-feedback">This same celebration was already defined elsewhere. Please remove it first where it is defined, then you can define it here.</div>
            </div>`;
        }

        if (FormControls.settings.dayField) {
            formRow += `<div class="form-group col-sm-1">
            <label for="onTheFly${FormControls.uniqid}Day">${__("Day")}</label><input type="number" min="1" max="31" value="1" class="form-control litEvent litEventDay" id="onTheFly${FormControls.uniqid}Day" />
            </div>`;
        }

        if (FormControls.settings.monthField) {
            formRow += `<div class="form-group col-sm-2">
            <label for="onTheFly${FormControls.uniqid}Month">${__("Month")}</label>
            <select class="form-control litEvent litEventMonth" id="onTheFly${FormControls.uniqid}Month">`;

            let formatter = new Intl.DateTimeFormat(LITCAL_LOCALE, { month: 'long' });
            for (let i = 0; i < 12; i++) {
                let month = new Date(Date.UTC(0, i, 2, 0, 0, 0));
                formRow += `<option value=${i + 1}>${formatter.format(month)}</option>`;
            }

            formRow += `</select>
            </div>`;
        }

        if (FormControls.settings.properField) {
            formRow += `<div class="form-group col-sm-3">
            <label style="display:block;" for="onTheFly${FormControls.uniqid}Proper">${__("Common (or Proper)")}</label>
            <select class="form-control litEvent litEventProper" id="{$uniqid}Proper" multiple="multiple" />
            <option value="Proper" selected>${__("Proper")}</option>
            <option value="Blessed Virgin Mary">${__("Common of the Blessed Virgin Mary")}</option>
            <option value="Martyrs">${__("Common of Martyrs")}</option>
            <option value="Martyrs:For One Martyr">${__("Common of Martyrs: For One Martyr")}</option>
            <option value="Martyrs:For Several Martyrs">${__("Common of Martyrs: For Several Martyrs")}</option>
            <option value="Martyrs:For Missionary Martyrs">${__("Common of Martyrs: For Missionary Martyrs")}</option>
            <option value="Martyrs:For One Missionary Martyr">${__("Common of Martyrs: For One Missionary Martyr")}</option>
            <option value="Martyrs:For Several Missionary Martyrs">${__("Common of Martyrs: For Several Missionary Martyrs")}</option>
            <option value="Martyrs:For a Virgin Martyr">${__("Common of Martyrs: For a Virgin Martyr")}</option>
            <option value="Martyrs:For a Holy Woman Martyr">${__("Common of Martyrs: For a Holy Woman Martyr")}</option>
            <option value="Pastors">${__("Common of Pastors")}</option>
            <option value="Pastors:For a Pope">${__("Common of Pastors: For a Pope")}</option>
            <option value="Pastors:For a Bishop">${__("Common of Pastors: For a Bishop")}</option>
            <option value="Pastors:For One Pastor">${__("Common of Pastors: For One Pastor")}</option>
            <option value="Pastors:For Several Pastors">${__("Common of Pastors: For Several Pastors")}</option>
            <option value="Pastors:Missionaries">${__("Common of Pastors: For Missionaries")}</option>
            <option value="Pastors:For Founders of a Church">${__("Common of Pastors: For Founders of a Church")}</option>
            <option value="Pastors:For Several Founders">${__("Common of Pastors: For Several Founders")}</option>
            <option value="Pastors:For One Founder">${__("Common of Pastors: For One Founder")}</option>
            <option value="Doctors">${__("Common of Doctors")}</option>
            <option value="Virgins">${__("Common of Virgins")}</option>
            <option value="Virgins:For One Virgin">${__("Common of Virgins: For One Virgin")}</option>
            <option value="Virgins:For Several Virgins">${__("Common of Virgins: For Several Virgins")}</option>
            <option value="Holy Men and Women">${__("Common of Holy Men and Women")}</option>
            <option value="Holy Men and Women:For One Saint">${__("Common of Holy Men and Women: For One Saint")}</option>
            <option value="Holy Men and Women:For Several Saints">${__("Common of Holy Men and Women: For Several Saints")}</option>
            <option value="Holy Men and Women:For Religious">${__("Common of Holy Men and Women: For Religious")}</option>
            <option value="Holy Men and Women:For an Abbot">${__("Common of Holy Men and Women: For an Abbot")}</option>
            <option value="Holy Men and Women:For a Monk">${__("Common of Holy Men and Women: For a Monk")}</option>
            <option value="Holy Men and Women:For a Nun">${__("Common of Holy Men and Women: For a Nun")}</option>
            <option value="Holy Men and Women:For Educators">${__("Common of Holy Men and Women: For Educators")}</option>
            <option value="Holy Men and Women:For Holy Women">${__("Common of Holy Men and Women: For Holy Women")}</option>
            <option value="Holy Men and Women:For Those Who Practiced Works of Mercy">${__("Common of Holy Men and Women: For Those Who Practiced Works of Mercy")}</option>
            <option value="Dedication of a Church">${__("Common of the Dedication of a Church")}</option>
            </select>
            </div>`;
        }

        if (FormControls.settings.colorField) {
            formRow += `<div class="form-group col-sm-2">
            <label for="onTheFly${FormControls.uniqid}Color">${__("Liturgical color")}</label>
            <select class="form-control litEvent litEventColor" id="onTheFly${FormControls.uniqid}Color" multiple="multiple" />
            <option value="white" selected>${__("white").toUpperCase()}</option>
            <option value="red">${__("red").toUpperCase()}</option>
            <option value="purple">${__("purple").toUpperCase()}</option>
            <option value="green">${__("green").toUpperCase()}</option>
            </select>
            </div>`;
        }

        if (FormControls.settings.fromYearField) {
            formRow += `<div class="form-group col-sm-1">
            <label for="onTheFly${FormControls.uniqid}FromYear">${__("Since")}</label>
            <input type="number" min="1970" max="9999" class="form-control litEvent litEventFromYear" id="onTheFly${FormControls.uniqid}FromYear" value="1970" />
            </div>`;
        }

        formRow += `</div>`;
        ++FormControls.uniqid;

        return formRow;

    }
}

$(document).on('click', '#diocesanCalendarDefinitionCardLinks a.page-link', function (event) {
    event.preventDefault();
    $('#diocesanCalendarDefinitionCardLinks li').removeClass('active');
    //console.log("you clicked " + $(this).text());
    if ($(this).hasClass('diocesan-carousel-next')) {
        $('.carousel').carousel('next');
    } else if ($(this).hasClass('diocesan-carousel-prev')) {
        $('.carousel').carousel('prev');
    } else {
        $(this).parent('li').addClass('active');
        $('.carousel').carousel(parseInt($(this).attr('data-slide-to')));
    }
});

$(document).on('change', '.litEvent', function (event) {
    $row = $(this).closest('.form-row');
    $card = $(this).closest('.card-body');
    if ($(this).hasClass('litEventName')) {
        console.log('LitEvent name has changed');
        if ($(this).val() == '') {
            //empty value probably means we are trying to delete an already defined event
            //so let's find the key and remove it
            oldEventKey = $(this).attr('data-valuewas');
            if ($CALENDAR.LitCal.hasOwnProperty(oldEventKey)) {
                delete $CALENDAR.LitCal[oldEventKey];
            }
            /*
            //so let's go back over all the name fields and recreate a clean LitCal object
            $CALENDAR = {LitCal:{}};
            $('#carouselItemSolemnities .form-row').each(function(idx,el){
                if($(el).find('.litEventName').val() != ''){
                    eventKey = $(el).find('.litEventName').val().replace(/[^a-zA-Z]/gi, '');

                }
            });
            */
        } else {
            eventKey = $(this).val().replace(/[^a-zA-Z]/gi, '');
            console.log('new LitEvent name identifier is ' + eventKey);
            if ($(this).attr('data-valuewas') == '' && $CALENDAR.LitCal.hasOwnProperty(eventKey) === false) {
                console.log('there was no data-valuewas attribute or it was empty, so we are creating ex-novo a new LitEvent');
                $CALENDAR.LitCal[eventKey] = new litEvent();
                $CALENDAR.LitCal[eventKey].name = $(this).val();
                //let's initialize defaults just in case the default input values happen to be correct, so no change events are fired
                $CALENDAR.LitCal[eventKey].day = parseInt($row.find('.litEventDay').val());
                $CALENDAR.LitCal[eventKey].month = parseInt($row.find('.litEventMonth').val());
                $CALENDAR.LitCal[eventKey].color = $row.find('.litEventColor').val();
                $CALENDAR.LitCal[eventKey].common = $row.find('.litEventProper').val().join(',');
                $CALENDAR.LitCal[eventKey].sinceYear = $row.find('.litEventFromYear').val();
                $CALENDAR.LitCal[eventKey].formRowNum = $card.find('.form-row').index($row);
                $(this).attr('data-valuewas', eventKey);
                $(this).removeClass('is-invalid');
            } else if ($(this).attr('data-valuewas') != '') {
                oldEventKey = $(this).attr('data-valuewas');
                console.log('the preceding value here was ' + oldEventKey);
                if ($CALENDAR.LitCal.hasOwnProperty(oldEventKey)) {
                    if (oldEventKey !== eventKey) {
                        console.log('will now attempt to copy the values from <' + oldEventKey + '> to <' + eventKey + '> and then remove <' + oldEventKey + '>');
                        Object.defineProperty($CALENDAR.LitCal, eventKey,
                            Object.getOwnPropertyDescriptor($CALENDAR.LitCal, oldEventKey));
                        $CALENDAR.LitCal[eventKey].name = $(this).val();
                        delete $CALENDAR.LitCal[oldEventKey];
                        $(this).attr('data-valuewas', eventKey);
                        $(this).removeClass('is-invalid');
                    }
                }
            } else if ($CALENDAR.LitCal.hasOwnProperty(eventKey)) {
                //this exact same festivity name was already defined elsewhere!
                $(this).val('');
                $(this).addClass('is-invalid');
            }
            switch ($(this).closest('.carousel-item').attr('id')) {
                case 'carouselItemSolemnities':
                    $CALENDAR.LitCal[eventKey].grade = 6;
                    if ($(this).val().match(/(martyr|martir|mártir|märtyr)/i) !== null) {
                        $row.find('.litEventColor').multiselect('deselectAll', false).multiselect('select', 'red');
                        $CALENDAR.LitCal[eventKey].color = 'red';
                    } else {
                        $row.find('.litEventColor').multiselect('deselectAll', false).multiselect('select', 'white');
                        $CALENDAR.LitCal[eventKey].color = 'white';
                    }
                    break;
                case 'carouselItemFeasts':
                    $CALENDAR.LitCal[eventKey].grade = 4;
                    break;
                case 'carouselItemMemorials':
                    $CALENDAR.LitCal[eventKey].grade = 3;
                    break;
                case 'carouselItemOptionalMemorials':
                    $CALENDAR.LitCal[eventKey].grade = 2;
                    break;
            }
        }
    } else if ($(this).hasClass('litEventDay')) {
        if ($row.find('.litEventName').val() != "") {
            eventKey = $row.find('.litEventName').val().replace(/[^a-zA-Z]/gi, '');
            if ($CALENDAR.LitCal.hasOwnProperty(eventKey)) {
                $CALENDAR.LitCal[eventKey].day = parseInt($(this).val());
            }
        }
    } else if ($(this).hasClass('litEventMonth')) {
        let selcdMonth = parseInt($(this).val());
        if ($row.find('.litEventName').val() != "") {
            eventKey = $row.find('.litEventName').val().replace(/[^a-zA-Z]/gi, '');
            if ($CALENDAR.LitCal.hasOwnProperty(eventKey)) {
                $CALENDAR.LitCal[eventKey].month = selcdMonth;
            }
        }
        $row.find('.litEventDay').attr('max', selcdMonth === FEBRUARY ? "28" : (monthsOfThirty.includes(selcdMonth) ? "30" : "31"));
        if (parseInt($row.find('.litEventDay').val()) > parseInt($row.find('.litEventDay').attr('max'))) {
            $row.find('.litEventDay').val($row.find('.litEventDay').attr('max'));
        }
    } else if ($(this).hasClass('litEventProper')) {
        if ($row.find('.litEventName').val() != "") {
            eventKey = $row.find('.litEventName').val().replace(/[^a-zA-Z]/gi, '');
            if ($CALENDAR.LitCal.hasOwnProperty(eventKey)) {
                if (typeof $(this).val() === 'object') {
                    $CALENDAR.LitCal[eventKey].common = $(this).val().join();
                } else {
                    $CALENDAR.LitCal[eventKey].common = $(this).val();
                }
                switch ($row.closest('.carousel-item').attr('id')) {
                    case 'carouselItemSolemnities':
                        /* we actually check this on name change
                        if($row.find('.litEventName').match(/(martyr|martir|mártir|märtyr)/i) !== null){
                            $row.find('.litEventColor').multiselect('deselectAll',false).multiselect('select','red');
                        } else {
                            $row.find('.litEventColor').multiselect('deselectAll',false).multiselect('select','white');
                        }
                        */
                        break;
                    case 'carouselItemFeasts':
                    case 'carouselItemMemorials':
                    case 'carouselItemOptionalMemorials':
                        let eventColors = [];
                        if ($CALENDAR.LitCal[eventKey].common.includes('Martyrs')) {
                            eventColors.push('red');
                        }
                        if ($CALENDAR.LitCal[eventKey].common.match(/(Blessed Virgin Mary|Pastors|Doctors|Virgins|Holy Men and Women|Dedication of a Church)/) !== null) {
                            eventColors.push('white');
                        }
                        $row.find('.litEventColor').multiselect('deselectAll', false).multiselect('select', eventColors);
                        $CALENDAR.LitCal[eventKey].color = eventColors.join(',');
                        break;
                }
            }
        }
    } else if ($(this).hasClass('litEventColor')) {
        if ($row.find('.litEventName').val() != "") {
            eventKey = $row.find('.litEventName').val().replace(/[^a-zA-Z]/gi, '');
            if ($CALENDAR.LitCal.hasOwnProperty(eventKey)) {
                $CALENDAR.LitCal[eventKey].color = $(this).val();
            }
        }
    } else if ($(this).hasClass('litEventFromYear')) {
        if ($row.find('.litEventName').val() != "") {
            eventKey = $row.find('.litEventName').val().replace(/[^a-zA-Z]/gi, '');
            if ($CALENDAR.LitCal.hasOwnProperty(eventKey)) {
                $CALENDAR.LitCal[eventKey].sinceYear = $(this).val();
            }
        }
    }
});

$(document).on('click', '#saveDiocesanCalendar_btn', function () {
    $nation = $('#diocesanCalendarNationalDependency').val();
    $diocese = $('#diocesanCalendarDioceseName').val();
    //$CALENDAR.Nation = $nation;
    //$CALENDAR.Diocese = $diocese;
    $data = JSON.stringify($CALENDAR);
    console.log('save button was clicked for NATION = ' + $nation + ', DIOCESE = ' + $diocese);
    let formsValid = true;
    $('form').each(function () {
        if (this.checkValidity() === false) {
            formsValid = false;
        }
        $(this).addClass('was-validated');
    });
    if (formsValid) {
        $.ajax({
            url: './writeDiocesanCalendar.php',
            method: 'post',
            dataType: 'json',
            data: { calendar: $data, diocese: $diocese, nation: $nation },
            success: function (data) {
                console.log('data returned from save action: ');
                console.log(data);
                $('<div class="toast" role="alert" aria-live="assertive" aria-atomic="true"><div class="toast-header"><img src="..." class="rounded mr-2" alt="..."><strong class="mr-auto">Bootstrap</strong><small class="text-muted">just now</small><button type="button" class="ml-2 mb-1 close" data-dismiss="toast" aria-label="Close"><span aria-hidden="true">&times;</span></button></div><div class="toast-body">See? Just like this.</div></div>').toast();
            },
            error: function (jqXHR, textStatus, errorThrown) {
                console.log(textStatus + ': ' + errorThrown);
                alert('there was an error!');
            }
        });
    } else {
        alert('Nation / Diocese cannot be empty');
    }
});

$(document).on('click', '#retrieveExistingDiocesanData', function (evt) {
    evt.preventDefault();
    let diocese = $('#diocesanCalendarDioceseName').val();
    let dioceseKey = $('#DiocesesList').find('option[value="' + diocese + '"]').attr('data-value').toUpperCase();
    let $diocesanCalendar;
    jQuery.ajax({
        url: $index[dioceseKey].path,
        dataType: 'json',
        statusCode: {
            404: function () {
                console.log('The JSON definition ' + $index[dioceseKey].path + ' does not exist yet.');
            }
        },
        success: function (data) {
            console.log('retrieved diocesan data:');
            console.log(data);
            $CALENDAR = data;
            //TODO: instead of creating / appending a single row, we have to check how many rows are missing
            //      between the last existing row and the rowNum for the current item
            //      and create all of the missing rows
            for (const [key, litevent] of Object.entries(data.LitCal)) {
                let $form;
                let $row;
                let numLastRow;
                let numMissingRows;
                switch (litevent.grade) {
                    case RANK.SOLEMNITY:
                        $form = $('#carouselItemSolemnities form');
                        numLastRow = $form.find('.form-row').length - 1;
                        if (litevent.formRowNum > numLastRow) {
                            numMissingRows = litevent.formRowNum - numLastRow;
                            while (numMissingRows-- > 0) {
                                $form.append($(FormControls.CreateFestivityRow('Other Solemnity')));
                            }
                        }
                        $row = $('#carouselItemSolemnities form .form-row').eq(litevent.formRowNum);
                        break;
                    case RANK.FEAST:
                        numLastRow = $('#carouselItemFeasts form .form-row').length - 1;
                        if (litevent.formRowNum > numLastRow) {
                            numMissingRows = litevent.formRowNum - numLastRow;
                            while (numMissingRows-- > 0) {
                                $('.carousel-item').eq(1).find('form').append($(FormControls.CreateFestivityRow('Other Feast')));
                            }
                        }
                        $row = $('#carouselItemFeasts form .form-row').eq(litevent.formRowNum);
                        break;
                    case RANK.MEMORIAL:
                        numLastRow = $('#carouselItemMemorials form .form-row').length - 1;
                        if (litevent.formRowNum > numLastRow) {
                            numMissingRows = litevent.formRowNum - numLastRow;
                            while (numMissingRows-- > 0) {
                                $('.carousel-item').eq(2).find('form').append($(FormControls.CreateFestivityRow('Other Memorial')));
                            }
                        }
                        $row = $('#carouselItemMemorials form .form-row').eq(litevent.formRowNum);
                        break;
                    case RANK.OPTIONALMEMORIAL:
                        numLastRow = $('#carouselItemOptionalMemorials form .form-row').length - 1;
                        if (litevent.formRowNum > numLastRow) {
                            numMissingRows = litevent.formRowNum - numLastRow;
                            while (numMissingRows-- > 0) {
                                $('.carousel-item').eq(3).find('form').append($(FormControls.CreateFestivityRow('Other Optional Memorial')));
                            }
                        }
                        $row = $('#carouselItemOptionalMemorials form .form-row').eq(litevent.formRowNum);
                        break;
                }
                $row.find('.litEventName').val(litevent.name).attr('data-valuewas', litevent.name.replace(/[^a-zA-Z]/gi, ''));
                $row.find('.litEventDay').val(litevent.day);
                $row.find('.litEventMonth').val(litevent.month);
                $row.find('.litEventProper').multiselect({
                    buttonWidth: '100%',
                    maxHeight: 200,
                    enableCaseInsensitiveFiltering: true,
                    onChange: function (option, checked, select) {
                        if ($(option).val() != 'Proper' && checked === true && $(option).parent().val().includes('Proper')) {
                            $(option).parent().multiselect('deselect', 'Proper');
                        } else if ($(option).val() == 'Proper' && checked === true) {
                            $(option).parent().multiselect('deselectAll', false).multiselect('select', 'Proper');
                        }
                    }
                }).multiselect('deselectAll', false).multiselect('select', litevent.common.toString().split(','));
                if (typeof litevent.color !== 'string') {
                    litevent.color = litevent.color.join(',');
                }
                $row.find('.litEventColor').multiselect({ buttonWidth: '100%' }).multiselect('deselectAll', false).multiselect('select', litevent.color.toString().split(','));
                $row.find('.litEventFromYear').val(litevent.sinceYear);
            };
        }
    });
});

$(document).on('click', '.onTheFlyEventRow', function () {
    let $row;
    switch (this.id) {
        case "addSolemnity":
            $row = $(FormControls.CreateFestivityRow('Other Solemnity'));
            $('.carousel-item').first().find('form').append($row);
            break;
        case "addFeast":
            $row = $(FormControls.CreateFestivityRow('Other Feast'));
            $('.carousel-item').eq(1).find('form').append($row);
            break;
        case "addMemorial":
            $row = $(FormControls.CreateFestivityRow('Other Memorial'));
            $('.carousel-item').eq(2).find('form').append($row);
            break;
        case "addOptionalMemorial":
            $row = $(FormControls.CreateFestivityRow('Other Optional Memorial'));
            $('.carousel-item').eq(3).find('form').append($row);
            break;
    }

    $row.find('.litEventProper').multiselect({
        buttonWidth: '100%',
        maxHeight: 200,
        //enableCollapsibleOptGroups: true,
        //collapseOptGroupsByDefault: true,
        enableCaseInsensitiveFiltering: true,
        onChange: function (option, checked, select) {
            if ($(option).val() != 'Proper' && checked === true && $(option).parent().val().includes('Proper')) {
                $(option).parent().multiselect('deselect', 'Proper');
            } else if ($(option).val() == 'Proper' && checked === true) {
                $(option).parent().multiselect('deselectAll', false).multiselect('select', 'Proper');
            }
        }
    });

    $row.find('.litEventColor').multiselect({
        buttonWidth: '100%'
    });
});


$(document).on('change', '#diocesanCalendarDioceseName', function () {
    $CALENDAR = { LitCal: {} };
    $('.carousel-item form').each(function () {
        this.reset();
        $(this).find('.form-row').slice(3).remove();
        $(this).find('h4').slice(3).remove();
        $(this).find('.litEventProper').multiselect('deselectAll', false).multiselect('select', 'Proper');
        $(this).find('.litEventColor').multiselect('deselectAll', false).multiselect('select', 'white');
        $(this).find('.litEventName').attr('data-valuewas', '');
    });
    $('form').each(function () { $(this).removeClass('was-validated') });
    //first we'll enforce only values from the current datalist
    if ($('#DiocesesList').find('option[value="' + $(this).val() + '"]').length > 0) {
        $(this).removeClass('is-invalid');
        $key = $('#DiocesesList').find('option[value="' + $(this).val() + '"]').attr('data-value').toUpperCase();
        console.log('selected diocese with key = ' + $key);
        if ($index.hasOwnProperty($key)) {
            $('#retrieveExistingDiocesanData').prop('disabled', false);
            console.log('we have an existing entry for this diocese!');
        } else {
            $('#retrieveExistingDiocesanData').prop('disabled', true);
            console.log('no existing entry for this diocese');
        }
    } else {
        $(this).addClass('is-invalid');
    }
});


$(document).on('change', '#diocesanCalendarNationalDependency', function () {
    $('#diocesanCalendarDioceseName').val('');
    $('#retrieveExistingDiocesanData').prop('disabled', true);
    switch ($(this).val()) {
        case "ITALY":
            $('#DiocesesList').empty();
            $ITALYDiocesesArr.forEach(diocese => $('#DiocesesList').append('<option data-value="' + diocese.replace(/[^a-zA-Z]/gi, '').toUpperCase() + '" value="' + diocese + '">'));
            break;
        case "USA":
            $('#DiocesesList').empty();
            $USDiocesesArr.forEach(diocese => $('#DiocesesList').append('<option data-value="' + diocese.replace(/[^a-zA-Z]/gi, '').toUpperCase() + '" value="' + diocese + '">'));
            break;
        default:
            $('#DiocesesList').empty();
    }
});

jQuery(document).ready(function () {
    let $carousel = $('.carousel').carousel();

    $carousel.on('slide.bs.carousel', function (event) {
        $('#diocesanCalendarDefinitionCardLinks li').removeClass('active');
        if (event.to == 0) {
            $('#diocesanCalendarDefinitionCardLinks li:first-child').addClass('disabled');
            $('#diocesanCalendarDefinitionCardLinks li:last-child').removeClass('disabled');
        } else if (event.to == 3) {
            $('#diocesanCalendarDefinitionCardLinks li:last-child').addClass('disabled');
            $('#diocesanCalendarDefinitionCardLinks li:first-child').removeClass('disabled');
        } else {
            $('#diocesanCalendarDefinitionCardLinks li:first-child').removeClass('disabled');
            $('#diocesanCalendarDefinitionCardLinks li:last-child').removeClass('disabled');
        }
        $('#diocesanCalendarDefinitionCardLinks li').find('[data-slide-to=' + event.to + ']').parent('li').addClass('active');
    });

    $('.litEventProper').multiselect({
        buttonWidth: '100%',
        maxHeight: 200,
        //enableCollapsibleOptGroups: true,
        //collapseOptGroupsByDefault: true,
        enableCaseInsensitiveFiltering: true,
        onChange: function (option, checked, select) {
            if ($(option).val() != 'Proper' && checked === true && $(option).parent().val().includes('Proper')) {
                $(option).parent().multiselect('deselect', 'Proper');
            } else if ($(option).val() == 'Proper' && checked === true) {
                $(option).parent().multiselect('deselectAll', false).multiselect('select', 'Proper');
            }
        }
    });

    $('.litEventColor').multiselect({
        buttonWidth: '100%'
    });

});
