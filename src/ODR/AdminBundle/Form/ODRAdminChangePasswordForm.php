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
 *
 */

namespace ODR\AdminBundle\Form;

// Symfony Forms
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
// Symfony Form classes
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;


class ODRAdminChangePasswordForm extends AbstractType
{

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {

        $target_user_id = $options['target_user_id'];

        $builder->add(
            'user_id',
            HiddenType::class,
            array(
                'required' => true,
                'label'  => 'user_id',
                'data' => $target_user_id,
                'mapped' => false,
            )
        );

        $builder->add(
            'plainPassword',
            RepeatedType::class,
            array(
                'type' => PasswordType::class,
                'error_bubbling' => true,   // required for errors to show when $form->isValid() is called
                'first_options' => array('label' => 'Password'),
                'second_options' => array('label' => 'Confirm Password'),
                'invalid_message' => 'The password fields must match',
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
        return 'ODRAdminChangePasswordForm';
    }


    /**
     * Returns the prefix of the template block name for this type.
     *
     * The block prefixes default to the underscored short class name with
     * the "Type" suffix removed (e.g. "UserProfileType" => "user_profile").
     *
     * @return string The prefix of the template block name
     */
    public function getBlockPrefix()
    {
        return 'ODRAdminChangePasswordForm';
    }


    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array('target_user_id' => 0));

        $resolver->setRequired('target_user_id');
    }
}
