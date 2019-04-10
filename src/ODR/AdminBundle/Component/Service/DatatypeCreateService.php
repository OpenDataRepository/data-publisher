<?php

/**
 * Open Data Repository Data Publisher
 * Datatype Info Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This service stores the code to get and rebuild the cached version of the datatype array, as
 * well as several other utility functions related to lists of datatypes.
 */

namespace ODR\AdminBundle\Component\Service;

// Entities
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTypeMeta;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Other
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;
// Utility
use ODR\AdminBundle\Component\Utility\UniqueUtility;
use ODR\AdminBundle\Component\Utility\UserUtility;


class DatatypeCreateService
{

    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var CacheService
     */
    private $cache_service;

    /**
     * @var CloneMasterDatatypeService
     */
    private $cdm_service;

    /**
     * @var UUIDService
     */
    private $uuid_service;

    /**
     * @var string
     */
    private $odr_web_dir;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * DatatypeInfoService constructor.
     *
     * DatatypeCreateService constructor.
     * @param EntityManager $entity_manager
     * @param CacheService $cache_service
     * @param CloneMasterDatatypeService $cdm_service
     * @param DatatypeInfoService $uuid_service
     * @param $odr_web_dir
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        CacheService $cache_service,
        CloneMasterDatatypeService $clone_master_datatype_service,
        UUIDService $uuid_service,
        $odr_web_dir,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->cache_service = $cache_service;
        $this->cdm_service = $clone_master_datatype_service;
        $this->uuid_service = $uuid_service;
        $this->odr_web_dir = $odr_web_dir;
        $this->logger = $logger;
    }

    /**
     * Adds a new database from a master template where the master template
     * has an associated properties/metadata template.
     *
     * Also creates initial properties record and forwards user to
     * properties page as next step in creation sequence.
     *
     * @param $master_datatype_id
     * @param int $datatype_id
     * @param null $admin
     * @param bool $bypass_queue
     * @return DataType|null
     */
    public function direct_add_datatype($master_datatype_id, $datatype_id = 0, $admin = null, $bypass_queue =  false)
    {
        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $this->em */
            /** @var PermissionsManagementService $pm_service */

            // Don't need to verify permissions, firewall won't let this action be called unless user is admin
            /** @var User $admin */
            if($admin === null) {
                throw new ODRNotFoundException('User');
            }

            // A master datatype is required
            // ...locate the master template datatype and store that it's the "source" for this new datatype
            $repo_datatype = $this->em->getRepository('ODRAdminBundle:DataType');
            /** @var DataType $master_datatype */
            $master_datatype = $repo_datatype->find($master_datatype_id);
            if ($master_datatype == null)
                throw new ODRNotFoundException('Master Datatype');

            // Create new DataType form
            $datatypes_to_process = array();
            $datatype = null;
            $unique_id = null;
            $clone_and_link = false;
            if($datatype_id > 0) {
                /** @var DataType $datatype */
                $datatype = $repo_datatype->find($datatype_id);
                $master_metadata = $master_datatype->getMetadataDatatype();

                $unique_id = $datatype->getUniqueId();
                $clone_and_link = true;
            }
            else {
                // Create a new Datatype entity
                $datatype = new DataType();
                $datatype->setRevision(0);

                $unique_id = $this->uuid_service->generateDatatypeUniqueId();
                $datatype->setUniqueId($unique_id);
                $datatype->setTemplateGroup($unique_id);

                // Create the datatype unique id and check to ensure uniqueness

                // Top-level datatypes exist in one of two states...in the "initial" state, they
                //  shouldn't be viewed by users because they're lacking themes and permissions
                // Once they have those, then they should be put into the "operational" state
                $datatype->setSetupStep(DataType::STATE_INITIAL);

                // Is this a Master Type?
                $datatype->setIsMasterType(false);
                $datatype->setMasterDataType($master_datatype);

                $datatype->setCreatedBy($admin);
                $datatype->setUpdatedBy($admin);

                // Save all changes made
                $this->em->persist($datatype);
                $this->em->flush();
                $this->em->refresh($datatype);

                // Top level datatypes are their own parent/grandparent
                $datatype->setParent($datatype);
                $datatype->setGrandparent($datatype);
                $this->em->persist($datatype);


                // Fill out the rest of the metadata properties for this datatype...don't need to set short/long name since they're already from the form
                $datatype_meta_data = new DataTypeMeta();
                $datatype_meta_data->setDataType($datatype);
                $datatype_meta_data->setShortName('New Dataset');
                $datatype_meta_data->setLongName('New Dataset');

                /** @var RenderPlugin $default_render_plugin */
                $default_render_plugin = $this->em->getRepository('ODRAdminBundle:RenderPlugin')->find(1);    // default render plugin
                $datatype_meta_data->setRenderPlugin($default_render_plugin);

                // Default search slug to Dataset ID
                $datatype_meta_data->setSearchSlug($datatype->getUniqueId());
                $datatype_meta_data->setXmlShortName('');

                // Master Template Metadata
                // Once a child database is completely created from the master template, the creation process will update the revisions appropriately.
                $datatype_meta_data->setMasterRevision(0);
                $datatype_meta_data->setMasterPublishedRevision(0);
                $datatype_meta_data->setTrackingMasterRevision(0);

                $datatype_meta_data->setPublicDate(new \DateTime('2200-01-01 00:00:00'));

                $datatype_meta_data->setExternalIdField(null);
                $datatype_meta_data->setNameField(null);
                $datatype_meta_data->setSortField(null);
                $datatype_meta_data->setBackgroundImageField(null);

                $datatype_meta_data->setCreatedBy($admin);
                $datatype_meta_data->setUpdatedBy($admin);
                $this->em->persist($datatype_meta_data);

                // Ensure the "in-memory" version of the new datatype knows about its meta entry
                $datatype->addDataTypeMetum($datatype_meta_data);
                $this->em->flush();


                array_push($datatypes_to_process, $datatype);

                // If is non-master datatype, clone master-related metadata type
                $master_datatype = $datatype->getMasterDataType();
                $master_metadata = $master_datatype->getMetadataDatatype();
            }

            /*
             * Create Datatype Metadata Object (a second datatype to store one record with the properties
             * for the parent datatype).
             */

            if ($master_metadata != null) {
                $metadata_datatype = clone $master_metadata;
                // Unset is master type
                $metadata_datatype->setIsMasterType(0);
                $metadata_datatype->setGrandparent($metadata_datatype);
                $metadata_datatype->setParent($metadata_datatype);
                $metadata_datatype->setMasterDataType($master_metadata);
                // Set template group to that of datatype
                $metadata_datatype->setTemplateGroup($unique_id);
                // Clone has wrong state - set to initial
                $metadata_datatype->setSetupStep(DataType::STATE_INITIAL);

                // Need to always set a unique id
                $metadata_unique_id = $this->uuid_service->generateDatatypeUniqueId();
                $metadata_datatype->setUniqueId($metadata_unique_id);

                // Set new datatype meta
                $metadata_datatype_meta = clone $datatype->getDataTypeMeta();
                $metadata_datatype_meta->setShortName("Properties");
                $metadata_datatype_meta->setLongName($datatype->getDataTypeMeta()->getLongName() . " - Properties");
                $metadata_datatype_meta->setDataType($metadata_datatype);

                // Associate the metadata
                $metadata_datatype->addDataTypeMetum($metadata_datatype_meta);
                $metadata_datatype->setMetadataFor($datatype);

                // New Datatype
                $this->em->persist($metadata_datatype);
                // New Datatype Meta
                $this->em->persist($metadata_datatype_meta);

                // Set Metadata Datatype
                $datatype->setMetadataDatatype($metadata_datatype);
                $this->em->persist($datatype);
                $this->em->flush();

                array_push($datatypes_to_process, $metadata_datatype);

            }
            /*
             * END Create Datatype Metadata Object
             */



            /*
             * Clone theme or create theme as needed for new datatype(s)
             */
            // Determine which is parent


            /** @var DataType $datatype */
            foreach ($datatypes_to_process as $datatype) {
                if($bypass_queue) {
                    // Directly create datatype calling clone master datatype service
                    $created_dt = $this->cdm_service->createDatatypeFromMaster(
                        $datatype->getId(),
                        $admin->getId(),
                        $unique_id
                    );
                }
                else {
                    // Send to pheanstalk
                    // ----------------------------------------
                    // If the datatype is being created from a master template...
                    /*
                    // Start the job to create the datatype from the template
                    $pheanstalk = $this->get('pheanstalk');
                    $redis_prefix = $this->container->getParameter('memcached_key_prefix');
                    $api_key = $this->container->getParameter('beanstalk_api_key');

                    // Insert the new job into the queue
                    $priority = 1024;   // should be roughly default priority
                    $params = array(
                        "user_id" => $admin->getId(),
                        "datatype_id" => $datatype->getId(),
                        "template_group" => $unique_id,
                        "redis_prefix" => $redis_prefix,    // debug purposes only
                        "api_key" => $api_key,
                    );

                    $params["clone_and_link"] = false;
                    if($clone_and_link) {
                        $params["clone_and_link"] = true;
                    }

                    $payload = json_encode($params);

                    $pheanstalk->useTube('create_datatype_from_master')->put($payload, $priority, 0);
                    */
                }
            }
            /*
             * END Clone theme or create theme as needed for new datatype(s)
             */

            if($bypass_queue) {
                // return the metadata record?
                return $datatype;
            }
            else {
                return $datatype;
            }
        }
        catch (\Exception $e) {
            $source = 0xa54e875c;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }
}
