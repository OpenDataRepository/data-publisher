<?php

/**
 * Open Data Repository Data Publisher
 * Unit Conversions Definition
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Since unit conversions might be useful in more than one place, it's better to have them off in
 * their own file...
 *
 */

namespace ODR\OpenRepository\GraphBundle\Plugins;

use ODR\AdminBundle\Exception\ODRBadRequestException;

class UnitConversionsDef
{

    // TODO - so these are the "most used" conversions...do I go through the effort to add "all" of the "standard" conversions?  e.g. gigameter

    /**
     * Holds the conversion factor between the "standard SI unit" and the various other units of a
     * particular conversion type.  This setup allows for converting from any unit to any other unit
     * in the same category in a maximum of two multiplications.
     *
     * @var array[]
     */
    public static $conversions = array(
        'Length' => array(
            // meters (SI unit) => m
            'km' => 1e3,  // kilometers
            'm' => 1.0,   // meters, standard SI unit
            'cm' => 1e-2, // centimeters
            'mm' => 1e-3, // millimeters
            'µm' => 1e-6, // micrometers
            'nm' => 1e-9, // nanometers

            // imperial units => m
            'mi' => 1609.344,     // miles
            'yd' => 0.9144,       // yards
            'ft' => 0.3048,       // feet
            'in' => 0.0254,       // inches

            // non-SI units => m
            'Å' => 1e-10,  // angstrom
        ),
        'Mass' => array(
            // grams (SI unit) => kg
            'kg' => 1.0,  // kilograms, standard SI unit
            'g' => 1e-3,  // grams
            'mg' => 1e-6, // milligrams
            'µg' => 1e-9, // microgram
            'ng' => 1e-12, // nanogram

            // non-SI units => kg
            'lb' => 4.5359237e-1,      // pounds
            'oz' => 4.5359237e-1 / 16, // ounces
        ),
        'Pressure' => array(
            // Pascals (SI unit) => Pa
            'GPa' => 1e9, // gigapascals
            'MPa' => 1e6, // megapascals
            'kPa' => 1e3, // kilopascals
            'hPa' => 1e2, // hectopascals
            'Pa' => 1.0,  // pascals, standard SI unit

            // bars (kinda SI unit) => Pa
            'Mbar' => 1e11, // megabars
            'kbar' => 1e8,  // kilobars
            'bar' => 1e5,   // bars
            'mbar' => 1e2,  // millibars

            // non-SI units => Pa
            'atm' => 101325,     // atmospheres
            'psi' => 6.894757e3, // pounds per square inch
        ),
        'Temperature' => array(
            // kelvin (SI unit)
            'K' => 1.0,

            // Celsius is kinda SI unit, apparently
            'C' => array(
                'to' => 'convertToC',
                'from' => 'convertFromC',
            ),

            // Fahrenheit is non-SI
            'F' => array(
                'to' => 'convertToF',
                'from' => 'convertFromF',
            ),
            // Rankine
            'R' => 5/9,
        ),
        'Time' => array(
            // seconds (SI unit) => s
            's' => 1.0,    // second, standard SI unit
            'ms' => 1e-3,  // millisecond
            'µs' => 1e-6,  // microsecond
            'ns' => 1e-9,  // nanosecond
            'ps' => 1e-12, // picosecond

            // non-SI units => s
            'm' => 60,        // minutes
            'h' => 3600,      // hours
            'd' => 86400,     // days
            'w' => 604800,    // week, assumed to be 7 days
            'yr' => 31556952, // (gregorian) year
        ),
    );


