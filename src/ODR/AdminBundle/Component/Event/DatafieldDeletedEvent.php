<?php

/**
 * Open Data Repository Data Publisher
 * DatafieldDeleted Event
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Kinda don't want this event to exist, but with Datatypes/Datarecords having a similar event...
 *
 * Render Plugins currently aren't allowed to latch onto this event, mostly because I don't want
 * them to.
 */

namespace ODR\AdminBundle\Component\Event;

// Entities
use ODR\AdminBundle\Entity\DataType;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Symfony
use Symfony\Component\EventDispatcher\Event;


class DatafieldDeletedEvent extends Event implements ODREventInterface
{
    // Best practice is apparently to have the Event class define the event name
    const NAME = 'odr.event.datafield_deleted_event';

    /**
     * @var int
     */
    private $datafield_id;

    /**
     * @var string
     */
    private $datafield_uuid;

    /**
     * @var DataType
     */
    private $datatype;

    /**
     * @var ODRUser
     */
    private $user;


    /**
     * DatafieldDeletedEvent constructor.
     *
     * @param int $datafield_id
     * @param string $datafield_uuid
     * @param DataType $datatype
     * @param ODRUser $user
     */
    public function __construct(
        $datafield_id,
        $datafield_uuid,
        DataType $datatype,
        ODRUser $user
    ) {
        $this->datafield_id = $datafield_id;
        $this->datafield_uuid = $datafield_uuid;
        $this->datatype = $datatype;
        $this->user = $user;
    }


    /**
     * Returns the id of the datafield that was deleted.
     *
     * @return int
     */
    public function getDatafieldId()
    {
        return $this->datafield_id;
    }


    /**
     * Returns the uuid of the datafield that was deleted.
     *
     * @return string
     */
    public function getDatafieldUUID()
    {
        return $this->datafield_uuid;
    }


    /**
     * Returns the datatype of the datafield that was deleted
     *
     * @return DataType
     */
    public function getDatatype()
    {
        return $this->datatype;
    }


    /**
     * Returns the user that deleted the datafield.
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
            'df_id '.$this->datafield_id,
            'df_uuid '.$this->datafield_uuid,
            'dt '.$this->datatype->getId()
        );
    }
}
