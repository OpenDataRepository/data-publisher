<?php

/**
 * Open Data Repository Data Publisher
 * UUID Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This service stores the code to generate UUIDs for ODR's database entities.
 *
 * At the moment, these are 100% random...but the function calls are kept separate in-case this
 * needs to change to a different format (e.g. "entity_id" + "entity_type" + "odr_instance_id")...
 */

namespace ODR\AdminBundle\Component\Service;

// Services
use ODR\AdminBundle\Component\Utility\UniqueUtility;
// Other
use Symfony\Bridge\Monolog\Logger;


class UUIDService
{

    /**
     * @var Logger
     */
    private $logger;


    /**
     * UUIDService constructor.
     *
     * @param Logger $logger
     */
    public function __construct(
        Logger $logger
    ) {
        $this->logger = $logger;
    }


    /**
     * Generates and returns a UUID for datafields.
     *
     * @return string
     */
    public function generateDatafieldUniqueId()
    {
        return UniqueUtility::uniqueIdReal(28);
    }


    /**
     * Generates and returns a UUID for datarecords.
     *
     * @return string
     */
    public function generateDatarecordUniqueId()
    {
        return UniqueUtility::uniqueIdReal(28);
    }


    /**
     * Generates and returns a UUID for databases.
     *
     * Shouldn't be used for the datatype's "template_group" property, as those should be based off
     * of the grandparent datatype's "unique_id".
     *
     * @return string
     */
    public function generateDatatypeUniqueId()
    {
        return UniqueUtility::uniqueIdReal(28);
    }


    /**
     * Generates and returns a UUID for files.
     *
     * @return string
     */
    public function generateFileUniqueId()
    {
        return UniqueUtility::uniqueIdReal(28);
    }


    /**
     * Generates and returns a UUID for images.
     *
     * @return string
     */
    public function generateImageUniqueId()
    {
        return UniqueUtility::uniqueIdReal(28);
    }


    /**
     * Generates and returns a UUID for radio options.
     *
     * @return string
     */
    public function generateRadioOptionUniqueId()
    {
        return UniqueUtility::uniqueIdReal(28);
    }


    /**
     * Generates and returns a UUID for tags.
     *
     * @return string
     */
    public function generateTagUniqueId()
    {
        return UniqueUtility::uniqueIdReal(28);
    }
}
