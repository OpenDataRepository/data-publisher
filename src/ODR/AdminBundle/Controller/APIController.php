<?php

/**
 * Open Data Repository Data Publisher
 * API Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Handles the OAuth-specific API routes.
 */

namespace ODR\AdminBundle\Controller;

// Entities
use ODR\AdminBundle\Entity\Boolean;
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataRecordFields;
use ODR\AdminBundle\Entity\DataRecordMeta;
use ODR\AdminBundle\Entity\DataTree;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTypeMeta;
use ODR\AdminBundle\Entity\DatetimeValue;
use ODR\AdminBundle\Entity\DecimalValue;
use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Entity\FileMeta;
use ODR\AdminBundle\Entity\Group;
use ODR\AdminBundle\Entity\Image;
use ODR\AdminBundle\Entity\ImageMeta;
use ODR\AdminBundle\Entity\IntegerValue;
use ODR\AdminBundle\Entity\LongText;
use ODR\AdminBundle\Entity\LongVarchar;
use ODR\AdminBundle\Entity\MediumVarchar;
use ODR\AdminBundle\Entity\RadioOptions;
use ODR\AdminBundle\Entity\RadioOptionsMeta;
use ODR\AdminBundle\Entity\RadioSelection;
use ODR\AdminBundle\Entity\ShortVarchar;
use ODR\AdminBundle\Entity\Tags;
use ODR\AdminBundle\Entity\TagMeta;
use ODR\AdminBundle\Entity\TagSelection;
use ODR\AdminBundle\Entity\TagTree;
use ODR\AdminBundle\Entity\TrackedCSVExport;
use ODR\AdminBundle\Entity\TrackedJob;
use ODR\AdminBundle\Entity\UserGroup;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Events
use ODR\AdminBundle\Component\Event\DatarecordCreatedEvent;
use ODR\AdminBundle\Component\Event\DatarecordModifiedEvent;
use ODR\AdminBundle\Component\Event\DatarecordPublicStatusChangedEvent;
use ODR\AdminBundle\Component\Event\DatatypeModifiedEvent;
use ODR\AdminBundle\Component\Event\DatatypeImportedEvent;
use ODR\AdminBundle\Component\Event\DatatypePublicStatusChangedEvent;
use ODR\AdminBundle\Component\Event\FileDeletedEvent;
// Exceptions
use ODR\AdminBundle\Component\CustomException\ODRJsonException;
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
use ODR\AdminBundle\Exception\ODRNotImplementedException;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
use ODR\AdminBundle\Component\Service\CryptoService;
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\AdminBundle\Component\Service\DatarecordExportService;
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\AdminBundle\Component\Service\DatatreeInfoService;
use ODR\AdminBundle\Component\Service\DatatypeCreateService;
use ODR\AdminBundle\Component\Service\DatatypeExportService;
use ODR\AdminBundle\Component\Service\EntityCreationService;
use ODR\AdminBundle\Component\Service\EntityDeletionService;
use ODR\AdminBundle\Component\Service\ODRUploadService;
use ODR\AdminBundle\Component\Service\ODRUserGroupMangementService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\SortService;
use ODR\AdminBundle\Component\Service\UUIDService;
use ODR\AdminBundle\Component\Utility\ValidUtility;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchAPIService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchKeyService;
// Symfony
use Doctrine\ORM\EntityManager;
use FOS\UserBundle\Doctrine\UserManager;
use HWI\Bundle\OAuthBundle\Tests\Fixtures\FOSUser;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileNotFoundException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;


class APIController extends ODRCustomController
{
    /**
     * Creates appropriate metadata info in JSON-LD to meet requirements
     * for discovery by Google Dataset Search and other search providers.
     *
     * @param $dataset_uuid
     * @param $version
     * @param Request $request
     * @return JsonResponse
     * @throws ODRException
     * @throws ODRNotFoundException
     */
    public function jsonLDAction($dataset_uuid, $version, Request $request)
    {
        try {
            // Determine if dataset has metadata or is metadata dataset
            // Get first record/main record - metadata can only have one record

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->findOneBy(
                array(
                    'unique_id' => $dataset_uuid,
                )
            );
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');


            if ($datatype->getMetadataDatatype()) {
                $datatype = $datatype->getMetadataDatatype();
            }

            /** @var DataRecord $metadata_record */
            $metadata_record = $em->getRepository('ODRAdminBundle:DataRecord')
                ->findOneBy(array('dataType' => $datatype->getId()));

            // Now get the json record and update it with the correct user_id ant date times
            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');
            $json_metadata_record = $cache_service
                ->get('json_record_' . $metadata_record->getUniqueId());

            if (!$json_metadata_record) {
                // Need to pull record using getExport...
                $json_metadata_record = self::getRecordData(
                    'v3',
                    $metadata_record->getUniqueId(),
                    'json',
                    'anon.'
                );

                if ($json_metadata_record) {
                    $json_metadata_record = json_decode($json_metadata_record, true);
                }
            } else {
                // Check if dataset has public attribute
                $json_metadata_record = json_decode($json_metadata_record, true);
            }

            /*
             * {
             *      "@context": "https://schema.org/",
             *      "@type": "Dataset",
             *      "name": "Removal of organic carbon by natural bacterioplankton communities as a function of pCO2 from laboratory experiments between 2012 and 2016",
             *      "description": "This dataset includes results of laboratory experiments which measured dissolved organic carbon (DOC) usage by natural bacteria in seawater at different pCO2 levels. Included in this dataset are; bacterial abundance, total organic carbon (TOC), what DOC was added to the experiment, target pCO2 level. ",
             *      "url": "https://www.sample-data-repository.org/dataset/472032",
             *      "sameAs": "https://search.dataone.org/#view/https://www.sample-data-repository.org/dataset/472032",
             *      "version": "2013-11-21",
             *      "isAccessibleForFree": true,
             *      "keywords": ["ocean acidification", "Dissolved Organic Carbon", "bacterioplankton respiration", "pCO2", "carbon dioxide", "oceans"],
             *      "license": [ "http://spdx.org/licenses/CC0-1.0", "https://creativecommons.org/publicdomain/zero/1.0"]
             *      "creator:{
             *          "@list": [
             *              {
             *                  "@type": "Person",
             *                  "name": "Creator #1"
             *              },
             *              {
             *                  "@type": "Person",
             *                  "name": "Creator #2"
             *              }
             *            ]
             *          }
             *    }
             */

            // Convert to Dataset Content Type
            $output_dataset = array();
            $output_dataset["@context"] = "https://schema.org/";
            $output_dataset["@type"] = "Dataset";
            $output_dataset["name"] = self::getFieldValue(
                $json_metadata_record, '23db96d4f26a46af7de57a3da752'
            );
            $output_dataset["description"] = self::getFieldValue(
                $json_metadata_record, '64caf7e390296f72d70bdf84a853'
            );
            $output_dataset["version"] = self::getFieldValue(
                $json_metadata_record, 'c05b2d1f01fac1b726a971af178b'
            );
            $output_dataset["url"] = self::getFieldValue(
                $json_metadata_record, '84ad17c89dbeecad2eda27faa3c8'
            );
            $output_dataset["sameAs"] = self::getFieldValue(
                $json_metadata_record, '84ad17c89dbeecad2eda27faa3c8'
            );
            $output_dataset["isAccessibleForFree"] = self::getFieldValue(
                $json_metadata_record, 'df1e203fee2f12fb7d53c0ebccb3'
            );
            // Process Creators
            $creators_array = self::getValuesMatching(
                $json_metadata_record,
                'a88ecce7c65356de38dd982db8c8',
                '59414503bf4fa640df1b0a2172b9'
            );
            $output_dataset["creator"] = array();
            $output_dataset["creator"]["@list"] = array();
            foreach ($creators_array as $creator) {
                $output_dataset["creator"]["@list"][] = array(
                    "@type" => "Person",
                    "name" => $creator
                );
            }
            // Process Keywords
            $keywords_array = self::getValuesMatching(
                $json_metadata_record,
                '546da23100565304ed804c317597',
                'bd31f72545adea8b586203a70e02'
            );
            $output_dataset["keywords"] = array();
            foreach ($keywords_array as $keyword) {
                $output_dataset["keywords"][] = $keyword;
            }
            // Process Licenses
            $license_array = self::getValuesMatching(
                $json_metadata_record,
                '02dd484abb6232a317e7de257110',
                '7270156ddab75822052466f50bd9'
            );
            $output_dataset["license"] = array();
            foreach ($license_array as $license) {
                $output_dataset["license"][] = $license;
            }
            return new JsonResponse($output_dataset);
        } catch (\Exception $e) {
            $source = 0x5dc89429;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode());
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }

    /**
     * @param $record
     * @param $field_uuid
     * @return mixed|string
     */
    public function getValuesMatching($record, $template_uuid, $field_uuid)
    {
        $output_array = [];
        if (
            isset($record['template_uuid'])
            && strlen($record['template_uuid']) > 0
            && isset($record['records_' . $record['template_uuid']])
        ) {
            $record_array = $record['records_' . $record['template_uuid']];
        }
        foreach ($record_array as $record) {
            if ($record['template_uuid'] == $template_uuid) {
                $value = self::getFieldValue($record, $field_uuid);
                if ($value != '') {
                    array_push($output_array, $value);
                }
            }
        }
        return $output_array;
    }

    /**
     * @param $record
     * @param $field_uuid
     * @return mixed|string
     */
    public function getFieldValue($record, $field_uuid)
    {
        $field_array = [];
        if (
            isset($record['template_uuid'])
            && strlen($record['template_uuid']) > 0
            && isset($record['fields_' . $record['template_uuid']])
        ) {
            $field_array = $record['fields_' . $record['template_uuid']];
        } else if (
            isset($record['database_uuid'])
            && strlen($record['database_uuid']) > 0
            && isset($record['fields_' . $record['database_uuid']])
        ) {
            $field_array = $record['fields_' . $record['database_uuid']];
        }
        if (count($field_array) > 0) {
            foreach ($field_array as $field_data) {
                if ($field_data['template_field_uuid'] == $field_uuid) {
                    if (isset($field_data['value'])) {
                        return $field_data['value'];
                    } else if (isset($field_data['files'])) {
                        return $field_data['files'][0]['href'];
                    }
                } else if ($field_data['field_uuid'] == $field_uuid) {
                    if (isset($field_data['value'])) {
                        return $field_data['value'];
                    } else if (isset($field_data['files'])) {
                        return $field_data['files'][0]['href'];
                    }
                }
            }
        }

        return '';
    }


