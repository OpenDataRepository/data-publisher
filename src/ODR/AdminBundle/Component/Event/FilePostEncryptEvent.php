<?php

/**
 * Open Data Repository Data Publisher
 * FilePostEncrypt Event
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This event exists primarily because the original method ODR used for generating graphs...where
 * browsers would request graph images based on what the graph plugin returned, and would immediately
 * trigger phantomJS to render the graph if didn't exist...was no longer really working right.
 * This is partially because it was effectively DDOS'ing the server, and partially because Chrome
 * Puppeteer isn't quite as blase about its job as phantomJS is.
 *
 * Having to switch from an "on-demand" graph rendering to a "pre-render" system isn't all sunshine
 * and rainbows, however...the "on-demand" system was particularly convenient because it offloaded
 * any permissions checking to ODR's rendering system, and "pre-rendering" not only has to (kinda)
 * care about permissions, but it also has to deal with requests that happen for a graph that is
 * scheduled to be rendered.  Apparently Puppeteer takes ~200ms per graph, which isn't bad, but it
 * adds up when you need to deal with thousands of them.
 *
 *
 * Additionally, this event has a big caveat...encryption is a 100% detached/async background process.
 * There's no connection/link/promise to send any info back at all, and it could be quite some time
 * before there's something to send back in the first place, depending on server load.
 *
 * As such, this event really doesn't work for anything where a user is actually waiting on a result.
 * It only barely makes sense for "pre-rendering" graph stuff because that's a background process
 * that can (hopefully) do its thing whenever.
 *
 *
 * The docs for FilePreEncryptEvent may also be of interest.
 */

namespace ODR\AdminBundle\Component\Event;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Entity\Image;
// Symfony
use Symfony\Component\EventDispatcher\Event;


class FilePostEncryptEvent extends Event implements ODREventInterface
{
    // Best practice is apparently to have the Event class define the event name
    const NAME = 'odr.event.file_post_encrypt_event';

    /**
     * @var File|Image
     */
    private $file;

    /**
     * @var DataFields
     */
    private $datafield;


    /**
     * FilePostEncryptEvent constructor.
     *
     * @param File|Image $file
     * @param DataFields $datafield
     */
    public function __construct(
        $file,
        DataFields $datafield
    ) {
        $this->file = $file;
        $this->datafield = $datafield;
    }


    /**
     * Returns the file or image that just been encrypted.
     *
     * Unlike files handled by the FilePreEncryptEvent, this file should work like it does in the
     * rest of ODR.
     *
     * @return File|Image
     */
    public function getFile()
    {
        return $this->file;
    }


    /**
     * Returns which datafield the file has been uploaded into.
     *
     * @return DataFields
     */
    public function getDatafield()
    {
        return $this->datafield;
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
        $typeclass = $this->datafield->getFieldType()->getTypeClass();

        return array(
            self::NAME,
            $typeclass.' '.$this->file->getId(),
            'df '.$this->datafield->getId(),
        );
    }
}
