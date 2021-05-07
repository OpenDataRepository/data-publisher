<?php

/**
 * Open Data Repository Data Publisher
 * FileDeleted Event
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This event exists because, unfortunately, there is sometimes a need to read the uploaded file so
 * values for other fields can be derived from the file's contents.  And naturally, if a file is
 * deleted, then the fields derived from the file need to be updated.  This is quite inconvenient,
 * because ODR was not originally designed with this in mind.
 */

namespace ODR\AdminBundle\Component\Event;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Symfony
use Symfony\Component\EventDispatcher\Event;


class FileDeletedEvent extends Event implements ODREventInterface
{
    // Best practice is apparently to have the Event class define the event name
    const NAME = 'odr.event.file_deleted_event';

    /**
     * @var int
     */
    private $file_id;

    /**
     * @var DataFields
     */
    private $datafield;

    /**
     * @var DataRecord
     */
    private $datarecord;

    /**
     * @var ODRUser
     */
    private $user;


    /**
     * FileDeletedEvent constructor.
     *
     * @param int $file_id
     * @param DataFields $datafield
     * @param DataRecord $datarecord
     * @param ODRUser $user
     */
    public function __construct(
        int $file_id,
        DataFields $datafield,
        DataRecord $datarecord,
        ODRUser $user
    ) {
        $this->file_id = $file_id;
        $this->datafield = $datafield;
        $this->datarecord = $datarecord;
        $this->user = $user;
    }


    /**
     * Returns the id of the file that was just deleted
     *
     * @return int
     */
    public function getFileId()
    {
        return $this->file_id;
    }

    /**
     * Returns the datafield that the file was deleted from.
     *
     * @return DataFields
     */
    public function getDatafield()
    {
        return $this->datafield;
    }


    /**
     * Returns the datarecord that the file was deleted from.
     *
     * @return DataRecord
     */
    public function getDatarecord()
    {
        return $this->datarecord;
    }


    /**
     * Returns the user that deleted the file.
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
            'file '.$this->file_id,
            'df '.$this->datafield->getId(),
            'dr '.$this->datarecord->getId(),
        );
    }
}
