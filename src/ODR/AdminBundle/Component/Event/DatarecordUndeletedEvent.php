<?php

/**
 * Open Data Repository Data Publisher
 * DatarecordUndeleted Event
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Undeleting a datarecord needs a lot of cache clearing performed, and the other events don't really
 * cover all of it...or they have undesired side-effects.
 */

namespace ODR\AdminBundle\Component\Event;

// Entities
use ODR\AdminBundle\Entity\DataRecord;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Symfony
use Symfony\Component\EventDispatcher\Event;

class DatarecordUndeletedEvent extends \Symfony\Contracts\EventDispatcher\Event implements ODREventInterface
{
    // Best practice is apparently to have the Event class define the event name
    const NAME = 'odr.event.datarecord_undeleted_event';


    /**
     * DatarecordUndeletedEvent constructor.
     *
     * @param DataRecord $datarecord
     * @param ODRUser $user
     * @param null|DataRecord[] $linked_ancestor_datarecords
     * @param null|DataRecord[] $linked_descendant_datarecords
    */
    public function __construct(
        private readonly DataRecord $datarecord,
        private readonly ODRUser $user,
        private readonly ?array $linked_ancestor_datarecords,
        private readonly ?array $linked_descendant_datarecords
    ) {

    }


    /**
     * Returns the datarecord that just got undeleted.
     *
     * @return DataRecord
     */
    public function getDatarecord()
    {
        return $this->datarecord;
    }


    /**
     * Returns the datarecords that linked to the undeleted record.
     *
     * @return null|DataRecord[]
     */
    public function getLinkedAncestors()
    {
        return $this->linked_ancestor_datarecords;
    }


    /**
     * Returns the datarecords that the undeleted record (and its descendants) linked to.
     *
     * @return null|DataRecord[]
     */
    public function getLinkedDescendants()
    {
        return $this->linked_descendant_datarecords;
    }


    /**
     * Returns the user that performed the undeletion.
     *
     * @return ODRUser
     */
    public function getUser()
    {
        return $this->user;
    }


    /**
     * {@inheritDoc}
     */
    public function getEventName()
    {
        return self::NAME;
    }


    /**
     * {@inheritDoc}
     */
    public function getErrorInfo()
    {
        return [
            self::NAME,
            'dr '.$this->datarecord->getId(),
        ];
    }
}
