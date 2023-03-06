<?php

/**
 * Open Data Repository Data Publisher
 * DatatypeModified Event
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


class DatatypeModifiedEvent extends Event implements ODREventInterface
{
    // Best practice is apparently to have the Event class define the event name
    const NAME = 'odr.event.datatype_modified_event';

    /**
     * @var DataType
     */
    private $datatype;

    /**
     * @var ODRUser
     */
    private $user;

    /**
     * @var bool
     */
    private $clear_datarecord_caches;


    /**
     * DatatypeModifiedEvent constructor.
     *
     * @param DataType $datatype
     * @param ODRUser $user
     * @param bool $clear_datarecord_caches
     */
    public function __construct(
        DataType $datatype,
        ODRUser $user,
        bool $clear_datarecord_caches = false
    ) {
        $this->datatype = $datatype;
        $this->user = $user;
        $this->clear_datarecord_caches = $clear_datarecord_caches;
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
     * Returns the user that performed the modification.
     *
     * @return ODRUser
     */
    public function getUser()
    {
        return $this->user;
    }


    /**
     * Returns whether the event needs to also clear all datarecord cache entries
     */
    public function getClearDatarecordCaches()
    {
        return $this->clear_datarecord_caches;
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
        if ( $this->clear_datarecord_caches ) {
            return array(
                self::NAME,
                'dt '.$this->datatype->getId(),
                'clear_datarecord_entries',
            );
        }
        else {
            return array(
                self::NAME,
                'dt '.$this->datatype->getId(),
            );
        }
    }
}
