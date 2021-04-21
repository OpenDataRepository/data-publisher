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
use ODR\AdminBundle\Entity\Image;
// Exceptions
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Other
use Doctrine\ORM\EntityManager;
use dterranova\Bundle\CryptoBundle\Crypto\CryptoAdapter;
use Symfony\Bridge\Monolog\Logger;


class CryptoService
{

    /**
     * @var CacheService
     */
    private $cache_service;

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
     * @var EntityManager
     */
    private $em;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * CryptoService constructor.
     *
     * @param CacheService $cache_service
     * @param CryptoAdapter $crypto_adapter
     * @param string $crypto_dir
     * @param string $odr_web_dir
     * @param EntityManager $entity_manager
     * @param Logger $logger
     */
    public function __construct(
        CacheService $cache_service,
        CryptoAdapter $crypto_adapter,
        $crypto_dir,
        $odr_web_dir,
        EntityManager $entity_manager,
        Logger $logger
    ) {
        $this->cache_service = $cache_service;
        $this->crypto_adapter = $crypto_adapter;
        $this->crypto_dir = realpath($crypto_dir);
        $this->odr_web_dir = realpath($odr_web_dir);
        $this->em = $entity_manager;
        $this->logger = $logger;
    }


    /**
     * Wrapper function to set up decryption of a specific File.  If this File is not public, then
     * whatever is calling this function needs to delete the decrypted version of this File off the
     * server when it's finished with the File.
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

        if ($target_filename == '')
            $target_filename = 'File_'.$file->getId().'.'.$file->getExt();

        // Determine where the encrypted file is stored
        $crypto_chunk_dir = $this->crypto_dir.'/File_'.$file_id;

        // Determine where the decrypted file is supposed to go
        $base_filepath = $this->odr_web_dir.'/'.$file->getUploadDir();

        // Decrypt the file
        return self::decryptworker($crypto_chunk_dir, $file->getEncryptKey(), $base_filepath, $target_filename);
    }


    /**
     * Decrypts a specific File, and stores it in the specified zip archive.
     *
     * @param $file_id
     * @param $target_filename
     * @param string $desired_filename The name the file should have in the archive
     * @param string $archive_filepath The full path to the archive to store this file in
     *
     * @throws ODRException
     */
    public function decryptFileForArchive($file_id, $target_filename, $desired_filename, $archive_filepath)
    {
        // Attempt to open the specified zip archive
        $handle = fopen($archive_filepath, 'c');    // create file if it doesn't exist, otherwise do not fail and position pointer at beginning of file
        if (!$handle)
            throw new ODRException('unable to open "'.$archive_filepath.'" for writing');

        // Decrypt the specified file prior to acquiring a lock on the archive
        $local_filepath = self::decryptFile($file_id, $target_filename);

        // TODO - flock() seems unreliable, but can ignore for now since the function is unused...
        // Attempt to acquire a lock on the zip archive so only one process is adding to it at a time
        $lock = false;
        while (!$lock) {
            $lock = flock($handle, LOCK_EX);
            if (!$lock)
                usleep(200000);     // sleep for a fifth of a second to try to acquire a lock...
        }

        // Open the archive for appending, or create if it doesn't exist
        $zip_archive = new \ZipArchive();
        $zip_archive->open($archive_filepath, \ZipArchive::CREATE);

        // Add the specified file to the zip archive
        $zip_archive->addFile($local_filepath, $desired_filename);
        $zip_archive->close();

        // Delete decrypted version of non-public files off the server
//        if ( !$base_obj->isPublic() )
        if ( strpos($target_filename, 'File_'.$file_id) === false )    // TODO - better way of doing this?
            unlink($local_filepath);

        // Release the lock on the zip archive
        flock($handle, LOCK_UN);
        fclose($handle);
    }


    /**
     * Wrapper function to set up decryption of a specific Image.  If this File is not public, then
     * whatever is calling this function needs to delete the decrypted version of this Image off the
     * server when it's finished with the Image.
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

        if ($target_filename == '')
            $target_filename = 'Image_'.$image->getId().'.'.$image->getExt();

        // Determine where the encrypted file is stored
        $crypto_chunk_dir = $this->crypto_dir.'/Image_'.$image_id;

        // Determine where the decrypted file is supposed to go
        $base_filepath = $this->odr_web_dir.'/'.$image->getUploadDir();

        // Decrypt the image
        return self::decryptworker($crypto_chunk_dir, $image->getEncryptKey(), $base_filepath, $target_filename);
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
        if ( !file_exists($local_filepath) ) {
            // Convert the hex string representation to binary...current version of php doesn't
            //  have hex2bin().  Apparently, php has had a function to go from bin->hex for at
            //  least 7 years, but hasn't had the reverse function until just recently?
            $key = pack("H*", $key);

            // Open the target file
            $handle = fopen($local_filepath, "wb");
            if (!$handle)
                throw new ODRException('Unable to open "'.$local_filepath.'" for writing');

            // Decrypt each chunk and write to target file
            $chunk_id = 0;
            while (file_exists($crypto_chunk_dir.'/'.'enc.'.$chunk_id)) {
                if (!file_exists($crypto_chunk_dir.'/'.'enc.'.$chunk_id))
                    throw new ODRException('Encrypted chunk not found: '.$crypto_chunk_dir.'/'.'enc.'.$chunk_id);

                $data = file_get_contents($crypto_chunk_dir.'/'.'enc.'.$chunk_id);
                fwrite($handle, $this->crypto_adapter->decrypt($data, $key));
                $chunk_id++;
            }
        }

        $file_decryptions = $this->cache_service->get('file_decryptions');
        if ( isset($file_decryptions[$target_filename]) ) {
            unset( $file_decryptions[$target_filename] );
            $this->cache_service->set('file_decryptions', $file_decryptions);
        }

        return $local_filepath;
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