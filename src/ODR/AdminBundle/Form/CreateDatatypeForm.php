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
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;


class CreateDatatypeForm extends AbstractType
{

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->add(
            'shortName', 
            'text', 
            array(
                'required' => true,
                'label'  => 'Short Name',
            )
        );

        $builder->add(
            'longName', 
            'text', 
            array(
                'required' => true,
                'label'  => 'Full TypeName',
            )
        );

        $builder->add('save', SubmitType::class);

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
    }


    /**
     * Returns the name of this type.
     *
     * @return string The name of this type
     */
    public function getName() {
        return 'CreateDatatypeForm';
    }


    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
//        $resolver->setDefaults(array('data_class' => 'ODR\AdminBundle\Entity\DataType'));
        $resolver->setDefaults(array('data_class' => 'ODR\AdminBundle\Entity\DataTypeMeta'));
    }


}
