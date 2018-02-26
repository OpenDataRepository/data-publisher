<?php

/**
 * Open Data Repository Data Publisher
 * Flow Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The Flow controller is originally based off the flow-php-server library,
 * but has been modified to work with Symfony's natural file handling, and
 * further modified to meed the specific needs of ODR.
 *
 * Due to the needs of the library, this controller intentionally does not use ODR's custom exceptions.
 *
 * @see https://github.com/flowjs/flow.js
 * @see https://github.com/flowjs/flow-php-server
 *
 * saveFile(), validateFile(), saveChunk(), validateChunk(), getChunkPath(), checkChunk() in particular borrow heavily from
 * @see https://github.com/flowjs/flow-php-server/blob/master/src/Flow/File.php
 */

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataType;
use ODR\OpenRepository\UserBundle\Entity\User;
// Services
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\File\File as SymfonyFile;


class FlowController extends ODRCustomController
{

    /** 
     * HTTP Status codes of 200 are interpreted by flow.js as "success"
     *
     * @param string $message
     *
     * @return Response
     */
    private function flowSuccess($message = '')
    {
        $response = new Response();
        $response->setStatusCode(200);
        $response->setContent($message);

        return $response;
    }


    /** 
     * All HTTP Status codes not specified in self::flowSuccess() and self::flowAbort() are interpreted as "continue"
     *
     * @param string $message
     *
     * @return Response
     */
    private function flowContinue($message = '')
    {
        $response = new Response();
        $response->setStatusCode(204);
        $response->setContent($message);

        return $response;
    }


    /** 
     * All HTTP Status codes not specified in self::flowSuccess() and self::flowAbort() are interpreted as "continue"
     *
     * @param string $message
     *
     * @return Response
     */
    private function flowError($message = '')
    {
        $response = new Response();
        $response->setStatusCode(503);
        $response->setContent($message);

        return $response;
    }


    /** 
     * HTTP Status codes of 404 are interpreted by flow.js as "abort"
     *
     * @param string $message
     *
     * @return Response
     */
    private function flowAbort($message = '')
    {
        $response = new Response();
        $response->setStatusCode(404);
        $response->setContent($message);

        return $response;
    }


