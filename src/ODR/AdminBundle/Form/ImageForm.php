<?php

/**
 * @deprecated
 *
 * Open Data Repository Data Publisher
 * Image Form
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 */

namespace ODR\AdminBundle\Form;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

use ODR\AdminBundle\Form\DataTransformer\DataFieldsToNumberTransformer;
use ODR\AdminBundle\Form\DataTransformer\FieldTypeToNumberTransformer;
use ODR\AdminBundle\Form\DataTransformer\DataRecordToNumberTransformer;
use ODR\AdminBundle\Form\DataTransformer\DataRecordFieldsToNumberTransformer;
use ODR\AdminBundle\Form\DataTransformer\ImageSizesToNumberTransformer;

class ImageForm extends AbstractType
{

    /**
     * TODO: description.
     * 
     * @var mixed
     */
    private $em;

    /**
    * TODO: short description.
    * 
    * @param EntityManager $em 
    * 
    */
    public function __construct($em)
    {  
        $this->em = $em;
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {

        // if(isset($options['update']) && $options['update'] == true) {
            $builder->add('id', 'hidden');
        // }
        $builder->add(
            'original', 
            'text', 
            array(
                'required' => true,
                'label'  => 'Value',
            )
        );
/*
        $builder->add(
            'caption', 
            'text', 
            array(
                'required' => true,
                'label'  => 'Image File',
            )
        );
*/
        $builder->add(
            'file', 
            'file', 
            array(
                'required' => true,
                'label'  => 'Image File',
            )
        );

        $is_transformer = new ImageSizesToNumberTransformer($this->em);
        $builder->add(
            $builder->create('image_size', 'hidden')
                ->addModelTransformer($is_transformer)
        );


        $df_transformer = new DataFieldsToNumberTransformer($this->em);
        $builder->add(
            $builder->create('data_field', 'hidden')
                ->addModelTransformer($df_transformer)
        );

        $ft_transformer = new FieldTypeToNumberTransformer($this->em);
        $builder->add(
            $builder->create('field_type', 'hidden')
                ->addModelTransformer($ft_transformer)
        );

        $dr_transformer = new DataRecordToNumberTransformer($this->em);
        $builder->add(
            $builder->create('data_record', 'hidden')
                ->addModelTransformer($dr_transformer)
        );

        $drf_transformer = new DataRecordFieldsToNumberTransformer($this->em);
        $builder->add(
            $builder->create('data_record_fields', 'hidden')
                ->addModelTransformer($drf_transformer)
        );

        $builder->add(
            'createdBy',
            'entity',
            array(
                'class' => 'ODR\OpenRepository\UserBundle\Entity\User',
                'property' => 'id',
            )
        );

        $builder->add(
            'updatedBy',
            'entity',
            array(
                'class' => 'ODR\OpenRepository\UserBundle\Entity\User',
                'property' => 'id',
                'attr'=>array('style'=>'display:none;')
            )
        );
    }
    
    public function getName() {
        return 'ImageForm';
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
        $resolver->setDefaults(array('data_class' => 'ODR\AdminBundle\Entity\Image'));
    }


}
