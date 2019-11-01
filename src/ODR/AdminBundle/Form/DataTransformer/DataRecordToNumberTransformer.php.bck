<?php 

// src/ODR/AdminBundle/Form/DataTransformer/DataRecordToNumberTransformer.php
namespace ODR\AdminBundle\Form\DataTransformer;

use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Doctrine\Common\Persistence\ObjectManager;
use ODR\TaskBundle\Entity\DataRecord;

/**
 * TODO: short description.
 * 
 * TODO: long description.
 * 
 */
class DataRecordToNumberTransformer implements DataTransformerInterface
{
    /**
     * @var ObjectManager
     */
    private $om;

    /**
     * @param ObjectManager $om
     */
    public function __construct(ObjectManager $om)
    {
        $this->om = $om;
    }

    /**
     * Transforms an object (datarecord) to a string (number).
     *
     * @param  DataRecord|null $datarecord
     * @return string
     */
    public function transform($datarecord)
    {
        if (null === $datarecord) {
            return "";
        }

        return $datarecord->getId();
    }

    /**
     * Transforms a string (number) to an object (datarecord).
     *
     * @param  string $number
     *
     * @return DataRecord|null
     *
     * @throws TransformationFailedException if object (datarecord) is not found.
     */
    public function reverseTransform($number)
    {
        if (!$number) {
            return null;
        }

        $datarecord = $this->om
            ->getRepository('ODRAdminBundle:DataRecord')
            ->find($number)
        ;

        if (null === $datarecord) {
            throw new TransformationFailedException(sprintf(
                'An data record with ID "%s" does not exist!',
                $number
            ));
        }

        return $datarecord;
    }
}
