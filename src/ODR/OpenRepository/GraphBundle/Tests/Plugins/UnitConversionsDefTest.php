<?php

/**
 * Open Data Repository Data Publisher
 * UnitConversionsDef Test
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Dealing with significant digits and uncertainties is hard.  Tests are needed.
 */

namespace ODR\OpenRepository\GraphBundle\Tests\Plugins;

// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
// Services
use ODR\OpenRepository\GraphBundle\Plugins\UnitConversionsDef;
// Symfony
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;


class UnitConversionsDefTest extends WebTestCase
{

    /**
     * @covers \ODR\OpenRepository\GraphBundle\Plugins\UnitConversionsDef::explodeValue
     * @dataProvider provideExplodeValues
     */
    public function testExplodeValue($original_value, $expected_source_value, $expected_source_float, $expected_tolerance_value, $expected_tolerance_float, $expected_units)
    {
        // The regex and the logic are the important parts of this function...the units don't really
        //  matter, but assume they're pressure-based units to further the testing
        $ret = UnitConversionsDef::explodeValue($original_value, 'Pressure', 'GPa');

        if ( is_null($expected_source_value) ) {
            // This test is expected to return null
            $this->assertNull($ret);
        }
        else if ( is_null($expected_units) ) {
            // This test is expected to claim the value needs no conversion
            $this->assertEquals( $original_value, $ret );
        }
        else {
            // This test is expected to claim the value needs conversion, and these are the relevant
            //  variables to use for this conversion
            $this->assertEquals( $expected_source_value, $ret['source_value_str'], 'source str' );
            $this->assertEqualsWithDelta( $expected_source_float, $ret['source_value'], 0.00001, 'source val' );
            $this->assertEquals( $expected_tolerance_value, $ret['tolerance_value_str'], 'tolerance str' );
            $this->assertEqualsWithDelta( $expected_tolerance_float, $ret['tolerance_value'], 0.00001, 'source val' );
            $this->assertEquals( $expected_units, $ret['source_units'], 'units' );
        }
    }