    /**
     * Handles uploads of files via Flow.js
     * TODO - need CSRF token?
     *
     * @param string  $upload_type
     * @param integer $datatype_id
     * @param integer $datarecord_id
     * @param integer $datafield_id
     * @param Request $request
     * 
     * @return Response
     */
    public function flowAction($upload_type, $datatype_id, $datarecord_id, $datafield_id, Request $request)
    {
        try {
            // ----------------------------------------
            // Grab required objects...
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            // uploads to file/image datafields MUST have datarecord/datafield ids
            if ( ($upload_type == 'file' || $upload_type == 'image') && ($datarecord_id == 0 || $datafield_id == 0) )
                return self::flowAbort('Invalid parameters');

            // everyt other kind of upload MUST NOT have datarecord/datafield ids
            if ( !($upload_type == 'file' || $upload_type == 'image') && ($datarecord_id != 0 || $datafield_id != 0) )
                return self::flowAbort('Invalid parameters');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                return self::flowAbort('Datatype does not exist');

            // If datarecordfield is specified, ensure it exists
            $datarecord = null;
            $datafield = null;
            if ($datarecord_id != 0 && $datafield_id != 0) {
                /** @var DataRecord $datarecord */
                $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
                if ($datarecord == null)
                    return self::flowAbort('Datarecord does not exist');

                /** @var DataFields $datafield */
                $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
                if ($datafield == null)
                    return self::flowAbort('Datafield does not exist');

                if ($datarecord->getDataType()->getId() != $datatype_id || $datafield->getDataType()->getId() != $datatype_id)
                    return self::flowAbort('Parameter mismatch');
            }


            // ----------------------------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_id = $user->getId();

            // Ensure user has permissions to be doing this
            if ($upload_type == 'csv' || $upload_type == 'xml') {
                if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                    return self::flowAbort('Not allowed to upload csv/xml files for importing');
            }
            else {
                if ( !$pm_service->canEditDatarecord($user, $datarecord) )
                    return self::flowAbort('Not allowed to edit this Datarecord');
            }

            if ($datafield !== null) {
                if ( !$pm_service->canEditDatafield($user, $datafield, $datarecord) )
                    return self::flowAbort('Not allowed to edit this Datafield');

                // If user is trying to upload to a datafield that only allows a single file/image to be uploaded...
                if ( !$datafield->getAllowMultipleUploads() ) {
                    // ...ensure the datafield doesn't already have a file/image uploaded
                    if ($upload_type == 'file') {
                        $files = $em->getRepository('ODRAdminBundle:File')->findBy( array('dataRecord' => $datarecord->getId(), 'dataField' => $datafield->getId()) );
                        if ( count($files) > 0 )
                            return self::flowAbort('This Datafield already has a file uploaded to it');
                    }
                    else if ($upload_type == 'image') {
                        $images = $em->getRepository('ODRAdminBundle:Image')->findBy( array('dataRecord' => $datarecord->getId(), 'dataField' => $datafield->getId(), 'original' => 1) );
                        if ( count($images) > 0 )
                            return self::flowAbort('This Datafield already has an image uploaded to it');
                    }
                }
            }


            // ----------------------------------------
            // Load file validation parameters
            $validation_params = $this->container->getParameter('file_validation');
            switch ($upload_type) {
                case 'xml':
                    $validation_params = $validation_params['xml'];
                    break;
                case 'csv':
                    $validation_params = $validation_params['csv'];
                    break;
                case 'file':
                    $validation_params = $validation_params['file'];
                    break;
                case 'image':
                    $validation_params = $validation_params['image'];
                    break;
                case 'csv_import_file_storage':
                case 'xml_import_file_storage':
                    $maxsize = max( intval($validation_params['file']['maxSize']), intval($validation_params['image']['maxSize']) );
                    $validation_params = array(
                        'maxSize' => $maxsize,
                        'maxSizeErrorMessage' => 'The uploaded file is too large.  Allowed maximum size is '.$maxsize.' MB.',
//                        'mimeTypes' => array_unique( array_merge($validation_params['file']['mimeTypes'], $validation_params['image']['mimeTypes']) ),
                        'mimeTypes' => array(),
                        'mimeTypesErrorMessage' => 'Please upload a valid file for later importing.',   // TODO
                    );
                    break;
            }


            // ----------------------------------------
            if ($request->getRealMethod() == 'GET') {
                // Extract useful info from the GET query
                $identifier = $request->query->get('flowIdentifier');
                $index = $request->query->get('flowChunkNumber');
                $expected_size = intval( $request->query->get('flowTotalSize') );

                $allowed_filesize = intval( $validation_params['maxSize'] );

                if ( $expected_size > ($allowed_filesize * 1024 * 1024) ) {
                    // TODO - delete uploaded chunks on abort/cancel?
                    // Expected filesize is too big, don't continue to upload
                    return self::flowAbort( $validation_params['maxSizeErrorMessage'] );
                }
                else if ( self::checkChunk($user_id, $identifier, $index) ) {
                    // Chunk exists
                    return self::flowSuccess();
                }
                else {
                    // Chunk does not exist...(re)upload chunk
                    return self::flowContinue();
                }
            }
            else {
                // Extract the uploaded file and other required information from the POST request
                $uploaded_file = $request->files->get('file');

                // Validate properties of the POST...
                $post = $request->request;
                $chunk_number = intval( $post->get('flowChunkNumber') );
                $total_chunks = intval( $post->get('flowTotalChunks') );
                $expected_size = intval( $post->get('flowTotalSize') );
                $current_chunk_size = intval( $post->get('flowCurrentChunkSize') );
                $identifier = $post->get('flowIdentifier');
                $original_filename = $post->get('flowFilename');

                $allowed_filesize = intval( $validation_params['maxSize'] );

                if ( $expected_size > ($allowed_filesize * 1024 * 1024) ) {
                    // Expected filesize is too big, don't continue to upload
                    return self::flowAbort( $validation_params['maxSizeErrorMessage'] );
                }
                else if ( self::validateChunk($uploaded_file, $current_chunk_size) ) {
                    // ...no errors found, move uploaded chunk to storage directory
                    self::saveChunk($user_id, $uploaded_file, $identifier, $chunk_number);
                }
                else {
                    // ...some non-fatal error found, instruct flow.js to re-attempt upload
                    return self::flowError();
                }
            }

            // Check whether file is uploaded completely and properly
            $path_prefix = $this->getParameter('odr_web_directory').'/';
            $destination_folder = 'uploads/files/chunks/user_'.$user_id.'/completed';
            if ( !file_exists($path_prefix.$destination_folder) )
                mkdir( $path_prefix.$destination_folder );

            $destination = $path_prefix.$destination_folder.'/'.$original_filename;

            if ( self::validateFile($user_id, $identifier, $total_chunks, $expected_size) && self::saveFile($user_id, $identifier, $total_chunks, $destination) ) {
                // All file chunks sucessfully uploaded and spliced back together
                $uploaded_file = new SymfonyFile($destination);

                // Don't have to check filesize again...the sum of the sizes of the uploaded chunks have to match $expected_size, and too large of a file would be caught earlier

                // Have Symfony check mimetype now that file is uploaded...
                if ( count($validation_params['mimeTypes']) > 0 && !in_array($uploaded_file->getMimeType(), $validation_params['mimeTypes']) ) {
                    $mimetype = $uploaded_file->getMimeType();

                    // Not allowed to upload file...delete it
                    unlink( $destination );

                    // Instruct flow.js to abort
                    return self::flowAbort( 'You attempted to upload a file with mimetype "'.$mimetype.'", '.$validation_params['mimeTypesErrorMessage'] );
                }

                if ($upload_type == 'csv') {
                    // Upload is a CSVImport file
                    self::finishCSVUpload($path_prefix.$destination_folder, $original_filename, $user_id, $request);
                }
                else if ($upload_type == 'xml') {
                    // Upload is an XMLImport file
                    self::finishXMLUpload($path_prefix.$destination_folder, $original_filename, $user_id, $request);
                }
                else if ($datarecord_id != 0 && $datafield_id != 0) {
                    // Upload meant for a file/image datafield...finish moving the uploaded file and store it properly
                    $drf = parent::ODR_addDataRecordField($em, $user, $datarecord, $datafield);
                    parent::finishUpload($em, $destination_folder, $original_filename, $user_id, $drf->getId());

                    // Mark this datarecord as updated
                    /** @var DatarecordInfoService $dri_service */
                    $dri_service = $this->container->get('odr.datarecord_info_service');
                    $dri_service->updateDatarecordCacheEntry($datarecord, $user);
                }
                else {
                    // Upload is a file/image meant to be referenced by a later XML/CSV Import
                    $uploaded_file->move( $path_prefix.$destination_folder, $original_filename );
                    self::finishImportFileUpload($path_prefix.$destination_folder, $original_filename, $user_id, $upload_type);
                }

                // Return success
                return self::flowSuccess('File uploaded successfully');
            }
            else {
                // No action required, continue to upload/re-upload chunks
                return self::flowSuccess();
            }

        }
        catch (\Exception $e) {
            return self::flowError( $e->getMessage() );     // TODO - this will let flow.js continue trying to upload...should it abort instead?
        }
    }


