<?php

/**
* Open Data Repository Data Publisher
* DataField Form
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
*/

namespace ODR\AdminBundle\Form;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;


class DatafieldsForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->add(
            'field_type',
            'entity', 
            array(
                'class' => 'ODR\AdminBundle\Entity\FieldType', 
                'property' => 'type_name', 
                'label' => 'Field Type',
            )
        );

        $builder->add(
            'render_plugin',
            'entity', 
            array(
                'class' => 'ODR\AdminBundle\Entity\RenderPlugin', 
                'property' => 'plugin_name', 
                'label' => 'Render Plugin',
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

        $builder->add(
            'is_unique',
            'checkbox',
            array(
                'label'  => 'Unique ?',
            )
        );

        $builder->add(
            'required', 
            'checkbox', 
            array(
                'label'  => 'Required ?',
            )
        );

        $builder->add(
            'data_type', 
            'hidden', 
            array(
                'required' => true,
            )
        );

        $builder->add(
            'searchable',
            'checkbox',
            array(
                'label'  => 'Searchable ?',
            )
        );

        $builder->add(
            'allow_multiple_uploads',
            'checkbox',
            array(
                'label'  => 'Allow Multiple Uploads ?',
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
