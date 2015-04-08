<?php 

// src/ODR/AdminBundle/Form/DataTransformer/UserToNumberTransformer.php
namespace ODR\AdminBundle\Form\DataTransformer;

use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Doctrine\Common\Persistence\ObjectManager;
use ODR\OpenRepository\UserBundle\Entity\User;

/**
 * TODO: short description.
 * 
 * TODO: long description.
 * 
 */
class UserToNumberTransformer implements DataTransformerInterface
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
     * Transforms an object (user) to a string (number).
     *
     * @param  User|null $user
     * @return string
     */
    public function transform($user)
    {
        if (null === $user) {
            return "";
        }

        return $user->getId();
    }

    /**
     * Transforms a string (number) to an object (user).
     *
     * @param  string $number
     *
     * @return User|null
     *
     * @throws TransformationFailedException if object (user) is not found.
     */
    public function reverseTransform($number)
    {
        if (!$number) {
            return null;
        }

        $user = $this->om
            ->getRepository('ODROpenRepositoryUserBundle:User')
            ->find($number)
        ;

        if (null === $user) {
            throw new TransformationFailedException(sprintf(
                'A user record with ID "%s" does not exist!',
                $number
            ));
        }

        return $user;
    }
}
