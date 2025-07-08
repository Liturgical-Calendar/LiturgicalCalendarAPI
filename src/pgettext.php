<?php

if (!function_exists('pgettext')) {

    /**
     * Wrapper for dcgettext with a context
     *
     * @param string $context     context of the string
     * @param string $msgid       string to translate
     *
     * @return string             translated string
     */
    function pgettext(string $context, string $msgid)
    {
        $contextString = "{$context}\004{$msgid}";
        $translation   = dcgettext('litcal', $contextString, LC_MESSAGES);
        if ($translation == $contextString) {
            return $msgid;
        } else {
            return $translation;
        }
    }

}
