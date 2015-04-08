<?php

/**
* Open Data Repository Data Publisher
* XSD Controller
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* The XSD controller builds the XSD schema definitions for
* the various entites in the database that need a schema to
* validate XML imports.  It also provides users the ability
* to download these XSD schemas.
*
* TODO - no download action for radio_option xsd
*/

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entites
use ODR\AdminBundle\StoredRecord;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeDataField;
use ODR\AdminBundle\Entity\ThemeDataType;
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTree;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataRecordFields;
use ODR\AdminBundle\Entity\ShortVarchar;
use ODR\AdminBundle\Entity\Image;
use ODR\AdminBundle\Entity\ImageSizes;
use ODR\AdminBundle\Entity\ImageStorage;
use ODR\AdminBundle\Entity\RadioOption;
use ODR\AdminBundle\Entity\RadioSelection;
// Forms
use ODR\AdminBundle\Form\DatafieldsForm;
use ODR\AdminBundle\Form\DatatypeForm;
use ODR\AdminBundle\Form\UpdateDataFieldsForm;
use ODR\AdminBundle\Form\UpdateDataTypeForm;
use ODR\AdminBundle\Form\ShortVarcharForm;
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\UploadedFile;


class XSDController extends ODRCustomController
{


    /**
     * Writes an XSD file describing a datatype to the xsd directory on the server.
     * 
     * @param integer $datatype_id The datatype that is having its schema built.
     * @param Request $request
     * 
     * @return a Symfony JSON response containing TODO
     */
    public function builddatatypeAction($datatype_id, Request $request) {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $repo = $em->getRepository('ODRAdminBundle:DataType');
            $datatype = $repo->find($datatype_id);
            if ($datatype == null)
                return parent::deletedEntityError('DataType');


            $xsd_export_path = dirname(__FILE__).'/../../../../web/uploads/xsd/';
            $filename = $datatype->getXMLShortName().'.xsd';

            $handle = fopen($xsd_export_path.$filename, 'w');
            if ($handle !== false) {
                // Build the schema and write it to the disk
                $schema = self::datatype_GetDisplayData($request, $datatype_id);
                fwrite($handle, $schema);

                fclose($handle);

                $templating = $this->get('templating');
                $return['d'] = array(
                    'html' => $templating->render('ODRAdminBundle:XSDCreate:xsd_download.html.twig', array('datatype_id' => $datatype_id))
                );

            }
            else {
                // Shouldn't be an issue?
                $return['r'] = 1;
                $return['t'] = 'ex';
                $return['d'] = 'Error 0x232819234 Could not open file at "'.$xsd_export_path.$filename.'"';
            }
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x233282314 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Writes an XSD file describing a radio_option datafield to the xsd directory on the server.
     * 
     * @param integer $datafield_id The DataField that is having its schema built.
     * @param Request $request
     * 
     * @return TODO
     */
    public function buildradiooptionAction($datafield_id, Request $request) {
        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {
            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $repo = $em->getRepository('ODRAdminBundle:DataFields');
            $datafield = $repo->find($datafield_id);
            $datatype = $datafield->getDataType();
            if ($datatype == null)
                return parent::deletedEntityError('DataType');


            $xsd_export_path = dirname(__FILE__).'/../../../../web/uploads/xsd/';
            $filename = 'Datafield_'.$datafield_id.'.xsd';

            $handle = fopen($xsd_export_path.$filename, 'w');
            if ($handle !== false) {
                // Build the schema and write it to the disk
                $schema = self::radiooption_GetDisplayData($request, $datafield_id);
                fwrite($handle, $schema);

                fclose($handle);
/*
                $templating = $this->get('templating');
                $return['d'] = array(
                    'html' => $templating->render('ODRAdminBundle:XSDCreate:xsd_download.html.twig', array('datatype_id' => $datatype_id))
                );
*/
            }
            else {
                // Shouldn't be an issue?
                $return['r'] = 1;
                $return['t'] = 'ex';
                $return['d'] = 'Error 0x232139325 Could not open file at "'.$xsd_export_path.$filename.'"';
            }
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x232812235 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Sidesteps Symfony to permit a browser to download an XSD file.
     * 
     * @param integer $datatype_id Which DataType to download the schema for.
     * @param Request $request
     * 
     * @return TODO
     */
    public function downloadXSDAction($datatype_id, Request $request) {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        $response = new Response();

        try {
            $datatype = $this->getDoctrine()->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                return parent::deletedEntityError('DataType');

            $xsd_export_path = dirname(__FILE__).'/../../../../web/uploads/xsd/';
            $filename = $datatype->getXMLShortName().'.xsd';

            $handle = fopen($xsd_export_path.$filename, 'r');
            if ($handle !== false) {
                // Set up a response to send the file back
                $response->setPrivate();
                $response->headers->set('Content-Type', mime_content_type($xsd_export_path.$filename));
                $response->headers->set('Content-Disposition', 'attachment;filename="'.$filename.'"');
                $response->headers->set('Content-length', filesize($xsd_export_path.$filename));

                $response->sendHeaders();

                $content = file_get_contents($xsd_export_path.$filename);   // using file_get_contents() because apparently readfile() appends # of bytes read when firefox is used
                $response->setContent($content);

                fclose($handle);
            }
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x841728657 ' . $e->getMessage();
        }

        if ($return['r'] !== 0) {
            // If error encountered, do a json return
            $response = new Response(json_encode($return));
            $response->headers->set('Content-Type', 'application/json');
            return $response;
        }
        else {
            // Return the previously created response
            return $response;
        }

    }


    /**
     * Renders and returns the XSD schema definition for a DataType.
     *
     * @param Request $request
     * @param integer $datatype_id  Which DataType is having its schema written.
     * @param string $template_name 
     *
     * @return string
     */
    private function datatype_GetDisplayData(Request $request, $datatype_id, $template_name = 'default')
    {
        // Required objects
        $em = $this->getDoctrine()->getManager();
        $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
        $theme = $em->getRepository('ODRAdminBundle:Theme')->find(1);
        $templating = $this->get('templating');

        $user = $this->container->get('security.context')->getToken()->getUser();

        $datatype = null;
        $theme_element = null;
        if ($datatype_id !== null) {
            $datatype = $repo_datatype->find($datatype_id);
        }

        $indent = 0;
        $is_link = 0;
        $top_level = 1;
        $short_form = false;

$debug = true;
$debug = false;
if ($debug)
    print '<pre>';

            $tree = parent::buildDatatypeTree($user, $theme, $datatype, $theme_element, $em, $is_link, $top_level, $short_form, $debug, $indent);

if ($debug)
    print '</pre>';

        $template = 'ODRAdminBundle:XSDCreate:xsd_ajax.html.twig';

        $html = $templating->render(
            $template,
            array(
                'datatype_tree' => $tree,
            )
        );

        return $html;
    }


    /**
     * Renders and returns the XSD schema definition for a radio_option DataField.
     *
     * @param Request $request
     * @param integer $datafield_id
     *
     * @return string
     */
    private function radiooption_GetDisplayData(Request $request, $datafield_id)
    {
        // Required objects
        $em = $this->getDoctrine()->getManager();
        $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);

        $templating = $this->get('templating');
        $template = 'ODRAdminBundle:XSDCreate:xsd_radio_option.html.twig';

        $html = $templating->render(
            $template,
            array(
                'datafield' => $datafield,
            )
        );

        return $html;

    }
}
