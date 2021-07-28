<?php

/**
 * Open Data Repository Data Publisher
 * Remote Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Holds functions that help users set up and configure the "ODR Remote Search" javascript module,
 * intended for use by a 3rd party website.
 *
 * These controller actions help the user select the (subset of) datatype/datafields that they're
 * interested in searching from their own website...a second piece of javascript is then generated
 * that configures the module, allowing it to build the base64 search keys that ODR's search system
 * expects.
 *
 * People can then enter search terms on the 3rd party website, and get redirected to the equivalent
 * search results page on ODR.
 */

namespace ODR\OpenRepository\SearchBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataType;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\AdminBundle\Component\Service\DatatreeInfoService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\ThemeInfoService;
use ODR\AdminBundle\Component\Utility\UserUtility;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchService;
// Symfony
use Symfony\Bundle\TwigBundle\TwigEngine;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;


class RemoteController extends Controller
{

    /**
     * Displays a list of public datatypes that can be searched on without an ODR login.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function startAction(Request $request)
    {

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatabaseInfoService $dbi_service */
            $dbi_service = $this->container->get('odr.database_info_service');
            /** @var DatatreeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatree_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            // --------------------
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Don't actually care about the user here, but should keep datarecord counts accurate
            $datatype_permissions = $pm_service->getDatatypePermissions($user);
            // --------------------


            // ----------------------------------------
            // Need a list of public top-level non-metadata datatypes
            $top_level_datatypes = $dti_service->getTopLevelDatatypes();
            $query = $em->createQuery(
               'SELECT dt, dtm, dt_cb
                FROM ODRAdminBundle:DataType AS dt
                LEFT JOIN dt.dataTypeMeta AS dtm
                LEFT JOIN dt.createdBy AS dt_cb

                WHERE dt.id IN (:datatypes) AND dt.is_master_type = :is_master_type
                AND dt.unique_id = dt.template_group AND dtm.publicDate != :public_date
                AND dt.setup_step IN (:setup_steps) AND dt.metadata_for IS NULL
                AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL'
            )->setParameters(
                array(
                    'datatypes' => $top_level_datatypes,
                    'is_master_type' => false,
                    'public_date' => '2200-01-01 00:00:00',
                    'setup_steps' => DataType::STATE_VIEWABLE
                )
            );
            $results = $query->getArrayResult();

            // Do a bit of cleanup on the database results...
            $datatypes = array();
            foreach ($results as $num => $dt) {
                $dt_id = $dt['id'];
                $datatypes[$dt_id] = $dt;

                // Flatten datatype meta
                $datatypes[$dt_id]['dataTypeMeta'] = $datatypes[$dt_id]['dataTypeMeta'][0];
                $datatypes[$dt_id]['createdBy'] = UserUtility::cleanUserData($dt['createdBy']);
            }

            // Also need the counts of the public datarecords
            $metadata = $dbi_service->getDatarecordCounts($top_level_datatypes, $datatype_permissions);


            // ----------------------------------------
            // Render this as a "base" page...otherwise users have to go to something like
            //  https://odr.io/admin#/remote_search ...which requires a login
            $site_baseurl = $this->container->getParameter('site_baseurl');

            $html = $this->renderView(
                'ODROpenRepositorySearchBundle:Remote:index.html.twig',
                array(
                    'site_baseurl' => $site_baseurl,
                    'window_title' => 'ODR Admin',

                    'user' => $user,
                    'datatype_permissions' => $datatype_permissions,

                    'datatypes' => $datatypes,
                    'metadata' => $metadata,
                )
            );
        }
        catch (\Exception $e) {
            $source = 0xc8fe1734;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response($html);
        $response->headers->set('Content-Type', 'text/html');
        return $response;
    }


    /**
     * After the user picks a datatype in startAction, they need to be able to pick from a list of
     * public datafields...
     *
     * @param integer $datatype_id
     * @param Request $request
     *
     * @return Response
     */
    public function selectAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatabaseInfoService $dbi_service */
            $dbi_service = $this->container->get('odr.database_info_service');
            /** @var PermissionsManagementService $pm_service */
//            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchService $search_service */
            $search_service = $this->container->get('odr.search_service');
            /** @var ThemeInfoService $ti_service */
            $ti_service = $this->container->get('odr.theme_info_service');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            // This controller action only works with public top-level non-metadata datatypes
            if (!$datatype->isPublic())
                throw new ODRBadRequestException();
            if ($datatype->getId() !== $datatype->getGrandparent()->getId())
                throw new ODRBadRequestException();
            if ($datatype->getIsMasterType())
                throw new ODRBadRequestException();
            if ($datatype->getMetadataFor() !== null)
                throw new ODRBadRequestException();


