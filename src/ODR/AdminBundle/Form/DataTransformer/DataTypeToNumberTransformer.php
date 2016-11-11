<?php

/**
 * Open Data Repository Data Publisher
 * DataTypeToNumber Transformer
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Transforms a Datatype entity into an id number for a form, or vice versa.
 *
 */

namespace ODR\AdminBundle\Form\DataTransformer;

// ODR
use ODR\AdminBundle\Entity\DataType;
// Doctrine
use Doctrine\Common\Persistence\ObjectManager;
// Symfony
use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;


class DataTypeToNumberTransformer implements DataTransformerInterface
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
     * Transforms a Datatype entity into an id
     *
     * @param DataType|null $datatype
     *
     * @return string
     */
    public function transform($datatype)
    {
        if ($datatype === null) {
            return "";
        }

        return $datatype->getId();
    }


    /**
     * Transforms an id back into a Datatype entity
     *
     * @param string $number
     *
     * @return DataType|null
     *
     * @throws TransformationFailedException if the Datatype is not found.
     */
    public function reverseTransform($number)
    {
        if (!$number) {
            return null;
        }

        $datatype = $this->om->getRepository('ODRAdminBundle:DataType')->find($number);
        if ($datatype == null) {
            throw new TransformationFailedException(sprintf(
                'A Datatype with ID "%s" does not exist!',
                $number
            ));
        }

        return $datatype;
    }
}