    /**
     * Because scientific data is written by people, there's no guarantee that the units used are
     * "standard"...so there needs to be an extensive lookup table to convert whatever could be read
     * into "standard" units...
     *
     * The caveat here is that the "official" names should be first...the UnitConversion plugin
     * needs something more than just the units to identify conversions by, and the least fragile
     * method to get that "something more" is to assume the first alias encountered is the "official"
     * one.
     *
     * The keys should be lowercase, so stupid capitalizations can be dealt with.
     *
     * @var array[]
     */
    public static $aliases = array(
        'Length' => array(
            // "Official" names first...
            'kilometers' => 'km',
            'meters' => 'm',
            'centimeters' => 'cm',
            'millimeters' => 'mm',
            'micrometers' => 'µm',
            'nanometers' => 'nm',

            'miles' => 'mi',
            'yards' => 'yd',
            'feet' => 'ft',
            'inches' => 'in',

            'angstroms' => 'Å',

            // "Unofficial" names after
            'kilometer' => 'km',
            'meter' => 'm',
            'centimeter' => 'cm',
            'millimeter' => 'mm',
            'micrometer' => 'µm',
            'nanometer' => 'nm',

            'kilometres' => 'km',
            'metres' => 'm',
            'centimetres' => 'cm',
            'millimetres' => 'mm',
            'micrometres' => 'µm',
            'nanometres' => 'nm',

            'kilometre' => 'km',
            'metre' => 'm',
            'centimetre' => 'cm',
            'millimetre' => 'mm',
            'micrometre' => 'µm',
            'nanometre' => 'nm',

            'mile' => 'mi',
            'yard' => 'yd',
            'foot' => 'ft',
            'inch' => 'in',

            'kms' => 'km',
            'microns' => 'µm',
            'um' => 'µm',
            'a' => 'Å',    // typically, the alternate unit used is "A"
        ),
        'Mass' => array(
            // "Official" names first...
            'kilograms' => 'kg',
            'grams' => 'g',
            'milligrams' => 'mg',
            'micrograms' => 'µg',
            'nanograms' => 'ng',

            'pounds' => 'lb',
            'ounces' => 'oz',

            // "Unofficial" names after
            'kilogram' => 'kg',
            'gram' => 'g',
            'milligram' => 'mg',
            'microgram' => 'µg',
            'nanogram' => 'ng',

            'ug' => 'µg',
            'mcg' => 'µg',

            'pound' => 'lb',
            'ounce' => 'oz',

            'lbs' => 'lb',
        ),
        'Pressure' => array(
            // "Official" names first...
            'gigapascals' => 'GPa',
            'megapascals' => 'MPa',
            'kilopascals' => 'kPa',
            'hectopascals' => 'hPa',
            'pascals' => 'Pa',

            'megabars' => 'Mbar',
            'kilobars' => 'kbar',
            'bars' => 'bar',
            'millibars' => 'mbar',

            'atmospheres' => 'atm',
            'pounds per square inch' => 'psi',

            // "Unofficial" names after...
            'kb' => 'kbar',
            'poundspersquareinch' => 'psi',   // need this one because spaces are stripped...
        ),
        'Temperature' => array(
            // "Official" names first...
            'kelvin' => 'K',
            'celsius' => 'C',
            'fahrenheit' => 'F',
            'rankine' => 'R',

            // "Unofficial" names after...
            // These are usually capitalized, but need to define them as lowercase to be sure
            '°k' => 'K',
            'deg k' => 'K',
            '°c' => 'C',
            'deg c' => 'C',
            '°f' => 'F',
            'deg f' => 'F',
            '°r' => 'R',
            'deg r' => 'R',

            // Need these because spaces are stripped...
            'degk' => 'K',
            'degc' => 'C',
            'degf' => 'F',
            'degr' => 'R',
        ),
        'Time' => array(
            // "Official" names first...
            'seconds' => 's',
            'milliseconds' => 'ms',
            'microseconds' => 'µs',
            'nanoseconds' => 'ns',
            'picoseconds' => 'ps',

            'minutes' => 'm',
            'hours' => 'h',
            'days' => 'd',
            'weeks' => 'w',
            'years' => 'yr',

            // "Unofficial" names after
            'sec' => 's',
            'us' => 'µs',

            'mins' => 'm',
            'hrs' => 'h',
            'wks' => 'w',
        ),
    );


    /**
     * Converts a value in Kelvin to a value in Celsius.
     * @param float $x
     * @return float
     */
    private static function convertToC($x) {
        return $x - 273.15;
    }


