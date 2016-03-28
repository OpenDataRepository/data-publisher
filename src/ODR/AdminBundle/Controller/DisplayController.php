<?php

/**
* Open Data Repository Data Publisher
* Results Controller
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* The results controller displays actual record results to the 
* user. These results may be pulled from memcached or directly
* rendered if no cached copy exists.  It also handles file and
* image downloads because of routing constraints within Symfony.
*
*/

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entites
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeDataField;
use ODR\AdminBundle\Entity\ThemeDataType;
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTree;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataRecordFields;
use ODR\AdminBundle\Entity\ShortVarchar;
use ODR\AdminBundle\Entity\MediumVarchar;
use ODR\AdminBundle\Entity\LongVarchar;
use ODR\AdminBundle\Entity\LongText;
use ODR\AdminBundle\Entity\DecimalValue;
use ODR\AdminBundle\Entity\IntegerValue;
use ODR\AdminBundle\Entity\Image;
use ODR\AdminBundle\Entity\ImageSizes;
use ODR\AdminBundle\Entity\ImageStorage;
use ODR\AdminBundle\Entity\File;
// Forms
use ODR\AdminBundle\Form\DatafieldsForm;
use ODR\AdminBundle\Form\DatatypeForm;
use ODR\AdminBundle\Form\UpdateDataFieldsForm;
use ODR\AdminBundle\Form\UpdateDataTypeForm;
use ODR\AdminBundle\Form\ShortVarcharForm;
use ODR\AdminBundle\Form\MediumVarcharForm;
use ODR\AdminBundle\Form\LongVarcharForm;
use ODR\AdminBundle\Form\LongTextForm;
use ODR\AdminBundle\Form\DecimalValueForm;
use ODR\AdminBundle\Form\IntegerValueForm;
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Doctrine\ORM\Query\ResultSetMappingBuilder;

//use Symfony\Co+mponent\Security\Core\Exception\AccessDeniedException;


class DisplayController extends ODRCustomController
{

    private function GetDataRecordData($datarecord_id) {

        $em = $this->getDoctrine()->getManager();

        $memcached = $this->get('memcached');
        $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
        $memcached_prefix = $this->container->getParameter('memcached_key_prefix');

        $t0 = microtime(true);
        // Query to retrieve all records by grandparent
        $query = $em->createQuery(
            'SELECT
                       odr, p, gp, odt, orp, orpf, orpi, orpo, orpm,  odrf, ob, odv, off,
                       oi, oip, ois, oiv, olt, olvc, om, odrs, odrsro, osvc, odf, oro
                   FROM
                     ODRAdminBundle:DataRecord odr
                     LEFT JOIN odr.parent p
                     LEFT JOIN odr.grandparent gp
                     LEFT JOIN odr.dataRecordFields odrf
                     LEFT JOIN odrf.boolean ob
                     LEFT JOIN odrf.decimalValue odv
                     LEFT JOIN odrf.file off
                     LEFT JOIN odrf.image oi
                     LEFT JOIN oi.parent oip
                     LEFT JOIN oi.imageSize ois
                     LEFT JOIN odrf.integerValue oiv
                     LEFT JOIN odrf.longText olt
                     LEFT JOIN odrf.longVarchar olvc
                     LEFT JOIN odrf.mediumVarchar om
                     LEFT JOIN odrf.radioSelection odrs
                     LEFT JOIN odrs.radioOption odrsro
                     LEFT JOIN odrf.shortVarchar osvc
                     LEFT JOIN odr.dataType odt
                     LEFT JOIN odrf.dataField odf
                     LEFT JOIN odf.radioOptions oro
                     LEFT JOIN odt.renderPlugin orp
                     LEFT JOIN orp.renderPluginFields orpf
                     LEFT JOIN orp.renderPluginInstance orpi
                     LEFT JOIN orpi.renderPluginOptions orpo
                     LEFT JOIN orpi.renderPluginMap orpm
                   WHERE
                       odr.grandparent = :grandparent_id
                       AND odr.deletedAt IS NULL
                       AND odrf.deletedAt IS NULL
                       AND ((orpi.dataField IS NULL AND orpi.dataType IS NULL) OR (orpi.dataType = odt.id and orpi.dataField IS NULL) OR (orpi.dataField = odrf.id AND orpi.dataType IS NULL))
                       AND (orpm.renderPluginFields = orpf.id OR orpm.renderPluginFields IS NULL)
                       '
        )->setParameters(array('grandparent_id' => $datarecord_id));

        /*
         *      orp, orpf, orpi, orpo, orpm
                       AND ((orpi.dataField IS NULL AND orpi.dataType IS NULL) OR (orpi.dataType = odt.id and orpi.dataField IS NULL) OR (orpi.dataField = odrf.id AND orpi.dataType IS NULL))
                       AND (orpm.renderPluginFields = orpf.id OR orpm.renderPluginFields IS NULL)
         */

        $data_record_data = $query->getArrayResult();

        $t1 = microtime(true);
        $diff = $t1 - $t0;

        // Reformat result to fit model  array[parent][datatypes]
        $data_record_by_parent = array();

        // Also build array of unique parent ids to use in linked query
        foreach($data_record_data as $dr) {
            $parent_id = $dr['parent']['id'];
            // Check if parent id is set in array
            if (!array_key_exists($parent_id, $data_record_by_parent) || !isset($data_record_by_parent[$parent_id])) {
                $data_record_by_parent[$parent_id] = array();
            }
            // Add data type under parent
            $data_record_by_parent[$parent_id][$dr['id']] = $dr;
        }

        // Store Cache Entry
        $memcached->set($memcached_prefix . '.data_array_cache_' . $datarecord_id, $data_record_by_parent, 0);

        return $data_record_by_parent;

    }

