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

class UnitConversionsDef
{

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

            // bars (SI unit) => Pa
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

            // non-SI units
            'C' => array( // Celsius
                'to' => 'convertToC',
                'from' => 'convertFromC',
            ),
            'F' => array( // Fahrenheit
                'to' => 'convertToF',
                'from' => 'convertFromF',
            ),
            'R' => 5/9,   // Rankine
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

            'pounds' => 'lb',
            'ounces' => 'oz',

            // "Unofficial" names after
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

            // "Unofficial" names after
            'kb' => 'kbar',
        ),
        'Temperature' => array(
            // "Official" names first...
            'kelvin' => 'K',
            'celsius' => 'C',
            'fahrenheit' => 'F',
            'rankine' => 'R',

            // "Unofficial" names after...
            // These are usually capitalized, but need to do lowercase to be sure
            '°k' => 'K',
            'deg k' => 'K',
            '°c' => 'C',
            'deg c' => 'C',
            '°f' => 'F',
            'deg f' => 'F',
            '°r' => 'R',
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
     * Attempts to convert the given value into a value with the given units.
     *
     * @param string $original_value
     * @param string $conversion_type
     * @param string $target_units
     * @return string
     */
    public static function performConversion($original_value, $conversion_type, $target_units)
    {
        // If no value was given, then there's nothing to convert
        $original_value = trim($original_value);
        if ( $original_value === '' )
            return '';

        // If missing the information to convert to, then there's nothing that can be done
        if ( $conversion_type === '' || $target_units === '' )
            return '';
        // Don't attempt to convert to something that's undefined...
        if ( !isset(self::$conversions[$conversion_type][$target_units]) )
            return '';


        // ----------------------------------------
        // Attempt to determine the units of the original value
        $source_value = null;
        $tolerance_value = null;
        $source_units = null;

        // Due to wanting the original value to have units, we can't just use floatval()...
        $pattern = '/(\-?\d+(?:\.\d+)?(?:e\-?\d+)?)(\(\d+\))?\s*(.+)/';
        // An optional '-' character, followed by digits, followed by an optional decimal portion, followed by an optional (negative) exponent...
        // ...then an optional sequence of digits between open/close parenthesis...
        // ...then some number of spaces before matching anything remaining in the value
        $matches = array();
        preg_match($pattern, $original_value, $matches, PREG_UNMATCHED_AS_NULL);    // need to track whether the second capture group matched anything or not
        if ( !is_null($matches[1]) )
            $source_value = floatval( $matches[1] );
        if ( !is_null($matches[2]) )
            $tolerance_value = floatval( substr($matches[2], 1, -1) );  // cut out the parenthesis before converting to float
        if ( !is_null($matches[3]) )
            $source_units = trim( $matches[3] );

        // If the original value couldn't be converted into a number, then the conversion can't
        //  continue
        if ( is_null($source_value) )
            return '';
        // If the original value didn't have any units, then the conversion can't continue
        if ( is_null($source_units) )
            return '';


        // If the source units aren't already in the expected format...
        if ( !isset(self::$conversions[$conversion_type][$source_units]) ) {
            // Then attempt to use the aliases to ensure that the source units is in an expected form
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

                // NOTE: this will likely break when dealing with "mega" (M) and "milli" (m) prefixes
                //  ...but if it does break, that's the user's fault for not storing their data
                //  correctly in the first place.
            }

            if ( $tmp !== '' ) {
                // If an alias was found, then use that
                $source_units = $tmp;
            }
            else {
                // Otherwise, this is an unknown alias...unable to continue converting
                return '';
            }
        }

        // If the source value is already in the correct units, then don't need to convert...return
        //  a lightly modified version
        if ( $source_units === $target_units ) {
            if ( is_null($tolerance_value) )
                return $source_value.' '.$target_units;
            else
                return $source_value.'('.$tolerance_value.') '.$target_units;
        }


        // ----------------------------------------
        // Otherwise, need to the math to convert from the source units to the "standard" unit...
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


        // ----------------------------------------
        // Due to floating point bullshit, the converted value probably needs to be cleaned up a bit
        // TODO

        // Now that the conversion is done, return the result
        if ( is_null($tolerance_value) )
            return $target_value.' '.$target_units;
        else
            return $target_value.'('.$tolerance_value.') '.$target_units;
    }
}
