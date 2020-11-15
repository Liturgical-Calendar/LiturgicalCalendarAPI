(function ($) {
    let locale = Cookies.get("currentLocale").substring(0,2);
    let languageNames = new Intl.DisplayNames([locale], {type: 'language'});
    $(document).ready(function(){
      $('#langChoicesDropdown').text( languageNames.of(locale) );
      $('#langChoiceEnglish').text( languageNames.of('en') );
      $('#langChoiceFrench').text( languageNames.of('fr') );
      $('#langChoiceGerman').text( languageNames.of('de') );
      $('#langChoiceItalian').text( languageNames.of('it') );
      $('#langChoiceSpanish').text( languageNames.of('es') );
      $('#langChoicePortuguese').text( languageNames.of('pt') );
      //$('#langChoiceLatin').text( languageNames.of('en') );
    });
})(jQuery);
