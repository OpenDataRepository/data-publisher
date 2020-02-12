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
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRConflictException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\AdminBundle\Component\Service\FakeRecordService;
use ODR\AdminBundle\Component\Service\ODRRenderService;
use ODR\AdminBundle\Component\Service\ODRTabHelperService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchService;
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


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

            // Metadata datatypes should already have their top-level datarecord from elsewhere
            if ( !is_null($datatype->getMetadataFor()) )
                throw new ODRBadRequestException('Unable to create a fake record for a metadata datatype');


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
            // Ensure the post data is valid first
            $post = $request->request->all();
//print_r($post);  exit();

            if ( !isset($post['datatype_id'])
                || !isset($post['datarecord_id'])
//                || !isset($post['datafields'])    // datafields won't exist if no fields are required and user doesn't enter anything
                || !isset($post['tokens'])
            ) {
                throw new ODRBadRequestException();
            }

            // TODO - parent/grandparent datarecord ids so this works for child records?
            $datatype_id = $post['datatype_id'];
//            $tmp_dr_id = $post['datarecord_id'];
            $csrf_tokens = $post['tokens'];

            $datafields = array();
            if ( isset($post['datafields']) )
                $datafields = $post['datafields'];

            if ( !is_numeric($datatype_id)
                || !is_array($datafields)
                || !is_array($csrf_tokens)
            ) {
                throw new ODRBadRequestException();
            }


            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var FakeRecordService $fake_record_service */
            $fake_record_service = $this->container->get('odr.fake_record_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


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

            // Metadata datatypes should already have their top-level datarecord from elsewhere
            if ( !is_null($datatype->getMetadataFor()) )
                throw new ODRBadRequestException('Unable to save to a metadata datatype');


            // Validate the fake record's data, if possible
            $fake_record_service->verifyFakeRecord($post, $datatype, $user);
            // If no error thrown, commit the data
            $new_datarecord = $fake_record_service->commitFakeRecord($post, $datatype, $user);


            // The new record was created, return its id
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
            /** @var SearchService $search_service */
            $search_service = $this->container->get('odr.search_service');


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

            // Metadata datatypes should already have their top-level datarecord from elsewhere
            if ( !is_null($datatype->getMetadataFor()) )
                throw new ODRBadRequestException('Unable to save to a metadata datatype');


            // Datafield needs to be unique for this to make sense
            if ( !$datafield->getIsUnique() )
                throw new ODRBadRequestException();

            if ( $search_service->valueAlreadyExists($datafield, $value) )
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
