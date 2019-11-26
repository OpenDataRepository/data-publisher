<?php

/**
 * Open Data Repository Data Publisher
 * Valid Utility
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Centrally stores functions to determine whether a given value is valid for a given typeclass.
 */

namespace ODR\AdminBundle\Component\Utility;


class ValidUtility
{

    /**
     * Returns whether the given value is a valid boolean.
     *
     * @param mixed $value
     *
     * @return bool
     */
    static public function isValidBoolean($value)
    {
        // These values are valid for boolean fieldtypes
        if ( $value === 1 || $value === '1' || $value === true )
            return true;
        if ( $value === 0 || $value === '0' || $value === false )
            return true;

        // Anything else is not
        return false;
    }


    /**
     * Returns whether the given string describes a valid integer value.
     *
     * @param string $value
     *
     * @return bool
     */
    static public function isValidInteger($value)
    {
        // Empty string and null are valid integer values
        if ( is_null($value) || $value === '' )
            return true;

        // Regex matches "0"
        // OR
        // an optional minus sign followed by a non-zero integer value
        if ( preg_match('/^0$|^-?[1-9][0-9]*$/', $value) !== 1 )
            return false;

        // Otherwise, no problems
        return true;
    }


    /**
     * Returns whether the given string describes a valid decimal value.
     *
     * @param string $value
     *
     * @return bool
     */
    static public function isValidDecimal($value)
    {
        // Empty string and null are valid decimal values
        if ( is_null($value) || $value === '' )
            return true;

        // The main goal is to prevent leading zeros, and values like "-0.00" from being saved

        // Regex matches zero, optionally followed by a decimal point then any sequence of digits
        // OR
        // an optional minus sign followed by a non-zero integer, optionally followed by a decimal point and any sequence of digits
        // OR
        // a minus sign followed by a zero and a decimal point, followed by any sequence of digits that has at least one non-zero digit
        if ( preg_match('/^0(\.[0-9]+)?$|^-?[1-9][0-9]*(\.[0-9]+)?$|^-0\.[0-9]*[1-9]+[0-9]*$/', $value) !== 1 )
            return false;

        // Otherwise, no problems
        return true;
    }


    /**
     * Returns whether the given string is a valid ShortVarchar value.
     *
     * @param string $value
     *
     * @return bool
     */
    static public function isValidShortVarchar($value)
    {
        if ( strlen($value) > 32 )
            return false;

        // Otherwise, no problems
        return true;
    }


    /**
     * Returns whether the given string is a valid MediumVarchar value.
     *
     * @param string $value
     *
     * @return bool
     */
    static public function isValidMediumVarchar($value)
    {
        if ( strlen($value) > 64 )
            return false;

        // Otherwise, no problems
        return true;
    }


    /**
     * Returns whether the given string is a valid LongVarchar value.
     *
     * @param string $value
     *
     * @return bool
     */
    static public function isValidLongVarchar($value)
    {
        if ( strlen($value) > 255 )
            return false;

        // Otherwise, no problems
        return true;
    }


    /**
     * Returns whether the given string is a valid Datetime value for the given format.
     *
     * @param string $value
     * @param string $format
     *
     * @return bool
     */
    static public function isValidDatetime($value, $format = 'Y-m-d')
    {
        $date = \DateTime::createFromFormat($format, $value);
        if ( $date && $date->format($format) === $value )
            return true;

        return false;
    }
}
