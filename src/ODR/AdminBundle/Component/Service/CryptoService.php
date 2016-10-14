<?php

namespace ODR\AdminBundle\Component\Service;

use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Entity\FileChecksum;
// use ODR\AdminBundle\Entity\FileMeta;
use ODR\AdminBundle\Entity\Image;
use ODR\AdminBundle\Entity\ImageChecksum;
// use ODR\AdminBundle\Entity\ImageMeta;
// use ODR\AdminBundle\Entity\ImageSizes;


// use Symfony\Component\HttpFoundation\File\File as SymfonyFile;
use Symfony\Component\DependencyInjection\ContainerInterface as Container;
use Doctrine\ORM\EntityManager;


/**
 * Created by PhpStorm.
 * User: nate
 * Date: 10/14/16
 * Time: 11:59 AM
 */
class CryptoService
{


    /**
     * @var mixed
     */
    private $logger;


    /**
     * @var mixed
     */
    private $container;

    public function __construct(Container $container, EntityManager $entity_manager, $logger) {
        $this->container = $container;
        $this->em = $entity_manager;
        $this->logger = $logger;
    }



    /**
     * Utility function that does the work of encrypting a given File/Image entity.
     *
     * @throws \Exception
     *
     * @param integer $object_id The id of the File/Image to encrypt
     * @param string $object_type "File" or "Image"
     *
     */
    public function encryptObject($object_id, $object_type)
    {
        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->em;
            $generator = $this->container->get('security.secure_random');
            $crypto = $this->container->get("dterranova_crypto.crypto_adapter");

            $repo_filechecksum = $em->getRepository('ODRAdminBundle:FileChecksum');
            $repo_imagechecksum = $em->getRepository('ODRAdminBundle:ImageChecksum');


            $absolute_path = '';
            $base_obj = null;
            $object_type = strtolower($object_type);
            if ($object_type == 'file') {
                // Grab the file and associated information
                /** @var File $base_obj */
                $base_obj = $em->getRepository('ODRAdminBundle:File')->find($object_id);
                $file_upload_path = dirname(__FILE__).'/../../../../../web/uploads/files/';
                $filename = 'File_'.$object_id.'.'.$base_obj->getExt();

                if ( !file_exists($file_upload_path.$filename) )
                    throw new \Exception("File does not exist");

                // crypto bundle requires an absolute path to the file to encrypt/decrypt
                $absolute_path = realpath($file_upload_path.$filename);
            }
            else if ($object_type == 'image') {
                // Grab the image and associated information
                /** @var Image $base_obj */
                $base_obj = $em->getRepository('ODRAdminBundle:Image')->find($object_id);
                $image_upload_path = dirname(__FILE__).'/../../../../../web/uploads/images/';
                $imagename = 'Image_'.$object_id.'.'.$base_obj->getExt();

                if ( !file_exists($image_upload_path.$imagename) )
                    throw new \Exception("Image does not exist");

                // crypto bundle requires an absolute path to the file to encrypt/decrypt
                $absolute_path = realpath($image_upload_path.$imagename);
            }
            /** @var File|Image $base_obj */

            // Generate a random number for encryption purposes
            $bytes = $generator->nextBytes(16); // 128-bit random number
//print 'bytes ('.gettype($bytes).'): '.$bytes."\n";

            // Convert the binary key into a hex string for db storage
            $hexEncoded_num = bin2hex($bytes);

            // Save the encryption key
            $base_obj->setEncryptKey($hexEncoded_num);
            $em->persist($base_obj);


            // Locate the directory where the encrypted files exist
            $encrypted_basedir = $this->container->getParameter('dterranova_crypto.temp_folder');
            if ($object_type == 'file')
                $encrypted_basedir .= '/File_'.$object_id.'/';
            else if ($object_type == 'image')
                $encrypted_basedir .= '/Image_'.$object_id.'/';

            // Remove all previously encrypted chunks of this object if the directory exists
            if ( file_exists($encrypted_basedir) )
                self::deleteEncryptionDir($encrypted_basedir);


            // Encrypt the file
            $crypto->encryptFile($absolute_path, $bytes);

            // Create an md5 checksum of all the pieces of that encrypted file
            $chunk_id = 0;
            while ( file_exists($encrypted_basedir.'enc.'.$chunk_id) ) {
                $checksum = md5_file($encrypted_basedir.'enc.'.$chunk_id);

                // Attempt to load a checksum object
                $obj = null;
                if ($object_type == 'file')
                    $obj = $repo_filechecksum->findOneBy( array('file' => $object_id, 'chunk_id' => $chunk_id) );
                else if ($object_type == 'image')
                    $obj = $repo_imagechecksum->findOneBy( array('image' => $object_id, 'chunk_id' => $chunk_id) );
                /** @var FileChecksum|ImageChecksum $obj */

                // Create a checksum entry if it doesn't exist
                if ($obj == null) {
                    if ($object_type == 'file') {
                        $obj = new FileChecksum();
                        $obj->setFile($base_obj);
                    }
                    else if ($object_type == 'image') {
                        $obj = new ImageChecksum();
                        $obj->setImage($base_obj);
                    }
                }

                // Save the checksum entry
                $obj->setChunkId($chunk_id);
                $obj->setChecksum($checksum);

                $em->persist($obj);

                // Look for any more encrypted chunks
                $chunk_id++;
            }

            // Save all changes
            $em->flush();
        }
        catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }


    /**
     * Utility function that does the work of decrypting a given File/Image entity.
     * Note that the filename of the decrypted file/image is determined solely by $object_id and $object_type because of constraints in the $crypto->decryptFile() function
     *
     * @param integer $object_id  The id of the File/Image to decrypt
     * @param string $object_type "File" or "Image"
     *
     * @return string The absolute path to the newly decrypted file/image
     */
    public function decryptObject($object_id, $object_type)
    {
        // Grab necessary objects
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->em; // $this->getDoctrine()->getManager();
        $crypto = $this->container->get("dterranova_crypto.crypto_adapter");

        // TODO: auto-check the checksum?
//        $repo_filechecksum = $em->getRepository('ODRAdminBundle:FileChecksum');
//        $repo_imagechecksum = $em->getRepository('ODRAdminBundle:ImageChecksum');


        $absolute_path = '';
        $base_obj = null;
        $object_type = strtolower($object_type);
        if ($object_type == 'file') {
            // Grab the file and associated information
            /** @var File $base_obj */
            $base_obj = $em->getRepository('ODRAdminBundle:File')->find($object_id);
            $file_upload_path = dirname(__FILE__).'/../../../../../web/uploads/files/';
            $filename = 'File_'.$object_id.'.'.$base_obj->getExt();

            // crypto bundle requires an absolute path to the file to encrypt/decrypt
            $absolute_path = realpath($file_upload_path).'/'.$filename;
        }
        else if ($object_type == 'image') {
            // Grab the image and associated information
            /** @var Image $base_obj */
            $base_obj = $em->getRepository('ODRAdminBundle:Image')->find($object_id);
            $image_upload_path = dirname(__FILE__).'/../../../../../web/uploads/images/';
            $imagename = 'Image_'.$object_id.'.'.$base_obj->getExt();

            // crypto bundle requires an absolute path to the file to encrypt/decrypt
            $absolute_path = realpath($image_upload_path).'/'.$imagename;
        }
        /** @var File|Image $base_obj */

        // Apparently files/images can decrypt to a zero length file sometimes...check for and deal with this
        if ( file_exists($absolute_path) && filesize($absolute_path) == 0 )
            unlink($absolute_path);

        // Since errors apparently don't cascade from the CryptoBundle through to here...
        if ( !file_exists($absolute_path) ) {
            // Grab the hex string representation that the file was encrypted with
            $key = $base_obj->getEncryptKey();
            // Convert the hex string representation to binary...php had a function to go bin->hex, but didn't have a function for hex->bin for at least 7 years?!?
            $key = pack("H*", $key);   // don't have hex2bin() in current version of php...this appears to work based on the "if it decrypts to something intelligible, you did it right" theory

            // Decrypt the file (do NOT delete the encrypted version)
            $crypto->decryptFile($absolute_path, $key, false);
        }

        return $absolute_path;
    }

    /**
     * Since calling mkdir() when a directory already exists apparently causes a warning, and because the
     * dterranova Crypto bundle doesn't automatically handle it...this function deletes the specified directory
     * and all its contents off the server
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