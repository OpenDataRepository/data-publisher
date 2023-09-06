<?php

/**
 * Open Data Repository Data Publisher
 * Upload Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This service holds the functions that handle creating ODR File/Image database entities and
 * triggering the encryption process on a file/image that's been uploaded to the server.
 *
 * There are also alternate functions that "replace" an existing ODR File/Image entity and the
 * contents of the associated encrypted directory with the file/image at the provided filepath.
 */

namespace ODR\AdminBundle\Component\Service;

// Entities
use ODR\AdminBundle\Entity\DataRecordFields;
use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Entity\Image;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Events
use ODR\AdminBundle\Component\Event\DatafieldModifiedEvent;
use ODR\AdminBundle\Component\Event\DatarecordModifiedEvent;
use ODR\AdminBundle\Component\Event\FilePostEncryptEvent;
use ODR\AdminBundle\Component\Event\FilePreEncryptEvent;
// Exceptions
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Other
use Doctrine\ORM\EntityManager;
use Pheanstalk\Pheanstalk;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Routing\Router;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;


class ODRUploadService
{

    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var CryptoService
     */
    private $crypto_service;

    /**
     * @var EntityCreationService
     */
    private $ec_service;

    /**
     * @var EventDispatcherInterface
     */
    private $event_dispatcher;

    // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
    //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode

    /**
     * @var Pheanstalk
     */
    private $pheanstalk;

    /**
     * @var Router
     */
    private $router;

    /**
     * @var string
     */
    private $redis_prefix;

