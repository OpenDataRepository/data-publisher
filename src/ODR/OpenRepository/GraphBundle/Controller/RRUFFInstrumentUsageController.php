<?php

/**
 * Open Data Repository Data Publisher
 * RRUFF Instrument Usage Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * In order to avoid dealing with caching or event bullshit, it's considerably easier for the element
 * created by the RRUFFInstrumentUsage plugin to trigger a two-step process to build a search results
 * URL for "RRUFF Samples that have a specific RRUFF Instrument Name".
 *
 * This isn't necessarily true for non-table layouts, but might as well use the same scheme for both.
 */

namespace ODR\OpenRepository\GraphBundle\Controller;

// Controllers/Classes
use ODR\AdminBundle\Controller\ODRCustomController;
// Entities
use ODR\AdminBundle\Entity\DataRecord;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchKeyService;
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Router;
use Symfony\Component\Templating\EngineInterface;


class RRUFFInstrumentUsageController extends ODRCustomController
{

    /**
     * The plugin generates a (static-ish) link to this controller action.
     *
     * The action then proceeds to generate the desired search link...which couldn't be done in
     * table layouts without either a) forcing a onPostUpdate check so changes to Instrument Name
     * will delete table cache entries or b) forcing table layouts to have plugin-provided "global"
     * javascript.
     *
     * @param int $datarecord_id
     * @param Request $request
     *
     * @return Response
     */
    public function redirectAction($datarecord_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatabaseInfoService $database_info_service */
            $database_info_service = $this->container->get('odr.database_info_service');
            /** @var DatarecordInfoService $datarecord_info_service */
            $datarecord_info_service = $this->container->get('odr.datarecord_info_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');
            /** @var Router $router */
            $router = $this->get('router');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');


            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ($datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('DataType');


            // ----------------------------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in

            if ( !$permissions_service->canViewDatarecord($user, $datarecord) )
                throw new ODRForbiddenException();
            // ----------------------------------------

            // Going to use the cached arrays for this...
            $datatype_array = $database_info_service->getDatatypeArray($datatype->getId(), false);
            $datarecord_array = $datarecord_info_service->getDatarecordArray($datarecord_id, false);

            // Since this is a highly specialized plugin, brute-forcing the fields to find the one
            //  using this plugin is viable
            $render_plugin_instance = null;
            foreach ($datatype_array[$datatype->getId()]['dataFields'] as $df_id => $df) {
                if ( isset($df['renderPluginInstances']) ) {
                    foreach ($df['renderPluginInstances'] as $rpi_id => $rpi) {
                        if ( $rpi['renderPlugin']['pluginClassName'] === 'odr_plugins.rruff.instrument_usage' ) {
                            $render_plugin_instance = $rpi;
                            break;
                        }
                    }
                }
            }
            if ( is_null($render_plugin_instance) )
                throw new ODRNotFoundException('Field is not using the odr_plugins.rruff.instrument_usage plugin');


            // Need to use the contents of the Instrument Name field to generate a search key
            $options = $render_plugin_instance['renderPluginOptionsMap'];
            $target_datatype_id = $options['target_datatype'];
            $instrument_name_df_id = $options['source_datafield'];

            $instrument_name_df_value = '';
            if ( isset($datarecord_array[$datarecord_id]['dataRecordFields'][$instrument_name_df_id]['longVarchar'][0]) )
                $instrument_name_df_value = $datarecord_array[$datarecord_id]['dataRecordFields'][$instrument_name_df_id]['longVarchar'][0]['value'];

            $search_params = array(
                'dt_id' => $target_datatype_id,
                $instrument_name_df_id => '"'.$instrument_name_df_value.'"',
            );
            $search_key = $search_key_service->encodeSearchKey($search_params);

            // The search key can then be part of a route...
            $url = $router->generate(
                'odr_search_render',
                array(
                    'search_theme_id' => 0,
                    'search_key' => $search_key,
                )
            );

            // ...that the page can render directly
            $return['d'] = array(
                'datatype_id' => $datatype->getId(),
                'html' => $templating->render(
                    'ODROpenRepositoryGraphBundle:RRUFF:RRUFFInstrumentUsage/page.html.twig',
                    array(
                        'url' => $url
                    )
                )
            );

        }
        catch (\Exception $e) {
            $source = 0x87b967aa;
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
