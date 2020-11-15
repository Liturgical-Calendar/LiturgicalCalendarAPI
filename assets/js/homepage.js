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
      $('#langChoiceLatin').text( languageNames.of('lat') );
      $(document).on('click','#langChoicesDropdownItems .dropdown-item',function(){
        switch( $(this).attr('id') ){
            case 'langChoiceEnglish':
                Cookies.set('currentLocale','en');
                break;
            case 'langChoiceFrench':
                Cookies.set('currentLocale','fr');
                break;
            case 'langChoiceGerman':
                Cookies.set('currentLocale','de');
                break;
            case 'langChoiceItalian':
                Cookies.set('currentLocale','it');
                break;
            case 'langChoiceSpanish':
                Cookies.set('currentLocale','es');
                break;
            case 'langChoicePortuguese':
                Cookies.set('currentLocale','pt');
                break;
            case 'langChoiceLatin':
                Cookies.set('currentLocale','lat');
                break;
        }
        location.reload();
      });
    });
})(jQuery);
