<?php

/**
 * Open Data Repository Data Publisher
 * ThemeElementToNumber Transformer
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Transforms a ThemeElement into an id number for a form, or vice versa.
 *
 */

namespace ODR\AdminBundle\Form\DataTransformer;

// ODR
use ODR\AdminBundle\Entity\ThemeElement;
// Doctrine
use Doctrine\Common\Persistence\ObjectManager;
// Symfony
use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;


class ThemeElementToNumberTransformer implements DataTransformerInterface
{

    private $om;

    /**
     * @param ObjectManager $om
     */
    public function __construct(ObjectManager $om)
    {
        $this->om = $om;
    }


    /**
     * Transforms a ThemeElement into an id
     *
     * @param ThemeElement|null $theme_element
     *
     * @return string
     */
    public function transform($theme_element)
    {
        if ($theme_element === null) {
            return "";
        }

        return $theme_element->getId();
    }


    /**
     * Transforms an id back into a ThemeElement
     *
     * @param string $number
     *
     * @return ThemeElement|null
     *
     * @throws TransformationFailedException if the ThemeElement is not found.
     */
    public function reverseTransform($number)
    {
        if (!$number) {
            return null;
        }

        $theme_element = $this->om->getRepository('ODRAdminBundle:ThemeElement')->find($number);
        if ($theme_element == null) {
            throw new TransformationFailedException(sprintf(
                'A ThemeElement with ID "%s" does not exist!',
                $number
            ));
        }

        return $theme_element;
    }
}
