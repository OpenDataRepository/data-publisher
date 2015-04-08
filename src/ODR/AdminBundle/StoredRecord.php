<?php

namespace ODR\AdminBundle;

// use Symfony\Bundle\FrameworkBundle\Controller\Controller;
        
// Entites
// use ODR\AdminBundle\Entity\Theme;
// use ODR\AdminBundle\Entity\ThemeDataField;
// use ODR\AdminBundle\Entity\ThemeDataType;
// use ODR\AdminBundle\Entity\DataFields;
// use ODR\AdminBundle\Entity\DataType;
// use ODR\AdminBundle\Entity\DataTree;
// use ODR\AdminBundle\Entity\DataRecord;
// use ODR\AdminBundle\Entity\DataRecordType;
// use ODR\AdminBundle\Entity\DataRecordFields;
// use ODR\AdminBundle\Entity\Checkbox;
// Forms

class StoredRecord {

    /**
     * TODO: description.
     * 
     * @var double
     */
    public $data_record_id;

    /**
     * TODO: description.
     * 
     * @var double
     */
    public $data_record;

    /**
     * TODO: description.
     * 
     * @var double
     */
    public $data_type;

    /**
     * TODO: description.
     * 
     * @var mixed
     */
    public $child_data_types;

    /**
     * TODO: description.
     * 
     * @var double
     */
    public $data_fields;

    /**
     * TODO: description.
     * 
     * @var double  Defaults to array(). 
     */
    public $data_record_type = array();

    /**
     * TODO: description.
     * 
     * @var double  Defaults to array(). 
     */
    public $data_record_fields = array();

    /**
     * TODO: description.
     * 
     * @var double  Defaults to array(). 
     */
    public $data_record_fields_values = array();

    /**
     * TODO: description.
     * 
     * @var bool  Defaults to array(). 
     */
    public $boolean_data = array();


    /**
     * TODO: description.
     * 
     * @var mixed  Defaults to array(). 
     */
    public $checkbox_data = array();


    /**
     * TODO: description.
     * 
     * @var double  Defaults to array(). 
     */
    public $datetime_data = array();

    /**
     * TODO: description.
     * 
     * @var mixed  Defaults to array(). 
     */
    public $file_data = array();

    /**
     * TODO: description.
     * 
     * @var int  Defaults to array(). 
     */
    public $image_data = array();

    /**
     * TODO: description.
     * 
     * @var int  Defaults to array(). 
     */
    public $integer_data = array();

    /**
     * TODO: description.
     * 
     * @var mixed  Defaults to array(). 
     */
    public $long_text_data = array();

    /**
     * TODO: description.
     * 
     * @var mixed  Defaults to array(). 
     */
    public $long_varchar_data = array();

    /**
     * TODO: description.
     * 
     * @var mixed  Defaults to array(). 
     */
    public $medium_varchar_data = array();

    /**
     * TODO: description.
     * 
     * @var resource  Defaults to array(). 
     */
    public $radio = array();

    /**
     * TODO: description.
     * 
     * @var string  Defaults to array(). 
     */
    public $short_varchar_data = array();

    /**
     * TODO: description.
     * 
     * @var mixed  Defaults to array(). 
     */
    public $xyz_data = array();


    /**
     * TODO: description.
     * 
     * @var mixed  Defaults to array(). 
     */
    public $tree_data = array();

}