    /**
     * @return array
     */
    public function provideExplodeValues()
    {
        return [
            // ----------------------------------------
            // Expecting null returns because there's nothing to convert
            'empty string' => [ '', null, null, null, null, null ],
            'just a number' => [ '123', null, null,  null, null, null ],
            'just text' => [ 'abc', null, null, null, null, null ],
            'text followed by number' => [ 'abc 123', null, null, null, null, null ],
            'just decimal point' => [ '.', null, null, null, null, null ],
            // Expecting null because the units are unknown
            'number followed by text' => [ '123 abc', null, null, null, null, null ],
            'number followed by non-pressure unit' => [ '123 °C', null, null, null, null, null ],
            // Expecting null because of invalid inputs
            'no fractional exponent' => ['1e3.2 atm', null, null, null, null, null],
            'no exponent missing a number' => ['1e atm', null, null, null, null, null],
            'no exponent followed by a tolerance' => ['123e4(5) atm', null, null, null, null, null],

            'negative tolerance is invalid' => ['123(-4) atm', null, null, null, null, null ],
            'exponent before tolerance is ambiguous, apparently' => ['123e4(5) atm', null, null, null, null, null ],

            // Expecting a string because the units are the same
            'long form units' => [ '123 Gigapascals', '123 Gigapascals', null, null, null, null ],
            'long form units no space' => [ '123gigapascals', '123gigapascals', null, null, null, null ],
            'short form units' => [ '123 GPa', '123 GPa', null, null, null, null ],
            'short form units no space' => [ '123GPa', '123GPa', null, null, null, null ],
            'capitalization should not matter' => [ '123 GiGApAscALs', '123 GiGApAscALs', null, null, null, null ],
            'extra spaces technically should not matter' => [ '   123   Gigapascals   ', '   123   Gigapascals   ', null, null, null, null ],

            // Expecting an array, but no tolerances
            'zero' => ['0 atm', '0', 0.0, null, null, 'atm'],
            'also zero' => ['0.0 atm', '0.0', 0.0, null, null, 'atm'],
            'zero again' => ['0. atm', '0.', 0.0, null, null, 'atm'],
            'negative zero technically allowed' => ['-0 atm', '-0', 0.0, null, null, 'atm'],

            'exponent' => ['1e3 atm', '1e3', 1e3, null, null, 'atm'],
            'float with exponent' => ['1.2e3 atm', '1.2e3', 1.2e3, null, null, 'atm'],
            'positive float smaller than 1 with exponent' => ['0.2e3 atm', '0.2e3', 0.2e3, null, null, 'atm'],
            'also positive float smaller than 1 with exponent' => ['.2e3 atm', '0.2e3', 0.2e3, null, null, 'atm'],

            'negative exponents should work' => ['123e-4 atm', '123e-4', 123e-4, null, null, 'atm'],
            'explicit positive exponents should work' => ['123e+4 atm', '123e4', 123e4, null, null, 'atm'],
            'exponents with leading zeros should work' => ['123e04 atm', '123e4', 123e4, null, null, 'atm'],
            'exponents with lots of leading zeros should work' => ['123e00040 atm', '123e40', 123e40, null, null, 'atm'],

            'alternate exponents should work too (1)' => ['123*10^4 atm', '123e4', 123e4, null, null, 'atm'],
            'alternate exponents should work too (2)' => ['123⋅10^4 atm', '123e4', 123e4, null, null, 'atm'],
            'alternate exponents should work too (3)' => ['123×10^4 atm', '123e4', 123e4, null, null, 'atm'],
            'alternate exponents should work too (4)' => ['123x10^4 atm', '123e4', 123e4, null, null, 'atm'],

            'relative tolerance' => ['123(4) atm', '123', 123, '4', 4, 'atm'],
            'relative tolerance on decimal (1)' => ['12.3(4) atm', '12.3', 12.3, '4', 0.4, 'atm'],
            'relative tolerance on decimal (2)' => ['123.45(6) atm', '123.45', 123.45, '6', 0.06, 'atm'],
            'relative tolerance on decimal (3)' => ['123.45(67) atm', '123.45', 123.45, '67', 0.67, 'atm'],
            'relative tolerance on decimal (4)' => ['123.45(678) atm', '123.45', 123.45, '678', 6.78, 'atm'],
            'relative tolerance on decimal (5)' => ['123.(4) atm', '123.', 123.0, '4', 4, 'atm'],

            'absolute tolerance (1)' => ['123(4.0) atm', '123', 123, '4.0', 4.0, 'atm'],
            'absolute tolerance (2)' => ['123(4.) atm', '123', 123, '4.0', 4.0, 'atm'],
            'absolute tolerance (3)' => ['123(40.) atm', '123', 123, '40.0', 40.0, 'atm'],
            'absolute tolerance on decimal' => ['123.4(5.) atm', '123.4', 123.4, '5.0', 5.0, 'atm'],

            'exponents are supposed to be applied to both value and tolerance' => ['12.34(56)e-7 atm', '12.34e-7', 12.34e-7, '56e-7', 0.56e-7, 'atm'],
            'value and tolerance can have different exponents' => ['12e-3+/-1e2 atm', '12e-3', 12e-3, '1e2', 1e2, 'atm'],
        ];
    }


    /**
     * @covers \ODR\OpenRepository\GraphBundle\Plugins\UnitConversionsDef::determinePrecision
     * @dataProvider provideDeterminePrecisionValues
     */
    public function testDeterminePrecision($source_value_str, $precision_type, $expected_digits)
    {
        // expected exceptions have to be expected prior to the function that can thrown said exception
        if ( is_null($expected_digits) )
            $this->expectException(ODRBadRequestException::class);

        // This is the least important function to test, but might as well...
        $ret = UnitConversionsDef::determinePrecision($source_value_str, $precision_type);

        // The rest of the values are supposed to be equal
        $this->assertEquals( $expected_digits, $ret );
    }