    /**
     * Returns basic information about the currently logged-in user to the API.
     *
     * @param string $version
     * @param Request $request
     *
     * @return Response
     */
    public function userdataAction($version, Request $request)
    {
        try {
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in

            if ($user != 'anon.' /*&& $user->hasRole('ROLE_JUPYTERHUB_USER')*/) {
                $user_array = array(
                    'id' => $user->getEmail(),
                    'username' => $user->getUserString(),
                    'realname' => $user->getUserString(),
                    'email' => $user->getEmail(),
                    'baseurl' => $this->getParameter('site_baseurl'),
                );

                if ($this->has('odr.jupyterhub_bridge.username_service'))
                    $user_array['jupyterhub_username'] = $this->get('odr.jupyterhub_bridge.username_service')->getJupyterhubUsername($user);


                // Symfony already knows the request format due to use of the _format parameter in the route
                $format = $request->getRequestFormat();
                $data = $this->get('templating')->render(
                    'ODRAdminBundle:API:userdata.' . $format . '.twig',
                    array(
                        'user_data' => $user_array,
                    )
                );

                // Symfony should automatically set the response format based on the request format
                return new Response($data);
            }

            // Otherwise, user isn't allowed to do this
            throw new ODRForbiddenException();
        } catch (\Exception $e) {
            $source = 0xfd346a45;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Returns an array of identifying information for all datatypes the user can view.
     *
     * By default, returns top-level datatypes as json to the browser...however, it can also display
     * child datatypes and/or return the response as a file.
     *
     * @param string $version
     * @param string $type
     * @param Request $request
     *
     * @return Response
     */
    public function getDatatypeListAction($version, $type, Request $request)
    {
        try {
            // Default to only showing top-level datatypes...
            $show_child_datatypes = false;
            if ($request->query->has('display') && $request->query->get('display') == 'all')
                // ...but show child datatypes upon request
                $show_child_datatypes = true;

            // Default to returning the data straight to the browser...
            $download_response = false;
            if ($request->query->has('download') && $request->query->get('download') == 'file')
                // ...but return the data as a download on request
                $download_response = true;

            // This action can list both regular databases and master templates
            // It doesn't make sense to have both in the same output
            $is_master_type = 0;
            if ($type === 'master_templates')
                $is_master_type = 1;


            // ----------------------------------------
            // Load required objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatatreeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatree_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            // Get the user's permissions if applicable
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $datatype_permissions = $pm_service->getDatatypePermissions($user);

            $top_level_datatype_ids = $dti_service->getTopLevelDatatypes();
            $datatree_array = $dti_service->getDatatreeArray();

            // ----------------------------------------
            $results = array();
            if ($show_child_datatypes) {
                // Build/execute a query to get basic info on all datatypes
                $query = $em->createQuery(
                    'SELECT
                        dt.id AS database_id, dtm.shortName AS database_name, dtm.searchSlug AS search_slug,
                        dtm.description AS database_description, dtm.publicDate AS public_date,
                        dt.unique_id AS unique_id, mdt.unique_id AS template_id
                    FROM ODRAdminBundle:DataType AS dt
                    JOIN ODRAdminBundle:DataTypeMeta AS dtm WITH dtm.dataType = dt
                    LEFT JOIN ODRAdminBundle:DataType AS mdt WITH dt.masterDataType = mdt
                    WHERE dt.setup_step IN (:setup_steps)
                    AND dt.is_master_type = :is_master_type AND dt.metadata_for IS NULL
                    AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL'
                )->setParameters(
                    array(
                        'setup_steps' => DataType::STATE_VIEWABLE,
                        'is_master_type' => $is_master_type,
                    )
                );
                $results = $query->getArrayResult();
            } else {
                // Build/execute a query to get basic info on all top-level datatypes
                $query = $em->createQuery(
                    'SELECT
                        dt.id AS database_id, dtm.shortName AS database_name, dtm.searchSlug AS search_slug,
                        dtm.description AS database_description, dtm.publicDate AS public_date,
                        dt.unique_id AS unique_id, mdt.unique_id AS template_id
                    FROM ODRAdminBundle:DataType AS dt
                    JOIN ODRAdminBundle:DataTypeMeta AS dtm WITH dtm.dataType = dt
                    LEFT JOIN ODRAdminBundle:DataType AS mdt WITH dt.masterDataType = mdt
                    WHERE dt.id IN (:datatype_ids) AND dt.setup_step IN (:setup_steps)
                    AND dt.is_master_type = :is_master_type AND dt.metadata_for IS NULL
                    AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL'
                )->setParameters(
                    array(
                        'datatype_ids' => $top_level_datatype_ids,
                        'setup_steps' => DataType::STATE_VIEWABLE,
                        'is_master_type' => $is_master_type,
                    )
                );
                $results = $query->getArrayResult();
            }


            // ----------------------------------------
            // Filter the query results by what the user is allowed to see
            $datatype_data = array();
            foreach ($results as $num => $dt) {
                // Store whether the user has permission to view this datatype
                $dt_id = $dt['database_id'];
                $can_view_datatype = false;
                if (isset($datatype_permissions[$dt_id]) && isset($datatype_permissions[$dt_id]['dt_view']))
                    $can_view_datatype = true;

                // If the datatype is public, or the user doesn't have permission to view this datatype...
                $public_date = $dt['public_date']->format('Y-m-d H:i:s');
                unset($results[$num]['public_date']);

                if ($can_view_datatype || $public_date !== '2200-01-01 00:00:00')
                    // ...save it in the results array
                    $datatype_data[$dt_id] = $results[$num];
            }


            // ----------------------------------------
            // Organize the datatype data into a new array if needed
            $final_datatype_data = array();

            if ($show_child_datatypes) {
                // Need to recursively turn this array of datatypes into an inflated array
                foreach ($datatype_data as $dt_id => $dt) {
                    if (in_array($dt_id, $top_level_datatype_ids)) {
                        $tmp = self::inflateDatatypeArray($datatype_data, $datatree_array, $dt_id);
                        if (count($tmp) > 0)
                            $dt['child_databases'] = array_values($tmp);

                        $final_datatype_data[$dt_id] = $dt;
                    }
                }
            } else {
                // Otherwise, this is just the top-level dataypes
                $final_datatype_data = $datatype_data;
            }

            $final_datatype_data = array('databases' => array_values($final_datatype_data));

            // Symfony already knows the request format due to use of the _format parameter in the route
            $format = $request->getRequestFormat();
            $data = $this->get('templating')->render(
                'ODRAdminBundle:API:datatype_list.' . $format . '.twig',
                array(
                    'datatype_list' => $final_datatype_data,
                )
            );


            // ----------------------------------------
            // Set up a response to send the datatype list back to the user
            $response = new Response();

            if ($download_response) {
                $response->setPrivate();
                //$response->headers->set('Content-Length', filesize($xml_export_path.$filename));
                $response->headers->set('Content-Disposition', 'attachment; filename="Datatype_list.' . $request->getRequestFormat() . '";');
            }

            // Symfony should automatically set the response format based on the request format
            $response->setContent($data);
            return $response;
        } catch (\Exception $e) {
            $source = 0x5dc89429;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Utility function to recursively inflate the datatype array for self::datatypelistAction()
     * Can't use the one in the DatabaseInfoService because this array has a different structure
     *
     * @param array $source_data
     * @param array $datatree_array @see DatatreeInfoService::getDatatreeArray()
     * @param integer $parent_datatype_id
     *
     * @return array
     */
    private function inflateDatatypeArray($source_data, $datatree_array, $parent_datatype_id)
    {
        $child_datatype_data = array();

        // Search for any children of the parent datatype
        foreach ($datatree_array['descendant_of'] as $child_dt_id => $parent_dt_id) {
            // If a child was found, and it exists in the source data array...
            if ($parent_dt_id == $parent_datatype_id && isset($source_data[$child_dt_id])) {
                // ...store the child datatype's data
                $child_datatype_data[$child_dt_id] = $source_data[$child_dt_id];

                // ...find all of this datatype's children, if it has any
                $tmp = self::inflateDatatypeArray($source_data, $datatree_array, $child_dt_id);
                if (count($tmp) > 0)
                    $child_datatype_data[$child_dt_id]['child_databases'] = array_values($tmp);
            }
        }

        return $child_datatype_data;
    }


    /**
     * Renders and returns the json/XML version of the given Datatype.
     *
     * @param string $version
     * @param string $datatype_uuid
     * @param string $type
     * @param Request $request
     *
     * @return Response
     */
    public function getDatatypeExportAction($version, $datatype_uuid, $type, Request $request)
    {
        try {
            // ----------------------------------------
            // Default to only showing all info about the datatype/template...
            $display_metadata = true;
            if ($request->query->has('metadata') && $request->query->get('metadata') == 'false')
                // ...but restrict to only the most useful info upon request
                $display_metadata = false;

            // Default to returning the data straight to the browser...
            $download_response = false;
            if ($request->query->has('download') && $request->query->get('download') == 'file')
                // ...but return the data as a download on request
                $download_response = true;

            // This action can list both regular databases and master templates
            // It doesn't make sense to have both in the same output
            // /api/v4/template/{datatype_uuid} now returns a datatype template
            // /api/v4/master/{datatype_uuid} now returns a master datatype template
            // previous API versions return master templates
            $is_master_type = 0;
            if ($type === 'master_template' && $version !== 'v4' && $version !== 'v5')
                $is_master_type = 1;


            // ----------------------------------------
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatatypeExportService $dte_service */
            $dte_service = $this->container->get('odr.datatype_export_service');
            /** @var DatatreeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatree_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->findOneBy(
                array(
                    'unique_id' => $datatype_uuid,
                    'is_master_type' => $is_master_type
                )
            );
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');
            $datatype_id = $datatype->getId();


            $top_level_datatypes = $dti_service->getTopLevelDatatypes();
            if (!in_array($datatype_id, $top_level_datatypes))
                throw new ODRBadRequestException('Only permitted on top-level datatypes');


            // ----------------------------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in

            if (!$pm_service->canViewDatatype($user, $datatype))
                throw new ODRForbiddenException();
            // ----------------------------------------


            // ----------------------------------------
            // Render the requested datatype
            $data = $dte_service->getData(
                $version,
                $datatype_id,
                $request->getRequestFormat(),
                $display_metadata,
                $user,
                $this->container->getParameter('site_baseurl')
            );

            // Set up a response to send the datatype back
            $response = new Response();

            if ($download_response) {
                $response->setPrivate();
                //$response->headers->set('Content-Length', filesize($xml_export_path.$filename));
                $response->headers->set('Content-Disposition', 'attachment; filename="Datatype_' . $datatype_id . '.' . $request->getRequestFormat() . '";');
            }

            $response->setContent($data);
            return $response;
        } catch (\Exception $e) {
            $source = 0x43dd4818;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Returns a list of top-level datarecords for the given datatype that the user is allowed to see.
     *
     * @param string $version
     * @param string $datatype_uuid
     * @param integer $limit
     * @param integer $offset
     * @param Request $request
     *
     * @return Response
     */
    public function getDatarecordListAction($version, $datatype_uuid, $limit, $offset, $recent, Request $request)
    {
        try {
            // ----------------------------------------
            // Default to returning the data straight to the browser...
            $download_response = false;
            if ($request->query->has('download') && $request->query->get('download') == 'file')
                // ...but return the data as a download on request
                $download_response = true;


            // ----------------------------------------
            // Load required objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatatreeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatree_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var SortService $sort_service */
            $sort_service = $this->container->get('odr.sort_service');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->findOneBy(
                array(
                    'unique_id' => $datatype_uuid
                )
            );
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');
            $datatype_id = $datatype->getId();

            $top_level_datatype_ids = $dti_service->getTopLevelDatatypes();
            if (!in_array($datatype_id, $top_level_datatype_ids))
                throw new ODRBadRequestException('Datatype must be top-level');


            // ----------------------------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $can_view_datarecord = $pm_service->canViewNonPublicDatarecords($user, $datatype);

            if (!$pm_service->canViewDatatype($user, $datatype))
                throw new ODRForbiddenException();
            // ----------------------------------------


            // ----------------------------------------
            // Allow users to specify positive integer values less than a billion for these variables
            $offset = intval($offset);
            $limit = intval($limit);

            // If limit is set to 0, then return all results
            if ($limit === 0)
                $limit = 999999999;

            if ($offset >= 1000000000)
                throw new ODRBadRequestException('Offset must be less than a billion');
            if ($limit >= 1000000000)
                throw new ODRBadRequestException('Limit must be less than a billion');

            // $offset is currently the index of the "first" datarecord the user wants...turn $limit
            //  into the index of the "last" datarecord the user wants
            $limit = $offset + $limit;

            // ----------------------------------------
            // Load all top-level datarecords of this datatype that the user can see

            $str =
                'SELECT dr.id AS dr_id, dr.unique_id AS dr_uuid
                FROM ODRAdminBundle:DataRecord AS dr
                LEFT JOIN ODRAdminBundle:DataRecordMeta AS drm WITH drm.dataRecord = dr
                WHERE dr.dataType = :datatype_id
                AND dr.deletedAt IS NULL AND drm.deletedAt IS NULL';
            $params = array('datatype_id' => $datatype_id);
            if (!$can_view_datarecord) {
                $str .= ' AND drm.publicDate != :public_date';
                $params['public_date'] = '2200-01-01 00:00:00';
            }

            if ($recent) {
                $str .= ' AND dr.updated >= :updated_date';

                $updated_date = new \DateTime();
                // return new JsonResponse(['done' => $updated_date]);
                if(preg_match("/^\d+$/",$recent)) {
                    // Recent is a time stamp
                    // Updated last 12 hours...
                    $updated_date = new \DateTime('@' . $recent/1000);
                }
                // return new JsonResponse(['done' => $updated_date]);
                $params['updated_date'] = date_format(
                    date_sub($updated_date, date_interval_create_from_date_string('12 hours')),
                    "Y-m-d H:i:s"
                );
                // Temporary for testing
                // $params['updated_date'] = date_format(
                    // date_sub($updated_date, date_interval_create_from_date_string('90 days')),
                    // "Y-m-d H:i:s"
                // );
                // return new JsonResponse(['done' => $params['updated_date']]);
            }

            $str .= ' ORDER BY dr.id ASC';

            $query = $em->createQuery($str)->setParameters($params);
            $results = $query->getArrayResult();

            // return new JsonResponse(['done' => count($results)]);

            if ($offset > count($results))
                throw new ODRBadRequestException('This database only has ' . count($results) . ' viewable records, but a starting offset of ' . $offset . ' was specified.');

            $dr_list = array();
            $internal_ids = array();
            foreach ($results as $result) {
                $dr_id = $result['dr_id'];
                $dr_uuid = $result['dr_uuid'];

                $internal_ids[] = $result['dr_id'];
                $dr_list[$dr_id] = array(
                    'internal_id' => $dr_id,
                    'unique_id' => $dr_uuid,
                    'external_id' => '',
                    'record_name' => '',
                );
            }

            // If this datatype has an external_id field, make sure the query selects it for the
            //  JSON response
            if ($datatype->getExternalIdField() !== null) {
                // Have the sort service here, somight as well use that
                $external_id_values = $sort_service->sortDatarecordsByDatafield(
                    $datatype->getExternalIdField()->getId(),
                    'asc',
                    join(',', $internal_ids)
                );
                foreach ($external_id_values as $dr_id => $value)
                    $dr_list[$dr_id]['external_id'] = $value;
            }

            // If this datatype has a name field, make sure the query selects it for the JSON response
            if (!empty($datatype->getNameFields())) {
                foreach ($datatype->getNameFields() as $name_df) {
                    // Might as well use the sort service for this too, but it's slightly trickier
                    //  since there could be more than one field making up the name values
                    $values = $sort_service->sortDatarecordsByDatafield(
                        $name_df->getId(),
                        'asc',
                        join(',', $internal_ids)
                    );
                    foreach ($values as $dr_id => $value) {
                        if ($dr_list[$dr_id]['record_name'] === '')
                            $dr_list[$dr_id]['record_name'] = $value;
                        else
                            $dr_list[$dr_id]['record_name'] .= ' ' . $value;
                    }
                }
            }

            // ----------------------------------------
            // Get the sorted list of datarecords
            $sorted_datarecord_list = $sort_service->getSortedDatarecordList(
                $datatype_id,
                join(',', $internal_ids)
            );


            // $sorted_datarecord_list and $dr_list both contain all datarecords of this datatype
            $count = 0;
            $final_datarecord_list = array();
            foreach ($sorted_datarecord_list as $dr_id => $sort_value) {
                // Only save datarecords inside the window that the user specified
                if (isset($dr_list[$dr_id]) && $count >= $offset && $count < $limit)
                    $final_datarecord_list[] = $dr_list[$dr_id];

                $count++;
            }

            // The list needs to be wrapped in another array...
            $final_datarecord_list = array('records' => $final_datarecord_list);

            // ----------------------------------------
            // Symfony already knows the request format due to use of the _format parameter in the route
            $format = $request->getRequestFormat();
            $data = $this->get('templating')->render(
                'ODRAdminBundle:API:datarecord_list.' . $format . '.twig',
                array(
                    'datarecord_list' => $final_datarecord_list,
                )
            );

            // Set up a response to send the datatype back
            $response = new Response();

            if ($download_response) {
                $response->setPrivate();
                //$response->headers->set('Content-Length', filesize($xml_export_path.$filename));
                $response->headers->set('Content-Disposition', 'attachment; filename="Datatype_' . $datatype_uuid . '_list.' . $request->getRequestFormat() . '";');
            }

            $response->setContent($data);
            return $response;
        } catch (\Exception $e) {
            $source = 0xd12ec6ee;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Returns a list of top-level datatypes that are derived from the given template.
     *
     * @param string $version
     * @param string $datatype_uuid
     * @param integer $limit
     * @param integer $offset
     * @param Request $request
     *
     * @return Response
     */
    public function getTemplateDatatypeListAction($version, $datatype_uuid, $limit, $offset, Request $request)
    {
        try {
            // ----------------------------------------
            // Default to returning the data straight to the browser...
            $download_response = false;
            if ($request->query->has('download') && $request->query->get('download') == 'file')
                // ...but return the data as a download on request
                $download_response = true;


            // ----------------------------------------
            // Load required objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatatreeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatree_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var DataType $template_datatype */
            $template_datatype = $em->getRepository('ODRAdminBundle:DataType')->findOneBy(
                array(
                    'unique_id' => $datatype_uuid,
                    'is_master_type' => 1
                )
            );
            if ($template_datatype == null)
                throw new ODRNotFoundException('Datatype');
            $template_datatype_id = $template_datatype->getId();

            $top_level_datatype_ids = $dti_service->getTopLevelDatatypes();
            if (!in_array($template_datatype_id, $top_level_datatype_ids))
                throw new ODRBadRequestException('Datatype must be top-level');


            // ----------------------------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $datatype_permissions = $pm_service->getDatatypePermissions($user);

            // TODO - enforce permissions on template?
//            if ( !$pm_service->canViewDatatype($user, $datatype) )
//                throw new ODRForbiddenException();
            // ----------------------------------------


            // ----------------------------------------
            // Allow users to specify positive integer values less than a billion for these variables
            $offset = intval($offset);
            $limit = intval($limit);

            // If limit is set to 0, then return all results
            if ($limit === 0)
                $limit = 999999999;

            if ($offset >= 1000000000)
                throw new ODRBadRequestException('Offset must be less than a billion');
            if ($limit >= 1000000000)
                throw new ODRBadRequestException('Limit must be less than a billion');


            // ----------------------------------------
            // Load all top-level datatypes that are derived from this template
            $query = $em->createQuery(
                'SELECT
                    dt.id AS database_id, dt.unique_id AS unique_id, dtm.shortName AS database_name,
                    dtm.description AS database_description, dtm.publicDate AS public_date,
                    dtm.searchSlug AS search_slug, mdt.unique_id AS template_id
                FROM ODRAdminBundle:DataType AS dt
                JOIN ODRAdminBundle:DataTypeMeta AS dtm WITH dtm.dataType = dt
                JOIN ODRAdminBundle:DataType AS mdt WITH dt.masterDataType = mdt
                WHERE mdt.unique_id = :template_uuid
                AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL AND mdt.deletedAt IS NULL'
            )->setParameters(
                array(
                    'template_uuid' => $datatype_uuid
                )
            );
            $results = $query->getArrayResult();

            // Only save the datatypes the user is allowed to see
            $datatype_list = array();
            foreach ($results as $result) {
                $is_public = true;
                if ($result['public_date']->format('Y-m-d') === '2200-01-01')
                    $is_public = false;
                unset($result['public_date']);

                $dt_id = $result['database_id'];
                $can_view_datatype = false;
                if (isset($datatype_permissions[$dt_id])
                    && isset($datatype_permissions[$dt_id]['dt_view'])
                ) {
                    $can_view_datatype = true;
                }

                // Organize the datatype list by their internal id
                if ($is_public || $can_view_datatype)
                    $datatype_list[$dt_id] = $result;
            }

            if ($offset > count($datatype_list))
                throw new ODRBadRequestException('This template only has ' . count($datatype_list) . ' viewable databases, but a starting offset of ' . $offset . ' was specified.');


            // ----------------------------------------
            // Apply limit/offset to the list and wrap in another array
            $datatype_list = array('databases' => array_slice($datatype_list, $offset, $limit));

            // Symfony already knows the request format due to use of the _format parameter in the route
            $format = $request->getRequestFormat();
            $data = $this->get('templating')->render(
                'ODRAdminBundle:API:datatype_list.' . $format . '.twig',
                array(
                    'datatype_list' => $datatype_list,
                )
            );

            // Set up a response to send the datatype back
            $response = new Response();

            if ($download_response) {
                $response->setPrivate();
                //$response->headers->set('Content-Length', filesize($xml_export_path.$filename));
                $response->headers->set('Content-Disposition', 'attachment; filename="template_' . $datatype_uuid . '_list.' . $request->getRequestFormat() . '";');
            }

            $response->setContent($data);
            return $response;
        } catch (\Exception $e) {
            $source = 0x1c7b55d0;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Creates a datarecord for an existing dataset.
     * Requires a valid dataset and user permissions.
     *
     * @param string $version
     * @param string $dataset_uuid
     * @param Request $request
     *
     * @return RedirectResponse
     */
    public function createrecordAction($version, $dataset_uuid, Request $request)
    {

        try {

            // Only used if SuperAdmin & Present
            $user_email = null;
            if (isset($_POST['user_email']))
                $user_email = $_POST['user_email'];

            if ($dataset_uuid == null || strlen($dataset_uuid) < 1) {
                throw new ODRNotFoundException('Datatype');
            }

            // Check if user exists & throw user not found error
            /** @var ODRUser $user */  // Anon when nobody is logged in.
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ($user->hasRole('ROLE_SUPER_ADMIN') && $user_email !== null) {
                // Save which user started this creation process
                $user_manager = $this->container->get('fos_user.user_manager');
                /** @var ODRUser $user */
                $user = $user_manager->findUserBy(array('email' => $user_email));
                if (is_null($user))
                    throw new ODRNotFoundException('unrecognized email: "' . $user_email . '"');
            }

            // Check if template is valid
            /** @var EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            /** @var DataType $dataset_datatype */
            $dataset_datatype = $repo_datatype
                ->findOneBy(array('unique_id' => $dataset_uuid));

            if ($dataset_datatype == null)
                throw new ODRNotFoundException('Datatype');

            // Check if can Add Record
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');

            if (!$pm_service->canAddDatarecord($user, $dataset_datatype))
                throw new ODRForbiddenException();

            // Do we handle metadata datatypes??  Probably not...
            // A metadata datarecord doesn't exist...create one
            /** @var EntityCreationService $entity_create_service */
            $entity_create_service = $this->container->get('odr.entity_creation_service');

            $delay_flush = true;
            $dataset_record = $entity_create_service
                ->createDatarecord($user, $dataset_datatype, $delay_flush);

            // Check if record public date needs updating
            // TODO Check User or LoggedInUser is SuperAdmin
            if ($dataset_record && (
                    isset($_POST['public_date'])
                    || isset($_POST['created'])
                )
            ) {
                if ($data_record_meta = $dataset_record->getDataRecordMeta()) {
                    if (isset($_POST['public_date']) && $data_record_meta->getPublicDate()->format("Y-m-d H:i:s") !== $_POST['public_date']) {
                        $data_record_meta->setPublicDate(new \DateTime($_POST['public_date']));
                    }

                    if (isset($_POST['created'])) {
                        self::setDates($data_record_meta, $_POST['created']);
                        self::setDates($dataset_record, $_POST['created']);
                    } else {
                        self::setDates($data_record_meta, null);
                    }

                    if (
                        !$pm_service->isDatatypeAdmin($user, $dataset_record->getDataType())
                        && !$pm_service->canAddDatarecord($user, $dataset_record->getDataType())
                    ) {
                        throw new ODRForbiddenException();
                    }

                    $em->persist($data_record_meta);
                }
            }

            // Datarecord is ready, remove provisioned flag
            // TODO Naming is a little weird here
            $dataset_record->setProvisioned(false);
            $em->persist($dataset_record);
            $em->flush();

            // This is wrapped in a try/catch block because any uncaught exceptions will abort
            //  creation of the new datarecord...
            try {
                // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                /** @var EventDispatcherInterface $event_dispatcher */
                $dispatcher = $this->get('event_dispatcher');
                $event = new DatarecordCreatedEvent($dataset_record, $user);
                $dispatcher->dispatch(DatarecordCreatedEvent::NAME, $event);
            } catch (\Exception $e) {
                // ...don't particularly want to rethrow the error since it'll interrupt
                //  everything downstream of the event (such as file encryption...), but
                //  having the error disappear is less ideal on the dev environment...
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

            // ----------------------------------------
            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');
            $cache_service->delete('datatype_' . $dataset_record->getDataType()->getId() . '_record_order');

            $response = new Response('Created', 201);
            $url = $this->generateUrl('odr_api_get_dataset_record', array(
                'version' => $version,
                'record_uuid' => $dataset_record->getUniqueId()
            ), false);
            $response->headers->set('Location', $url);

            return $this->redirect($url);
        } catch (\Exception $e) {
            $source = 0x773df3ed;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

    }


    /**
     * TODO - this action does nothing, and isn't referenced from the routing file...delete?
     *
     * @param string $version
     * @param Request $request
     */
    public function assignPermission($version, Request $request)
    {
        try {

            // Accept JSON or POST?
            // POST Params
            // user_email:nancy.drew@detectivemysteries.com
            // first_name:Nancy
            // last_name:Drew
            // dataset_name:A New Dataset
            // template_uuid:uuid of a template


            $user_email = null;
            if (isset($_POST['user_email']))
                $user_email = $_POST['user_email'];

            // We must check if the logged in user is acting as a user
            // When acting as a user, the logged in user must be a SuperAdmin
            /** @var ODRUser $logged_in_user */
            $logged_in_user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            if ($user_email === '') {
                // User is setting up dataset for themselves - always allowed
                $user_email = $logged_in_user->getEmail();
            } else if (!$logged_in_user->hasRole('ROLE_SUPER_ADMIN')) {
                // We are acting as a user and do not have Super Permissions - Forbidden
                throw new ODRForbiddenException();
            }

            // Check if user exists & throw user not found error
            // Save which user started this creation process
            // Any user can create a dataset as long as they exist
            // No need to do further permissions checks.
            $user_manager = $this->container->get('fos_user.user_manager');
            /** @var ODRUser $user */
            $user = $user_manager->findUserBy(array('email' => $user_email));
            if (is_null($user))
                throw new ODRNotFoundException('unrecognized email: "' . $user_email . '"');

        } catch (\Exception $e) {
            $source = 0x88a02ef3;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Creates a dataset by cloning the requested master template.
     * Requires a valid master template with metadata template
     *
     * @param string $version
     * @param Request $request
     *
     * @return RedirectResponse
     */
    public function createdatasetAction($version, Request $request)
    {

        try {

            // Accept JSON or POST?
            // POST Params
            // user_email:nancy.drew@detectivemysteries.com
            // first_name:Nancy
            // last_name:Drew
            // dataset_name:A New Dataset
            // template_uuid:uuid of a template


            $user_email = null;
            if (isset($_POST['user_email']))
                $user_email = $_POST['user_email'];

            if (!isset($_POST['template_uuid']))
                throw new ODRBadRequestException("Template UUID is required.");

            $template_uuid = $_POST['template_uuid'];

            // we must check if the logged in user is acting as a user
            // when acting as a user, the logged in user must be a superadmin
            /** @var ODRUser $logged_in_user */
            $logged_in_user = $this->container->get('security.token_storage')->gettoken()->getuser();   // <-- will return 'anon.' when nobody is logged in
            if ($user_email === '') {
                // user is setting up dataset for themselves - always allowed
                $user_email = $logged_in_user->getEmail();
            } else if (!$logged_in_user->hasRole('role_super_admin')) {
                // we are acting as a user and do not have super permissions - forbidden
                throw new ODRForbiddenException();
            }

            // check if user exists & throw user not found error
            // save which user started this creation process
            // any user can create a dataset as long as they exist
            // no need to do further permissions checks.
            $user_manager = $this->container->get('fos_user.user_manager');
            /** @var ODRUser $user */
            $user = $user_manager->finduserby(array('email' => $user_email));
            if (is_null($user))
                throw new ODRNotFoundException('unrecognized email: "' . $user_email . '"');

            // Check if template is valid
            /** @var EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            /** @var DataType $master_datatype */
            $master_datatype = $repo_datatype
                ->findOneBy(array('unique_id' => $template_uuid));

            if ($master_datatype == null)
                throw new ODRNotFoundException('Datatype');

            // If a metadata datatype is loaded directly, need to create full template
            if ($metadata_for = $master_datatype->getMetadataFor()) {
                $master_datatype = $metadata_for;
            }

            // Check here if a database exists in "preload" mode with correct version
            /** @var DataType[] $datatypes */
            $datatypes = $repo_datatype->findBy([
                'masterDataType' => $master_datatype->getMetadataDatatype()->getId(),
                'preload_status' => $master_datatype->getMetadataDatatype()->getDataTypeMeta()->getMasterRevision()
            ]);

            /*
            print $master_datatype->getMetadataDatatype()->getId() . " -- ";
            print $master_datatype->getMetadataDatatype()->getDataTypeMeta()->getMasterRevision() . " -- ";
            print count($datatypes) . " - ";
            print $datatypes[0]->getId(); exit();
            */

            $datatype = null;
            if (count($datatypes) > 0) {
                // Use the prebuilt datatype
                // This is a metadata datatype
                /** @var DataType $metadata_datatype */
                $metadata_datatype = $datatypes[0];

                /** @var \DateTime $date_value */
                $date_value = new \DateTime();

                /** @var DataType[] $related_datatypes */
                $related_datatypes = $repo_datatype->findBy([
                    'template_group' => $metadata_datatype->getTemplateGroup()
                ]);
                foreach ($related_datatypes as $related_datatype) {
                    $related_datatype->setCreatedBy($user);
                    $related_datatype->setUpdatedBy($user);
                    $related_datatype->setCreated($date_value);
                    $related_datatype->setUpdated($date_value);
                    $related_datatype->setPreloadStatus('issued');
                    $permission_groups = $related_datatype->getGroups();

                    /** @var Group[] $permission_groups */
                    foreach ($permission_groups as $group) {
                        if ($group->getPurpose() == 'admin') {
                            $user_group = new UserGroup();
                            $user_group->setUser($user);
                            $user_group->setGroup($group);
                            $user_group->setCreated($date_value);
                            $user_group->setCreatedBy($user);
                            $em->persist($user_group);
                        }
                    }
                }

                /** @var DataRecord $metadata_record */
                $metadata_record = $em->getRepository('ODRAdminBundle:DataRecord')
                    ->findOneBy(array('dataType' => $metadata_datatype->getId()));

                $metadata_record->setCreated($date_value);
                $metadata_record->setCreatedBy($user);
                $metadata_record->setUpdated($date_value);
                $metadata_record->setUpdatedBy($user);

                $em->persist($metadata_record);
                // Updating datatype info
                $em->flush();

                /** @var CacheService $cache_service */
                $cache_service = $this->container->get('odr.cache_service');
                $cache_service->delete('user_' . $user->getId() . '_permissions');

                // Now get the json record and update it with the correct user_id ant date times
                $json_metadata_record = $cache_service
                    ->get('json_record_' . $metadata_record->getUniqueId());


                if (!$json_metadata_record) {
                    // Need to pull record using getExport...
                    $json_metadata_record = self::getRecordData(
                        'v3',
                        $metadata_record->getUniqueId(),
                        'json',
                        $user
                    );

                    if ($json_metadata_record) {
                        $json_metadata_record = json_decode($json_metadata_record, true);
                    }
                } else {
                    // Check if dataset has public attribute
                    $json_metadata_record = json_decode($json_metadata_record, true);
                }

                // parse through and fix metadata
                $json_metadata_record = self::checkRecord($json_metadata_record, $user, $date_value);

                $cache_service->set('json_record_' . $metadata_record->getUniqueId(), json_encode($json_metadata_record));

                // set the "datatype" to the metadata datatype
                $datatype = $metadata_datatype;
            } else {
                /** @var DatatypeCreateService $dtc_service */
                $dtc_service = $this->container->get('odr.datatype_create_service');

                /** @var DataType $datatype */
                $datatype = $dtc_service->direct_add_datatype(
                    $master_datatype->getId(),
                    0,
                    $user,
                    true
                );

                // ----------------------------------------
                // Both paths of $dtc_service->direct_add_datatype() call CloneMasterDatatypeService,
                //  so don't need to fire off a DatatypeCreated event for the new datatype here
//                try {
//                    // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
//                    //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
//                    /** @var EventDispatcherInterface $event_dispatcher */
//                    $dispatcher = $this->get('event_dispatcher');
//                    $event = new DatatypeCreatedEvent($datatype, $user);
//                    $dispatcher->dispatch(DatatypeCreatedEvent::NAME, $event);
//                }
//                catch (\Exception $e) {
//                    // ...don't want to rethrow the error since it'll interrupt everything after this
//                    //  event
////                if ( $this->container->getParameter('kernel.environment') === 'dev' )
////                    throw $e;
//                }

                // Return metadata datatype if one exists
                if ($metadata_datatype = $datatype->getMetadataDatatype()) {
                    $datatype = $metadata_datatype;
                }
            }
            // If this is a metadata type get the first record

            // Retrieve first (and only) record ...
            /** @var DataRecord $metadata_record */
            $metadata_record = $em->getRepository('ODRAdminBundle:DataRecord')
                ->findOneBy(array('dataType' => $datatype->getId()));

            if (!$metadata_record) {
                // A metadata datarecord doesn't exist...create one
                /** @var EntityCreationService $entity_create_service */
                $entity_create_service = $this->container->get('odr.entity_creation_service');

                $delay_flush = true;
                $metadata_record = $entity_create_service
                    ->createDatarecord($user, $datatype, $delay_flush);

                // Datarecord is ready, remove provisioned flag
                // TODO Naming is a little weird here
                $metadata_record->setProvisioned(false);
                $em->flush();

                // This is wrapped in a try/catch block because any uncaught exceptions will abort
                //  creation of the new datarecord...
                try {
                    // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                    //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                    /** @var EventDispatcherInterface $event_dispatcher */
                    $dispatcher = $this->get('event_dispatcher');
                    $event = new DatarecordCreatedEvent($metadata_record, $user);
                    $dispatcher->dispatch(DatarecordCreatedEvent::NAME, $event);
                } catch (\Exception $e) {
                    // ...don't particularly want to rethrow the error since it'll interrupt
                    //  everything downstream of the event (such as file encryption...), but
                    //  having the error disappear is less ideal on the dev environment...
//                    if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                        throw $e;
                }
            }

            // Retrieve first (and only) record ...
            if ($datatype->getMetadataFor()) {

                /** @var DataRecord $metadata_record */
                $actual_data_record = $em->getRepository('ODRAdminBundle:DataRecord')
                    ->findOneBy(array('dataType' => $datatype->getMetadataFor()->getId()));

                if (!$actual_data_record) {
                    // A metadata datarecord doesn't exist...create one
                    /** @var EntityCreationService $entity_create_service */
                    $entity_create_service = $this->container->get('odr.entity_creation_service');

                    $delay_flush = true;
                    $actual_data_record = $entity_create_service
                        ->createDatarecord($user, $datatype->getMetadataFor(), $delay_flush);

                    // Datarecord is ready, remove provisioned flag
                    // TODO Naming is a little weird here
                    $actual_data_record->setProvisioned(false);
                    $em->flush();

                    // This is wrapped in a try/catch block because any uncaught exceptions will abort
                    //  creation of the new datarecord...
                    try {
                        // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                        //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                        /** @var EventDispatcherInterface $event_dispatcher */
                        $dispatcher = $this->get('event_dispatcher');
                        $event = new DatarecordCreatedEvent($actual_data_record, $user);
                        $dispatcher->dispatch(DatarecordCreatedEvent::NAME, $event);
                    } catch (\Exception $e) {
                        // ...don't particularly want to rethrow the error since it'll interrupt
                        //  everything downstream of the event (such as file encryption...), but
                        //  having the error disappear is less ideal on the dev environment...
//                        if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                            throw $e;
                    }
                }

            }
//            // Set name field?
//
//            /** @var DataFields $name_field */
//            $name_field = $datatype->getNameField();
//            if ($name_field) {
//                // We have a name field
//                /** @var DataRecordFields $drf */
//                $drf = $em->getRepository('ODRAdminBundle:DataRecordFields')->findOneBy(
//                    array(
//                        'dataRecord' => $metadata_record->getId(),
//                        'dataField' => $name_field->getId()
//                    )
//                );
//
//                if ($drf) {
//                    /** @var LongText $new_field */
//                    $new_field = new LongText();
//                    switch ($name_field->getFieldType()) {
//                        case '5':
//                            /** @var LongText $new_field */
//                            $new_field = new LongText();
//                            break;
//                        case '6':
//                            /** @var LongVarchar $new_field */
//                            $new_field = new LongVarchar();
//                            break;
//                        case '7':
//                            /** @var MediumVarchar $new_field */
//                            $new_field = new MediumVarchar();
//                            break;
//                        case '9':
//                            /** @var ShortVarchar $new_field */
//                            $new_field = new ShortVarchar();
//                            break;
//                    }
//
//                    $new_field->setDataField($name_field);
//                    $new_field->setDataRecord($metadata_record);
//                    $new_field->setDataRecordFields($drf);
//                    $new_field->setFieldType($name_field->getFieldType());
//
//                    $new_field->setCreatedBy($user);
//                    $new_field->setUpdatedBy($user);
//                    $new_field->setCreated(new \DateTime());
//                    $new_field->setUpdated(new \DateTime());
//                    $new_field->setValue($dataset_name);
//                    $em->persist($new_field);
//
//                    $em->flush();
//                }
//            }

            $response = new Response('Created', 201);
            $url = $this->generateUrl('odr_api_get_dataset_record', array(
                'version' => $version,
                'record_uuid' => $metadata_record->getUniqueId()
            ), false);
            $response->headers->set('Location', $url);

            return $this->redirect($url);
        } catch (\Exception $e) {
            $source = 0x89adf33e;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

    }

    /**
     * @param array $record
     * @param ODRUser $user
     * @param \DateTime $datetime_value
     *
     * @return array
     */
    private function checkRecord(&$record, $user, $datetime_value)
    {
        if (isset($record['_record_metadata'])) {
            $record['_record_metadata']['_create_auth'] = $user->getEmailCanonical();
            $record['_record_metadata']['_create_date'] = $datetime_value->format('Y-m-d H:i:s');
        }

        $output_records = array();
        foreach ($record['records'] as $child_record) {
            $output_records[] = self::checkRecord($child_record, $user, $datetime_value);
        }
        $record['records'] = $output_records;
        return $record;
    }

    /**
     * @param array $tag_tree
     * @param array $selected_tags
     */
    private function selectedTags($tag_tree, &$selected_tags = array())
    {
        foreach ($tag_tree as $tag) {
            if (isset($tag['selected']) && $tag['selected'] == 1) {
                array_push($selected_tags, $tag['template_tag_uuid']);
            }

            if (isset($tag['children']) && is_array($tag['children']) && count($tag['children']) > 0) {
                self::selectedTags($tag['children'], $selected_tags);
            }
        }
    }


    /**
     * Checks if the changes can successfully be completed by the user.
     *
     * @param $dataset
     * @param $orig_dataset
     * @param $user
     * @param $top_level
     * @param $changed
     * @return bool  - true if capable, false if lacking permissions
     * @throws \Exception
     */
    private function checkUpdatePermissions($dataset, $orig_dataset, $user, $top_level, &$changed)
    {
        // Check if radio options are added or updated
        /*
            {
                "name": "geochemistry",
                "template_radio_option_uuid": "0730d71",
                "updated_at": "2018-09-25 16:44:54",
                "id": 58272,
                "selected": "1"
            },
        */

        // Check if fields are added or updated
        $fields_updated = false;
        try {
            $exception_source = 0xc8b8c8b7;

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');

            // Check Fields
            /** @var DataRecord $data_record */
            $data_record = $em->getRepository('ODRAdminBundle:DataRecord')->findOneBy(
                array(
                    'unique_id' => $dataset['record_uuid']
                )
            );

            if ($data_record && (
                    isset($dataset['public_date'])
                    || isset($dataset['created'])
                )
            ) {
                if (
                    !$pm_service->isDatatypeAdmin($user, $data_record->getDataType())
                    && !$pm_service->canAddDatarecord($user, $data_record->getDataType())
                ) {
                    return false;
                }
            }

            // TODO If Fields Updated, need to check if user can edit record
            if (isset($dataset['fields'])) {
                for ($i = 0; $i < count($dataset['fields']); $i++) {
                    $field = $dataset['fields'][$i];

                    // Determine field type
                    $data_field = null;
                    if ( isset($field['template_field_uuid']) && $field['template_field_uuid'] !== null ) {
                        /** @var DataFields $data_field */
                        $data_field = $em->getRepository('ODRAdminBundle:DataFields')->findOneBy(
                            array(
                                'templateFieldUuid' => $field['template_field_uuid'],
                                'dataType' => $data_record->getDataType()->getId()
                            )
                        );

                        if ($data_field == null)
                            throw new ODRNotFoundException('Unable to find the datafield with template_field_uuid "'.$field['template_field_uuid'].'" for the datatype "'.$data_record->getDataType()->getUniqueId().'"', true);
                    }
                    else if ( isset($field['field_uuid']) && $field['field_uuid'] !== null ) {
                        /** @var DataFields $data_field */
                        $data_field = $em->getRepository('ODRAdminBundle:DataFields')->findOneBy(
                            array(
                                'fieldUuid' => $field['field_uuid'],
                                'dataType' => $data_record->getDataType()->getId()
                            )
                        );

                        if ($data_field == null)
                            throw new ODRNotFoundException('Unable to find the datafield with field_uuid "'.$field['field_uuid'].'" for the datatype "'.$data_record->getDataType()->getUniqueId().'"', true);
                    }
                    else {
                        throw new ODRBadRequestException('Datafield entries must have either a template_field_uuid or a field_uuid set');
                    }

                    $typeclass = $data_field->getFieldType()->getTypeClass();
                    $typename = $data_field->getFieldType()->getTypeName();

                    // Deal with files and images here
                    if ($typename === 'File' || $typename === 'Image') {
                        if (isset($field['files']) && is_array($field['files']) && count($field['files']) > 0) {
                            foreach ($field['files'] as $file) {
                                if (isset($file['public_date']) || isset($file['quality'])) {
                                    $fields_updated = true;
                                }
                            }
                        }
                    } else if ($typename === 'Boolean') {
                        /** @var DataRecordFields $drf */
                        $drf = $em->getRepository('ODRAdminBundle:DataRecordFields')->findOneBy(
                            array(
                                'dataRecord' => $dataset['internal_id'],
                                'dataField' => $data_field->getId()
                            )
                        );

                        // TODO This is a field creation event - needs permissions check
                        if (!$drf) {
                            // TODO Permissions Check
                            $fields_updated = true;
                        } else {
                            // Lookup Boolean by DRF & Field ID
                            /** @var Boolean $bool */
                            $bool = $em->getRepository('ODRAdminBundle:Boolean')->findOneBy(
                                array(
                                    'dataRecordFields' => $drf->getId()
                                )
                            );

                            if ($bool) {
                                // check if value matches field->selected
                                if ($bool->getValue() !== $field['selected']) {
                                    // remove old entity
                                    $fields_updated = true;
                                }
                            } else {
                                $fields_updated = true;
                            }
                        }
                    }
                    else if ( isset($field['tags']) && is_array($field['tags']) ) {

                        switch ($typename) {

                            // Tag field - need to difference hierarchy
                            case 'Tags':
                                // Determine selected tags in original dataset
                                // Determine selected tags in current
                                // print $field['template_field_uuid']."\n";

                                $selected_tags = array();
                                self::selectedTags($field['value'], $selected_tags);

                                $orig_selected_tags = array();
                                $orig_tag_field = array();
                                if ($orig_dataset) {
                                    foreach ($orig_dataset['fields'] as $o_field) {
                                        if (
                                            isset($o_field['value']) &&
                                            isset($field['field_uuid']) &&
                                            $o_field['field_uuid'] == $field['field_uuid']
                                        ) {
                                            $orig_tag_field = $o_field['value'];
                                            self::selectedTags($o_field['value'], $orig_selected_tags);
                                        }
                                    }
                                }

                                $new_tags = array();
                                $deleted_tags = array();

                                // check for new tags
                                foreach ($selected_tags as $tag) {
                                    $found = false;
                                    foreach ($orig_selected_tags as $o_tag) {
                                        if ($tag == $o_tag) {
                                            $found = true;
                                        }
                                    }
                                    if (!$found) {
                                        array_push($new_tags, $tag);
                                    }
                                }

                                // Check for deleted tags
                                foreach ($orig_selected_tags as $o_tag) {
                                    $found = false;
                                    foreach ($selected_tags as $tag) {
                                        if ($tag == $o_tag) {
                                            $found = true;
                                        }
                                    }
                                    if (!$found) {
                                        array_push($deleted_tags, $o_tag);
                                    }
                                }

                                /** @var DataRecordFields $drf */
                                $drf = $em->getRepository('ODRAdminBundle:DataRecordFields')
                                    ->findOneBy(
                                        array(
                                            'dataRecord' => $dataset['internal_id'],
                                            'dataField' => $data_field->getId()
                                        )
                                    );

                                // Delete deleted tags
                                foreach ($deleted_tags as $tag_uuid) {
                                    /** @var Tags $tag */
                                    $tag = $em->getRepository('ODRAdminBundle:Tags')->findOneBy(
                                        array(
                                            'tagUuid' => $tag_uuid,
                                            'dataField' => $data_field->getId()
                                        )
                                    );
                                    /** @var TagSelection $tag_selection */
                                    $tag_selection = $em->getRepository('ODRAdminBundle:TagSelection')
                                        ->findOneBy(
                                            array(
                                                'tag' => $tag->getId(),
                                                'dataRecordFields' => $drf->getId()
                                            )
                                        );

                                    $fields_updated = true;
                                }


                                // Check if new tag exists in template
                                // Add to template if not exists
                                foreach ($new_tags as $tag_uuid) {
                                    // Lookup Tag by UUID
                                    /** @var Tags $tag */
                                    $tag = $em->getRepository('ODRAdminBundle:Tags')->findOneBy(
                                        array(
                                            'tagUuid' => $tag_uuid,
                                            'dataField' => $data_field->getId()
                                        )
                                    );

                                    if (!$tag) {
                                        // We need Datatype Admin Perms
                                        if (
                                            !$pm_service->isDatatypeAdmin($user, $data_record->getDataType())
                                            && !$pm_service->canEditDatatype($user, $data_record->getDataType())
                                        ) {
                                            return false;
                                        }
                                    }


                                    // User Added Options
                                    $fields_updated = true;

                                }

                                break;
                        }
                    }
                    else if ( isset($field['values']) && is_array($field['values']) ) {

                        switch ( $typename ) {
                            case 'Single Radio':
                            case 'Multiple Radio':
                            case 'Single Select':
                            case 'Multiple Select':
                                // Determine whether the user is allowed to make the changes they're
                                //  requesting to this field...
                                $radio_field_changed = self::checkRadioFieldPermissions($em, $pm_service, $user, $data_record, $data_field, $orig_dataset, $field);
                                if ($radio_field_changed)
                                    $fields_updated = true;
                                break;

                            default:
                                throw new ODRBadRequestException('Structure for Radio fields used on '.$typeclass.' Field '.$data_field->getFieldUuid(), $exception_source);
                        }
                    }
                    else if (isset($field['value'])) {
                        // Field is singular data field
                        $drf = false;
                        $field_changes = true;
                        if ($orig_dataset) {
                            foreach ($orig_dataset['fields'] as $o_field) {
                                // If we find a matching field....
                                if (isset($o_field['value']) && !is_array($o_field['value'])
                                    && (
                                        (
                                            isset($o_field['template_field_uuid'])
                                            && isset($field['template_field_uuid'])
                                            && $o_field['template_field_uuid'] !== null
                                            && $o_field['template_field_uuid'] == $field['template_field_uuid']
                                        )
                                        || (
                                            isset($field['field_uuid']) && $o_field['field_uuid'] == $field['field_uuid']
                                        )
                                    )
                                ) {
                                    if ($o_field['value'] !== $field['value']) {
                                        // Update value to new value (delete and enter new data)
                                        /** @var DataRecordFields $drf */
                                        $drf = $em->getRepository('ODRAdminBundle:DataRecordFields')->findOneBy(
                                            array(
                                                'dataRecord' => $dataset['internal_id'],
                                                'dataField' => $data_field->getId()
                                            )
                                        );
                                    } else {
                                        // No changes necessary - field values match
                                        $field_changes = false;
                                    }
                                }
                            }
                        }
                        if ($field_changes) {
                            // Changes are required or a field needs to be added.
                            $fields_updated = true;
                        }
                    }
                }
            }

            // If at least one of the fields is going to get changed...
            if ( $fields_updated ) {
                // ...ensure the user can modify the record
                $changed = true;
                if ( !$pm_service->canEditDatarecord($user, $data_record) )
                    throw new ODRForbiddenException('Not allowed to edit the Record '.$data_record->getUniqueId(), $exception_source);
            }


            // Remove deleted [related] records
            if ($orig_dataset && isset($orig_dataset['records'])) {
                // Check if old record exists and delete if necessary...
                for ($i = 0; $i < count($orig_dataset['records']); $i++) {
                    $o_record = $orig_dataset['records'][$i];

                    $record_found = false;
                    // Check if record_uuid and template_uuid match - if so we're differencing
                    for ($j = 0; $j < count($dataset['records']); $j++) {
                        $record = $dataset['records'][$j];
                        // New records don't have UUIDs and need to be ignored in this check
                        if (
                            isset($record['record_uuid'])
                            && !empty($record['record_uuid'])
//                            && $record['template_uuid'] == $o_record['template_uuid']
                            && $record['database_uuid'] == $o_record['database_uuid']
                            && $record['record_uuid'] == $o_record['record_uuid']
                        ) {
                            $record_found = true;
                        }
                    }

                    // TODO Check here if user has permission to delete
                    if (!$record_found) {
                        // Recursively build list of record ids
                        $records_to_delete = [];
                        // print var_export($o_record);exit();
                        self::getRecordsToDelete($records_to_delete, $o_record);

                        foreach ($records_to_delete as $record_to_delete) {
                            // Use delete record
                            /** @var DataRecord $del_record */
                            $del_record = $em->getRepository('ODRAdminBundle:DataRecord')
                                ->findOneBy(
                                    array(
                                        'unique_id' => $record_to_delete
                                    )
                                );


                            // TODO Need to recursively delete records here....
                            if ($del_record) {
                                $changed = true;
                                if (
                                    !$pm_service->isDatatypeAdmin($user, $del_record->getDataType())
                                    && !$pm_service->canDeleteDatarecord($user, $del_record->getDataType())
                                ) {
                                    return false;
                                }
                            }
                        }
                    }
                }
            }

            // Need to check for child & linked records
            // Create child if new one added
            // Create link if needed (possibly creating record in link)
            // Search for record to link??
            if (isset($dataset['records'])) {

                for ($i = 0; $i < count($dataset['records']); $i++) {
                    $record = $dataset['records'][$i];

                    $record_found = false;
                    if ($orig_dataset && isset($orig_dataset['records'])) {
                        // Check if record_uuid and template_uuid match - if so we're differencing
                        for ($j = 0; $j < count($orig_dataset['records']); $j++) {
                            $o_record = $orig_dataset['records'][$j];
                            if (
                                isset($record['record_uuid'])
                                && (
//                                    $record['template_uuid'] == $o_record['template_uuid']
                                    $record['database_uuid'] == $o_record['database_uuid']
                                    && $record['record_uuid'] == $o_record['record_uuid']
                                )
                            ) {
                                $record_found = true;
                                // Check for differences
                                // Permissions will be implicit here
                                $dataset['records'][$i] = self::checkUpdatePermissions($record, $o_record, $user, false, $changed);
                            }
                        }
                    }
                    if (!$record_found) {
                        /** @var DataType $record_data_type */
                        $record_data_type = $em->getRepository('ODRAdminBundle:DataType')->findOneBy(
                            array(
                                'unique_id' => $record['database_uuid']
                            )
                        );

                        // Determine if datatype is a link
                        $is_link = false;
                        /** @var DataTree $datatree */
                        $datatree = $em->getRepository('ODRAdminBundle:DataTree')->findOneBy(
                            array(
                                'ancestor' => $data_record->getDataType()->getId(),
                                'descendant' => $record_data_type->getId()
                            )
                        );
                        if ($datatree == null)
                            throw new ODRNotFoundException('Datatree');


                        if ($datatree->getIsLink()) {
                            $is_link = true;
                        }

                        // If the record has a record_uuid, we just need to make the link
                        if ($is_link && isset($record['record_uuid']) && strlen($record['record_uuid']) > 0) {
                            /** @var DataRecord $data_record */
                            $linked_data_record = $em->getRepository('ODRAdminBundle:DataRecord')->findOneBy(
                                array(
                                    'unique_id' => $record['record_uuid']
                                )
                            );

                            if (is_null($linked_data_record))
                                throw new ODRNotFoundException('DataRecord');

                            // Need to persist and flush
                            if (
                                !$pm_service->isDatatypeAdmin($user, $linked_data_record->getDataType())
                                && !$pm_service->canAddDatarecord($user, $linked_data_record->getDataType())
                            ) {
                                return false;
                            }
                        } else {
                            // Need to persist and flush
                            if (
                                !$pm_service->isDatatypeAdmin($user, $record_data_type)
                                && !$pm_service->canAddDatarecord($user, $record_data_type)
                            ) {
                                return false;
                            }

                        }

                    }
                }
            }

            // If we made it here, the user has permission
            return true;
        } catch (\Exception $e) {
            throw $e;
        }
    }


    /**
     * Each of the four radio typeclasses requires the same steps to determine whether the user is
     * allowed to make any changes to them.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param PermissionsManagementService $pm_service
     * @param ODRUser $user
     * @param DataRecord $datarecord The datarecord the user might be modifying
     * @param DataFields $datafield The datafield the user might be modifying
     * @param array $orig_dataset The array version of the current record
     * @param array $field An array of the state the user wants to field to be in after datasetDiff()
     * @throws ODRException
     * @return bool
     */
    private function checkRadioFieldPermissions($em, $pm_service, $user, $datarecord, $datafield, $orig_dataset, $field)
    {
        // Going to need these
        $selected_options = $field['values'];
        $exception_source = 0x4b879e27;

        $repo_dataRecordFields = $em->getRepository('ODRAdminBundle:DataRecordFields');
        $repo_radioOptions = $em->getRepository('ODRAdminBundle:RadioOptions');
        $repo_radioSelections = $em->getRepository('ODRAdminBundle:RadioSelection');

        // If the datafield only allows a single radio selection...
        $typename = $datafield->getFieldType()->getTypeName();
        if ( $typename === 'Single Radio' || $typename === 'Single Select' ) {
            // ...then need to throw an error if the user wants to leave the field in a state where
            //  it has multiple radio options selected
            if ( count($selected_options) > 1 )
                throw new ODRBadRequestException('Field '.$datafield->getFieldUuid().' is not allowed to have multiple options selected', $exception_source);
        }


        // ----------------------------------------
        // Locate the currently selected options in the dataset
        $orig_selected_options = array();
        if ($orig_dataset) {
            foreach ($orig_dataset['fields'] as $o_field) {
                if (
                    isset($o_field['values']) &&
                    isset($field['field_uuid']) &&
                    $o_field['field_uuid'] == $field['field_uuid']
                ) {
                    $orig_selected_options = $o_field['values'];
                    break;
                }
            }
        }

        // Determine whether the submitted dataset will select/create any options, or unselect an option
        $new_options = array();
        $deleted_options = array();

        // Check for new options
        foreach ($selected_options as $option) {
            $found = false;
            foreach ($orig_selected_options as $o_option) {
                if ($option == $o_option)
                    $found = true;
            }

            if (!$found)
                array_push($new_options, $option['template_radio_option_uuid']);
        }

        // Check for deleted options
        foreach ($orig_selected_options as $o_option) {
            $found = false;
            foreach ($selected_options as $option) {
                if ($option == $o_option)
                    $found = true;
            }

            if (!$found)
                array_push($deleted_options, $o_option['template_radio_option_uuid']);
        }


        // ----------------------------------------
        // Need to determine whether a change is taking place
        $changed = false;
        $drf = null;

        // Determine whether an option got deselected
        foreach ($deleted_options as $option_uuid) {
            if ( is_null($drf) ) {
                /** @var DataRecordFields $drf */
                $drf = $repo_dataRecordFields->findOneBy(
                    array(
                        'dataRecord' => $datarecord->getId(),
                        'dataField' => $datafield->getId()
                    )
                );
            }
            // In order for the API call to be able to deselect a radio option, the drf entry must
            //  exist beforehand...so there's no need to check for whether it does or not

            /** @var RadioOptions $option */
            $option = $repo_radioOptions->findOneBy(
                array(
                    'radioOptionUuid' => $option_uuid,
                    'dataField' => $datafield->getId()
                )
            );
            /** @var RadioSelection $option_selection */
            $option_selection = $repo_radioSelections->findOneBy(
                array(
                    'radioOption' => $option->getId(),
                    'dataRecordFields' => $drf->getId()
                )
            );

            if ($option_selection) {
                // The option exists, so it will get deselected...determine whether the user
                //  has the permissions to do so
                $changed = true;
                if ( !$pm_service->canEditDatafield($user, $datafield) )
                    throw new ODRForbiddenException('Not allowed to deselect options in the Field '.$datafield->getFieldUuid(), $exception_source);
            }
        }


        // Determine whether an existing option got selected, or a new option needs to get created
        foreach ($new_options as $option_uuid) {
            $changed = true;

            // Lookup Option by UUID
            /** @var RadioOptions $option */
            $option = $repo_radioOptions->findOneBy(
                array(
                    'radioOptionUuid' => $option_uuid,
                    'dataField' => $datafield->getId(),
                )
            );

            if (!$option) {
                // The option doesn't exist, so it will get created...determine whether the user
                //  has the permissions to do so
                if ( !$pm_service->isDatatypeAdmin($user, $datafield->getDataType()) )
                    throw new ODRForbiddenException('Not allowed to create options for the Field '.$datafield->getFieldUuid(), $exception_source);
            }
            else {
                // The option exists, so it will get selected...determine whether the user has the
                //  permissions to do so
                if ( !$pm_service->canEditDatafield($user, $datafield) )
                    throw new ODRForbiddenException('Not allowed to select options in the Field '.$datafield->getFieldUuid(), $exception_source);
            }

            // Note that ValidUtility has functions to check whether radio options or tags are
            //  valid...but those aren't needed because the API will create them if they don't exist
        }

        // If this point is reached, either the user has permissions, or no changes are being made
        return $changed;
    }


    /**
     * Updates the metadata in the submitted dataset to match the database.
     *
     * @param array $field
     * @param DataFields $data_field
     * @param mixed $new_field
     */
    private function fieldMeta(&$field, $data_field, $new_field)
    {
        if (isset($field['_field_metadata'])) {
            if (method_exists($new_field, 'getCreatedBy')) {
                $field['_field_metadata']['_create_auth'] = $new_field->getCreatedBy()->getEmailCanonical();
            }
            if (method_exists($new_field, 'getCreated')) {
                $field['_field_metadata']['_create_date'] = $new_field->getCreated()->format('Y-m-d H:i:s');
                // print $field['field_name']. " - ";
                // print $field['_field_metadata']['_create_date']. " - ";
            }
            if (method_exists($new_field, 'getUpdated')) {
                $field['_field_metadata']['_update_date'] = $new_field->getUpdated()->format('Y-m-d H:i:s');
                // print $field['_field_metadata']['_update_date'];
            }
            if (method_exists($data_field, 'getPublicDate')) {
                $field['_field_metadata']['_public_date'] = $data_field->getPublicDate()->format('Y-m-d H:i:s');
            }
        }
        unset($field['created']);
    }


    /**
     * Updates the given entity's created/updated dates, and createdBy/updatedBy values.
     *
     * @param mixed $db_obj
     * @param ODRUser $user
     * @param string|\DateTime $date
     */
    private function setDates($db_obj, $date = null)
    {
        if ($date == null) {
            $db_obj->setCreated(new \DateTime());
            if (method_exists($db_obj, 'setUpdated'))
                $db_obj->setUpdated(new \DateTime());
        } else {
            $db_obj->setCreated(new \DateTime($date));
            if (method_exists($db_obj, 'setUpdated'))
                $db_obj->setUpdated(new \DateTime($date));
        }
    }

    /**
     * The param dataset is misnamed in this output.  This is really editing a single
     * record.  In the acase of a metadata dataset, there is only one record.  So, this
     * was using the term dataset when record is more appropriate.
     *
     * @param $dataset
     * @param $orig_dataset
     * @param $user
     * @param $top_level
     * @param $changed
     * @return array
     * @throws \Exception
     */
    private function datasetDiff($dataset, $orig_dataset, $user, $top_level, &$changed)
    {
        // Check if radio options are added or updated
        /*
            {
                "name": "geochemistry",
                "template_radio_option_uuid": "0730d71",
                "updated_at": "2018-09-25 16:44:54",
                "id": 58272,
                "selected": "1"
            },
        */

        // Check if fields are added or updated
        $fields_updated = false;
        $radio_option_created = false;
        $tag_created = false;

        try {
            $exception_source = 0xf9e3ed29;

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var UUIDService $uuid_service */
            $uuid_service = $this->container->get('odr.uuid_service');

            // Check Fields
            /** @var DataRecord $data_record */
            $data_record = $em->getRepository('ODRAdminBundle:DataRecord')->findOneBy(
                array(
                    'unique_id' => $dataset['record_uuid']
                )
            );

            // Check if record public date needs updating
            // TODO Check User or LoggedInUser is SuperAdmin
            if ($data_record && (
                    isset($dataset['public_date'])
                    || isset($dataset['created'])
                )
            ) {
                if ($data_record_meta = $data_record->getDataRecordMeta()) {
                    $new_data_record_meta = clone $data_record_meta;
                    if (isset($dataset['public_date']) && $data_record_meta->getPublicDate()->format("Y-m-d H:i:s") !== $dataset['public_date']) {
                        $new_data_record_meta->setPublicDate(new \DateTime($dataset['public_date']));
                        unset($dataset['public_date']);
                    }

                    if (isset($dataset['created'])) {
                        self::setDates($new_data_record_meta, $dataset['created']);
                        self::setDates($data_record, $dataset['created']);
                    } else {
                        self::setDates($new_data_record_meta, null);
                    }

                    if (
                        !$pm_service->isDatatypeAdmin($user, $data_record->getDataType())
                        && !$pm_service->canAddDatarecord($user, $data_record->getDataType())
                    ) {
                        throw new ODRForbiddenException();
                    }

                    // Need to persist and flush
                    $em->remove($data_record_meta);
                    if (isset($dataset['created'])) {
                        unset($dataset['created']);
                        $em->persist($data_record);
                    }
                    $em->persist($new_data_record_meta);
                    $em->flush();
                    $em->refresh($data_record);

                    // Set metadata
                    $fields_updated = true;

                }

            }


            // TODO Need to check if user can edit record
            if (
                !$pm_service->isDatatypeAdmin($user, $data_record->getDataType())
                && !$pm_service->canEditDatarecord($user, $data_record)
            ) {
                throw new ODRForbiddenException($user->getUserString());
            }
            if (isset($dataset['fields'])) {
                for ($i = 0; $i < count($dataset['fields']); $i++) {
                    $field = $dataset['fields'][$i];

//                    if (!isset($field['created']))
//                        $field['created'] = null;

                    // Guaranteed to have either "template_field_uuid" or "field_uuid" at this point...
                    $data_field = null;
                    if ( isset($field['template_field_uuid']) && $field['template_field_uuid'] !== null ) {
                        /** @var DataFields $data_field */
                        $data_field = $em->getRepository('ODRAdminBundle:DataFields')->findOneBy(
                            array(
                                'templateFieldUuid' => $field['template_field_uuid'],
                                'dataType' => $data_record->getDataType()->getId()
                            )
                        );
                    }
                    else {
                        /** @var DataFields $data_field */
                        $data_field = $em->getRepository('ODRAdminBundle:DataFields')->findOneBy(
                            array(
                                'fieldUuid' => $field['field_uuid'],
                                'dataType' => $data_record->getDataType()->getId()
                            )
                        );
                    }

                    // Determine field type
                    $typeclass = $data_field->getFieldType()->getTypeClass();
                    $typename = $data_field->getFieldType()->getTypeName();

                    // Probably going to end up needing this
                    /** @var DataRecordFields $drf */
                    $drf = $em->getRepository('ODRAdminBundle:DataRecordFields')->findOneBy(
                        array(
                            'dataRecord' => $dataset['internal_id'],
                            'dataField' => $data_field->getId()
                        )
                    );
                    // NOTE: $drf might legitimately be null...i.e. a newly created record without
                    //  any data in it


                    // Deal with files and images here
                    if ($typename === 'File' || $typename === 'Image') {
                        if (isset($field['files']) && is_array($field['files']) && count($field['files']) > 0) {
                            for ($j = 0; $j < count($field['files']); $j++) {
                                $file = $field['files'][$j];

                                $new_public_date = null;
                                $new_quality = null;

                                if (isset($file['public_date']))
                                    $new_public_date = new \DateTime($file['public_date']);
                                if (isset($file['quality']))
                                    $new_quality = intval($file['quality']);

                                if (!is_null($new_public_date) || !is_null($new_quality)) {
                                    switch ($typename) {
                                        case 'File':
                                            /** @var File $file_obj */
                                            $file_obj = $em->getRepository('ODRAdminBundle:File')->findOneBy(
                                                array(
                                                    'unique_id' => $file['file_uuid']
                                                )
                                            );
                                            if ($file_obj == null)
                                                throw new ODRNotFoundException('File');

                                            $new_file_meta = clone $file_obj->getFileMeta();
                                            if (!is_null($new_public_date))
                                                $new_file_meta->setPublicDate($new_public_date);
                                            if (!is_null($new_quality))
                                                $new_file_meta->setQuality($new_quality);

                                            $em->remove($file_obj->getFileMeta());
                                            $em->persist($new_file_meta);
                                            $fields_updated = true;

                                            unset($file['public_date']);
                                            unset($file['quality']);

                                            $field['files'][$j] = $file;
                                            $dataset['fields'][$i] = $field;

                                            break;

                                        case 'Image':
                                            /** @var Image $image_obj */
                                            $image_obj = $em->getRepository('ODRAdminBundle:Image')->findOneBy(
                                                array(
                                                    'unique_id' => $file['file_uuid']
                                                )
                                            );
                                            if ($image_obj == null)
                                                throw new ODRNotFoundException('Image');

                                            // Only act on items with image meta
                                            if ($image_meta = $image_obj->getImageMeta()) {
                                                $new_image_meta = clone $image_meta;

                                                if (!is_null($new_public_date))
                                                    $new_image_meta->setPublicDate($new_public_date);
                                                if (!is_null($new_quality))
                                                    $new_image_meta->setQuality($new_quality);

                                                $em->remove($image_meta);
                                                $em->persist($new_image_meta);
                                                $fields_updated = true;
                                            }

                                            // Set this for all?  How do we know if it updated the parent
                                            $file['_file_metadata']['_public_date'] = date_format($new_public_date, "Y-m-d H:i:s");
                                            unset($file['public_date']);
                                            unset($file['quality']);

                                            $field['files'][$j] = $file;
                                            $dataset['fields'][$i] = $field;

                                            break;
                                    }
                                }
                            }
                        }
                    } else if ($typename === 'Boolean') {
                        /** @var DataRecordFields $drf */
                        $drf = $em->getRepository('ODRAdminBundle:DataRecordFields')->findOneBy(
                            array(
                                'dataRecord' => $dataset['internal_id'],
                                'dataField' => $data_field->getId()
                            )
                        );

                        if (!$drf) {
                            // If drf entry doesn't exist, create new
                            $drf = new DataRecordFields();
                            $drf->setCreatedBy($user);
                            self::setDates($drf, $field['created']);
                            $drf->setDataField($data_field);
                            $drf->setDataRecord($data_record);
                            $em->persist($drf);
                        }

                        // Lookup Boolean by DRF & Field ID
                        /** @var Boolean $bool */
                        $bool = $em->getRepository('ODRAdminBundle:Boolean')->findOneBy(
                            array(
                                'dataRecordFields' => $drf->getId()
                            )
                        );

                        if ($bool) {
                            // check if value matches field->selected
                            if ($bool->getValue() !== $field['selected']) {
                                // remove old entity
                                $em->remove($bool);
                                /** @var Boolean $new_field */
                                $new_field = new Boolean();
                                $new_field->setDataField($data_field);
                                $new_field->setDataRecord($data_record);
                                $new_field->setDataRecordFields($drf);
                                $new_field->setFieldType($data_field->getFieldType());
                                $new_field->setCreatedBy($user);
                                $new_field->setUpdatedBy($user);
                                self::setDates($new_field, $field['created']);
                                $new_field->setValue($field['selected']);
                                $em->persist($new_field);
                                $fields_updated = true;
                            }
                        } else {
                            /** @var Boolean $new_field */
                            $new_field = new Boolean();
                            $new_field->setDataField($data_field);
                            $new_field->setDataRecord($data_record);
                            $new_field->setDataRecordFields($drf);
                            $new_field->setFieldType($data_field->getFieldType());
                            $new_field->setCreatedBy($user);
                            $new_field->setUpdatedBy($user);
                            self::setDates($new_field, $field['created']);
                            $new_field->setValue($field['selected']);
                            $em->persist($new_field);
                            $fields_updated = true;
                        }
                        self::fieldMeta($field, $data_field, $new_field);
                        $dataset['fields'][$i] = $field;
                    }
                    else if ( isset($field['tags']) && is_array($field['tags']) ) {

                        switch ($typename) {

                            // Tag field - need to difference hierarchy
                            case 'Tags':
                                // Determine selected tags in original dataset
                                // Determine selected tags in current
                                // print $field['template_field_uuid']."\n";

                                $selected_tags = array();
                                self::selectedTags($field['value'], $selected_tags);

                                $orig_selected_tags = array();
                                $orig_tag_field = array();
                                if ($orig_dataset) {
                                    foreach ($orig_dataset['fields'] as $o_field) {
                                        if (
                                            isset($o_field['value']) &&
                                            isset($field['field_uuid']) &&
                                            $o_field['field_uuid'] == $field['field_uuid']
                                        ) {
                                            $orig_tag_field = $o_field['value'];
                                            self::selectedTags($o_field['value'], $orig_selected_tags);
                                        }
                                    }
                                }

                                $new_tags = array();
                                $deleted_tags = array();

                                // check for new tags
                                foreach ($selected_tags as $tag) {
                                    $found = false;
                                    foreach ($orig_selected_tags as $o_tag) {
                                        if ($tag == $o_tag) {
                                            $found = true;
                                        }
                                    }
                                    if (!$found) {
                                        array_push($new_tags, $tag);
                                    }
                                }

                                // Check for deleted tags
                                foreach ($orig_selected_tags as $o_tag) {
                                    $found = false;
                                    foreach ($selected_tags as $tag) {
                                        if ($tag == $o_tag) {
                                            $found = true;
                                        }
                                    }
                                    if (!$found) {
                                        array_push($deleted_tags, $o_tag);
                                    }
                                }

                                /** @var DataRecordFields $drf */
                                $drf = $em->getRepository('ODRAdminBundle:DataRecordFields')
                                    ->findOneBy(
                                        array(
                                            'dataRecord' => $dataset['internal_id'],
                                            'dataField' => $data_field->getId()
                                        )
                                    );

                                // Delete deleted tags
                                foreach ($deleted_tags as $tag_uuid) {
                                    /** @var Tags $tag */
                                    $tag = $em->getRepository('ODRAdminBundle:Tags')->findOneBy(
                                        array(
                                            'tagUuid' => $tag_uuid,
                                            'dataField' => $data_field->getId()
                                        )
                                    );
                                    /** @var TagSelection $tag_selection */
                                    $tag_selection = $em->getRepository('ODRAdminBundle:TagSelection')
                                        ->findOneBy(
                                            array(
                                                'tag' => $tag->getId(),
                                                'dataRecordFields' => $drf->getId()
                                            )
                                        );

                                    if ($tag_selection) {
                                        $em->remove($tag_selection);
                                        $fields_updated = true;
                                    }
                                }


                                // Check if new tag exists in template
                                // Add to template if not exists
                                foreach ($new_tags as $tag_uuid) {
                                    // Lookup Tag by UUID
                                    /** @var Tags $tag */
                                    $tag = $em->getRepository('ODRAdminBundle:Tags')->findOneBy(
                                        array(
                                            'tagUuid' => $tag_uuid,
                                            'dataField' => $data_field->getId()
                                        )
                                    );

                                    // User Added Options
                                    if (!$tag) {
                                        if (!$tag) {
                                            // We need Datatype Admin Perms
                                            if (
                                                !$pm_service->isDatatypeAdmin($user, $data_record->getDataType())
                                                && !$pm_service->canEditDatatype($user, $data_record->getDataType())
                                            ) {
                                                throw new ODRForbiddenException();
                                            }
                                        }
                                        // Create tag and set as user created
                                        $tag = new Tags();
                                        $tag_created = true;

                                        // Option UUID gets overloaded with the name if a user created tag
                                        $tag->setTagName($tag_uuid);

                                        /** @var UUIDService $uuid_service */
                                        $uuid_service = $this->container->get('odr.uuid_service');
                                        $tag->setTagUuid($uuid_service->generateTagUniqueId());
                                        $tag->setCreatedBy($user);
                                        self::setDates($tag, $field['created']);
                                        $tag->setUserCreated(1);
                                        $tag->setDataField($data_field);
                                        $em->persist($tag);

                                        // Search $field['value'] for tag and find parent
                                        $tag_parent_uuid = null;
                                        foreach ($field['value'] as $field_tag) {
                                            if ($field_tag['template_tag_uuid'] == $tag_uuid) {
                                                // This is our tag
                                                $tag_parent_uuid = $field_tag['tag_parent_uuid'];
                                            }
                                        }

                                        if ($tag_parent_uuid == null)
                                            throw new \Exception('Tag parent UUID is required when adding user-created tags');

                                        // Look up parent tag
                                        /** @var Tags $tag_parent */
                                        $tag_parent = $em->getRepository('ODRAdminBundle:Tags')->findOneBy(
                                            array(
                                                'tagUuid' => $tag_parent_uuid,
                                                'dataField' => $data_field->getId()
                                            )
                                        );

                                        if (!$tag_parent)
                                            throw new \Exception('The parent tag is invalid or not found.');    // TODO - not all tags will have parents...

                                        /** @var TagTree $tag_tree */
                                        $tag_tree = new TagTree();
                                        $tag_tree->setChild($tag);
                                        $tag_tree->setParent($tag_parent);
                                        $tag_tree->setCreatedBy($user);
                                        self::setDates($tag_tree, $field['created']);
                                        $em->persist($tag_tree);

                                        /** @var TagMeta $tag_meta */
                                        $tag_meta = new TagMeta();
                                        $tag_meta->setTag($tag);
                                        $tag_meta->setTagName($tag_uuid);
                                        $tag_meta->setXmlTagName($tag_uuid);
                                        $tag_meta->setDisplayOrder(0);
                                        $tag_meta->setCreatedBy($user);
                                        self::setDates($tag_meta, $field['created']);
                                        $tag_meta->setUpdatedBy($user);
                                        $em->persist($tag_meta);
                                    }

                                    if (!$drf) {
                                        // If drf entry doesn't exist, create new
                                        $drf = new DataRecordFields();
                                        $drf->setCreatedBy($user);
                                        self::setDates($drf, $field['created']);
                                        $drf->setDataField($data_field);
                                        $drf->setDataRecord($data_record);
                                        $em->persist($drf);
                                    }

                                    /** @var TagSelection $new_field */
                                    $new_field = new TagSelection();
                                    $new_field->setTag($tag);
                                    $new_field->setDataRecordFields($drf);
                                    $new_field->setDataRecord($data_record);
                                    $new_field->setCreatedBy($user);
                                    $new_field->setUpdatedBy($user);
                                    self::setDates($new_field, $field['created']);
                                    $new_field->setSelected(1);
                                    $em->persist($new_field);

                                    self::fieldMeta($field, $data_field, $new_field);
                                    $fields_updated = true;


                                    // Trying to do everything realtime - no waiting forever stuff
                                    // Maybe the references will be stored in the variable anyway?
                                    $em->flush();
                                    $em->refresh($new_field);

                                    // Added tags need to replace their value in the array
                                    for ($j = 0; $j < count($field['value']); $j++) {
                                        if (
                                            $field['value'][$j]['template_tag_uuid'] == $tag->getTagUuid()
                                            || (
                                                $tag->getUserCreated()
                                                && $field['value'][$j]['template_tag_uuid'] == $tag_uuid
                                            )
                                        ) {
                                            // replace this block
                                            $field['value'][$j]['test'] = 1;
                                            $field['value'][$j]['template_tag_uuid'] = $tag->getTagUuid();
                                            $field['value'][$j]['id'] = $new_field->getId();
                                            $field['value'][$j]['selected'] = 1;
                                            $field['value'][$j]['updated_at'] = $new_field->getUpdated()->format('Y-m-d H:i:s');
                                            $field['value'][$j]['created_at'] = $new_field->getCreated()->format('Y-m-d H:i:s');

                                            if ($tag_created) {
                                                $field['value'][$j]['name'] = $tag_uuid;
                                                $field['value'][$j]['user_created'] = 1;
                                            }
                                            else {
                                                $field['value'][$j]['name'] = $tag->getTagName();
                                                $field['value'][$j]['user_created'] = $tag->getUserCreated();
                                            }
                                        }
                                    }
                                }

                                // Get full definitions for fields from original dataset
                                for ($j = 0; $j < count($orig_tag_field); $j++) {
                                    for ($k = 0; $k < count($field['value']); $k++) {
                                        if ($field['value'][$k]['template_tag_uuid'] == $orig_tag_field[$j]['template_tag_uuid']) {
                                            $field['value'][$k] = $orig_tag_field[$j];
                                            break;
                                        }
                                    }
                                }
                                // Assign the updated field back to the dataset.
                                $dataset['fields'][$i] = $field;

                                break;

                        }
                    }
                    else if ( isset($field['values']) && is_array($field['values']) ) {

                        switch ( $typename ) {
                            case 'Single Radio':
                            case 'Multiple Radio':
                            case 'Single Select':
                            case 'Multiple Select':
                                $ret = self::updateRadioField($em, $uuid_service, $user, $data_record, $data_field, $orig_dataset, $field);

                                if ( $ret['fields_updated'] )
                                    $fields_updated = true;
                                if ( $ret['radio_option_created'] )
                                    $radio_option_created = true;

                                $dataset['fields'][$i] = $ret['new_json'];
                                break;

                            default:
                                throw new ODRBadRequestException('Structure for Radio fields used on '.$typeclass.' Field '.$data_field->getFieldUuid(), $exception_source);
                        }
                    }
                    else if (isset($field['value'])) {
                        // Field is singular data field
                        $drf = false;
                        $field_changes = true;
                        if ($orig_dataset) {
                            foreach ($orig_dataset['fields'] as $o_field) {
                                // If we find a matching field....
                                if (isset($o_field['value']) && !is_array($o_field['value'])
                                    && (
                                        (
                                            isset($o_field['template_field_uuid'])
                                            && isset($field['template_field_uuid'])
                                            && $o_field['template_field_uuid'] !== null
                                            && $o_field['template_field_uuid'] == $field['template_field_uuid']
                                        )
                                        || (
                                            isset($field['field_uuid']) && $o_field['field_uuid'] == $field['field_uuid']
                                        )
                                    )
                                ) {
                                    if ($o_field['value'] !== $field['value']) {
                                        // print $o_field['template_field_uuid'] . ' ';
                                        // print $o_field['field_uuid'] . ' ';
                                        // print $field['field_uuid'] . ' ';
                                        // print $data_field->getFieldUuid() . ' ';
                                        // print $field['value'] . " ";
                                        // print $data_field->getFieldType()->getId(); exit();
                                        // Update value to new value (delete and enter new data)
                                        /** @var DataRecordFields $drf */
                                        $drf = $em->getRepository('ODRAdminBundle:DataRecordFields')->findOneBy(
                                            array(
                                                'dataRecord' => $dataset['internal_id'],
                                                'dataField' => $data_field->getId()
                                            )
                                        );
                                    } else {
                                        // No changes necessary - field values match
                                        $field_changes = false;
                                    }
                                }
                            }
                        }
                        if ($field_changes) {
                            // Changes are required or a field needs to be added.

                            $existing_field = null;
                            if (!$drf) {
                                // If drf entry doesn't exist, create new
                                $drf = new DataRecordFields();
                                $drf->setCreatedBy($user);
                                self::setDates($drf, $field['created']);
                                $drf->setDataField($data_field);
                                $drf->setDataRecord($data_record);
                                $em->persist($drf);
                            } else {
                                switch ($typename) {
                                    case 'Integer':
                                        $existing_field = $em->getRepository('ODRAdminBundle:IntegerValue')
                                            ->findOneBy(array('dataRecordFields' => $drf->getId()));
                                        break;
                                    case 'Paragraph Text':
                                        $existing_field = $em->getRepository('ODRAdminBundle:LongText')
                                            ->findOneBy(array('dataRecordFields' => $drf->getId()));
                                        break;
                                    case 'Long Text':
                                        $existing_field = $em->getRepository('ODRAdminBundle:LongVarchar')
                                            ->findOneBy(array('dataRecordFields' => $drf->getId()));
                                        break;
                                    case 'Medium Text':
                                        $existing_field = $em->getRepository('ODRAdminBundle:MediumVarchar')
                                            ->findOneBy(array('dataRecordFields' => $drf->getId()));
                                        break;
                                    case 'Short Text':
                                        $existing_field = $em->getRepository('ODRAdminBundle:ShortVarchar')
                                            ->findOneBy(array('dataRecordFields' => $drf->getId()));
                                        break;
                                    case 'Decimal':
                                        $existing_field = $em->getRepository('ODRAdminBundle:DecimalValue')
                                            ->findOneBy(array('dataRecordFields' => $drf->getId()));
                                        break;
                                    case 'DateTime':
                                        $existing_field = $em->getRepository('ODRAdminBundle:DatetimeValue')
                                            ->findOneBy(array('dataRecordFields' => $drf->getId()));
                                        break;
                                }

                            }

                            switch ($typename) {
                                // IntegerValue
                                case 'Integer':
                                    /** @var IntegerValue $new_field */
                                    $new_field = new IntegerValue();
                                    if ($existing_field) {
                                        // clone and update?
                                        $new_field = clone $existing_field;
                                    } else {
                                        $new_field->setDataField($data_field);
                                        $new_field->setDataRecord($data_record);
                                        $new_field->setDataRecordFields($drf);
                                        $new_field->setFieldType($data_field->getFieldType());
                                    }

                                    $new_field->setCreatedBy($user);
                                    $new_field->setUpdatedBy($user);
                                    self::setDates($new_field, $field['created']);
                                    $new_field->setValue($field['value']);

                                    $em->persist($new_field);
                                    if ($existing_field) {
                                        $em->remove($existing_field);
                                    }
                                    $fields_updated = true;

                                    // Trying to do everything realtime - no waiting forever stuff
                                    $em->flush();
                                    $em->refresh($new_field);

                                    // Assign the updated field back to the dataset.
                                    $field['value'] = $new_field->getValue();
                                    $field['id'] = $new_field->getId();
                                    // $field['updated_at'] = $new_field->getUpdated()->format('Y-m-d H:i:s');
                                    self::fieldMeta($field, $data_field, $new_field);
                                    $dataset['fields'][$i] = $field;
                                    break;

                                // Paragraph Text
                                case 'Paragraph Text':
                                    /** @var LongText $new_field */
                                    $new_field = new LongText();
                                    if ($existing_field) {
                                        // clone and update?
                                        $new_field = clone $existing_field;
                                    } else {
                                        $new_field->setDataField($data_field);
                                        $new_field->setDataRecord($data_record);
                                        $new_field->setDataRecordFields($drf);
                                        $new_field->setFieldType($data_field->getFieldType());
                                    }

                                    $new_field->setCreatedBy($user);
                                    $new_field->setUpdatedBy($user);
                                    self::setDates($new_field, $field['created']);
                                    $new_field->setValue($field['value']);

                                    $em->persist($new_field);
                                    if ($existing_field) {
                                        $em->remove($existing_field);
                                    }
                                    $fields_updated = true;

                                    // Trying to do everything realtime - no waiting forever stuff
                                    $em->flush();
                                    $em->refresh($new_field);

                                    // Assign the updated field back to the dataset.
                                    $field['value'] = $new_field->getValue();
                                    $field['id'] = $new_field->getId();
                                    // $field['updated_at'] = $new_field->getUpdated()->format('Y-m-d H:i:s');
                                    self::fieldMeta($field, $data_field, $new_field);
                                    $dataset['fields'][$i] = $field;
                                    break;

                                // LongVarchar
                                case 'Long Text':
                                    /** @var LongVarchar $new_field */
                                    $new_field = new LongVarchar();
                                    if ($existing_field) {
                                        // clone and update?
                                        $new_field = clone $existing_field;
                                    } else {
                                        $new_field->setDataField($data_field);
                                        $new_field->setDataRecord($data_record);
                                        $new_field->setDataRecordFields($drf);
                                        $new_field->setFieldType($data_field->getFieldType());
                                    }

                                    $new_field->setCreatedBy($user);
                                    $new_field->setUpdatedBy($user);
                                    self::setDates($new_field, $field['created']);
                                    $new_field->setValue($field['value']);

                                    $em->persist($new_field);
                                    if ($existing_field) {
                                        $em->remove($existing_field);
                                    }
                                    $fields_updated = true;

                                    // Trying to do everything realtime - no waiting forever stuff
                                    $em->flush();
                                    $em->refresh($new_field);

                                    // Assign the updated field back to the dataset.
                                    $field['value'] = $new_field->getValue();
                                    $field['id'] = $new_field->getId();
                                    // $field['updated_at'] = $new_field->getUpdated()->format('Y-m-d H:i:s');
                                    self::fieldMeta($field, $data_field, $new_field);
                                    $dataset['fields'][$i] = $field;
                                    break;

                                // MediumVarchar
                                case 'Medium Text':
                                    /** @var MediumVarchar $new_field */
                                    $new_field = new MediumVarchar();
                                    if ($existing_field) {
                                        // clone and update?
                                        $new_field = clone $existing_field;
                                    } else {
                                        $new_field->setDataField($data_field);
                                        $new_field->setDataRecord($data_record);
                                        $new_field->setDataRecordFields($drf);
                                        $new_field->setFieldType($data_field->getFieldType());
                                    }

                                    $new_field->setCreatedBy($user);
                                    $new_field->setUpdatedBy($user);
                                    self::setDates($new_field, $field['created']);
                                    $new_field->setValue($field['value']);

                                    $em->persist($new_field);
                                    if ($existing_field) {
                                        $em->remove($existing_field);
                                    }
                                    $fields_updated = true;

                                    // Trying to do everything realtime - no waiting forever stuff
                                    $em->flush();
                                    $em->refresh($new_field);

                                    // Assign the updated field back to the dataset.
                                    $field['value'] = $new_field->getValue();
                                    $field['id'] = $new_field->getId();
                                    // $field['updated_at'] = $new_field->getUpdated()->format('Y-m-d H:i:s');
                                    self::fieldMeta($field, $data_field, $new_field);
                                    $dataset['fields'][$i] = $field;
                                    break;

                                // ShortVarchar
                                case 'Short Text':
                                    /** @var ShortVarchar $new_field */
                                    $new_field = new ShortVarchar();
                                    if ($existing_field) {
                                        // clone and update?
                                        $new_field = clone $existing_field;
                                    } else {
                                        $new_field->setDataField($data_field);
                                        $new_field->setDataRecord($data_record);
                                        $new_field->setDataRecordFields($drf);
                                        $new_field->setFieldType($data_field->getFieldType());
                                    }

                                    $new_field->setCreatedBy($user);
                                    $new_field->setUpdatedBy($user);
                                    self::setDates($new_field, $field['created']);
                                    $new_field->setValue($field['value']);
                                    $new_field->setConvertedValue('');    // TODO - need converted value

                                    $em->persist($new_field);
                                    if ($existing_field) {
                                        $em->remove($existing_field);
                                    }
                                    $fields_updated = true;

                                    // Trying to do everything realtime - no waiting forever stuff
                                    $em->flush();
                                    $em->refresh($new_field);

                                    // Assign the updated field back to the dataset.
                                    $field['value'] = $new_field->getValue();
                                    $field['id'] = $new_field->getId();
                                    // $field['updated_at'] = $new_field->getUpdated()->format('Y-m-d H:i:s');
                                    self::fieldMeta($field, $data_field, $new_field);
                                    $dataset['fields'][$i] = $field;
                                    break;

                                // DecimalValue
                                case 'Decimal':
                                    /** @var DecimalValue $new_field */
                                    $new_field = new DecimalValue();
                                    if ($existing_field) {
                                        // clone and update?
                                        $new_field = clone $existing_field;
                                    } else {
                                        $new_field->setDataField($data_field);
                                        $new_field->setDataRecord($data_record);
                                        $new_field->setDataRecordFields($drf);
                                        $new_field->setFieldType($data_field->getFieldType());
                                    }

                                    $new_field->setCreatedBy($user);
                                    $new_field->setUpdatedBy($user);
                                    self::setDates($new_field, $field['created']);
                                    $new_field->setValue($field['value']);

                                    $em->persist($new_field);
                                    if ($existing_field) {
                                        $em->remove($existing_field);
                                    }
                                    $fields_updated = true;

                                    // Trying to do everything realtime - no waiting forever stuff
                                    $em->flush();
                                    $em->refresh($new_field);

                                    // Assign the updated field back to the dataset.
                                    $field['value'] = $new_field->getValue();
                                    $field['id'] = $new_field->getId();
                                    // $field['updated_at'] = $new_field->getUpdated()->format('Y-m-d H:i:s');
                                    self::fieldMeta($field, $data_field, $new_field);
                                    $dataset['fields'][$i] = $field;
                                    break;

                                // DatetimeValue
                                case 'DateTime':
                                    /** @var DatetimeValue $new_field */
                                    $new_field = new DatetimeValue();
                                    if ($existing_field) {
                                        // clone and update?
                                        $new_field = clone $existing_field;
                                    } else {
                                        $new_field->setDataField($data_field);
                                        $new_field->setDataRecord($data_record);
                                        $new_field->setDataRecordFields($drf);
                                        $new_field->setFieldType($data_field->getFieldType());
                                    }

                                    $new_field->setCreatedBy($user);
                                    $new_field->setUpdatedBy($user);
                                    self::setDates($new_field, $field['created']);

                                    if (is_null($field['value'])
                                        || $field['value'] === '0000-00-00'
                                        || $field['value'] === '0000-00-00 00:00:00'
                                    ) {
                                        $field['value'] = new \DateTime('9999-12-31 00:00:00');    // matches EditController::updateAction()
                                    } else {
                                        $field['value'] = new \DateTime($field['value']);
                                    }

                                    $new_field->setValue($field['value']);

                                    $em->persist($new_field);
                                    if ($existing_field) {
                                        $em->remove($existing_field);
                                    }
                                    $fields_updated = true;

                                    // Trying to do everything realtime - no waiting forever stuff
                                    $em->flush();
                                    $em->refresh($new_field);

                                    // Assign the updated field back to the dataset.
                                    $field['value'] = $new_field->getValue();
                                    $field['id'] = $new_field->getId();
                                    // $field['updated_at'] = $new_field->getUpdated()->format('Y-m-d H:i:s');
                                    self::fieldMeta($field, $data_field, $new_field);
                                    $dataset['fields'][$i] = $field;
                                    break;

                                default:
                                    break;
                            }


                            // Check if field is "name" field for datatype
                            /*
                            if(
                                $data_record->getDataType()->getNameField()->getId() == $data_field->getId()
                                && $data_record->getDataType()->getMetadataFor() !== null
                            ) {
                                // This is the name field so update database name
                                // TODO Update database name

                            }
                            */
                        }
                    }


                    // Fill out any missing values in the submitted data
                    if ( !isset($field['id']) )
                        $dataset['fields'][$i]['id'] = $data_field->getId();
                    if ( !isset($field['field_name']) )
                        $dataset['fields'][$i]['field_name'] = $data_field->getFieldName();
                    if ( !isset($field['field_uuid']) )
                        $dataset['fields'][$i]['field_uuid'] = $data_field->getFieldUuid();
                    if ( !isset($field['template_field_uuid']) )
                        $dataset['fields'][$i]['template_field_uuid'] = $data_field->getTemplateFieldUuid();
                }
            }


            // Remove deleted [related] records
            // TODO Only allow if user can delete related records
            if ($orig_dataset && isset($orig_dataset['records'])) {
                // Check if old record exists and delete if necessary...
                for ($i = 0; $i < count($orig_dataset['records']); $i++) {
                    $o_record = $orig_dataset['records'][$i];

                    $record_found = false;
                    // Check if record_uuid and template_uuid match - if so we're differencing
                    for ($j = 0; $j < count($dataset['records']); $j++) {
                        $record = $dataset['records'][$j];
                        // New records don't have UUIDs and need to be ignored in this check
                        if (
                            isset($record['record_uuid'])
                            && !empty($record['record_uuid'])
//                            && $record['template_uuid'] == $o_record['template_uuid']
                            && $record['database_uuid'] == $o_record['database_uuid']
                            && $record['record_uuid'] == $o_record['record_uuid']
                        ) {
                            $record_found = true;
                        }
                    }

                    // TODO Check here if user has permission to delete
                    if (!$record_found) {
                        // Recursively build list of record ids
                        $records_to_delete = [];
                        // print var_export($o_record);exit();
                        self::getRecordsToDelete($records_to_delete, $o_record);

                        foreach ($records_to_delete as $record_to_delete) {
                            // Use delete record
                            /** @var DataRecord $del_record */
                            $del_record = $em->getRepository('ODRAdminBundle:DataRecord')
                                ->findOneBy(
                                    array(
                                        'unique_id' => $record_to_delete
                                    )
                                );


                            // TODO Need to recursively delete records here....
                            if ($del_record) {
                                if (
                                    !$pm_service->isDatatypeAdmin($user, $del_record->getDataType())
                                    && !$pm_service->canDeleteDatarecord($user, $del_record->getDataType())
                                ) {
                                    throw new ODRForbiddenException();
                                }
                                $em->remove($del_record);
                                $changed = true;
                            }
                        }
                        // Commit Deletions
                        $em->flush();
                    }
                }
            }

            // Need to check for child & linked records
            // Create child if new one added
            // Create link if needed (possibly creating record in link)
            // Search for record to link??
            if (isset($dataset['records'])) {
                for ($i = 0; $i < count($dataset['records']); $i++) {
                    $record = $dataset['records'][$i];

                    $record_found = false;
                    if ($orig_dataset && isset($orig_dataset['records'])) {
                        // Check if record_uuid and template_uuid match - if so we're differencing
                        for ($j = 0; $j < count($orig_dataset['records']); $j++) {
                            $o_record = $orig_dataset['records'][$j];
                            if (
                                isset($record['record_uuid'])
                                && (
//                                    $record['template_uuid'] == $o_record['template_uuid']
                                    $record['database_uuid'] == $o_record['database_uuid']
                                    && $record['record_uuid'] == $o_record['record_uuid']
                                )
                            ) {
                                $record_found = true;
                                // Check for differences
                                // Permissions will be implicit here
                                $dataset['records'][$i] = self::datasetDiff($record, $o_record, $user, false, $changed);
                            }
                        }
                    }
                    if (!$record_found) {

                        /** @var DataType $record_data_type */
                        $record_data_type = $em->getRepository('ODRAdminBundle:DataType')->findOneBy(
                            array(
                                'unique_id' => $record['database_uuid']
                            )
                        );

                        if (
                            !$pm_service->isDatatypeAdmin($user, $record_data_type)
                            && !$pm_service->canAddDatarecord($user, $record_data_type)
                        ) {
                            throw new ODRForbiddenException();
                        }

                        // Determine if datatype is a link
                        $is_link = false;
                        /** @var DataTree $datatree */
                        $datatree = $em->getRepository('ODRAdminBundle:DataTree')->findOneBy(
                            array(
                                'ancestor' => $data_record->getDataType()->getId(),
                                'descendant' => $record_data_type->getId()
                            )
                        );

                        if ($datatree == null)
                            throw new ODRNotFoundException('Datatree');

                        if ($datatree->getIsLink()) {
                            $is_link = true;
                        }

                        // If the record has a record_uuid, we just need to make the link
                        if ($is_link && isset($record['record_uuid']) && strlen($record['record_uuid']) > 0) {
                            print 'A LINK HAS BEEN ADDED';
                            /** @var DataRecord $linked_data_record */
                            $linked_data_record = $em->getRepository('ODRAdminBundle:DataRecord')->findOneBy(
                                array(
                                    'unique_id' => $record['record_uuid']
                                )
                            );

                            if (is_null($linked_data_record))
                                throw new ODRNotFoundException('DataRecord');

                            /** @var EntityCreationService $ec_service */
                            $ec_service = $this->container->get('odr.entity_creation_service');
                            $ec_service->createDatarecordLink($user, $data_record, $linked_data_record);

                            // TODO - Should we allow changes to the record here - not practical I think
                            $dataset['records'][$i] = $record;

                            // TODO - need to clear the correct cache entries

                        } else if (!$is_link && isset($record['record_uuid']) && strlen($record['record_uuid']) > 0) {
                            throw new ODRBadRequestException('New child records (non-linked) can not have pre-existing UUIDs.');
                        } else {
                            /** @var EntityCreationService $ec_service */
                            $ec_service = $this->container->get('odr.entity_creation_service');
                            $new_record = $ec_service->createDatarecord($user, $record_data_type, true, false);
                            $new_record_meta = $new_record->getDataRecordMeta();

                            // Don't want this to remain true
                            $new_record->setProvisioned(false);

                            if (isset($record['created'])) {
                                // The API call specified the record was created at a specific
                                //  time in the past, so ODR needs to store that
                                $new_record->setCreated(new \DateTime($record['created']));
                                $new_record->setUpdated(new \DateTime($record['created']));
                                $new_record_meta->setCreated(new \DateTime($record['created']));
                                $new_record_meta->setUpdated(new \DateTime($record['created']));
                            }

                            if (isset($record['public_date'])) {
                                // The API call specified when the record was changed to public, so
                                //  ODR needs to also store that
                                $new_record_meta->setPublicDate(new \DateTime($record['public_date']));
                            }

                            if (!$is_link) {
                                // This API call could be creating a child record...ensure its
                                //  parents are properly set
                                $new_record->setParent($data_record);
                                $new_record->setGrandparent($data_record->getGrandparent());
                            }


//                            // TODO - this should be using entitycreationservice
//                            /** @var UUIDService $uuid_service */
//                            $uuid_service = $this->container->get('odr.uuid_service');
//
//                            // TODO Check if user can create record in DataType
//                            /** @var DataRecord $new_record */
//                            $new_record = new DataRecord();
//                            $new_record->setDataType($record_data_type);
//                            if (isset($record['created'])) {
//                                $new_record->setCreated(new \DateTime($record['created']));
//                                $new_record->setUpdated(new \DateTime($record['created']));
//                            } else {
//                                $new_record->setCreated(new \DateTime());
//                                $new_record->setUpdated(new \DateTime());
//                            }
//                            $new_record->setCreatedBy($user);
//                            $new_record->setUpdatedBy($user);
//                            $new_record->setUniqueId($uuid_service->generateDatarecordUniqueId());
//                            $new_record->setProvisioned(0);
//
//                            if ($is_link) {
//                                $new_record->setParent($new_record);
//                                $new_record->setGrandparent($new_record);
//                            } else {
//                                $new_record->setParent($data_record);
//                                $new_record->setGrandparent($data_record->getGrandparent());
//                            }
//
//                            /** @var DataRecordMeta $new_record_meta */
//                            $new_record_meta = new DataRecordMeta();
//                            $new_record_meta->setCreatedBy($user);
//                            $new_record_meta->setUpdatedBy($user);
//                            if (isset($record['created'])) {
//                                $new_record_meta->setCreated(new \DateTime($record['created']));
//                                $new_record_meta->setUpdated(new \DateTime($record['created']));
//                            } else {
//                                $new_record_meta->setCreated(new \DateTime());
//                                $new_record_meta->setUpdated(new \DateTime());
//                            }
//                            $new_record_meta->setDataRecord($new_record);
//                            // $new_record_meta->setPublicDate(new \DateTime('2200-01-01T00:00:00.0Z'));
//                            if (isset($record['public_date'])) {
//                                $new_record_meta->setPublicDate(new \DateTime($record['public_date']));
//                            } else {
//                                $new_record_meta->setPublicDate(new \DateTime());
//                            }

                            // Need to persist and flush
                            $em->persist($new_record);
                            $em->persist($new_record_meta);
                            $em->flush();
                            $em->refresh($new_record);

                            if ($is_link) {
                                $ec_service->createDatarecordLink($user, $data_record, $new_record);

                                // TODO - need to clear the correct cache entries
                            }

                            // This is wrapped in a try/catch block because any uncaught exceptions will abort
                            //  creation of the new datarecord...
                            try {
                                // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                                //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                                /** @var EventDispatcherInterface $event_dispatcher */
                                $dispatcher = $this->get('event_dispatcher');
                                $event = new DatarecordCreatedEvent($new_record, $user);
                                $dispatcher->dispatch(DatarecordCreatedEvent::NAME, $event);
                            } catch (\Exception $e) {
                                // ...don't want to rethrow the error since it'll interrupt everything after this
                                //  event.  In this case, a datarecord gets created, but the rest of the values
                                //  aren't saved and the provisioned flag never gets changed to "false"...leaving
                                //  the datarecord in a state that the user can't view/edit
//                                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                                    throw $e;
                            }

                            // Populate the UUID of the newly added record
                            $record['record_uuid'] = $new_record->getUniqueId();
                            $record['internal_id'] = $new_record->getId();
                            $record['updated_at'] = $new_record->getUpdated()->format('Y-m-d H:i:s');
                            $record['created_at'] = $new_record->getCreated()->format('Y-m-d H:i:s');
                            if (!isset($record['database_uuid'])) {
                                $record['database_uuid'] = $record_data_type->getUniqueId();
                            }

                            // Difference with null
                            $null_record = false;
                            $dataset['records'][$i] = self::datasetDiff($record, $null_record, $user, false, $changed);
                        }


                        // Mark Changed
                        $changed = true;


                    }
                }
            }

            if ($fields_updated ||
                ($top_level && $changed)
            ) {
                // Mark this datarecord as updated
                if (isset($record['created'])) {
                    $data_record->setUpdated(new \DateTime($record['created']));
                } else {
                    $data_record->setUpdated(new \DateTime());
                }
                $data_record->setUpdatedBy($user);

                // Need to set changed for higher levels
                $changed = true;

                $em->flush();
                $em->refresh($data_record);

                $dataset['_record_metadata']['_public_date'] = $data_record->getDataRecordMeta()->getPublicDate()->format('Y-m-d H:i:s');
                $dataset['_record_metadata']['_create_auth'] = $data_record->getCreatedBy()->getEmailCanonical();
                $dataset['_record_metadata']['_create_date'] = $data_record->getCreated()->format('Y-m-d H:i:s');
                $dataset['_record_metadata']['_update_date'] = $data_record->getDataRecordMeta()->getUpdated()->format('Y-m-d H:i:s');

                // TODO - does this need to distinguish between a DatarecordPublicStatusChanged event and a DatarecordModified event?
                try {
                    // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                    //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                    /** @var EventDispatcherInterface $event_dispatcher */
                    $dispatcher = $this->get('event_dispatcher');
                    $event = new DatarecordModifiedEvent($data_record, $user);
                    $dispatcher->dispatch(DatarecordModifiedEvent::NAME, $event);
                } catch (\Exception $e) {
                    // ...don't want to rethrow the error since it'll interrupt everything after this
                    //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
                }
            }

            if ($radio_option_created || $tag_created) {
                // Mark the new child datatype's parent as updated
                try {
                    // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                    //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                    /** @var EventDispatcherInterface $event_dispatcher */
                    $dispatcher = $this->get('event_dispatcher');
                    $event = new DatatypeModifiedEvent($data_record->getDataType(), $user);
                    $dispatcher->dispatch(DatatypeModifiedEvent::NAME, $event);
                } catch (\Exception $e) {
                    // ...don't want to rethrow the error since it'll interrupt everything after this
                    //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
                }
            }

            // Check Related datatypes
            return $dataset;
        } catch (\Exception $e) {
            throw $e;
        }
    }


    /**
     * Each of the four radio typeclasses requires the same steps to update
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param PermissionsManagementService $pm_service
     * @param UUIDService $uuid_service
     * @param ODRUser $user
     * @param DataRecord $datarecord The datarecord the user might be modifying
     * @param DataFields $datafield The datafield the user might be modifying
     * @param array $orig_dataset The array version of the current record
     * @param array $field An array of the state the user wants to field to be in after datasetDiff()
     * @throws ODRException
     * @return array
     */
    private function updateRadioField($em, $uuid_service, $user, $datarecord, $datafield, $orig_dataset, $field)
    {
        // Going to need these
        $selected_options = $field['values'];

        $created = null;
        if ( isset($field['created']) )
            $created = $field['created'];

        $repo_dataRecordFields = $em->getRepository('ODRAdminBundle:DataRecordFields');
        $repo_radioOptions = $em->getRepository('ODRAdminBundle:RadioOptions');
        $repo_radioSelections = $em->getRepository('ODRAdminBundle:RadioSelection');

        // This function shouldn't be throwing errors, otherwise the database gets left in some
        //  unknown state...it's up to checkRadioFieldPermissions() to throw any errors
//        $typename = $datafield->getFieldType()->getTypeName();
//        if ( $typename === 'Single Radio' || $typename === 'Single Select' ) {
//            // ...then need to throw an error if the user wants to leave the field in a state where
//            //  it has multiple radio options selected
//            if ( count($selected_options) > 1 )
//                throw new ODRBadRequestException('Field '.$datafield->getFieldUuid().' is not allowed to have multiple options selected', $exception_source);
//        }


        // ----------------------------------------
        // Locate the currently selected options in the dataset
        $orig_selected_options = array();
        if ($orig_dataset) {
            foreach ($orig_dataset['fields'] as $o_field) {
                if (
                    isset($o_field['values']) &&
                    isset($field['field_uuid']) &&
                    $o_field['field_uuid'] == $field['field_uuid']
                ) {
                    $orig_selected_options = $o_field['values'];
                    break;
                }
            }
        }

        // Determine whether the submitted dataset will select/create any options, or unselect an option
        $new_options = array();
        $deleted_options = array();

        // Check for new options
        foreach ($selected_options as $option) {
            $found = false;
            foreach ($orig_selected_options as $o_option) {
                if ($option == $o_option)
                    $found = true;
            }

            if (!$found)
                array_push($new_options, $option['template_radio_option_uuid']);
        }

        // Check for deleted options
        foreach ($orig_selected_options as $o_option) {
            $found = false;
            foreach ($selected_options as $option) {
                if ($option == $o_option)
                    $found = true;
            }

            if (!$found)
                array_push($deleted_options, $o_option['template_radio_option_uuid']);
        }


        // ----------------------------------------
        // Need to perform the changes
        $fields_updated = false;
        $radio_option_created = false;

        /** @var DataRecordFields $drf */
        $drf = $repo_dataRecordFields->findOneBy(
            array(
                'dataRecord' => $datarecord->getId(),
                'dataField' => $datafield->getId()
            )
        );

        // Determine whether an option got deselected
        foreach ($deleted_options as $option_uuid) {
            // In order for the API call to be able to deselect a radio option, the drf entry must
            //  exist beforehand...so there's no need to check whether it does or not

            /** @var RadioOptions $option */
            $option = $repo_radioOptions->findOneBy(
                array(
                    'radioOptionUuid' => $option_uuid,
                    'dataField' => $datafield->getId()
                )
            );
            /** @var RadioSelection $option_selection */
            $option_selection = $repo_radioSelections->findOneBy(
                array(
                    'radioOption' => $option->getId(),
                    'dataRecordFields' => $drf->getId()
                )
            );

            if ($option_selection) {
                $fields_updated = true;
                $em->remove($option_selection);
            }
        }


        // Determine whether an existing option got selected, or a new option needs to get created
        foreach ($new_options as $option_uuid) {
            $fields_updated = true;
            $new_option = false;    // $radio_option_created is the 'global' flag for this field
                                    // $new_option is the 'local' flag for this radio option

            // Lookup Option by UUID
            /** @var RadioOptions $option */
            $option = $repo_radioOptions->findOneBy(
                array(
                    'radioOptionUuid' => $option_uuid,
                    'dataField' => $datafield->getId(),
                )
            );

            if (!$option) {
                // The option doesn't exist, so create it
                $option = new RadioOptions();
                $radio_option_created = true;
                $new_option = true;

                // Since this is a new option, $option_uuid is actually its name
                $option->setOptionName($option_uuid);

                $option->setRadioOptionUuid($uuid_service->generateRadioOptionUniqueId());
                $option->setCreatedBy($user);
                self::setDates($option, $created);
                $option->setUserCreated(1);
                $option->setDataField($datafield);
                $em->persist($option);

                /** @var RadioOptionsMeta $option_meta */
                $option_meta = new RadioOptionsMeta();
                $option_meta->setRadioOption($option);
                $option_meta->setIsDefault(false);
                $option_meta->setCreatedBy($user);
                self::setDates($option_meta, $created);
                $option_meta->setDisplayOrder(0);
                $option_meta->setXmlOptionName('');
                $option_meta->setOptionName($option_uuid);
                $em->persist($option_meta);
            }

            // If drf entry doesn't exist at this point, create it
            if ( is_null($drf) ) {
                $drf = new DataRecordFields();
                $drf->setCreatedBy($user);
                self::setDates($drf, $created);
                $drf->setDataField($datafield);
                $drf->setDataRecord($datarecord);
                $em->persist($drf);
            }

            /** @var RadioSelection $new_selection */
            $new_selection = new RadioSelection();
            $new_selection->setRadioOption($option);
            $new_selection->setDataRecord($datarecord);
            $new_selection->setDataRecordFields($drf);
            $new_selection->setCreatedBy($user);
            $new_selection->setUpdatedBy($user);
            self::setDates($new_selection, $created);
            $new_selection->setSelected(1);
            $em->persist($new_selection);

            // Trying to do everything realtime - no waiting forever stuff
            // Maybe the references will be stored in the variable anyway?
            $em->flush();
            $em->refresh($new_selection);

            self::fieldMeta($field, $datafield, $new_selection);

            // Newly created radio options need to replace their value in the array
            for ($j = 0; $j < count($field['values']); $j++) {
                if (
                    $field['values'][$j]['template_radio_option_uuid'] == $option->getRadioOptionUuid()
                    || (
                        $option->getUserCreated()
                        && $field['values'][$j]['template_radio_option_uuid'] == $option_uuid
                    )
                ) {
                    // replace this block
                    $field['values'][$j]['template_radio_option_uuid'] = $option->getRadioOptionUuid();
                    $field['values'][$j]['id'] = $option->getId();
                    $field['values'][$j]['selected'] = 1;
                    // Using the option's created date is intentional here
                    $field['values'][$j]['updated_at'] = $option->getCreated()->format('Y-m-d H:i:s');

                    if ($new_option) {
                        $field['values'][$j]['name'] = $option_uuid;    // Since this is a new option, $option_uuid is actually its name
                        $field['values'][$j]['user_created'] = 1;
                    }
                    else {
                        $field['values'][$j]['name'] = $option->getOptionName();
                        if ( $option->getUserCreated() > 0 )
                            $field['values'][$j]['user_created'] = $option->getUserCreated();
                    }
                }
            }

            // Do not assign modified field back to dataset here
        }

        // Don't want this in the array still
        if ( isset($field['created']) )
            unset( $field['created'] );

        return array(
            'fields_updated' => $fields_updated,
            'radio_option_created' => $radio_option_created,
            'new_json' => $field,
        );
    }


    private function getRecordsToDelete(&$records_to_delete, $record)
    {
        array_push($records_to_delete, $record['record_uuid']);
        if (isset($record['records']) && count($record['records']) > 0) {
            foreach ($record['records'] as $child_record) {
                self::getRecordsToDelete($records_to_delete, $child_record);
            }
        }
    }

    /**
     *
     * Accepts wrapped JSON with $user_email or $dataset/record directly
     * Updates a dataset record
     *
     * @param $version
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function updatedatasetAction($version, Request $request)
    {

        /*
        $content = $request->getContent();
        if (!empty($content)) {
            $dataset_data = json_decode($content, true); // 2nd param to get as array
            $dataset = $dataset_data['dataset'];
            $logger = $this->get('logger');
            $logger->info('DATA FROM UPDATEDATASET: ' . json_encode($dataset));

            $response = new Response('Updated', 200);
            $response->headers->set('Content-Type', 'application/json');
            $response->setContent(json_encode($dataset));
            return $response;
        }
        // exit();
        */

        /*
        $record_uuid = $dataset['record_uuid'];
        $cache_service = $this->container->get('odr.cache_service');
        $metadata_record = $cache_service
            ->get('json_record_' . $record_uuid);

        $response->headers->set('Content-Type', 'application/json');
        $response->setContent($metadata_record);
        return $response;
        */

        try {
            $content = $request->getContent();
            if (empty($content))
                throw new ODRException('No dataset data to update.');


            // Rebuild data array from JSON
            $dataset_data = json_decode($content, true); // 2nd param to get as array

            // Need to determine if user is acting on their own behalf or
            // on behalf of another user

            /** @var ODRUser $logged_in_user */  // Anon when nobody is logged in.
            $logged_in_user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ($logged_in_user == 'anon.')
                throw new ODRForbiddenException();

            // User to act as during update
            $user = null;
            $dataset = [];
            if (isset($dataset_data['user_email']) && $dataset_data['user_email'] !== null) {
                // Act As user
                $user_email = $dataset_data['user_email'];
                $dataset = $dataset_data['dataset'];
                if (!$logged_in_user->hasRole('ROLE_SUPER_ADMIN'))
                    throw new ODRForbiddenException();

                $user_manager = $this->container->get('fos_user.user_manager');
                /** @var ODRUser $user */
                $user = $user_manager->findUserBy(array('email' => $user_email));
                if (is_null($user))
                    throw new ODRNotFoundException('unrecognized email: "' . $user_email . '"');
            } else {
                $user_email = $logged_in_user->getEmail();
                $user = $logged_in_user;
                $dataset = $dataset_data;
            }

            // Record UUID
            $record_uuid = $dataset['record_uuid'];

            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');
            $record = $cache_service
                ->get('json_record_' . $record_uuid);


            if (!$record) {
                // Need to pull record using getExport...
                $record = self::getRecordData(
                    $version,
                    $record_uuid,
                    $request->getRequestFormat(),
                    $user
                );

                if ($record) {
                    $record = json_decode($record, true);
                }
            } else {
                // Check if dataset has public attribute
                $record = json_decode($record, true);
            }

            // Generate internal ids or database uuids as needed
            $changed = false;

            // datasetDiff Processes the record and adds updates, etc.
            if (self::checkUpdatePermissions($dataset, $record, $user, true, $changed)) {
                $changed = false;
                $dataset = self::datasetDiff($dataset, $record, $user, true, $changed);
            } else {
                // Return invalid permissions
                throw new ODRForbiddenException();
            }

            // Here we need to set the anon record as well...
            // Anon metadata records will always be public...
            // Also need a filter to filter by permissions.  Really easy
            // if the JSON had public/not-public as a field in all datapoints.
            $cache_service->set('json_record_' . $record_uuid, json_encode($dataset));

            // ----------------------------------------
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->findOneBy(
                array('unique_id' => $record_uuid)
            );

            try {
                // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                /** @var EventDispatcherInterface $event_dispatcher */
                $dispatcher = $this->get('event_dispatcher');
                $event = new DatatypeImportedEvent($datarecord->getDataType(), $user);
                $dispatcher->dispatch(DatatypeImportedEvent::NAME, $event);
            } catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }


            // Respond and redirect to record
            $response = new Response('Updated', 200);

            $response->headers->set('Content-Type', 'application/json');
            $response->setContent(json_encode($dataset));
            return $response;
        } catch (\Exception $e) {
            $source = 0x388847de;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

    }

    /**
     * Gets a single record for a metadata dataset or one or more records for a
     * normal dataset.  If record_uuid is present, will return only that record without
     * the count wrapper.
     *
     * @param $version
     * @param $dataset_uuid
     * @param $record_uuid
     * @param Request $request
     * @return Response
     */
    public function getRecordsByDatasetUUIDAction($version, $dataset_uuid, $record_uuid = null, Request $request): Response
    {

        try {

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            // This is the API User - system admin
            /** @var ODRUser $user */  // Anon when nobody is logged in.
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // $user_manager = $this->container->get('fos_user.user_manager');
            // $user = $user_manager->findUserBy(array('email' => ''));
            // We should allow anonymous access to records they can see...
            if (is_null($user))
                throw new ODRNotFoundException('User');

            // Find datatype for Dataset UUID
            /** @var DataType $data_type */
            $data_type = $em->getRepository('ODRAdminBundle:DataType')->findOneBy(
                array(
                    'unique_id' => $dataset_uuid
                )
            );

            if (is_null($data_type))
                throw new ODRNotFoundException('DataType');

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');

            // Determine if we're searching a metadata record or a normal dataset
            // Metadata datasets have one record each
            // Regular datasets have multiple records
            $is_datatype_admin = $pm_service->isDatatypeAdmin($user, $data_type);

            // print $user->getUsername() . ' ' . $is_datatype_admin; exit();


            if ($data_type->getIsMasterType()) {
                // Find datarecord from dataset
                /** @var DataRecord $data_record */
                $data_record = $em->getRepository('ODRAdminBundle:DataRecord')->findOneBy(
                    array(
                        'dataType' => $data_type->getId()
                    )
                );

                if (is_null($data_record))
                    throw new ODRNotFoundException('DataRecord');

                if ($is_datatype_admin || $pm_service->canViewDatarecord($user, $data_record)) {
                    return $this->getDatarecordExportAction(
                        $version,
                        $data_record->getUniqueId(),
                        $request,
                        $user
                    );
                } else {
                    throw new ODRNotFoundException('DataRecord');
                }

            } else {
                // Find datarecord from dataset
                /** @var DataRecord[] $data_records */
                $data_records = [];
                if ($record_uuid == null) {
                    $data_records = $em->getRepository('ODRAdminBundle:DataRecord')->findBy(
                        array(
                            'dataType' => $data_type->getId()
                        )
                    );
                } else {
                    $data_records = $em->getRepository('ODRAdminBundle:DataRecord')->findBy(
                        array(
                            'dataType' => $data_type->getId(),
                            'unique_id' => $record_uuid
                        )
                    );
                }

                if (count($data_records) < 1)
                    throw new ODRNotFoundException('DataRecord');

                $output_records = [];
                if ($is_datatype_admin) {
                    $output_records = $data_records;
                } else {
                    foreach ($data_records as $data_record) {
                        if ($pm_service->canViewDatarecord($user, $data_record)) {
                            array_push($output_records, $data_record);
                        }
                    }
                    if (count($output_records) < 1)
                        throw new ODRNotFoundException('DataRecord');
                }

                $display_metadata = true;
                if ($request->query->has('metadata') && $request->query->get('metadata') == 'false')
                    // ...but restrict to only the most useful info upon request
                    $display_metadata = false;

                $output = '';
                if ($data_type->getMetadataFor() == null && $record_uuid == null) {
                    $output .= '{';
                    $output .= '"count": ' . count($output_records) . ',';
                    $output .= '"records": [';
                }
                for ($i = 0; $i < count($output_records); $i++) {
                    $data_record = $output_records[$i];

                    $output .= self::getRecordData(
                        $version,
                        $data_record->getUniqueId(),
                        $request->getrequestformat(),
                        $display_metadata,
                        $user
                    );

                    if ($i < (count($output_records) - 1)) {
                        $output .= ',';
                    }

                }
                if ($data_type->getMetadataFor() == null && $record_uuid == null) {
                    $output .= ']}';
                }

                // set up a response to send the datatype back
                $response = new response();

                $response->setcontent($output);
                return $response;

            }
        } catch (\Exception $e) {
            $source = 0x722347a6;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

    }


    public function datasetQuotaByUUIDAction($version, $dataset_uuid, Request $request)
    {
        // get user from post body
        try {
            /** @var EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            $content = $request->getContent();
            if (!empty($content)) {
                $data = json_decode($content, true); // 2nd param to get as array
                // user
                /** @var UserManager $user_manager */
                $user_manager = $this->container->get('fos_user.user_manager');

                /** @var ODRUser $user */
                $user = $user_manager->findUserBy(array('email' => $data['user_email']));
                if (is_null($user))
                    throw new ODRNotFoundException('unrecognized email: "' . $data['user_email'] . '"');

                /** @var DataType $datatype */
                $datatype = $em->getRepository('ODRAdminBundle:DataType')->findOneBy(
                    array(
                        'unique_id' => $dataset_uuid
                    )
                );

                // When calling with a metadata datatype, automatically delete the
                // actual dataset data and the related metadata
                if ($data_datatype = $datatype->getMetadataFor()) {
                    $datatype = $data_datatype;
                }

                /** @var PermissionsManagementService $pm_service */
                $pm_service = $this->container->get('odr.permissions_management_service');
                // Ensure user has permissions to be doing this
                if (!$pm_service->isDatatypeAdmin($user, $datatype))
                    throw new ODRForbiddenException();

                // http://office_dev/app_dev.php/v3/dataset/quota/520cd6a
                // Only check the datatype files
                $query = $em->createQuery("
                    SELECT SUM(odrf.filesize) FROM ODRAdminBundle:File AS odrf
                    join odrf.dataRecord as dr
                    join dr.dataType as dt
                    where dt.id = :datatype_id ")
                    ->setParameter("datatype_id", $datatype->getId()
                    );

                $total = $query->getScalarResult();

                if ($total[0][1] === null) {
                    $total[0][1] = 0;
                }

                $result = array('total_bytes' => $total[0][1]);

                $response = new JsonResponse($result);
                return $response;
            } else {
                throw new ODRBadRequestException('User must be identified for permissions check.');
            }
        } catch (\Exception $e) {
            $source = 0x19238491;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * This works with the "data" dataset by default and automatically deletes the related
     * metadata.
     *
     * @param $version
     * @param $dataset_uuid
     * @param Request $request
     * @return Response
     */
    public function deleteDatasetByUUIDAction($version, $dataset_uuid, Request $request)
    {
        // get user from post body
        try {
            /** @var EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            $content = $request->getContent();
            if (!empty($content)) {
                $data = json_decode($content, true); // 2nd param to get as array
                // user
                /** @var UserManager $user_manager */
                $user_manager = $this->container->get('fos_user.user_manager');

                /** @var ODRUser $user */
                $user = $user_manager->findUserBy(array('email' => $data['user_email']));
                if (is_null($user))
                    throw new ODRNotFoundException('unrecognized email: "' . $data['user_email'] . '"');

                /** @var DataType $datatype */
                $datatype = $em->getRepository('ODRAdminBundle:DataType')->findOneBy(
                    array(
                        'unique_id' => $dataset_uuid
                    )
                );

                // When calling with a metadata datatype, automatically delete the
                // actual dataset data and the related metadata
                if ($data_datatype = $datatype->getMetadataFor()) {
                    $datatype = $data_datatype;
                }

                /** @var PermissionsManagementService $pm_service */
                $pm_service = $this->container->get('odr.permissions_management_service');
                // Ensure user has permissions to be doing this
                if (!$pm_service->isDatatypeAdmin($user, $datatype))
                    throw new ODRForbiddenException();
                // --------------------

                /** @var EntityDeletionService $ed_service */
                $ed_service = $this->container->get('odr.entity_deletion_service');
                $ed_service->deleteDatatype($datatype, $user);

                // Delete datatype
                $response = new Response('Deleted', 200);
                return $response;
            } else {
                throw new ODRBadRequestException('User must be identified for permissions check.');
            }
        } catch (\Exception $e) {
            $source = 0x1923491;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }

    /**
     * Parse raw HTTP request data
     *
     * Pass in $a_data as an array. This is done by reference to avoid copying
     * the data around too much.
     *
     * Any files found in the request will be added by their field name to the
     * $data['files'] array.
     *
     * @param array  Empty array to fill with data
     * @return  array  Associative array of request data
     */
    private function parse_raw_http_request($a_data = [])
    {
        // read incoming data
        $input = file_get_contents('php://input');

        if (strlen($input) < 1) {
            return [];
        }

        // grab multipart boundary from content type header
        preg_match('/boundary=(.*)$/', $_SERVER['CONTENT_TYPE'], $matches);

        // content type is probably regular form-encoded
        if (!count($matches)) {
            // we expect regular puts to containt a query string containing data
            parse_str(urldecode($input), $a_data);
            return $a_data;
        }

        $boundary = $matches[1];

        // split content by boundary and get rid of last -- element
        $a_blocks = preg_split("/-+$boundary/", $input);
        array_pop($a_blocks);

        $keyValueStr = '';
        // loop data blocks
        foreach ($a_blocks as $id => $block) {
            if (empty($block))
                continue;

            // you'll have to var_dump $block to understand this and maybe replace \n or \r with a visibile char

            // parse uploaded files
            if (strpos($block, 'application/octet-stream') !== FALSE) {
                // match "name", then everything after "stream" (optional) except for prepending newlines
                preg_match("/name=\"([^\"]*)\".*stream[\n|\r]+([^\n\r].*)?$/s", $block, $matches);
                $a_data['files'][$matches[1]] = $matches[2];
            } // parse all other fields
            else {
                // match "name" and optional value in between newline sequences
                preg_match('/name=\"([^\"]*)\"[\n|\r]+([^\n\r].*)?\r$/s', $block, $matches);
                $keyValueStr .= $matches[1] . "=" . $matches[2] . "&";
            }
        }
        $keyValueArr = [];
        parse_str($keyValueStr, $keyValueArr);
        return array_merge($a_data, $keyValueArr);
    }


    /**
     * Deletes a File via the API.
     *
     * @param string $version
     * @param string $file_uuid
     * @param Request $request
     * @return RedirectResponse
     */
    public function fileDeleteByUUIDAction($version, $file_uuid, Request $request)
    {
        try {

            $user_email = '';
            $_POST = self::parse_raw_http_request();
            if (isset($post['user_email']))
                $user_email = $_POST['user_email'];

            // We must check if the logged in user is acting as a user
            // When acting as a user, the logged in user must be a SuperAdmin
            /** @var ODRUser $logged_in_user */
            $logged_in_user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            if ($user_email === '') {
                $user_email = $logged_in_user->getEmail();
            } else if (!$logged_in_user->hasRole('ROLE_SUPER_ADMIN')) {
                // We are acting as a user and do not have Super Permissions - Forbidden
                throw new ODRForbiddenException();
            }

            // Check if user exists & throw user not found error
            // Save which user started this creation process
            // Any user can create a dataset as long as they exist
            // No need to do further permissions checks.
            $user_manager = $this->container->get('fos_user.user_manager');
            /** @var ODRUser $user */
            $user = $user_manager->findUserBy(array('email' => $user_email));
            if (is_null($user))
                throw new ODRNotFoundException('unrecognized email: "' . $user_email . '"');


            // ----------------------------------------
            // Load required objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
            //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
            /** @var EventDispatcherInterface $event_dispatcher */
            $dispatcher = $this->get('event_dispatcher');


            /** @var File $file */
            $file = $em->getRepository('ODRAdminBundle:File')->findOneBy(
                array(
                    'unique_id' => $file_uuid
                )
            );
            if ($file == null) {
                // try image
                $file = $em->getRepository('ODRAdminBundle:Image')->findOneBy(
                    array(
                        'unique_id' => $file_uuid
                    )
                );
            }

            if ($file == null)
                throw new ODRNotFoundException('File');

            $datafield = $file->getDataField();
            if ($datafield->getDeletedAt() != null)
                throw new ODRNotFoundException('Datafield');

            $data_record = $file->getDataRecord();
            if ($data_record->getDeletedAt() != null)
                throw new ODRNotFoundException('Datarecord');

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            // Ensure user has permissions to be doing this
            // TODO - ROLE SUPER ADMIN??
            if (!$pm_service->canEditDatarecord($user, $data_record))
                throw new ODRForbiddenException();

            // Files that aren't done encrypting shouldn't be deleted
            // if ($file->getProvisioned() == true)
            // throw new ODRNotFoundException('File');

            // Determine if file or image
            $deleting_file = false;
            switch ($file->getFieldType()->getTypeName()) {
                // File
                case 'File':
                    $deleting_file = true;
                    $em->remove($file);
                    break;

                // Image
                case 'Image':
                    /** @var Image $image */
                    $image = $file;
                    if (method_exists($image, 'getParent')) {
                        $parent = $image->getParent();
                        if ($parent == null) {
                            $parent = $image;
                        }

                        /** @var Image[] $images */
                        $images = $em->getRepository('ODRAdminBundle:Image')->findBy(
                            array(
                                'parent' => $parent->getId()
                            )
                        );

                        foreach ($images as $del_image) {
                            $em->remove($del_image);
                        }

                    }
                    break;
            }

            $em->flush();


            // ----------------------------------------
            // Need to fire off a DatarecordModified event because a file got deleted
            try {
                $event = new DatarecordModifiedEvent($data_record, $user);
                $dispatcher->dispatch(DatarecordModifiedEvent::NAME, $event);
            } catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

            // Only need to fire off a FileDeleted event if a file got deleted...images don't matter
            if ($deleting_file) {
                try {
                    $event = new FileDeletedEvent($file->getId(), $datafield, $data_record, $user);
                    $dispatcher->dispatch(FileDeletedEvent::NAME, $event);
                } catch (\Exception $e) {
                    // ...don't particularly want to rethrow the error since it'll interrupt
                    //  everything downstream of the event (such as file encryption...), but
                    //  having the error disappear is less ideal on the dev environment...
//                    if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                        throw $e;
                }
            }

            $response = new Response('Created', 201);
            $url = $this->generateUrl('odr_api_get_dataset_record', array(
                'version' => $version,
                'record_uuid' => $data_record->getUniqueId()
            ), false);
            $response->headers->set('Location', $url);

            return $this->redirect($url);
        } catch (\Exception $e) {
            $source = 0x8a83ef89;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Changes the public date of a record
     *
     * @param string $version
     * @param Request $request
     * @return RedirectResponse
     */
    public function publishRecordAction($version, Request $request): RedirectResponse
    {
        /*
                        $response = new Response('Updated', 200);
                        $response->headers->set('Content-Type', 'application/json');
                        $response->setContent(json_encode(array('true' => 'yes')));
                        return $response;
        */
        $content = $request->request->all();
        if (!empty($content)) {
            $logger = $this->get('logger');
            $logger->info('DATA FROM PUBLISH: ' . var_export($content, true));
        }

        try {
            // Get data from POST/Request
            $data = $request->request->all();

            /** @var DatarecordInfoService $dri_service */
            $dri_service = $this->container->get('odr.datarecord_info_service');

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ODRUser $logged_in_user */  // Anon when nobody is logged in.
            $logged_in_user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ($logged_in_user == 'anon.')
                throw new ODRForbiddenException();

            // User to act as during update
            $user = null;
            if (isset($data['user_email']) && $data['user_email'] !== null) {
                // Act As user
                $user_email = $data['user_email'];
                if (!$logged_in_user->hasRole('ROLE_SUPER_ADMIN'))
                    throw new ODRForbiddenException();

                $user_manager = $this->container->get('fos_user.user_manager');
                /** @var ODRUser $user */
                $user = $user_manager->findUserBy(array('email' => $user_email));
                if (is_null($user))
                    throw new ODRNotFoundException('unrecognized email: "' . $user_email . '"');
            } else {
                $user_email = $logged_in_user->getEmail();
                $user = $logged_in_user;
            }

            // Find datatype for Dataset UUID
            /** @var DataType $data_type */
            $data_type = $em->getRepository('ODRAdminBundle:DataType')->findOneBy(
                array(
                    'unique_id' => $data['dataset_uuid']
                )
            );

            if (is_null($data_type))
                throw new ODRNotFoundException('DataType');

            // Calculate the Public Date
            if (isset($data['public_date'])) {
                $public_date = new \DateTime($data['public_date']);
            } else {
                $public_date = new \DateTime();
            }

            /** @var DataRecord $data_record */
            $data_record = null;
            if (isset($data['record_uuid'])) {
                /** @var DataRecord $data_record */
                $data_record = $em->getRepository('ODRAdminBundle:DataRecord')->findOneBy(
                    array(
                        'unique_id' => $data['record_uuid']
                    )
                );
            }
            if ($data_record === null)
                throw new ODRNotFoundException('Record');

            // TODO Convert this to add new meta record and delete old
            // Ensure record is public
            /** @var DataRecordMeta $data_record_meta */
            $data_record_meta = clone $data_record->getDataRecordMeta();
            $data_record_meta->setPublicDate($public_date);
            $data_record_meta->setUpdatedBy($user);

            $em->remove($data_record->getDataRecordMeta());
            $em->persist($data_record_meta);
            $em->flush();


            // ----------------------------------------
            // Fire off a DatarecordPublicStatusChanged event...this will also end up triggering
            //  the database changes and cache clearing that a DatarecordModified event would cause

            // NOTE: do NOT want to also fire off a DatarecordModified event...this would effectively
            //  double the work any event subscribers (such as RSS) would have to do
            try {
                // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                /** @var EventDispatcherInterface $event_dispatcher */
                $dispatcher = $this->get('event_dispatcher');
                $event = new DatarecordPublicStatusChangedEvent($data_record, $user);
                $dispatcher->dispatch(DatarecordPublicStatusChangedEvent::NAME, $event);
            } catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

            $response = new Response('Created', 201);

            // Switching to get datarecord which uses user's permissions to build array
            // This is required because the user can turn databases non-public.
            $url = $this->generateUrl('odr_api_get_dataset_record', array(
                'version' => $version,
                'record_uuid' => $data_record->getUniqueId()
            ), false);

            $response->headers->set('Location', $url);

            return $this->redirect($url);
        } catch (\Exception $e) {
            $source = 0x82831003;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Changes the public date of a datatype.
     *
     * @param string $version
     * @param Request $request
     * @return RedirectResponse
     */
    public function publishAction($version, Request $request)
    {
        /*
                        $response = new Response('Updated', 200);
                        $response->headers->set('Content-Type', 'application/json');
                        $response->setContent(json_encode(array('true' => 'yes')));
                        return $response;
        */
        $content = $request->request->all();
        if (!empty($content)) {
            $logger = $this->get('logger');
            $logger->info('DATA FROM PUBLISH: ' . var_export($content, true));
        }

        try {
            // Get data from POST/Request
            $data = $request->request->all();

            if (!isset($data['dataset_uuid']))
                throw new ODRBadRequestException();


            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');

            /** @var DatarecordInfoService $dri_service */
            $dri_service = $this->container->get('odr.datarecord_info_service');

            /** @var DatabaseInfoService $dbi_service */
            $dbi_service = $this->container->get('odr.database_info_service');

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ODRUser $logged_in_user */  // Anon when nobody is logged in.
            $logged_in_user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ($logged_in_user == 'anon.')
                throw new ODRForbiddenException();

            // User to act as during update
            $user = null;
            if (isset($data['user_email']) && $data['user_email'] !== null) {
                // Act As user
                $user_email = $data['user_email'];
                if (!$logged_in_user->hasRole('ROLE_SUPER_ADMIN'))
                    throw new ODRForbiddenException();

                $user_manager = $this->container->get('fos_user.user_manager');
                /** @var ODRUser $user */
                $user = $user_manager->findUserBy(array('email' => $user_email));
                if (is_null($user))
                    throw new ODRNotFoundException('unrecognized email: "' . $user_email . '"');
            } else {
                $user_email = $logged_in_user->getEmail();
                $user = $logged_in_user;
            }

            // Find datatype for Dataset UUID
            /** @var DataType $data_type */
            $data_type = $em->getRepository('ODRAdminBundle:DataType')->findOneBy(
                array(
                    'unique_id' => $data['dataset_uuid']
                )
            );

            if (is_null($data_type))
                throw new ODRNotFoundException('DataType');

            // Calculate the Public Date
            if (isset($data['public_date'])) {
                $public_date = new \DateTime($data['public_date']);
            } else {
                $public_date = new \DateTime();
            }

            /** @var DataRecord $data_record */
            $data_record = null;
            if (isset($data['record_uuid'])) {
                /** @var DataRecord $data_record */
                $data_record = $em->getRepository('ODRAdminBundle:DataRecord')->findOneBy(
                    array(
                        'unique_id' => $data['record_uuid']
                    )
                );
            } // Only works for Metadat Records
            else if (isset($data['dataset_uuid'])) {
                /** @var DataRecord $data_record */
                $data_record = $em->getRepository('ODRAdminBundle:DataRecord')->findOneBy(
                    array(
                        'dataType' => $data_type->getId()
                    )
                );
            }

            if ($data_record == null)
                throw new ODRNotFoundException('Datarecord');


            // Ensure Datatype is public
            /** @var DataTypeMeta $data_type_meta */
            $data_type_meta = $data_type->getDataTypeMeta();
            $data_type_meta->setPublicDate($public_date);
            $data_type_meta->setUpdatedBy($user);
            $em->persist($data_type_meta);

            // Ensure record is public
            /** @var DataRecordMeta $data_record_meta */
            $data_record_meta = $data_record->getDataRecordMeta();
            $data_record_meta->setPublicDate($public_date);
            $data_record_meta->setUpdatedBy($user);

            // Change permissions for all related datarecords
            $json_data = self::getRecordData(
                $version,
                $data_record->getUniqueId(),
                'json',
                1,
                $user
            );

            $json_record_data = json_decode($json_data, true);
            foreach ($json_record_data['records'] as $json_record) {
                // Make record public
                // Check for children
                self::makeDatarecordPublic($json_record, $public_date, $user);
            }

            $em->persist($data_record_meta);


            $actual_data_record = "";
            $actual_data_type = $data_type->getMetadataFor();
            if ($actual_data_type) {
                /** @var DataTypeMeta $data_type_meta */
                $actual_data_type_meta = $actual_data_type->getDataTypeMeta();
                $actual_data_type_meta->setPublicDate($public_date);

                $actual_data_type_meta->setUpdatedBy($user);
                $em->persist($actual_data_type_meta);

                /** @var DataRecord $actual_data_record */
                $actual_data_record = $em->getRepository('ODRAdminBundle:DataRecord')->findOneBy(
                    array(
                        'dataType' => $actual_data_type->getId()
                    )
                );

                // Ensure record is public
                /** @var DataRecordMeta $data_record_meta */
                $actual_data_record_meta = $actual_data_record->getDataRecordMeta();
                $actual_data_record_meta->setPublicDate($public_date);
                $actual_data_record_meta->setUpdatedBy($user);

                // Change permissions for all related datarecords
                $json_data = self::getRecordData(
                    $version,
                    $actual_data_record->getUniqueId(),
                    'json',
                    1,
                    $user
                );

                $json_record_data = json_decode($json_data, true);
                foreach ($json_record_data['records'] as $json_record) {
                    // Make record public
                    // Check for children
                    self::makeDatarecordPublic($json_record, $public_date, $user);
                }

                $em->persist($actual_data_record_meta);
            }

            $em->flush();


            // ----------------------------------------
            // Fire off a DatarecordPublicStatusChanged event...this will also end up triggering
            //  the database changes and cache clearing that a DatarecordModified event would cause

            // NOTE: do NOT want to also fire off a DatarecordModified event...this would effectively
            //  double the work any event subscribers (such as RSS) would have to do
            try {
                // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                /** @var EventDispatcherInterface $event_dispatcher */
                $dispatcher = $this->get('event_dispatcher');
                $event = new DatarecordPublicStatusChangedEvent($data_record, $user);
                $dispatcher->dispatch(DatarecordPublicStatusChangedEvent::NAME, $event);
            } catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

            // Also need to fire off a DatatypePublicStatusChangedEvent event...
            try {
                // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                /** @var EventDispatcherInterface $event_dispatcher */
                $dispatcher = $this->get('event_dispatcher');
                $event = new DatatypePublicStatusChangedEvent($data_type, $user);
                $dispatcher->dispatch(DatatypePublicStatusChangedEvent::NAME, $event);
            } catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }


            if ($actual_data_record != "") {
                try {
                    // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                    //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                    /** @var EventDispatcherInterface $event_dispatcher */
                    $dispatcher = $this->get('event_dispatcher');
                    $event = new DatarecordPublicStatusChangedEvent($actual_data_record, $user);
                    $dispatcher->dispatch(DatarecordPublicStatusChangedEvent::NAME, $event);
                } catch (\Exception $e) {
                    // ...don't want to rethrow the error since it'll interrupt everything after this
                    //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
                }

                try {
                    // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                    //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                    /** @var EventDispatcherInterface $event_dispatcher */
                    $dispatcher = $this->get('event_dispatcher');
                    $event = new DatatypePublicStatusChangedEvent($actual_data_type, $user);
                    $dispatcher->dispatch(DatatypePublicStatusChangedEvent::NAME, $event);
                } catch (\Exception $e) {
                    // ...don't want to rethrow the error since it'll interrupt everything after this
                    //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
                }
            }

            $response = new Response('Created', 201);

            // Switching to get datarecord which uses user's permissions to build array
            // This is required because the user can turn databases non-public.
            $url = $this->generateUrl('odr_api_get_dataset_single_no_format', array(
                'version' => $version,
                'dataset_uuid' => $data_record->getDataType()->getUniqueId()
            ), false);

            $response->headers->set('Location', $url);

            return $this->redirect($url);

        } catch (\Exception $e) {
            $source = 0x75e74bd7;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }

    /**
     * @param array $record_data
     * @param \DateTime $public_date
     * @param ODRUser $user
     */
    private function makeDatarecordPublic($record_data, $public_date, $user)
    {
        /** @var EntityManager $em */
        $em = $this->getDoctrine()->getManager();

        // Probably should check if user owns record here?

        /** @var DataRecord $data_record */
        $data_record = $em->getRepository('ODRAdminBundle:DataRecord')->findOneBy(
            array(
                'unique_id' => $record_data['record_uuid']
            )
        );

        if ($data_record) {
            // Set public using utility
            $data_record->setPublicDate($user, $public_date, $em);
        }

        if (isset($record_data['records'])) {
            foreach ($record_data['records'] as $record) {
                self::makeDatarecordPublic($record, $public_date, $user);
            }
        }

        // All flushes done at end

    }


    /**
     * Uploads a File or Image.
     *
     * @param string $version
     * @param Request $request
     *
     * @return Response
     */
    public function addfileAction($version, Request $request)
    {
        try {
            // Get data from POST/Request
            $data = $request->request->all();

            // dataset_uuid is not optional
            if (!isset($data['dataset_uuid']) && $data['dataset_uuid'] !== '')
                throw new ODRBadRequestException();
            $dataset_uuid = $data['dataset_uuid'];

            // user_email is technically optional...if it isn't provided, then the logged-in user
            //  is used
            $user_email = null;
            if (isset($data['user_email']) && $data['user_email'] !== '')
                $user_email = $data['user_email'];

            // record_uuid is also technically optional...if not provided, then it'll default to
            //  selecting the metadata/example record (assuming the provided datatype is a metadata
            //  or a template datatype...)
            $record_uuid = null;
            if (isset($data['record_uuid']) && $data['record_uuid'] !== '')
                $record_uuid = $data['record_uuid'];

            // fields can be specified by either field_uuid or template_field_uuid
            $field_uuid = null;
            if (isset($data['field_uuid']) && $data['field_uuid'] !== '')
                $field_uuid = $data['field_uuid'];
            $template_field_uuid = null;
            if (isset($data['template_field_uuid']) && $data['template_field_uuid'] !== '')
                $template_field_uuid = $data['template_field_uuid'];

            // created/public dates are optional
            $created = null;
            if (isset($data['created']) && $data['created'] !== '')
                $created = new \DateTime($data['created']);
            $public_date = null;
            if (isset($data['public_date']) && $data['public_date'] !== '')
                $public_date = new \DateTime($data['public_date']);

            // display order for new images is also optional
            $display_order = null;
            if (isset($data['display_order']) && is_numeric($data['display_order']))
                $display_order = intval($data['display_order']);

            // quality is optional
            $quality = null;
            if (isset($data['quality']) && is_numeric($data['quality']))
                $quality = intval($data['quality']);


            /** @var EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var EntityCreationService $ec_service */
            $ec_service = $this->container->get('odr.entity_creation_service');
            /** @var EntityDeletionService $ed_service */
            $ed_service = $this->container->get('odr.entity_deletion_service');
            /** @var ODRUploadService $odr_upload_service */
            $odr_upload_service = $this->container->get('odr.upload_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var Logger $logger */
            $logger = $this->container->get('logger');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->findOneBy(
                array('unique_id' => $dataset_uuid)
            );
            if ($datatype == null)
                throw new ODRNotFoundException('DataType');

            /** @var DataRecord $datarecord */
            $datarecord = null;
            if (!is_null($record_uuid)) {
                $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->findOneBy(
                    array('unique_id' => $record_uuid)
                );
            } else if ($datatype->getIsMasterType() || !is_null($datatype->getMetadataFor())) {
                // The alternate datarecord load is only allowed when it's a master template or
                //  a metadata datatype...those are only supposed to have a single record
                $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->findOneBy(
                    array('dataType' => $datatype->getId())
                );
            }
            if ($datarecord == null)
                throw new ODRNotFoundException('DataRecord');
            if ($datarecord->getDataType()->getId() !== $datatype->getId())
                throw new ODRBadRequestException();

            /** @var DataFields $datafield */
            $datafield = null;
            if (!is_null($template_field_uuid)) {
                $datafield = $em->getRepository('ODRAdminBundle:DataFields')->findOneBy(
                    array(
                        'templateFieldUuid' => $template_field_uuid,
                        'dataType' => $datatype->getId()
                    )
                );
            } else if (!is_null($field_uuid)) {
                $datafield = $em->getRepository('ODRAdminBundle:DataFields')->findOneBy(
                    array(
                        'fieldUuid' => $field_uuid,
                        'dataType' => $datatype->getId()
                    )
                );
            }
            if ($datafield == null)
                throw new ODRNotFoundException('DataField');
            if ($datafield->getDataType()->getId() !== $datatype->getId())
                throw new ODRBadRequestException();

            // Only allow on file/image fields
            $typeclass = $datafield->getFieldType()->getTypeClass();
            if ($typeclass !== 'File' && $typeclass !== 'Image')
                throw new ODRBadRequestException();


            // ----------------------------------------
            // Determine user privileges
            /** @var ODRUser $logged_in_user */
            $logged_in_user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            /** @var ODRUser $user */
            $user = null;

            if (is_null($user_email)) {
                // If a user email wasn't provided, then use the admin user for this action
                $user = $logged_in_user;
            } else if (!is_null($user_email) && $logged_in_user->hasRole('ROLE_SUPER_ADMIN')) {
                // If a user email was provided, and the user calling this action is a super-admin,
                //  then attempt to locate the user for the given email
                /** @var UserManager $user_manager */
                $user_manager = $this->container->get('fos_user.user_manager');
                $user = $user_manager->findUserBy(array('email' => $user_email));
                if ($user == null)
                    throw new ODRNotFoundException('unrecognized email: "' . $user_email . '"');
            }

            if ($user == null)
                throw new ODRNotFoundException('User');

            // Ensure this user can modify this datafield
            if (!$pm_service->canEditDatafield($user, $datafield, $datarecord))
                throw new ODRForbiddenException();
            // ----------------------------------------


            // ----------------------------------------
            // Quota Check
            // Only check the files that have been uploaded to this datatype
            // TODO - should it include images too?
            // TODO - this only returns files uploaded to top-level records...shouldn't it do child records as well?
            $query = $em->createQuery(
                'SELECT SUM(f.filesize) FROM ODRAdminBundle:File AS f
                JOIN f.dataRecord AS dr
                JOIN dr.dataType AS dt
                WHERE dt.id = :datatype_id
                AND f.deletedAt IS NULL AND dr.deletedAt IS NULL'
            )->setParameters(array('datatype_id' => $datatype->getId()));
            $total = $query->getScalarResult();

            if ($total[0][1] > 25000000000) {
                // 25 GB temporary limit
                throw new ODRForbiddenException("Quota Exceeded (25GB)");
            }

            // Check for local file on server (with name & path from data
            /*
             * $data['local_files']['0']['local_file_name'] = '92q0fa9klaj340jasfd90j13';
             * $data['local_files']['0']['original_file_name'] = 'some_file.txt';
             */
            $using_local_files = false;
            $file_array = array();
            if (isset($data['local_files']) && count($data['local_files']) > 0) {
                $using_local_files = true;
                $file_array = $data['local_files'];
            }

            if (!$using_local_files) {
                $files_bag = $request->files->all();
                if (count($files_bag) < 1)
                    throw new ODRNotFoundException('File to upload');

                foreach ($files_bag as $file)
                    $file_array[] = $file;
            }


            // ----------------------------------------
            // Ensure the relevant drf entry exists
            $drf = $ec_service->createDatarecordField($user, $datarecord, $datafield, $created);

            foreach ($file_array as $file) {
                // Going to need these...
                $local_filename = '';
                $original_filename = '';
                $current_folder = '';

                // Regardless of whether the file is "local" or not, it needs to get moved to this
                //  directory so that ODRUploadService can find it
                $destination_folder = $this->getParameter('odr_tmp_directory') . '/user_' . $user->getId() . '/chunks/completed';
                if (!file_exists($destination_folder))
                    mkdir($destination_folder, 0777, true);
//                $logger->debug('ensured "'.$destination_folder.'" exists', array('APIController::addfileAction()'));

                if ($using_local_files) {
                    // If the file is "local" then the POST request will have both its current name
                    //  on the disk, and its desired name after the upload
                    $local_filename = $file['local_file_name'];
                    $original_filename = $file['original_file_name'];

                    // Additionally, it won't be in the "usual" place
                    $current_folder = $this->getParameter('uploaded_files_path');
//                    $logger->debug('is local file...local_filename: "'.$local_filename.'", original_filename: "'.$original_filename.'", current_folder: "'.$current_folder.'"', array('APIController::addfileAction()'));
                } else {
                    // Otherwise, the file will have been "uploaded" as part of the POST request
                    /** @var \Symfony\Component\HttpFoundation\File\File $file */
                    $local_filename = $file->getFileName();
                    $original_filename = $file->getClientOriginalName();

                    // ...the "usual" place is same $destination given to FlowController::saveFile()
                    $current_folder = $this->getParameter('odr_tmp_directory') . '/user_' . $user->getId() . '/chunks/completed';
//                    $logger->debug('not local file...local_filename: "'.$local_filename.'", original_filename: "'.$original_filename.'"', array('APIController::addfileAction()'));

                    // ...so get Symfony to move the file from the POST request to that location
                    $file->move($current_folder);
//                    $logger->debug('file moved to current_folder: "'.$current_folder.'"', array('APIController::addfileAction()'));
                }

//                if ( file_exists($current_folder.'/'.$local_filename) )
//                    $logger->debug('file at "'.$current_folder.'/'.$local_filename.'" exists', array('APIController::addfileAction()'));
//                else
//                    $logger->debug('file at "'.$current_folder.'/'.$local_filename.'" does not exist', array('APIController::addfileAction()'));

                // Move the file from its current location to its expected location
                rename($current_folder . '/' . $local_filename, $destination_folder . '/' . $original_filename);

//                if ( file_exists($destination_folder.'/'.$original_filename) )
//                    $logger->debug('file successfully moved to "'.$destination_folder.'/'.$original_filename.'"', array('APIController::addfileAction()'));
//                else
//                    $logger->debug('unable to move file to "'.$destination_folder.'/'.$original_filename.'"???', array('APIController::addfileAction()'));

                // TODO - Need to also check file size here?

                // ----------------------------------------
                // Now that the file is in the correct place, get ODR to encrypt it properly
                switch ($typeclass) {
                    case 'File':
                        // If the field only allows a single file...
                        if (!$datafield->getAllowMultipleUploads()) {
                            // ...then delete the currently uploaded file if one exists
                            /** @var File $current_file */
                            $current_file = $em->getRepository('ODRAdminBundle:File')->findOneBy(
                                array('dataRecordFields' => $drf->getId())
                            );
                            if ($current_file != null)
                                $ed_service->deleteFile($current_file, $user);
                        }

                        // Upload the new file
                        $odr_upload_service->uploadNewFile(
                            $destination_folder . '/' . $original_filename,
                            $user,
                            $drf,
                            $created,
                            $public_date,
                            $quality
                        );

                        break;

                    case 'Image':
                        // If the field only allows a single image...
                        if (!$datafield->getAllowMultipleUploads()) {
                            // ...then delete the currently uploaded image if one exists
                            /** @var Image $current_image */
                            $current_image = $em->getRepository('ODRAdminBundle:Image')->findOneBy(
                                array(
                                    'dataRecordFields' => $drf->getId(),
                                    'parent' => null
                                )
                            );
                            if ($current_image != null)
                                $ed_service->deleteImage($current_image, $user);
                        }

                        // Upload the new image
                        $odr_upload_service->uploadNewImage(
                            $destination_folder . '/' . $original_filename,
                            $user,
                            $drf,
                            $created,
                            $public_date,
                            $display_order,
                            $quality
                        );
                        break;
                }
            }

            // TODO - need to build image and file arrays here and fix into JSON....

            // Don't need to fire off any more events...the services have already done so


            // ----------------------------------------
            $response = new Response('Created', 201);
            $url = $this->generateUrl(
                'odr_api_get_dataset_record',
                array(
                    'version' => $version,
                    'record_uuid' => $datarecord->getGrandparent()->getUniqueId()
                ),
                false
            );
            $response->headers->set('Location', $url);

            return $this->redirect($url);
        } catch (\Exception $e) {
            $source = 0x8a83ef88;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Retrieves a dataset record by the record UUID
     *
     * @param string $version
     * @param string $record_uuid
     * @param Request $request
     *
     * @return Response
     */
    public function getRecordAction($version, $record_uuid, Request $request): Response
    {
        try {
            // Check API permission level (if SuperAPI - override user)
            // API Super should be able to administer datatypes derived from certain templates

            // $user_manager = $this->container->get('fos_user.user_manager');
            // $user = $user_manager->findUserBy(array('email' => 'nate@opendatarepository.org'));

            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if (is_null($user))
                throw new ODRNotFoundException('User');

            return $this->getDatarecordExportAction(
                $version,
                $record_uuid,
                $request,
                $user
            );
        } catch (\Exception $e) {
            $source = 0x9ea474c1;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

    }

    /**
     * @param $version
     * @param $datarecord_uuid
     * @param $format
     * @param bool $display_metadata
     * @param null $user
     * @param bool $flush
     * @return array|bool|string
     */
    private function getRecordData(
        $version,
        $datarecord_uuid,
        $format,
        $display_metadata = false,
        $user = null,
        $flush = false
    )
    {
        // ----------------------------------------
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();

        /** @var DatarecordExportService $dre_service */
        $dre_service = $this->container->get('odr.datarecord_export_service');
        /** @var PermissionsManagementService $pm_service */
        $pm_service = $this->container->get('odr.permissions_management_service');


        /** @var DataRecord $datarecord */
        $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->findOneBy(
            array('unique_id' => $datarecord_uuid)
        );
        if ($datarecord == null)
            throw new ODRNotFoundException('Datarecord');

        $datarecord_id = $datarecord->getId();

        $datatype = $datarecord->getDataType();
        if (!$datatype || $datatype->getDeletedAt() != null)
            throw new ODRNotFoundException('Datatype');

        // TODO Why???
        if ($datarecord->getId() != $datarecord->getGrandparent()->getId())
            throw new ODRBadRequestException('Only permitted on top-level datarecords');


        // ----------------------------------------
        // Determine user privileges
        /** @var ODRUser $user */
        if ($user === null) {
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
        }

        // If either the datatype or the datarecord is not public, and the user doesn't have
        //  the correct permissions...then don't allow them to view the datarecord
        if (!$pm_service->canViewDatatype($user, $datatype))
            throw new ODRForbiddenException();

        if (!$pm_service->canViewDatarecord($user, $datarecord))
            throw new ODRForbiddenException();


        // TODO - system needs to delete these keys when record is updated elsewhere
        /** @var CacheService $cache_service */
        $cache_service = $this->container->get('odr.cache_service');
        $data = $cache_service
            ->get('json_record_' . $datarecord_uuid);

        $data = false;
        // $flush = true;
        if (!$data || $flush) {

            // Render the requested datarecord
            $data = $dre_service->getData(
                $version,
                array($datarecord_id),
                $format,
                $display_metadata,
                $user,
                $this->container->getParameter('site_baseurl'),
                0
            );


            // Cache this data for faster retrieval
            // TODO work out how to expire this data...
            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');
            $cache_service->set(
                'json_record_' . $datarecord_uuid,
                $data
            );
        }

        return $data;
    }

    /**
     * Renders and returns the json/XML version of the
     * given DataRecord.
     *
     * @param $version
     * @param $record_uuid
     * @param Request $request
     * @param null $user
     * @return Response
     */
    public function getDatarecordExportAction(
        $version, $record_uuid, Request $request, $user = null
    )
    {
        try {
            // ----------------------------------------
            // Default to only showing all info about the datarecord
            $display_metadata = true;
            if ($request->query->has('metadata') && $request->query->get('metadata') == 'false')
                // ...but restrict to only the most useful info upon request
                $display_metadata = false;

            // Default to returning the data straight to the browser...
            $download_response = false;
            if ($request->query->has('download') && $request->query->get('download') == 'file')
                // ...but return the data as a download on request
                $download_response = true;


            $data = self::getRecordData(
                $version,
                $record_uuid,
                $request->getRequestFormat(),
                $display_metadata,
                $user
            );


            // Set up a response to send the datatype back
            $response = new Response();

            if ($download_response) {
                $response->setPrivate();
                //$response->headers->set('Content-Length', filesize($xml_export_path.$filename));
                $response->headers->set(
                    'Content-Disposition',
                    'attachment; filename="Datarecord_' . $data['internal_id'] . '.' . $request->getRequestFormat() . '";'
                );
            }

            $response->setContent($data);
            return $response;
        } catch (\Exception $e) {
            $source = 0x80e2674a;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }

    /**
     * @param $version
     * @param $template_uuid
     * @param $template_field_uuid
     * @param Request $request
     *
     * @return Response
     */
    public function getfieldstatsbydatasetAction(
        $version,
        $template_uuid,
        $template_field_uuid,
        Request $request
    )
    {
        try {
            // ----------------------------------------

            // Default to returning the data straight to the browser...
            $download_response = false;
            if ($request->query->has('download') && $request->query->get('download') == 'file')
                // ...but return the data as a download on request
                $download_response = true;

            // ----------------------------------------
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchAPIService $search_api_service */
            $search_api_service = $this->container->get('odr.search_api_service');
            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');

            /** @var DataType $template_datatype */
            $template_datatype = $em->getRepository('ODRAdminBundle:DataType')->findOneBy(
                array(
                    'unique_id' => $template_uuid,
                    'is_master_type' => 1    // require master template
                )
            );
            if ($template_datatype == null)
                throw new ODRNotFoundException('Datatype');

            /** @var DataFields $template_datafield */
            $template_datafield = $em->getRepository('ODRAdminBundle:DataFields')->findOneBy(
                array(
                    'dataType' => $template_datatype->getId(),
                    'fieldUuid' => $template_field_uuid
                )
            );
            if ($template_datafield == null)
                throw new ODRNotFoundException('Datafield');

            $typeclass = $template_datafield->getFieldType()->getTypeClass();
            if ($typeclass !== 'Radio' && $typeclass !== 'Tag')
                throw new ODRBadRequestException('Getting field stats only makes sense for Radio or Tag fields');

            $item_label = 'template_radio_option_uuid';
            $array_label = 'radio_options';
            if ($typeclass === 'Tag') {
                $item_label = 'template_tag_uuid';
                $array_label = 'value';
            }

            // ----------------------------------------
            // Determine user privileges
            /** @var ODRUser $user */
            // $token = $this->container->get('security.token_storage')->getToken();   // <-- will return 'anon.' when nobody is logged in
            // $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in


            // TODO this is currently used by public searches only.  Need to improve call to allow private.
            $user = 'anon.';
            $user_permissions = $pm_service->getUserPermissionsArray($user);

            // TODO - should permissions get involved on the template side?
            /*
                        // If either the datatype or the datarecord is not public, and the user doesn't have
                        //  the correct permissions...then don't allow them to view the datarecord
                        if ( !$pm_service->canViewDatatype($user, $datatype) )
                            throw new ODRForbiddenException();

                        if ( !$pm_service->canViewDatarecord($user, $datarecord) )
                            throw new ODRForbiddenException();
            */

            // ----------------------------------------
            // Craft a search key specifically for this API call
            $params = array(
                "template_uuid" => $template_uuid,
                "field_stats" => $template_field_uuid,
            );
            $search_key = $search_key_service->encodeSearchKey($params);

            // Don't need to validate the search key...don't want people to be able to run this
            //  type of search without going through this action anyways

            $result = $search_api_service->performTemplateSearch($search_key, $user_permissions);

            $labels = $result['labels'];
            $records = $result['records'];


            /** @var DatatypeExportService $dte_service */
            $dte_service = $this->container->get('odr.datatype_export_service');

            // Render the requested datatype
            $template_data = $dte_service->getData(
                $version,
                $template_datatype->getId(),
                $request->getRequestFormat(),
                false,
                $user,
                $this->container->getParameter('site_baseurl')
            );

            $template = json_decode($template_data, true);

            // get the field in question
            $field = array();
            for ($i = 0; $i < count($template['fields']); $i++) {
                if ($template['fields'][$i]['template_field_uuid'] == $template_field_uuid) {
                    $field = $template['fields'][$i];
                    break;
                }
            }

            // print var_export($records, true);exit();
            // Translate the two provided arrays into a a slightly different format
            $data = array();
            foreach ($records as $dt_id => $df_list) {
                foreach ($df_list as $df_id => $dr_list) {
                    foreach ($dr_list as $dr_id => $item_list) {
                        foreach ($item_list as $num => $item_uuid) {
                            $item_name = $labels[$item_uuid];
                            if (!isset($data[$item_name])) {
                                $data[$item_name] = array(
                                    $item_label => $item_uuid
                                );
                            }
                            $data[$item_name]['records'][] = $dr_id;
                        }
                    }
                }
            }

            for ($i = 0; $i < count($field[$array_label]); $i++) {
                // First level
                $level = $field[$array_label][$i];
                $level_array = [];
                foreach ($data as $name => $item_record) {
                    if ($level[$item_label] == $item_record[$item_label]) {
                        // add records to parent array
                        $level_array = array_merge($level_array, $item_record['records']);
                    }
                }

                // Get array of matching records
                // Merge with array of records matching child terms
                $sub_level_array = [];
                if (isset($level['children'])) {
                    $sub_level_array = self::check_children($level['children'], $data, $item_label);
                }
                $level_array = array_merge($level_array, $sub_level_array);
                $level['count'] = count(array_unique($level_array));
                $field[$array_label][$i] = $level;
            }

            // print(json_encode($field));exit();

            // Set up a response to send the datatype back
            $response = new Response();
            $response->setContent(json_encode($field));
            return $response;
        } catch (\Exception $e) {
            $source = 0x883def33;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * @param $selection_array
     * @param $data
     * @param $item_label
     * @return array
     */
    function check_children(&$selection_array, $data, $item_label)
    {

        $my_level_array = [];
        for ($i = 0; $i < count($selection_array); $i++) {
            $level = $selection_array[$i];

            $sub_level_array = [];
            foreach ($data as $name => $item_record) {
                if ($level[$item_label] == $item_record[$item_label]) {
                    // add records to parent array
                    $sub_level_array = array_merge($sub_level_array, $item_record['records']);
                }
            }

            $children_array = [];
            if (isset($level['children'])) {
                $children_array = self::check_children($level['children'], $data, $item_label);
            }
            $sub_level_array = array_merge($sub_level_array, $children_array);
            $level['count'] = count(array_unique($sub_level_array));
            $selection_array[$i] = $level;

            $my_level_array = array_merge($my_level_array, $sub_level_array);
        }
        return $my_level_array;
    }

    /**
     * Returns a list of radio options in the given template field, and a count of how many
     * datarecords from datatypes derived from the given template have those options selected.
     *
     * @param $version
     * @param $template_uuid
     * @param $template_field_uuid
     * @param Request $request
     */
    public function getfieldstatsAction(
        $version,
        $template_uuid,
        $template_field_uuid,
        Request $request
    )
    {
        try {
            // ----------------------------------------

            // Default to returning the data straight to the browser...
            $download_response = false;
            if ($request->query->has('download') && $request->query->get('download') == 'file')
                // ...but return the data as a download on request
                $download_response = true;

            // ----------------------------------------
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchAPIService $search_api_service */
            $search_api_service = $this->container->get('odr.search_api_service');
            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');

            /** @var DataType $template_datatype */
            $template_datatype = $em->getRepository('ODRAdminBundle:DataType')->findOneBy(
                array(
                    'unique_id' => $template_uuid,
                    'is_master_type' => 1    // require master template
                )
            );
            if ($template_datatype == null)
                throw new ODRNotFoundException('Datatype');

            /** @var DataFields $template_datafield */
            $template_datafield = $em->getRepository('ODRAdminBundle:DataFields')->findOneBy(
                array(
                    'dataType' => $template_datatype->getId(),
                    'fieldUuid' => $template_field_uuid
                )
            );
            if ($template_datafield == null)
                throw new ODRNotFoundException('Datafield');

            $typeclass = $template_datafield->getFieldType()->getTypeClass();
            if ($typeclass !== 'Radio' && $typeclass !== 'Tag')
                throw new ODRBadRequestException('Getting field stats only makes sense for Radio or Tag fields');

            $item_label = 'template_radio_option_uuid';
            if ($typeclass === 'Tag')
                $item_label = 'template_tag_uuid';

            // ----------------------------------------
            // Determine user privileges
            /** @var ODRUser $user */
            // $token = $this->container->get('security.token_storage')->getToken();   // <-- will return 'anon.' when nobody is logged in
            // $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in


            // TODO this is currently used by public searches only.  Need to improve call to allow private.
            $user = 'anon.';
            $user_permissions = $pm_service->getUserPermissionsArray($user);

            // TODO - should permissions get involved on the template side?
            /*
                        // If either the datatype or the datarecord is not public, and the user doesn't have
                        //  the correct permissions...then don't allow them to view the datarecord
                        if ( !$pm_service->canViewDatatype($user, $datatype) )
                            throw new ODRForbiddenException();

                        if ( !$pm_service->canViewDatarecord($user, $datarecord) )
                            throw new ODRForbiddenException();
            */

            // ----------------------------------------
            // Craft a search key specifically for this API call
            $params = array(
                "template_uuid" => $template_uuid,
                "field_stats" => $template_field_uuid,
            );
            $search_key = $search_key_service->encodeSearchKey($params);

            // Don't need to validate the search key...don't want people to be able to run this
            //  type of search without going through this action anyways

            $result = $search_api_service->performTemplateSearch($search_key, $user_permissions);

            $labels = $result['labels'];
            $records = $result['records'];

            // Translate the two provided arrays into a a slightly different format
            $data = array();
            foreach ($records as $dt_id => $df_list) {
                foreach ($df_list as $df_id => $dr_list) {
                    foreach ($dr_list as $dr_id => $item_list) {
                        foreach ($item_list as $num => $item_uuid) {
                            $item_name = $labels[$item_uuid];
                            if (!isset($data[$item_name])) {
                                $data[$item_name] = array(
                                    'count' => 0,
                                    $item_label => $item_uuid
                                );
                            }

                            $data[$item_name]['count']++;
                        }
                    }
                }
            }

            // Sort the options in descending order by number of datarecords where they're selected
            uasort($data, function ($a, $b) {
                if ($a['count'] < $b['count'])
                    return 1;
                else if ($a['count'] == $b['count'])
                    return 0;
                else
                    return -1;
            });


            // ----------------------------------------
            // Render the data in the requested format
            $format = $request->getRequestFormat();
            $templating = $this->get('templating');
            $data = $templating->render(
                'ODRAdminBundle:API:field_stats.' . $format . '.twig',
                array(
                    'field_stats' => $data
                )
            );

            // Set up a response to send the datatype back
            $response = new Response();

            if ($download_response) {
                $response->setPrivate();
                //$response->headers->set('Content-Length', filesize($xml_export_path.$filename));
                $response->headers->set('Content-Disposition', 'attachment; filename="Datafield_' . $template_field_uuid . '.' . $request->getRequestFormat() . '";');
            }

            $response->setContent($data);
            return $response;
        } catch (\Exception $e) {
            $source = 0x66869767;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }

    /**
     * Creates a Symfony Response so API users can download a file or an image.
     *
     * @param string $version
     * @param string $file_uuid
     * @param Request $request
     *
     * @return Response|StreamedResponse
     */
    public function fileDownloadByUUIDAction($version, $file_uuid, Request $request)
    {
        try {
            // ----------------------------------------
            // Load required objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CryptoService $crypto_service */
            $crypto_service = $this->container->get('odr.crypto_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            // This API action works on both files and images...
            $obj = $em->getRepository('ODRAdminBundle:File')->findOneBy(
                array('unique_id' => $file_uuid)
            );
            if ($obj == null) {
                // ...if there's no file with the given UUID, look for an image instead
                $obj = $em->getRepository('ODRAdminBundle:Image')->findOneBy(
                    array('unique_id' => $file_uuid)
                );
            }
            if ($obj == null)
                throw new ODRNotFoundException('File');
            /** @var File|Image $obj */

            $datafield = $obj->getDataField();
            if ($datafield->getDeletedAt() != null)
                throw new ODRNotFoundException('Datafield');
            $typeclass = $datafield->getFieldType()->getTypeClass();

            $datarecord = $obj->getDataRecord();
            if ($datarecord->getDeletedAt() != null)
                throw new ODRNotFoundException('Datarecord');

            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            // Files/Images that aren't done encrypting shouldn't be downloaded
            if ($obj->getEncryptKey() === '')
                throw new ODRNotFoundException('File');


            // ----------------------------------------
            // Determine user privileges
            // TODO - Determine how to make this work for "act-as" users
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in

            if ($typeclass === 'File') {
                if (!$pm_service->canViewFile($user, $obj))
                    throw new ODRForbiddenException();
            } else if ($typeclass === 'Image') {
                if (!$pm_service->canViewImage($user, $obj))
                    throw new ODRForbiddenException();
            }
            // ----------------------------------------

            if ($typeclass === 'File') {
                /** @var File $file */
                $file = $obj;

                // Only allow this action for files smaller than 5Mb?
                $filesize = $file->getFilesize() / 1024 / 1024;
                if ($filesize > 50)
                    throw new ODRNotImplementedException('Currently not allowed to download files larger than 5Mb');

                // Ensure file exists on the server before attempting to serve it...
                $filename = 'File_' . $file->getId() . '.' . $file->getExt();
                if (!$file->isPublic())
                    $filename = md5($file->getOriginalChecksum() . '_' . $file->getId() . '_' . $user->getId()) . '.' . $file->getExt();

                $local_filepath = realpath($this->getParameter('odr_web_directory') . '/' . $file->getUploadDir() . '/' . $filename);
                if (!$local_filepath)
                    $local_filepath = $crypto_service->decryptFile($file->getId(), $filename);

                $handle = fopen($local_filepath, 'r');
                if ($handle === false)
                    throw new FileNotFoundException($local_filepath);


                // Attach the original filename to the download
                $display_filename = $file->getOriginalFileName();
                if ($display_filename == null)
                    $display_filename = 'File_' . $file->getId() . '.' . $file->getExt();

                // Set up a response to send the file back
                $response = new StreamedResponse();
                $response->setPrivate();
                $response->headers->set('Content-Length', filesize($local_filepath));        // TODO - apparently this isn't sent?
                $response->headers->set('Content-Disposition', 'attachment; filename="' . $display_filename . '";');
                $response->headers->set('Content-Type', mime_content_type($local_filepath));

                // Use symfony's StreamedResponse to send the decrypted file back in chunks to the user
                $response->setCallback(function () use ($handle) {
                    while (!feof($handle)) {
                        $buffer = fread($handle, 65536);    // attempt to send 64Kb at a time
                        echo $buffer;
                        flush();
                    }
                    fclose($handle);
                });

                // If file is non-public, delete the decrypted version off the server
                if (!$file->isPublic())
                    unlink($local_filepath);

                return $response;
            } else if ($typeclass === 'Image') {
                /** @var Image $image */
                $image = $obj;

                // Ensure file exists before attempting to download it
                $filename = 'Image_' . $image->getId() . '.' . $image->getExt();
                if (!$image->isPublic())
                    $filename = md5($image->getOriginalChecksum() . '_' . $image->getId() . '_' . $user->getId()) . '.' . $image->getExt();

                // Ensure the image exists in decrypted format
                $image_path = realpath($this->getParameter('odr_web_directory') . '/' . $filename);     // realpath() returns false if file does not exist
                if (!$image->isPublic() || !$image_path)
                    $image_path = $crypto_service->decryptImage($image->getId(), $filename);

                $handle = fopen($image_path, 'r');
                if ($handle === false)
                    throw new FileNotFoundException($image_path);

                // Have to send image headers first...
                $response = new Response();
                $response->setPrivate();

                switch (strtolower($image->getExt())) {
                    case 'gif':
                        $response->headers->set('Content-Type', 'image/gif');
                        break;
                    case 'png':
                        $response->headers->set('Content-Type', 'image/png');
                        break;
                    case 'jpg':
                    case 'jpeg':
                        $response->headers->set('Content-Type', 'image/jpeg');
                        break;
                }

                // Attach the image's original name to the headers...
                $display_filename = $image->getOriginalFileName();
                if ($display_filename == null)
                    $display_filename = 'Image_' . $image->getId() . '.' . $image->getExt();

                $response->headers->set('Content-Disposition', 'inline; filename="' . $display_filename . '";');
                $response->sendHeaders();

                // After headers are sent, send the image itself
                $im = null;
                switch (strtolower($image->getExt())) {
                    case 'gif':
                        $im = imagecreatefromgif($image_path);
                        imagegif($im);
                        break;
                    case 'png':
                        $im = imagecreatefrompng($image_path);
                        imagepng($im);
                        break;
                    case 'jpg':
                    case 'jpeg':
                        $im = imagecreatefromjpeg($image_path);
                        imagejpeg($im);
                        break;
                }
                imagedestroy($im);

                fclose($handle);

                // If the image isn't public, delete the decrypted version so it can't be accessed without going through symfony
                if (!$image->isPublic())
                    unlink($image_path);

                return $response;
            }
        } catch (\Exception $e) {
            // Returning an error...do it in json
            $request->setRequestFormat('json');

            $source = 0xbbaafae5;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Begins the download of a file by its id.
     *
     * @param string $version
     * @param integer $file_id
     * @param Request $request
     *
     * @return JsonResponse|StreamedResponse
     */
    public function filedownloadAction($version, $file_id, Request $request)
    {
        try {
            // Need to load the file to convert the id into a uuid...
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var File $file */
            $file = $em->getRepository('ODRAdminBundle:File')->find($file_id);
            if ($file == null)
                throw new ODRNotFoundException('File');
            $file_uuid = $file->getUniqueId();

            // ...but the download is otherwise handled by this other controller action
            return $this->fileDownloadByUUIDAction(
                $version,
                $file_uuid,
                $request
            );
        } catch (\Exception $e) {
            // Any errors should be returned in json format
            $request->setRequestFormat('json');

            $source = 0x91c5c5d9;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Begins the download of an image by its id.
     *
     * @param string $version
     * @param integer $image_id
     * @param Request $request
     *
     * @return JsonResponse|StreamedResponse
     */
    public function imagedownloadAction($version, $image_id, Request $request)
    {
        try {
            // Need to load the file to convert the id into a uuid...
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var Image $image */
            $image = $em->getRepository('ODRAdminBundle:Image')->find($image_id);
            if ($image == null)
                throw new ODRNotFoundException('Image');
            $image_uuid = $image->getUniqueId();

            // ...but the download is otherwise handled by this other controller action
            return $this->fileDownloadByUUIDAction(
                $version,
                $image_uuid,
                $request
            );
        } catch (\Exception $e) {
            // Any errors should be returned in json format
            $request->setRequestFormat('json');

            $source = 0x3c4842c5;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }

    /**
     * @param string $version
     * @param Request $request
     * @return Response
     */
    public function userPermissionsAction($version, Request $request)
    {

        try {

            $user_email = null;
            if (isset($_POST['user_email']))
                $user_email = $_POST['user_email'];

            if (!isset($_POST['dataset_uuid']))
                throw new ODRBadRequestException('Dataset UUID is required.');

            $dataset_uuid = $_POST['dataset_uuid'];

            if (!isset($_POST['permission']))
                throw new ODRBadRequestException('Permission type is required.');

            // one of "admin", "edit_all", "view_all", "view_only"
            $permission = $_POST['permission'];

            // We must check if the logged in user is acting as a user
            // When acting as a user, the logged in user must be a SuperAdmin
            /** @var ODRUser $logged_in_user */
            $logged_in_user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            if ($user_email === '') {
                // User is setting up dataset for themselves - always allowed
                $user_email = $logged_in_user->getEmail();
            } else if (!$logged_in_user->hasRole('ROLE_SUPER_ADMIN')) {
                // We are acting as a user and do not have Super Permissions - Forbidden
                throw new ODRForbiddenException();
            }

            // Check if user exists & throw user not found error
            // Save which user started this creation process
            // Any user can create a dataset as long as they exist
            // No need to do further permissions checks.
            $user_manager = $this->container->get('fos_user.user_manager');
            /** @var ODRUser $user */
            $user = $user_manager->findUserBy(array('email' => $user_email));
            if (is_null($user))
                throw new ODRNotFoundException('unrecognized email: "' . $user_email . '"');

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->findOneBy(
                array(
                    'unique_id' => $dataset_uuid
                )
            );

            if (!$datatype)
                throw new ODRNotFoundException('Datatype');

            // Grant
            /** @var ODRUserGroupMangementService $user_group_service */
            $user_group_service = $this->container->get('odr.user_group_management_service');
            $user_group_service->addUserToDefaultGroup(
                $logged_in_user,
                $user,
                $datatype,
                $permission
            );

            /** @var Response $response */
            $response = new Response();
            $response->headers->set('Content-Type', 'application/json');
            $output_array = [];
            $output_array['success'] = "true";
            $response->setContent(json_encode($output_array));

            return $response;
        } catch (\Exception $e) {
            // Any errors should be returned in json format
            $request->setRequestFormat('json');

            $source = 0xafaf3835;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }

    /**
     * Retrieve user info and list of created databases (that have metadata).
     * Creates user if not exists.
     *
     * @param $version
     * @param Request $request
     */
    public function userAction($version, Request $request)
    {


        try {
            $user_email = null;
            if (isset($_POST['user_email']))
                $user_email = $_POST['user_email'];

            // TODO - implement update of user data
//            if (!isset($_POST['first_name']) || !isset($_POST['last_name']))
//                throw new ODRBadRequestException("First and last name paramaters are required.");
//
//            $first_name = $_POST['first_name'];
//            $last_name = $_POST['last_name'];

            // We must check if the logged in user is acting as a user
            // When acting as a user, the logged in user must be a SuperAdmin
            /** @var ODRUser $logged_in_user */
            $logged_in_user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            if (strlen($user_email) < 5)
                throw new ODRNotFoundException('User Email Parameter');

            // The logged in user must be a SuperAdmin to create users
            if (!$logged_in_user->hasRole('ROLE_SUPER_ADMIN')) {
                // We are acting as a user and do not have Super Permissions - Forbidden
                throw new ODRForbiddenException();
            }

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            // Check if user exists & throw user not found error
            // Save which user started this creation process
            // Any user can create a dataset as long as they exist
            // No need to do further permissions checks.
            $user_manager = $this->container->get('fos_user.user_manager');
            /** @var FOSUser $user */
            $user = $user_manager->findUserBy(array('email' => $user_email));
            if (is_null($user)) {
                // Create a new user with this email & set a random password

                $user = $user_manager->createUser();
                $user->setUsername($user_email);
                $user->setEmail($user_email);
                $user->setPlainPassword(random_bytes(8));
                $user->setRoles(array('ROLE_USER'));
                $user->setEnabled(1);
                $user_manager->updateUser($user);

            } else {
                // Undelete User if needed
                $user->setEnabled(1);
                $user_manager->updateUser($user);


                $filter = $em->getFilters()->enable('softdeleteable');
                $filter->disableForEntity(UserGroup::class);

                $user_groups = $em->getRepository('ODRAdminBundle:UserGroup')
                    ->findBy(array('user' => $user->getId()));

                foreach ($user_groups as $group) {
                    $group->setDeletedAt(null);
                    $group->setDeletedBy(null);
                    $em->persist($group);
                }

                $em->flush();
            }


            /** @var DataType $datatype */
            /*
            $datatypes = $em->getRepository('ODRAdminBundle:DataType')->findAll(
                array(
                    'createdBy' => $user,
                    'is_master_type' => 0,
                    'metadata_for_id'
                )
            );
            */

            $query = $em->createQuery(
                'SELECT
                       dt.id AS database_id,
                       dt.unique_id AS database_uuid,
                       dr.id AS record_id,
                       dr.unique_id AS record_uuid
                    FROM ODRAdminBundle:DataType AS dt
                    JOIN ODRAdminBundle:DataRecord AS dr WITH dr.dataType = dt
                    WHERE dt.setup_step IN (:setup_steps)
                    AND dt.createdBy = :user
                    AND dt.is_master_type = :is_master_type 
                    AND dt.metadata_for IS NOT NULL
                    AND dt.deletedAt IS NULL'
            )->setParameters(
                array(
                    'user' => $user,
                    'setup_steps' => DataType::STATE_VIEWABLE,
                    'is_master_type' => 0
                )
            );
            $results = $query->getArrayResult();

            $metadata_records = array();
            foreach ($results as $record) {
                try {
                    $data = self::getRecordData(
                        $version,
                        $record['record_uuid'],
                        $request->getRequestFormat(),
                        1, // Need to figure out how this is set
                        $user
                    );

                    if ($data) {
                        array_push($metadata_records, json_decode($data));
                    }
                } catch (\Exception $e) {
                    // Ignoring errors building data
                    // TODO need to determine cause of data errors
                }

            }

            $output_array = array();
            $output_array['user_email'] = $user->getEmail();
            $output_array['datasets'] = $metadata_records;


            // Set up a response to send the datatype back
            /** @var Response $response */
            $response = new Response();
            $response->headers->set('Content-Type', 'application/json');
            $response->setContent(json_encode($output_array));

            return $response;

        } catch (\Exception $e) {
            // Any errors should be returned in json format
            $request->setRequestFormat('json');

            $source = 0x8a8b2309;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * @param $template_uuid
     * @param $template_field_uuid
     * @param Request $request
     * @return Response
     */
    public function search_field_statsAction($template_uuid, $template_field_uuid, Request $request)
    {
        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')
                ->findOneBy(
                    array(
                        'is_master_type' => 1,
                        'unique_id' => $template_uuid
                    )
                );

            if ($datatype == null || $datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            // Find all records for datatypes with this master_template_id
            $datatype_array = $em->getRepository('ODRAdminBundle:DataType')
                ->findBy(
                    array(
                        'masterDataType' => $datatype->getId(),
                        'is_master_type' => 0
                    )
                );

            $records = array();
            /** @var DataType $dt */
            foreach ($datatype_array as $dt) {
                // Find record
                $results = $em->createQuery(
                    'SELECT distinct dr FROM ODRAdminBundle:DataRecord dr
                            JOIN ODRAdminBundle:DataRecordMeta drm 
                            WHERE drm.publicDate <= CURRENT_DATE()
                            AND dr.dataType = :data_type_id
                            AND drm.deletedAt IS NULL
                            AND dr.deletedAt IS NULL
                ')
                    ->setParameters(
                        array(
                            'data_type_id' => $dt->getId()
                        )
                    )
                    ->getArrayResult();
                // Add record object to array
                if (count($results) > 0) {
                    $records[] = $results[0];
                }
            }

            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Use get record to build array
            $output_records = array();
            /** @var DataRecord $record */
            foreach ($records as $record) {
                // Let the APIController do the rest of the error-checking
                $all = $request->query->all();
                $all['download'] = 'raw';
                $all['metadata'] = 'true';
                $result = self::getRecordData(
                    'v1',
                    $record['unique_id'],
                    'json',
                    true,
                    $user
                );
                $parsed_result = json_decode($result);
                if (
                    $parsed_result !== null
                    && !property_exists($parsed_result, 'error')
                    && property_exists($parsed_result, 'records')
                    && is_array($parsed_result->records)
                ) {
                    array_push($output_records, $parsed_result->records['0']);
                }
            }
            // Process to build options array matching field id
            $options_data = array();
            foreach ($output_records as $record) {
                self::optionStats($record, $template_field_uuid, $options_data);
            }
            // Return array of records
            $response = new Response(json_encode($options_data));
            $response->headers->set('Content-Type', 'application/json');
            return $response;
        } catch (\Exception $e) {
            $source = 0x54b42212;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }

    /**
     * @param $record
     * @param string $field_uuid
     * @param array $options_data
     */
    function optionStats($record, $field_uuid, &$options_data)
    {
        self::checkOptions($record->fields, $field_uuid, $options_data);
        // Check child records (calls check record)
        if (property_exists($record, 'child_records')) {
            foreach ($record->child_records as $child_record) {
                foreach ($child_record->records as $child_data_record) {
                    self::checkOptions($child_data_record->fields, $field_uuid, $options_data);
                }
            }
        }
        // Check linked records (calls check record)
        if (property_exists($record, 'linked_records')) {
            foreach ($record->linked_records as $child_record) {
                foreach ($child_record->records as $child_data_record) {
                    self::checkOptions($child_data_record->fields, $field_uuid, $options_data);
                }
            }
        }
    }

    /**
     * @param array $record_fields
     * @param string $field_uuid
     * @param array $options_data
     */
    function checkOptions($record_fields, $field_uuid, &$options_data)
    {
        foreach ($record_fields as $field) {
            // We are only checking option fields
            if (
                $field->template_field_uuid == $field_uuid
                && property_exists($field, 'value')
                && is_array($field->value)
            ) {
                foreach ($field->value as $option_id => $option) {
                    foreach ($option as $key => $selected_option) {
                        if (preg_match("/\s\&gt;\s/", $selected_option->name)) {
                            // We need to split and process
                            $option_data = preg_split("/\s\&gt;\s/", $selected_option->name);
                            for ($i = 0; $i < count($option_data); $i++) {
                                if ($i == 0) {
                                    if (!isset($options_data[$option_data[0]])) {
                                        $options_data[$option_data[0]] = array(
                                            'count' => 0,
                                        );
                                    }
                                    $options_data[$option_data[0]]['count']++;
                                    if (count($option_data) == 1) {
                                        $options_data[$option_data[0]]['template_radio_option_uuid'] = $selected_option->template_radio_option_uuid;
                                    }
                                }
                                if ($i == 1) {
                                    if (!isset($options_data[$option_data[0]][$option_data[1]])) {
                                        $options_data[$option_data[0]][$option_data[1]] = array(
                                            'count' => 0,
                                        );
                                    }
                                    $options_data[$option_data[0]][$option_data[1]]['count']++;
                                    if (count($option_data) == 2) {
                                        $options_data[$option_data[0]][$option_data[1]]['template_radio_option_uuid'] = $selected_option->template_radio_option_uuid;
                                    }
                                }
                                if ($i == 2) {
                                    if (!isset($options_data[$option_data[0]][$option_data[1]][$option_data[2]])) {
                                        $options_data[$option_data[0]][$option_data[1]][$option_data[2]] = array(
                                            'count' => 0,
                                        );
                                    }
                                    $options_data[$option_data[0]][$option_data[1]][$option_data[2]]['count']++;
                                    if (count($option_data) == 3) {
                                        $options_data[$option_data[0]][$option_data[1]][$option_data[2]]['template_radio_option_uuid'] = $selected_option->template_radio_option_uuid;
                                    }
                                }
                                if ($i == 3) {
                                    if (!isset($options_data[$option_data[0]][$option_data[1]][$option_data[2]][$option_data[3]])) {
                                        $options_data[$option_data[0]][$option_data[1]][$option_data[2]][$option_data[3]] = array(
                                            'count' => 0,
                                        );
                                    }
                                    $options_data[$option_data[0]][$option_data[1]][$option_data[2]][$option_data[3]]['count']++;
                                    if (count($option_data) == 4) {
                                        $options_data[$option_data[0]][$option_data[1]][$option_data[2]][$option_data[3]]['template_radio_option_uuid'] = $selected_option->template_radio_option_uuid;
                                    }
                                }
                                if ($i == 4) {
                                    if (!isset($options_data[$option_data[0]][$option_data[1]][$option_data[2]][$option_data[3]][$option_data[4]])) {
                                        $options_data[$option_data[0]][$option_data[1]][$option_data[2]][$option_data[3]][$option_data[4]] = array(
                                            'count' => 0,
                                        );
                                    }
                                    $options_data[$option_data[0]][$option_data[1]][$option_data[2]][$option_data[3]][$option_data[4]]['count']++;
                                    if (count($option_data) == 5) {
                                        $options_data[$option_data[0]][$option_data[1]][$option_data[2]][$option_data[3]][$option_data[4]]['template_radio_option_uuid'] = $selected_option->template_radio_option_uuid;
                                    }
                                }
                                if ($i == 5) {
                                    if (!isset($options_data[$option_data[0]][$option_data[1]][$option_data[2]][$option_data[3]][$option_data[4]][$option_data[5]])) {
                                        $options_data[$option_data[0]][$option_data[1]][$option_data[2]][$option_data[3]][$option_data[4]][$option_data[5]] = array(
                                            'count' => 0,
                                        );
                                    }
                                    $options_data[$option_data[0]][$option_data[1]][$option_data[2]][$option_data[3]][$option_data[4]][$option_data[5]]['count']++;
                                    if (count($option_data) == 6) {
                                        $options_data[$option_data[0]][$option_data[1]][$option_data[2]][$option_data[3]][$option_data[4]][$option_data[5]]['template_radio_option_uuid'] = $selected_option->template_radio_option_uuid;
                                    }
                                }
                                if ($i == 6) {
                                    if (!isset($options_data[$option_data[0]][$option_data[1]][$option_data[2]][$option_data[3]][$option_data[4]][$option_data[5]][$option_data[6]])) {
                                        $options_data[$option_data[0]][$option_data[1]][$option_data[2]][$option_data[3]][$option_data[4]][$option_data[5]][$option_data[6]] = array(
                                            'count' => 0,
                                        );
                                    }
                                    $options_data[$option_data[0]][$option_data[1]][$option_data[2]][$option_data[3]][$option_data[4]][$option_data[5]][$option_data[6]]['count']++;
                                    if (count($option_data) == 7) {
                                        $options_data[$option_data[0]][$option_data[1]][$option_data[2]][$option_data[3]][$option_data[4]][$option_data[5]][$option_data[6]]['template_radio_option_uuid'] = $selected_option->template_radio_option_uuid;
                                    }
                                }
                            }
                        } else {
                            if (!isset($options_data[$selected_option->name])) {
                                $options_data[$selected_option->name] = array(
                                    'count' => 0,
                                );
                            }
                            $options_data[$selected_option->name]['count']++;
                            $options_data[$selected_option->name]['template_radio_option_uuid'] = $selected_option->template_radio_option_uuid;
                        }
                    }
                }
            }
        }
    }


    /**
     * @param $version
     * @param Request $request
     * @return mixed
     * @throws ODRException
     * @throws ODRForbiddenException
     * @throws ODRNotFoundException
     */
    public function createJobAction($version, Request $request)
    {

        try {

            $POST = json_decode(file_get_contents('php://input'), true);

            // Only used if SuperAdmin & Present
            $user_email = null;
            if (isset($POST['user_email']))
                $user_email = $POST['user_email'];

            // Check if user exists & throw user not found error
            /** @var ODRUser $user */  // Anon when nobody is logged in.
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ($user->hasRole('ROLE_SUPER_ADMIN') && $user_email !== null) {
                // Save which user started this creation process
                $user_manager = $this->container->get('fos_user.user_manager');
                /** @var ODRUser $user */
                $user = $user_manager->findUserBy(array('email' => $user_email));
                if (is_null($user))
                    throw new ODRNotFoundException('unrecognized email: "' . $user_email . '"');
            }

            // Check if template is valid
            /** @var EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            // /** @var PermissionsManagementService $pm_service */
            // $pm_service = $this->container->get('odr.permissions_management_service');

            // Check if user can add a tracked job
            // if (!$pm_service->canAddDatarecord($user, $dataset_datatype))
                // throw new ODRForbiddenException();


            $job = array();
            if(isset($POST['job'])) {
                // $job = json_decode($POST['job'], true);
                $job = $POST['job'];
            }


            /** @var TrackedJob $tracked_job */
            $tracked_job = new TrackedJob();
            if(isset($job['job_type']))
                $tracked_job->setJobType($job['job_type']);
            if(isset($job['target_entity']))
                $tracked_job->setTargetEntity($job['target_entity']);

            if(isset($job['total']))
                $tracked_job->setTotal($job['total']);

            $tracked_job->setCreatedBy($user);
            $tracked_job->setCurrent(0);
            $tracked_job->setFailed(0);
            $tracked_job->setStarted(new \DateTime());
            if(isset($job['total']))
                $tracked_job->setTotal($job['total']);
            if(isset($job['additional_data']))
                $tracked_job->setAdditionalData($job['additional_data']);

            $em->persist($tracked_job);
            $em->flush();
            $em->refresh($tracked_job);
            // $repo_job = $em->getRepository('ODRAdminBundle:TrackedJob');
            // /** @var TrackedJob $tracked_job */
            // $tracked_job = $repo_job->findOneBy(['id' => $tracked_job->getId()]);
            return new JsonResponse($tracked_job->toArray());

        } catch (\Exception $e) {
            $source = 0x828fed9f;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

    }

    public function getPut() {
        // Fetch content and determine boundary
        $raw_data = file_get_contents('php://input');
        $boundary = substr($raw_data, 0, strpos($raw_data, "\r\n"));

        // Fetch each part
        $parts = array_slice(explode($boundary, $raw_data), 1);
        $data = array();

        foreach ($parts as $part) {
            // If this is the last part, break
            if ($part == "--\r\n") break;

            // Separate content from headers
            $part = ltrim($part, "\r\n");
            list($raw_headers, $body) = explode("\r\n\r\n", $part, 2);

            // Parse the headers list
            $raw_headers = explode("\r\n", $raw_headers);
            $headers = array();
            foreach ($raw_headers as $header) {
                list($name, $value) = explode(':', $header);
                $headers[strtolower($name)] = ltrim($value, ' ');
            }

            // Parse the Content-Disposition to get the field name, etc.
            if (isset($headers['content-disposition'])) {
                $filename = null;
                preg_match(
                    '/^(.+); *name="([^"]+)"(; *filename="([^"]+)")?/',
                    $headers['content-disposition'],
                    $matches
                );
                list(, $type, $name) = $matches;
                isset($matches[4]) and $filename = $matches[4];

                // handle your fields here
                switch ($name) {
                    // this is a file upload
                    case 'userfile':
                        file_put_contents($filename, $body);
                        break;

                    // default for all other files is to populate $data
                    default:
                        $data[$name] = substr($body, 0, strlen($body) - 2);
                        break;
                }
            }
        }
        return $data;
    }

    /**
     * @param $version
     * @param Request $request
     * @return mixed
     * @throws ODRException
     * @throws ODRForbiddenException
     * @throws ODRNotFoundException
     */
    public function runningJobsAction($version, $job_type, Request $request)
    {
        try {
            // Check if user exists & throw user not found error
            $user_email = null;

            /** @var ODRUser $user */  // Anon when nobody is logged in.
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ($user->hasRole('ROLE_SUPER_ADMIN') && $user_email !== null) {
                // Save which user started this creation process
                $user_manager = $this->container->get('fos_user.user_manager');
                /** @var ODRUser $user */
                $user = $user_manager->findUserBy(array('email' => $user_email));
                if (is_null($user))
                    throw new ODRNotFoundException('unrecognized email: "' . $user_email . '"');
            }

            // Check if template is valid
            /** @var EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            // /** @var PermissionsManagementService $pm_service */
            // $pm_service = $this->container->get('odr.permissions_management_service');

            // Check if user can add a tracked job
            // if (!$pm_service->canAddDatarecord($user, $dataset_datatype))
            // throw new ODRForbiddenException();


            // Get the Job
            $repo_job = $em->getRepository('ODRAdminBundle:TrackedJob');
            /** @var TrackedJob $tracked_job */
            $tracked_jobs = $repo_job->findBy([
                'createdBy' => $user,
                'job_type' => $job_type,
                'completed' => null
            ]);

            $output_array = [];
            $output_array['jobs'] = [];
            if(count($tracked_jobs) > 0) {
                foreach($tracked_jobs as $tracked_job) {
                    $output_array['jobs'][] = $tracked_job->toArray();
                }
            }
            return new JsonResponse($output_array);

        } catch (\Exception $e) {
            $source = 0x8737adf2;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }

    /**
     * @param $version
     * @param Request $request
     * @return mixed
     * @throws ODRException
     * @throws ODRForbiddenException
     * @throws ODRNotFoundException
     */
    public function jobCancelAction($version, $job_id, Request $request)
    {
        try {
            // Check if user exists & throw user not found error
            $user_email = null;

            /** @var ODRUser $user */  // Anon when nobody is logged in.
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ($user->hasRole('ROLE_SUPER_ADMIN') && $user_email !== null) {
                // Save which user started this creation process
                $user_manager = $this->container->get('fos_user.user_manager');
                /** @var ODRUser $user */
                $user = $user_manager->findUserBy(array('email' => $user_email));
                if (is_null($user))
                    throw new ODRNotFoundException('unrecognized email: "' . $user_email . '"');
            }

            // Check if template is valid
            /** @var EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            // /** @var PermissionsManagementService $pm_service */
            // $pm_service = $this->container->get('odr.permissions_management_service');

            // Check if user can add a tracked job
            // if (!$pm_service->canAddDatarecord($user, $dataset_datatype))
            // throw new ODRForbiddenException();


            // Get the Job
            $repo_job = $em->getRepository('ODRAdminBundle:TrackedJob');
            /** @var TrackedJob $tracked_job */
            $tracked_job = $repo_job->findOneBy([
                'id' => $job_id,
                'createdBy' => $user
            ]);

            if($tracked_job) {
                $em->remove($tracked_job);
                $em->flush();
            }
            return new JsonResponse(['deleted' => true]);

        } catch (\Exception $e) {
            $source = 0x8737adf2;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * @param $version
     * @param Request $request
     * @return mixed
     * @throws ODRException
     * @throws ODRForbiddenException
     * @throws ODRNotFoundException
     */
    public function jobCompleteAction($version, $job_id, Request $request)
    {
        try {
            // Check if user exists & throw user not found error
            $user_email = null;

            /** @var ODRUser $user */  // Anon when nobody is logged in.
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ($user->hasRole('ROLE_SUPER_ADMIN') && $user_email !== null) {
                // Save which user started this creation process
                $user_manager = $this->container->get('fos_user.user_manager');
                /** @var ODRUser $user */
                $user = $user_manager->findUserBy(array('email' => $user_email));
                if (is_null($user))
                    throw new ODRNotFoundException('unrecognized email: "' . $user_email . '"');
            }

            // Check if template is valid
            /** @var EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            // Get the Job
            $repo_job = $em->getRepository('ODRAdminBundle:TrackedJob');
            /** @var TrackedJob $tracked_job */
            $tracked_job = $repo_job->findOneBy([
                'id' => $job_id,
                'createdBy' => $user
            ]);

            if($tracked_job) {
                // Update counts from TrackedCSVExport
                /** @var TrackedCSVExport[] $tracked_csv_exports */
                $tracked_csv_exports = $em
                    ->getRepository('ODRAdminBundle:TrackedCSVExport')
                    ->findBy(
                        array('trackedJob' => $tracked_job->getId())
                    );

                $tracked_job->setCurrent(count($tracked_csv_exports));

                $em->persist($tracked_job);
                $em->flush();
                $em->refresh($tracked_job);

                $num_jobs = $tracked_job->getTotal() - $tracked_job->getCurrent();
                for($i=0; $i<$num_jobs; $i++) {
                    // Create rows
                    /*
                        "job": {
                            "tracked_job_id": 1866,
                            "random_key": "random_key_1866",
                            "job_order": 0,
                            "line_count": 1
                        }
                    */
                    $worker_job = new TrackedCSVExport();
                    $worker_job->setTrackedJob($tracked_job);
                    $worker_job->setLineCount(1);
                    $worker_job->setJobOrder(0);
                    $worker_job->setRandomKey('random_key_' + rand(0,1000000));
                    $em->persist($worker_job);
                }

                $em->flush();

                $tracked_csv_exports = $em
                    ->getRepository('ODRAdminBundle:TrackedCSVExport')
                    ->findBy(
                        array('trackedJob' => $tracked_job->getId())
                    );

                // Update Count
                $tracked_job->setCurrent(count($tracked_csv_exports));
                if($tracked_job->getCurrent() === $tracked_job->getTotal()) {
                    $tracked_job->setCompleted(new \DateTime());
                }
                $em->persist($tracked_job);
                $em->flush();
                $em->refresh($tracked_job);
            }
            else {
                throw new \Exception('Not Found');
            }
            return new JsonResponse($tracked_job->toArray());

        } catch (\Exception $e) {
            $source = 0x8737adf2;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }

    /**
     * @param $version
     * @param Request $request
     * @return mixed
     * @throws ODRException
     * @throws ODRForbiddenException
     * @throws ODRNotFoundException
     */
    public function completedJobsAction($version, $job_type, $count, Request $request)
    {
        try {
            // Check if user exists & throw user not found error
            $user_email = null;

            /** @var ODRUser $user */  // Anon when nobody is logged in.
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ($user->hasRole('ROLE_SUPER_ADMIN') && $user_email !== null) {
                // Save which user started this creation process
                $user_manager = $this->container->get('fos_user.user_manager');
                /** @var ODRUser $user */
                $user = $user_manager->findUserBy(array('email' => $user_email));
                if (is_null($user))
                    throw new ODRNotFoundException('unrecognized email: "' . $user_email . '"');
            }

            // Check if template is valid
            /** @var EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            // Get the Job
            $repo_job = $em->getRepository('ODRAdminBundle:TrackedJob');
            /** @var TrackedJob[] $tracked_jobs */
            $query = $em->createQuery(
                'SELECT tj 
                    FROM ODRAdminBundle:TrackedJob tj
                    WHERE tj.createdBy = :createdBy
                    AND tj.job_type like :job_type
                    AND tj.completed IS NOT NULL
                    ORDER BY tj.completed DESC'
                )
                ->setMaxResults($count)
                ->setParameters( array(
                    'createdBy' => $user->getId(),
                    'job_type' => $job_type,
                )
            );
            // print $query->getSQL();exit();
            $tracked_jobs = $query->getArrayResult();

            $output_array = [];
            $output_array['jobs'] = [];
            $output_array['jobs'] = $tracked_jobs;
            return new JsonResponse($output_array);

        } catch (\Exception $e) {
            $source = 0x8737adf2;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }
    /**
     * @param $version
     * @param Request $request
     * @return mixed
     * @throws ODRException
     * @throws ODRForbiddenException
     * @throws ODRNotFoundException
     */
    public function jobStatusAction($version, $job_id, $full, Request $request)
    {

        try {
            // Check if user exists & throw user not found error
            $user_email = null;

            /** @var ODRUser $user */  // Anon when nobody is logged in.
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ($user->hasRole('ROLE_SUPER_ADMIN') && $user_email !== null) {
                // Save which user started this creation process
                $user_manager = $this->container->get('fos_user.user_manager');
                /** @var ODRUser $user */
                $user = $user_manager->findUserBy(array('email' => $user_email));
                if (is_null($user))
                    throw new ODRNotFoundException('unrecognized email: "' . $user_email . '"');
            }

            // Check if template is valid
            /** @var EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            // Get the Job
            $repo_job = $em->getRepository('ODRAdminBundle:TrackedJob');
            /** @var TrackedJob $tracked_job */
            $tracked_job = $repo_job->findOneBy([
                'id' => $job_id,
                'createdBy' => $user->getId()
            ]);

            if($tracked_job && $full > 0) {
                // Update counts from TrackedCSVExport
                // Need to also load all the TrackedCSVExport entries of this job...
                /** @var TrackedCSVExport[] $tracked_csv_exports */
                $tracked_csv_exports = $em
                    ->getRepository('ODRAdminBundle:TrackedCSVExport')
                    ->findBy(
                    array('trackedJob' => $tracked_job->getId())
                );

                $tracked_job->setCurrent(count($tracked_csv_exports));

                $em->persist($tracked_job);
                $em->flush();
                $em->refresh($tracked_job);
            }
            return new JsonResponse($tracked_job->toArray());

        } catch (\Exception $e) {
            $source = 0xdafe9892;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }

    public function workerJobAction($version, Request $request)
    {

        try {
            $POST = json_decode(file_get_contents('php://input'), true);

            // Only used if SuperAdmin & Present
            $user_email = null;
            if (isset($POST['user_email']))
                $user_email = $POST['user_email'];

            // Check if user exists & throw user not found error
            /** @var ODRUser $user */  // Anon when nobody is logged in.
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ($user->hasRole('ROLE_SUPER_ADMIN') && $user_email !== null) {
                // Save which user started this creation process
                $user_manager = $this->container->get('fos_user.user_manager');
                /** @var ODRUser $user */
                $user = $user_manager->findUserBy(array('email' => $user_email));
                if (is_null($user))
                    throw new ODRNotFoundException('unrecognized email: "' . $user_email . '"');
            }

            // Check if template is valid
            /** @var EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            // /** @var PermissionsManagementService $pm_service */
            // $pm_service = $this->container->get('odr.permissions_management_service');

            // Check if user can add a tracked job
            // if (!$pm_service->canAddDatarecord($user, $dataset_datatype))
            // throw new ODRForbiddenException();

            $job = $POST['job'];

            /*
             {
                "user_email": "nate@opendatarepository.org",
                "job": {
                    "tracked_job_id": 1866,
                    "random_key": "random_key_1866",
                    "job_order": 0,
                    "line_count": 1
                }
            }
            */
            $repo_job = $em->getRepository('ODRAdminBundle:TrackedJob');
            /** @var TrackedJob $tracked_job */
            $tracked_job = $repo_job->findOneBy(['id' => $job['tracked_job_id']]);

            $worker_job = new TrackedCSVExport();
            $worker_job->setTrackedJob($tracked_job);
            $worker_job->setJobOrder($job['job_order']);
            $worker_job->setRandomKey($job['random_key']);
            $worker_job->setLineCount($job['line_count']);
            $em->persist($worker_job);
            $em->flush();
            $em->refresh($worker_job);

            return new JsonResponse($worker_job->toArray());

        } catch (\Exception $e) {
            $source = 0x828fed9f;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }

    /**
     * @param $version
     * @param Request $request
     * @return mixed
     * @throws ODRException
     * @throws ODRForbiddenException
     * @throws ODRNotFoundException
     */
    public function updateJobAction($version, Request $request)
    {

        try {
            // Only used if SuperAdmin & Present
            $_PUT = json_decode(file_get_contents('php://input'), true);

            $user_email = null;
            if (isset($_PUT['user_email']))
                $user_email = $_PUT['user_email'];

            // Check if user exists & throw user not found error
            /** @var ODRUser $user */  // Anon when nobody is logged in.
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ($user->hasRole('ROLE_SUPER_ADMIN') && $user_email !== null) {
                // Save which user started this creation process
                $user_manager = $this->container->get('fos_user.user_manager');
                /** @var ODRUser $user */
                $user = $user_manager->findUserBy(array('email' => $user_email));
                if (is_null($user))
                    throw new ODRNotFoundException('unrecognized email: "' . $user_email . '"');
            }

            // Check if template is valid
            /** @var EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            // /** @var PermissionsManagementService $pm_service */
            // $pm_service = $this->container->get('odr.permissions_management_service');

            // Check if user can add a tracked job
            // if (!$pm_service->canAddDatarecord($user, $dataset_datatype))
            // throw new ODRForbiddenException();

            // $job = json_decode($_PUT['job'], true);
            $job = $_PUT['job'];

            /*
            {
                "id": 1850,
                "job_type": "ima_update",
                "total": 755,
                "current": 0,
                "completed": null,
                "started": {
                    "date": "2024-07-26 21:33:33.000000",
                    "timezone_type": 3,
                    "timezone": "UTC"
                },
                "viewed": null,
                "additional_data": "none"
            }
            */

            $repo_job = $em->getRepository('ODRAdminBundle:TrackedJob');
            /** @var TrackedJob $tracked_job */
            $tracked_job = $repo_job->findOneBy(['id' => $job['id']]);
            if($tracked_job) {
                $tracked_job->setJobType($job['job_type']);
                $tracked_job->setTargetEntity($job['target_entity']);
                $tracked_job->setCurrent($job['current']);
                $tracked_job->setTotal($job['total']);
                if($job['viewed'] != null)
                    $tracked_job->setViewed($job['viewed']);
                if($job['completed'] != null)
                    $tracked_job->setCompleted(new \DateTime());

                $tracked_job->setAdditionalData($job['additional_data']);
            }
            else {
                throw new ODRJsonException('Not Found');
            }

            $em->persist($tracked_job);
            $em->flush();
            $em->refresh($tracked_job);

            return new JsonResponse($tracked_job->toArray());

        } catch (\Exception $e) {
            $source = 0x828fed9f;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }
}