    /**
     * Converts a value in Celsius to a value in Kelvin.
     * @param float $x
     * @return float
     */
    private static function convertFromC($x) {
        return $x + 273.15;
    }


    /**
     * Converts a value in Kelvin to a value in Fahrenheit.
     * @param float $x
     * @return float
     */
    private static function convertToF($x) {
        return ($x - 273.15) * 9/5 + 32;
    }


    /**
     * Converts a value in Fahrenheit to a value in Kelvin.
     * @param float $x
     * @return float
     */
    private static function convertFromF($x) {
        return ($x - 32) * 5/9 + 273.15;
    }


    /**
     * Due to academics,
     *
     * @var string[]
     */
    public static $precision_types = array(
        'none',
        'precise', // do the calculation "correctly"..."100" has a precision of "1", because the zeros are ambiguous
        'greedy',  // treat "100" as having a precision of "3"
    );


    /**
     * This function extracts the various numerical "parts" that make up a value to be converted.
     * This is separate from self::performConversion() so it's easier to test.
     *
     * @param string $original_value The value that might be converted
     * @param string $conversion_type One of the top-level keys in self::$conversions..e.g. "Pressure" or "Temperature"
     * @param string $target_units One of the values in self::$conversions...e.g. "GPa" or "K"
     *
     * @return null|string|array If null, then no conversion can be performed. If a string, then no conversion needs to be done.  Otherwise, returns an array.
     */
    public static function explodeValue($original_value, $conversion_type, $target_units)
    {
        // If no value was given, then there's nothing to convert
        $regex_value = trim($original_value);
        if ( $regex_value === '' )
            return null;

        // Want to remove all whitespace from the string, so the regex is slightly easier to understand
        $regex_value = str_replace(array(" ", "\t", "\n", "\r", "\0", "\x0B"), '', $regex_value);
        // TODO - perform other replacements on the value to make things conform better...
        $regex_value = str_replace( array("·"), "⋅", $regex_value);    // replace U+00B7 with U+22C5

        // Need to split the given string apart...
        $source_value_str = $source_value = null;
        $first_exponent_str = $first_exponent = null;
        $tolerance_value_str = $tolerance_value = null;
        $second_exponent_str = $second_exponent = null;
        $source_units = null;

        // Due to wanting the original value to have units, and because existence of decimal places
        //  are important, we can't just use floatval()...
        $decimal = '\d*(?:\.\d*)?';                         // standard decimal number regex
        $exponent = '(?:e|E|x10|×10|⋅10|\*10)[\^\-\+]*\d+'; // exponent regex, matches a couple different varieties

        $pattern =  '/';
//        $pattern =  '/\(?';                         // optional open parens...for values with "global exponents" like "(12.3±5.0)×10-12"

        $pattern .= '(';                            // open the first capture group ($source_value)...
            $pattern .= '\-?'.$decimal;             // standard decimal number regex, with an optional minus sign
        $pattern .= ')';                            // close the first capture group

        $pattern .= '(';                            // open the second capture group ($first_exponent)...
            $pattern .= $exponent;                  // standard exponent regex
        $pattern .= ')?';                           // close the second capture group...exponents are optional

        $pattern .= '(';                            // open the third capture group ($tolerance_value)...
            $pattern .= '\('.$decimal.'\)';         // standard decimal regex wrapped with literal '(' and ')' characters
            $pattern .= '|';                        // or
            $pattern .= '(?:\+\/\-|±)'.$decimal;    // a couple different plus/minus variants followed by a decimal
        $pattern .= ')?';                           // close the third capture group...tolerances are optional

//        $pattern .= '\)?';                          // optional close parens, for values with "global exponents" like "(12.3±5.0)×10-12"

        $pattern .= '(';                            // open the fourth capture group ($second_exponent)...
            $pattern .= $exponent;                  // standard exponent regex
        $pattern .= ')?';                           // close the fourth capture group...exponents are optional

        $pattern .= '(.*)/';                        // capture the remaining characters, hopefully getting the source units


        // ----------------------------------------
        $matches = array();
        preg_match($pattern, $regex_value, $matches, PREG_UNMATCHED_AS_NULL);    // need to track whether each capture group matched anything or not
        // Extract the strings first, if they exist
        if ( !is_null($matches[1]) && $matches[1] !== '' ) {    // apparently the first capture group can return the empty string when just given text
            $source_value_str = $matches[1];

            // Positive decimals less than 1 look better with a zero out front...
            if ( $source_value_str[0] === '.' )
                $source_value_str = '0'.$source_value_str;
        }

        if ( !is_null($matches[2]) )
            $first_exponent_str = self::fixExponent($matches[2]);

        if ( !is_null($matches[3]) ) {
            $tmp = $matches[3];

            // The regex includes more than just the actual number for the tolerance...want to cut
            //  the extraneous characters out
            for ($i = 0; $i < strlen($tmp); $i++) {
                $char = $tmp[$i];
                if ( ($char >= '0' && $char <= '9') || $char === '.' )
                    $tolerance_value_str .= $char;
            }
            // If the final character is a decimal, the put another zero on the end for clarity
            if ( substr($tolerance_value_str, -1) === '.' )
                $tolerance_value_str .= '0';
        }

        if ( !is_null($matches[4]) )
            $second_exponent_str = self::fixExponent($matches[4]);

        if ( !is_null($matches[5]) )
            $source_units = $matches[5];


        // ----------------------------------------
        // If either the source value str or the source units str are null, then the conversion will
        //  never work
        if ( is_null($source_value_str) || is_null($source_units) )
            return null;

        // If the source units aren't already in the expected format...
        if ( !isset(self::$conversions[$conversion_type][$source_units]) ) {
            // ...then have to attempt to use the aliases to figure out what the user entered
            $lowercase_source_units = strtolower($source_units);

            $tmp = '';
            if ( isset(self::$aliases[$conversion_type][$source_units]) )
                $tmp = self::$aliases[$conversion_type][$source_units];
            else if ( isset(self::$aliases[$conversion_type][$lowercase_source_units]) )
                $tmp = self::$aliases[$conversion_type][$lowercase_source_units];
            else {
                // Unfortunately, there's also a possibility that the user did a non-standard
                //  capitalization of a conventional unit...e.g. a temperature of "100 c".

                // Easiest way to deal with this is to convert each official unit to lowercase, then
                //  check that against the lowercase version of the given units
                foreach (self::$conversions[$conversion_type] as $target_unit => $conversion_factor) {
                    if ( strtolower($target_unit) === $lowercase_source_units ) {
                        $tmp = $target_unit;
                        break;
                    }
                }

                // NOTE: this will break when dealing with "mega" (M) and "milli" (m) prefixes...
                //  ...but it's not really my fault that the user isn't storing their data correctly
                //  in the first place.
            }

            if ( $tmp !== '' ) {
                // If an alias was found, then use that
                $source_units = $tmp;
            }
            else {
                // Otherwise, this is an unknown alias...unable to continue converting
                return null;
            }
        }

        // If the source value is already in the correct units, then don't need to continue parsing
        //  the original value...
        if ( $source_units === $target_units )
            return $original_value;


        // ----------------------------------------
        // Otherwise, need to actually perform a conversion

        // Need to first get the source/tolerance values into floats, which is slightly tricky
        //  because the exponents aren't trivially tied to the source/tolerance values...
        if ( !is_null($first_exponent_str) && is_null($second_exponent_str) ) {
            // In this case, the first exponent always belongs to the source value
            $source_value_str .= 'e'.$first_exponent_str;

            // ...unless the user entered something like "123e4(5)", which is ambiguous
            if ( !is_null($tolerance_value_str) )
                return null;
        }
        else if ( !is_null($first_exponent_str) && !is_null($second_exponent_str) ) {
            // In this case, the source value and the tolerance value have their own individual
            //  exponents
            $source_value_str .= 'e'.$first_exponent_str;
            if ( !is_null($tolerance_value_str) )
                $tolerance_value_str .= 'e'.$second_exponent_str;
        }
        else if ( is_null($first_exponent_str) && !is_null($second_exponent_str) ) {
            // In this case, the exponent is understood to apply to both the source value and the
            //  tolerance value at the same time
            $source_value_str .= 'e'.$second_exponent_str;
            if ( !is_null($tolerance_value_str) )
                $tolerance_value_str .= 'e'.$second_exponent_str;
        }

        // Convert the resulting strings into floats so the math can do its thing
        $source_value = floatval($source_value_str);
        if ( !is_null($tolerance_value_str) ) {
            // If the tolerance value has a decimal point...
            if ( strpos($tolerance_value_str, '.') !== false ) {
                // ...then it's understood to be an "absolute" tolerance...
                //  e.g. 1.23(1.5) means 1.23±1.5, or a value between -0.27 and 2.73
                $tolerance_value = floatval($tolerance_value_str);
            }
            else {
                // ...otherwise, it's "relative" to the number of decimal places in the source value
                //  e.g. 1.23(15) means 1.23±0.15, or a value between 1.05 and 1.38
                $decimal_index = strpos($source_value_str, '.');
                if ( $decimal_index === false ) {
                    // ...but if the source value has no decimal, then it's relative to the ones place anyways
                    $tolerance_value = floatval($tolerance_value_str);
                }
                else {
                    // Need to determine how many decimal places the source value had...easier to do
                    //  that with the raw regex match
                    $decimal_places = 0;
                    for ($i = $decimal_index+1; $i < strlen($source_value_str); $i++) {
                        $char = $source_value_str[$i];
                        if ( $char >= '0' && $char <= '9' )
                            $decimal_places++;
                        else
                            break;
                    }
                    // NOTE - for a value like "12.(34)", the decimal point is superfluous as far as the tolerance is concerned
                    $tolerance_value = floatval($tolerance_value_str) * floatval('1e-'.$decimal_places);
                }
            }
        }

        return array(
            'source_value_str' => $source_value_str,
            'source_value' => $source_value,
            'tolerance_value_str' => $tolerance_value_str,
            'tolerance_value' => $tolerance_value,
            'source_units' => $source_units,
        );
    }