    /**
     * Moves the specified file from the upload directory to the user's CSVImport directory.
     *
     * @param string $filepath             The absolute path to the file
     * @param string $original_filename    The original name of the file
     * @param integer $user_id             Which user is doing the uploading
     * @param Request $request
     *
     */
    private function finishCSVUpload($filepath, $original_filename, $user_id, Request $request)
    {
        // Grab the uploaded file at its current location
        $csv_file = new SymfonyFile($filepath.'/'.$original_filename);

        // Ensure a CSVImport directory exists for this user
        $destination_folder = $this->getParameter('odr_web_directory').'/uploads/csv';
        if ( !file_exists($destination_folder) )
            mkdir( $destination_folder );
        $destination_folder .= '/user_'.$user_id;
        if ( !file_exists($destination_folder) )
            mkdir( $destination_folder );

        // Splice a timestamp into the filename
        $final_filename = $original_filename.'.'.time();

        // Move the file from its current location to the correct CSVImport directory
        $csv_file->move($destination_folder, $final_filename);

        // Save the new filename in the user's session
        $session = $request->getSession();
        $session->set('csv_file', $final_filename);
    }


    /**
     * Moves the specified file from the upload directory to the user's XMLImport directory.
     *
     * @param string $filepath             The absolute path to the file
     * @param string $original_filename    The original name of the file
     * @param integer $user_id             Which user is doing the uploading
     * @param Request $request
     *
     */
    private function finishXMLUpload($filepath, $original_filename, $user_id, Request $request)
    {
        // Grab the uploaded file at its current location
        $xml_file = new SymfonyFile($filepath.'/'.$original_filename);

        // Ensure an XMLImport directory exists for this user
        $destination_folder = $this->getParameter('odr_web_directory').'/uploads/xml';
        if ( !file_exists($destination_folder) )
            mkdir( $destination_folder );
        $destination_folder .= '/user_'.$user_id;
        if ( !file_exists($destination_folder) )
            mkdir( $destination_folder );
        $destination_folder .= '/unprocessed';
        if ( !file_exists($destination_folder) )
            mkdir( $destination_folder );

        // Splice a timestamp into the filename
        $final_filename = $original_filename.'.'.time();

        // Move the file from its current location to the correct XMLImport directory
        $xml_file->move($destination_folder, $final_filename);

        // Save the new filename in the user's session
//        $session = $request->getSession();
//        $session->set('csv_file', $final_filename);
    }