            // --------------------
            /** @var ODRUser $user */
//            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            // Don't actually care about the user here, only displaying public datatypes/datafields
            // --------------------


            // ----------------------------------------
            // Going to need name/description info for all the datafields to be displayed
            $dt_array = $dbi_service->getDatatypeArray($datatype_id, true);    // should contain links

            // Need the list of public, searchable datafields
            $searchable_datafields = $search_service->getSearchableDatafields($datatype_id);

            // Filter it down to the ones that can be searched
            $include_general_search = false;
            $permitted_datafields = array();
            foreach ($searchable_datafields as $dt_id => $dt_data) {
                if ( $dt_data['dt_public_date'] !== '2200-01-01' ) {
                    // Datatype is public, look through its public datafields...
                    foreach ($dt_data['datafields'] as $key => $df_data ) {
                        // Ignore datafields that aren't public or can't be searched on
                        if ( $key === 'non_public' || $df_data['searchable'] === DataFields::NOT_SEARCHED )
                            continue;

                        // Need the datafield's information from the cached datatype array...
                        $df = $dt_array[$dt_id]['dataFields'][$key];
                        $typeclass = $df_data['typeclass'];

                        // Ignore datafields that aren't text/numbers, since other stuff is harder
                        //  to deal with
                        switch ($typeclass) {
                            case 'Boolean':
                            case 'File':
                            case 'Image':
                            case 'Radio':
                            case 'DatetimeValue':
                            case 'Tag':
                                // Creating HTML elements for searching these fields is more
                                //  complicated or requires additional info...don't "volunteer"
                                //  these fields
                            case 'Markdown':
                                // Searching markdown fields is meaningless, skip ahead to the next
                                //  field in this datatype
                                continue 2;
                        }

                        // All datafields that make it to here are valid...they're public, searchable,
                        //  and are text/number fields

                        // If the field is suitable for advanced search...
                        if ( $df_data['searchable'] === DataFields::ADVANCED_SEARCH
                            || $df_data['searchable'] === DataFields::ADVANCED_SEARCH_ONLY
                        ) {
                            // ...then it should be displayed in the interface
                            if ( !isset($permitted_datafields[$dt_id]) )
                                $permitted_datafields[$dt_id] = array();
                            $permitted_datafields[$dt_id][$key] = $df;
                        }

                        // If the field is suitable for general search...
                        if ( $df_data['searchable'] === DataFields::GENERAL_SEARCH
                            || $df_data['searchable'] === DataFields::ADVANCED_SEARCH
                        ) {
                            // ...then need an entry to enable general search
                            $include_general_search = true;
                        }
                    }
                }
            }

            // Also going to need the theme array
            $master_theme = $ti_service->getDatatypeMasterTheme($datatype_id);
            $theme_array = $ti_service->getThemeArray($master_theme->getId());

            // Need to stack both arrays
            $dt_array = array( $datatype_id => $dbi_service->stackDatatypeArray($dt_array, $datatype_id) );
            $theme_array = array( $master_theme->getId() => $ti_service->stackThemeArray($theme_array, $master_theme->getId()) );