    /**
     * @return array
     */
    public function provideDeterminePrecisionValues()
    {
        return [
            // ----------------------------------------
            // Expecting exceptions here
            'empty string' => [ '', '', null ],
            'precision type of none' => [ '', 'none', null ],

            // greedy...
            'greedy 100' => [ '100', 'greedy', 3 ],
            'greedy 120' => [ '120', 'greedy', 3 ],
            'greedy 123' => [ '123', 'greedy', 3 ],
            'greedy 100.0' => [ '100.0', 'greedy', 4 ],
            'greedy 100.00' => [ '100.00', 'greedy', 5 ],

            // precise...
            'precise 100' => [ '100', 'precise', 1 ],
            'precise 120' => [ '120', 'precise', 2 ],
            'precise 123' => [ '123', 'precise', 3 ],
            'precise 100.0' => [ '100.0', 'precise', 4 ],
            'precise 100.00' => [ '100.00', 'precise', 5 ],

            // fractional...the precision type doesn't matter here
            'greedy 0.01' => [ '0.01', 'greedy', 1 ],
            'precise 0.01' => [ '0.01', 'precise', 1 ],

            'precise 0.010' => [ '0.010', 'precise', 2 ],
            'precise 0.0100' => [ '0.0100', 'precise', 3 ],
        ];
    }


    /**
     * @covers \ODR\OpenRepository\GraphBundle\Plugins\UnitConversionsDef::applyPrecision
     * @dataProvider provideApplyPrecisionValues
     */
    public function testApplyPrecision($value, $desired_precision, $expected_value_str)
    {
        // This function forces a float back into a formatted string, so it's pretty important...
        $ret = UnitConversionsDef::applyPrecision($value, $desired_precision);

        $this->assertEquals( $expected_value_str, $ret );
    }


    /**
     * @return array
     */
    public function provideApplyPrecisionValues()
    {
        return [
            // ----------------------------------------
            // numbers greater than zero
            'greater than zero, precision of 1' => [ 123.45, 1, '100' ],
            'greater than zero, precision of 2' => [ 123.45, 2, '120' ],
            'greater than zero, precision of 3' => [ 123.45, 3, '123' ],
            'greater than zero, precision of 4' => [ 123.45, 4, '123.5' ],    // not 123.4 due to rounding
            'greater than zero, precision of 5' => [ 123.45, 5, '123.45' ],
            'greater than zero, precision of 6' => [ 123.45, 6, '123.450' ],

            // numbers less than zero
            'less than zero, precision of 1' => [ 0.12, 1, '0.1' ],
            'less than zero, precision of 2' => [ 0.12, 2, '0.12' ],
            'less than zero, precision of 3' => [ 0.12, 3, '0.120' ],
            'less than zero, precision of 4' => [ 0.12, 4, '0.1200' ],

            // ambiguous tests...
            'ambiguous, precision of 1' => [ 100, 1, '100' ],
            'ambiguous, precision of 2' => [ 100, 2, '100' ],
            'ambiguous, precision of 3' => [ 100, 3, '100.' ],    // TODO - should this instead return '100'?
            'ambiguous, precision of 4' => [ 100, 4, '100.0' ],
        ];
    }


    /**
     * @covers \ODR\OpenRepository\GraphBundle\Plugins\UnitConversionsDef::performConversion
     * @dataProvider provideConversionValues
     */
    public function testPerformConversion($original_value, $conversion_type, $target_units, $precision_type, $expected_value)
    {
        $this->markTestSkipped('Not fully implemented yet');

        // The regex and the logic are the important parts of this function...the units don't really
        //  matter, but assume they're pressure-based units to further the testing
        $ret = UnitConversionsDef::performConversion($original_value, $conversion_type, $target_units, $precision_type);

        $this->assertEquals( $expected_value, $ret );
    }


    /**
     * @return array
     */
    public function provideConversionValues()
    {
        return [
            // ----------------------------------------
            // Expecting null returns because there's nothing to convert
            'empty string' => [ '', '', '', '', '' ],
        ];
    }
}
