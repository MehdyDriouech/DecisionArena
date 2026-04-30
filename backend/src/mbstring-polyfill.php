<?php
/**
 * Fallbacks when ext-mbstring is disabled (e.g. Apache PHP uses a different php.ini than CLI).
 * Native functions are used when the extension is loaded — these are skipped via function_exists.
 */
if (!function_exists('mb_strlen')) {
    function mb_strlen($string, $encoding = null): int {
        return strlen((string) $string);
    }
}

if (!function_exists('mb_substr')) {
    function mb_substr($string, $start, $length = null, $encoding = null): string {
        $string = (string) $string;
        if ($length === null) {
            return substr($string, $start);
        }
        return substr($string, (int) $start, (int) $length);
    }
}

if (!function_exists('mb_strtolower')) {
    function mb_strtolower($string, $encoding = null): string {
        return strtolower((string) $string);
    }
}

if (!function_exists('mb_strtoupper')) {
    function mb_strtoupper($string, $encoding = null): string {
        return strtoupper((string) $string);
    }
}

if (!function_exists('mb_stripos')) {
    function mb_stripos($haystack, $needle, $offset = 0, $encoding = null) {
        return stripos((string) $haystack, (string) $needle, (int) $offset);
    }
}

if (!function_exists('mb_strpos')) {
    function mb_strpos($haystack, $needle, $offset = 0, $encoding = null) {
        return strpos((string) $haystack, (string) $needle, (int) $offset);
    }
}
