<?php

/**
 * Open Data Repository Data Publisher
 * Crypto Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Contains the functions to encrypt/decrypt files and images.
 */

namespace ODR\AdminBundle\Component\Service;

// Entities
use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Entity\FileChecksum;
use ODR\AdminBundle\Entity\Image;
use ODR\AdminBundle\Entity\ImageChecksum;
// Events
use ODR\AdminBundle\Component\Event\DatafieldModifiedEvent;
use ODR\AdminBundle\Component\Event\DatarecordModifiedEvent;
use ODR\AdminBundle\Component\Event\FilePostEncryptEvent;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Other
use Doctrine\ORM\EntityManager;
use dterranova\Bundle\CryptoBundle\Crypto\CryptoAdapter;
use JMS\SecurityExtraBundle\Security\Util\SecureRandom;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;


class CryptoService
{

    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var LockService
     */
    private $lock_service;

    /**
     * @var EventDispatcherInterface
     */
    private $event_dispatcher;

    // NOTE - $event_dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
    //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode

    /**
     * @var SecureRandom
     */
    private $generator;

    /**
     * @var CryptoAdapter
     */
    private $crypto_adapter;

    /**
     * @var string
     */
    private $crypto_dir;

    /**
     * @var string
     */
    private $odr_web_dir;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * CryptoService constructor
     *
     * @param EntityManager $entity_manager
     * @param LockService $lock_service
     * @param EventDispatcherInterface $event_dispatcher
     * @param SecureRandom $generator
     * @param CryptoAdapter $crypto_adapter
     * @param string $crypto_dir
     * @param string $odr_web_dir
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        LockService $lock_service,
        EventDispatcherInterface $event_dispatcher,
        SecureRandom $generator,
        CryptoAdapter $crypto_adapter,
        string $crypto_dir,
        string $odr_web_dir,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->lock_service = $lock_service;
        $this->event_dispatcher = $event_dispatcher;
        $this->generator = $generator;
        $this->crypto_adapter = $crypto_adapter;
        $this->crypto_dir = realpath($crypto_dir);
        $this->odr_web_dir = realpath($odr_web_dir);
        $this->logger = $logger;
    }


    /**
     * Wrapper function to set up decryption of a specific File.  This function can't determine if
     * the File is public or not, so the caller needs to delete the decrypted version of non-public
     * Files off the server afterwards.
     *
     * @param int $file_id
     * @param string $target_filename
     *
     * @throws ODRNotFoundException
     *
     * @return string The path to the decrypted File
     */
    public function decryptFile($file_id, $target_filename = '')
    {
        // Load the file that needs decrypting
        /** @var File $file */
        $file = $this->em->getRepository('ODRAdminBundle:File')->find($file_id);
        if ($file == null)
            throw new ODRNotFoundException('File');
        if ($file->getEncryptKey() === '')
            throw new ODRNotFoundException('File');

        if ($target_filename == '')
            $target_filename = 'File_'.$file->getId().'.'.$file->getExt();

        // Determine where the encrypted file is stored
        $crypto_chunk_dir = $this->crypto_dir.'/File_'.$file_id;

        // Determine where the decrypted file is supposed to go
        $base_filepath = $this->odr_web_dir.'/'.$file->getUploadDir();

        // NOTE - this will put non-public files in the web-accessible directory...in theory, it
        //  could put the file into ODR's tmp directory to guarantee nobody without permissions can
        //  download it, but then phantomJS wouldn't be able to access non-public files used for
        //  building graphs...

        // Decrypt the file
//        $this->logger->debug('CryptoService.php: Attempting to decrypt file '.$file_id.' to "'.$base_filepath.'/'.$target_filename.'"');
        return self::decryptworker($crypto_chunk_dir, $file->getEncryptKey(), $base_filepath, $target_filename);
    }


    /**
     * Wrapper function to set up decryption of a specific Image.  This function can't determine if
     * the Image is public or not, so the caller needs to delete the decrypted version of non-public
     * Images off the server afterwards.
     *
     * @param int $image_id
     * @param string $target_filename
     *
     * @throws ODRNotFoundException
     *
     * @return string The path to the decrypted Image
     */
    public function decryptImage($image_id, $target_filename = '')
    {
        // Load the image that needs decrypting
        /** @var Image $image */
        $image = $this->em->getRepository('ODRAdminBundle:Image')->find($image_id);
        if ($image == null)
            throw new ODRNotFoundException('Image');
        if ($image->getEncryptKey() === '')
            throw new ODRNotFoundException('Image');

        if ($target_filename == '')
            $target_filename = 'Image_'.$image->getId().'.'.$image->getExt();

        // Determine where the encrypted file is stored
        $crypto_chunk_dir = $this->crypto_dir.'/Image_'.$image_id;

        // Determine where the decrypted file is supposed to go
        $base_filepath = $this->odr_web_dir.'/'.$image->getUploadDir();

        // NOTE - this will put non-public images in the web-accessible directory...in theory, it
        //  could put the image into ODR's tmp directory to guarantee nobody without permissions can
        //  download it...but doing it this way to match file decryption

        // Decrypt the image
//        $this->logger->debug('CryptoService.php: Attempting to decrypt image '.$image_id.' to "'.$base_filepath.'/'.$target_filename.'"');
        return self::decryptworker($crypto_chunk_dir, $image->getEncryptKey(), $base_filepath, $target_filename);
    }


    /**
     * Decrypts a File/Image and stores it in the specified zip archive.
     *
     * @param string $object_type Either 'file' or 'image'
     * @param int $object_id
     * @param string $local_filename The name the decrypted file/image should have on the server
     * @param string $desired_filename The name the file/image should have in the archive
     * @param string $archive_filepath The full path to the archive to store this file in
     *
     * @throws ODRException
     */
    public function decryptObjectForArchive($object_type, $object_id, $local_filename, $desired_filename, $archive_filepath)
    {
        // Sanity check...
        $object_type = strtolower($object_type);
        if ( $object_type !== 'file' && $object_type !== 'image')
            throw new ODRBadRequestException('Invalid object_type');

        // Attempt to open the specified zip archive
        // IMPORTANT: the following lines exist to fix a bug in like libzip 1.6 and earlier, where \ZipArchive::CREATE would whine that the archive doesn't exist
        // IMPORTANT: however, prod is using libzip 1.7.x, and the following lines instead cause \ZipArchive::CREATE to complain the archive is invalid or unintialized
        // IMPORTANT: this behavior, obviously is contradictory
//        $handle = fopen($archive_filepath, 'c');    // create file if it doesn't exist, otherwise do not fail and position pointer at beginning of file
//        if (!$handle)
//            throw new ODRException('unable to open "'.$archive_filepath.'" for writing');

        // Infer whether the specified file/image is public or not based on the fielename it's being
        //  decrypted to, and then ensure the file/image is decrypted prior to acquiring a lock on
        //  the archive
        $is_public = true;
        $local_filepath = '';
        if ($object_type === 'file') {
            if ( strpos($local_filename, 'File_'.$object_id) === false )    // TODO - better way of doing this?
                $is_public = false;

            $local_filepath = self::decryptFile($object_id, $local_filename);
        }
        else if ($object_type === 'image') {
            if ( strpos($local_filename, 'Image_'.$object_id) === false )    // TODO - better way of doing this?
                $is_public = false;

            $local_filepath = self::decryptImage($object_id, $local_filename);
        }


        // Acquire a lock on this zip archive so that multiple processes don't clobber each other
        $offset = strrpos($archive_filepath, '/') + 1;
        $lockpath = substr($archive_filepath, $offset);

        $lockHandler = $this->lock_service->createLock($lockpath.'.lock', 15);    // 15 second ttl
        if ( !$lockHandler->acquire() ) {
            // Another process is in the mix...block until it finishes
            $lockHandler->acquire(true);
        }

        // Open the archive for appending, or create if it doesn't exist
        $zip_archive = new \ZipArchive();
        $zip_archive->open($archive_filepath, \ZipArchive::CREATE);

        // Add the specified file to the zip archive
        $zip_archive->addFile($local_filepath, $desired_filename);
        $zip_archive->close();

        // Delete decrypted version of non-public files off the server
        if ( !$is_public )
            unlink($local_filepath);


        // Release the previously acquired lock
        $lockHandler->release();
    }


    /**
     * Does the work of decrypting a File or Image.
     *
     * @param string $crypto_chunk_dir The directory containing the encoded chunk files
     * @param string $key The hex string representation that the file was encrypted with
     * @param string $base_filepath The directory to store the decrypted file in
     * @param string $target_filename The filename of the decrypted file
     *
     * @throws ODRException
     *
     * @return string
     */
    private function decryptworker($crypto_chunk_dir, $key, $base_filepath, $target_filename)
    {
        // Store where to decrypt the File/Image to
        $local_filepath = $base_filepath.'/'.$target_filename;

        // Don't decrypt the file if it already exists on the server
        if ( file_exists($local_filepath) )
            return $local_filepath;


        // Otherwise, try to only allow one process to decrypt the file at a time
        $object_key = pathinfo($crypto_chunk_dir, PATHINFO_BASENAME);
        $lockHandler = $this->lock_service->createLock($object_key.'_decryption');
        if ( !$lockHandler->acquire() ) {
            // Another process is attempting to decrypt this file...block until it finishes
            $lockHandler->acquire(true);
        }
        else {
            // Convert the hex string representation of the file's encryption key into binary
            $key = hex2bin($key);

            // Open the target file
            $handle = fopen($local_filepath, "wb");
            if (!$handle)
                throw new ODRException('Unable to open "'.$local_filepath.'" for writing');

            // Decrypt each chunk and write to target file
            $chunk_id = 0;
            while (file_exists($crypto_chunk_dir.'/'.'enc.'.$chunk_id)) {
                if ( !file_exists($crypto_chunk_dir.'/'.'enc.'.$chunk_id) ) {
                    // Error encoutered...delete any partially decrypted data
                    fclose($handle);
                    if ( file_exists($local_filepath) )
                        unlink($local_filepath);

                    // Ensure the lock is released too
                    $lockHandler->release();
                    throw new ODRException('Encrypted chunk not found: '.$crypto_chunk_dir.'/'.'enc.'.$chunk_id);
                }

                $data = file_get_contents($crypto_chunk_dir.'/'.'enc.'.$chunk_id);
                $decrypted_data = $this->crypto_adapter->decrypt($data, $key);
                if ( $decrypted_data === false ) {
                    // Error encoutered...delete any partially decrypted data
                    fclose($handle);
                    if ( file_exists($local_filepath) )
                        unlink($local_filepath);

                    // Ensure the lock is released too
                    $lockHandler->release();
                    throw new ODRException('Unable to decrypt chunk: '.$crypto_chunk_dir.'/'.'enc.'.$chunk_id);
                }

                fwrite($handle, $decrypted_data);
                $chunk_id++;
            }

            // Now that the file is decrypted, release the lock on it
            $lockHandler->release();
        }

        return $local_filepath;
    }


    /**
     * Wrapper function to set up the encryption of a specific File.
     *
     * IMPORTANT: Unlike images, this function marks the datarecord as updated...this typically gets
     * called at the end of a beanstalk job, so the caller can't really fire off the event itself.
     *
     * @param int $file_id The id of the ODR File entity that will store info about this file
     * @param string $current_filepath The path to the un-encrypted file on the server
     */
    public function encryptFile($file_id, $current_filepath)
    {
        // Load the file that needs encrypting
        /** @var File $file */
        $file = $this->em->getRepository('ODRAdminBundle:File')->find($file_id);
        if ($file == null)
            throw new ODRNotFoundException('File');

        // Only encrypt a File if it hasn't been encrypted yet
        if ( $file->getOriginalChecksum() !== '' )
            throw new ODRBadRequestException('The File is already encrypted');

        // Ensure the file exists on the server before trying to encrypt it
        if ( !file_exists($current_filepath) )
            throw new ODRNotFoundException('The uploaded file does not exist', true);


        // Ensure the file is named in "File_<id>.<ext>" format, so that the crypto bundle uses that
        //  same format inside the encryption directory
        $new_filename = 'File_'.$file->getId().'.'.$file->getExt();

        $dirname = pathinfo($current_filepath, PATHINFO_DIRNAME);
        rename($current_filepath, $dirname.'/'.$new_filename);
        // Convert the renamed file into an absolute path for the crypto bundle
        $absolute_filepath = realpath($dirname.'/'.$new_filename);

        // Might as well set the correct localFilename here
        $file->setLocalFileName( $file->getUploadDir().'/'.$new_filename );
        // Also should set the checksum of the File before the encryption process deletes it
        $file->setOriginalChecksum( md5_file($absolute_filepath) );

        $this->em->persist($file);


        // ----------------------------------------
        // Encrypt the file
        self::encryptworker($file, $absolute_filepath);

        // If the file is supposed to be public...
        if ( $file->isPublic() ) {
            // ...then ensure it's decrypted after the encryption process
            self::decryptFile($file_id);
        }

        // Update the cached version of the datarecord so whichever controller is handling the
        //  "are you done encrypting yet?" javascript requests can return the correct HTML
        // TODO - ...I'm pretty sure this javascript request no longer exists?  regardless, datarecord should be updated here
        $datarecord = $file->getDataRecord();
        $datafield = $file->getDataField();
        $user = $file->getCreatedBy();

        try {
            $event = new DatafieldModifiedEvent($datafield, $user);
            $this->event_dispatcher->dispatch(DatafieldModifiedEvent::NAME, $event);
        }
        catch (\Exception $e) {
            // ...don't want to rethrow the error since it'll interrupt everything after this
            //  event
//            if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                throw $e;
        }

        try {
            // Do NOT mark the record as updated in the database after encryption finished...stuff
            //  was already marked as updated when the initial upload was completed
            $event = new DatarecordModifiedEvent($datarecord, $user, false);
            $this->event_dispatcher->dispatch(DatarecordModifiedEvent::NAME, $event);
        }
        catch (\Exception $e) {
            // ...don't want to rethrow the error since it'll interrupt everything after this
            //  event
//            if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                throw $e;
        }

        // Need this event to be after DatarecordModified, to ensure that cache entries aren't stale...
        try {
            $event = new FilePostEncryptEvent($file, $datafield);
            $this->event_dispatcher->dispatch(FilePostEncryptEvent::NAME, $event);
        }
        catch (\Exception $e) {
            // ...don't want to rethrow the error since it'll interrupt everything after this
            //  event
//            if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                throw $e;
        }
    }


    /**
     * Wrapper function to set up the encryption of a specific Image.
     *
     * IMPORTANT: unlike files, this function does not mark the datarecord as updated when done.
     * If this function fired off a DatarecordModified event, then there would always be at least
     * two of those events fired...one for the "original" image, and another for the "thumbnail".
     * More than one event is undesirable, so whatever calls this function needs to fire off the
     * event instead.
     *
     * @param int $image_id The id of the ODR Image entity that will store info about this image
     * @param string $current_filepath The path to the un-encrypted image on the server
     */
    public function encryptImage($image_id, $current_filepath)
    {
        // Load the image that needs encrypting
        /** @var Image $image */
        $image = $this->em->getRepository('ODRAdminBundle:Image')->find($image_id);
        if ($image == null)
            throw new ODRNotFoundException('Image');

        // Only encrypt an Image if it hasn't been encrypted yet
        if ( $image->getOriginalChecksum() !== '' )
            throw new ODRBadRequestException('The Image is already encrypted');

        // Ensure the image exists on the server before trying to encrypt it
        if ( !file_exists($current_filepath) )
            throw new ODRNotFoundException('The uploaded image does not exist', true);


        // Ensure the image is named in "Image_<id>.<ext>" format, so that the crypto bundle uses that
        //  same format inside the encryption directory
        $new_filename = 'Image_'.$image->getId().'.'.$image->getExt();

        $dirname = pathinfo($current_filepath, PATHINFO_DIRNAME);
        rename($current_filepath, $dirname.'/'.$new_filename);
        // Convert the renamed file into an absolute path for the crypto bundle
        $absolute_filepath = realpath($dirname.'/'.$new_filename);

        // Might as well set the correct localFilename here
        $image->setLocalFileName( $image->getUploadDir().'/'.$new_filename );
        // Also should set the checksum of the Image before the encryption process deletes it
        $image->setOriginalChecksum( md5_file($absolute_filepath) );

        $this->em->persist($image);


        // ----------------------------------------
        // Encrypt the image
        self::encryptworker($image, $absolute_filepath);

        // If image is supposed to be public...
        if ( $image->isPublic() ) {
            // ...then ensure it's decrypted after the encryption process
            self::decryptImage($image_id);
        }
    }


    /**
     * Does the work of encrypting the given File/Image.
     *
     * @param File|Image $obj The database object for the File/Image that's being encrypted
     * @param string $filepath The absolute path to the unencrypted file/image on the server
     */
    private function encryptworker($obj, $filepath)
    {
        // Generate a random number for encryption purposes
        $bytes = $this->generator->nextBytes(16); // 128-bit random number
        // Convert the binary key into a hex string for db storage
        $hexEncoded_num = bin2hex($bytes);
        // Save the encryption key
        $obj->setEncryptKey($hexEncoded_num);

        // Locate the directory where the encrypted files exist
        $encrypted_basedir = $this->crypto_dir;
        if ($obj instanceof File)
            $encrypted_basedir .= '/File_'.$obj->getId().'/';
        else if ($obj instanceof Image)
            $encrypted_basedir .= '/Image_'.$obj->getId().'/';

        // Remove all previously encrypted chunks of this object if the directory exists
        if ( file_exists($encrypted_basedir) )
            self::deleteEncryptionDir($encrypted_basedir);

        // Persist the new information prior to encryption
        $this->em->persist($obj);


        // ----------------------------------------
        // Encrypt the file/image...this will delete the unencrypted version off the server
        $this->crypto_adapter->encryptFile($filepath, $bytes);


        // ----------------------------------------
        // Also want to create checksums of each piece of the encrypted file/image
        $results = array();
        if ($obj instanceof File) {
            $results = $this->em->getRepository('ODRAdminBundle:FileChecksum')->findBy(
                array( 'file' => $obj->getId() )
            );
        }
        else if ($obj instanceof Image) {
            $results = $this->em->getRepository('ODRAdminBundle:ImageChecksum')->findBy(
                array( 'image' => $obj->getId() )
            );
        }
        /** @var FileChecksum[]|ImageChecksum[] $results */

        $existing_checksums = array();
        foreach ($results as $result) {
            $existing_checksums[ $result->getChunkId() ] = $result;
        }

        // For each of the encrypted chunks of the file/image...
        $chunk_id = 0;
        while ( file_exists($encrypted_basedir.'enc.'.$chunk_id) ) {
            // ...ensure a File/Image Checksum entity exists...
            $checksum_entity = null;
            if ( isset($existing_checksums[$chunk_id]) )
                $checksum_entity = $existing_checksums[$chunk_id];

            if ( is_null($checksum_entity) ) {
                if ($obj instanceof File) {
                    $checksum_entity = new FileChecksum();
                    $checksum_entity->setFile($obj);
                    $checksum_entity->setChunkId($chunk_id);
                }
                else if ($obj instanceof Image) {
                    $checksum_entity = new ImageChecksum();
                    $checksum_entity->setImage($obj);
                    $checksum_entity->setChunkId($chunk_id);
                }
            }

            // ...so the checksum of this chunk of the encrypted file/image can be stored
            $checksum = md5_file($encrypted_basedir.'enc.'.$chunk_id);
            $checksum_entity->setChecksum($checksum);

            // Continue looking for chunk of the encrypted file/image
            $this->em->persist($checksum_entity);
            $chunk_id++;
        }

        // Save all changes made
        $this->em->flush();
    }


    /**
     * Since calling mkdir() when a directory already exists apparently causes a warning, and
     * because the dterranova Crypto bundle doesn't automatically handle it...this function deletes
     * the specified directory and all its contents off the server.
     *
     * @param string $basedir
     */
    private function deleteEncryptionDir($basedir)
    {
        if ( !file_exists($basedir) )
            return;

        $file_list = scandir($basedir);
        foreach ($file_list as $file) {
            if ($file != '.' && $file !== '..')
                unlink($basedir.$file);
        }

        rmdir($basedir);
    }
}