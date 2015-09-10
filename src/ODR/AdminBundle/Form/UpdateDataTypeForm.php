<?php

/**
* Open Data Repository Data Publisher
* UpdateDataType Form
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* Builds the Form used for modifying Datatype properties via
* the right slideout in DisplayTemplate.
*/

//ODR/AdminBundle/Forms/UpdateDataTypeForm.class.php
namespace ODR\AdminBundle\Form;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

use Doctrine\ORM\EntityRepository;

class UpdateDataTypeForm extends AbstractType
{

    protected $datatype;
    public function __construct (\ODR\AdminBundle\Entity\DataType $datatype) {
        $this->datatype = $datatype;
    }


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
                'label'  => 'Long Name',
            )
        );

        $builder->add(
            'description', 
            'textarea', 
            array(
                'required' => true,
                'label'  => 'Description',
            )
        );

        $datatype = $this->datatype;
        $builder->add(
            'externalIdField',
            'entity',
            array(
                'class' => 'ODR\AdminBundle\Entity\DataFields',
                'query_builder' => function(EntityRepository $er) use ($datatype) {
                    return $er->createQueryBuilder('df')
                                ->where('df.is_unique = 1 AND df.dataType = ?1')
                                ->setParameter(1, $datatype);
                },

                'label' => 'External ID Field',
                'property' => 'field_name',
                'expanded' => false,
                'multiple' => false,
                'empty_value' => 'NONE',
            )
        );


        $datatype = $this->datatype;
        $builder->add(
            'nameField',
            'entity',
            array(
                'class' => 'ODR\AdminBundle\Entity\DataFields',
/*
                'query_builder' => function(EntityRepository $er) use ($datatype) {
                    return $er->createQueryBuilder('df')
                                ->where('df.is_unique = 1 AND df.dataType = ?1')
                                ->setParameter(1, $datatype);
                },
*/
                'query_builder' => function(EntityRepository $er) use ($datatype) {
                    return $er->createQueryBuilder('df')
                                ->leftJoin('ODRAdminBundle:FieldType', 'ft', 'WITH', 'df.fieldType = ft')
                                ->where('ft.canBeSortField = 1 AND df.dataType = ?1')
                                ->setParameter(1, $datatype);
                },

                'label' => 'Name Field',
                'property' => 'field_name',
                'expanded' => false,
                'multiple' => false,
                'empty_value' => 'NONE',
            )
        );

        $datatype = $this->datatype;
        $builder->add(
            'sortField',
            'entity',
            array(
                'class' => 'ODR\AdminBundle\Entity\DataFields',
                'query_builder' => function(EntityRepository $er) use ($datatype) {
                    return $er->createQueryBuilder('df')
                                ->leftJoin('ODRAdminBundle:FieldType', 'ft', 'WITH', 'df.fieldType = ft')
                                ->where('ft.canBeSortField = 1 AND df.dataType = ?1')
                                ->setParameter(1, $datatype);
                },

                'label' => 'Sort Field',
                'property' => 'field_name',
                'expanded' => false,
                'multiple' => false,
                'empty_value' => 'NONE',
            )
        );

        $builder->add(
            'multiple_records_per_parent',
            'checkbox',
            array(
                'required' => false,
                'label'  => 'Multiple Allowed',
            )
        );

        $builder->add(
            'display_type',
            'choice',
            array(
                'choices' => array('0' => 'Accordion', '1' => 'Tabbed', '2' => 'Dropdown', '3' => 'List'),
                'label'  => 'Display As',
                'expanded' => false,
                'multiple' => false,
                'empty_value' => false
            )
        );
    }
    
    public function getName() {
        return 'UpdateDataTypeForm';
    }

    /**
     * @param OptionsResolverInterface $resolver 
     */
    public function setDefaultOptions(OptionsResolverInterface $resolver)
    {
        $resolver->setDefaults(array('data_class' => 'ODR\AdminBundle\Entity\DataType'));
    }


}
