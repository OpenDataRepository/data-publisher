<?php

/**
* Open Data Repository Data Publisher
* MassEdit Controller
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* The massedit controller handles rendering and processing a
* form that allows the user to change the data content for a
* collection of datarecords simultaneously.
*
*/

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\TrackedJob;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeElement;
use ODR\AdminBundle\Entity\ThemeElementField;
use ODR\AdminBundle\Entity\ThemeDataField;
use ODR\AdminBundle\Entity\ThemeDataType;
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTree;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataRecordFields;
use ODR\AdminBundle\Entity\Boolean;
use ODR\AdminBundle\Entity\ShortVarchar;
use ODR\AdminBundle\Entity\MediumVarchar;
use ODR\AdminBundle\Entity\LongVarchar;
use ODR\AdminBundle\Entity\LongText;
use ODR\AdminBundle\Entity\DecimalValue;
use ODR\AdminBundle\Entity\DatetimeValue;
use ODR\AdminBundle\Entity\IntegerValue;
use ODR\AdminBundle\Entity\Image;
use ODR\AdminBundle\Entity\ImageSizes;
use ODR\AdminBundle\Entity\ImageStorage;
use ODR\AdminBundle\Entity\RadioOptions;
use ODR\AdminBundle\Entity\RadioSelection;
use ODR\AdminBundle\Entity\FileChecksum;
use ODR\AdminBundle\Entity\ImageChecksum;

