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
    // ----------------------------------------
    // For ODR's IntegerValue
    // This regex matches "0"
    // OR
    // an optional minus sign followed by a non-zero integer value
    const INTEGER_REGEX = "^0$|^-?[1-9][0-9]*$";

    // ----------------------------------------
    // For ODR's DecimalValue, the main goal is to match whatever filter_var() says is a valid float

    // Note that php's definition at https://www.php.net/manual/en/language.types.float.php
    //  does not match the output of floatval() and filter_var(), even on PHP 7.4.x
    // e.g. floatval("1e") -> 1  but filter_var("1e", FILTER_VALIDATE_FLOAT) -> false

    // This regex matches...
    // an optional '+' or '-' sign, followed by either...
    //  - a sequence of digits followed by an optional period and more optional digits
    // OR
    //  - an optional sequence of digits followed by a mandatory period and at least one digit
    // ...which can then be followed by another optional sequence...
    // - 'e' or 'E', followed by an optional '-' or '+', followed by at least one digit
    const DECIMAL_REGEX = "^[+-]?(?:[0-9]+\.?[0-9]*|[0-9]*\.[0-9]+)(?:[eE][+-]?[0-9]+)?$";

    /*
     * As a quick reference, there was an earlier decimal regex...
     * /^0(\.[0-9]+)?$|^-?[1-9][0-9]*(\.[0-9]+)?$|^-0\.[0-9]*[1-9]+[0-9]*$/
     * which matched...
     *  - zero, optionally followed by a decimal point and at least one digit
     * OR
     *  - an optional minus sign followed by a non-zero integer, optionally followed by a decimal point and any sequence of digits
     * OR
     *  - a minus sign followed by a zero and a decimal point, followed by any sequence of digits that has at least one non-zero digit
     *
     * That older regex couldn't match exponents, and was overly strict on allowed leading zeros.
     */


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

        // If the given string does not match this regex, then it can't be a valid integer value
        if ( preg_match('/'.self::INTEGER_REGEX.'/', $value) !== 1 )
            return false;

        // Doctrine (and therefore Mysql) use 4 bytes to store values for IntegerValue fields, so
        //  potential values that require more than 4 bytes to store are invalid...
        $val = intval($value);
        if ( $val > 2147483647 || $val < -2147483648 )
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

        // If the given string does not match this regex, then it can't be a valid decimal value
        if ( preg_match('/'.self::DECIMAL_REGEX.'/', $value) !== 1 )
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


    /**
     * Returns whether the collection of options is valid for the given datafield.
     *
     * @param array $df_array
     * @param array $options
     *
     * @return bool
     */
    static public function areValidRadioOptions($df_array, $options)
    {
        // Single select/radio are allowed to have at most one selection
        $typename = $df_array['dataFieldMeta']['fieldType']['typeName'];
        if ($typename === 'Single Select' || $typename === 'Single Radio') {
            if ( count($options) > 1 )
                return false;
        }

        // Convert the available options into a different format to make them easier to search
        $available_options = array();
        foreach ($df_array['radioOptions'] as $num => $ro)
            $available_options[ $ro['id'] ] = 0;

        foreach ($options as $ro_id => $num) {
            // MassEdit can provide an id of "none", indicating that it wants to deselect everything in the field
            if ( $ro_id === 'none' )
                continue;

            // Otherwise, the option has to belong to the datafield for it to be valid
            if ( !isset($available_options[$ro_id]) )
                return false;
        }

        // Otherwise, no errors
        return true;
    }


    /**
     * Returns whether the collection of tags is valid for the given datafield.
     *
     * @param array $df_array
     * @param array $tags
     *
     * @return bool
     */
    static public function areValidTags($df_array, $tags)
    {
        // Tags allow any number of selections by default

        // Unfortunately the tags are stored in stacked format, so need to flatten them
        $available_tags = array();
        self::getAvailableTags($df_array['tags'], $available_tags);

        foreach ($tags as $tag_id => $num) {
            // The tag has to belong to the datafield for it to be valid
            if ( !isset($available_tags[$tag_id]) )
                return false;
        }

        // Otherwise, no errors
        return true;
    }


    /**
     * Flattens a stacked tag hierarchy for {@link self::areValidTags()}
     *
     * @param array $tag_array
     * @param array $available_tags
     */
    static private function getAvailableTags($tag_array, &$available_tags)
    {
        foreach ($tag_array as $tag_id => $tag) {
            $available_tags[$tag_id] = 0;

            if ( isset($tag['children']) )
                self::getAvailableTags($tag['children'], $available_tags);
        }
    }


    /**
     * If the given $str describes a valid quality JSON object/array, then returns the decoded array.
     * If not, then return an error string instead.
     *
     * Either a single-level object or array with numeric keys is considered valid.  e.g.
     *  {"-1":"ignore","0":"unrated","1":"poor","2":"fair","3":"excellent"}
     *  ["F","D","C","B","A","S"]
     *
     * @param string $str
     * @return string|array
     */
    static public function isValidQualityJSON($str)
    {
        $quality_json_error = '';
        try {
            $quality_json = json_decode($str, true, 2, JSON_THROW_ON_ERROR);
            if ( is_array($quality_json) && count($quality_json) > 1 ) {
                // If it's an array, then it's probably valid, but also want to ensure the keys are
                //  numeric first
                foreach ($quality_json as $key => $value) {
                    if ( !is_int($key) ) {
                        $quality_json_error = 'Provided JSON does not have numeric keys';
                        break;
                    }
                }
            }
            else {
                $quality_json_error = 'Provided JSON does not have at least 2 entries';
            }

            if ( $quality_json_error === '' )
                return $quality_json;
        }
        catch (\Exception $e) {
            // Disappear the JSON parse exception
            if ( $e->getMessage() === 'Maximum stack depth exceeded' )
                $quality_json_error = 'Provided JSON must not have have multiple levels';
            else
                $quality_json_error = $e->getMessage();
        }

        return $quality_json_error;
    }
}
