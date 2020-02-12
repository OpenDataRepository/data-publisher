<?php

/**
 * Open Data Repository Data Publisher
 * Fake Record Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This service stores the code to verify and save "fake" records...records that are being rendered
 * but don't have an actual entry in the database yet.  This currently is useful both for creating
 * new top-level records, and for entering metadata during datatype creation.
 */

namespace ODR\AdminBundle\Component\Service;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\RadioOptions;
use ODR\AdminBundle\Entity\Tags;
use ODR\AdminBundle\Component\Utility\ValidUtility;
use ODR\AdminBundle\Exception\ODRException;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRConflictException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\OpenRepository\SearchBundle\Component\Service\SearchCacheService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchService;
// Other
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\Security\Csrf\CsrfTokenManager;


class FakeRecordService
{

    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var DatatypeInfoService
     */
    private $dti_service;

    /**
     * @var EntityCreationService
     */
    private $ec_service;

    /**
     * @var PermissionsManagementService
     */
    private $pm_service;

    /**
     * @var SearchService
     */
    private $search_service;

    /**
     * @var SearchCacheService
     */
    private $search_cache_service;

    /**
     * @var SortService
     */
    private $sort_service;

    /**
     * @var CsrfTokenManager
     */
    private $token_manager;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * FakeRecordService constructor.
     *
     * @param EntityManager $entity_manager
     * @param DatatypeInfoService $datatype_info_service
     * @param EntityCreationService $entity_creation_service
     * @param PermissionsManagementService $permissions_service
     * @param SearchService $search_service
     * @param SearchCacheService $search_cache_service
     * @param SortService $sort_service
     * @param CsrfTokenManager $token_manager
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        DatatypeInfoService $datatype_info_service,
        EntityCreationService $entity_creation_service,
        PermissionsManagementService $permissions_service,
        SearchService $search_service,
        SearchCacheService $search_cache_service,
        CsrfTokenManager $token_manager,
        SortService $sort_service,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->dti_service = $datatype_info_service;
        $this->ec_service = $entity_creation_service;
        $this->pm_service = $permissions_service;
        $this->search_service = $search_service;
        $this->search_cache_service = $search_cache_service;
        $this->sort_service = $sort_service;
        $this->token_manager = $token_manager;
        $this->logger = $logger;
    }


    /**
     * Takes the given post data and attempts to validate it as a record of the given datatype.  Any
     * problems encountered result in an error being thrown.
     *
     * Because this needs to work both for fake top-level records and validation of metadata records
     * during database creation, this function doesn't actually create the new record.
     *
     * @param array $post
     * @param DataType $datatype
     * @param ODRUser $user
     *
     * @throws ODRException
     */
    public function verifyFakeRecord($post, $datatype, $user)
    {
        try {
            // ----------------------------------------
            if ( !isset($post['datarecord_id'])
//                || !isset($post['datafields'])    // datafields won't exist if no fields are required and user doesn't enter anything
                || !isset($post['tokens'])
            ) {
                throw new ODRBadRequestException();
            }

            // TODO - include parent/grandparent datarecord ids so this works for child records?
//            $datatype_id = $post['datatype_id'];
            $tmp_dr_id = $post['datarecord_id'];
            $csrf_tokens = $post['tokens'];

            $datafields = array();
            if ( isset($post['datafields']) )
                $datafields = $post['datafields'];


            // ----------------------------------------
            // Verify that the datafields and tokens make sense
            $datatype_array = $this->dti_service->getDatatypeArray($datatype->getGrandparent()->getId(), false);    // don't need links
            $found_datafields = array();

            // Easier on the database to get the cache entry
            foreach ($datatype_array[$datatype->getId()]['dataFields'] as $df_id => $df) {
                $df_name = $df['dataFieldMeta']['fieldName'];
                $typeclass = $df['dataFieldMeta']['fieldType']['typeClass'];

                // Don't check markdown-type fields
                if ( $typeclass === 'Markdown' )
                    continue;

                // Verify that a field marked as 'unique' or 'required' has a value
                if ($df['dataFieldMeta']['is_unique'] === true || $df['dataFieldMeta']['required'] === true) {
                    if ( !isset($datafields[$df_id]) ) {
                        if ( $typeclass == 'Radio' || $typeclass == 'Tag' )
                            throw new ODRBadRequestException('The Datafield "'.$df_name.'" must have a selection');
                        else
                            throw new ODRBadRequestException('The Datafield "'.$df_name.'" must have a value');
                    }
                }


                // Verify that the CSRF token for this field was submitted with the form regardless
                //  of whether the field has a value or not...
                if ( !isset($csrf_tokens[$df_id]) )
                    throw new ODRBadRequestException('Missing CSRF Token');

                // ...and that the CSRF token is valid
                $token_id = $typeclass.'Form_'.$tmp_dr_id.'_'.$df_id;
                $check_token = $this->token_manager->getToken($token_id)->getValue();
                if ($csrf_tokens[$df_id] !== $check_token)
                    throw new ODRBadRequestException('Invalid CSRF Token');


                // Otherwise, only care about the field if it has a value in it...
                if ( isset($datafields[$df_id]) ) {
                    $found_datafields[$df_id] = 1;
                    $value = $datafields[$df_id];

                    // Verify that the value submitted for this datafield makes sense
                    switch ($typeclass) {
                        // These are legitimate typeclasses
                        case 'Boolean':
                        case 'IntegerValue':
                        case 'DecimalValue':
                        case 'LongText':    // paragraph text
                        case 'LongVarchar':
                        case 'MediumVarchar':
                        case 'ShortVarchar':
                        case 'DatetimeValue':
                            if (!self::isValidValue($typeclass, $value))
                                throw new ODRBadRequestException('The Datafield "'.$df_name.'" has an invalid value');
                            break;

                        // Radio options need a different validation
                        case 'Radio':
                            if (!ValidUtility::areValidRadioOptions($df, $value))
                                throw new ODRBadRequestException('The Datafield "'.$df_name.'" has an invalid value');
                            break;

                        // Tags also need a different validation
                        case 'Tag':
                            if (!ValidUtility::areValidTags($df, $value))
                                throw new ODRBadRequestException('The Datafield "'.$df_name.'" has an invalid value');
                            break;

                        // The rest of the typeclasses aren't valid
                        case 'File':
                        case 'Image':
                        case 'Markdown':
                        default:
                            throw new ODRBadRequestException('The Datafield "'.$df_name.'" should not have a value');
                    }
                }
            }

            // Verify that all the datafields with values belong to the datatype
            foreach ($datafields as $df_id => $val) {
                if ( !isset($found_datafields[$df_id]) )
                    throw new ODRBadRequestException('Invalid Datafield');
            }


            // ----------------------------------------
            // Load datafield entities to prepare for entity creation, and to perform final
            //  permission checks
            $repo_datafields = $this->em->getRepository('ODRAdminBundle:DataFields');

            $df_mapping = array();
            foreach ($datafields as $df_id => $val) {
                /** @var DataFields $df */
                $df = $repo_datafields->find($df_id);
                if ($df == null)
                    throw new ODRNotFoundException('Datafield');

                $df_mapping[$df->getId()] = $df;
            }
            /** @var DataFields[] $df_mapping */

            // Also ensure the user can edit all of these fields before continuing
            foreach ($df_mapping as $df_id => $df) {
                if ( !$this->pm_service->canEditDatafield($user, $df) )
                    throw new ODRForbiddenException();
            }


            // ----------------------------------------
            // If any of the fields are unique, then need to verify that a non-unique value isn't
            //  going to get saved
            foreach ($datafields as $df_id => $value) {
                $df = $df_mapping[$df_id];
                if ( $df->getIsUnique() ) {
                    if ( $this->search_service->valueAlreadyExists($df, $value) )
                        throw new ODRConflictException('A Datarecord already has the value "'.$value.'" stored in the "'.$df->getFieldName().'" Datafield.');
                }
            }


            // ----------------------------------------
            // Now that all the post data makes sense, time to create some entities

        }
        catch (\Exception $e) {
            $source = 0xe9a75c09;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Returns whether the given value is valid for the given typeclass.  Meant to bypass having
     * to build a pile of Symfony Form objects for self::verifyFakeRecord(), since all of the given
     * values need to be valid prior to saving.
     *
     * Radio options and Tags need different parameters, so they're not validated here.
     *
     * @param string $typeclass
     * @param string $value
     *
     * @return bool
     */
    private function isValidValue($typeclass, $value)
    {
        switch ($typeclass) {
            // These are legitimate typeclasses
            case 'Boolean':
                return ValidUtility::isValidBoolean($value);
            case 'IntegerValue':
                return ValidUtility::isValidInteger($value);
            case 'DecimalValue':
                return ValidUtility::isValidDecimal($value);
            case 'LongText':    // paragraph text, can accept any value
                break;
            case 'LongVarchar':
                return ValidUtility::isValidLongVarchar($value);
            case 'MediumVarchar':
                return ValidUtility::isValidMediumVarchar($value);
            case 'ShortVarchar':
                return ValidUtility::isValidShortVarchar($value);
            case 'DatetimeValue':
                return ValidUtility::isValidDatetime($value);

            default:
                return false;
        }

        // Otherwise, no problem
        return true;
    }


    /**
     * Takes the given post data and commits it as a new record of the given datatype.  This function
     * assumes that the post data has already been validated by self::verifyFakeRecord()
     *
     * @param array $post
     * @param DataType $datatype
     * @param ODRUser $user
     *
     * @return DataRecord
     */
    public function commitFakeRecord($post, $datatype, $user)
    {
        try {
            // ----------------------------------------
//                || !isset($post['datafields'])    // datafields won't exist if no fields are required and user doesn't enter anything
//                throw new ODRBadRequestException();

            $datafields = array();
            if ( isset($post['datafields']) )
                $datafields = $post['datafields'];


            // ----------------------------------------
            // Going to need these repositories
            $repo_datafields = $this->em->getRepository('ODRAdminBundle:DataFields');
            $repo_radio_options = $this->em->getRepository('ODRAdminBundle:RadioOptions');
            $repo_tags = $this->em->getRepository('ODRAdminBundle:Tags');

            $new_datarecord = $this->ec_service->createDatarecord($user, $datatype);    // creation of storage entities makes delaying flush here pointless
            $new_datarecord->setProvisioned(false);
            $this->em->persist($new_datarecord);

            foreach ($datafields as $df_id => $value) {
                /** @var DataFields $df */
                $df = $repo_datafields->find($df_id);  // this should already exist
                $typeclass = $df->getFieldType()->getTypeClass();

                if ($typeclass === 'Radio') {
                    foreach ($value as $ro_id => $num) {
                        /** @var RadioOptions $ro */
                        $ro = $repo_radio_options->find($ro_id);    // this should already exist

                        // Create the drf entry...
                        $drf = $this->ec_service->createDatarecordField($user, $new_datarecord, $df);
                        // ...then create the radio selection
                        $radio_selection = $this->ec_service->createRadioSelection($user, $ro, $drf);

                        // These are unselected when created, so change that
                        $radio_selection->setSelected(1);
                        $this->em->persist($radio_selection);    // don't flush immediately
                    }
                }
                else if ($typeclass === 'Tag') {
                    foreach ($value as $tag_id => $num) {
                        /** @var Tags $tag */
                        $tag = $repo_tags->find($tag_id);    // this should already exist

                        // Create the drf entry...
                        $drf = $this->ec_service->createDatarecordField($user, $new_datarecord, $df);
                        // ...then create the tag selection
                        $tag_selection = $this->ec_service->createTagSelection($user, $tag, $drf);

                        // New tags are unselected by default
                        $tag_selection->setSelected(1);
                        $this->em->persist($tag_selection);    // don't flush immediately...
                    }
                }
                else {
                    // All other fieldtypes
                    $this->ec_service->createStorageEntity($user, $new_datarecord, $df, $value);
                }
            }

            // Ensure everything is flushed
            $this->em->flush();


            // ----------------------------------------
            // Since the datarecord is brand new, don't need to delete its cache entry

            // Since a record got created, the sort order for this datatype's records will have changed
            $this->sort_service->resetDatatypeSortOrder($datatype);
            // Delete all search results that can change
            $this->search_cache_service->onDatarecordCreate($datatype);

            // Return the new datarecord
            return $new_datarecord;
        }
        catch (\Exception $e) {
            $source = 0x258519cb;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }
}