// Forms
use ODR\AdminBundle\Form\DecimalValueForm;
use ODR\AdminBundle\Form\IntegerValueForm;
use ODR\AdminBundle\Form\DatafieldsForm;
use ODR\AdminBundle\Form\DatatypeForm;
use ODR\AdminBundle\Form\UpdateDataFieldsForm;
use ODR\AdminBundle\Form\UpdateDataTypeForm;
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class MassEditController extends ODRCustomController
{

    /**
     * Sets up a mass edit request made from the shortresults list.
     * 
     * @param integer $datatype_id The database id of the DataType to mass edit...
     * 
     * @return a Symfony JSON response containing HTML
     */
    public function massEditListAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            $templating = $this->get('templating');
            $session = $request->getSession();

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype_id ]) && isset($user_permissions[ $datatype_id ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // Grab list of datarecords and associate to search key
            $em = $this->getDoctrine()->getManager();
            $query = $em->createQuery(
               'SELECT dr.id AS dr_id
                FROM ODRAdminBundle:DataRecord AS dr
                WHERE dr.deletedAt IS NULL AND dr.dataType = :datatype'
            )->setParameters( array('datatype' => $datatype_id) );
            $results = $query->getResult();

            $str = '';
            foreach ($results as $result) {
                $dr_id = $result['dr_id'];
                $str .= $dr_id.',';
            }
            $datarecords = substr($str, 0, strlen($str)-1);


            // Store the list of datarecord ids for later use
            $search_key = 'datatype_id='.$datatype_id;
            $saved_searches = array();
            if ( $session->has('saved_searches') )
                $saved_searches = $session->get('saved_searches');
            $search_checksum = md5($search_key);


            $saved_searches[$search_checksum] = array('logged_in' => true, 'datatype' => $datatype_id, 'datarecords' => $datarecords, 'encoded_search_key' => $search_key);
            $session->set('saved_searches', $saved_searches);


            // Get the mass edit page rendered
            $html = self::massEditRender($datatype_id, $search_checksum, $request);    // Using $search_checksum so Symfony doesn't screw up $search_key as it is passed around
            $return['d'] = array( 'html' => $html );
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x12736280 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;

    }


    /**
     * Sets up a mass edit request made from a search results page.
     * 
     * @param integer $datatype_id The database id of the DataType the search was performed on.
     * @param string $search_key   The search key identifying which datarecords to potentially mass edit
     * 
     * @return a Symfony JSON response containing HTML
     */
    public function massEditAction($datatype_id, $search_key, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            $templating = $this->get('templating');
            $session = $request->getSession();

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);
            $logged_in = true;

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype_id ]) && isset($user_permissions[ $datatype_id ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // ----------------------------------------
            // TODO - replace with parent::getSavedSearch()
            $encoded_search_key = '';
            $datarecords = '';
            if ($search_key !== '') {
                $search_controller = $this->get('odr_search_controller', $request);
                $search_controller->setContainer($this->container);

                if ( !$session->has('saved_searches') ) {
                    // no saved searches at all for some reason, redo the search with the given search key...
                    $search_controller->performSearch($search_key, $request);
                }

                // Grab the list of saved searches and attempt to locate the desired search
                $saved_searches = $session->get('saved_searches');
                $search_checksum = md5($search_key);

                if ( !isset($saved_searches[$search_checksum]) ) {
                    // no saved search for this query, redo the search...
                    $search_controller->performSearch($search_key, $request);

                    // Grab the list of saved searches again
                    $saved_searches = $session->get('saved_searches');
                }

                $search_params = $saved_searches[$search_checksum];
                $was_logged_in = $search_params['logged_in'];

                // If user's login status changed between now and when the search was run...
                if ($was_logged_in !== $logged_in) {
                    // ...run the search again
                    $search_controller->performSearch($search_key, $request);
                    $saved_searches = $session->get('saved_searches');
                    $search_params = $saved_searches[$search_checksum];
                }

                // Now that the search is guaranteed to exist and be correct...get all pieces of info about the search
                $datarecords = $search_params['datarecords'];
                $encoded_search_key = $search_params['encoded_search_key'];
            }

            // If the user is attempting to view a datarecord from a search that returned no results...
            if ($encoded_search_key !== '' && $datarecords === '') {
                // ...redirect to "no results found" page
                return $search_controller->renderAction($encoded_search_key, 1, 'searching', $request);
            }


            // Get the mass edit page rendered
            $html = self::massEditRender($datatype_id, $search_checksum, $request);    // Using $search_checksum so Symfony doesn't screw up $search_key as it is passed around
            $return['d'] = array( 'html' => $html );
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x12736279 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;

    }

    /**
     * Renders and returns the html used for performing mass edits.
     * 
     * @param integer $datatype_id    The database id that the search was performed on.
     * @param string $search_checksum The md5 checksum created from a $search_key
     * @param Request $request
     * 
     * @return string
     */
    private function massEditRender($datatype_id, $search_checksum, Request $request)
    {
        // Required objects
        $em = $this->getDoctrine()->getManager();
        $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
        $theme = $em->getRepository('ODRAdminBundle:Theme')->find(1);
        $templating = $this->get('templating');

        // --------------------
        // Determine user privileges
        $user = $this->container->get('security.context')->getToken()->getUser();
        $datatype_permissions = parent::getPermissionsArray($user->getId(), $request);
        $datafield_permissions = parent::getDatafieldPermissionsArray($user->getId(), $request);
        // --------------------

        $datatype = null;
        $theme_element = null;
        if ($datatype_id !== null) 
            $datatype = $repo_datatype->find($datatype_id);

        $indent = 0;
        $is_link = 0;
        $top_level = 1;
        $short_form = true;     // ?

$debug = true;
$debug = false;
if ($debug)
    print '<pre>';

        $tree = parent::buildDatatypeTree($user, $theme, $datatype, $theme_element, $em, $is_link, $top_level, $short_form, $debug, $indent);

if ($debug)
    print '</pre>';


        $html = $templating->render(
            'ODRAdminBundle:MassEdit:massedit_ajax.html.twig',
            array(
                'datafield_permissions' => $datafield_permissions,
                'search_checksum' => $search_checksum,
                'datatype_tree' => $tree,
                'theme' => $theme,
            )
        );

        return $html;
    }


    /**
     * Spawns a pheanstalk job for each datarecord-datafield pair modified by the mass update.
     * 
     * @param Request $request
     * 
     * @return an empty Symfony JSON response, unless an error occurred.
     */
    public function massUpdateAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Ensure post is valid
            $post = $_POST;
//print_r($post);
//return;
//            if ( !(isset($post['search_key']) && (isset($post['datafields']) || isset($post['datarecord_public'])) && isset($post['datatype_id'])) )
            if ( !(isset($post['search_checksum']) && (isset($post['datafields']) || isset($post['datarecord_public'])) && isset($post['datatype_id'])) )
                throw new \Exception('bad post request');
//            $search_key = $post['search_key'];
            $search_checksum = $post['search_checksum'];
            $datafields = array();
            if ( isset($post['datafields']) )
                $datafields = $post['datafields'];
            $datatype_id = $post['datatype_id'];

            $datarecord_public = 0;
            if ( isset($post['datarecord_public']) )
                $datarecord_public = $post['datarecord_public'];

            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
            $repo_datarecordfields = $em->getRepository('ODRAdminBundle:DataRecordFields');

//            $memcached = $this->get('memcached');
//            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
            $session = $request->getSession();
            $api_key = $this->container->getParameter('beanstalk_api_key');
            $pheanstalk = $this->get('pheanstalk');

            $url = $this->container->getParameter('site_baseurl');
            $url .= $this->container->get('router')->generate('odr_mass_update_worker');

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);
            $logged_in = true;

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype_id ]) && isset($user_permissions[ $datatype_id ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            $search_controller = $this->get('odr_search_controller', $request);
            $search_controller->setContainer($this->container);


            // TODO - this assumes that the search result exists in the session...replace with parent::getSavedSearch() to ensure it exists, or throw an error if it doesn't?
            // Grab the list of saved searches and attempt to locate the desired search
            $saved_searches = $session->get('saved_searches');
            $search_params = $saved_searches[$search_checksum];
            // Now that the search is guaranteed to exist and be correct...get all pieces of info about the search
            $datarecords = $search_params['datarecords'];
            $encoded_search_key = $search_params['encoded_search_key'];


            // If the user is attempting to view a datarecord from a search that returned no results...
            if ($encoded_search_key !== '' && $datarecords === '') {
                // ...redirect to "no results found" page
                return $search_controller->renderAction($encoded_search_key, 1, 'searching', $request);
            }

            $datarecords = explode(',', $datarecords);

            // ----------------------------------------
            // Ensure no unique datafields managed to get marked for this mass update
            foreach ($datafields as $df_id => $value) {
                $df = $repo_datafield->find($df_id);
                if ( $df->getIsUnique() == 1 )
                    unset($datafields[$df_id]);
            }

//print '$datarecords: '.print_r($datarecords, true)."\n";
//print '$datafields: '.print_r($datafields, true)."\n";
//return;


            // ----------------------------------------
            // If content of datafields was modified, get/create an entity to track the progress of this mass edit
            // Don't create a TrackedJob if this mass_edit just changes public status
            $tracked_job_id = -1;
            if ( count($datafields) > 0 && count($datarecords) > 0 ) {
                $job_type = 'mass_edit';
                $target_entity = 'datatype_'.$datatype_id;
                $additional_data = array('description' => 'Mass Edit of DataType '.$datatype_id);
                $restrictions = '';
                $total = count($datarecords) * count($datafields);
                $reuse_existing = false;

                $tracked_job = parent::ODR_getTrackedJob($em, $user, $job_type, $target_entity, $additional_data, $restrictions, $total, $reuse_existing);
                $tracked_job_id = $tracked_job->getId();
            }


            // ----------------------------------------
            // Deal with datarecord public status first, if needed
            $updated = false;
            if ( $datarecord_public !== null ) {
                $query_str = 'UPDATE ODRAdminBundle:DataRecord AS dr SET dr.publicDate = :public_date, dr.updated = :updated, dr.updatedBy = :updated_by WHERE dr.id IN (:datarecords)';    // TODO - doesn't update log
                $parameters = array('datarecords' => $datarecords, 'other_date' => new \DateTime('2200-01-01 00:00:00'), 'updated' => new \DateTime(), 'updated_by' => $user->getId());

                $updated = true;
                if ($datarecord_public == '-1') {
                    $query_str .= ' AND dr.publicDate != :other_date';
                    $parameters['public_date'] = new \DateTime('2200-01-01 00:00:00');
                }
                else if ($datarecord_public == '1') {
                    $query_str .= ' AND dr.publicDate = :other_date';
                    $parameters['public_date'] = new \DateTIme();
                }
                else {
                    $updated = false;
                }

//print $query_str."\n";
//print_r($parameters);

                if ($updated) {
                    $query = $em->createQuery($query_str)->setParameters( $parameters );
                    $num_updated = $query->execute();
//print '$num_updated: '.$num_updated."\n";
                }
            }

            // Finish dealing with datarecord public status if necessary
            if ($updated) {
                $em->flush();
//                $options = array();
//                $options['mark_as_updated'] = true;

                // Refresh the cache entries for these datarecords
//                foreach ($datarecords as $num => $datarecord_id)
//                    parent::updateDatarecordCache($datarecord_id, $options);
            }


            // ----------------------------------------
            foreach ($datarecords as $num => $datarecord_id) {
                foreach ($datafields as $datafield_id => $value) {
                    $drf = $repo_datarecordfields->findOneBy( array('dataRecord' => $datarecord_id, 'dataField' => $datafield_id) );    // TODO - needs to be a single query performed earlier

                    // Create a new pheanstalk job
                    $priority = 1024;   // should be roughly default priority
                    $payload = json_encode(
                        array(
                            "tracked_job_id" => $tracked_job_id,
                            "user_id" => $user->getId(),
                            "datarecordfield_id" => $drf->getId(),
                            "value" => $value,
                            "memcached_prefix" => $memcached_prefix,    // debug purposes only
                            "url" => $url,
                            "api_key" => $api_key,
                        )
                    );

                    $delay = 5;
                    $pheanstalk->useTube('mass_edit')->put($payload, $priority, $delay);

                }
            }

//            $router = $this->get('router');
//            $return['d'] = array( 'url' => $router->generate('odr_search_render', array('search_key' => $search_key, 'offset' => 1, 'source' => 'searching')) );
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x24463979 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;

    }


    /**
     * Called by the mass update worker processes to update a datarecord-datafield pair to a new value. 
     * 
     * @param Request $request
     * 
     * @return TODO
     */
    public function massUpdateWorkerAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            $ret = '';
            $post = $_POST;
//$ret = print_r($post, true);
            if ( !isset($post['tracked_job_id']) || !isset($post['user_id']) || !isset($post['datarecordfield_id']) || !isset($post['value']) || !isset($post['api_key']) )
                throw new \Exception('Invalid Form');

            // Pull data from the post
            $tracked_job_id = $post['tracked_job_id'];
            $user_id = $post['user_id'];
            $datarecordfield_id = $post['datarecordfield_id'];
            $value = $post['value'];
            $api_key = $post['api_key'];

            // Load symfony objects
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
            $pheanstalk = $this->get('pheanstalk');
            $logger = $this->get('logger');
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);

            $em = $this->get('doctrine')->getManager();
            $repo_user = $this->getDoctrine()->getRepository('ODROpenRepositoryUserBundle:User');
            $repo_datarecordfield = $em->getRepository('ODRAdminBundle:DataRecordFields');
            $repo_radio_option = $em->getRepository('ODRAdminBundle:RadioOptions');
            $repo_radio_selection = $em->getRepository('ODRAdminBundle:RadioSelection');

            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid Form');


            // ----------------------------------------
            $user = $repo_user->find($user_id);
            $datarecordfield = $repo_datarecordfield->find($datarecordfield_id);
            if ($datarecordfield == null)
                throw new \Exception('MassEditCommand.php: DataRecordField '.$datarecordfield_id.' is deleted, skipping');

            $datarecord = $datarecordfield->getDataRecord();
            if ($datarecord == null)
                throw new \Exception('MassEditCommand.php: DataRecordField '.$datarecordfield_id.' refers to deleted DataRecord, skipping');

            $datafield = $datarecordfield->getDataField();
            if ($datafield == null)
                throw new \Exception('MassEditCommand.php: DataRecordField '.$datarecordfield_id.' refers to deleted DataField, skipping');

            $datatype = $datarecord->getDataType();
            if ($datatype == null)
                throw new \Exception('MassEditCommand.php: DataRecordField '.$datarecordfield_id.' refers to deleted DataType, skipping');

            $datatype_id = $datatype->getId();


            // ----------------------------------------
            // See if there are migrations jobs in progress for this datatype
            $block = false;
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => 'migrate', 'restrictions' => 'datatype_'.$datatype_id, 'completed' => null) );
            if ($tracked_job !== null) {
                $target_entity = $tracked_job->getTargetEntity();
                $tmp = explode('_', $target_entity);
                $datafield_id = $tmp[1];

                $ret = 'MassEditCommand.php: Datafield '.$datafield_id.' is currently being migrated to a different fieldtype...'."\n";
                $return['r'] = 2;
                $block = true;
            }


            // ----------------------------------------
            if (!$block) {
                $field_typeclass = $datafield->getFieldType()->getTypeClass();
                $field_typename = $datafield->getFieldType()->getTypeName();
                if ($field_typeclass == 'Radio') {
                    // Grab all selection objects attached to this radio object
                    $radio_selections = array();
                    $tmp = $repo_radio_selection->findBy( array('dataRecordFields' => $datarecordfield->getId()) );
                    foreach ($tmp as $radio_selection)
                        $radio_selections[ $radio_selection->getRadioOption()->getId() ] = $radio_selection;

                    // Set radio_selection objects to the desired state        
                    $selected = $radio_option_id = null;
                    foreach ($value as $id => $val) {
                        $radio_option_id = $id;
                        $selected = $val;

                        if ( !isset($radio_selections[$radio_option_id]) ) {
                            $radio_option = $repo_radio_option->find($radio_option_id);

                            $radio_selection = parent::ODR_addRadioSelection($em, $user, $radio_option, $datarecordfield, $selected);
$ret .= 'created radio_selection object for datafield '.$datafield->getId().' ('.$field_typename.') of datarecord '.$datarecord->getId().', radio_option_id '.$radio_option_id.'...setting selected to '.$selected."\n";
                        }
                        else {
                            $radio_selection = $radio_selections[$radio_option_id];
                            if ( $selected != $radio_selection->getSelected() ) {
                                $radio_selection->setSelected($selected);
                                $radio_selection->setUpdatedBy($user);
                                $em->persist($radio_selection);
$ret .= 'found radio_selection object for datafield '.$datafield->getId().' ('.$field_typename.') of datarecord '.$datarecord->getId().', radio_option_id '.$radio_option_id.'...setting selected to '.$selected."\n";
                            }
                            else {
$ret .= 'not changing radio_selection object for datafield '.$datafield->getId().' ('.$field_typename.') of datarecord '.$datarecord->getId().', radio_option_id '.$radio_option_id.'...current selected status to desired status'."\n";
                            }

                            // Need to keep track of which radio_selection was modified for Single Radio/Select
                            unset( $radio_selections[$radio_option_id] );
                        }
                    }

                    // If only a single selection is allowed, deselect the other existing radio_selection objects
                    if ( $field_typename == "Single Radio" || $field_typename == "Single Select" ) {
                        foreach ($radio_selections as $radio_option_id => $radio_selection) {
                            if ( $radio_selection->getSelected() != 0 ) {
                                $radio_selection->setSelected(0);
                                $radio_selection->setUpdatedBy($user);
                                $em->persist($radio_selection);
$ret .= 'deselecting radio_option_id '.$radio_option_id.' for datafield '.$datafield->getId().' ('.$field_typename.') of datarecord '.$datarecord->getId()."\n";
                            }
                        }
                    }
                }
                else if ($field_typeclass == 'DatetimeValue') {
                    // Save the value in the referenced entity
                    $entity = $datarecordfield->getAssociatedEntity();

                    if ( $entity->getValue() !== null && $entity->getValue()->format('Y-m-d') !== $value ) {
$ret .= 'setting datafield '.$datafield->getId().' ('.$field_typename.') of datarecord '.$datarecord->getId().' from "'.$entity->getValue()->format('Y-m-d').'" to "'.$value."\"\n";
                        if ($value !== '')
                            $entity->setValue(new \DateTime($value));
                        else
                            $entity->setValue(null);

                        $entity->setUpdatedBy($user);
                        $em->persist($entity);
                    }
                    else {
$old_value = null;
if ($entity->getValue() !== null)
    $old_value = $entity->getValue();

$ret .= 'not changing datafield '.$datafield->getId().' ('.$field_typename.') of datarecord '.$datarecord->getId().', current value "'.$old_value->format('Y-m-d').'" identical to desired value "'.$value."\"\n";
                    }

                }
                else {
                    // Save the value in the referenced entity
                    $entity = $datarecordfield->getAssociatedEntity();

                    if ( $entity->getValue() !== $value ) {
$ret .= 'setting datafield '.$datafield->getId().' ('.$field_typename.') of datarecord '.$datarecord->getId().' from "'.$entity->getValue().'" to "'.$value."\"\n";
                        $entity->setValue($value);
                        $entity->setUpdatedBy($user);
                        $em->persist($entity);
                    }
                    else {
$ret .= 'not changing datafield '.$datafield->getId().' ('.$field_typename.') of datarecord '.$datarecord->getId().', current value "'.$entity->getValue().'" identical to desired value "'.$value.'"'."\n";
                    }
                }


                // ----------------------------------------
                // Update the job tracker if necessary
                if ($tracked_job_id !== -1) {
                    $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->find($tracked_job_id);

                    $total = $tracked_job->getTotal();
                    $count = $tracked_job->incrementCurrent($em);

                    if ($count >= $total)
                        $tracked_job->setCompleted( new \DateTime() );

                    $em->persist($tracked_job);
//                    $em->flush();
$ret .= '  Set current to '.$count."\n";
                }

                $em->flush();

                // ----------------------------------------
                // Determine whether short/textresults needs to be updated
                $options = array();
                $options['user_id'] = $user->getId();
                $options['mark_as_updated'] = true;
                if ( parent::inShortResults($datafield) )
                    $options['force_shortresults_recache'] = true;
                if ( $datafield->getDisplayOrder() != -1 )
                    $options['force_textresults_recache'] = true;

                // Recache this datarecord
                parent::updateDatarecordCache($datarecord->getId(), $options);
$ret .=  "---------------\n";
                $return['d'] = $ret;
            }
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x61395739 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }

}
