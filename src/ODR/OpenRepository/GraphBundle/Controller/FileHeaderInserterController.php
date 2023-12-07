<?php

/**
 * Open Data Repository Data Publisher
 * FileHeaderInserter Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * All of the details and explanations of this (filthy, honestly) FileHeaderInserter plugin can be
 * read at /src/ODR/OpenRepository/GraphBundle/Plugins/Base/FileHeaderInserterPlugin.php
 *
 * In there, there's a mention that users need to be able to force a rebuild of the headers from
 * Edit mode...this controller is what allows that to happen.
 */

namespace ODR\OpenRepository\GraphBundle\Controller;

// Controllers/Classes
use ODR\AdminBundle\Controller\ODRCustomController;
// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataRecordFields;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Events
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\OpenRepository\GraphBundle\Plugins\Base\FileHeaderInserterPlugin;
// Symfony
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


class FileHeaderInserterController extends ODRCustomController
{

    /**
     * Forces a rebuild of the headers for all files uploaded to this drf entry.
     *
     * @param integer $dr_id
     * @param integer $df_id
     * @param Request $request
     *
     * @return Response
     */
    public function rebuildAction($dr_id, $df_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // Load required objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatabaseInfoService $dbi_service */
            $dbi_service = $this->container->get('odr.database_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var Logger $logger */
            $logger = $this->container->get('logger');


            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($dr_id);
            if ($datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($df_id);
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ( !is_null($datatype->getDeletedAt()) )
                throw new ODRNotFoundException('Datatype');

            /** @var DataRecordFields $drf */
            $drf = $em->getRepository('ODRAdminBundle:DataRecordFields')->findOneBy(
                array(
                    'dataRecord' => $datarecord->getId(),
                    'dataField' => $datafield->getId(),
                )
            );
            // If no files are uploaded, then this drf entry can legitimately be null


            // ----------------------------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in

            // Ensure the user is allowed to edit the datafield
            if ( !$pm_service->canEditDatafield($user, $datafield) )
                throw new ODRForbiddenException();
            // ----------------------------------------


            // ----------------------------------------
            // Ensure the datafield is using the correct render plugin
            $plugin_classname = null;

            $dt_array = $dbi_service->getDatatypeArray($datatype->getGrandparent()->getId(), false);    // don't want links

            $dt = $dt_array[$datatype->getId()];
            if ( isset($dt['dataFields']) && isset($dt['dataFields'][$datafield->getId()]) ) {
                $df = $dt['dataFields'][$datafield->getId()];
                if ( isset($df['renderPluginInstances']) ) {
                    foreach ($df['renderPluginInstances'] as $rpi_id => $rpi) {
                        if ( $rpi['renderPlugin']['pluginClassName'] === 'odr_plugins.base.file_header_inserter' ) {
                            // Datafield is using the correct plugin...
                            $plugin_classname = $rpi['renderPlugin']['pluginClassName'];
                        }
                    }
                }
            }

            if ( is_null($plugin_classname) )
                throw new ODRBadRequestException('Datafield is not using the FileHeaderInserter Render Plugin');

            // Loading the service makes sense now that we know the datafield is using the plugin
            /** @var FileHeaderInserterPlugin $plugin_service */
            $plugin_service = $this->container->get('odr_plugins.base.file_header_inserter');


            // ----------------------------------------
            if ( $drf == null ) {
                /* No drf exists, so there can't be any files uploaded here...nothing to do */
                $logger->debug('No files to rebuild the file headers for in datafield '.$datafield->getId().' datarecord '.$datarecord->getId(), array(self::class, 'rebuildAction()'));
            }
            else {
                // ----------------------------------------
                // Technically can do this without a try/catch block, but no real reason not to use one
                try {
                    // Since there are at least three places where file headers need to be dealt with,
                    //  it's better to have the render plugin do all the work

                    // This particular place, however, is allowed to throw exceptions to notify
                    //  the user of issues...the events are not
                    $notify_user = true;
                    $plugin_service->executeOnFileDatafield($drf, $user, $notify_user);
                }
                catch (\Exception $e) {
                    // Can't really display the error to the user yet, but can log it...
                    $logger->debug('-- (ERROR) '.$e->getMessage(), array(self::class, 'rebuildAction()', 'drf '.$drf->getId()));

                    // Since this isn't a background job or an event, however, the suspected reason
                    //  for the problem can get displayed to the user
                    throw $e;
                }
            }
        }
        catch (\Exception $e) {
            $source = 0x17e250dc;
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