    private function GetLinkedData($data_record, $linked_data) {

        $em = $this->getDoctrine()->getManager();

        $memcached = $this->get('memcached');
        $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
        $memcached_prefix = $this->container->getParameter('memcached_key_prefix');

        // Get Linked Record IDs from Parent Array
        $parent_ids = array();
        if(!is_null($data_record)) {
            $parent_ids = array_keys($data_record);
        }
        $query = $em->createQuery(
            'SELECT
                    ldt, des, anc
                     FROM
                        ODRAdminBundle:LinkedDataTree AS ldt
                        LEFT JOIN ldt.descendant AS des
                        LEFT JOIN ldt.ancestor AS anc
                      WHERE
                          ldt.ancestor IN (:parent_ids)
                          AND anc.deletedAt IS NULL
                          AND des.deletedAt IS NULL'
        )->setParameters(array('parent_ids' => $parent_ids));

        $linked_data_records = $query->getArrayResult();

        // Calculate linked data ID array
        foreach($linked_data_records as $ldr) {
            $ldr_id = $ldr['descendant']['id'];
            if(!array_key_exists($ldr['ancestor']['id'], $linked_data) || !is_array($linked_data[$ldr['ancestor']['id']])) {
                $linked_data[$ldr['ancestor']['id']] = array();
            }
            // If 0 - pull from database. Do not use cache.
            if(1 && $linked_data_record_by_parent = $memcached->get($memcached_prefix.'.data_array_cache_'.$ldr_id)) {
                $linked_data[$ldr['ancestor']['id']][$ldr['descendant']['id']] = $linked_data_record_by_parent[$ldr['descendant']['id']][$ldr['descendant']['id']];
                $linked_data = self::GetLinkedData($linked_data[$ldr['ancestor']['id']], $linked_data);
            }
            else {
                // Lookup the linked record and store in memcached
                $linked_data_record = self::GetDataRecordData($ldr_id);
                $linked_data[$ldr['ancestor']['id']][$ldr['descendant']['id']] = $linked_data_record[$ldr['descendant']['id']][$ldr['descendant']['id']];

                $linked_data = self::GetLinkedData($linked_data[$ldr['ancestor']['id']], $linked_data);
            }
        }

        // Retrieve Linked Data
        return $linked_data;

    }
    /**
     * Returns the "Results" version of the given DataRecord.
     * 
     * @param integer $datarecord_id The database id of the datarecord to return.
     * @param string $search_key     Used for search header, an optional string describing which search result list $datarecord_id is a part of
     * @param integer $offset        Used for search header, an optional integer indicating which page of the search result list $datarecord_id is on
     * @param Request $request
     * 
     * @return a Symfony JSON response containing the HTML version of the requested DataRecord that the user is allowed to view
     */
    public function viewAction($datarecord_id, $search_key, $offset, Request $request) 
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';


        $em = $this->getDoctrine()->getManager();

