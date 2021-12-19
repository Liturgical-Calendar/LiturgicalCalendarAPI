<?php

if (!function_exists('pgettext')) {

    function pgettext($context, $msgid) {
       $contextString = "{$context}\004{$msgid}";
       $translation = dcgettext('litcal', $contextString, LC_MESSAGES);
       //$translation = _( $contextString );
       if ($translation == $contextString)  return $msgid;
       else  return $translation;
    }

}
