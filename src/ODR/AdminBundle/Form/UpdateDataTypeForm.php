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

namespace ODR\AdminBundle\Form;

use ODR\AdminBundle\Entity\DataType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

use Doctrine\ORM\EntityRepository;

class UpdateDataTypeForm extends AbstractType
{

    /** @var DataType */
    protected $datatype;

    /** @var bool */
    protected $for_slideout;

    /** @var bool */
    protected $is_top_level;

    /**
     * UpdateDataTypeForm constructor.
     *
     * @param DataType $datatype
     * @param bool $for_slideout  If true, then form is being used for right slideout in DisplayTemplate...otherwise, being used for the datatype properties page
     * @param bool $is_top_level  Whether the Datatype is top-level or not
     */
    public function __construct (\ODR\AdminBundle\Entity\DataType $datatype, $for_slideout, $is_top_level) {
        $this->datatype = $datatype;
        $this->for_slideout = $for_slideout;
        $this->is_top_level = $is_top_level;
    }


    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        // Load values passed in via constructor
        $datatype = $this->datatype;
        $for_slideout = $this->for_slideout;
        $is_top_level = $this->is_top_level;

        if ($for_slideout) {
            // If form is being used for the slideout, provide the ability to edit short name, long name, and description...but not searchSlug
            $builder->add(
                'short_name',
                'text',
                array(
                    'required' => true,
                    'label' => 'Short Name',
                )
            );

            $builder->add(
                'long_name',
                'text',
                array(
                    'required' => true,
                    'label' => 'Long Name',
                )
            );

            $builder->add(
                'description',
                'textarea',
                array(
                    'required' => true,
                    'label' => 'Description',
                )
            );

            // required so validator won't complain of "extra fields"
            $builder->add(
                'searchSlug',
                'hidden'
            );
        }
        else {
            // If form is being used on the datatype properties page, don't allow user to edit short name, long name, and description...but allow searchSlug
            $builder->add(
                'searchSlug',
                'text',
                array(
                    'label' => 'Search Abbreviation',
                )
            );
        }


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
                                ->leftJoin('ODRAdminBundle:DataFieldsMeta', 'dfm', 'WITH', 'dfm.dataField = df')
                                ->leftJoin('ODRAdminBundle:FieldType', 'ft', 'WITH', 'dfm.fieldType = ft')
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

        $builder->add(
            'sortField',
            'entity',
            array(
                'class' => 'ODR\AdminBundle\Entity\DataFields',
                'query_builder' => function(EntityRepository $er) use ($datatype) {
                    return $er->createQueryBuilder('df')
                                ->leftJoin('ODRAdminBundle:DataFieldsMeta', 'dfm', 'WITH', 'dfm.dataField = df')
                                ->leftJoin('ODRAdminBundle:FieldType', 'ft', 'WITH', 'dfm.fieldType = ft')
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

        if ($is_top_level) {
            // Only provide options to change these properties if datatype is top-level...
            $builder->add(
                'backgroundImageField',
                'entity',
                array(
                    'class' => 'ODR\AdminBundle\Entity\DataFields',
                    'query_builder' => function(EntityRepository $er) use ($datatype) {
                        return $er->createQueryBuilder('df')
                            ->leftJoin('ODRAdminBundle:DataFieldsMeta', 'dfm', 'WITH', 'dfm.dataField = df')
                            ->leftJoin('ODRAdminBundle:FieldType', 'ft', 'WITH', 'dfm.fieldType = ft')
                            ->where('ft.typeName = ?1 AND df.dataType = ?2')
                            ->setParameter(1, 'Image')
                            ->setParameter(2, $datatype);
                    },

                    'label' => 'Background Image Field',
                    'property' => 'field_name',
                    'expanded' => false,
                    'multiple' => false,
                    'empty_value' => 'NONE',
                )
            );

            $builder->add(
                'useShortResults',
                'choice',
                array(
                    'choices' => array('0' => 'TextResults', '1' => 'ShortResults'),
                    'label' => 'Short Display',
                    'expanded' => false,
                    'multiple' => false,
                    'empty_value' => false
                )
            );
        }

        if ( $for_slideout == true && $is_top_level == false ) {
            // Only display this property if the form is for a childtype
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
        else {
            // required so validator won't complain of "extra fields"
            $builder->add(
                'display_type',
                'hidden'
            );
        }

    }


    /**
     * Returns the name of this type.
     *
     * @return string The name of this type
     */
    public function getName() {
        return 'UpdateDataTypeForm';
    }


    /**
     * {@inheritdoc}
     */
    public function setDefaultOptions(OptionsResolverInterface $resolver)
    {
//        $resolver->setDefaults(array('data_class' => 'ODR\AdminBundle\Entity\DataType'));
        $resolver->setDefaults(array('data_class' => 'ODR\AdminBundle\Entity\DataTypeMeta'));
    }


}