            // ----------------------------------------
            /** @var TwigEngine $templating */
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODROpenRepositorySearchBundle:Remote:select_ajax.html.twig',
                    array(
                        'datatype_id' => $datatype_id,
                        'datatype_array' => $dt_array,
                        'theme_id' => $master_theme->getId(),
                        'theme_array' => $theme_array,

                        'include_general_search' => $include_general_search,
                        'permitted_datafields' => $permitted_datafields,
                    )
                )
            );
        }
        catch (\Exception $e) {
            $source = 0x6df67221;
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
     * After the user picks a datatype in startAction, and at least one datafield in selectAction,
     * the final step is to turn all that data into a javascript config blurb.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function configAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // ----------------------------------------
            // Going to use the data in the POST request
            $post = $request->request->all();

            // Need the protocol and the baseurl
            $protocol = $request->getScheme();
            $site_baseurl = $this->container->getParameter('site_baseurl');

            if ( !isset($post['datatype_id']) || !isset($post['datafield_ids']) )
                throw new ODRBadRequestException();

            $datatype_id = intval($post['datatype_id']);
            $datafield_ids = $post['datafield_ids'];
            $datafield_ids = array_flip( array_keys($datafield_ids) );


            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatabaseInfoService $dbi_service */
            $dbi_service = $this->container->get('odr.database_info_service');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            // This controller action only works with public top-level datatypes
            if (!$datatype->isPublic())
                throw new ODRBadRequestException();
            if ($datatype->getId() !== $datatype->getGrandparent()->getId())
                throw new ODRBadRequestException();
            if ($datatype->getIsMasterType())
                throw new ODRBadRequestException();
            if ($datatype->getMetadataFor() !== null)
                throw new ODRBadRequestException();


            // --------------------
//            /** @var ODRUser $user */
//            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            // Don't actually care about the user here, only displaying public datatypes/datafields
            // --------------------


            // Need the datafield info
            $dt_array = $dbi_service->getDatatypeArray($datatype_id, true);    // should contain links
            $datafields = array();

            // If the "general search" checkbox was selected, create an entry for it in the final
            //  array since it won't be in the cached datatype array
            if ( isset($datafield_ids['gen']) )
                $datafields['gen'] = 'general_search';

            // Verify that the provided datafields are searchable and public
            foreach ($dt_array as $dt_id => $dt) {
                if ( isset($dt['dataFields']) ) {
                    foreach ($dt['dataFields'] as $df_id => $df) {
                        // If the user selected this datafield...
                        if ( isset($datafield_ids[$df_id]) ) {
                            $dfm = $df['dataFieldMeta'];

                            $is_searchable = false;
                            if ( $dfm['searchable'] === DataFields::ADVANCED_SEARCH
                                || $dfm['searchable'] === DataFields::ADVANCED_SEARCH_ONLY
                            ) {
                                $is_searchable = true;
                            }

                            $is_public = true;
                            if ( $dfm['publicDate'] === '2200-01-01 00:00:00' )
                                $is_public = false;

                            // ...only save the field if it's both searchable and public
                            if ( $is_searchable && $is_public )
                                $datafields[$df_id] = strtolower(str_replace(' ', '_', $dfm['fieldName']));
                        }
                    }
                }
            }

            // Verify that the submitted post didn't have any non-public or unsearchable fields in it
            if ( count($datafields) !== count($datafield_ids) )
                throw new ODRBadRequestException();


            // ----------------------------------------
            /** @var TwigEngine $templating */
            $templating = $this->get('templating');

            // Need to render this separately...
            $str = $templating->render(
                'ODROpenRepositorySearchBundle:Remote:config_data.html.twig',
                array(
                    'protocol' => $protocol,
                    'baseurl' => $site_baseurl,
                    'datatype_id' => $datatype_id,
                    'search_slug' => $datatype->getSearchSlug(),
                    'datafields' => $datafields,
                )
            );

            // ...so it can get escaped properly
            $return['d'] = array(
                'html' => $templating->render(
                    'ODROpenRepositorySearchBundle:Remote:config_data_wrapper.html.twig',
                    array(
                        'config' => $str
                    )
                )
            );
        }
        catch (\Exception $e) {
            $source = 0x55fd8b23;
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
     * Provides a link to download the actual javascript for the module, in both minified and
     * unminified versions.
     *
     * @param bool $minified
     * @param Request $request
     *
     * @return Response
     */
    public function downloadAction($minified, Request $request)
    {

        try {
            // Need templating to render a couple twig things in the javascript file...
            /** @var TwigEngine $templating */
            $templating = $this->get('templating');

            $js = '';
            if ($minified) {
                $js = $templating->render(
                    'ODROpenRepositorySearchBundle:Remote:odr_remote_search_min.js.twig',
                    array(
                    )
                );
            }
            else {
                $js = $templating->render(
                    'ODROpenRepositorySearchBundle:Remote:odr_remote_search.js.twig',
                    array(
                    )
                );
            }

//            // Ideally, this would be provided to the downloader in a minified state...
//            // TODO - ...or should it be its own entirely separate github repository, with changelogs and shit?
//            if ($minified) {
//                // TODO - how to minify?  kinda cheating right now...
//                $js = $js;
//            }

            // ----------------------------------------
            // Set up a response so the user can download the file
            $response = new Response();

            $response->setPrivate();
            $response->headers->set('Content-Type', 'text/javascript');
            $response->headers->set('Content-Length', strlen($js));

            if ($minified)
                $response->headers->set('Content-Disposition', 'attachment; filename="odr_remote_search.min.js";');
            else
                $response->headers->set('Content-Disposition', 'attachment; filename="odr_remote_search.js";');

            $response->setContent($js);
            return $response;
        }
        catch (\Exception $e) {
            $source = 0x04fadaa8;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

//        $response = new Response($js);
//        $response->headers->set('Content-Type', 'text/javascript');
//        return $response;
    }


    /**
     * Renders pages of examples about implementing/modifying the ODR Remote Search stuff.
     *
     * @param string $type
     * @param Request $request
     *
     * @return Response
     */
    public function examplesAction($type, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // ----------------------------------------
            $site_baseurl = $this->getParameter('site_baseurl');

            // Just need to get twig to render an example
            /** @var TwigEngine $templating */
            $templating = $this->get('templating');

            $template = null;
            if ($type === 'basic1')
                $template = 'ODROpenRepositorySearchBundle:Remote:config_example_basic1.html.twig';
            else if ($type === 'basic2')
                $template = 'ODROpenRepositorySearchBundle:Remote:config_example_basic2.html.twig';
            else if ($type === 'basic3')
                $template = 'ODROpenRepositorySearchBundle:Remote:config_example_basic3.html.twig';
            else if ($type === 'defaults')
                $template = 'ODROpenRepositorySearchBundle:Remote:config_example_adv_extra.html.twig';
            else if ($type === 'alt')
                $template = 'ODROpenRepositorySearchBundle:Remote:config_example_adv_alt.html.twig';

            $template_type = 'basic';
            if ( strpos($type, 'adv') !== false )
                $template_type = 'adv';

            // Need to render the example separately...
            $str = $templating->render(
                $template,
                array(
                    'site_baseurl' => $site_baseurl,
                )
            );

            // ...so it can get escaped properly
            $return['d'] = array(
                'html' => $templating->render(
                    'ODROpenRepositorySearchBundle:Remote:config_example_wrapper.html.twig',
                    array(
                        'site_baseurl' => $site_baseurl,
                        'template_type' => $template_type,
                        'str' => $str
                    )
                )
            );
        }
        catch (\Exception $e) {
            $source = 0xee3fe022;
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
