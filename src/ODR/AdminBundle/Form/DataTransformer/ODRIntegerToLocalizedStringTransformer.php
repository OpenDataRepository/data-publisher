<?php

/**
 * Open Data Repository Data Publisher
 * ODR IntegerToLocalizedStringTransformer
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Overrides Symfony's built-in IntegerToLocalizedStringTransformer to run a regex pattern prior
 * to transforming the form's data into an integer.
 */

namespace ODR\AdminBundle\Form\DataTransformer;

use ODR\AdminBundle\Component\Utility\ValidUtility;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\DataTransformer\IntegerToLocalizedStringTransformer;


class ODRIntegerToLocalizedStringTransformer extends IntegerToLocalizedStringTransformer
{

    /**
     * {@inheritdoc}
     */
    public function __construct($scale = 0, $grouping = false, $roundingMode = self::ROUND_DOWN)
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
        if ( !ValidUtility::isValidInteger($value) )
            throw new TransformationFailedException();

        // If the given value is acceptable, let the parent class do its thing
        return parent::reverseTransform($value);
    }
}
