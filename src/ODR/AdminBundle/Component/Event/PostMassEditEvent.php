<?php

/**
 * Open Data Repository Data Publisher
 * Post MassEdit Event
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This event exists so plugins can run their stuff as part of the MassEdit process without necessarily
 * having to change the current values in the relevant datafields.
 *
 * The event is run after most modifications in MassEditController::massUpdateWorkerValuesAction()...
 * the only exception being when MassEditController calls EntityMetaModifyService::updateStorageEntity().
 * The function in that service fires off a PostUpdateEvent, and I can't think of an application
 * where it makes sense to listen to the PostMassEditEvent, but not the PostUpdateEvent.
 */

namespace ODR\AdminBundle\Component\Event;

// Entities
use ODR\AdminBundle\Entity\DataRecordFields;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Symfony
use Symfony\Component\EventDispatcher\Event;


class PostMassEditEvent extends Event implements ODREventInterface
{
    // Best practice is apparently to have the Event class define the event name
    const NAME = 'odr.event.post_massedit_event';

    /**
     * @var DataRecordFields
     */
    private $drf;

    /**
     * @var ODRUser
     */
    private $user;


    /**
     * PostMassEditEvent constructor.
     *
     * @param DataRecordFields $drf
     * @param ODRUser $user
     */
    public function __construct(
        DataRecordFields $drf,
        ODRUser $user
    ) {
        $this->drf = $drf;
        $this->user = $user;
    }


    /**
     * Returns the dataRecordFields entity that got modified as part of a MassEdit job.
     *
     * @return DataRecordFields
     */
    public function getDataRecordFields()
    {
        return $this->drf;
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
            $this->drf->getId().' ('.$this->drf->getDataField()->getFieldType()->getTypeClass().')',
            'df '.$this->drf->getDataField()->getId(),
            'dr '.$this->drf->getDataRecord()->getId(),
        );
    }
}
