<?php

/**
 * Open Data Repository Data Publisher
 * DatatypeCreated Event
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


class DatatypeCreatedEvent extends Event implements ODREventInterface
{
    // Best practice is apparently to have the Event class define the event name
    const NAME = 'odr.event.datatype_created_event';

    /**
     * @var DataType
     */
    private $datatype;

    /**
     * @var ODRUser
     */
    private $user;


    /**
     * DatatypeCreatedEvent constructor.
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
     * Returns the datatype that just got created.
     *
     * @return DataType
     */
    public function getDatatype()
    {
        return $this->datatype;
    }


    /**
     * Returns the user that created the datatype.
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