    /**
     * Easier to have the exponent stuff off in its own function...
     *
     * @param string $match
     * @return string
     */
    private static function fixExponent($match)
    {
        // Ensure the optional '^' character doesn't exist
        $tmp = strtolower( str_replace(array('^', '+'), '', $match) );

        // The regex includes more than just the actual number for the exponent...trim it down
        $str = '';
        if ( strpos($tmp, 'e') !== false ) {
            // The exponent was presented with either 'e' or 'E'
            $str = substr($tmp, 1);
        }
        else {
            // The exponent was presented with some variant of '*10'...
            $str = str_replace(array('x10', '×10', '⋅10', '*10'), '', $tmp);
        }

        // Strip leading zeros
        for ($i = 0; $i < strlen($str); $i++) {
            if ( $str[$i] !== '0' ) {
                $str = substr($str, $i);
                break;
            }
        }

        return $str;
    }


    /**
     * Attempts to convert the given value into a value with the given units.
     *
     * @param string $original_value The value that might be converted
     * @param string $conversion_type One of the top-level keys in self::$conversions..e.g. "Pressure" or "Temperature"
     * @param string $target_units One of the values in self::$conversions...e.g. "GPa" or "K"
     * @param string $precision_type {@link UnitConversionsDef::$precision_types}
     *
     * @return string
     */
    public static function performConversion($original_value, $conversion_type, $target_units, $precision_type = 'none')
    {
        // If missing the information to convert to, then there's nothing that can be done
        if ( $conversion_type === '' || $target_units === '' )
            return '';
        // Don't attempt to convert to something that's undefined...
        if ( !isset(self::$conversions[$conversion_type][$target_units]) )
            return '';


        // ----------------------------------------
        // Extract the usable information from the given string
        $ret = self::explodeValue($original_value, $conversion_type, $target_units);

        $source_value_str = $source_value = null;
        $tolerance_value_str = $tolerance_value = null;
        $source_units = null;

        if ( is_null($ret) ) {
            // If the return is null, then the given string can't be converted for some reason
            return '';
        }
        else if ( !is_array($ret) ) {
            // If the return is a string, then the given string doesn't need to be converted
            return $ret;

            // TODO - do normalization processing to the string?
        }
        else {
            // If the return is an array, then extract the data from it
            $source_value_str = $ret['source_value_str'];
            $source_value = $ret['source_value'];
            $tolerance_value_str = $ret['tolerance_value_str'];
            $tolerance_value = $ret['tolerance_value'];
            $source_units = $ret['source_units'];
        }


        // ----------------------------------------
        // Need to do the maths to convert from the source units to the "standard" unit...
        $tmp_value = '';
        if ( is_numeric(self::$conversions[$conversion_type][$source_units]) ) {
            // This conversion is described by a conversion factor
            $tmp_value = $source_value * self::$conversions[$conversion_type][$source_units];

            // Do the same to the tolerance value, if it exists
            if ( !is_null($tolerance_value) )
                $tolerance_value = $tolerance_value * self::$conversions[$conversion_type][$source_units];
        }
        else {
            // This conversion is described by a function
            $tmp_func = self::$conversions[$conversion_type][$source_units]['from'];
            $tmp_value = call_user_func(array(self::class, $tmp_func), $source_value);    // need to use the array() because the target function is static

            // Do the same to the tolerance value, if it exists
            if ( !is_null($tolerance_value) )
                $tolerance_value = call_user_func(array(self::class, $tmp_func), $tolerance_value);
        }

        // ...then convert the "standard" unit to the target unit
        if ( is_numeric(self::$conversions[$conversion_type][$target_units]) ) {
            // This conversion is described by a conversion factor
            $target_value = $tmp_value / self::$conversions[$conversion_type][$target_units];

            // Do the same to the tolerance value, if it exists
            if ( !is_null($tolerance_value) )
                $tolerance_value = $tolerance_value / self::$conversions[$conversion_type][$target_units];
        }
        else {
            // This conversion is described by a function
            $tmp_func = self::$conversions[$conversion_type][$target_units]['to'];
            $target_value = call_user_func(array(self::class, $tmp_func), $tmp_value);    // need to use the array() because the target function is static

            // Do the same to the tolerance value, if it exists
            if ( !is_null($tolerance_value) )
                $tolerance_value = call_user_func(array(self::class, $tmp_func), $tolerance_value);
        }

        // NOTE - due to effectively all of these conversions being a multiplication/division, we
        //  don't need to do fancy shit with the tolerance value...the "relative uncertainty" remains
        //  the same (in theory?)


        // ----------------------------------------
        // The converted value probably needs to be cleaned up a bit...both due to significant figures
        //  and due to the possibility of floating point bullshit
        if ( $precision_type !== 'none' ) {
            // Temperatures seem to typically be rounded/truncated based on the number of digits past
            //  the decimal point (e.g. 30C -> 303K, or 30.5C -> 303.6K)
            if ( $conversion_type === 'Temperature' ) {
                // Temperatures are typically treated as precise out to the units position...
                $precision = 0;

                // ...but if they have precision past the decimal point...
                $decimal = strpos($source_value, '.');
                if ( $decimal !== false ) {
                    // ...then they're typically rounded to the same number of decimal places as the
                    //  original value
                    $precision = strlen($source_value) - 1 - $decimal;
                }

                // Perform the actual rounding
                $target_value = round($target_value, $precision);
                if ( !is_null($tolerance_value) )
                    $tolerance_value = round($tolerance_value, $precision);
            }
            else {
                $source_value_precision = $tolerance_value_precision = null;
                if ( is_null($tolerance_value_str) ) {
                    // If a tolerance value does not exist, then determine the precision based on
                    //  what the plugin was configured to use
                    $source_value_precision = self::determinePrecision($source_value_str, $precision_type);
                }
                else {
                    // If a tolerance value exists, then all digits in the source value are significant
                    $source_value_precision = self::determinePrecision($source_value_str, 'greedy');
                    $tolerance_value_precision = self::determinePrecision($tolerance_value_str, 'greedy');
                }

                // TODO - so technically the number of significant digits isn't "gospel"...it's more about retaining the "relative uncertainty", as stated at the end of:
                // TODO - https://chem.libretexts.org/Bookshelves/General_Chemistry/Chem1_(Lower)/04%3A_The_Basics_of_Chemistry/4.06%3A_Significant_Figures_and_Rounding

                // TODO - to copy the argument, 9 inches only has 1 significant digit (implied to be roughly 6% uncertainty)...it converts to 22.86 cm
                // TODO - ...rounding to one sig fig (to make 20 cm) implies ~25% uncertainty.  rounding to two sig figs (to make 23 cm) implies ~2% uncertainty, and fits a little better
                // TODO - ...but that's a judgement call by definition.  bleh.

                // Ensure the converted values have the correct precision
                $target_value = self::applyPrecision($target_value, $source_value_precision);
                if ( !is_null($tolerance_value) )
                    $tolerance_value = self::applyPrecision($tolerance_value, $tolerance_value_precision);
            }
        }


        // ----------------------------------------
        // Now that the conversion is done, return the result
        if ( is_null($tolerance_value) )
            return $target_value.' '.$target_units;
        else
            return $target_value.'('.$tolerance_value.') '.$target_units;
    }


