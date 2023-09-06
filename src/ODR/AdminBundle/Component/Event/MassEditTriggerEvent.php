<?php

/**
 * Open Data Repository Data Publisher
 * MassEdit Trigger Event
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This event exists so users can trigger plugin-granted modifications for specific fields, without
 * necessarily having to change the current values in said datafields.
 *
 *
 * The MassEdit system "asks" the plugins which datafields to enable this functionality for...then
 * once the user has submitted the MassEdit POST, the system again "asks" the selected plugins whether
 * they only want to do their thing when explicitly requested.
 *
 * For instance, the FileHeaderInserter plugin has to lookup a bunch of values in the cached arrays,
 * then decrypt/modify/re-encrypt each relevant file in that datafield...something which should be
 * avoided unless absolutely necessary.
 *
 * Other plugins intend to use this event as a substitute of sorts for the PostUpdate event...they
 * don't want the MassEditTrigger event to fire when the PostUpdate event would (due to the user
 * putting a new value in the MassEdit field), because that would needlessly duplicate effort...
 * and it's easier for the controller to enforce this than the UI.
 */

namespace ODR\AdminBundle\Component\Event;

// Entities
use ODR\AdminBundle\Entity\DataRecordFields;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Symfony
use Symfony\Component\EventDispatcher\Event;


class MassEditTriggerEvent extends Event implements ODREventInterface
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
     * @var string
     */
    private $plugin_classname;


    /**
     * MassEditTriggerEvent constructor.
     *
     * @param DataRecordFields $drf
     * @param ODRUser $user
     * @param string $plugin_classname
     */
    public function __construct(
        DataRecordFields $drf,
        ODRUser $user,
        string $plugin_classname
    ) {
        $this->drf = $drf;
        $this->user = $user;
        $this->plugin_classname = $plugin_classname;
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
     * Returns the user that triggered the change to this datafield.
     *
     * @return ODRUser
     */
    public function getUser()
    {
        return $this->user;
    }


    /**
     * Returns which plugin this event should trigger.
     *
     * @return string
     */
    public function getPluginClassName()
    {
        return $this->plugin_classname;
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
