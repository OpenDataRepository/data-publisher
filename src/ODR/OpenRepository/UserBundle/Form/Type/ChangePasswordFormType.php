<?php

/**
* Open Data Repository Data Publisher
* ChangePassword Form (override)
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* Overrides FoS default change password form by removing the
* need for the current user to enter their current password.
*
*/


namespace ODR\OpenRepository\UserBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;

class ChangePasswordFormType extends AbstractType
{

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        // Don't require user or admin to enter the current password
        $builder->remove('current_password');
    }

    public function getParent()
    {
        return 'fos_user_change_password';
    }

    public function getName()
    {
        return 'odr_user_change_password';
    }
}
