<?php

/**
* Open Data Repository Data Publisher
* ODRAdminChangePassword Form
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* This form allows a forcible change to some other user's password,
* since FoS (understandably) lacks this functionality.
*/

namespace ODR\AdminBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;

use Doctrine\ORM\EntityRepository;

class ODRAdminChangePasswordForm extends AbstractType
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
/*
        $builder->add(
            'email',
            'email',
            array(
                'required' => true,
                'label'  => 'Email',
            )
        );
*/
        $builder->add(
            'plainPassword',
            'repeated',
            array(
                'type' => 'password',
                'error_bubbling' => true,   // required for errors to show on $form->bind()
                'first_options' => array('label' => 'Password'),
                'second_options' => array('label' => 'Confirm Password'),
                'invalid_message' => 'The password fields must match',
            )
        );

    }

    public function getName()
    {
        return 'ODRAdminChangePasswordForm';
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
