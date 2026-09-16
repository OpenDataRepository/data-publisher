<?php

/**
 * Open Data Repository Data Publisher
 * FilePostUpload Event
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * After ODR got changed to no longer encrypt files in Sept/Oct 2026, the previous FilePreEncrypt
 * and FilePostEncrypt events became meaningless.
 *
 * File uploading happens in several steps:
 * 1) The 3rd party javascript library Flow.js POSTs chunks of files to the server
 * 2) ODRAdminBundle:FlowController receives these POSTs, and saves them in
 *      %odr_tmp_directory%/user_X/chunks/*
 * 3) Once all chunks are uploaded, ODRAdminBundle:FlowController recombines the chunks into a single
 *      file at %odr_tmp_directory%/user_X/chunks/completed/<original_filename>
 * 4) ODRAdminBundle:ODRUploadService then moves the file into %odr_tmp_directory%/crypto_dir/File_Y/enc.*
 *
 * Originally, crypto_dir had a directory of an encrypted version of each file, but nowadays it just
 * stores unencrypted files in a directory that isn't web-accessible.  If the file is public, then TODO
 */

namespace ODR\AdminBundle\Component\Event;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Entity\Image;
// Symfony
use Symfony\Component\EventDispatcher\Event;


class FilePostUploadEvent extends \Symfony\Contracts\EventDispatcher\Event implements ODREventInterface
{
    // Best practice is apparently to have the Event class define the event name
    const NAME = 'odr.event.file_post_upload_event';


    /**
     * FilePostUploadEvent constructor.
     *
     * @param File|Image $file
     * @param DataFields $datafield
     */
    public function __construct(
        private File|Image $file,
        private readonly DataFields $datafield
    ) {
    }


    /**
     * Returns the file or image that has been uploaded.
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

        return [
            self::NAME,
            $typeclass.' '.$this->file->getId(),
            'df '.$this->datafield->getId(),
        ];
    }
}