    /**
     * Iterates over a numerical string to determine how many digits of precision it has.
     *
     * @param string $source_value_str
     * @param string $precision_type {@link UnitConversionsDef::$precision_types}
     *
     * @return int
     */
    public static function determinePrecision($source_value_str, $precision_type)
    {
        // Ensure the precision type isn't stupid...
        if ( $precision_type === 'none' || !in_array($precision_type, self::$precision_types) )
            throw new ODRBadRequestException('Invalid precision_type of "'.$precision_type.'" given to UnitConversionsDef::determinePrecision()', 0xf93585b8);

        // Going to make one pass through the source value...
        $found_digit = $has_decimal = false;
        $digits = $trailing_zeros = 0;

        for ($i = 0; $i < strlen($source_value_str); $i++) {
            $char = $source_value_str[$i];
            if ( $char >= '1' && $char <= '9' ) {
                // Guard against leading zeros
                $found_digit = true;

                // Each one of these digits increases the precision, and also resets any trailing zeros
                $digits += 1 + $trailing_zeros;
                $trailing_zeros = 0;
            }
            else if ( $char === '0' ) {
                // Zeros are only significant when they're preceeded by a non-zero digit...
                if ( $found_digit ) {
                    // ...but can't just add them straight to $digits because it depends on the
                    //  $precision_type
                    $trailing_zeros++;
                }
            }
            else if ( $char === 'e' || $char === 'E' || $char === '+' || $char === '±' ) {
                // Now in the "exponent" part of the number, so done determining precision
                break;
            }
            else if ( $char === '.' ) {
                // Also need to keep track of whether a decimal existed
                $has_decimal = true;
            }
        }

        // The return value depends on the type of precision demanded...
        if ( $precision_type === 'greedy' || $has_decimal ) {
            // 'greedy' treats all digits, including trailing zeros, as significant
            // 'precise' also treats all digits as significant, but only when a decimal point exists
            return $digits + $trailing_zeros;
        }
        else /*if ( $precision_type === 'precise' )*/ {
            // 'precise' only treats digits as significant when a decimal point does not exist
            return $digits;
        }
    }


