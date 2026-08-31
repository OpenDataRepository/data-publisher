<?php

/**
 * Open Data Repository Data Publisher
 * JSmolTrigger Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The American Mineralogist Crystal Structure database (AMCSD) originally had the ability to render
 *  CIF files with JSmol (https://jmol.sourceforge.net/) (https://wiki.jmol.org/index.php/JSmol).
 *
 * This controller is called from a clickable icon in a file datafield's header, rendered by the
 * JSmol Trigger Addon (odr_plugins.rruff.jsmol_trigger)...it returns what is required to use JSmol
 * in one of ODR's remodals
 */

namespace ODR\OpenRepository\GraphBundle\Controller;

// Controllers/Classes
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\AdminBundle\Controller\ODRCustomController;
// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\File;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Events
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\AdminBundle\Component\Service\CryptoService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\OpenRepository\GraphBundle\Plugins\RRUFF\JSmolTriggerPlugin;
// Symfony
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


class JSmolTriggerController extends ODRCustomController
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
        private readonly CryptoService $crypto_service,
    ) {
        parent::__construct($clone_theme_service, $database_info_service, $datarecord_info_service, $datatree_info_service, $entity_meta_modify_service, $render_service, $tab_helper_service, $permissions_management_service, $table_theme_helper_service, $theme_info_service, $search_service, $search_key_service);
    }

    /**
     * TODO
     *
     * @param integer $dr_id
     * @param integer $df_id
     * @param Request $request
     * @return Response
     */
    public function triggerAction($dr_id, $df_id, Request $request)
    {
        $return = [];
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = [];

        try {
            // Load required objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->container->get('doctrine')->getManager();

            /** @var CryptoService $crypto_service */
            $crypto_service = $this->crypto_service;
            /** @var DatabaseInfoService $database_info_service */
            $database_info_service = $this->database_info_service;
            /** @var DatarecordInfoService $datarecord_info_service */
            $datarecord_info_service = $this->datarecord_info_service;
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->permissions_management_service;
            /** @var \Twig\Environment $templating */
            $templating = $this->container->get('twig');
            /** @var LoggerInterface $logger */
            $logger = $this->container->get('logger');


            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODR\AdminBundle\Entity\DataRecord')->find($dr_id);
            if ($datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODR\AdminBundle\Entity\DataFields')->find($df_id);
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');

            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');
            if ( $datarecord->getDataType()->getId() !== $datafield->getDataType()->getId() )
                throw new ODRNotFoundException('Datatype');

            // ----------------------------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()?->getUser() ?? 'anon.';   // <-- will return 'anon.' when nobody is logged in

            // Ensure the user is allowed to view the Datafield
            if ( !$permissions_service->canViewDatafield($user, $datafield) )
                throw new ODRForbiddenException();
            // ----------------------------------------

            // Only activate on single-upload file datafields
            if ( $datafield->getFieldType()->getTypeClass() !== 'File' )
                throw new ODRNotFoundException('Datafield');
            if ( $datafield->getAllowMultipleUploads() == true )
                throw new ODRNotFoundException('Datafield');

            // Only activate if the datafield has the correct render plugin
            $dt_array = $database_info_service->getDatatypeArray($datatype->getId(), false);  // don't want links
            $dt = $dt_array[$datatype->getId()];
            $dr_array = $datarecord_info_service->getDatarecordArray($datarecord->getId(), false);  // don't want links
            $dr = $dr_array[$datarecord->getId()];

            $options = null;
            if ( isset($dt['dataFields'][$datafield->getId()]) ) {
                $df = $dt['dataFields'][$datafield->getId()];
                foreach ($df['renderPluginInstances'] as $rpi_id => $rpi) {
                    if ( $rpi['renderPlugin']['pluginClassName'] === 'odr_plugins.rruff.jsmol_trigger' ) {
                        if ( isset($rpi['renderPluginOptionsMap']) )
                            $options = $rpi['renderPluginOptionsMap'];
                        break;
                    }
                }
            }
            if ( is_null($options) )
                throw new ODRBadRequestException('Datafield is not using JSmol Trigger Plugin');

            // Ensure defaults exist for this...
            if ( !isset($options['jsmol_config']) )
                $options['jsmol_config'] = "packed; unitcell on; set axesUnitcell; axes on;";
            if ( !isset($options['background']) )
                $options['background'] = "#4F4F4F";
            if ( !isset($options['height']) )
                $options['height'] = "600px";
            if ( !isset($options['width']) )
                $options['width'] = "600px";

            // Slightly easier if the (single) file uploaded to this field is hydrated
            $file = null;
            if ( isset($dr['dataRecordFields'][$datafield->getId()]['file'][0]['id']) ) {
                $file_id = $dr['dataRecordFields'][$datafield->getId()]['file'][0]['id'];

                /** @var File $file */
                $file = $em->getRepository('ODR\AdminBundle\Entity\File')->find($file_id);
                if ($file == null)
                    throw new ODRNotFoundException('File');

                // Files that aren't done encrypting shouldn't be downloaded
                if ($file->getEncryptKey() === '')
                    throw new ODRNotFoundException('File');

                // For this action specifically, ensure the file is public
                if ( !$file->isPublic() )
                    throw new ODRBadRequestException('File must be public');
            }


            // Don't actually need to instantiate the plugin
//            /** @var ContainerInterface $service_container */
//            $service_container = $this->container->get('service_container');
//            /** @var JSmolTriggerPlugin $plugin_service */
//            $plugin_service = $service_container->get('odr_plugins.rruff.jsmol_trigger');

            // JSmol requires dynamic javascript loading...
            $site_baseurl = $this->getParameter('site_baseurl');
            // ...which necessitates NOT using the wordpress_site_baseurl parameter
//            if ( $this->getParameter('odr_wordpress_integrated') )
//                $site_baseurl = $this->getParameter('wordpress_site_baseurl');


            // ...and also ensure the file is decrypted, so JSmol can load it directly
            $local_filename = $file->getLocalFileName();
            if ( !str_starts_with($local_filename, '/') )
                $local_filename = '/'.$local_filename;

            $odr_web_directory = $this->getParameter('odr_web_directory');
            if ( !file_exists($odr_web_directory.$local_filename) ) {
                // File does not exist on the server...decrypt it
                // Don't need random filename shennanigans, because the file is guaranteed to be public
                $crypto_service->decryptFile($file->getId());
            }

            $return['d'] = $templating->render(
                '@ODROpenRepositoryGraph/RRUFF/JSmolTrigger/jsmol_trigger_remodal_content.html.twig',
                [
                    'site_baseurl' => $site_baseurl,
                    'local_filename' => $local_filename,
                    'file_id' => $file->getId(),

                    'options' => $options,
                ]
            );
        }
        catch (\Exception $e) {
            $source = 0x38e0118c;
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
