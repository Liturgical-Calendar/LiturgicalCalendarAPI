let CalendarIndex = {};
let CalendarNations = [];
let selectOptions = {};
const { COUNTRIES, LITCAL_LOCALE, __ } = i18n;
let countryNames = new Intl.DisplayNames([LITCAL_LOCALE], {type: 'region'});
const RequestURLBase = "LitCalEngine.php";
let requestURL = {
    year: null,
    corpuschristi: null,
    epiphany: null,
    ascension: null,
    locale: null,
    returntype: null,
    nationalpreset: null,
    diocesanpreset: null
};

let serializeRequestURL = function(obj){
    let parameters = [];
    for (const key in obj) {
        if(obj[key] != null && obj[key] != ''){
            parameters.push(key + "=" + encodeURIComponent(obj[key]));
        }
    }
    return parameters.join('&');
};

(function ($) {
    $.getJSON('nations/index.json',function(data){
        CalendarIndex = data;
        for(const [key,value] of Object.entries(CalendarIndex)){
            if(CalendarNations.indexOf(value.nation) === -1){
                CalendarNations.push(value.nation);
                selectOptions[value.nation] = [];
            }
            CalendarNations.sort();
            selectOptions[value.nation].push(`<option data-calendartype="diocesanpreset" value="${key}">${value.diocese}</option>`);
        }
        //CalendarNations = nationsTmp.filter(onlyUnique);
        CalendarNations.forEach(item => $('#APICalendarSelect').append(`<option data-calendartype="nationalpreset" value="${item}">${countryNames.of(COUNTRIES[item])}</option>`));
        if(CalendarNations.indexOf("ITALY") === -1){
            $('#APICalendarSelect').append(`<option data-calendartype="nationalpreset" value="ITALY">${countryNames.of(COUNTRIES["ITALY"])}</option>`);
        }
        if(CalendarNations.indexOf("USA") === -1){
            $('#APICalendarSelect').append(`<option data-calendartype="nationalpreset" value="USA">${countryNames.of(COUNTRIES["USA"])}</option>`);
        }
        CalendarNations.forEach(item => {
            let $optGroup = $(`<optgroup label="${countryNames.of(COUNTRIES[item])}">`);
            $('#APICalendarSelect').append($optGroup);
            selectOptions[item].forEach(groupItem => $optGroup.append(groupItem));
        });
    });

    $(document).on('change','#APICalendarSelect',function(){
        if($(this).val() != "" && $(this).val() != "VATICAN" ){
            let presetType = $(this).find(':selected').attr("data-calendartype");
            switch(presetType){
                case 'nationalpreset':
                    requestURL.nationalpreset = $(this).val();
                    requestURL.diocesanpreset = null;
                    break;
                case 'diocesanpreset':
                    requestURL.diocesanpreset = $(this).val();
                    requestURL.nationalpreset = null;
                    break;
            }
        } else {
            requestURL.nationalpreset = null;
            requestURL.diocesanpreset = null;
        }
        requestURL.locale = null;
        requestURL.ascension = null;
        requestURL.corpuschristi = null;
        requestURL.epiphany = null;
        $('.requestOption').val('');
        let requestURL_encoded = serializeRequestURL(requestURL);
        $('#RequestURLExample').text(`${RequestURLBase}${requestURL_encoded!=''?'?':''}${requestURL_encoded}`);
        $('#RequestURLButton').attr('href',`${RequestURLBase}${requestURL_encoded!=''?'?':''}${requestURL_encoded}`);
    });

    $(document).on('change','#RequestOptionReturnType',function(){
        requestURL.returntype = $(this).val();
        let requestURL_encoded = serializeRequestURL(requestURL);
        $('#RequestURLExample').text(`${RequestURLBase}${requestURL_encoded!=''?'?':''}${requestURL_encoded}`);
        $('#RequestURLButton').attr('href',`${RequestURLBase}${requestURL_encoded!=''?'?':''}${requestURL_encoded}`);
    });

    $(document).on('change','.requestOption',function(){
        $('#APICalendarSelect').val("");
        requestURL.nationalpreset = null;
        requestURL.diocesanpreset = null;
        switch($(this).attr("id")){
            case 'RequestOptionEpiphany':
                requestURL.epiphany = $(this).val();
                break;
            case 'RequestOptionCorpusChristi':
                requestURL.corpuschristi = $(this).val();
                break;
            case 'RequestOptionAscension':
                requestURL.ascension = $(this).val();
                break;
            case 'RequestOptionLocale':
                requestURL.locale = $(this).val();
                break;
        }
        let requestURL_encoded = serializeRequestURL(requestURL);
        $('#RequestURLExample').text(`${RequestURLBase}${requestURL_encoded!=''?'?':''}${requestURL_encoded}`);
        $('#RequestURLButton').attr('href',`${RequestURLBase}${requestURL_encoded!=''?'?':''}${requestURL_encoded}`);
    });

    $(document).on('change','#RequestOptionYear',function(){
        requestURL.year = $(this).val();
        let requestURL_encoded = serializeRequestURL(requestURL);
        $('#RequestURLExample').text(`${RequestURLBase}${requestURL_encoded!=''?'?':''}${requestURL_encoded}`);
        $('#RequestURLButton').attr('href',`${RequestURLBase}${requestURL_encoded!=''?'?':''}${requestURL_encoded}`);
    });

})(jQuery);
/*
const onlyUnique = function(value, index, self) {
    return self.indexOf(value) === index;
}
*/
