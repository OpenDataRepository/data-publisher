<?php

/**
 * Open Data Repository Data Publisher
 * DatafieldModified Event
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
use ODR\AdminBundle\Entity\DataFields;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Symfony
use Symfony\Component\EventDispatcher\Event;


class DatafieldModifiedEvent extends Event implements ODREventInterface
{
    // Best practice is apparently to have the Event class define the event name
    const NAME = 'odr.event.datafield_modified_event';

    /**
     * @var DataFields
     */
    private $datafield;

    /**
     * @var ODRUser
     */
    private $user;


    /**
     * DatafieldModifiedEvent constructor.
     *
     * @param DataFields $datafield
     * @param ODRUser $user
     */
    public function __construct(
        DataFields $datafield,
        ODRUser $user
    ) {
        $this->datafield = $datafield;
        $this->user = $user;
    }


    /**
     * Returns the datafield that just got modified.
     *
     * @return DataFields
     */
    public function getDatafield()
    {
        return $this->datafield;
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
            'df '.$this->datafield->getId(),
        );
    }
}
