<?php

/**
 * Open Data Repository Data Publisher
 * Reset Password Form
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The "set a new password" form used by the password-reset flow. Replaces FOSUserBundle's
 * ResettingFormType. Bound to the User entity's plainPassword (validated by User::isPasswordValid);
 * ODRUserManager hashes it on save.
 */

namespace ODR\OpenRepository\UserBundle\Form;

use ODR\OpenRepository\UserBundle\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;


class ResetPasswordForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->add('plainPassword', RepeatedType::class, [
            'type' => PasswordType::class,
            'options' => ['attr' => ['autocomplete' => 'new-password']],
            'first_options' => ['label' => 'New password'],
            'second_options' => ['label' => 'Repeat new password'],
            'invalid_message' => 'The password fields must match.',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            // the User's isPasswordValid callback handles password-strength validation
        ]);
    }
}
