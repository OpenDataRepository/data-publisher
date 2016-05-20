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

// Entities
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\Theme;
use ODR\OpenRepository\UserBundle\Entity\User;
// Forms
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;


class XSDController extends ODRCustomController
{

    /**
     * Writes an XSD file describing a datatype to the xsd directory on the server.
     * 
     * @param integer $datatype_id The datatype that is having its schema built.
     * @param Request $request
     * 
     * @return Response
     */
    public function builddatatypeAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                return parent::deletedEntityError('Datatype');

            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->findOneBy( array('dataType' => $datatype->getId(), 'themeType' => 'master') );
            if ($theme == null)
                return parent::deletedEntityError('Theme');


            // Ensure directory exists
            $xsd_export_path = dirname(__FILE__).'/../../../../web/uploads/xsd/';
            if ( !file_exists($xsd_export_path) )
                mkdir( $xsd_export_path );

            //$filename = $datatype->getXmlShortName().'.xsd';
            $filename = $datatype->getShortName().'.xsd';
            $handle = fopen($xsd_export_path.$filename, 'w');

            if ($handle !== false) {
                // Build the schema and write it to the disk
                $schema = self::XSD_GetDisplayData($em, $datatype_id, $request);
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
     * Sidesteps Symfony to permit a browser to download an XSD file.
     * 
     * @param integer $datatype_id Which DataType to download the schema for.
     * @param Request $request
     * 
     * @return Response
     */
    public function downloadXSDAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        $response = new StreamedResponse();

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                return parent::deletedEntityError('Datatype');

            $xsd_export_path = dirname(__FILE__).'/../../../../web/uploads/xsd/';
            $filename = $datatype->getXmlShortName().'.xsd';

            $handle = fopen($xsd_export_path.$filename, 'r');
            if ($handle !== false) {
                // Set up a response to send the file back
                $response->setPrivate();
                $response->headers->set('Content-Type', mime_content_type($xsd_export_path.$filename));
                $response->headers->set('Content-Disposition', 'attachment;filename="'.$filename.'"');
                $response->headers->set('Content-length', filesize($xsd_export_path.$filename));

//                $response->sendHeaders();

                // Use symfony's StreamedResponse to send the decrypted file back in chunks to the user
                $response->setCallback(function() use ($handle) {
                    while ( !feof($handle) ) {
                        $buffer = fread($handle, 65536);    // attempt to send 64Kb at a time
                        echo $buffer;
                        flush();
                    }
                    fclose($handle);
                });
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
     * @param \Doctrine\ORM\EntityManager $em
     * @param integer $datatype_id
     * @param Request $request
     *
     * @return string
     */
    private function XSD_GetDisplayData($em, $datatype_id, Request $request)
    {
        // All of these should already exist
        /** @var DataType $datatype */
        $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
        /** @var Theme $theme */
        $theme = $em->getRepository('ODRAdminBundle:Theme')->findOneBy( array('dataType' => $datatype->getId(), 'themeType' => 'master') );

        $memcached = $this->get('memcached');
        $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
        $memcached_prefix = $this->container->getParameter('memcached_key_prefix');

        // ----------------------------------------

        // Always bypass cache if in dev mode?
        $bypass_cache = false;
        if ($this->container->getParameter('kernel.environment') === 'dev')
            $bypass_cache = true;


        // ----------------------------------------
        // Determine which datatypes/childtypes to load from the cache
        $include_links = false;
        $associated_datatypes = parent::getAssociatedDatatypes($em, array($datatype_id), $include_links);

//print '<pre>'.print_r($associated_datatypes, true).'</pre>'; exit();

        // Grab the cached versions of all of the associated datatypes, and store them all at the same level in a single array
        $datatree_array = parent::getDatatreeArray($em, $bypass_cache);

//print '<pre>'.print_r($datatree_array, true).'</pre>'; exit();

        $datatype_array = array();
        foreach ($associated_datatypes as $num => $dt_id) {
            $datatype_data = $memcached->get($memcached_prefix.'.cached_datatype_'.$dt_id);
            if ($bypass_cache || $datatype_data == null)
                $datatype_data = parent::getDatatypeData($em, $datatree_array, $dt_id, $bypass_cache);

            foreach ($datatype_data as $dt_id => $data)
                $datatype_array[$dt_id] = $data;
        }

//print '<pre>'.print_r($datatype_data, true).'</pre>'; exit();

        // ----------------------------------------
        // Render the schema layout
        $templating = $this->get('templating');
        $html = $templating->render(
            'ODRAdminBundle:XSDCreate:xsd_ajax.html.twig',
            array(
                'datatype_array' => $datatype_array,
                'initial_datatype_id' => $datatype_id,
                'theme_id' => $theme->getId(),
            )
        );

        return $html;
    }

}
