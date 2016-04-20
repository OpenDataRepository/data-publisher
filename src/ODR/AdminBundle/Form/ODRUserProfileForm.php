<?php

/**
* Open Data Repository Data Publisher
* ODRUserProfile Form
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* This form is used by both the user creation page and the
* profile edit page.
*/

namespace ODR\AdminBundle\Form;

use ODR\OpenRepository\UserBundle\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;

use Doctrine\ORM\EntityRepository;

class ODRUserProfileForm extends AbstractType
{

    /** @var User $target_user */
    private $target_user;


    /**
     * ODRUserProfileForm constructor.
     *
     * @param User $user
     */
    public function __construct(\ODR\OpenRepository\UserBundle\Entity\User $user)
    {
        $this->target_user = $user;
    }


    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {

        $builder->add(
            'user_id',
            'hidden',
            array(
                'required' => true,
                'label'  => 'user_id',
                'data' => $this->target_user->getId(),
                'mapped' => false,
            )
        );

        $builder->add(
            'email',
            'email',
            array(
                'required' => true,
                'label'  => 'Email',
            )
        );

        $builder->add(
            'plainPassword',
            'repeated',
            array(
                'type' => 'password',
                'error_bubbling' => true,   // required for errors to show on $form->bind()
                'first_options' => array('label' => 'Password', 'always_empty' => false),
                'second_options' => array('label' => 'Confirm Password', 'always_empty' => false),
                'invalid_message' => 'The password fields must match',
            )
        );

        $builder->add(
            'firstName',
            'text',
            array(
                'required' => false,
                'label'  => 'First Name',
            )
        );

        $builder->add(
            'lastName',
            'text',
            array(
                'required' => false,
                'label'  => 'Last Name',
            )
        );

        $builder->add(
            'institution',
            'text',
            array(
                'required' => false,
                'label'  => 'Institution',
            )
        );

        $builder->add(
            'position',
            'text',
            array(
                'required' => false,
                'label'  => 'Position',
            )
        );

        $builder->add(
            'phoneNumber',
            'text',
            array(
                'required' => false,
                'label'  => 'Phone Number',
            )
        );

    }


    /**
     * Returns the name of this type.
     *
     * @return string The name of this type
     */
    public function getName()
    {
        return 'ODRUserProfileForm';
    }
}
