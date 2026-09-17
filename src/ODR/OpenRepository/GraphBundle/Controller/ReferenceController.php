<?php

/**
 * Open Data Repository Data Publisher
 * Reference Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The various Reference plugins are somewhat easier to use when they display a preview of the
 * fully formatted reference on the Edit page...but that requires a controller action to re-render
 * the reference each time a change is made.
 */

namespace ODR\OpenRepository\GraphBundle\Controller;

// Controllers/Classes
use ODR\AdminBundle\Controller\ODRCustomController;
// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Events
use ODR\AdminBundle\Component\Event\DatafieldModifiedEvent;
use ODR\AdminBundle\Component\Event\DatarecordModifiedEvent;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotImplementedException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\AdminBundle\Component\Service\EntityCreationService;
use ODR\AdminBundle\Component\Service\EntityMetaModifyService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\ThemeInfoService;
use ODR\OpenRepository\GraphBundle\Plugins\DatatypePluginInterface;
// Symfony
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;


class ReferenceController extends ODRCustomController
{

    public function __construct(
        $clone_theme_service,
        $database_info_service,
        $datarecord_info_service,
        $datatree_info_service,
        $entity_meta_modify_service,
        $render_service,
        $tab_helper_service,
        $permissions_management_service,
        $table_theme_helper_service,
        $theme_info_service,
        $search_service,
        $search_key_service,
        private readonly EntityCreationService $entity_creation_service,
    ) {
        parent::__construct($clone_theme_service, $database_info_service, $datarecord_info_service, $datatree_info_service, $entity_meta_modify_service, $render_service, $tab_helper_service, $permissions_management_service, $table_theme_helper_service, $theme_info_service, $search_service, $search_key_service);
    }

