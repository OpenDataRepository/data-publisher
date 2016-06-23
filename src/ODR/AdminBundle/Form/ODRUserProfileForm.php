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
 *
 */

namespace ODR\AdminBundle\Form;

// Symfony Forms
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
// Symfony Form classes
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;


class ODRUserProfileForm extends AbstractType
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
            'email',
            EmailType::class,
            array(
                'required' => true,
                'label'  => 'Email',
            )
        );

        if ($target_user_id == 0) {
            // Don't want a password form on a regular profile edit page...syfmony would attempt to overwrite the user's password with whatever is in that field if that was the case
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

        $builder->add(
            'firstName',
            TextType::class,
            array(
                'required' => false,
                'label'  => 'First Name',
            )
        );

        $builder->add(
            'lastName',
            TextType::class,
            array(
                'required' => false,
                'label'  => 'Last Name',
            )
        );

        $builder->add(
            'institution',
            TextType::class,
            array(
                'required' => false,
                'label'  => 'Institution',
            )
        );

        $builder->add(
            'position',
            TextType::class,
            array(
                'required' => false,
                'label'  => 'Position',
            )
        );

        $builder->add(
            'phoneNumber',
            TextType::class,
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
        return 'ODRUserProfileForm';
    }


    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array('target_user_id' => 0));
    }
}