            // New Caching System
            try {

                $memcached = $this->get('memcached');
                $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
                $memcached_prefix = $this->container->getParameter('memcached_key_prefix');

                $t0 = microtime(true);
                $linked_data_array = array();
                // If 0 - pull from database - do not use cache
                if (1 && $data_record_array = $memcached->get($memcached_prefix . '.data_array_cache_' . $datarecord_id)) {
                    $linked_data_array = self::GetLinkedData($data_record_array, $linked_data_array);
                    $t1 = microtime(true);
                } else {
                    // Get data from memcached or database as needed
                    $data_record_array = self::GetDataRecordData($datarecord_id);
                    $linked_data_array = self::GetLinkedData($data_record_array, $linked_data_array);
                    $t1 = microtime(true);
                }

                $diff = $t1 - $t0;

                // TODO Filter the arrays by permission
                // pop out non-viewable fields/types and is not public
                // add an "editable" flag to editable types/fields.

                // $t0 = microtime(true);
                // Theme Data Query
                $related_datatypes = array();
                foreach($data_record_array as $dr) {
                    foreach($dr as $rec) {
                        array_push($related_datatypes, $rec['dataType']['id']);
                    }
                }
                foreach($linked_data_array as $ldr) {
                    foreach($ldr as $rec) {
                        array_push($related_datatypes, $rec['dataType']['id']);
                    }
                }

                $related_datatypes = array_unique($related_datatypes);


                $theme = $em->getRepository('ODRAdminBundle:Theme')->find(1);

                $query = $em->createQuery(
                    'SELECT
                       ote, otedt, otef, odt, otdt, odf, odfft, otdf
                     FROM
                        ODRAdminBundle:ThemeElement AS ote
                        LEFT JOIN ote.dataType as otedt
                        LEFT JOIN ote.themeElementField AS otef
                        LEFT JOIN otef.dataType AS odt
                        LEFT JOIN otef.dataFields AS odf
                        LEFT JOIN odf.fieldType odfft
                        LEFT JOIN odf.themeDataField AS otdf
                        LEFT JOIN odt.themeDataType AS otdt
                      WHERE
                          ote.dataType IN (:related_datatypes)
                          AND ote.theme = :theme_id
                          AND ote.deletedAt IS NULL
                          AND otedt.deletedAt IS NULL
                          AND otef.deletedAt IS NULL
                          AND odt.deletedAt IS NULL
                          AND odf.deletedAt IS NULL
                          AND (otdf.theme = :theme_id OR otdf.theme IS NULL)
                          AND (otdt.theme = :theme_id OR otdt.theme IS NULL)
                      ORDER BY
                          ote.dataType ASC,
                          ote.displayOrder ASC'
                )->setParameters(array('related_datatypes' => $related_datatypes, 'theme_id' => $theme->getId()));

                $theme_data = $query->getArrayResult();

               $theme_data_by_type = array();
               foreach($theme_data as $td) {
                    if(!array_key_exists($td['dataType']['id'], $theme_data_by_type) || !is_array($theme_data_by_type[$td['dataType']['id']])){
                        $theme_data_by_type[$td['dataType']['id']] = array();
                    }
                    array_push($theme_data_by_type[$td['dataType']['id']],$td);
                }
                // Retrieve Linked Data
                $t1 = microtime(true);
                $diff = $t1 - $t0;



                // Determine which template to use for rendering
                $template = 'ODRAdminBundle:Display:results_ajax.html.twig';

                $t0 = microtime(true);
                // Render the DataRecord
                $templating = $this->get('templating');

                $html = $templating->render(
                    $template,
                    array(
                        'data_record_array' => $data_record_array,
                        'data_record_id' => $datarecord_id,
                        'linked_data_array' => $linked_data_array,
                        'theme_data_by_type' => $theme_data_by_type,
                        'theme' => $theme,
                    )
                );

                $t1 = microtime(true);

                $diff = $t1 - $t0;
                $testt = array();
                array_push($testt,1);
                $return['d'] = array(
                    'datatype_id' => '43',
                    'html' => $html
                );


            }
//        catch(\Doctrine\ORM\ORMException $e) {
//        // catch (\Doctrine\ORM\NoResultException $e) {
//            $return['r'] = 1;
//            $return['t'] = 'ex';
//            $return['d'] = 'Error 0x81988388 ' . $e->getMessage();
//        }
            catch (\Exception $e) {
                $return['r'] = 1;
                $return['t'] = 'ex';
                $return['d'] = 'Error 0x38978321 ' . $e->getMessage();
            }

