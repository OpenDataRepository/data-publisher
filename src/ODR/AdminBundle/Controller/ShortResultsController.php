<?php

/**
* Open Data Repository Data Publisher
* ShortResults Controller
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* The ShortResults controller handles requests to view lists of
* datarecords in either ShortResults or TextResults format.  This
* controller also handles requests to reload the ShortResults variant
* of a single datarecord, for when the user wishes to immediately
* view a ShortReseults variant that was not in memcached.
*
*/

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\DataRecord;
// Forms
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


/**
 * Class ShortResultsController
 * @deprecated
 *
 * @package ODR\AdminBundle\Controller
 */
class ShortResultsController extends ODRCustomController
{

    /**
     * Returns the ShortResults/TextResults version of this datarecord...triggered when the user clicks a "reload html for this datarecord" button after a cache failure
     * TODO - currently never given the option to reload a textresults entry...they always exist from a user's point of view
     *
     * @param integer $datarecord_id Which datarecord needs to render ShortResults/TextResults for
     * @param string $force          ..currently, whether to load ShortResults, TextResults, or whatever is default for the DataType
     * @param Request $request
     *
     * @return Response TODO
     */
    public function reloadAction($datarecord_id, $force, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get Current User
//            $user = $this->container->get('security.context')->getToken()->getUser();
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ($datarecord == null)
                return parent::deletedEntityError('DataRecord');
            $datatype = $datarecord->getDataType();
            if ($datatype == null)
                return parent::deletedEntityError('DataType');


            // Attempt to load the datarecord from the cache...
            $data = null;
            $html = '';
//            if ($force == 'short')
                $data = $memcached->get($memcached_prefix.'.data_record_short_form_'.$datarecord->getId());
/*
            else if ($force == 'text')
                $data = $memcached->get($memcached_prefix.'.data_record_short_text_form_'.$datarecord->getId());
            else if ($datatype->getUseShortResults() == 1)
                $data = $memcached->get($memcached_prefix.'.data_record_short_form_'.$datarecord->getId());
            else
                $data = $memcached->get($memcached_prefix.'.data_record_short_text_form_'.$datarecord->getId());
*/

            // No caching in dev environment
            if ($this->container->getParameter('kernel.environment') === 'dev')
                $data = null;

            if ($data == null/* || $data['revision'] < $datatype->getRevision()*/) {
                // ...otherwise, ensure all the entities exist before rendering and caching the short form of the DataRecord
                parent::verifyExistence($datarecord);
//                if ($force == 'short') {
                    $html = parent::Short_GetDisplayData($request, $datarecord->getId());
                    $data = array( 'revision' => $datatype->getRevision(), 'html' => $html );
                    $memcached->set($memcached_prefix.'.data_record_short_form_'.$datarecord->getId(), $data, 0);
/*
                }
                else if ($force == 'text') {
                    $html = parent::Text_GetDisplayData($request, $datarecord->getId());
                    $data = array( 'revision' => $datatype->getRevision(), 'html' => $html );
                    $memcached->set($memcached_prefix.'.data_record_short_text_form_'.$datarecord->getId(), $data, 0);
                }
                else if ($datatype->getUseShortResults() == 1) {
                    $html = parent::Short_GetDisplayData($request, $datarecord->getId());
                    $data = array( 'revision' => $datatype->getRevision(), 'html' => $html );
                    $memcached->set($memcached_prefix.'.data_record_short_form_'.$datarecord->getId(), $data, 0);
                }
                else {
                    $html = parent::Text_GetDisplayData($request, $datarecord->getId());
                    $data = array( 'revision' => $datatype->getRevision(), 'html' => $html );
                    $memcached->set($memcached_prefix.'.data_record_short_text_form_'.$datarecord->getId(), $data, 0);
                }
*/
                // Update all cache entries for this datarecord
                $options = array();
                parent::updateDatarecordCache($datarecord->getId(), $options);
            }
            else {
                // If the memcache entry exists, grab the html
                $html = $data['html'];
            }

            $return['d'] = array(
//                'force' => $force,
//                'use_shortresults' => $datatype->getUseShortResults(),
                'datarecord_id' => $datarecord_id,
                'html' => $html,
            );
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x178823602 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * TODO - this was a sitemap function
     * Returns a JSON object containing an array of all DataRecord IDs and the contents of their unique (or sort) datafield.
     *
     * @param integer $datatype_id
     * @param Request $request
     *
     * @return Response TODO
     */
    public function recordlistAction($datatype_id, Request $request)
    {
/*
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'json';
        $return['d'] = '';

        try {
            // Get Entity Manager and setup objects
            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
            $repo_datarecordfield = $em->getRepository('ODRAdminBundle:DataRecordFields');
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');

            $datatype = $repo_datatype->find($datatype_id);

            // Attempt to locate a datafield we can use to 'name' the DataRecord...try the name field first
            $skip = false;
            $datafield = $datatype->getNameField();
            if ($datafield === null) {
                // name field failed, try the sort field
                $datafield = $datatype->getSortField();

                if ($datafield === null) {
                    $return['d'] = 'Error!';
                    $skip = true;
                }
            }

            // If the attempt to locate the 'name' DataRecord didn't fail
            if (!$skip) {
                // Grab all DataRecords of this DataType
                $datarecords = $repo_datarecord->findByDataType($datatype);

                // Grab the IDs and 'names' of all the DataRecords
                $type_class = $datafield->getFieldType()->getTypeClass();
                $data = array();
                foreach ($datarecords as $dr) {
                    // 
                    $drf = $repo_datarecordfield->findOneBy( array('dataRecord' => $dr->getId(), 'dataField' => $datafield->getId()) );
//                    $entity = parent::loadFromDataRecordField($drf, $type_class);
//                    $name = $entity->getValue();
                    $name = $drf->getAssociatedEntity()->getValue();

                    //
                    $data[$dr->getId()] = $name;
                }

                $return['d'] = $data;
            }
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x122280082 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
*/
    }


    /**
     * TODO - this was a sitemap function
     * Builds and returns...TODO
     * 
     * @param Integer $datatype_id
     * @param string $datatype_name
     * @param Request $request
     * 
     * @return Response TODO
     */
    public function mapAction($datatype_id, $datatype_name, Request $request)
    {
/*
        $return = '';

        try {
            // Get necessary objects
            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
            $templating = $this->get('templating');
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');

            // Grab the desired datarecord
            $datatype = $repo_datatype->find($datatype_id);
            $datarecords = $repo_datarecord->findBy( array('dataType' => $datatype->getId()) );

            // Grab the short form HTML of each of the datarecords to be displayed
            $user = 'anon.';
            $cache_html = '';
            foreach ($datarecords as $datarecord) {
                if ($datarecord->isPublic()) {                 // <-- 'anon.' indicates no user logged in, though i believe this action is only accessed when somebody is logged in

                    // Attempt to load the datarecord from the cache...
                    $html = $memcached->get($memcached_prefix.'.data_record_short_form_'.$datarecord->getId());

                    // No caching in dev environment
                    if ($this->container->getParameter('kernel.environment') === 'dev')
                        $html = null;

                    if ($html != null) {
                        // ...if the html exists, append to the current list and continue
                        $cache_html .= $html;
                    }
                    else {
                        // ...otherwise, ensure all the entities exist before rendering the short form of the DataRecord
                        parent::verifyExistence($datarecord);
                        $html = parent::Short_GetDisplayData($request, $datarecord->getId());

                        // Cache the html
                        $memcached->set($memcached_prefix.'.data_record_short_form_'.$datarecord->getId(), $html, 0);

                        $cache_html .= $html;
                    }
                }
            }

            // Render the javascript redirect
            $prefix = '/app_dev.php/search#';
            $redirect_str = $this->generateUrl( 'odr_shortresults_list', array('datatype_id' => $datatype_id, 'target' => 'results') );
            $header = $templating->render(
                'ODRAdminBundle:Default:redirect_js.html.twig',
                array(
                    'prefix' => $prefix,
                    'url' => $redirect_str
                )
            );

            // Concatenate the two
            $return = $header.$cache_html;
        }
        catch (\Exception $e) {
//            $return['r'] = 1;
//            $return['t'] = 'ex';
//            $return['d'] = 'Error 0x802484450 ' . $e->getMessage();
        }

        $response = new Response($return);
        $response->headers->set('Content-Type', 'text/html');
        return $response;
*/
    }

}
