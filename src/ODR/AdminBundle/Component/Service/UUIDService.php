<?php

/**
 * Open Data Repository Data Publisher
 * UUID Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This service stores the code to generate UUIDs for ODR's database entities.
 */

namespace ODR\AdminBundle\Component\Service;

// Entities
// Services
use ODR\AdminBundle\Component\Utility\UniqueUtility;
// Other
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;

class UUIDService
{

    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * UUIDService constructor.
     *
     * @param EntityManager $entityManager
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->logger = $logger;
    }


    /**
     * Generates and returns a unique_id string that doesn't collide with any other datafield's
     * "unique_id" property.
     *
     * @return string
     */
    public function generateDatafieldUniqueId()
    {
        /*
        // Need to get all current ids in use in order to determine uniqueness of a new id...
        $query = $this->em->createQuery(
           'SELECT df.fieldUuid
            FROM ODRAdminBundle:DataFields AS df
            WHERE df.deletedAt IS NULL and df.fieldUuid IS NOT NULL'
        );
        $results = $query->getArrayResult();

        $existing_ids = array();
        foreach ($results as $num => $result)
            $existing_ids[ $result['fieldUuid'] ] = 1;


        // Keep generating ids until we come across one that's not in use
        $unique_id = UniqueUtility::uniqueIdReal();
        while ( isset($existing_ids[$unique_id]) )
            $unique_id = UniqueUtility::uniqueIdReal();
        */

        $unique_id = UniqueUtility::uniqueIdReal(28);
        return $unique_id;
    }


    /**
     * Generates and returns a unique_id string that doesn't collide with any other datarecord's
     * "unique_id" property.
     *
     * @return string
     */
    public function generateDatarecordUniqueId()
    {
        /*
        // Need to get all current ids in use in order to determine uniqueness of a new id...
        $query = $this->em->createQuery(
           'SELECT dr.unique_id
            FROM ODRAdminBundle:DataRecord AS dr
            WHERE dr.deletedAt IS NULL and dr.unique_id IS NOT NULL'
        );
        $results = $query->getArrayResult();

        $existing_ids = array();
        foreach ($results as $num => $result)
            $existing_ids[ $result['unique_id'] ] = 1;


        // Keep generating ids until we come across one that's not in use
        $unique_id = UniqueUtility::uniqueIdReal();
        while ( isset($existing_ids[$unique_id]) )
            $unique_id = UniqueUtility::uniqueIdReal();
        */

        $unique_id = UniqueUtility::uniqueIdReal(28);
        return $unique_id;
    }


    /**
     * Generates and returns a unique_id string that doesn't collide with any other datatype's
     * "unique_id" property.  Shouldn't be used for the datatype's "template_group" property, as
     * those should be based off of the grandparent datatype's "unique_id".
     *
     * @return string
     */
    public function generateDatatypeUniqueId()
    {
        /*
        // Need to get all current ids in use in order to determine uniqueness of a new id...
        $query = $this->em->createQuery(
           'SELECT dt.unique_id
            FROM ODRAdminBundle:DataType AS dt
            WHERE dt.deletedAt IS NULL and dt.unique_id IS NOT NULL'
        );
        $results = $query->getArrayResult();

        $existing_ids = array();
        foreach ($results as $num => $result)
            $existing_ids[ $result['unique_id'] ] = 1;


        // Keep generating ids until we come across one that's not in use
        $unique_id = UniqueUtility::uniqueIdReal();
        while ( isset($existing_ids[$unique_id]) )
            $unique_id = UniqueUtility::uniqueIdReal();

        */

        $unique_id = UniqueUtility::uniqueIdReal();
        return $unique_id;
    }


    /**
     * Generates and returns a unique_id string that doesn't collide with any other radio option's
     * "unique_id" property.
     *
     * @return string
     */
    public function generateRadioOptionUniqueId()
    {
        /*
        // Need to get all current ids in use in order to determine uniqueness of a new id...
        $query = $this->em->createQuery(
           'SELECT ro.radioOptionUuid
            FROM ODRAdminBundle:RadioOptions AS ro
            WHERE ro.deletedAt IS NULL and ro.radioOptionUuid IS NOT NULL'
        );
        $results = $query->getArrayResult();

        $existing_ids = array();
        foreach ($results as $num => $result)
            $existing_ids[ $result['radioOptionUuid'] ] = 1;


        // Keep generating ids until we come across one that's not in use
        $unique_id = UniqueUtility::uniqueIdReal();
        while ( isset($existing_ids[$unique_id]) )
            $unique_id = UniqueUtility::uniqueIdReal();
        */

        $unique_id = UniqueUtility::uniqueIdReal(28);
        return $unique_id;
    }


    /**
     * Generates and returns a unique_id string that doesn't collide with any other tag's
     * "unique_id" property.
     *
     * @return string
     */
    public function generateTagUniqueId()
    {
        /*
        // Need to get all current ids in use in order to determine uniqueness of a new id...
        $query = $this->em->createQuery(
           'SELECT t.tagUuid
            FROM ODRAdminBundle:Tags AS t
            WHERE t.deletedAt IS NULL and t.tagUuid IS NOT NULL'
        );
        $results = $query->getArrayResult();

        $existing_ids = array();
        foreach ($results as $num => $result)
            $existing_ids[ $result['tagUuid'] ] = 1;


        // Keep generating ids until we come across one that's not in use
        $unique_id = UniqueUtility::uniqueIdReal();
        while ( isset($existing_ids[$unique_id]) )
            $unique_id = UniqueUtility::uniqueIdReal();
        */

        $unique_id = UniqueUtility::uniqueIdReal(28);
        return $unique_id;
    }

    // TODO - technically, the above functions don't guarantee uniqueness due to delay_flush shennanigans
    // TODO - can't use a mysql constraint, that'll just throw errors upon flushing
    // TODO - don't think a list of "reserved uuids" in redis will work...
    // TODO - forcing a verification check afterwards (with or without placeholder uuids) is...not possible without always running a file-locked-verification after creating something
}