    /**
     * @var string
     */
    private $api_key;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * ODRUploadService constructor
     *
     * @param EntityManager $entity_manager
     * @param CryptoService $crypto_service
     * @param EntityCreationService $entity_creation_service
     * @param EventDispatcherInterface $event_dispatcher
     * @param Pheanstalk $pheanstalk
     * @param Router $router
     * @param string $redis_prefix
     * @param string $api_key
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        CryptoService $crypto_service,
        EntityCreationService $entity_creation_service,
        EventDispatcherInterface $event_dispatcher,
        Pheanstalk $pheanstalk,
        Router $router,
        string $redis_prefix,
        string $api_key,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->crypto_service = $crypto_service;
        $this->ec_service = $entity_creation_service;
        $this->event_dispatcher = $event_dispatcher;
        $this->pheanstalk = $pheanstalk;
        $this->router = $router;
        $this->redis_prefix = $redis_prefix;
        $this->api_key = $api_key;
        $this->logger = $logger;
    }


    /**
     * Given a path to a file that exists on the server, and some information about where its info
     * is supposed to be stored in the database...this function creates the required supporting ODR
     * entities, triggers a PreEncrypt Event for the File, and then starts the async encryption
     * process.
     *
     * For a File, the encryption process is deferred through beanstalk because of the possibility
     * of huge files.
     *
     * @param string $filepath The path to the uploaded file on the server
     * @param ODRUser $user
     * @param DataRecordFields $drf
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     * @param \DateTime|null $public_date If provided, then the public date is set to this
     *
     * @return File The new incomplete File entity for the file at $filepath...The beanstalk process
     *              will be deleting the file at $filepath and modifying the File entity at some
     *              unknown time in the future.  USE WITH CAUTION.
     */
    public function uploadNewFile($filepath, $user, $drf, $created = null, $public_date = null)
    {
        // Ensure the filepath is valid
        if ( !file_exists($filepath) )
            throw new ODRNotFoundException('The file at "'.$filepath.'" does not exist on the server', true, 0x6fe6e25d);

        // The user uploaded a File...create a database entry with as much info as possible
        $file = $this->ec_service->createFile($user, $drf, $filepath, $created, $public_date);


        // ----------------------------------------
        // Now that the File (mostly) exists, should fire off the FilePreEncrypt event
        // Since the File isn't encrypted, several properties don't exactly work the same as they
        //  do after encryption.  @see FilePreEncryptEvent::getFile() for specifics.

        // This is wrapped in a try/catch block because any uncaught exceptions thrown by the
        //  event subscribers will prevent file encryption otherwise...
        try {
            $event = new FilePreEncryptEvent($file, $drf->getDataField());
            $this->event_dispatcher->dispatch(FilePreEncryptEvent::NAME, $event);
        }
        catch (\Exception $e) {
            // ...don't particularly want to rethrow the error since it'll interrupt
            //  everything downstream of the event...having file encryption interrupted is not
            //  acceptable though, so any errors need to disappear
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
        }

        // NOTE - the event is dispatched prior to the file encryption so that file encryption
        //  doesn't have to become a TrackedJob...which would also require the page would to
        //  check for and handle the event dispatching completion...

        // See ODR\AdminBundle\Component\Event\FilePreEncryptEvent.php for more details

        // Additionally, CryptoService handles firing all other events for files

        // ----------------------------------------
        // Reload the file incase the FilePreEncryptEvent screwed with the filepath
        $this->em->refresh($file);
        $file_meta = $file->getFileMeta();
        $this->em->refresh($file_meta);

        $filepath = $file->getLocalFileName().'/'.$file_meta->getOriginalFileName();

        // ----------------------------------------
//        $this->crypto_service->encryptFile($file->getId(), $filepath);

        // Need to use beanstalk to encrypt the file so the UI doesn't block on huge files

        // Generate the url for cURL to use
        $url = $this->router->generate('odr_crypto_request', array(), UrlGeneratorInterface::ABSOLUTE_URL);

        // Insert the new job into the queue
        $priority = 1024;   // should be roughly default priority
        $payload = json_encode(
            array(
                "object_type" => 'file',
                "object_id" => $file->getId(),
                "crypto_type" => 'encrypt',

                "local_filename" => $filepath,
                "archive_filepath" => '',
                "desired_filename" => '',

                "redis_prefix" => $this->redis_prefix,    // debug purposes only
                "url" => $url,
                "api_key" => $this->api_key,
            )
        );

        $delay = 1;
        $this->pheanstalk->useTube('crypto_requests')->put($payload, $priority, $delay);

        // Returning the file despite it still being unencrypted, and also despite that beanstalk
        //  will eventually encrypt it (deleting the file at $filepath) at some unknown time in the
        //  future.  USE WITH CAUTION.
        return $file;
    }


    /**
     * Given a path to an image that exists on the server, and some information about where its info
     * is supposed to be stored in the database...this function creates the required supporting ODR
     * entities, creates image thumbnails, and then encrypts the image and its thumbnails.
     *
     * For an Image, the encryption process is performed inline.  TODO - defer encryption instead?
     *
     * @param string $filepath The path to the uploaded file on the server
     * @param ODRUser $user
     * @param DataRecordFields $drf
     * @param \DateTime|null $created If provided, then the created/updated dates are set to this
     * @param \DateTime|null $public_date If provided, then the public date is set to this
     * @param int|null $display_order If provided, then the display_order is set to this
     *
     * @return Image
     */
    public function uploadNewImage($filepath, $user, $drf, $created = null, $public_date = null, $display_order = null)
    {
        // Ensure the filepath is valid
        if ( !file_exists($filepath) )
            throw new ODRNotFoundException('The image at "'.$filepath.'" does not exist on the server', true, 0x5a301f18);

        // The user uplaoded an Image...create a database entry with as much info as possible
        $image = $this->ec_service->createImage($user, $drf, $filepath, $created, $public_date, $display_order);


        // ----------------------------------------
        // Now that the Image (mostly) exists, should fire off the FilePreEncrypt event
        // Since the Image isn't encrypted, several properties don't exactly work the same as they
        //  do after encryption.  @see FilePreEncryptEvent::getFile() for specifics.

        // This is wrapped in a try/catch block because any uncaught exceptions thrown by the
        //  event subscribers will prevent file encryption otherwise...
        try {
            $event = new FilePreEncryptEvent($image, $drf->getDataField());
            $this->event_dispatcher->dispatch(FilePreEncryptEvent::NAME, $event);
        }
        catch (\Exception $e) {
            // ...don't particularly want to rethrow the error since it'll interrupt
            //  everything downstream of the event...having file encryption interrupted is not
            //  acceptable though, so any errors need to disappear
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
        }

        // NOTE - the event is dispatched prior to the image encryption so that image encryption
        //  doesn't have to become a TrackedJob...which would also require the page would to
        //  check for and handle the event dispatching completion...

        // See ODR\AdminBundle\Component\Event\FilePreEncryptEvent.php for more details

        // ----------------------------------------
        // Reload the image incase the FilePreEncryptEvent screwed with the filepath
        $this->em->refresh($image);
        $image_meta = $image->getImageMeta();
        $this->em->refresh($image_meta);

        $filepath = $image->getLocalFileName().'/'.$image_meta->getOriginalFileName();

        // Create thumbnails (and any other reiszed versions) of the original image before it gets
        //  encrypted
        $resized_images = $this->ec_service->createResizedImages($image, $filepath);

        // Encrypt the resized image...this will also set the localFilename, encryptKey, and
        //  originalChecksum properties
        $dirname = pathinfo($filepath, PATHINFO_DIRNAME);
        foreach ($resized_images as $resized_image) {
            $resized_image_filename = 'Image_'.$resized_image->getId().'.'.$resized_image->getExt();
            $this->crypto_service->encryptImage($resized_image->getId(), $dirname.'/'.$resized_image_filename);
        }

        // Encrypt the original image...this will also set the localFilename, encryptKey, and
        //  originalChecksum properties
        // TODO - should encryption be deferred through beanstalk instead?
        $this->crypto_service->encryptImage($image->getId(), $filepath);


        // ----------------------------------------
        // Mark this datafield and datarecord as updated...unlike files, we don't want images to
        //  fire off events inside CryptoService...it would end up firing off one event for the
        //  original image, then another for each thumbnail created
        $datarecord = $image->getDataRecord();
        $datafield = $image->getDataField();

        try {
            $event = new FilePostEncryptEvent($image, $datafield);
            $this->event_dispatcher->dispatch(FilePostEncryptEvent::NAME, $event);
        }
        catch (\Exception $e) {
            // ...don't want to rethrow the error since it'll interrupt everything after this
            //  event
//            if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                throw $e;
        }

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
            $event = new DatarecordModifiedEvent($datarecord, $user);
            $this->event_dispatcher->dispatch(DatarecordModifiedEvent::NAME, $event);
        }
        catch (\Exception $e) {
            // ...don't want to rethrow the error since it'll interrupt everything after this
            //  event
//            if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                throw $e;
        }

        return $image;
    }


    /**
     * Replaces the given File entity with the file that exists at the given path.
     *
     * @param File $existing_file
     * @param string $filepath The path to an unencrypted file on the server
     * @param ODRUser $user
     */
    public function replaceExistingFile($existing_file, $filepath, $user)
    {
        // Ensure the filepath is valid
        if ( !file_exists($filepath) )
            throw new ODRNotFoundException('The file at "'.$filepath.'" does not exist on the server', true, 0x77ac3ca5);

        // In order to overwrite this File, several of its properties need to be reset
        $existing_file->setEncryptKey('');
        $existing_file->setOriginalChecksum('');
        $existing_file->setFilesize( filesize($filepath) );

        $existing_file_meta = $existing_file->getFileMeta();
        $existing_file_meta->setUpdatedBy($user);
        $existing_file_meta->setUpdated(new \DateTime());

        $this->em->persist($existing_file);
        $this->em->persist($existing_file_meta);

        // Encrypt the given file, storing its relevant information back in $existing_file
        $this->crypto_service->encryptFile($existing_file->getId(), $filepath);

        // CryptoService handles firing events for files
    }


    /**
     * Replaces the given Image entity with the image that exists at the given path.
     *
     * @param Image $existing_image
     * @param string $filepath The path to the uploaded file on the server
     * @param ODRUser $user
     */
    public function replaceExistingImage($existing_image, $filepath, $user)
    {
        // Ensure the filepath is valid
        if ( !file_exists($filepath) )
            throw new ODRNotFoundException('The image at "'.$filepath.'" does not exist on the server', true, 0xedbb893c);

        // In order to overwrite this Image, several of its properties need to be reset...same for
        //  any related resized images
        $relevant_images = array();
        foreach ($existing_image->getChildren() as $i)
            $relevant_images[] = $i;
        $relevant_images[] = $existing_image;

        foreach ($relevant_images as $i) {
            $i->setOriginalChecksum('');
            $i->setEncryptKey('');
            // localFilename will be reset inside $crypto_service->encryptImage() later

            if ( $i->getOriginal() ) {
                // Reset the stored width/height to match the image on the server
                $sizes = getimagesize($filepath);
                $i->setImageWidth($sizes[0]);
                $i->setImageHeight($sizes[1]);

                // Mark the original Image's meta entry as updated
                $im = $i->getImageMeta();
                $im->setUpdatedBy($user);
                $im->setUpdated(new \DateTime());
                $this->em->persist($im);
            }
            // Don't need to change the width/height of an existing resized Image...it'll get
            //  changed inside $ec_service->createResizedImages() later

            $this->em->persist($i);
        }

        // Flush the database changes
        $this->em->flush();

        // Recreate all the necessary resized versions of the image
        $resized_images = $this->ec_service->createResizedImages($existing_image, $filepath, true);

        // Encrypt all the resized versions first
        $dirname = pathinfo($filepath, PATHINFO_DIRNAME);
        foreach ($resized_images as $resized_image) {
            $resized_image_filename = 'Image_'.$resized_image->getId().'.'.$resized_image->getExt();
            $this->crypto_service->encryptImage($resized_image->getId(), $dirname.'/'.$resized_image_filename);
        }

        // Encrypt the original version of the image, storing its information back in $existing_image
        $this->crypto_service->encryptImage($existing_image->getId(), $filepath);


        // ----------------------------------------
        // Mark this datafield and datarecord as updated...unlike files, we don't want images to
        //  fire off events inside CryptoService...it would end up firing off one event for the
        //  original image, then another for each thumbnail created
        $datarecord = $existing_image->getDataRecord();
        $datafield = $existing_image->getDataField();

        try {
            $event = new FilePostEncryptEvent($existing_image, $datafield);
            $this->event_dispatcher->dispatch(FilePostEncryptEvent::NAME, $event);
        }
        catch (\Exception $e) {
            // ...don't want to rethrow the error since it'll interrupt everything after this
            //  event
//            if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                throw $e;
        }

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
            $event = new DatarecordModifiedEvent($datarecord, $user);
            $this->event_dispatcher->dispatch(DatarecordModifiedEvent::NAME, $event);
        }
        catch (\Exception $e) {
            // ...don't want to rethrow the error since it'll interrupt everything after this
            //  event
//            if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                throw $e;
        }
    }
}