    /**
     * Modifies the given value to have the requested number of digits of precision.
     * TODO - round() or truncate()?
     *
     * @param float $target_value
     * @param int $desired_precision
     *
     * @return string
     */
    public static function applyPrecision($target_value, $desired_precision)
    {
        // Need to convert the float back into a string, due to potentially requiring arbitrary zeros
        $target_value_str = strval($target_value);
        $target_value_precision = self::determinePrecision($target_value_str, 'precise');

        $fixed_value_str = $target_value_str;
        if ( $target_value_precision > $desired_precision ) {
            // Most of the conversions are going to end up creating values that need to be rounded
            //  in order for significant digits to be correctly applied...
            $decimal_point = strpos($target_value_str, '.');
            if ( $decimal_point === false ) {
                // If the $target_value doesn't have a decimal point (e.g. "101325"), then pretend
                //  it's at the end so round() continues to work properly (e.g. "101325.")
                $decimal_point = strlen($target_value_str);
            }

            if ( $target_value >= 1.0 ) {
                // Using the built-in round() function is straightforward when the converted
                //  value is greater than 1...
                $new_target_value = round($target_value, ($desired_precision-$decimal_point));
                $fixed_value_str = strval($new_target_value);
            }
            else {
                // ...otherwise, need to offset the round() so it works properly

                // Check whether the target value has an exponent first...
                $exponent = strpos($fixed_value_str, 'E');
                if ($exponent !== false) {
                    $val = substr($fixed_value_str, $exponent+1);
                    $offset = intval($val);

                    // $decimal_point should always be 1 at this point
                    $new_target_value = round($target_value, ($desired_precision-$decimal_point-$offset));
                    $fixed_value_str = strval($new_target_value);

                    // If the number is small enough that PHP returns it in exponentiated
                    //  format, then it'll always appear to have at least 2 digits of
                    //  precision...
                    if ( strpos($fixed_value_str, 'E') !== false && $desired_precision === 1 ) {
                        // ...so if only one digit of precision is required, fix that
                        $fixed_value_str = $fixed_value_str[0] . substr($fixed_value_str, 3);
                    }
                }
                // TODO - do i need the other version of exponentiation?  php is only ever going to return with 'E'...
                else {
                    // If the target value is less than 1, but doesn't have an exponent, then
                    //  need to crawl through the string to find the first non-zero digit
                    $offset = 0;

                    // Start looking from the first character after the period
                    for ($i = 2; $i < strlen($target_value_str); $i++) {
                        $char = $target_value_str[$i];
                        if ( $char >= '1' && $char <= '9' )
                            break;
                        else
                            $offset++;
                    }

                    // $offset is now the correct value for round() to work properly
                    $new_target_value = round($target_value, ($desired_precision+$offset));
                    $fixed_value_str = strval($new_target_value);
                }
            }
        }


        // For the conversions which happen to end up creating results that are "less precise" than
        //  the input, or for the ones where round() happens to lose digits of precision...it's
        //  necessary to add more
        $fixed_value_precision = self::determinePrecision($fixed_value_str, 'precise');
        if ( $fixed_value_precision < $desired_precision) {
            // The adjustment needs to fall back to counting the characters in the result...
            $fixed_value_len = strlen($fixed_value_str);
            $decimal_pos = strpos($fixed_value_str, '.');
            if ( $decimal_pos !== false ) {
                // If the fixed value has a decimal point, then it shouldn't be part of the length
                $fixed_value_len -= 1;
                if ( $fixed_value_str[0] === '0' )
                    // If the fixed value is also between 0 and 1, then also do not count the leading zero
                    $fixed_value_len -= 1;
            }

            if ( $fixed_value_len <= $desired_precision ) {
                // The only way to make the "fixed value" more precise in this case is to add zeros
                //  after a decimal point...
                if ( $decimal_pos === false) {
                    // ...ensure a decimal point exists
                    $fixed_value_str .= '.';

                    // If adding a decimal point, then $fixed_value_precision should now equal the
                    //  number of digits before the decimal point
                    $fixed_value_precision = $fixed_value_len;
                }

                // Continue adding trailing zeros until the precision requirements are met
                while ($fixed_value_precision < $desired_precision) {
                    $fixed_value_str .= '0';
                    $fixed_value_precision++;
                }
            }
        }

        return $fixed_value_str;
    }
}
