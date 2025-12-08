<?php

/**
 * Open Data Repository Data Publisher
 * UpdateTheme Form
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Builds the Form used for modifying Theme properties via
 * the right slideout in DisplayTemplate.
 *
 */

namespace ODR\AdminBundle\Form;

// Entities
use ODR\AdminBundle\Entity\ThemeMeta;
// Symfony Forms
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
// Symfony Form classes
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;


class UpdateThemeForm extends AbstractType
{

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $is_master_theme = $options['is_master_theme'];

        $builder->add(
            'templateName',
            TextType::class,
            array(
                'required' => true,
                'label' => 'Theme Name',
            )
        );

        $builder->add(
            'templateDescription',
            TextareaType::class,
            array(
                'required' => true,
                'label' => 'Theme Description',
            )
        );

        $builder->add(
            'defaultFor',
            HiddenType::class
        );

        $builder->add(
            'displayOrder',
            HiddenType::class
        );

        $builder->add(
            'shared',
            HiddenType::class
        );

        $builder->add(
            'sourceSyncVersion',
            HiddenType::class
        );

        $builder->add(
            'disableSearchSidebar',
            CheckboxType::class,
            array(
                'required' => true,
                'label' => 'Disable the search sidebar when in use'
            )
        );

        if ( !$is_master_theme ) {
            $builder->add(
                'themeVisibility',
                ChoiceType::class,
                array(
                    'choices' => array(
                        'Any' => ThemeMeta::ANY_CONTEXT,
                        'Search Results Only' => ThemeMeta::SHORT_CONTEXT,
                        'Display/Edit Only' => ThemeMeta::LONG_CONTEXT,
                    ),
                    'label'  => 'Allow Layout to be used for: ',
                    'expanded' => false,
                    'multiple' => false,
                    'placeholder' => false
                )
            );
        }

        $builder->add(
            'isTableTheme',
            CheckboxType::class,
            array(
                'required' => true,
                'label' => 'Render as Table when used for Search Results?'
            )
        );

        $builder->add(
            'displaysAllResults',
            CheckboxType::class,
            array(
                'required' => true,
                'label' => 'Render up to 10,000 records (Table layouts only, potentially slow)'
            )
        );

        $builder->add(
            'enableHorizontalScrolling',
            CheckboxType::class,
            array(
                'required' => true,
                'label' => 'Force Table layouts to scroll horizontally instead of responsively hiding columns'
            )
        );
    }


    /**
     * Returns the name of this type.
     *
     * @return string The name of this type
     */
    public function getName() {
        return 'UpdateThemeForm';
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
        return 'UpdateThemeForm';
    }


    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(
            array(
                'data_class' => 'ODR\AdminBundle\Entity\ThemeMeta'
            )
        );

        // Required options should not have defaults set
        $resolver->setRequired('is_master_theme');
    }
}
