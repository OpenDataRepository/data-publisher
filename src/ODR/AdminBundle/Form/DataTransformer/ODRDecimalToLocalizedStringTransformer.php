<?php

/**
 * Open Data Repository Data Publisher
 * ODR DecimalToLocalizedStringTransformer
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Overrides Symfony's built-in NumberToLocalizedStringTransformer to run a regex pattern prior
 * to transforming the form's data into a number.
 */

namespace ODR\AdminBundle\Form\DataTransformer;

use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\DataTransformer\NumberToLocalizedStringTransformer;


class ODRDecimalToLocalizedStringTransformer extends NumberToLocalizedStringTransformer
{

    /**
     * {@inheritdoc}
     */
    public function __construct($scale = null, $grouping = false, $roundingMode = self::ROUND_HALF_UP)
    {
        parent::__construct($scale, $grouping, $roundingMode);
    }


    /**
     * Runs a validation regex on the given string value from the form before Symfony casts the it
     * to an integer as part of converting "view" data into "normalized" data.
     *
     * @see http://symfony.com/doc/2.8/form/data_transformers.html#about-model-and-view-transformers
     *
     * {@inheritdoc}
     */
    public function reverseTransform($value)
    {
        // The main goal is to prevent leading zeros, and values like "-0.00" from being saved

        // Regex matches zero, optionally followed by a decimal point then any sequence of digits
        // OR
        // an optional minus sign followed by a non-zero integer, optionally followed by a decimal point and any sequence of digits
        // OR
        // a minus sign followed by a zero and a decimal point, followed by any sequence of digits that has at least one non-zero digit
        if ( preg_match('/^0(\.[0-9]+)?$|^-?[1-9][0-9]*(\.[0-9]+)?$|^-0\.[0-9]*[1-9]+[0-9]*$/', $value) !== 1 )
            throw new TransformationFailedException();

        // If the given value is acceptable, just return the string itself...the Decimal entity will
        //  deal with actually storing it...
        return $value;
//        return parent::reverseTransform($value);
    }
}
