<?php

/**
 * Open Data Repository Data Publisher
 * PostUpdate Event
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This event exists to allow "derivation" of one field's value from another field.  This setup is
 * irritating, because ODR was not originally designed with this in mind.
 */

namespace ODR\AdminBundle\Component\Event;

// Entities
use ODR\AdminBundle\Entity\Boolean as ODRBoolean;
use ODR\AdminBundle\Entity\DatetimeValue;
use ODR\AdminBundle\Entity\DecimalValue;
use ODR\AdminBundle\Entity\IntegerValue;
use ODR\AdminBundle\Entity\LongText;
use ODR\AdminBundle\Entity\LongVarchar;
use ODR\AdminBundle\Entity\MediumVarchar;
use ODR\AdminBundle\Entity\ShortVarchar;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Symfony
use Symfony\Component\EventDispatcher\Event;


class PostUpdateEvent extends Event implements ODREventInterface
{
    // Best practice is apparently to have the Event class define the event name
    const NAME = 'odr.event.post_update_event';

    /**
     * @var ODRBoolean|DatetimeValue|DecimalValue|IntegerValue|LongText|LongVarchar|MediumVarchar|ShortVarchar
     */
    private $storage_entity;

    /**
     * @var null|ODRBoolean|DatetimeValue|DecimalValue|IntegerValue|LongText|LongVarchar|MediumVarchar|ShortVarchar
     */
    private $derived_entity;

    /**
     * @var ODRUser
     */
    private $user;


    /**
     * PostUpdateEvent constructor.
     *
     * @param ODRBoolean|DatetimeValue|DecimalValue|IntegerValue|LongText|LongVarchar|MediumVarchar|ShortVarchar $storage_entity
     * @param ODRUser $user
     */
    public function __construct(
        $storage_entity,
        ODRUser $user
    ) {
        $this->storage_entity = $storage_entity;
        $this->derived_entity = null;
        $this->user = $user;
    }


    /**
     * Returns the storage entity that got created/modified.
     *
     * @return ODRBoolean|DatetimeValue|DecimalValue|IntegerValue|LongText|LongVarchar|MediumVarchar|ShortVarchar
     */
    public function getStorageEntity()
    {
        return $this->storage_entity;
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
     * Sets which entity (if any) this event ended up changing.    TODO - does this need to be an array?
     *
     * @param ODRBoolean|DatetimeValue|DecimalValue|IntegerValue|LongText|LongVarchar|MediumVarchar|ShortVarchar $derived_entity
     */
    public function setDerivedEntity($derived_entity)
    {
        $this->derived_entity = $derived_entity;
    }


    /**
     * Returns which entity (if any) this event ended up changing.
     *
     * @return ODRBoolean|DatetimeValue|DecimalValue|IntegerValue|LongText|LongVarchar|MediumVarchar|ShortVarchar|null
     */
    public function getDerivedEntity()
    {
        return $this->derived_entity;
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
            $this->storage_entity->getDataField()->getFieldType()->getTypeClass().' '.$this->storage_entity->getId(),
            'df '.$this->storage_entity->getDataField()->getId(),
            'dr '.$this->storage_entity->getDataRecord()->getId(),
        );
    }
}
