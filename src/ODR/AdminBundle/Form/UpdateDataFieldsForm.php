<?php

/**
* Open Data Repository Data Publisher
* UpdateDataField Form
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
*/

namespace ODR\AdminBundle\Form;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

use Doctrine\ORM\EntityRepository;

class UpdateDatafieldsForm extends AbstractType
{
    protected $allowed_fieldtypes;
    public function __construct (array $allowed_fieldtypes) {
        $this->allowed_fieldtypes = $allowed_fieldtypes;
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $allowed_fieldtypes = $this->allowed_fieldtypes;
        $builder->add(
            'field_type',
            'entity',
            array(
                'class' => 'ODR\AdminBundle\Entity\FieldType',
                'query_builder' => function(EntityRepository $er) use ($allowed_fieldtypes) {
                    return $er->createQueryBuilder('ft')
                                ->where('ft.id IN (:types)')
                                ->setParameter('types', $allowed_fieldtypes);
                },
                'label' => 'Field Type',
                'property' => 'typeName',
                'expanded' => false,
                'multiple' => false,
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
            'data_type', 
            'entity', 
            array(
                'class' => 'ODR\AdminBundle\Entity\DataType', 
                'property' => 'id', 
                'label' => 'Data Type',
                'attr'=> array('style'=>'display:none'),
                'required' => true,
            )
        );

        $builder->add(
            'field_name', 
            'text', 
            array(
                'required' => true,
                'label'  => 'Field Name',
            )
        );

        $builder->add(
            'description', 
            'text', 
            array(
                'required' => true,
                'label'  => 'Description',
            )
        );

        $builder->add(
            'markdown_text',
            'textarea',
            array(
                'required' => true,
                'label' => 'Markdown Text',
            )
        );
/*
        $builder->add(
            'regex_validator', 
            'text', 
            array(
                'label'  => 'Regex Validator',
            )
        );

        $builder->add(
            'php_validator', 
            'text', 
            array(
                'label'  => 'PHP Validator',
            )
        );
*/

        $builder->add(
            'is_unique',
            'checkbox',
            array(
                'label'  => 'Unique',
                'required' => false
            )
        );

        $builder->add(
            'required', 
            'checkbox', 
            array(
                'label'  => 'Required',
                'required' => false
            )
        );

        $builder->add(
            'searchable', 
            'choice', 
            array(
                'choices' => array('0' => 'No', '1' => 'General Only', '2' => 'Advanced'),
                'label'  => 'Searchable',
                'expanded' => false,
                'multiple' => false,
                'empty_value' => false
            )
        );

        $builder->add(
            'user_only_search',
            'checkbox',
            array(
                'label'  => 'Only Searchable by Registered Users',
                'required' => false
            )
        );

        $builder->add(
            'allow_multiple_uploads',
            'checkbox',
            array(
                'label'  => 'Allow Multiple Uploads',
                'required' => false
            )
        );

        $builder->add(
            'shorten_filename',
            'checkbox',
            array(
                'label'  => 'Shorten Displayed Filename',
                'required' => false
            )
        );

        $builder->add(
            'radio_option_name_sort',
            'checkbox',
            array(
                'label'  => 'Sort Options Alphabetically',
                'required' => false
            )
        );

        $builder->add(
            'children_per_row',
            'choice',
            array(
                'choices' => array('1' => '1', '2' => '2', '3' => '3', '4' => '4', '5' => '5', '6' => '6', '8' => '8'),
                'label'  => '',
                'expanded' => false,
                'multiple' => false,
                'empty_value' => false
            )
        );

    }
    
    public function getName() {
        return 'DatafieldsForm';
    }

    /**
     * TODO: short description.
     * 
     * @param OptionsResolverInterface $resolver 
     * 
     * @return TODO
     */
    public function setDefaultOptions(OptionsResolverInterface $resolver)
    {
        $resolver->setDefaults(array('data_class' => 'ODR\AdminBundle\Entity\DataFields'));
    }


}
