<?php

/**
 * Open Data Repository Data Publisher
 * PluginOptionsChangedEvent Event
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This event is dispatched when a user changes a RenderPluginOption or a RenderPluginMap value,
 * since several render plugins need to do things when those happen.
 */

namespace ODR\AdminBundle\Component\Event;

// Entities
use ODR\AdminBundle\Entity\RenderPluginInstance;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Symfony
use Symfony\Component\EventDispatcher\Event;


class PluginOptionsChangedEvent extends Event implements ODREventInterface
{
    // Best practice is apparently to have the Event class define the event name
    const NAME = 'odr.event.plugin_options_changed_event';

    /**
     * @var RenderPluginInstance
     */
    private $render_plugin_instance;

    /**
     * @var ODRUser
     */
    private $user;


    /**
     * PluginOptionsChangedEvent constructor.
     *
     * @param RenderPluginInstance $render_plugin_instance
     * @param ODRUser $user
     */
    public function __construct(
        RenderPluginInstance $render_plugin_instance,
        ODRUser $user
    ) {
        $this->render_plugin_instance = $render_plugin_instance;
        $this->user = $user;
    }


    /**
     * Returns the RenderPluginInstance entry that got modified.
     *
     * @return RenderPluginInstance
     */
    public function getRenderPluginInstance()
    {
        return $this->render_plugin_instance;
    }

    /**
     * Returns the user that made the change.
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
            'rpi '.$this->render_plugin_instance->getId(),
        );
    }
}