    /**
     * Moves the specified file from the upload directory to the directory used for storing files/images referenced as part of a CSV/XML Import...
     *
     * @param string $filepath          The absolute path to the file
     * @param string $original_filename The original name of the file
     * @param integer $user_id          Which user is doing the uploading
     * @param string $upload_type       csv|xml
     *
     */
    private function finishImportFileUpload($filepath, $original_filename, $user_id, $upload_type)
    {
        // Grab the uploaded file at its current location
        $uploaded_file = new SymfonyFile($filepath.'/'.$original_filename);

        // Determine which directory structure to switch to
        $type = '';
        if ($upload_type == 'csv_import_file_storage')
            $type = 'csv';
        else if ($upload_type == 'xml_import_file_storage')
            $type = 'xml';

        // Ensure a CSV/XML Import directory exists for this user
        $destination_folder = $this->getParameter('odr_web_directory').'/uploads/'.$type;
        if ( !file_exists($destination_folder) )
            mkdir( $destination_folder );
        $destination_folder .= '/user_'.$user_id;
        if ( !file_exists($destination_folder) )
            mkdir( $destination_folder );
        $destination_folder .= '/storage';
        if ( !file_exists($destination_folder) )
            mkdir( $destination_folder );
        
        // Move the file from its current location to the correct CSV/XML Import directory
        $uploaded_file->move($destination_folder, $original_filename);
    }


