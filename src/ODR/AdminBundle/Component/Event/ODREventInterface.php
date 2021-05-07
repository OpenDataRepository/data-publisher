<?php

/**
 * Open Data Repository Data Publisher
 * ODREventInterface
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * All ODR events must implement this interface.
 */

namespace ODR\AdminBundle\Component\Event;

interface ODREventInterface
{
    /**
     * Returns the event name
     *
     * @return string
     */
    public function getEventName();


    /**
     * Returns identifying information so that ODREventSubscriber can log more descriptive errors
     * if required.
     *
     * @return string[]
     */
    public function getErrorInfo();
}
