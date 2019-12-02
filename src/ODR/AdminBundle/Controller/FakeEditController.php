<?php

/**
 * Open Data Repository Data Publisher
 * Fake Edit Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This controller handles creating and saving "fake" datarecords...though technically they're more
 * "ephemeral" than "fake", since they don't exist in the database until savefakerecordAction()
 * is called.
 *
 */

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\RadioOptions;
use ODR\AdminBundle\Entity\Tags;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRConflictException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\AdminBundle\Component\Service\DatatypeInfoService;
use ODR\AdminBundle\Component\Service\EntityCreationService;
use ODR\AdminBundle\Component\Service\ODRRenderService;
use ODR\AdminBundle\Component\Service\ODRTabHelperService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Utility\ValidUtility;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchCacheService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchService;
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfTokenManager;


class FakeEditController extends ODRCustomController
{

    /**
     * Renders HTML for a "fake" datarecord...one without a database id.  Handling a "fake" record
     * is more complicated than one that isn't...but users keep managing to forget about records
     * created through EditController::adddatarecordAction(), which leads to an increasing number
     * of entirely blank records in databases...
     *
     * @param int $datatype_id
     * @param Request $request
     *
     * @return Response
     */
    public function fakerecordAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get Entity Manager and setup repo
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ODRRenderService $odr_render_service */
            $odr_render_service = $this->container->get('odr.render_service');
            /** @var ODRTabHelperService $odr_tab_service */
            $odr_tab_service = $this->container->get('odr.tab_helper_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ( $datatype == null )
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if ( !$pm_service->canAddDatarecord($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------

            // Grab the tab's id, if it exists
            $params = $request->query->all();
            $odr_tab_id = '';
            if ( isset($params['odr_tab_id']) )
                $odr_tab_id = $params['odr_tab_id'];
            else
                $odr_tab_id = $odr_tab_service->createTabId();

            // Render and return the html for a "fake" datarecord
            $page_html = $odr_render_service->getFakeEditHTML($user, $datatype);

            // The "fake" datarecord still needs a header
            $templating = $this->get('templating');
            $header_html = $templating->render(
                'ODRAdminBundle:Edit:fake_edit_header.html.twig',
                array(
                    'datatype' => $datatype,
                    'odr_tab_id' => $odr_tab_id,
                )
            );

            $return['d'] = array(
                'datatype_id' => $datatype->getId(),
                'html' => $header_html.$page_html,
            );

        }
        catch (\Exception $e) {
            $source = 0x4e2a6c9d;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Takes an array of datafields, their values, and associated tokens...and then creates a new
     * datarecord with those values, assuming that they're all valid.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function savefakerecordAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            $post = $request->request->all();
//print_r($post);  exit();

            if ( !isset($post['datatype_id'])
                || !isset($post['datarecord_id'])
                || !isset($post['datafields'])
                || !isset($post['tokens'])
            ) {
                throw new ODRBadRequestException();
            }

            // TODO - parent/grandparent datarecord ids so this works for child records?
            $datatype_id = $post['datatype_id'];
            $tmp_dr_id = $post['datarecord_id'];
            $datafields = $post['datafields'];
            $csrf_tokens = $post['tokens'];

            if ( !is_numeric($datatype_id)
                || !is_array($datafields)
                || !is_array($csrf_tokens)
            ) {
                throw new ODRBadRequestException();
            }


            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var EntityCreationService $ec_service */
            $ec_service = $this->container->get('odr.entity_creation_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchCacheService $search_cache_service */
            $search_cache_service = $this->container->get('odr.search_cache_service');
            /** @var CsrfTokenManager $token_manager */
            $token_manager = $this->container->get('security.csrf.token_manager');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if ( !$pm_service->canAddDatarecord($user, $datatype) )
                throw new ODRForbiddenException();
            if ( !$pm_service->canEditDatatype($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------

            // Verify that the datafields and tokens make sense
            $datatype_array = $dti_service->getDatatypeArray($datatype->getGrandparent()->getId(), false);    // don't need links
            $found_datafields = array();

            // Easier on the database to get the cache entry
            foreach ($datatype_array[$datatype->getId()]['dataFields'] as $df_id => $df) {
                // Verify that a fields marked as unique has a value
                if ( $df['dataFieldMeta']['is_unique'] === true ) {
                    if ( !isset($datafields[$df_id]) )
                        throw new ODRBadRequestException();
                }

                // Otherwise, only care about the field if it has a value in it...
                if ( isset($datafields[$df_id]) ) {
                    $found_datafields[$df_id] = 1;

                    $typeclass = $df['dataFieldMeta']['fieldType']['typeClass'];
                    $typename = $df['dataFieldMeta']['fieldType']['typeName'];
                    $token_id = $typeclass . 'Form_' . $tmp_dr_id . '_' . $df_id;
                    $value = $datafields[$df_id];

                    // Verify that the CSRF token for this field was submitted with the form...
                    if ( !isset($csrf_tokens[$df_id]) )
                        throw new ODRBadRequestException();

                    // ...and that it's valid
                    $check_token = $token_manager->getToken($token_id)->getValue();
                    if ( $csrf_tokens[$df_id] !== $check_token )
                        throw new ODRBadRequestException('Invalid CSRF Token');


                    // Verify that the typeclass and the value make sense
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
                            if ( !self::isValidValue($typeclass, $value) )
                                throw new ODRBadRequestException('Invalid value');
                            break;

                        // Radio options need a different validation
                        case 'Radio':
                            if ( !self::areValidRadioOptions($typename, $df, $value) )
                                throw new ODRBadRequestException('Invalid value');
                            break;

                        // Tags also need a different validation
                        case 'Tag':
                            if ( !self::areValidTags($df, $value) )
                                throw new ODRBadRequestException('Invalid value');
                            break;

                        // The rest of the typeclasses aren't valid
                        case 'File':
                        case 'Image':
                        case 'Markdown':
                        default:
                            throw new ODRBadRequestException('Invalid typeclass');
                    }
                }
            }

            // Verify that all the listed datafields belong to the datatype
            foreach ($datafields as $df_id => $val) {
                if ( !isset($found_datafields[$df_id]) )
                    throw new ODRBadRequestException('Invalid Datafield');
            }


            // ----------------------------------------
            // Load datafield entities to prepare for entity creation, and to perform final
            //  permission checks
            $repo_datafields = $em->getRepository('ODRAdminBundle:DataFields');
            $repo_radio_options = $em->getRepository('ODRAdminBundle:RadioOptions');
            $repo_tags = $em->getRepository('ODRAdminBundle:Tags');

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
                if ( !$pm_service->canEditDatafield($user, $df) )
                    throw new ODRForbiddenException();
            }


            // ----------------------------------------
            // If any of the fields are unique, then need to verify that a non-unique value isn't
            //  going to get saved
            foreach ($datafields as $df_id => $value) {
                $df = $df_mapping[$df_id];
                if ( $df->getIsUnique() ) {
                    if ( self::valueAlreadyExists($df, $value) )
                        throw new ODRConflictException('A Datarecord already has the value "'.$value.'" stored in the "'.$df->getFieldName().'" Datafield.');
                }
            }


            // ----------------------------------------
            // Now that all the post data makes sense, time to create some entities
            $new_datarecord = $ec_service->createDatarecord($user, $datatype);    // creation of storage entities makes delaying flush here pointless
            $new_datarecord->setProvisioned(false);
            $em->persist($new_datarecord);

            foreach ($datafields as $df_id => $value) {
                $df = $df_mapping[$df_id];
                $typeclass = $df->getFieldType()->getTypeClass();

                if ( $typeclass === 'Radio' ) {
                    foreach ($value as $ro_id => $num) {
                        /** @var RadioOptions $ro */
                        $ro = $repo_radio_options->find($ro_id);    // this should already exist

                        // Create the drf entry...
                        $drf = $ec_service->createDatarecordField($user, $new_datarecord, $df);
                        // ...then create the radio selection
                        $radio_selection = $ec_service->createRadioSelection($user, $ro, $drf);

                        // These are unselected when created, so change that
                        $radio_selection->setSelected(1);
                        $em->persist($radio_selection);    // don't flush immediately
                    }
                }
                else if ( $typeclass === 'Tag' ) {
                    foreach ($value as $tag_id => $num) {
                        /** @var Tags $tag */
                        $tag = $repo_tags->find($tag_id);    // this should already exist

                        // Create the drf entry...
                        $drf = $ec_service->createDatarecordField($user, $new_datarecord, $df);
                        // ...then create the tag selection
                        $tag_selection = $ec_service->createTagSelection($user, $tag, $drf);

                        // New tags are unselected by default
                        $tag_selection->setSelected(1);
                        $em->persist($tag_selection);    // don't flush immediately...
                    }
                }
                else {
                    // All other fieldtypes
                    $ec_service->createStorageEntity($user, $new_datarecord, $df, $value);
                }
            }

            // Ensure everything is flushed
            $em->flush();


            // ----------------------------------------
            // Since the datarecord is brand new, don't need to delete its cache entry

            // Delete the cached string containing the ordered list of datarecords for this datatype
            $dti_service->resetDatatypeSortOrder($datatype->getId());
            // Delete all search results that can change
            $search_cache_service->onDatarecordCreate($datatype);

            // Everything created, return the id of the new datarecord
            $return['d'] = array(
                'new_datarecord_id' => $new_datarecord->getId()
            );

        }
        catch (\Exception $e) {
            $source = 0x709c2e94;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Returns whether the given value is valid for the given typeclass.  Meant to bypass having
     * to build a pile of Symfony Form objects for saveasnewAction(), since all of the given values
     * need to be valid prior to saving.
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
     * Returns whether the collection of options is valid for the given datafield.
     *
     * @param string $typename
     * @param array $df_array
     * @param array $options
     *
     * @return bool
     */
    private function areValidRadioOptions($typename, $df_array, $options)
    {
        // Single select/radio are allowed to have at most one selection
        if ($typename === 'Single Select' || $typename === 'Single Radio') {
            if ( count($options) > 1 )
                return false;
        }

        // Convert the available options into a different format to make them easier to search
        $available_options = array();
        foreach ($df_array['radioOptions'] as $num => $ro)
            $available_options[ $ro['id'] ] = 0;

        foreach ($options as $ro_id => $num) {
            // The option has to belong to the datafield for it to be valid
            if ( !isset($available_options[$ro_id]) )
                return false;
        }

        // Otherwise, no errors
        return true;
    }


    /**
     * Returns whether the collection of tags is valid for the given datafield.
     *
     * @param array $df_array
     * @param array $tags
     *
     * @return bool
     */
    private function areValidTags($df_array, $tags)
    {
        // Tags allow any number of selections by default

        // Unfortunately the tags are stored in stacked format, so need to flatten them
        $available_tags = array();
        self::getAvailableTags($df_array['tags'], $available_tags);

        foreach ($tags as $tag_id => $num) {
            // The tag has to belong to the datafield for it to be valid
            if ( !isset($available_tags[$tag_id]) )
                return false;
        }

        // Otherwise, no errors
        return true;
    }


    /**
     * Flattens a stacked tag hierarchy, leaving only leaf tag ids in $available_tags
     *
     * @param array $tag_array
     * @param array $available_tags
     */
    private function getAvailableTags($tag_array, &$available_tags)
    {
        foreach ($tag_array as $tag_id => $tag) {
            if ( !isset($tag['children']) )
                $available_tags[$tag_id] = 0;
            else
                self::getAvailableTags($tag['children'], $available_tags);
        }
    }


    /**
     * Returns whether the given value already exists in the given datafield.
     *
     * Changes made here should also be made in EditController::valueAlreadyExists()
     *
     * @param DataFields $datafield
     * @param string $value
     *
     * @return bool
     */
    private function valueAlreadyExists($datafield, $value)
    {
        // Want to perform an exact search for this value
        // This is allowed because currently only text and number fields are allowed to be unique
        $value = '"'.$value.'"';

        /** @var SearchService $search_service */
        $search_service = $this->container->get('odr.search_service');
        $search_results = $search_service->searchTextOrNumberDatafield($datafield, $value);

        // If the search returned anything, then the value already exists
        if ( count($search_results['records']) > 0 )
            return true;
        else
            return false;
    }


    /**
     * Checks whether the given value for the given datafield is unique or not...it's easier for the
     * javascript to throw up warnings about uniqueness conflicts when it only has to check a
     * single datafield at a time.
     *
     * @param int $datafield_id
     * @param Request $request
     *
     * @return Response
     */
    public function checkfakerecordfielduniqueAction($datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            $post = $request->request->all();
//print_r($post);  exit();

            // Should only be one typeclass in here...
            if ( count($post) !== 1 )
                throw new ODRBadRequestException();

            // Don't know exactly which typeclass this'll be...
            $value = '';
            foreach ($post as $typeclass => $form_data) {
                // ...but it should have these two keys in the array
                if ( !isset($form_data['_token']) || !isset($form_data['value']) )
                    throw new ODRBadRequestException();

                $value = trim($form_data['value']);
            }


            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if ( !$pm_service->canAddDatarecord($user, $datatype) )
                throw new ODRForbiddenException();
            if ( !$pm_service->canEditDatatype($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // Datafield needs to be unique for this to make sense
            if ( !$datafield->getIsUnique() )
                throw new ODRBadRequestException();

            if ( self::valueAlreadyExists($datafield, $value) )
                throw new ODRConflictException('A Datarecord already has the value "'.$value.'" stored in the "'.$datafield->getFieldName().'" Datafield.');

        }
        catch (\Exception $e) {
            $source = 0xfd53e056;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }
}
