<?php

/**
 * Open Data Repository Data Publisher
 * DatatypeDeletedEvent Event
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


class DatatypeDeletedEvent extends Event implements ODREventInterface
{
    // Best practice is apparently to have the Event class define the event name
    const NAME = 'odr.event.datatype_deleted_event';

    /**
     * @var int
     */
    private $datatype_id;

    /**
     * @var string
     */
    private $datatype_uuid;

    /**
     * @var ODRUser
     */
    private $user;

    /**
     * @var bool
     */
    private $was_top_level;


    /**
     * DatatypeDeletedEvent constructor.
     *
     * @param int $datatype_id
     * @param string $datatype_uuid
     * @param ODRUser $user
     * @param bool $was_top_level
     */
    public function __construct(
        int $datatype_id,
        string $datatype_uuid,
        ODRUser $user,
        bool $was_top_level = false
    ) {
        $this->datatype_id = $datatype_id;
        $this->datatype_uuid = $datatype_uuid;
        $this->user = $user;
        $this->was_top_level = $was_top_level;
    }


    /**
     * Returns the id of the datatype that was deleted.
     *
     * @return int
     */
    public function getDatatypeId()
    {
        return $this->datatype_id;
    }


    /**
     * Returns the uuid of the datatype that was deleted.
     *
     * @return string
     */
    public function getDatatypeUUID()
    {
        return $this->datatype_uuid;
    }


    /**
     * Returns the user that deleted the datarecord.
     *
     * @return ODRUser
     */
    public function getUser()
    {
        return $this->user;
    }


    /**
     * Returns whether the deleted datatype was top-level or not
     */
    public function getWasTopLevel()
    {
        return $this->was_top_level;
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
            'dt_id '.$this->datatype_id,
            'dt_uuid '.$this->datatype_uuid
        );
    }
}
