<?php

/**
 * Open Data Repository Data Publisher
 * Entity Creation Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * TODO -
 *
 */

namespace ODR\AdminBundle\Component\Service;

// Entities
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataRecordMeta;
use ODR\AdminBundle\Entity\DataType;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
// Other
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;


class EntityCreationService
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
     * EntityCreationService constructor.
     *
     * @param EntityManager $entityManager
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entityManager,
        Logger $logger
    ) {
        $this->em = $entityManager;

        $this->logger = $logger;
    }


    /**
     * Creates and persists a new Datarecord and a new DatarecordMeta entity.  The user will need
     * to set the provisioned property back to false eventually.
     *
     * @param ODRUser $user
     * @param DataType $datatype
     * @param bool $delay_flush
     *
     * @return DataRecord
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    public function createDatarecord($user, $datatype, $delay_flush = false)
    {
        // Initial create
        $datarecord = new DataRecord();

        $datarecord->setDataType($datatype);
        $datarecord->setCreatedBy($user);
        $datarecord->setUpdatedBy($user);

        // Default to assuming this is a top-level datarecord
        $datarecord->setParent($datarecord);
        $datarecord->setGrandparent($datarecord);

        $datarecord->setProvisioned(true);  // Prevent most areas of the site from doing anything with this datarecord...whatever created this datarecord needs to eventually set this to false
        $datarecord->setUniqueId(null);

        $this->em->persist($datarecord);

        $datarecord_meta = new DataRecordMeta();
        $datarecord_meta->setDataRecord($datarecord);
        $datarecord_meta->setPublicDate(new \DateTime('2200-01-01 00:00:00'));   // default to not public

        $datarecord_meta->setCreatedBy($user);
        $datarecord_meta->setUpdatedBy($user);

        $datarecord->addDataRecordMetum($datarecord_meta);
        $this->em->persist($datarecord_meta);

        if ( !$delay_flush )
            $this->em->flush();

        return $datarecord;
    }

    // TODO - add rest of entity creation stuff into here?
}
