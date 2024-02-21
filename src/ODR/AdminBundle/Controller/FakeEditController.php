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

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\RadioOptions;
use ODR\AdminBundle\Entity\Tags;
use ODR\AdminBundle\Entity\ThemeDataType;
use ODR\AdminBundle\Entity\ThemeElement;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Events
use ODR\AdminBundle\Component\Event\DatafieldModifiedEvent;
use ODR\AdminBundle\Component\Event\DatarecordCreatedEvent;
use ODR\AdminBundle\Component\Event\DatarecordModifiedEvent;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRConflictException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\AdminBundle\Component\Service\EntityCreationService;
use ODR\AdminBundle\Component\Service\ODRRenderService;
use ODR\AdminBundle\Component\Service\ODRTabHelperService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\SortService;
use ODR\AdminBundle\Component\Utility\ValidUtility;
use ODR\OpenRepository\GraphBundle\Plugins\DatafieldDerivationInterface;
// Symfony
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Templating\EngineInterface;


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
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ( $datatype == null )
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if ( !$permissions_service->canAddDatarecord($user, $datatype) )
                throw new ODRForbiddenException();
            // TODO - ...shouldn't this also require the user to be able to edit at least one datafield?  doesn't really make sense otherwise...
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
            $header_html = $templating->render(
                'ODRAdminBundle:FakeEdit:fake_edit_header.html.twig',
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

            // Ensure the post data is valid...
            if ( !isset($post['datatype_id']) || !isset($post['datarecord_id']) )
                throw new ODRBadRequestException();

            $datatype_id = $post['datatype_id'];
            if ( !is_numeric($datatype_id) )
                throw new ODRBadRequestException();

            // TODO - parent/grandparent datarecord ids so this works for child records?
            $tmp_dr_id = $post['datarecord_id'];

            $datafields = array();
            if ( isset($post['datafields']) )
                $datafields = $post['datafields'];

            $csrf_tokens = array();
            if ( isset($post['tokens']) )
                $csrf_tokens = $post['tokens'];


            // Special tokens probably won't exist...
            $special_tokens = array();
            if ( isset($post['special_tokens']) )
                $special_tokens = $post['special_tokens'];


            // Submission of a fake top-level may need to be handled differently than a submission
            //  via the inline link system...
            $inline_link = false;
            if ( isset($post['inline_link']) )
                $inline_link = true;


            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
            //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
            /** @var EventDispatcherInterface $event_dispatcher */
            $dispatcher = $this->get('event_dispatcher');

            /** @var DatabaseInfoService $database_info_service */
            $database_info_service = $this->container->get('odr.database_info_service');
            /** @var EntityCreationService $entity_create_service */
            $entity_create_service = $this->container->get('odr.entity_creation_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var SortService $sort_service */
            $sort_service = $this->container->get('odr.sort_service');
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

            if ( !$permissions_service->canAddDatarecord($user, $datatype) )
                throw new ODRForbiddenException();
            if ( !$permissions_service->canEditDatatype($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------

            // Need to verify that the datafields and tokens make sense
            $datatype_array = $database_info_service->getDatatypeArray($datatype->getGrandparent()->getId(), false);    // don't need links
            $found_datafields = array();

            // Easier to locate any datafields that are going to receive autogenerated values here
            $autogenerated_datafields = self::findAutogeneratedDatafields($datatype_array);
            // Same theory for any derived datafields
            $derived_datafields = self::findDerivedDatafields($datatype_array);

            // Easier on the database to use the cache entry
            foreach ($datatype_array[$datatype->getId()]['dataFields'] as $df_id => $df) {
                // Verify that a fields marked as unique has a value...
                $datafield_name = $df['dataFieldMeta']['fieldName'];
                if ( $df['dataFieldMeta']['is_unique'] === true ) {
                    if ( isset($derived_datafields[$df_id]) ) {
                        // If the datafield is supposed to be derived, then require all of its
                        //  source datafields to have values or be autogenerated
                        $source_fields_have_values = true;
                        foreach ($derived_datafields[$df_id] as $source_df_id) {
                            if ( !isset($datafields[$source_df_id]) && !isset($autogenerated_datafields[$source_df_id]) )
                                $source_fields_have_values = false;
                        }
                        if ( !$source_fields_have_values )
                            throw new ODRBadRequestException('The source Datafields for the Datafield "'.$datafield_name.'" must have values');
                    }
                    else {
                        // Complain if the datafield wasn't submitted with a value and it's not going
                        //  to be autogenerated
                        if ( !isset($datafields[$df_id]) && !isset($autogenerated_datafields[$df_id]) )
                            throw new ODRBadRequestException('The Datafield "'.$datafield_name.'" must have a value');
                    }
                }

                // If the datafield is marked as "no user edits"...
                if ( $df['dataFieldMeta']['prevent_user_edits'] === true ) {
                    // ...then it won't have a token because the field got rendered in Display mode
                    // However, render plugins can provide a special token for the field...
                    if ( isset($special_tokens[$df_id]) ) {
                        // ...and if they did, then check whether it's legitimate
                        $token_id = 'FakeEdit_'.$tmp_dr_id.'_'.$df_id.'_autogenerated';
                        $check_token = $token_manager->getToken($token_id)->getValue();
                        if ( $special_tokens[$df_id] !== $check_token )
                            throw new ODRBadRequestException('Invalid CSRF Token');

                        // No exception thrown, so the datafield's value will be set during whichever
                        //  render plugin will handle the DatarecordCreated event later on
                    }
                    else {
                        // Otherwise, it doesn't have a special token, so silently ensure that the
                        //  field hasn't been given a value when it's not supposed to be editable
                        //  by users
                        if ( isset($datafields[$df_id]) )
                            unset( $datafields[$df_id] );
                        if ( isset($csrf_tokens[$df_id]) )
                            unset( $csrf_tokens[$df_id] );
                    }
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
                        throw new ODRBadRequestException('Invalid CSRF Token');

                    // ...and that it's valid
                    $check_token = $token_manager->getToken($token_id)->getValue();
                    if ( $csrf_tokens[$df_id] !== $check_token )
                        throw new ODRBadRequestException('Invalid CSRF Token');


                    // The submitted value should only be verified if the datafield isn't marked
                    //  as having its value autogenerated
                    if ( !isset($autogenerated_datafields[$df_id]) ) {
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
                                    throw new ODRBadRequestException('The Datafield "'.$datafield_name.'" has an invalid value');
                                break;

                            // Radio options need a different validation
                            case 'Radio':
                                if (!ValidUtility::areValidRadioOptions($df, $value))
                                    throw new ODRBadRequestException('The Datafield "'.$datafield_name.'" has an invalid value');
                                break;

                            // Tags also need a different validation
                            case 'Tag':
                                if (!ValidUtility::areValidTags($df, $value))
                                    throw new ODRBadRequestException('The Datafield "'.$datafield_name.'" has an invalid value');
                                break;

                            // The rest of the typeclasses aren't valid
                            case 'File':
                            case 'Image':
                            case 'Markdown':
                            default:
                                throw new ODRBadRequestException('The Datafield "'.$datafield_name.'" is not a valid typeclass');
                        }
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
                if ( !$permissions_service->canEditDatafield($user, $df) )
                    throw new ODRForbiddenException();
            }


            // ----------------------------------------
            // Need to verify that the values getting saved won't cause uniqueness conflicts with
            //  any of the existing datarecords
            foreach ($datafields as $df_id => $value) {
                $df = $df_mapping[$df_id];
                if ( $df->getIsUnique() ) {
                    if ( $sort_service->valueAlreadyExists($df, $value) )
                        throw new ODRConflictException('A Datarecord already has the value "'.$value.'" stored in the "'.$df->getFieldName().'" Datafield.');
                }
            }


            // ----------------------------------------
            // When a fake top-level record is submitted, then the user was originally given a page
            //  that had the default radio options already selected...and they had the opportunity
            //  to change them.  These potential changes shouldn't be overwritten.
            $create_default_radio_options = false;
            if ($inline_link) {
                // ...however, if the fake record was submitted via the inline linking system, then
                //  the radio datafields were disabled (because there's no way to display search
                //  results)...therefore, the default radio options should be selected.
                $create_default_radio_options = true;
            }

            // Now that all the post data makes sense, it's time to create some entities
            $new_datarecord = $entity_create_service->createDatarecord(
                $user,
                $datatype,
                false,   // Delaying flush here is pointless, due to creation of storage entities below
                $create_default_radio_options
            );


            // ----------------------------------------
            // This is wrapped in a try/catch block because any uncaught exceptions will abort
            //  creation of the new datarecord...
            try {
                $event = new DatarecordCreatedEvent($new_datarecord, $user);
                $dispatcher->dispatch(DatarecordCreatedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event.  In this case, a datarecord gets created, but the rest of the values aren't
                //  saved and the provisioned flag never gets changed to "false"...leaving the
                //  datarecord in a state that the user can't view/edit
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }


            // ----------------------------------------
            // Now that the datarecord exists...
            $new_datarecord->setProvisioned(false);
            $em->persist($new_datarecord);

            // ...create any required storage entities and assign the requested values to them
            foreach ($datafields as $df_id => $value) {
                $df = $df_mapping[$df_id];
                $typeclass = $df->getFieldType()->getTypeClass();

                if ( $typeclass === 'Radio' ) {
                    foreach ($value as $ro_id => $num) {
                        /** @var RadioOptions $ro */
                        $ro = $repo_radio_options->find($ro_id);    // this should already exist

                        // Create the drf entry...
                        $drf = $entity_create_service->createDatarecordField($user, $new_datarecord, $df);
                        // ...then create the radio selection
                        $radio_selection = $entity_create_service->createRadioSelection($user, $ro, $drf);

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
                        $drf = $entity_create_service->createDatarecordField($user, $new_datarecord, $df);
                        // ...then create the tag selection
                        $tag_selection = $entity_create_service->createTagSelection($user, $tag, $drf);

                        // New tags are unselected by default
                        $tag_selection->setSelected(1);
                        $em->persist($tag_selection);    // don't flush immediately...
                    }
                }
                else {
                    // All other fieldtypes have the possibility of being autogenerated...
                    if ( isset($autogenerated_datafields[$df_id]) ) {
                        // ...if this one is, then don't save the autogenerated value that the user
                        //  couldn't change
                        $value = null;
                    }

                    $entity_create_service->createStorageEntity($user, $new_datarecord, $df, $value);

                    // Don't need to worry about clearing sort order of other datatypes as a result
                    //  of these new datafield values...this new datarecord won't be linked to by
                    //  anything yet, so it can't affect another datatype's sort order
                }
            }

            // Ensure everything is flushed
            $em->flush();


            // ----------------------------------------
            // Since the datafield got at least one new value...probably should fire off a modified
            //  event for each datafield...
            foreach ($datafields as $df_id => $value) {
                if ( !isset($autogenerated_datafields[$df_id]) ) {
                    // ...but only if the datafield wasn't one of the autogenerated ones.  If it was,
                    //  then whatever render plugin is responsible will fire the event instead
                    $df = $df_mapping[$df_id];

                    try {
                        $event = new DatafieldModifiedEvent($df, $user);
                        $dispatcher->dispatch(DatafieldModifiedEvent::NAME, $event);
                    }
                    catch (\Exception $e) {
                        // ...don't want to rethrow the error since it'll interrupt everything after this
                        //  event
//                        if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                            throw $e;
                    }
                }
            }

            // Since the datarecord got modified to have values in at least one field...probably
            //  should fire off a modified event
            try {
                $event = new DatarecordModifiedEvent($new_datarecord, $user);
                $dispatcher->dispatch(DatarecordModifiedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

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
     * Looks through the cached datatype array to track down all datafields that should have their
     * value autogenerated instead of set through FakeEdit.
     *
     * @param array $dt_array
     *
     * @return array
     */
    private function findAutogeneratedDatafields($dt_array)
    {
        // There are two places in the cached datatype array that should be checked...
        $autogenerated_datafields = array();

        foreach ($dt_array as $dt_id => $dt) {
            // ...the first is the render plugin for the datatype
            foreach ($dt['renderPluginInstances'] as $rpi_id => $rpi) {
                foreach ($rpi['renderPluginMap'] as $rpf_name => $rpm_df) {
                    $rpm_df_id = $rpm_df['id'];
                    if ( isset($rpm_df['properties']) && isset($rpm_df['properties']['autogenerate_values']) )
                        $autogenerated_datafields[$rpm_df_id] = 1;
                }
            }

            // ...the second is the render plugin for each of the datafields
            foreach ($dt['dataFields'] as $df_id => $df) {
                foreach ($df['renderPluginInstances'] as $rpi_id => $rpi) {
                    foreach ($rpi['renderPluginMap'] as $rpf_name => $rpm_df) {
                        if ( isset($rpm_df['properties']) && isset($rpm_df['properties']['autogenerate_values']) )
                            $autogenerated_datafields[$df_id] = 1;
                    }
                }
            }
        }

        return $autogenerated_datafields;
    }


    /**
     * TODO - move this to a service?  but it would have to import the symfony container...
     * Looks through the cached datatype array to determine whether any of the used render plugins
     * derive values for any of their datafields.
     *
     * @param array $datatype_array
     *
     * @return array
     */
    private function findDerivedDatafields($datatype_array)
    {
        $derived_datafields = array();

        foreach ($datatype_array as $dt_id => $dt) {
            // For each render plugin this datatype is using...
            foreach ($dt['renderPluginInstances'] as $rpi_id => $rpi) {
                $plugin_classname = $rpi['renderPlugin']['pluginClassName'];

                // Check whether any of the renderPluginField entries are derived prior to attempting to
                //  load the renderPlugin itself
                $load_render_plugin = false;
                foreach ($rpi['renderPluginMap'] as $rpf_name => $rpf) {
                    if ( isset($rpf['properties']['is_derived']) ) {
                        $load_render_plugin = true;
                        break;
                    }
                }

                // If a datafield from this plugin is derived...
                if ($load_render_plugin) {
                    /** @var DatafieldDerivationInterface $render_plugin */
                    $render_plugin = $this->container->get($plugin_classname);

                    if ($render_plugin instanceof DatafieldDerivationInterface) {
                        // ...then request an array of the datafields that are derived from some other
                        //  field so the rest of FakeEdit can use it
                        $tmp = $render_plugin->getDerivationMap($rpi);
                        foreach ($tmp as $derived_df_id => $source_datafields)
                            $derived_datafields[$derived_df_id] = $source_datafields;

                        // TODO - multiple plugins attempting to derive the value in the same datafield?
                    }
                }
            }
        }

        return $derived_datafields;
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
     * Checks whether the given value for the given datafield is unique or not...it's easier for the
     * javascript to throw up warnings about uniqueness conflicts when it only has to check a
     * single datafield at a time.
     *
     * @param int $datafield_id
     * @param int $datarecord_id
     * @param Request $request
     *
     * @return Response
     */
    public function checkfakerecordfielduniqueAction($datafield_id, $datarecord_id, Request $request)
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
            $error_type = '';
            $value = '';
            foreach ($post as $typeclass => $form_data) {
                // ...but it should have these two keys in the array
                if ( !isset($form_data['_token']) || !isset($form_data['value']) )
                    throw new ODRBadRequestException();

                $value = trim($form_data['value']);
                if ( isset($form_data['error_type']) )
                    $error_type = $form_data['error_type'];
            }


            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var SortService $sort_service */
            $sort_service = $this->container->get('odr.sort_service');


            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            $datarecord = null;
            if ( $datarecord_id !== '' ) {
                /** @var DataRecord $datarecord */
                $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
                if ($datarecord == null)
                    throw new ODRNotFoundException('Datarecord');
                if ( $datarecord->getDataType()->getId() !== $datatype->getId() )
                    throw new ODRBadRequestException();
            }

            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if ( !$permissions_service->canAddDatarecord($user, $datatype) )
                throw new ODRForbiddenException();
            if ( !$permissions_service->canEditDatatype($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------

            // Datafield needs to be unique for this to make sense
            if ( !$datafield->getIsUnique() )
                throw new ODRBadRequestException('The "'.$datafield->getFieldName().'" datafield is not unique');

            // ...which means the empty string will fail
            if ( $value === '' )
                throw new ODRBadRequestException('Unable to save a blank value to the "'.$datafield->getFieldName().'" datafield');


            // Determine whether the given value is a duplicate of a value that already exists
            $is_duplicate = $sort_service->valueAlreadyExists($datafield, $value, $datarecord);
            $error_str = 'A Datarecord already has the value "'.$value.'" stored in the "'.$datafield->getFieldName().'" Datafield.';

            if ( $error_type === 'json' ) {
                // Need to return JSON so the jQuery Validate plugin works properly...so according
                //  to https://jqueryvalidation.org/remote-method/ ...return a string describing the
                //  error when the value is a duplicate, or return the string "true" when the value
                //  isn't a duplicate
                $response = new Response();
                if ($is_duplicate)
                    $response->setContent(json_encode($error_str));
                else
                    $response->setContent(json_encode("true"));

                $response->headers->set('Content-Type', 'application/json');
                return $response;
            }
            else if ( $is_duplicate ) {
                // Don't need to return JSON, so throw an exception when the value is a duplicate
                throw new ODRConflictException($error_str);
            }

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


    /**
     * Given a child datatype id and a datarecord, re-render and return the html for that child datatype.
     *
     * This function is (typically?) called after InlineLink fails to save a new record...it
     * receives the values the user entered into the InlineLink interface, and these values should
     * be spliced into the FakeEdit interface so the user doesn't have to re-enter everything.
     *
     * @param int $theme_element_id        The theme element this child/linked datatype is in
     * @param int $parent_datarecord_id    The parent datarecord of the child/linked datarecord
     *                                       that is getting reloaded
     * @param int $top_level_datarecord_id The datarecord currently being viewed in edit mode,
     *                                       required incase the user tries to reload B or C in the
     *                                       structure A => B => C => ...
     * @param Request $request
     *
     * @return Response
     */
    public function reloadchildAction($theme_element_id, $parent_datarecord_id, $top_level_datarecord_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            $post = $request->request->all();

            // Most of the verification is the same as self::savefakerecordAction()
            if ( !isset($post['datatype_id'])
                || !isset($post['datarecord_id'])
                || !isset($post['datafields'])
                || !isset($post['tokens'])
            ) {
                if ( isset($post['datatype_id']) && isset($post['datarecord_id']) && !isset($post['datafields']) && !isset($post['tokens']) ) {
                    // User attempted to save a completely empty datarecord...return a more useful
                    //  error message
                    throw new ODRBadRequestException("The new record must have data entered in at least one field before it can be saved");

                    // TODO - technically, it would be valid if the datatype only had files/images/child datatypes
                    // TODO - ...but the resulting datatype is borderline useless, so it's not likely?
                }
                else {
                    // Some other kind of problem, return a generic error message
                    throw new ODRBadRequestException();
                }
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


            // ----------------------------------------
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatabaseInfoService $database_info_service */
            $database_info_service = $this->container->get('odr.database_info_service');
            /** @var ODRRenderService $odr_render_service */
            $odr_render_service = $this->container->get('odr.render_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var CsrfTokenManager $token_manager */
            $token_manager = $this->container->get('security.csrf.token_manager');


            /** @var ThemeElement $theme_element */
            $theme_element = $em->getRepository('ODRAdminBundle:ThemeElement')->find($theme_element_id);
            if ($theme_element == null)
                throw new ODRNotFoundException('ThemeElement');

            // This is only valid if the theme element has a child/linked datatype
            if ( $theme_element->getThemeDataType()->isEmpty() )
                throw new ODRBadRequestException();

            $theme = $theme_element->getTheme();
            $parent_datatype = $theme->getDataType();
            $top_level_datatype = $theme->getParentTheme()->getDataType();


            /** @var DataRecord $parent_datarecord */
            $parent_datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($parent_datarecord_id);
            if ($parent_datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            if ($parent_datarecord->getDataType()->getId() !== $parent_datatype->getId())
                throw new ODRBadRequestException();


            /** @var DataRecord $top_level_datarecord */
            $top_level_datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($top_level_datarecord_id);
            if ($top_level_datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            if ($top_level_datarecord->getDataType()->getId() !== $top_level_datatype->getId())
                throw new ODRBadRequestException();


            /** @var ThemeDataType $theme_datatype */
            $theme_datatype = $theme_element->getThemeDataType()->first();
            $child_datatype = $theme_datatype->getDataType();


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if ( !$permissions_service->canEditDatarecord($user, $parent_datarecord) )
                throw new ODRForbiddenException();
            if ( !$permissions_service->canViewDatatype($user, $child_datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // ----------------------------------------
            // Need to verify that the datafields and tokens make sense
            // Most of the verification is the same as self::savefakerecordAction(), but slightly
            //  relaxed because InlineLink can't be strict and still do its actual job
            $datatype_array = $database_info_service->getDatatypeArray($child_datatype->getGrandparent()->getId(), false);    // don't need links
            $found_datafields = array();

            // Easier to locate any datafields that are going to receive autogenerated values here
            $autogenerated_datafields = self::findAutogeneratedDatafields($datatype_array);

            // Easier on the database to use the cache entry
            foreach ($datatype_array[$child_datatype->getId()]['dataFields'] as $df_id => $df) {
                // Don't need to verify that a fields marked as unique has a value
                $datafield_name = $df['dataFieldMeta']['fieldName'];
//                if ( $df['dataFieldMeta']['is_unique'] === true ) {
//                    if ( !isset($datafields[$df_id]) )
//                        throw new ODRBadRequestException('The Datafield "'.$datafield_name.'" must have a value');
//                }

                // Ignore values in datafields marked as "no user edits"...InlineLink doesn't create
                //  the special tokens required, and the FakeEdit render path will overwrite the
                //  given values anyways
//                if ( $df['dataFieldMeta']['prevent_user_edits'] === true ) {
//                    if ( isset($special_tokens[$df_id]) ) {
//                        // ...but it has a special token, then check whether it's legitimate
//                        $token_id = 'FakeEdit_'.$tmp_dr_id.'_'.$df_id.'_autogenerated';
//                        $check_token = $token_manager->getToken($token_id)->getValue();
//                        if ( $special_tokens[$df_id] !== $check_token )
//                            throw new ODRBadRequestException('Invalid CSRF Token');
//
//                        // No exception thrown, so the datafield's value will be set during whichever
//                        //  render plugin will handle the DatarecordCreated event later on
//                    }
//                    else {
//                        // Otherwise, it doesn't have a special token, so silently ensure that the
//                        //  field hasn't been given a value when it's not supposed to be editable
//                        //  by users
//                        if ( isset($datafields[$df_id]) )
//                            unset( $datafields[$df_id] );
//                        if ( isset($csrf_tokens[$df_id]) )
//                            unset( $csrf_tokens[$df_id] );
//                    }
//                }

                // If a value was provided for the field...
                if ( isset($datafields[$df_id]) ) {
                    $found_datafields[$df_id] = 1;

                    $typeclass = $df['dataFieldMeta']['fieldType']['typeClass'];
//                    $typename = $df['dataFieldMeta']['fieldType']['typeName'];
                    $token_id = $typeclass . 'Form_' . $tmp_dr_id . '_' . $df_id;
                    $value = $datafields[$df_id];

                    // Verify that the CSRF token for this field was submitted with the form...
                    if ( !isset($csrf_tokens[$df_id]) )
                        throw new ODRBadRequestException('Invalid CSRF Token');

                    // ...and that it's valid
                    $check_token = $token_manager->getToken($token_id)->getValue();
                    if ( $csrf_tokens[$df_id] !== $check_token )
                        throw new ODRBadRequestException('Invalid CSRF Token');


                    // The submitted value should only be verified if the datafield isn't marked
                    //  as having its value autogenerated
                    if ( !isset($autogenerated_datafields[$df_id]) ) {
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
                                    throw new ODRBadRequestException('The Datafield "'.$datafield_name.'" has an invalid value');
                                break;

                            // Radio options need a different validation
                            case 'Radio':
                                if (!ValidUtility::areValidRadioOptions($df, $value))
                                    throw new ODRBadRequestException('The Datafield "'.$datafield_name.'" has an invalid value');
                                break;

                            // Tags also need a different validation
                            case 'Tag':
                                if (!ValidUtility::areValidTags($df, $value))
                                    throw new ODRBadRequestException('The Datafield "'.$datafield_name.'" has an invalid value');
                                break;

                            // The rest of the typeclasses aren't valid
                            case 'File':
                            case 'Image':
                            case 'Markdown':
                            default:
                                throw new ODRBadRequestException('The Datafield "'.$datafield_name.'" is not a valid typeclass');
                        }
                    }
                }
            }

            // Verify that all the listed datafields belong to the datatype
            foreach ($datafields as $df_id => $val) {
                if ( !isset($found_datafields[$df_id]) )
                    throw new ODRBadRequestException('Invalid Datafield');
            }

            // There should technically be at least one datafield provided, but it's not a big deal
            //  if that's not the case
//            if ( empty($datafields) )
//                throw new ODRBadRequestException("The new record must have data entered in at least one field before it can be saved");


            // ----------------------------------------
            // Render and return the HTML
            $return['d'] = array(
                'html' => $odr_render_service->reloadFakeEditChildtype(
                    $user,
                    $theme_element,
                    $parent_datarecord,
                    $top_level_datarecord,
                    $datafields
                )
            );
        }
        catch (\Exception $e) {
            $source = 0x276bf2ae;
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
