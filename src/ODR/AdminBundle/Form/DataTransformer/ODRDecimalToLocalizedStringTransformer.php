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

use ODR\AdminBundle\Component\Utility\ValidUtility;
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
     * Overrides the parent function so that the empty string is still considered valid for
     * transformation
     *
     * {@inheritDoc}
     */
    public function transform($value)
    {
        // The parent function throws an error when it receives the empty string, but apparently
        //  will happily convert <null> values into the empty string...
        $value = trim($value);
        if ( $value === '' )
            $value = null;

        return parent::transform($value);
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
        if ( !ValidUtility::isValidDecimal($value) )
            throw new TransformationFailedException();

        // If the given value is acceptable, just return the string itself...the Decimal entity will
        //  deal with actually storing it...
        return $value;
//        return parent::reverseTransform($value);
    }
}
