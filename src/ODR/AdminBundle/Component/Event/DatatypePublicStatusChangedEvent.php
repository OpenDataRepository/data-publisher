<?php

/**
 * Open Data Repository Data Publisher
 * DatatypePublicStatusChanged Event
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The primary use for this event is to notify stuff that needs synchronization via API.
 *
 * Render Plugins currently aren't allowed to latch onto this event.
 */

namespace ODR\AdminBundle\Component\Event;

// Entities
use ODR\AdminBundle\Entity\DataType;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Symfony
use Symfony\Component\EventDispatcher\Event;


class DatatypePublicStatusChangedEvent extends Event implements ODREventInterface
{
    // Best practice is apparently to have the Event class define the event name
    const NAME = 'odr.event.datatype_public_status_changed_event';

    /**
     * @var DataType
     */
    private $datatype;

    /**
     * @var ODRUser
     */
    private $user;


    /**
     * DatatypePublicStatusChangedEvent constructor.
     *
     * @param DataType $datatype
     * @param ODRUser $user
     */
    public function __construct(
        DataType $datatype,
        ODRUser $user
    ) {
        $this->datatype = $datatype;
        $this->user = $user;
    }


    /**
     * Returns the datatype that just got modified.
     *
     * @return DataType
     */
    public function getDatatype()
    {
        return $this->datatype;
    }


    /**
     * Returns the user that changed the public status of this datarecord.
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
            'dt '.$this->datatype->getId(),
        );
    }
}
