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

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;

use Doctrine\ORM\EntityRepository;

class ODRUserProfileForm extends AbstractType
{

    private $target_user;

    public function __construct(\ODR\OpenRepository\UserBundle\Entity\User $user)
    {
        $this->target_user = $user;
    }

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

    public function getName()
    {
        return 'ODRUserProfileForm';
    }

        /**
     * TODO: short description.
     * 
     * @param OptionsResolverInterface $resolver 
     * 
     * @return TODO
     */
//    public function setDefaultOptions(OptionsResolverInterface $resolver)
//    {
//        $resolver->setDefaults(array('data_class' => 'ODR\AdminBundle\Entity\DataFields'));
//    }

}

?>
