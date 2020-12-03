let CalendarIndex = {};
let CalendarNations = [];
let selectOptions = {};
const { COUNTRIES, LITCAL_LOCALE, __ } = i18n;
let countryNames = new Intl.DisplayNames([LITCAL_LOCALE], {type: 'region'});
const RequestURLBase = "LitCalEngine.php";

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
        CalendarNations.forEach(item => {
            let $optGroup = $(`<optgroup label="${countryNames.of(COUNTRIES[item])}">`);
            $('#APICalendarSelect').append($optGroup);
            selectOptions[item].forEach(groupItem => $optGroup.append(groupItem));
        });
    });

    $(document).on('change','#APICalendarSelect',function(){
        if($(this).val() != "" && $(this).val() != "VATICAN" ){
            let presetType = $(this).find(':selected').attr("data-calendartype");
            $('#RequestURLExample').text(RequestURLBase + '?' + presetType + '=' + $(this).val() );
            $('#RequestURLButton').attr('href',RequestURLBase + '?' + presetType + '=' + $(this).val() );
        } else {
            $('#RequestURLExample').text(RequestURLBase);
            $('#RequestURLButton').attr('href',RequestURLBase);
        }
    });

    $(document).on('change','.requestOption',function(){
        $('#APICalendarSelect').val("");
    });

    $(document).on('change','#RequestOptionYear',function(){
        let yearOption = ($(this).val() != "" ? `year=${$(this).val()}` : '');
        let presetType = '';
        if($('#APICalendarSelect').val() != ""  && $('#APICalendarSelect').val() != "VATICAN"){
            presetType = `${$('#APICalendarSelect').find(':selected').attr("data-calendartype")}=${$('#APICalendarSelect').val()}`;
        }
        $('#RequestURLExample').text(`${RequestURLBase}${presetType!=''?'?':''}${presetType}${yearOption!=''?'&':''}${yearOption}`);
        $('#RequestURLButton').attr('href',`${RequestURLBase}${presetType!=''?'?':''}${presetType}${yearOption!=''?'&':''}${yearOption}`);
});

})(jQuery);
/*
const onlyUnique = function(value, index, self) {
    return self.indexOf(value) === index;
}
*/
