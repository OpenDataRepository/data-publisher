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
use ODR\AdminBundle\Entity\DataRecordFields;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Symfony
use Symfony\Component\EventDispatcher\Event;


class RadioPostUpdateEvent extends Event implements ODREventInterface
{
    // Best practice is apparently to have the Event class define the event name
    const NAME = 'odr.event.radio_post_update_event';

    /**
     * @var DataRecordFields
     */
    private $radio_drf;

    /**
     * @var ODRUser
     */
    private $user;


    /**
     * RadioPostUpdateEvent constructor.
     *
     * @param DataRecordFields $radio_drf
     * @param ODRUser $user
     */
    public function __construct(
        DataRecordFields $radio_drf,
        ODRUser $user
    ) {
        $this->radio_drf = $radio_drf;
        $this->user = $user;
    }


    /**
     * Returns the drf entry that got created/modified.
     *
     * @return DataRecordFields
     */
    public function getRadioDataRecordField()
    {
        return $this->radio_drf;
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
            $this->radio_drf->getDataField()->getFieldType()->getTypeName(),
            'df '.$this->radio_drf->getDataField()->getId(),
            'dr '.$this->radio_drf->getDataRecord()->getId(),
        );
    }
}
