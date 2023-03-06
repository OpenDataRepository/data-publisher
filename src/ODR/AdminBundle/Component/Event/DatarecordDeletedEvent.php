<?php

/**
 * Open Data Repository Data Publisher
 * DatarecordDeleted Event
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The primary use for this event is to notify stuff that needs synchronization via API.
 *
 * The datarecord id/uuid parameters can either take a single value, or an array of values...MassEdit
 * needs the latter to be able to correctly notify after it's finished mass deleting records.
 */

namespace ODR\AdminBundle\Component\Event;

// Entities
use ODR\AdminBundle\Entity\DataType;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Symfony
use Symfony\Component\EventDispatcher\Event;


class DatarecordDeletedEvent extends Event implements ODREventInterface
{
    // Best practice is apparently to have the Event class define the event name
    const NAME = 'odr.event.datarecord_deleted_event';

    /**
     * @var int|int[]
     */
    private $datarecord_id;

    /**
     * @var string|string[]
     */
    private $datarecord_uuid;

    /**
     * @var DataType
     */
    private $datatype;

    /**
     * @var ODRUser
     */
    private $user;


    /**
     * DatarecordDeletedEvent constructor.
     *
     * @param int|int[] $datarecord_id
     * @param string|string[] $datarecord_uuid
     * @param DataType $datatype
     * @param ODRUser $user
     */
    public function __construct(
        $datarecord_id,
        $datarecord_uuid,
        DataType $datatype,
        ODRUser $user
    ) {
        $this->datarecord_id = $datarecord_id;
        $this->datarecord_uuid = $datarecord_uuid;
        $this->datatype = $datatype;
        $this->user = $user;
    }


    /**
     * Returns the id of the datarecord that was deleted, or an array of ids if mass deletion
     * triggered this.
     *
     * @return int|int[]
     */
    public function getDatarecordId()
    {
        return $this->datarecord_id;
    }


    /**
     * Returns the uuid of the datarecord that was deleted, or an array of uuids if mass deletion
     * triggered this.
     *
     * @return string|string[]
     */
    public function getDatarecordUUID()
    {
        return $this->datarecord_uuid;
    }


    /**
     * Returns the datatype of the datarecord that was deleted
     *
     * @return DataType
     */
    public function getDatatype()
    {
        return $this->datatype;
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
        if ( !is_array($this->datarecord_id) ) {
            return array(
                self::NAME,
                'dr_id '.$this->datarecord_id,
                'dr_uuid '.$this->datarecord_uuid,
                'dt '.$this->datatype->getId()
            );
        }
        else {
            return array(
                self::NAME,
                'dr_ids '.implode(',', $this->datarecord_id),
                'dt '.$this->datatype->getId()
            );
        }
    }
}
