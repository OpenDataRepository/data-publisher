<?php

namespace ODR\AdminBundle\Form\DataTransformer;

use Symfony\Component\Form\Extension\Core\DataTransformer\DateTimeToStringTransformer;

/**
 *  Extends the internal Symfony DaetTimeToStringTransformer class so its transform() function doesn't
 *    return a non-sensical value when passed the standard mysql null for a datetime object.
 */
class ODRDateTimeToStringTransformer extends DateTimeToStringTransformer
{

    public function __construct($inputTimezone = null, $outputTimezone = null, $format = 'Y-m-d H:i:s')
    {
        parent::__construct($inputTimezone, $outputTimezone, $format);
    }

    public function transform($value)
    {
        if ($value == new \DateTime('0000-00-00 00:00:00'))
            return '';
        else
            return parent::transform($value);
    }

    public function reverseTransform($value)
    {
        return parent::reverseTransform($value);
    }
}
?>
