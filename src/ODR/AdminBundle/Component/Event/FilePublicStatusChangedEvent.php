<?php

/**
 * Open Data Repository Data Publisher
 * FilePublicStatusChanged Event
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This event is fired when a File changes its public status.
 */

namespace ODR\AdminBundle\Component\Event;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Entity\Image;
// Symfony
use Symfony\Component\EventDispatcher\Event;


class FilePublicStatusChangedEvent extends Event implements ODREventInterface
{
    // Best practice is apparently to have the Event class define the event name
    const NAME = 'odr.event.file_public_status_changed_event';

    /**
     * @var File|Image
     */
    private $file;

    /**
     * @var DataFields
     */
    private $datafield;

    /**
     * @var string
     */
    private $context;


    /**
     * FilePublicStatusChangedEvent constructor.
     *
     * @param File|Image $file
     * @param DataFields $datafield
     * @param string $context
     */
    public function __construct(
        $file,
        DataFields $datafield,
        string $context
    ) {
        $this->file = $file;
        $this->datafield = $datafield;
        $this->context = $context;
    }


    /**
     * Returns the file or image that just been modified.
     *
     * @return File|Image
     */
    public function getFile()
    {
        return $this->file;
    }


    /**
     * Returns which datafield the file belongs to.
     *
     * @return DataFields
     */
    public function getDatafield()
    {
        return $this->datafield;
    }


    /**
     * Returns the context the event was fired in.
     *
     * @return string
     */
    public function getContext()
    {
        return $this->context;
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
            'called from '.$this->context,
        );
    }
}
