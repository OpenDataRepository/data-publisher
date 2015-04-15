<?php

/**
* Open Data Repository Data Publisher
* DataType Form
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
*/

namespace ODR\AdminBundle\Form;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;


class DatatypeForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->add(
            'short_name', 
            'text', 
            array(
                'required' => true,
                'label'  => 'Short Name',
            )
        );

        $builder->add(
            'long_name', 
            'text', 
            array(
                'required' => true,
                'label'  => 'Full TypeName',
            )
        );

/*
        $builder->add(
            'description', 
            'text', 
            array(
                'required' => true,
                'label'  => 'Description',
            )
        );
*/
/*
        $builder->add(
            'multiple_records_per_parent',
            'checkbox',
            array(
                'required' => false,
                'label'  => 'Multiple Allowed?',
            )
        );
*/
/*
        $builder->add(
            'public_date',
            'checkbox',
            array(
                'required' => false,
                'label'  => 'Public?',
            )
        );
*/
    }
    
    public function getName() {
        return 'DatatypeForm';
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
        $resolver->setDefaults(array('data_class' => 'ODR\AdminBundle\Entity\DataType'));
    }


}
