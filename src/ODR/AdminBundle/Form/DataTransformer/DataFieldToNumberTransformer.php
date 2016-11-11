<?php

/**
 * Open Data Repository Data Publisher
 * DataFieldToNumber Transformer
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Transforms a Datafield entity into an id number for a form, or vice versa.
 *
 */

namespace ODR\AdminBundle\Form\DataTransformer;

// ODR
use ODR\AdminBundle\Entity\DataFields;
// Doctrine
use Doctrine\Common\Persistence\ObjectManager;
// Symfony
use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;


class DataFieldToNumberTransformer implements DataTransformerInterface
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
     * Transforms a DataField entity into an id
     *
     * @param DataFields|null $datafield
     *
     * @return string
     */
    public function transform($datafield)
    {
        if ($datafield === null) {
            return "";
        }

        return $datafield->getId();
    }


    /**
     * Transforms an id back into a DataField entity
     *
     * @param string $number
     *
     * @return DataFields|null
     *
     * @throws TransformationFailedException if the DataField is not found.
     */
    public function reverseTransform($number)
    {
        if (!$number) {
            return null;
        }

        $datafield = $this->om->getRepository('ODRAdminBundle:DataFields')->find($number);
        if ($datafield == null) {
            throw new TransformationFailedException(sprintf(
                'A DataField with ID "%s" does not exist!',
                $number
            ));
        }

        return $datafield;
    }
}
