<?php

/**
 * Open Data Repository Data Publisher
 * DatarecordLinkStatusChanged Event
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Linking or unlinking Datarecords requires the deletion of a pile of cache entries.
 *
 * Render Plugins currently aren't allowed to latch onto this event.
 */

namespace ODR\AdminBundle\Component\Event;

// Entities
use ODR\AdminBundle\Entity\DataType;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Symfony
use Symfony\Component\EventDispatcher\Event;


class DatarecordLinkStatusChangedEvent extends Event implements ODREventInterface
{
    // Best practice is apparently to have the Event class define the event name
    const NAME = 'odr.event.datarecord_link_status_change_event';

    /**
     * @var int[]
     */
    private $datarecord_ids;

    /**
     * @var DataType
     */
    private $descendant_datatype;

    /**
     * @var ODRUser
     */
    private $user;

    /**
     * @var bool
     */
    private $mark_as_updated;


    /**
     * DatarecordLinkStatusChangedEvent constructor.
     *
     * @param int[] $datarecord_ids
     * @param DataType $descendant_datatype
     * @param ODRUser $user
     * @param bool $mark_as_updated
     */
    public function __construct(
        $datarecord_ids,
        DataType $descendant_datatype,
        ODRUser $user,
        bool $mark_as_updated = false
    ) {
        $this->datarecord_ids = $datarecord_ids;
        $this->descendant_datatype = $descendant_datatype;
        $this->user = $user;
        $this->mark_as_updated = $mark_as_updated;
    }


    /**
     * Returns the ids of the datarecords that have been affected by this event.  They should all be
     * "ancestors" (records) instead of "descendants"...though the event listeners should theoretically
     * be able to deal with either side of the relationship.  This is mostly because datarecord
     * deletion also needs to trigger this event, and in that case the "descendants" just got deleted.
     *
     * Since they're supposed to be "ancestors", there's no guarantee these records all belong to
     * the same datatype.
     *
     * @return int[]
     */
    public function getDatarecordIds()
    {
        return $this->datarecord_ids;
    }


    /**
     * Returns the datatype on the "descendant" side of the link.  Typically, this is going to be
     * the datatype of the datarecord(s) that triggered this event.
     *
     * @return DataType
     */
    public function getDescendantDatatype()
    {
        return $this->descendant_datatype;
    }


    /**
     * Returns the user that triggered this event.
     *
     * @return ODRUser
     */
    public function getUser()
    {
        return $this->user;
    }


    /**
     * Returns whether the event also needs to handle setting ancestor records as updated.
     *
     * @return bool
     */
    public function getMarkAsUpdated()
    {
        return $this->mark_as_updated;
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
            'dr_ids '.implode(',', $this->datarecord_ids),
            'dt '.$this->descendant_datatype->getId(),
            'mark_as_updated: '.$this->mark_as_updated,
        );
    }
}
