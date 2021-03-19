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
use ODR\AdminBundle\Component\Service\DatatypeInfoService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
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

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
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
            $metadata = $dti_service->getDatarecordCounts($top_level_datatypes, $datatype_permissions);


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

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var PermissionsManagementService $pm_service */
//            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchService $search_service */
            $search_service = $this->container->get('odr.search_service');

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
            // Need the list of public, searchable datafields
            $searchable_datafields = $search_service->getSearchableDatafields($datatype_id);

            // Filter it down to the ones that can be searched
            $permitted_datafields = array();
            foreach ($searchable_datafields[$datatype_id]['datafields'] as $key => $df) {
                if ( $key === 'non_public' || $df['searchable'] === DataFields::NOT_SEARCHED ) {
                    // Not a valid datafield
                }
                else {
                    $typeclass = $df['typeclass'];
                    switch ($typeclass) {
                        case 'ShortVarchar':
                        case 'MediumVarchar':
                        case 'LongVarchar':
                        case 'LongText':
                        case 'IntegerValue':
                        case 'DecimalValue':
                            $permitted_datafields[] = $key;
                            break;

                        default:
                            // TODO - allow other fieldtypse?  bool, date, radio, tag, etc
                            // Not a valid datafield
                            break;
                    }
                }
            }

            // The list above only has datafield ids and typeclasses...need name/description info too
            $dt_array = $dti_service->getDatatypeArray($datatype_id, false);    // don't want links


            // ----------------------------------------
            /** @var TwigEngine $templating */
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODROpenRepositorySearchBundle:Remote:select.html.twig',
                    array(
                        'datatype_id' => $datatype_id,
                        'dt_array' => $dt_array[$datatype_id],
                        'permitted_datafields' => $permitted_datafields
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

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');

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
            /** @var ODRUser $user */
//            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            // Don't actually care about the user here, only displaying public datatypes/datafields
            // --------------------


            // Need the datafield info
            $dt_array = $dti_service->getDatatypeArray($datatype_id, false);    // don't want links

            // Verify that the provided datafields are searchable and public
            $datafields = array();
            foreach ($datafield_ids as $df_id => $num) {
                if ( !isset($dt_array[$datatype_id]['dataFields'][$df_id]) )
                    throw new ODRBadRequestException();

                $df = $dt_array[$datatype_id]['dataFields'][$df_id];
                $dfm = $df['dataFieldMeta'];

                if ( $dfm['searchable'] === DataFields::NOT_SEARCHED || $dfm['publicDate'] === '2200-01-01 00:00:00' ) {
                    // Silently ignore illegal datafields
//                    throw new ODRBadRequestException();
                    continue;
                }


                // TODO - need to use something other than the field name...
                $datafields[$df_id] = strtolower(str_replace(' ', '_', $dfm['fieldName']));
            }


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
}
