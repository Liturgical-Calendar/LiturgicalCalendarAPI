const __ = function(key, locale = LITCAL_LOCALE)
{
    lcl = locale.toLowerCase();
    if (messages !== 'undefined' && messages !== null && typeof messages === 'object') {
        if (messages.hasOwnProperty(key)) {
            if (messages[key].hasOwnProperty(lcl)) {
                return messages[key][lcl];
            } else {
                return messages[key]["en"];
            }
        } else {
            return key;
        }
    } else {
        return key;
    }
}
