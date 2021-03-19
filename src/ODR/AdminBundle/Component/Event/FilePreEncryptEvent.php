<?php

/**
 * Open Data Repository Data Publisher
 * FilePreEncrypt Event
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This event exists because, unfortunately, there is sometimes a need to read the uploaded file so
 * values for other fields can be derived from the file's contents.  This is quite inconvenient,
 * because ODR was not originally designed with this in mind.
 *
 * Currently, File uploading happens in several steps...
 * 1) The 3rd party javascript library Flow.js POSTs chunks of files to the server
 * 2) When all chunks have been POSTed, the server recombines them into a single file
 * 3) The server creates as much of a File entity in the database as it can, leaving some temporary
 *      information in the database, such as the location of the unencrypted file
 * 4) A background process that is notified at the end of step 3 encrypts the file, moving it into
 *      the /app/crypto directory on the server and deleting the temporary unencrypted version. The
 *      background process then fixes the temporary parts of the File Entity in the database, and
 *      marks the File as ready.
 *
 * Unfortunately, reading the file to derive values could result in errors, and in theory it would
 * be useful to notify the user that errors happened...but there's no good way to get these errors
 * back to the user.
 *
 *
 * Currently, the event is set to fire right before step 4, and the single subscriber that listens
 * to this event uses other means to notify the user of problems.
 *
 * Theoretically, this placement allows API and EditController to directly notify the user...although
 * neither of those are currently set up to inform the user of anything other than success/failure,
 * and I really don't want to rewrite multiple sections of ODR so they could.  Especially because this
 * placement means files uploaded through CSVImport can't notify the user, since CSVImport is a
 * background job that happens without a "connection" to the user.
 *
 * Having the event fire after step 4 would require all File encryptions to be treated as TrackedJobs,
 * and this would also allow CSVImportController to store any errors it encountered while this event
 * got dispatched...but that would also require the Edit page to get rewritten to track the progress
 * of these event subscribers after it finishes tracking the file encryption progress...I have zero
 * desire to do either of those requirements.
 */

namespace ODR\AdminBundle\Component\Event;

// Entities
use ODR\AdminBundle\Entity\File;
// Symfony
use Symfony\Component\EventDispatcher\Event;


class FilePreEncryptEvent extends Event
{
    // Best practice is apparently to have the Event class define the event name
    const NAME = 'odr.event.file_pre_encrypt_event';

    /**
     * @var File
     */
    private $file;

    /**
     * FilePreEncryptEvent constructor.
     * TODO - modify the Event to have a "messages" array so the user can be notified of problems?
     * TODO     - ...problem with this approach being that API/CSVImport/Edit have no framework for notifying the user of problems
     * TODO - Or, modify to use Symfony's ParameterBag to notify the user, kind of like how FOSUserBundle does?
     * TODO     - ...not that that helps when uploading files through API or CSVImport, there's still no way to notify the user
     * TODO - Or, modify to treat all file encryptions as a TrackedJob so they could be tied to a TrackedError somehow?
     *
     * @param File $file
     */
    public function __construct(File $file)
    {
        $this->file = $file;
    }

    /**
     * Returns the file that has been uploaded, BUT NOT ENCRYPTED YET.
     *
     * Unlike fully encrypted files, the "provisioned" property is true and the "OriginalChecksum"
     * property is not set.  The "LocalFileName" property is the path to the file (likely somewhere
     * in /web/uploads/files/chunks/user_#/completed/), and the "OriginalFileName" property is the
     * current filename.  @see WorkerController::cryptorequestAction() for more details.
     *
     * @return File
     */
    public function getFile()
    {
        return $this->file;
    }
}
