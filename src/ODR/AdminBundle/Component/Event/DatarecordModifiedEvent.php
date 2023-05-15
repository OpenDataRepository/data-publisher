<?php

/**
 * Open Data Repository Data Publisher
 * DatarecordModified Event
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The primary use for this event is to notify stuff that needs synchronization via API.
 *
 * Render Plugins currently aren't allowed to latch onto this event, mostly because MassEdit and
 * CSVImport can fire this event for the same record multiple times in a row.
 */

namespace ODR\AdminBundle\Component\Event;

// Entities
use ODR\AdminBundle\Entity\DataRecord;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Symfony
use Symfony\Component\EventDispatcher\Event;


class DatarecordModifiedEvent extends Event implements ODREventInterface
{
    // Best practice is apparently to have the Event class define the event name
    const NAME = 'odr.event.datarecord_modified_event';

    /**
     * @var DataRecord
     */
    private $datarecord;

    /**
     * @var ODRUser
     */
    private $user;


    /**
     * DatarecordModifiedEvent constructor.
     *
     * @param DataRecord $datarecord
     * @param ODRUser $user
     */
    public function __construct(
        DataRecord $datarecord,
        ODRUser $user
    ) {
        $this->datarecord = $datarecord;
        $this->user = $user;
    }


    /**
     * Returns the datarecord that just got modified.
     *
     * @return DataRecord
     */
    public function getDatarecord()
    {
        return $this->datarecord;
    }


    /**
     * Returns the user that performed the modification.
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
        return array(
            self::NAME,
            'dr '.$this->datarecord->getId(),
        );
    }
}