            $response = new Response(json_encode($return));
            $response->headers->set('Content-Type', 'application/json');
            return $response;

    }


    /**
     * Creates a Symfony response that so browsers can download files from the server.
     * TODO - http://symfony.com/doc/current/components/http_foundation/introduction.html#serving-files
     * 
     * @param integer $file_id The database id of the file to download.
     * @param Request $request
     * 
     * @return TODO
     */
    public function filedownloadAction($file_id, Request $request) {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        $response = new Response();

        try {
            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $crypto = $this->get("dterranova_crypto.crypto_adapter");

            // Locate the file in the database
            $file = $em->getRepository('ODRAdminBundle:File')->find($file_id);
            if ($file == null)
                return parent::deletedEntityError('File');
            $datarecord = $file->getDataRecord();
            if ($datarecord == null)
                return parent::deletedEntityError('DataRecord');

            $datatype = $datarecord->getDataType();


            // --------------------
            // Check to see if the user is permitted to download this file
            if ( !$file->isPublic() ) {
                // Determine user privileges
                $user = $this->container->get('security.context')->getToken()->getUser();
                if ($user === 'anon.') {
                    // Non-logged in users not allowed to download non-public files
                    return parent::permissionDeniedError();
                }
                else {
                    // Grab the user's permission list
                    $user_permissions = parent::getPermissionsArray($user->getId(), $request);

                    // Ensure user has permissions to be doing this
                    if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'view' ])) )
                        return parent::permissionDeniedError();
                }
            }
            else {
                /* file is public, so no restrictions on who can download it */
            }
            // --------------------


            // Ensure the file exists in decrypted format
            $file_path = parent::decryptObject($file->getId(), 'file');
            $handle = fopen($file_path, 'r');
            if ($handle !== false) {

                $display_filename = $file->getOriginalFileName();
                if ($display_filename == null)
                    $display_filename = 'File_'.$file_id.'.'.$file->getExt();

                // Set up a response to send the file back
                $response->setPrivate();
                $response->headers->set('Content-Type', mime_content_type($file_path));
                $response->headers->set('Content-Length', filesize($file_path));
                $response->headers->set('Content-Disposition', 'attachment; filename="'.$display_filename.'";');

                $response->sendHeaders();

                $content = file_get_contents($file_path);   // using file_get_contents() because apparently readfile() tacks on # of bytes read at end of file for firefox
                $response->setContent($content);

                fclose($handle);

                // If the file isn't public, delete the decrypted version so isn't be accessible from web
                if (!$file->isPublic())
                    unlink($file_path);
            }
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x848418123 ' . $e->getMessage();
        }

        if ($return['r'] !== 0) {
            // If error encountered, do a json return
            $response = new Response(json_encode($return));
            $response->headers->set('Content-Type', 'application/json');
            return $response;
        }
        else {
            // Otherwise, return the previously created response
            return $response;
        }

    }


    /**
     * Creates a Symfony response that so browsers can download images from the server.
     * TODO - http://symfony.com/doc/current/components/http_foundation/introduction.html#serving-files
     *
     * @param integer $image_id The database_id of the image to download.
     * @param Request $request
     * 
     * @return TODO
     */
    public function imagedownloadAction($image_id, Request $request) {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        $response = new Response();

        try {
            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $crypto = $this->get("dterranova_crypto.crypto_adapter");

            // Locate the image object in the database
            $image = $em->getRepository('ODRAdminBundle:Image')->find($image_id);
            if ($image == null)
                return parent::deletedEntityError('Image');
            $datarecord = $image->getDataRecord();
            if ($datarecord == null)
                return parent::deletedEntityError('DataRecord');

            $datatype = $datarecord->getDataType();


            // --------------------
            // Check to see if the user is permitted to download this image
            if ( !$image->isPublic() ) {
                // Determine user privileges
                $user = $this->container->get('security.context')->getToken()->getUser();
                if ($user === 'anon.') {
                    // Non-logged in users not allowed to download non-public images
                    return parent::permissionDeniedError();
                }
                else {
                    // Grab the user's permission list
                    $user_permissions = parent::getPermissionsArray($user->getId(), $request);

                    // Ensure user has permissions to be doing this
                    if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'view' ])) )
                        return parent::permissionDeniedError();
                }
            }
            else {
                /* image is public, so no restrictions on who can download it */
            }
            // --------------------


            // Ensure the image exists in decrypted form
            $image_path = parent::decryptObject($image->getId(), 'image');
            $handle = fopen($image_path, 'r');
            if ($handle !== false) {

                // Have to send image headers first, apparently...
                $response->setPrivate();
                switch ($image->getExt()) {
                    case 'GIF':
                    case 'gif':
                        $response->headers->set('Content-Type', 'image/gif');
                        break;
                    case 'PNG':
                    case 'png':
                        $response->headers->set('Content-Type', 'image/png');
                        break;
                    case 'JPG':
                    case 'jpg':
                    case 'jpeg':
                        $response->headers->set('Content-Type', 'image/jpeg');
                        break;
                }
                $response->sendHeaders();

                // After headers are sent, send the image itself
                switch ($image->getExt()) {
                    case 'GIF':
                    case 'gif':
                        $im = imagecreatefromgif($image_path);
                        imagegif($im);
                        break;
                    case 'PNG':
                    case 'png':
                        $im = imagecreatefrompng($image_path);
                        imagepng($im);
                        break;
                    case 'JPG':
                    case 'jpg':
                    case 'jpeg':
                        $im = imagecreatefromjpeg($image_path);
                        imagejpeg($im);
                        break;
                }
                imagedestroy($im);

                fclose($handle);

                // If the image isn't public, delete the decrypted version so isn't be accessible from web
                if (!$image->isPublic())
                    unlink($image_path);
            }
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x848418124 ' . $e->getMessage();
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
     * TODO
     * 
     * @param Integer $datarecord_id
     * @param string $datarecord_name
     * @param Request $request
     * 
     * @return TODO
     */
    public function mapAction($datarecord_id, $datarecord_name, Request $request)
    {
        $return = '';

        try {
            // Get necessary objects
            $em = $this->getDoctrine()->getManager();
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
            $templating = $this->get('templating');
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');

            // Grab the desired datarecord
            $datarecord = $repo_datarecord->find($datarecord_id);
            $datatype = $datarecord->getDataType();

            // Determine which memcached key to load
            $has_non_public_children = false;
            $childrecords = $repo_datarecord->findByGrandparent($datarecord);
            foreach ($childrecords as $childrecord) {
                if ($childrecord->isPublic()) {
                    $has_non_public_children = true;
                    break;
                }
            }

            // If user is not logged in and the DataRecord has children that need to be hidden...
            $user = 'anon.';
            if ($user === 'anon.' && $has_non_public_children) {
                // ...load the varient of the DataRecord that hides the children
                $cache_html = $memcached->get($memcached_prefix.'.data_record_long_form_public_'.$datarecord_id);

                // No caching in dev environment
                if ($this->getParameter('kernel.environment') === 'dev')
                    $cache_html = null;

                if ($cache_html == null) {
                    // If the cached html doesn't exist, ensure all the entities exist before rendering caching the DataRecord's html
                    parent::verifyExistence($datatype, $datarecord);
                    $cache_html = parent::Long_GetDisplayData($request, $datarecord->getId(), 'public_only');
                    $memcached->set($memcached_prefix.'.data_record_long_form_public_'.$datarecord_id, $cache_html, 0);
                }
            }
            else {
                // ...user is logged in, or DataRecord has nothing to hide
                $cache_html = $memcached->get($memcached_prefix.'.data_record_long_form_'.$datarecord_id);

                // No caching in dev environment
                if ($this->getParameter('kernel.environment') === 'dev')
                    $cache_html = null;


                if ($cache_html == null) {
                    // If the cached html doesn't exist, ensure all the entities exist before rendering caching the DataRecord's html
                    parent::verifyExistence($datatype, $datarecord);
                    $cache_html = parent::Long_GetDisplayData($request, $datarecord-getId());
                    $memcached->set($memcached_prefix.'.data_record_long_form_'.$datarecord_id, $cache_html, 0);
                }
            }

            // Render the javascript redirect
            $prefix = '/app_dev.php/search#';
//            $redirect_str = $this->generateUrl( 'odr_results_view', array('datarecord_id' => $datarecord_id, 'search_key' => '', 'search_string' => '') );
            $redirect_str = $this->generateUrl( 'odr_results_view', array('datarecord_id' => $datarecord_id) );
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
/*
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x802484450 ' . $e->getMessage();
*/
        }

        $response = new Response($return);
        $response->headers->set('Content-Type', 'text/html');
        return $response;
    }

}
