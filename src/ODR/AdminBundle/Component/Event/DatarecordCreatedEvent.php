<?php

/**
 * Open Data Repository Data Publisher
 * DatarecordCreated Event
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Due to design decisions, "auto-incrementing" of ID fields for databases is going to be handled
 * via render plugins.  As such, an event is needed for notification that a datarecord has been
 * created, and therefore needs to have its ID generated.
 *
 * There are probably other potential uses of this, but none come to mind at the moment.
 */

namespace ODR\AdminBundle\Component\Event;

// Entities
use ODR\AdminBundle\Entity\DataRecord;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Symfony
use Symfony\Component\EventDispatcher\Event;


class DatarecordCreatedEvent extends Event implements ODREventInterface
{
    // Best practice is apparently to have the Event class define the event name
    const NAME = 'odr.event.datarecord_created_event';

    /**
     * @var DataRecord
     */
    private $datarecord;

    /**
     * @var ODRUser
     */
    private $user;


    /**
     * DatarecordCreatedEvent constructor.
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
     * Returns the datarecord that the file was deleted from.
     *
     * @return DataRecord
     */
    public function getDatarecord()
    {
        return $this->datarecord;
    }


    /**
     * Returns the user that deleted the file.
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
