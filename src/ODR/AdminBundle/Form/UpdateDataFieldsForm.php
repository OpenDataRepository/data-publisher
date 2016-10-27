<?php

/**
 * Open Data Repository Data Publisher
 * UpdateDataField Form
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Builds the form used for modifying Datafield properties via
 * the right slideout in DisplayTemplate
 *
 */

namespace ODR\AdminBundle\Form;

// Symfony Forms
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
// Symfony Form classes
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
//
use Doctrine\ORM\EntityRepository;


class UpdateDataFieldsForm extends AbstractType
{

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $allowed_fieldtypes = array_values($options['allowed_fieldtypes']);

        $builder->add(
            'field_type',
            EntityType::class,
            array(
                'class' => 'ODR\AdminBundle\Entity\FieldType',

                'query_builder' => function(EntityRepository $er) use ($allowed_fieldtypes) {
                    return $er->createQueryBuilder('ft')
                                ->where('ft.id IN (:types)')
                                ->setParameter('types', $allowed_fieldtypes);
                },

                'label' => 'Field Type',
                'choice_label' => 'typeName',
                'expanded' => false,
                'multiple' => false,
                'placeholder' => false,
            )
        );
/*
        $builder->add(
            'render_plugin',
            'entity',
            array(
                'class' => 'ODR\AdminBundle\Entity\RenderPlugin',
                'query_builder' => function(EntityRepository $er) {
                    return $er->createQueryBuilder('rp')
                                ->where('rp.plugin_type >= 2');
                },

                'property' => 'pluginName',
                'label' => 'Render Plugin',
                'expanded' => false,
                'multiple' => false,
            )
        );
*/

        $builder->add(
            'field_name', 
            TextType::class,
            array(
                'required' => true,
                'label'  => 'Field Name',
            )
        );

        $builder->add(
            'description', 
            TextType::class,
            array(
                'required' => true,
                'label'  => 'Description',
            )
        );

        $builder->add(
            'markdown_text',
            TextareaType::class,
            array(
                'required' => true,
                'label' => 'Markdown Text',
            )
        );
/*
        $builder->add(
            'regex_validator', 
            TextType::class,
            array(
                'label'  => 'Regex Validator',
            )
        );

        $builder->add(
            'php_validator', 
            TextType::class,
            array(
                'label'  => 'PHP Validator',
            )
        );
*/

        $builder->add(
            'is_unique',
            CheckboxType::class,
            array(
                'label'  => 'Unique',
                'required' => false
            )
        );

        $builder->add(
            'required', 
            CheckboxType::class,
            array(
                'label'  => 'Required',
                'required' => false
            )
        );

        $builder->add(
            'searchable', 
            ChoiceType::class,
            array(
                'choices' => array(
                    'No' => 0,
                    'General Only' => 1,
                    'Advanced' => 2,
                    'Advanced Only' => 3,
                ),
                'choices_as_values' => true,
                'label'  => 'Searchable',
                'expanded' => false,
                'multiple' => false,
                'placeholder' => false
            )
        );

        $builder->add(
            'user_only_search',
            CheckboxType::class,
            array(
                'label'  => 'Only Searchable by Registered Users',
                'required' => false
            )
        );

        $builder->add(
            'allow_multiple_uploads',
            CheckboxType::class,
            array(
                'label'  => 'Allow Multiple Uploads',
                'required' => false
            )
        );

        $builder->add(
            'shorten_filename',
            CheckboxType::class,
            array(
                'label'  => 'Shorten Displayed Filename',
                'required' => false
            )
        );

        $builder->add(
            'radio_option_name_sort',
            CheckboxType::class,
            array(
                'label'  => 'Sort Options Alphabetically',
                'required' => false
            )
        );
        $builder->add(
            'radio_option_display_unselected',
            CheckboxType::class,
            array(
                'label'  => 'Display Unselected Options',
                'required' => false
            )
        );

        $builder->add(
            'children_per_row',
            ChoiceType::class,
            array(
                'choices' => array(
                    '1' => '1',
                    '2' => '2',
                    '3' => '3',
                    '4' => '4',
                    '5' => '5',
                    '6' => '6',
                    '8' => '8'
                ),
                'choices_as_values' => true,
                'label'  => '',     // label set in Displaytemplate::datafield_properties_form.html.twig
                'expanded' => false,
                'multiple' => false,
                'placeholder' => false
            )
        );

    }


    /**
     * Returns the name of this type.
     *
     * @return string The name of this type
     */
    public function getName() {
        return 'DatafieldsForm';
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
        return 'DatafieldsForm';
    }


    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'ODR\AdminBundle\Entity\DataFieldsMeta',
            'allowed_fieldtypes' => null,
        ));

        $resolver->setRequired('allowed_fieldtypes');
    }
}