    /**
     * The various Reference plugins are somewhat easier to use when they display a preview of the
     *  fully formatted reference on the Edit page...but that requires a controller action to re-render
     *  the reference each time a change is made.
     *
     * @param string $request_datarecord_id
     * @param Request $request
     *
     * @return RedirectResponse|Response
     */
    public function renderAction($request_datarecord_id, Request $request)
    {
        $return = [];
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Load required objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->container->get('doctrine')->getManager();

            /** @var DatarecordInfoService $datarecord_info_service */
            $datarecord_info_service = $this->datarecord_info_service;
            /** @var DatabaseInfoService $database_info_service */
            $database_info_service = $this->database_info_service;
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->permissions_management_service;
            /** @var ThemeInfoService $theme_info_service */
            $theme_info_service = $this->theme_info_service;


            /** @var DataRecord $request_datarecord */
            $request_datarecord = $em->getRepository('ODR\AdminBundle\Entity\DataRecord')->find($request_datarecord_id);
            if ($request_datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            $is_top_level = 1;
            if ($request_datarecord->getId() !== $request_datarecord->getGrandparent()->getId())
                $is_top_level = 0;

            $request_datatype = $request_datarecord->getDataType();
            if ($request_datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');
            $request_datatype_id = $request_datatype->getId();


            // ----------------------------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()?->getUser() ?? 'anon.';   // <-- will return 'anon.' when nobody is logged in
            $user_permissions = $permissions_service->getUserPermissionsArray($user);
            $datatype_permissions = $user_permissions['datatypes'];
            $datafield_permissions = $user_permissions['datafields'];
            $is_datatype_admin = $permissions_service->isDatatypeAdmin($user, $request_datatype);

            // If the user isn't allowed to view either the datatype or the datarecord, don't continue
            if ( !$permissions_service->canViewDatarecord($user, $request_datarecord) )
                throw new ODRForbiddenException();
            // ----------------------------------------


            // ----------------------------------------
            // Don't care which theme the user is currently using
            $master_theme = $theme_info_service->getDatatypeMasterTheme($request_datatype_id);
            $plugin_theme_array = $theme_info_service->getThemeArray($master_theme->getId());

            // Get all Datarecords and Datatypes that are associated with the datarecord to render
            $datarecord_array = $datarecord_info_service->getDatarecordArray($request_datarecord_id, false);
            $datatype_array = $database_info_service->getDatatypeArray($request_datatype_id, false);

            // Delete everything that the user isn't allowed to see from the datatype/datarecord arrays
            $permissions_service->filterByGroupPermissions($datatype_array, $datarecord_array, $user_permissions);


            // The datatype to be passed to the plugin should not be wrapped with its id
            $plugin_dt_array = $database_info_service->stackDatatypeArray($datatype_array, $request_datatype_id);

            // The datatype could technically have multiple render plugins, but there should only be
            //  one graph plugin active
            $plugin_classname = null;
            $plugin_rpi_array = null;
            foreach ($datatype_array[ $request_datatype_id ]['renderPluginInstances'] as $rpi_id => $rpi) {
                // NOTE: not using the stacked version here due to odr_plugins.base.filter_graph...
                //  That plugin tends to have its renderPlugin entry in a childtype...
                $plugin_classname = $rpi['renderPlugin']['pluginClassName'];
                if ( $plugin_classname === 'odr_plugins.base.references'
                    || $plugin_classname === 'odr_plugins.chemin.chemin_references'
                    || $plugin_classname === 'odr_plugins.rruff.rruff_references'
                ) {
                    $plugin_rpi_array = $rpi;
                    break;
                }
            }
            if ( is_null($plugin_rpi_array) )
                throw new ODRException('Unable to find the RenderPluginInstance info');


            // ----------------------------------------
            // Need to determine the id of the parent datarecord...
            $parent_dr_id = null;

            if ( $is_top_level === 1 ) {
                // ...the requested datarecord is already top-level, so its "parent" is itself
                $parent_dr_id = $request_datarecord_id;
            }
            else {
                // ...the requested datarecord is not top-level, so need to find the parent.  Since
                //  $datarecord_array is not stacked, this can be done iteratively
                foreach ($datarecord_array as $dr_id => $dr) {
                    if ( isset($dr['children'][$request_datatype_id]) ) {
                        if ( in_array($request_datarecord_id, $dr['children'][$request_datatype_id]) ) {
                            $parent_dr_id = $dr_id;
                            break;
                        }
                    }
                }

                // This should always find something, since the child record has to exist before
                //  anything can make a request to render it...
            }
            if ( is_null($parent_dr_id) )
                throw new ODRException('Unable to find the Parent Datarecord info');

            // The first argument for the plugin service should only be a single cached datarecord
            //  entry, wrapped with its own id
            $plugin_dr_array = [$request_datarecord_id => $datarecord_info_service->stackDatarecordArray($datarecord_array, $request_datarecord_id)];

            // The argument for the parent datarecord array does not need to be wrapped
            $plugin_parent_dr_array = null;
            if ( $is_top_level === 1 )
                $plugin_parent_dr_array = $plugin_dr_array[$request_datarecord_id];
            else
                $plugin_parent_dr_array = $datarecord_info_service->stackDatarecordArray($datarecord_array, $parent_dr_id);


            // ----------------------------------------
            // Need some additional data so twig doesn't complain when rendering
            $rendering_options = [
                'is_link' => 0,    // these three values don't really matter at the moment
                'display_type' => 0,
                'multiple_allowed' => 0,

                'is_top_level' => $is_top_level,
                'context' => 'html',    // like 'text', but want files/urls...
                'is_datatype_admin' => $is_datatype_admin,
            ];

            /** @var ContainerInterface $service_container */
            $service_container = $this->container->get('service_container');
            /** @var DatatypePluginInterface $svc */
            $svc = $service_container->get($plugin_classname);
            $str = $svc->execute($plugin_dr_array, $plugin_dt_array, $plugin_rpi_array, $plugin_theme_array, $rendering_options, $plugin_parent_dr_array, $datatype_permissions, $datafield_permissions);

            $return['d'] = $str;
        }
        catch (\Exception $e) {
            $source = 0x6005597e;
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
     * Gathers the data required to open a dialog that compares existing reference data (with a URL)
     * against Crossref's API.  {@link https://www.crossref.org}
     *
     * @param string $request_datarecord_id
     * @param Request $request
     *
     * @return RedirectResponse|Response
     */
    public function loaddialogAction($request_datarecord_id, Request $request)
    {
        $return = [];
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Load required objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->container->get('doctrine')->getManager();

            /** @var DatarecordInfoService $datarecord_info_service */
            $datarecord_info_service = $this->datarecord_info_service;
            /** @var DatabaseInfoService $database_info_service */
            $database_info_service = $this->database_info_service;
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->permissions_management_service;
            /** @var \Twig\Environment $templating */
            $templating = $this->container->get('twig');


            /** @var DataRecord $request_datarecord */
            $request_datarecord = $em->getRepository('ODR\AdminBundle\Entity\DataRecord')->find($request_datarecord_id);
            if ($request_datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            $request_datatype = $request_datarecord->getDataType();
            if ($request_datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');
            $request_datatype_id = $request_datatype->getId();


            // ----------------------------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()?->getUser() ?? 'anon.';   // <-- will return 'anon.' when nobody is logged in

            $is_datatype_admin = false;
            if ( $permissions_service->isDatatypeAdmin($user, $request_datatype) )
                $is_datatype_admin = true;

            // If the user isn't allowed to view either the datatype or the datarecord, don't continue
            if ( !$permissions_service->canEditDatarecord($user, $request_datarecord) )
                throw new ODRForbiddenException();
            // ----------------------------------------

            // Going to dig through cache entries for the data already in the reference
            $dt_array = $database_info_service->getDatatypeArray($request_datatype_id, false);  // don't need links
            $dt = $dt_array[$request_datatype_id];
            $dr_array = $datarecord_info_service->getDatarecordArray($request_datarecord_id, false);
            $dr = $dr_array[$request_datarecord_id];

            // Retrieve mapping between datafields and render plugin fields
            $plugin_fields = [];
            foreach ($dt['renderPluginInstances'] as $rpi_id => $rpi) {
                $render_plugin_classname = $rpi['renderPlugin']['pluginClassName'];
                if ( $render_plugin_classname === 'odr_plugins.base.references'
                    || $render_plugin_classname === 'odr_plugins.chemin.chemin_references'
                    || $render_plugin_classname === 'odr_plugins.rruff.rruff_references'
                ) {
                    // Using the correct plugin
                    foreach ($rpi['renderPluginMap'] as $rpf_name => $rpf_df) {
                        // Need to find the real datafield entry in the primary datatype array
                        $rpf_df_id = $rpf_df['id'];

                        $df = null;
                        if ( isset($dt['dataFields'][$rpf_df_id]) )
                            $df = $dt['dataFields'][$rpf_df_id];

                        // Autogenerated fields will continue to work even if the user can't see them,
                        //  but probably should still complain about plugin mapping errors to datatype admins
                        if ($df == null && $is_datatype_admin)
                            throw new \Exception('Unable to locate array entry for the field "'.$rpf_name.'", mapped to df_id '.$rpf_df_id);

                        // Need to tweak display parameters for several of the fields...
                        $plugin_fields[$rpf_df_id] = $rpf_df;
                        $plugin_fields[$rpf_df_id]['rpf_name'] = $rpf_name;
                        $plugin_fields[$rpf_df_id]['typeClass'] = $df['dataFieldMeta']['fieldType']['typeClass'];
                    }
                }
            }

            // Don't want to get every single field, though...
            $doi_fields = self::getDOIFields($dt, $dr, $plugin_fields);

            // ...which also makes looking up the existing values easier
            $field_values = [];
            foreach ($plugin_fields as $rpf_df_id => $rpf_df) {
                $rpf_name = $rpf_df['rpf_name'];
                if ( !isset($doi_fields[$rpf_name]) ) {
                    unset( $plugin_fields[$rpf_df_id] );
                }
                else if ( isset($dr['dataRecordFields'][$rpf_df_id]) ) {
                    $drf = $dr['dataRecordFields'][$rpf_df_id];

                    // Just brute-force the typeclass
                    if ( isset($drf['shortVarchar'][0]['value']) )
                        $field_values[$rpf_name] = $drf['shortVarchar'][0]['value'];
                    else if ( isset($drf['mediumVarchar'][0]['value']) )
                        $field_values[$rpf_name] = $drf['mediumVarchar'][0]['value'];
                    else if ( isset($drf['longVarchar'][0]['value']) )
                        $field_values[$rpf_name] = $drf['longVarchar'][0]['value'];
                    else if ( isset($drf['longText'][0]['value']) )
                        $field_values[$rpf_name] = $drf['longText'][0]['value'];
                }
            }

            // Don't load the dialog if there's no URL to use
            if ( !isset($field_values['URL']) || $field_values['URL'] == '' )
                throw new ODRBadRequestException('This tool requires a URL already in the field');

            // Generate a csrf token for the form
            $token_key = 'Form_'.$request_datarecord_id.'_doi_lookup';

            // ----------------------------------------
            // Render and return the dialog
            $return['d'] = [
                'html' => $templating->render(
                    '@ODROpenRepositoryGraph/Base/References/references_lookup_form.html.twig',
                    [
                        'datarecord_id' => $request_datarecord_id,
                        'token_key' => $token_key,

                        'plugin_fields' => $plugin_fields,
                        'field_values' => $field_values,
                        'doi_fields' => $doi_fields,
                    ]
                )
            ];
        }
        catch (\Exception $e) {
            $source = 0x68a5cd4a;
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
     * The DOI info loaded when in FakeEdit mode needs to know where on the page the data goes...
     *
     * @param array $dt_array
     * @param array $dr_array
     * @param array $plugin_fields
     * @return array
     */
    private function getDOIFields($dt_array, $dr_array, $plugin_fields)
    {
        $doi_fields = [];
        $dr_id = $dr_array['id'];

        foreach ($plugin_fields as $df_id => $rpf_df) {
            $typeclass = $dt_array['dataFields'][$df_id]['dataFieldMeta']['fieldType']['typeClass'];
            $rpf_df_name = $rpf_df['rpf_name'];
            switch ($rpf_df_name) {
                case 'Authors':
                case 'Article Title':
                case 'Book Title':
                case 'Journal':
                case 'Publisher':
//                case 'Publisher Location':
                case 'Year':
//                case 'Month':
                case 'Volume':
                case 'Issue':
                case 'Pages':
                case 'URL':
                    $doi_fields[$rpf_df_name] = 'ODRReferencePluginDialogLookup_'.$dr_id.'_'.$df_id;
                    break;
            }
        }

        return $doi_fields;
    }


    /**
     * TODO
     *
     * @param Request $request
     *
     * @return RedirectResponse|Response
     */
    public function savedialogAction(Request $request)
    {
        $return = [];
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Ensure required variables exist
            $post = $request->request->all();
            if ( !isset($post['_token']) || !isset($post['datarecord_id']) )
                throw new ODRBadRequestException();

            $request_datarecord_id = $post['datarecord_id'];

            // Load required objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->container->get('doctrine')->getManager();

            // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
            //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
            /** @var EventDispatcherInterface $event_dispatcher */
            $dispatcher = $this->container->get('event_dispatcher');

            /** @var DatarecordInfoService $datarecord_info_service */
            $datarecord_info_service = $this->datarecord_info_service;
            /** @var DatabaseInfoService $database_info_service */
            $database_info_service = $this->database_info_service;
            /** @var EntityCreationService $entity_create_service */
            $entity_create_service = $this->entity_creation_service;
            /** @var EntityMetaModifyService $entity_modify_service */
            $entity_modify_service = $this->entity_meta_modify_service;
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->permissions_management_service;

            $repo_datafield = $em->getRepository('ODR\AdminBundle\Entity\DataFields');

            /** @var DataRecord $request_datarecord */
            $request_datarecord = $em->getRepository('ODR\AdminBundle\Entity\DataRecord')->find($request_datarecord_id);
            if ($request_datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            $request_datatype = $request_datarecord->getDataType();
            if ($request_datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');
            $request_datatype_id = $request_datatype->getId();


            // ----------------------------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()?->getUser() ?? 'anon.';   // <-- will return 'anon.' when nobody is logged in

            $is_datatype_admin = false;
            if ( $permissions_service->isDatatypeAdmin($user, $request_datatype) )
                $is_datatype_admin = true;

            // If the user isn't allowed to view either the datatype or the datarecord, don't continue
            if ( !$permissions_service->canEditDatarecord($user, $request_datarecord) )
                throw new ODRForbiddenException();
            // ----------------------------------------


            // ----------------------------------------
            // Verify the csrf token
            $token_key = 'Form_'.$request_datarecord_id.'_doi_lookup';
            if ( !$this->isCsrfTokenValid($token_key, $post['_token']) )
                throw new ODRBadRequestException('Invalid CSRF Token');

            // Going to dig through cache entries for the data already in the reference
            $dt_array = $database_info_service->getDatatypeArray($request_datatype_id, false);  // don't need links
            $dt = $dt_array[$request_datatype_id];
            $dr_array = $datarecord_info_service->getDatarecordArray($request_datarecord_id, false);
            $dr = $dr_array[$request_datarecord_id];

            // Retrieve mapping between datafields and render plugin fields
            $plugin_fields = [];
            foreach ($dt['renderPluginInstances'] as $rpi_id => $rpi) {
                $render_plugin_classname = $rpi['renderPlugin']['pluginClassName'];
                if ( $render_plugin_classname === 'odr_plugins.base.references'
                    || $render_plugin_classname === 'odr_plugins.chemin.chemin_references'
                    || $render_plugin_classname === 'odr_plugins.rruff.rruff_references'
                ) {
                    // Using the correct plugin
                    foreach ($rpi['renderPluginMap'] as $rpf_name => $rpf_df) {
                        // Need to find the real datafield entry in the primary datatype array
                        $rpf_df_id = $rpf_df['id'];

                        $df = null;
                        if ( isset($dt['dataFields'][$rpf_df_id]) )
                            $df = $dt['dataFields'][$rpf_df_id];

                        // Autogenerated fields will continue to work even if the user can't see them,
                        //  but probably should still complain about plugin mapping errors to datatype admins
                        if ($df == null && $is_datatype_admin)
                            throw new \Exception('Unable to locate array entry for the field "'.$rpf_name.'", mapped to df_id '.$rpf_df_id);

                        // Need to tweak display parameters for several of the fields...
                        $plugin_fields[$rpf_df_id] = $rpf_df;
                        $plugin_fields[$rpf_df_id]['rpf_name'] = $rpf_name;
                        $plugin_fields[$rpf_df_id]['typeClass'] = $df['dataFieldMeta']['fieldType']['typeClass'];
                    }
                }
            }

            // Don't want to get every single field, though...
            $doi_fields = self::getDOIFields($dt, $dr, $plugin_fields);

            // ----------------------------------------
            // Ensure all the DOI fields are in the post
            /** @var DataFields[] $df_lookup */
            $df_lookup = [];
            $values_to_save = [];
            foreach ($plugin_fields as $rpf_df_id => $rpf_df) {
                $rpf_name = $rpf_df['rpf_name'];
                if ( !isset($doi_fields[$rpf_name]) ) {
                    // This field won't be in the POST
                    unset( $plugin_fields[$rpf_df_id] );
                }
                else if ( !isset($post['switch_'.$rpf_df_id]) || !isset($post['lookup_'.$rpf_df_id]) ) {
                    // This field should be in the POST, but isn't
                    throw new ODRBadRequestException();
                }
                else if ( $post['switch_'.$rpf_df_id] == 'lookup' ) {
                    // Only care about the value acquired via the API call when the user wants to save it
                    $values_to_save[$rpf_df_id] = $post['lookup_'.$rpf_df_id];

                    // Going to need the hydrated version of this datafield
                    /** @var DataFields $hydrated_df */
                    $hydrated_df = $repo_datafield->find($rpf_df_id);
                    if ($hydrated_df == null)
                        throw new ODRNotFoundException('Datafield');
                    $df_lookup[$rpf_df_id] = $hydrated_df;
                }

                // If the switch field is set to 'current', then don't need to save anything for it
            }

            // Also ensure the user can edit all of these fields before continuing
            foreach ($df_lookup as $df_id => $df) {
                if ( !$permissions_service->canEditDatafield($user, $df, $request_datarecord) )
                    throw new ODRForbiddenException();
            }

            // Have a list of the fields to save now
            $changes_made = false;
            if ( count($values_to_save) > 0 ) {
                // ...not strictly true, but making this actually accurate isn't critically important
                $changes_made = true;

                foreach ($values_to_save as $df_id => $val) {
                    $df = $df_lookup[$df_id];

                    // Don't fire the PostUpdateEvent here if something gets created...
                    $storage_entity = $entity_create_service->createStorageEntity($user, $request_datarecord, $df, null, false);

                    // Because of createStorageEntity(), delaying flushes is pointless
                    $props = ['value' => $val];
                    $entity_modify_service->updateStorageEntity($user, $storage_entity, $props);

                    try {
                        $event = new DatafieldModifiedEvent($df, $user);
                        $dispatcher->dispatch($event, DatafieldModifiedEvent::NAME);
                    }
                    catch (\Exception) {
                        // ...don't want to rethrow the error since it'll interrupt everything after this
                        //  event
//                        if ( $this->getParameter('kernel.environment') === 'dev' )
//                            throw $e;
                    }
                }

                // Need to fire off a DatarecordModified event too
                try {
                    $event = new DatarecordModifiedEvent($request_datarecord, $user);
                    $dispatcher->dispatch($event, DatarecordModifiedEvent::NAME);
                }
                catch (\Exception $e) {
                    // ...don't want to rethrow the error since it'll interrupt everything after this
                    //  event
//                if ( $this->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
                }
            }


            $return['d'] = [
                'changes_made' => $changes_made
            ];

        }
        catch (\Exception $e) {
            $source = 0x0fff1e25;
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
