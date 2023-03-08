<?php

/**
 * Open Data Repository Data Publisher
 * DatatypeLinkStatusChanged Event
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Linking or unlinking two Datatypes requires the deletion of a pile of cache entries.
 *
 * Render Plugins currently aren't allowed to latch onto this event.
 */

namespace ODR\AdminBundle\Component\Event;

// Entities
use ODR\AdminBundle\Entity\DataType;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Symfony
use Symfony\Component\EventDispatcher\Event;


class DatatypeLinkStatusChangedEvent extends Event implements ODREventInterface
{
    // Best practice is apparently to have the Event class define the event name
    const NAME = 'odr.event.datatype_link_status_change_event';

    /**
     * @var DataType
     */
    private $ancestor_datatype;

    /**
     * @var Datatype|null
     */
    private $new_descendant_datatype;

    /**
     * @var Datatype|null
     */
    private $previous_descendant_datatype;

    /**
     * @var ODRUser
     */
    private $user;


    /**
     * DatatypeLinkStatusChangedEvent constructor.
     *
     * @param DataType $ancestor_datatype
     * @param DataType|null $new_descendant_datatype
     * @param DataType|null $previous_descendant_datatype
     * @param ODRUser $user
     */
    public function __construct(
        DataType $ancestor_datatype,
        $new_descendant_datatype,
        $previous_descendant_datatype,
        ODRUser $user
    ) {
        $this->ancestor_datatype = $ancestor_datatype;
        $this->new_descendant_datatype = $new_descendant_datatype;
        $this->previous_descendant_datatype = $previous_descendant_datatype;
        $this->user = $user;
    }


    /**
     * Returns the datatype on the "ancestor" side of the link.
     *
     * @return DataType
     */
    public function getAncestorDatatype()
    {
        return $this->ancestor_datatype;
    }


    /**
     * Returns the datatype that is now a "linked descendant" of the "ancestor" datatype.
     *
     * @return DataType|null
     */
    public function getNewDescendantDatatype()
    {
        return $this->new_descendant_datatype;
    }


    /**
     * Returns the datatype that used to be the "linked descendant" of the "ancestor datatype.
     *
     * @return DataType|null
     */
    public function getPreviousDescendantDatatype()
    {
        return $this->previous_descendant_datatype;
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
        $tmp = array(
            self::NAME,
            'ancestor dt '.$this->ancestor_datatype->getId()
        );
        if ( !is_null($this->new_descendant_datatype) )
            $tmp[] = 'new descendant dt '.$this->new_descendant_datatype->getId();
        if ( !is_null($this->previous_descendant_datatype) )
            $tmp[] = 'previous descendant dt '.$this->previous_descendant_datatype->getId();

        return $tmp;
    }
}