    /**
     * Splices all chunks of a specific file into a single complete file.
     *
     * @throws \Exception
     *
     * @param integer $user_id
     * @param string $identifier
     * @param integer $total_chunks
     * @param string $destination
     *
     * @return boolean
     */
    private function saveFile($user_id, $identifier, $total_chunks, $destination)
    {
        // Open destination file
        $handle = fopen($destination, 'wb');
        if (!$handle)
            throw new \Exception('failed to open destination file: '.$destination);

        // Get locks on destination file
        if (!flock($handle, LOCK_EX | LOCK_NB, $blocked)) {
            // @codeCoverageIgnoreStart
            if ($blocked) {
                // Concurrent request has requested a lock.
                // File is being processed at the moment.
                // Warning: lock is not checked in windows.
                return false;
            }
            // @codeCoverageIgnoreEnd
            throw new \Exception('failed to lock file: '.$destination);
        }

        try {
            // Splice together all of the chunks of this specific file
            for ($i = 1; $i <= $total_chunks; $i++) {
                $file = self::getChunkPath($user_id, $identifier, $i);
                $chunk = fopen($file, "rb");

                if (!$chunk)
                    throw new \Exception('failed to open chunk: '.$file);

                stream_copy_to_stream($chunk, $handle);
                fclose($chunk);
            }
        }
        catch (\Exception $e) {
            // Release locks
            flock($handle, LOCK_UN);
            fclose($handle);

            throw $e;
        }

        // File completely uploaded, delete intermediary chunks
        for ($i = 1; $i <= $total_chunks; $i++) {
            $file = self::getChunkPath($user_id, $identifier, $i);

            if ( file_exists($file) )
                unlink( $file );
        }

        // Release locks
        flock($handle, LOCK_UN);
        fclose($handle);

        return true;
    }


    /**
     * Returns whether the size of the uploaded chunks equals the expected size of the complete file being uploaded.
     *
     * @param integer $user_id
     * @param string $identifier
     * @param integer $total_chunks
     * @param integer $expected_size
     *
     * @return boolean
     */
    private function validateFile($user_id, $identifier, $total_chunks, $expected_size)
    {
        $actual_size = 0;

        for ($i = 1; $i <= $total_chunks; $i++) {
            // Ensure each chunk exists
            $chunk_file = self::getChunkPath($user_id, $identifier, $i);
            if ( !file_exists($chunk_file) )
                return false;

            // Keep a running total of how large the chunks are
            $actual_size += filesize($chunk_file);
        }

        // If the size of the uploaded chunks doesn't equal the expected size of the file, then something went wrong
        if ( $actual_size !== $expected_size )
            return false;

        return true;
    }


    /**
     * Moves an uploaded chunk from the tmp upload directory to its proper storage place on the server.
     *
     * @param integer $user_id
     * @param UploadedFile $file
     * @param string $identifier
     * @param integer $index
     */
    private function saveChunk($user_id, $file, $identifier, $index)
    {
        // Determine where the uploaded chunk should go, and break the path apart for UploadedFile::move()
        $destination = self::getChunkPath($user_id, $identifier, $index);
        $filepath = substr( $destination, 0, strrpos($destination, '/') );
        $filename = substr( $destination, strrpos($destination, '/')+1 );

        // Move the uploaded chunk to the correct spot
        $file->move($filepath, $filename);
    }


    /**
     * Returns whether an uploaded chunk conforms to expectations.
     *
     * @param UploadedFile $file
     * @param integer $current_chunk_size
     *
     * @return boolean
     */
    private function validateChunk($file, $current_chunk_size)
    {
        if ($file->getClientSize() !== $current_chunk_size)
            return false;

        if ($file->getError() !== 0)
            return false;

        if ($file->isValid() == 0)
            return false;

        return true;
    }


    /**
     * Returns the complete path to a specific chunk.
     *
     * @param integer $user_id
     * @param string $identifier
     * @param string $index
     *
     * @return string
     */
    private function getChunkPath($user_id, $identifier, $index)
    {
        $chunk_upload_path = $this->getParameter('odr_web_directory').'/uploads/files/chunks/user_'.$user_id;
        if ( !file_exists($chunk_upload_path) )
            mkdir( $chunk_upload_path );

        return $chunk_upload_path.'/'.$identifier.'_'.$index;
    }


    /**
     * Returns whether a specific chunk already exists on the server or not.
     *
     * @param integer $user_id
     * @param string $identifier
     * @param string $index
     *
     * @return boolean
     */
    private function checkChunk($user_id, $identifier, $index)
    {
        return file_exists( self::getChunkPath($user_id, $identifier, $index) );
    }

}
