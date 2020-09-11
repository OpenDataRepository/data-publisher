<?php

/**
 * Open Data Repository Data Publisher
 * Validation Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Contains controller actions for verifying database integrity
 */

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataFieldsMeta;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataRecordMeta;
use ODR\AdminBundle\Entity\DataTree;
use ODR\AdminBundle\Entity\DataTreeMeta;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTypeMeta;
use ODR\AdminBundle\Entity\FieldType;
use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Entity\FileMeta;
use ODR\AdminBundle\Entity\Image;
use ODR\AdminBundle\Entity\ImageMeta;
use ODR\AdminBundle\Entity\RadioOptions;
use ODR\AdminBundle\Entity\RadioOptionsMeta;
use ODR\AdminBundle\Entity\RenderPlugin;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeMeta;
use ODR\AdminBundle\Entity\ThemeElement;
use ODR\AdminBundle\Entity\ThemeElementMeta;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
use ODR\AdminBundle\Component\Service\DatatypeInfoService;
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


class ValidationController extends ODRCustomController
{

    /**
     * Debug function to force the correct datetime format in the database
     *
     * @param Request $request
     *
     * @return Response
     */
    public function fixdatabasedatesAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        $save = false;
//        $save = true;

        $em = null;
        $conn = null;

        try {
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $em->getFilters()->disable('softdeleteable');

            $has_created = $has_updated = $has_deleted = $has_publicdate = array();

            print '<pre>';
            $filepath = dirname(__FILE__).'/../Entity/';
            $filelist = scandir($filepath);
            foreach ($filelist as $num => $filename) {

                if (strlen($filename) > 3 && strpos($filename, '~') === false && strpos($filename, '.bck') === false) {
                    $handle = fopen($filepath.$filename, 'r');
                    if (!$handle)
                        throw new ODRException('Unable to open file');

                    $classname = '';
                    while (!feof($handle)) {
                        $line = fgets($handle);

                        $matches = array();
                        if ( preg_match('/^class ([^\s]+)$/', $line, $matches) == 1 )
                            $classname = $matches[1];
                        if ($classname == 'FieldType')
                            continue;

                        if ( strpos($line, 'private $created;') !== false )
                            $has_created[] = $classname;
                        if ( strpos($line, 'private $updated;') !== false )
                            $has_updated[] = $classname;
                        if ( strpos($line, 'private $deletedAt;') !== false )
                            $has_deleted[] = $classname;
                        if ( strpos($line, 'private $publicDate;') !== false )
                            $has_publicdate[] = $classname;
                    }

                    fclose($handle);
                }
            }

//print "has created: \n".print_r($has_created);
//print "has updated: \n".print_r($has_updated);
//print "has deleted: \n".print_r($has_deleted);
//print "has publicDate: \n".print_r($has_publicdate);

            $bad_created = $bad_updated = $bad_deleted = $bad_publicdate = array();
            $parameter = array('bad_date' => "0000-00-00%");

            foreach ($has_created as $num => $classname) {
                $query = $em->createQuery(
                   'SELECT COUNT(e.id)
                    FROM ODRAdminBundle:'.$classname.' AS e
                    WHERE e.created LIKE :bad_date'
                )->setParameters($parameter);
                $results = $query->getArrayResult();

                if ($results[0][1] > 0)
                    $bad_created[$classname] = $results[0][1];
            }

            foreach ($has_updated as $num => $classname) {
                $query = $em->createQuery(
                   'SELECT COUNT(e.id)
                    FROM ODRAdminBundle:'.$classname.' AS e
                    WHERE e.updated LIKE :bad_date'
                )->setParameters($parameter);
                $results = $query->getArrayResult();

                if ($results[0][1] > 0)
                    $bad_updated[$classname] = $results[0][1];
            }

            foreach ($has_deleted as $num => $classname) {
                $query = $em->createQuery(
                   'SELECT COUNT(e.id)
                    FROM ODRAdminBundle:'.$classname.' AS e
                    WHERE e.deletedAt LIKE :bad_date'
                )->setParameters($parameter);
                $results = $query->getArrayResult();

                if ($results[0][1] > 0)
                    $bad_deleted[$classname] = $results[0][1];
            }

            foreach ($has_publicdate as $num => $classname) {
                $query = $em->createQuery(
                   'SELECT COUNT(e.id)
                    FROM ODRAdminBundle:'.$classname.' AS e
                    WHERE e.publicDate LIKE :bad_date'
                )->setParameters($parameter);
                $results = $query->getArrayResult();

                if ($results[0][1] > 0)
                    $bad_publicdate[$classname] = $results[0][1];
            }

//print "bad created: \n".print_r($bad_created, true)."\n";
//print "bad updated: \n".print_r($bad_updated, true)."\n";
//print "bad deleted: \n".print_r($bad_deleted, true)."\n";
//print "bad publicDate: \n".print_r($bad_publicdate, true)."\n";

            // Since this could update a pile of stuff, wrap it in a transaction
            $conn = $em->getConnection();
            $conn->beginTransaction();

            foreach ($bad_created as $classname => $num) {
                $query = $em->createQuery(
                   'UPDATE ODRAdminBundle:'.$classname.' AS e
                    SET e.created = :good_date
                    WHERE e.created < :bad_date'
                )->setParameters(
                    array(
                        'good_date' => new \Datetime('2013-01-01 00:00:00'),
                        'bad_date' => '2000-01-01 00:00:00'
                    )
                );
                $first = $query->execute();
            }

            foreach ($bad_updated as $classname => $num) {
                $query = $em->createQuery(
                   'UPDATE ODRAdminBundle:'.$classname.' AS e
                    SET e.updated = e.deletedAt
                    WHERE e.deletedAt IS NOT NULL'
                );
                $first = $query->execute();

                $query = $em->createQuery(
                   'UPDATE ODRAdminBundle:'.$classname.' AS e
                    SET e.updated = e.created
                    WHERE e.updated < :good_date'
                )->setParameters(array('good_date' => '2000-01-01 00:00:00'));
                $second = $query->execute();
            }

            foreach ($bad_deleted as $classname => $num) {
                $query = $em->createQuery(
                   'UPDATE ODRAdminBundle:'.$classname.' AS e
                    SET e.deletedAt = NULL
                    WHERE e.deletedAt < :bad_date'
                )->setParameters(
                    array(
                        'bad_date' => '2000-01-01 00:00:00'
                    )
                );
                $first = $query->execute();
            }

            foreach ($bad_publicdate as $classname => $num) {
                $query = $em->createQuery(
                   'UPDATE ODRAdminBundle:'.$classname.' AS e
                    SET e.publicDate = :good_date
                    WHERE e.publicDate < :bad_date'
                )->setParameters(
                    array(
                        'good_date' => new \DateTime('2200-01-01 00:00:00'),
                        'bad_date' => '2000-01-01 00:00:00'
                    )
                );
                $first = $query->execute();
            }

            if ($save)
                $conn->commit();
            else
                $conn->rollBack();

            $em->getFilters()->enable('softdeleteable');
            print '</pre>';
        }
        catch (\Exception $e) {
            $em->getFilters()->enable('softdeleteable');

            if ( !is_null($conn) && $conn->isTransactionActive() )
                $conn->rollBack();

            $source = 0x82f7ce43;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'text/html');
        return $response;
    }


    /**
     * Apparently child datatypes managed to get search slugs before Sept 2017...which causes problems
     * when changing their properties because the form always submits their search_slug as an
     * empty string...but this change causes DisplaytemplateController::datatypepropertiesAction()
     * to run a regex that intentionally blocks blank search slugs...resulting in the inability to
     * modify any property of a child datatype until their search slugs are set to null in the
     * backend database.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function childsearchslugfixAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        $save = false;
//        $save = true;

        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();

        try {

            $query = $em->createQuery(
               'SELECT dtm
                FROM ODRAdminBundle:DataType AS gp_dt
                JOIN ODRAdminBundle:DataType AS dt WITH dt.grandparent = gp_dt
                JOIN ODRAdminBundle:DataTypeMeta AS dtm WITH dtm.dataType = dt
                WHERE gp_dt.id != dt.id AND dtm.searchSlug IS NOT NULL
                AND gp_dt.deletedAt IS NULL AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL
                ORDER BY gp_dt.id, dt.id'
            );
            /** @var DataTypeMeta[] $results */
            $results = $query->getResult();

            if (!$save) {
                print '<pre> These are the child datatypes that would be modified to have a null search slug'."\n\n";
                print '<table>';
                print '<tr>';
                print '<th>grandparent_id</th>';
                print '<th>grandparent_name</th>';
                print '<th>child_datatype_id</th>';
                print '<th>child_datatype_name</th>';
                print '<th>search_slug</th>';
                print '</tr>';

                foreach ($results as $result) {
                    print '<tr>';
                    print '<td>'.$result->getDataType()->getGrandparent()->getId().'</td>';
                    print '<td>'.$result->getDataType()->getGrandparent()->getShortName().'</td>';
                    print '<td>'.$result->getDataType()->getId().'</td>';
                    print '<td>'.$result->getShortName().'</td>';
                    print '<td>'.$result->getSearchSlug().'</td>';
                    print '</tr>';
                }

                print '</table>';
                print '</pre>';
            }
            else {
                foreach ($results as $result) {
                    $result->setSearchSlug(null);
                    $em->persist($result);
                }

                $em->flush();
            }
        }
        catch (\Exception $e) {
            // Don't want any changes made being saved to the database
            $em->clear();

            $source = 0xba8a4083;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'text/html');
        return $response;
    }


    /**
     * Looks for and creates any missing meta entries
     *
     * @param Request $request
     *
     * @return Response
     */
    public function fixmissingmetaentriesAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        $save = false;
//        $save = true;

        $conn = null;

        try {
            // ----------------------------------------
            // Load required objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
            $repo_fieldtype = $em->getRepository('ODRAdminBundle:FieldType');
            $repo_render_plugin = $em->getRepository('ODRAdminBundle:RenderPlugin');
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
//            $repo_datatree = $em->getRepository('ODRAdminBundle:DataTree');
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_file = $em->getRepository('ODRAdminBundle:File');
            $repo_image = $em->getRepository('ODRAdminBundle:Image');
            $repo_radio_options = $em->getRepository('ODRAdminBundle:RadioOptions');
            $repo_theme = $em->getRepository('ODRAdminBundle:Theme');
            $repo_theme_element = $em->getRepository('ODRAdminBundle:ThemeElement');

            /** @var RenderPlugin $default_render_plugin */
            $default_render_plugin = $repo_render_plugin->findOneBy( array('pluginClassName' => 'odr_plugins.base.default') );
            /** @var FieldType $default_fieldtype */
            $default_fieldtype = $repo_fieldtype->find(9);    // shortvarchar

            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            if (!$user->hasRole('ROLE_SUPER_ADMIN'))
                throw new ODRForbiddenException();

            $batch_size = 100;
            $count = 0;

            // Load everything regardless of deleted status
            $em->getFilters()->disable('softdeleteable');

            // Going to run native SQL queries for this, doctrine doesn't do subqueries well
            $conn = $em->getConnection();
            print '<pre>';

            // ----------------------------------------
            // Datafields
            $query =
                'SELECT df.id AS df_id
                 FROM odr_data_fields AS df
                 WHERE df.id NOT IN (
                     SELECT DISTINCT(dfm.data_field_id)
                     FROM odr_data_fields_meta AS dfm
                 )';
            $results = $conn->fetchAll($query);

            $missing_datafields = array();
            foreach ($results as $result)
                $missing_datafields[] = $result['df_id'];

            print 'missing datafield meta entries: '."\n";
            print_r($missing_datafields);

            if ($save) {
                foreach ($missing_datafields as $num => $df_id) {
                    /** @var DataFields $df */
                    $df = $repo_datafield->find($df_id);

                    $dfm = new DataFieldsMeta();
                    $dfm->setDataField($df);
                    $dfm->setFieldType($default_fieldtype);
                    $dfm->setRenderPlugin($default_render_plugin);

                    $dfm->setMasterRevision(0);
                    $dfm->setTrackingMasterRevision(0);
                    $dfm->setMasterPublishedRevision(0);

                    $dfm->setFieldName('New Field');
                    $dfm->setDescription('Field description');
                    $dfm->setXmlFieldName('');
                    $dfm->setInternalReferenceName('');
                    $dfm->setRegexValidator('');
                    $dfm->setPhpValidator('');

                    $dfm->setMarkdownText('');
                    $dfm->setIsUnique(false);
                    $dfm->setRequired(false);
                    $dfm->setSearchable(DataFields::NOT_SEARCHED);
                    $dfm->setPublicDate( new \DateTime('2200-01-01 00:00:00') );    // not public

                    $dfm->setChildrenPerRow(1);
                    $dfm->setRadioOptionNameSort(0);
                    $dfm->setRadioOptionDisplayUnselected(0);
                    $dfm->setAllowMultipleUploads(0);
                    $dfm->setShortenFilename(0);

                    $dfm->setCreatedBy($user);
                    $dfm->setUpdatedBy($user);

                    $em->persist($dfm);

                    $count++;
                    if ($count == $batch_size) {
                        $em->flush();
                        $count = 0;
                    }
                }
            }


            // ----------------------------------------
            // Datarecords
            $query =
                'SELECT dr.id AS dr_id
                 FROM odr_data_record AS dr
                 WHERE dr.id NOT IN (
                     SELECT DISTINCT(drm.data_record_id)
                     FROM odr_data_record_meta AS drm
                 )';
            $results = $conn->fetchAll($query);

            $missing_datarecords = array();
            foreach ($results as $result)
                $missing_datarecords[] = $result['dr_id'];

            print 'missing datarecord meta entries: '."\n";
            print_r($missing_datarecords);

            if ($save) {
                foreach ($missing_datarecords as $num => $dr_id) {
                    /** @var DataRecord $dr */
                    $dr = $repo_datarecord->find($dr_id);

                    $drm = new DataRecordMeta();
                    $drm->setDataRecord($dr);

                    if ( $dr->getDataType()->getNewRecordsArePublic() )
                        $drm->setPublicDate(new \DateTime());   // public
                    else
                        $drm->setPublicDate(new \DateTime('2200-01-01 00:00:00'));   // not public

                    $drm->setCreatedBy($user);
                    $drm->setUpdatedBy($user);

                    $em->persist($drm);

                    $count++;
                    if ($count == $batch_size) {
                        $em->flush();
                        $count = 0;
                    }
                }
            }


            // ----------------------------------------
            // Datatree
            $query =
                'SELECT dt.id AS dt_id
                 FROM odr_data_tree AS dt
                 WHERE dt.id NOT IN (
                     SELECT DISTINCT(dtm.data_tree_id)
                     FROM odr_data_tree_meta AS dtm
                 )';
            $results = $conn->fetchAll($query);

            $missing_datatrees = array();
            foreach ($results as $result)
                $missing_datatrees[] = $result['dt_id'];

            print 'missing datatree meta entries:  **NO ACTION TAKEN**'."\n";
            print_r($missing_datatrees);


            // ----------------------------------------
            // Datatypes
            $query =
                'SELECT dt.id AS dt_id
                 FROM odr_data_type AS dt
                 WHERE dt.id NOT IN (
                     SELECT DISTINCT(dtm.data_type_id)
                     FROM odr_data_type_meta AS dtm
                 )';
            $results = $conn->fetchAll($query);

            $missing_datatypes = array();
            foreach ($results as $result)
                $missing_datatypes[] = $result['dt_id'];

            print 'missing datatype meta entries: '."\n";
            print_r($missing_datatypes);

            if ($save) {
                foreach ($missing_datatypes as $num => $dt_id) {
                    /** @var DataType $dt */
                    $dt = $repo_datatype->find($dt_id);

                    $dtm = new DataTypeMeta();
                    $dtm->setDataType($dt);
                    $dtm->setRenderPlugin($default_render_plugin);

                    $dtm->setSearchSlug($dt->getUniqueId());
                    $dtm->setShortName("New Datatype");
                    $dtm->setLongName("New Datatype");
                    $dtm->setDescription("New DataType Description");
                    $dtm->setXmlShortName('');

                    $dtm->setSearchNotesUpper(null);
                    $dtm->setSearchNotesLower(null);

                    $dtm->setPublicDate(new \DateTime('1980-01-01 00:00:00'));    // public

                    $dtm->setExternalIdField(null);
                    $dtm->setNameField(null);
                    $dtm->setSortField(null);
                    $dtm->setBackgroundImageField(null);

                    $dtm->setMasterPublishedRevision(0);
                    $dtm->setMasterRevision(0);
                    $dtm->setTrackingMasterRevision(0);

                    $dtm->setCreatedBy($user);
                    $dtm->setUpdatedBy($user);

                    $em->persist($dtm);

                    $count++;
                    if ($count == $batch_size) {
                        $em->flush();
                        $count = 0;
                    }
                }
            }


            // ----------------------------------------
            // Files
            $query =
                'SELECT f.id AS f_id
                 FROM odr_file AS f
                 WHERE f.id NOT IN (
                     SELECT DISTINCT(fm.file_id)
                     FROM odr_file_meta AS fm
                 )';
            $results = $conn->fetchAll($query);

            $missing_files = array();
            foreach ($results as $result)
                $missing_files[] = $result['f_id'];

            print 'missing file meta entries: '."\n";
            print_r($missing_files);

            if ($save) {
                foreach ($missing_files as $num => $f_id) {
                    /** @var File $file */
                    $file = $repo_file->find($f_id);

                    $fm = new FileMeta();
                    $fm->setFile($file);
                    $fm->setOriginalFileName('file_name');
                    $fm->setExternalId('');

                    if ( $file->getDataField()->getNewFilesArePublic() )
                        $fm->setPublicDate(new \DateTime());    // public
                    else
                        $fm->setPublicDate(new \DateTime('2200-01-01 00:00:00'));    // not public

                    $fm->setCreatedBy($user);
                    $fm->setUpdatedBy($user);

                    $em->persist($fm);

                    $count++;
                    if ($count == $batch_size) {
                        $em->flush();
                        $count = 0;
                    }
                }
            }


            // ----------------------------------------
            // Images
            $query =
                'SELECT i.id AS i_id
                 FROM odr_image AS i
                 WHERE i.original = 1 AND i.id NOT IN (
                     SELECT DISTINCT(im.image_id)
                     FROM odr_image_meta AS im
                 )';
            $results = $conn->fetchAll($query);

            $missing_images = array();
            foreach ($results as $result)
                $missing_images[] = $result['i_id'];

            print 'missing image meta entries: '."\n";
            print_r($missing_images);

            if ($save) {
                foreach ($missing_images as $num => $i_id) {
                    /** @var Image $image */
                    $image = $repo_image->find($i_id);

                    $im = new ImageMeta();
                    $im->setImage($image);
                    $im->setDisplayorder(0);
                    $im->setOriginalFileName('image name');
                    $im->setCaption('image caption');
                    $im->setExternalId('');

                    if ( $image->getDataField()->getNewFilesArePublic() )
                        $im->setPublicDate(new \DateTime());    // public
                    else
                        $im->setPublicDate(new \DateTime('2200-01-01 00:00:00'));    // not public

                    $im->setCreatedBy($user);
                    $im->setUpdatedBy($user);

                    $em->persist($im);

                    $count++;
                    if ($count == $batch_size) {
                        $em->flush();
                        $count = 0;
                    }
                }
            }


            // ----------------------------------------
            // RadioOptions
            $query =
                'SELECT ro.id AS ro_id
                 FROM odr_radio_options AS ro
                 WHERE ro.id NOT IN (
                     SELECT DISTINCT(rom.radio_option_id)
                     FROM odr_radio_options_meta AS rom
                 )';
            $results = $conn->fetchAll($query);

            $missing_radio_options = array();
            foreach ($results as $result)
                $missing_radio_options[] = $result['ro_id'];

            print 'missing radio option meta entries: '."\n";
            print_r($missing_radio_options);

            if ($save) {
                foreach ($missing_radio_options as $num => $ro_id) {
                    /** @var RadioOptions $ro */
                    $ro = $repo_radio_options->find($ro_id);

                    $rom = new RadioOptionsMeta();
                    $rom->setRadioOption($ro);
                    $rom->setOptionName('Option Name');
                    $rom->setXmlOptionName('');
                    $rom->setDisplayOrder(0);
                    $rom->setIsDefault(false);

                    $rom->setCreatedBy($user);
                    $rom->setUpdatedBy($user);

                    $em->persist($rom);

                    $count++;
                    if ($count == $batch_size) {
                        $em->flush();
                        $count = 0;
                    }
                }
            }


            // ----------------------------------------
            // Themes
            $query =
                'SELECT t.id AS t_id
                 FROM odr_theme AS t
                 WHERE t.id NOT IN (
                     SELECT DISTINCT(tm.theme_id)
                     FROM odr_theme_meta AS tm
                 )';
            $results = $conn->fetchAll($query);

            $missing_themes = array();
            foreach ($results as $result)
                $missing_themes[] = $result['t_id'];

            print 'missing theme meta entries: '."\n";
            print_r($missing_themes);

            if ($save) {
                foreach ($missing_themes as $num => $t_id) {
                    /** @var Theme $theme */
                    $theme = $repo_theme->find($t_id);

                    $tm = new ThemeMeta();
                    $tm->setTheme($theme);
                    $tm->setTemplateName('');
                    $tm->setTemplateDescription('');
                    $tm->setIsDefault(false);
                    $tm->setDisplayOrder(0);
                    $tm->setShared(false);
                    $tm->setSourceSyncVersion(0);
                    $tm->setIsTableTheme(false);

                    $tm->setCreatedBy($user);
                    $tm->setUpdatedBy($user);

                    $em->persist($tm);

                    $count++;
                    if ($count == $batch_size) {
                        $em->flush();
                        $count = 0;
                    }
                }
            }


            // ----------------------------------------
            // ThemeElements
            $query =
                'SELECT te.id AS te_id
                 FROM odr_theme_element AS te
                 WHERE te.id NOT IN (
                     SELECT DISTINCT(tem.theme_element_id)
                     FROM odr_theme_element_meta AS tem
                 )';
            $results = $conn->fetchAll($query);

            $missing_theme_elements = array();
            foreach ($results as $result)
                $missing_theme_elements[] = $result['te_id'];

            print 'missing theme element meta entries: '."\n";
            print_r($missing_theme_elements);

            if ($save) {
                foreach ($missing_theme_elements as $num => $te_id) {
                    /** @var ThemeElement $te */
                    $te = $repo_theme_element->find($te_id);

                    $tem = new ThemeElementMeta();
                    $tem->setThemeElement($te);
                    $tem->setDisplayOrder(999);
                    $tem->setCssWidthMed('1-1');
                    $tem->setCssWidthXL('1-1');
                    $tem->setHidden(0);

                    $tem->setCreatedBy($user);
                    $tem->setUpdatedBy($user);

                    $em->persist($tem);

                    $count++;
                    if ($count == $batch_size) {
                        $em->flush();
                        $count = 0;
                    }
                }
            }

            // ----------------------------------------
            if ($save)
                $em->flush();

            print '</pre>';
            // Turn the deleted filter back on
            $em->getFilters()->enable('softdeleteable');
        }
        catch (\Exception $e) {
            $em->getFilters()->enable('softdeleteable');

            if ( !is_null($conn) && $conn->isTransactionActive() )
                $conn->rollBack();

            $source = 0x914b6e40;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'text/html');
        return $response;
    }


    /**
     * Looks for multiple meta entries that belong to the same "primary" entity.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function findduplicatemetaentriesAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        $save = false;
//        $save = true;

        $conn = null;

        try {
            // ----------------------------------------
            // Load required objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            if (!$user->hasRole('ROLE_SUPER_ADMIN'))
                throw new ODRForbiddenException();

            // Load everything regardless of deleted status
            $em->getFilters()->disable('softdeleteable');

            // Going to run native SQL queries for this, doctrine doesn't do subqueries well
            $conn = $em->getConnection();
            // May end up doing a pile of mass updates, so begin a transation
            $conn->beginTransaction();

            print '<pre>';

            // ----------------------------------------
            $entities = array(
                'DataFields' => 'dataField',
                'DataRecord' => 'dataRecord',
                'DataTree' => 'dataTree',
                'DataType' => 'dataType',
                'File' => 'file',
                'Group' => 'group',
                'Image' => 'image',
                'RadioOptions' => 'radioOption',
                'Theme' => 'theme',
                'ThemeElement' => 'themeElement',
            );

            foreach ($entities as $classname => $relation) {
                $query = $em->createQuery(
                   'SELECT e.id AS entity_id, em.id AS entity_meta_id
                    FROM ODRAdminBundle:'.$classname.' AS e
                    JOIN ODRAdminBundle:'.$classname.'Meta AS em WITH em.'.$relation.' = e
                    WHERE e.deletedAt IS NULL AND em.deletedAt IS NULL
                    ORDER BY e.id'
                );
                $results = $query->getArrayResult();

                $entity_ids = array();
                $entity_meta_ids = array();
                $to_delete = 0;

                print $classname."\n";
                if ( $results && is_array($results) ) {
//                    print 'found '.count($results).' entries'."\n";
                    foreach ($results as $result) {
                        $e_id = $result['entity_id'];
                        $em_id = $result['entity_meta_id'];

                        if ( isset($entity_ids[$e_id]) ) {
                            print ' -- '.$e_id.' already has the meta entry '.$entity_ids[$e_id].', but found another meta entry '.$em_id."\n";
                            $entity_meta_ids[] = $em_id;
                            $to_delete++;
                        }
                        else {
                            $entity_ids[$e_id] = $em_id;
                        }
                    }

                    if ( count($entity_meta_ids) > 0 )
                        print 'intending to delete: '.print_r($entity_meta_ids, true)."\n";

                    $query = $em->createQuery(
                       'UPDATE ODRAdminBundle:'.$classname.'Meta AS em
                        SET em.deletedAt = :now
                        WHERE em.id IN (:entity_meta_ids)'
                    )->setParameters(
                        array(
                            'now' => new \DateTime(),
                            'entity_meta_ids' => $entity_meta_ids
                        )
                    );
                    $rows = $query->execute();

                    print ' >> expected to delete '.$to_delete.' entries, actually deleted '.$rows.' rows...'."\n";
                    print 'END '.$classname."\n\n";
                }
                else {
                    print ' -- no duplicate meta entries'."\n\n";
                }

            }

            print '</pre>';

            // Turn the deleted filter back on
            $em->getFilters()->enable('softdeleteable');

            if ($save)
                $conn->commit();
            else
                $conn->rollBack();
        }
        catch (\Exception $e) {
            $em->getFilters()->enable('softdeleteable');

            if ( !is_null($conn) && $conn->isTransactionActive() )
                $conn->rollBack();

            $source = 0x50885ebf;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'text/html');
        return $response;
    }


    /**
     * Looks for meta entries belonging to delete "primary" entities and deletes them.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function findundeletedmetaentriesAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        $save = false;
//        $save = true;

        $conn = null;

        try {
            // ----------------------------------------
            // Load required objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            if (!$user->hasRole('ROLE_SUPER_ADMIN'))
                throw new ODRForbiddenException();

            // Load everything regardless of deleted status
            $em->getFilters()->disable('softdeleteable');

            // Going to run native SQL queries for this, doctrine doesn't do subqueries well
            $conn = $em->getConnection();
            // May end up doing a pile of mass updates, so begin a transation
            $conn->beginTransaction();

            print '<pre>';

            // ----------------------------------------
            $entities = array(
                'DataFields' => 'dataField',
                'DataRecord' => 'dataRecord',
                'DataTree' => 'dataTree',
                'DataType' => 'dataType',
                'File' => 'file',
                'Group' => 'group',
                'Image' => 'image',
                'RadioOptions' => 'radioOption',
                'Theme' => 'theme',
                'ThemeElement' => 'themeElement',
            );

            foreach ($entities as $classname => $relation) {
                $query = $em->createQuery(
                   'SELECT e.id AS entity_id, em.id AS entity_meta_id
                    FROM ODRAdminBundle:'.$classname.' AS e
                    JOIN ODRAdminBundle:'.$classname.'Meta AS em WITH em.'.$relation.' = e
                    WHERE e.deletedAt IS NOT NULL AND em.deletedAt IS NULL'
                );
                $results = $query->getArrayResult();

                $entity_meta_ids = array();

                print $classname."\n";
                if ( $results && is_array($results) ) {
                    print 'found '.count($results).' entries'."\n";
                    foreach ($results as $result) {
                        $entity_meta_ids[] = $result['entity_meta_id'];

                        print ' -- '.$result['entity_id'].' is deleted, but its meta entry '.$result['entity_meta_id'].' is not'."\n";
                    }
                    print 'found '.count($results).' entries'."\n";

                    $query = $em->createQuery(
                       'UPDATE ODRAdminBundle:'.$classname.'Meta AS em
                        SET em.deletedAt = :now
                        WHERE em.id IN (:entity_meta_ids)'
                    )->setParameters(
                        array(
                            'now' => new \DateTime(),
                            'entity_meta_ids' => $entity_meta_ids
                        )
                    );
                    $rows = $query->execute();

                    print ' >> deleting '.$rows.' rows...'."\n";
                    print 'END '.$classname."\n\n";
                }
                else {
                    print ' -- no extra meta entries'."\n\n";
                }

            }

            print '</pre>';

            // Turn the deleted filter back on
            $em->getFilters()->enable('softdeleteable');

            if ($save)
                $conn->commit();
            else
                $conn->rollBack();
        }
        catch (\Exception $e) {
            $em->getFilters()->enable('softdeleteable');

            if ( !is_null($conn) && $conn->isTransactionActive() )
                $conn->rollBack();

            $source = 0xabcdef00;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'text/html');
        return $response;
    }


    /**
     * Ensures the values stored in the Theme -> ThemeElement -> ThemeDataType tables match the
     * entries in the DataTree table.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function fixdatatreeentriesAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        $save = false;
//        $save = true;

        $conn = null;

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $conn = $em->getConnection();

            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();

            $query = $em->createQuery(
               'SELECT partial dt.{id}, partial ancestor.{id}, partial descendant.{id}
                FROM ODRAdminBundle:DataTree AS dt
                JOIN dt.ancestor AS ancestor
                JOIN dt.descendant AS descendant'
            );
            $results = $query->getArrayResult();

            $dt_array = array();
            foreach ($results as $result) {
                $dt_id = $result['id'];
                $ancestor_id = $result['ancestor']['id'];
                $descendant_id = $result['descendant']['id'];

                if (!isset($dt_array[$ancestor_id]))
                    $dt_array[$ancestor_id] = array();

                $dt_array[$ancestor_id][$descendant_id] = array('dt_id' => $dt_id, 'used' => 0);
            }
//            exit( '<pre>'.print_r($dt_array, true).'</pre>' );

            $query = $em->createQuery(
               'SELECT t.id AS theme_id, dt.id AS datatype_id, c_dt.id AS child_datatype_id
                FROM ODRAdminBundle:Theme AS t
                JOIN ODRAdminBundle:DataType AS dt WITH t.dataType = dt
                JOIN ODRAdminBundle:ThemeElement AS te WITH te.theme = t
                JOIN ODRAdminBundle:ThemeDataType AS tdt WITH tdt.themeElement = te
                JOIN ODRAdminBundle:DataType AS c_dt WITH tdt.dataType = c_dt
                WHERE t.deletedAt IS NULL AND te.deletedAt IS NULL AND tdt.deletedAt IS NULL
                AND dt.deletedAt IS NULL AND c_dt.deletedAt IS NULL
                ORDER BY dt.id, c_dt.id'
            );
            $results = $query->getArrayResult();

            $extra_ancestors = array();
            $extra_descendants = array();
            foreach ($results as $result) {
                $ancestor_id = $result['datatype_id'];
                $descendant_id = $result['child_datatype_id'];

                if ( isset($dt_array[$ancestor_id]) ) {
                    if ( isset($dt_array[$ancestor_id][$descendant_id]) ) {
                        $dt_array[$ancestor_id][$descendant_id]['used'] = 1;
                    }
                    else {
                        if ( !isset($extra_descendants[$ancestor_id]) )
                            $extra_descendants[$ancestor_id] = array();
                        $extra_descendants[$ancestor_id][] = $descendant_id;
                    }
                }
                else {
                    if ( !isset($extra_ancestors[$ancestor_id]) )
                        $extra_ancestors[$ancestor_id] = array();
                    $extra_ancestors[$ancestor_id][] = $descendant_id;
                }
            }

            print '<pre>';
//            print 'List of datatree entries: '.print_r($dt_array, true)."\n";

            print "----------------------------------------\n";
            print 'There should never be entries in these two arrays...'."\n\n";
            print 'Ancestors in themes but not in datatree: '.print_r($extra_ancestors, true)."\n";
            print 'Descendants in themes but not in datatree: '.print_r($extra_descendants, true)."\n";
//            exit();


            // ----------------------------------------
            // Ancestors in themes but not in the datatree can technically be taken care of by
            //  creating new datatree entries...though this should be handled on a case-by-case
            //  basis
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');

            // ...these can be taken care of by creating new datatree entries
            print "\nCreating datatree entries for Ancestors in themes but not in datatree...\n";
            $dt_cache = array();
            foreach ($extra_ancestors as $ancestor_id => $tmp) {

                if ( !isset($dt_cache[$ancestor_id]) )
                    $dt_cache[$ancestor_id] = $repo_datatype->find($ancestor_id);

                foreach ($tmp as $num => $descendant_id) {
                    if ( !isset($dt_cache[$descendant_id]) )
                        $dt_cache[$descendant_id] = $repo_datatype->find($descendant_id);
                }
            }

            $new_dts = array();
            foreach ($extra_ancestors as $ancestor_id => $tmp) {
                foreach ($tmp as $num => $descendant_id) {
                    $dt = new DataTree();
                    $dt->setAncestor( $dt_cache[$ancestor_id] );
                    $dt->setDescendant( $dt_cache[$descendant_id] );
                    $dt->setCreated( new \DateTime() );
                    $dt->setCreatedBy($user);

                    print "-- created datatree entry for ancestor ".$ancestor_id.", descendant ".$descendant_id."...\n";

                    if ($save) {
                        $em->persist($dt);
                        $em->flush();
                        $em->refresh($dt);

                        $new_dts[] = $dt;

                        print "-- new dt id: ".$dt->getId()."\n";
                    }
                }
            }
            /** @var DataTree[] $new_dts */

            foreach ($new_dts as $num => $new_dt) {
                $dt_meta = new DataTreeMeta();
                $dt_meta->setDataTree($new_dt);
                $dt_meta->setMultipleAllowed(1);

                if ($new_dt->getDescendant()->getParent()->getId() !== $new_dt->getAncestor()->getId()) {
                    $dt_meta->setIsLink(true);
                    print "-- created meta entry for datatree ".$new_dt->getId().", is_link set to 1\n";
                }
                else {
                    $dt_meta->setIsLink(false);
                    print "-- created meta entry for datatree ".$new_dt->getId().", is_link set to 0\n";
                }

                $dt_meta->setCreated(new \DateTime());
                $dt_meta->setCreatedBy($user);
                $dt_meta->setUpdated(new \DateTime());
                $dt_meta->setUpdatedBy($user);

                if ($save) {
                    $em->persist($dt);
                    $em->flush();
                    $em->refresh($dt);
                }
            }


            print "----------------------------------------\n";
            $entries_to_fix = array();
            foreach ($dt_array as $ancestor_id => $tmp) {
                foreach ($tmp as $descendant_id => $data) {
                    $dt_id = $data['dt_id'];

                    if ($data['used'] == 0) {
                        $entries_to_fix[$dt_id] = array(
                            'ancestor_id' => $ancestor_id,
                            'descendant_id' => $descendant_id,
                        );
                    }
                }
            }
            $datatree_ids = array_keys($entries_to_fix);

            print 'Datatree entries that need deleting: '.print_r($entries_to_fix, true)."\n";
//            exit();

            $conn->beginTransaction();

            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:DataTree AS dt
                SET dt.deletedAt = :now, dt.deletedBy = :user
                WHERE dt.id IN (:datatree_ids) AND dt.deletedAt IS NULL'
            )->setParameters(
                array(
                    'now' => new \DateTime(),
                    'user' => $user->getId(),
                    'datatree_ids' => $datatree_ids,
                )
            );
            $query->execute();

            print "Deleting these datatree and their datatree_meta entries...\n".print_r($datatree_ids, true)."\n\n";

            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:DataTreeMeta AS dtm
                SET dtm.deletedAt = :now
                WHERE dtm.dataTree IN (:datatree_ids) AND dtm.deletedAt IS NULL'
            )->setParameters(
                array(
                    'now' => new \DateTime(),
                    'datatree_ids' => $datatree_ids,
                )
            );
            $query->execute();

            if ($save)
                $conn->commit();
            else
                $conn->rollBack();

            // Changes may have been made, delete the cached entry based off the datatree entity
            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');
            $cache_service->delete('cached_datatree_array');

            print '</pre>';
        }
        catch (\Exception $e) {
            if ( !is_null($conn) && $conn->isTransactionActive() )
                $conn->rollBack();

            $source = 0x7410a96e;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'text/html');
        return $response;
    }


    /**
     * Locates a pile of ODR entities that, for one reason or another, didn't get deleted when an
     * entity they reference got deleted.
     *
     * @param Request $request
     *
     * @return Response
     * @throws \Doctrine\DBAL\ConnectionException
     */
    public function findorphanedentitiesAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        $save = false;
//        $save = true;

        $conn = null;

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $conn = $em->getConnection();

            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();

            print '<pre>';

            $target_entities = array(
                'DataFields',
                'DataRecord',
                'DataTree',
                'DataType',
                'File',
                'Group',
                'Image',
                'RadioOptions',
                'RenderPlugin',
                'RenderPluginFields',
                'RenderPluginInstance',
                'RenderPluginMap',
                'Theme',
                'ThemeElement',
            );
            $query_targets = array();


            // Going to parse the doctrine config files to find these relationships...
            $filepath = dirname(__FILE__).'/../Resources/config/doctrine/';
            $filelist = scandir($filepath);
            foreach ($filelist as $num => $filename) {

                if (strlen($filename) > 3
                    && strpos($filename, '~') === false
                    && strpos($filename, '.bck') === false && strpos($filename, '.backup') === false
                    && strpos($filename, 'Meta') === false      // Meta entries are handled in self::fixdatatreeentriesAction() because they can just get deleted without further side-effects
                ) {
                    $handle = fopen($filepath.$filename, 'r');
                    if (!$handle)
                        throw new ODRException('Unable to open file');

                    $tmp = explode('.', $filename);
                    $classname = $tmp[0];

                    // Read through the entire file...
                    $prev_line = '';
                    $line = '';
                    $in_many_to_one_section = false;
                    $has_deletedAt = false;

                    while ( !feof($handle) ) {
                        // Part of the info required to build the query later on is on the line prior to the 'targetEntity: ...' line
                        $prev_line = $line;
                        $line = fgets($handle);

                        // Only interested in these relationships if they're in the 'manyToOne' section...the current entity type isn't guaranteed to have a database column for this relationship otherwise
                        if ( strpos($line, 'manyToOne:') !== false )
                            $in_many_to_one_section = true;
                        else if ( strpos($line, 'oneToMany:') !== false )
                            $in_many_to_one_section = false;

                        // The query below requires a deletedAt to function correctly...
                        if ( strpos($line, 'deletedAt:') !== false )
                            $has_deletedAt = true;

                        // ...only interested in the lines that define relationships to other entities...
                        $matches = array();
                        if ( $in_many_to_one_section && $has_deletedAt && preg_match('/targetEntity: ([a-zA-Z]+)\n/', $line, $matches) === 1 ) {
                            // ...and then only interested in the ones that reference the above list of entities
                            $key = array_search($matches[1], $target_entities);
                            if ($key !== false) {
                                $prev_line = substr(trim($prev_line), 0, -1);
                                if ( strpos($prev_line, '#') === false) {
                                    // For each $target_entity listed in the config file, store the relationship and the entity it points to
                                    if (!isset($query_targets[$classname]))
                                        $query_targets[$classname] = array();

                                    $query_targets[$classname][$prev_line] = $target_entities[$key];
                                }
                            }
                        }
                    }

                    fclose($handle);
                }
            }

            // Datatypes also have a single metadata datatype...
            $query_targets['DataType']['metadata_datatype'] = 'DataType';
            $query_targets['DataType']['metadata_for'] = 'DataType';

            // Certain relationships are currently allowed to have undeleted child_entities that
            //  reference a deleted parent entity...
            $query_targets = self::removeAcceptableEntries($query_targets);

            // Load everything regardless of deleted status
            $em->getFilters()->disable('softdeleteable');
            $conn->beginTransaction();

            // All datatypes require at least one theme...
            $query =
               'SELECT dt.id AS dt_id, t.id AS t_id
                FROM odr_data_type dt
                LEFT JOIN odr_theme t ON (t.data_type_id = dt.id AND t.deletedAt IS NULL)
                WHERE dt.deletedAt IS NULL';
            $results = $conn->fetchAll($query);

            $dt_ids = array();
            foreach ($results as $result) {
                if ( is_null($result['t_id']) )
                    $dt_ids[] = $result['dt_id'];
            }

            print count($dt_ids).' undeleted Datatype entities that lack a Theme...'."\n";
            foreach ($dt_ids as $num => $dt_id)
                print ' -- '.$dt_id.' => NULL'."\n";

            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:DataType AS dt
                SET dt.deletedAt = :now
                WHERE dt.id IN (:datatype_ids)'
            )->setParameters(
                array(
                    'now' => new \DateTime(),
                    'datatype_ids' => $dt_ids
                )
            );
            $rows = $query->execute();

            if ($rows > 0)
                print ' >> deleting '.$rows.' rows...';
            print "\n\n";


            foreach ($query_targets as $classname => $relationships) {
                foreach ($relationships as $relation => $target_entity) {
                    // Find all instances of the given $classname (child_entity) that reference a deleted $target_entity (parent_entity)
                    // e.g.  find all undeleted datafield entities that reference a deleted datatype
                    //       find all undeleted shortvarchar entities that reference a deleted datarecord
                    $query = $em->createQuery(
                       'SELECT child_entity.id AS child_entity_id, parent_entity.id AS parent_entity_id
                        FROM ODRAdminBundle:'.$classname.' AS child_entity
                        JOIN ODRAdminBundle:'.$target_entity.' AS parent_entity WITH child_entity.'.$relation.' = parent_entity
                        WHERE parent_entity.deletedAt IS NOT NULL AND child_entity.deletedAt IS NULL'
                    );
                    $results = $query->getArrayResult();

                    if ( is_array($results) ) {
                        print count($results).' undeleted '.$classname.' entities that reference a deleted '.$relation.'...'."\n";

                        $entity_ids = array();
                        foreach ($results as $result) {
                            print ' -- '.$result['child_entity_id'].' => '.$result['parent_entity_id']."\n";
                            $entity_ids[] = $result['child_entity_id'];
                        }

                        if ( count($results) > 0 ) {
                            // TODO - should this also check for deletedBy?
                            $query = $em->createQuery(
                               'UPDATE ODRAdminBundle:'.$classname.' AS e
                                SET e.deletedAt = :now
                                WHERE e.id IN (:entity_ids)'
                            )->setParameters(
                                array(
                                    'now' => new \DateTime(),
                                    'entity_ids' => $entity_ids
                                )
                            );
                            $rows = $query->execute();

                            print ' >> deleting '.$rows.' rows...';
                        }

                        print "\n\n";
                    }
                }
            }

            if ($save)
                $conn->commit();
            else
                $conn->rollBack();

            $em->getFilters()->enable('softdeleteable');

            print '<pre>';
        }
        catch (\Exception $e) {
            if ( !is_null($conn) && $conn->isTransactionActive() )
                $conn->rollBack();

            $em->getFilters()->enable('softdeleteable');

            $source = 0xed5c5053;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'text/html');
        return $response;
    }


    /**
     * Some entities currently don't check for or delete other entities that relate to them...
     * Therefore, they need to be removed so self::findorphanedentitiesAction() doesn't report them.
     *
     * @param $query_targets
     *
     * @return mixed
     */
    private function removeAcceptableEntries($query_targets)
    {
        $storage_entities = array(
            'Boolean',
            'DatetimeValue',
            'DecimalValue',
            'File',
            'Image',
            'IntegerValue',
            'ShortVarchar',
            'MediumVarchar',
            'LongVarchar',
            'LongText',

            'DataRecordFields',
            'RadioOptions',
            'Tags',
            'ImageSizes',

            'RadioSelection',
            'TagSelection'
        );
        print "The process of deleting datafields/datarecords currently ignores these entities...\n";
        foreach ($storage_entities as $key => $classname) {
            if ( isset($query_targets[$classname]['dataField']) ) {
                unset( $query_targets[$classname]['dataField'] );
                print '>> ignoring undeleted '.$classname.' entities that reference deleted dataFields'."\n";
            }
            if ( isset($query_targets[$classname]['dataFields']) ) {
                unset( $query_targets[$classname]['dataFields'] );
                print '>> ignoring undeleted '.$classname.' entities that reference deleted dataFields'."\n";
            }
            if ( isset($query_targets[$classname]['dataRecord']) ) {
                unset( $query_targets[$classname]['dataRecord'] );
                print '>> ignoring undeleted '.$classname.' entities that reference deleted dataRecords'."\n";
            }
            if ( isset($query_targets[$classname]['radioSelection']) ) {
                unset( $query_targets[$classname]['radioSelection'] );
                print '>> ignoring undeleted '.$classname.' entities that reference deleted dataRecords'."\n";
            }
            if ( isset($query_targets[$classname]['tagSelection']) ) {
                unset( $query_targets[$classname]['tagSelection'] );
                print '>> ignoring undeleted '.$classname.' entities that reference deleted dataRecords'."\n";
            }
        }
        print "\n";

        $datatype_entities = array(
            'DataFields',
            'DataRecord',
            'Group',
            'GroupDatatypePermissions',
            'RenderPluginInstance',
            'RenderPluginMap',
        );
        print "The process of deleting datatypes currently ignores these entities...\n";
        foreach ($datatype_entities as $key => $classname) {
            if ( isset($query_targets[$classname]['dataType']) ) {
                unset( $query_targets[$classname]['dataType'] );
                print '>> ignoring undeleted '.$classname.' entities that reference deleted dataTypes'."\n";
            }
        }
        print "\n";

        print "Deleting Themes intentionally ignores ThemeElements...\n";
        if ( isset($query_targets['ThemeElement']['theme']) ) {
            unset( $query_targets['ThemeElement']['theme'] );
            print '>> ignoring undeleted ThemeElement entities that reference deleted themes'."\n";
        }
        print "\n";

        print "DisplaytemplateController::saverenderpluginsettingsAction() doesn't delete these when deleting renderPluginInstances\n";
        if ( isset($query_targets['RenderPluginMap']['renderPluginInstance']) ) {
            unset( $query_targets['RenderPluginMap']['renderPluginInstance'] );
            print '>> ignoring undeleted RenderPluginMap entities that reference deleted renderPluginInstances'."\n";
        }
        if ( isset($query_targets['RenderPluginOptions']['renderPluginInstance']) ) {
            unset( $query_targets['RenderPluginOptions']['renderPluginInstance'] );
            print '>> ignoring undeleted RenderPluginOptions entities that reference deleted renderPluginInstances'."\n";
        }
        print "\n";

        if ( isset($query_targets['DataFields']['masterDataField']) ) {
            unset( $query_targets['DataFields']['masterDataField'] );
            print '>> TODO - ignore undeleted DataField entities that reference deleted masterDataFields??'."\n";
        }
        if ( isset($query_targets['DataType']['masterDataType']) ) {
            unset( $query_targets['DataType']['masterDataType'] );
            print '>> TODO - ignore undeleted DataType entities that reference deleted masterDataTypes??'."\n";
        }
        print "\n";

        print "\n";
        return $query_targets;
    }


    /**
     * Theme elements should either be empty, or have one or more theme datafields, or exactly one
     * theme datatype entry.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function themeElementcheckAction(Request $request)
    {
        $save = false;
//        $save = true;

        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();

        try {
            $query = $em->createQuery(
               'SELECT t.id AS t_id, te.id AS te_id, tdf.id AS tdf_id, df.id AS df_id, tdt.id AS tdt_id, dt.id AS dt_id
                FROM ODRAdminBundle:Theme AS t
                JOIN ODRAdminBundle:ThemeElement AS te WITH te.theme = t
                LEFT JOIN ODRAdminBundle:ThemeDataField AS tdf WITH tdf.themeElement = te
                LEFT JOIN ODRAdminBundle:DataFields AS df WITH tdf.dataField = df
                LEFT JOIN ODRAdminBundle:ThemeDataType AS tdt WITH tdt.themeElement = te
                LEFT JOIN ODRAdminBundle:DataType AS dt WITH tdt.dataType = dt
                WHERE t.deletedAt IS NULL AND te.deletedAt IS NULL AND tdf.deletedAt IS NULL
                AND tdt.deletedAt IS NULL AND df.deletedAt IS NULL AND dt.deletedAt IS NULL'
            );
            $results = $query->getArrayResult();

            $tmp = array();
            foreach ($results as $result) {
                $t_id = $result['t_id'];
                $te_id = $result['te_id'];
                $tdf_id = $result['tdf_id'];
                $df_id = $result['df_id'];
                $tdt_id = $result['tdt_id'];
                $dt_id = $result['dt_id'];

                if ( is_null($tdf_id) || is_null($tdt_id) )
                    continue;

                if ( !isset($tmp[$t_id]) )
                    $tmp[$t_id] = array();
                if ( !isset($tmp[$t_id][$te_id]) ) {
                    $tmp[$t_id][$te_id] = array(
                        'theme_datafields' => array(),
                        'theme_dataypes' => array()
                    );
                }

                $tmp[$t_id][$te_id]['theme_datafields'][$tdf_id] = $df_id;
                $tmp[$t_id][$te_id]['theme_dataypes'][$tdt_id] = $dt_id;
            }

            exit('<pre>'.print_r($tmp, true).'</pre>' );
        }
        catch (\Exception $e) {
            // Don't want any changes made being saved to the database
            $em->clear();

            $source = 0xd5882c91;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Finds and reports datatypes that don't have a master theme...most likely going to be caused
     * by a critical error during their creation...
     *
     * @param Request $request
     *
     * @return Response
     */
    public function finddatatypeswithoutmasterthemesAction($check_top_level, Request $request)
    {
        $top_level = true;
        if ( $check_top_level == 0 )
            $top_level = false;

        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();

        try {
            $query = null;
            if (!$top_level) {
                $query = $em->createQuery(
                   'SELECT dt.id AS dt_id, t.id AS t_id, t.themeType AS theme_type, p_t.id AS pt_id, s_t.id AS st_id
                    FROM ODRAdminBundle:DataType AS dt
                    LEFT JOIN ODRAdminBundle:Theme AS t WITH t.dataType = dt
                    LEFT JOIN ODRAdminBundle:Theme AS p_t WITH t.parentTheme = p_t
                    LEFT JOIN ODRAdminBundle:Theme AS s_t WITH t.sourceTheme = s_t
                    WHERE 1=1 AND dt != dt.grandparent
                    AND dt.deletedAt IS NULL AND t.deletedAt IS NULL AND s_t.deletedAt IS NULL
                    AND p_t.deletedAt IS NULL
                    ORDER BY dt.id'
                )->setParameters(
                    array(
//                        'theme_type' => 'master'
                    )
                );
            }
            else {
                $query = $em->createQuery(
                   'SELECT dt.id AS dt_id, t.id AS t_id, t.themeType AS theme_type, p_t.id AS pt_id, s_t.id AS st_id
                    FROM ODRAdminBundle:DataType AS dt
                    LEFT JOIN ODRAdminBundle:Theme AS t WITH t.dataType = dt
                    LEFT JOIN ODRAdminBundle:Theme AS p_t WITH t.parentTheme = p_t
                    LEFT JOIN ODRAdminBundle:Theme AS s_t WITH t.sourceTheme = s_t
                    WHERE 1=1 AND dt = dt.grandparent
                    AND dt.deletedAt IS NULL AND t.deletedAt IS NULL AND s_t.deletedAt IS NULL
                    AND p_t.deletedAt IS NULL
                    ORDER BY dt.id'
                )->setParameters(
                    array(
//                        'theme_type' => 'master'
                    )
                );
            }
            $results = $query->getArrayResult();

//            dt = dt.grandparent AND t.themeType != :theme_type

            print '<pre>';

            $theme_data = array();
            foreach ($results as $result) {
                $dt_id = $result['dt_id'];
                $t_id = $result['t_id'];
                $st_id = $result['st_id'];
                $pt_id = $result['pt_id'];
                $theme_type = $result['theme_type'];

                if ( !isset($theme_data[$dt_id]) )
                    $theme_data[$dt_id] = array();

                $theme_data[$dt_id][$t_id] = array(
                    'theme_type' => $theme_type,
                    'source' => $st_id,
                    'parent' => $pt_id,
                );
            }

            print print_r($theme_data, true);

            $datatypes_with_master_themes = array();
            foreach ($theme_data as $dt_id => $theme_datum) {
                if ( !isset($datatypes_with_master_themes[$dt_id]) )
                    $datatypes_with_master_themes[$dt_id] = false;

                foreach ($theme_datum as $t_id => $t_data) {
                    $st_id = $t_data['source'];
                    if ($t_id === $st_id)
                        $datatypes_with_master_themes[$dt_id] = true;
                }
            }

            foreach ($datatypes_with_master_themes as $dt_id => $value) {
                if ($value === true)
                    unset( $datatypes_with_master_themes[$dt_id] );
            }

            print print_r($datatypes_with_master_themes, true);
            print count($datatypes_with_master_themes).' datatypes do not have correctly formatted master themes'."\n";


            if ($top_level) {
                // FIX TOP-LEVEL DATATYPES
                $strings = array();
                foreach ($datatypes_with_master_themes as $dt_id => $value) {
                    print "----------------------------------------\n";
                    print print_r($theme_data[$dt_id], true)."\n";

                    // Top-level datatypes (including linked datatypes) can have multiple "master"
                    //  themes...but should have just one theme where theme.id == parent_theme_id...
                    //  all other themes for this datatype should point to that one
                    $source_theme_id = null;
                    foreach ($theme_data[$dt_id] as $t_id => $t_data) {
                        if ($t_id == '' || $t_data['source'] == '')
                            continue;

                        if ($t_data['theme_type'] === 'master' && $t_data['parent'] === $t_id) {
                            if ( !is_null($source_theme_id) ) {
                                $source_theme_id = '!!!';
                                print 'MULTIPLE MASTER THEMES DETECTED FOR TOP-LEVEL DATATYPE';
                            }
                            else {
                                $source_theme_id = $t_id;
                            }
                        }
                    }

                    // Now that we've found the intended source theme...
                    if (!is_null($source_theme_id)) {
                        foreach ($theme_data[$dt_id] as $t_id => $t_data) {
                            $string = "UPDATE odr_theme SET source_theme_id = ".$source_theme_id." WHERE id = ".$t_id.";\n";
                            $strings[] = $string;

                            print $string;
                        }
                    }
                    print "\n";
                }

                print 'START TRANSACTION;'."\n";
                foreach ($strings as $string)
                    print $string;
                print 'COMMIT;'."\n";
            }
            else {
                // FIX CHILD DATATYPES
                $strings = array();
                foreach ($datatypes_with_master_themes as $dt_id => $value) {
                    print "----------------------------------------\n";
                    print print_r($theme_data[$dt_id], true)."\n";

                    // Child datatypes are supposed to only have a single "master" theme...any
                    //  variants use that one theme as their source
                    $source_theme_id = null;
                    foreach ($theme_data[$dt_id] as $t_id => $t_data) {
                        if ($t_id == '' || $t_data['source'] == '')
                            continue;

                        if ($t_data['theme_type'] === 'master') {
                            if ( !is_null($source_theme_id) ) {
                                $source_theme_id = '!!!';
                                print 'MULTIPLE MASTER THEMES DETECTED FOR CHILD DATATYPE';
                            }
                            else {
                                $source_theme_id = $t_id;
                            }
                        }
                    }

                    // Now that we've found the intended source theme...
                    foreach ($theme_data[$dt_id] as $t_id => $t_data) {
                        if ($t_id == '' || $t_data['source'] == '')
                            continue;

                        $string = "UPDATE odr_theme SET source_theme_id = ".$source_theme_id." WHERE id = ".$t_id.";\n";
                        $strings[] = $string;

                        print $string;
                    }
                    print "\n";
                }

                print 'START TRANSACTION;'."\n";
                foreach ($strings as $string)
                    print $string;
                print 'COMMIT;'."\n";
            }

            print '</pre>';

            exit();
        }
        catch (\Exception $e) {
            // Don't want any changes made being saved to the database
            $em->clear();

            $source = 0xfa5b93c9;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Ensures that all themes with the same "parent theme" are all eventually accessible via the
     * child_theme_id property of theme_datatype entries within the "parent theme".
     *
     * @param Request $request
     *
     * @return Response
     */
    public function tracethemeancestoryAction(Request $request)
    {
        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $conn = $em->getConnection();

            $query =
               'SELECT
                    t.id AS t_id, t.parent_theme_id, t.source_theme_id, t.data_type_id AS dt_id,
                    tdt.child_theme_id, tdt.data_type_id AS child_datatype_id
                FROM odr_theme t
                JOIN odr_theme_element te ON te.theme_id = t.id
                JOIN odr_theme_data_type tdt ON tdt.theme_element_id = te.id
                WHERE t.deletedAt IS NULL AND te.deletedAt IS NULL AND tdt.deletedAt IS NULL';

            $results = $conn->fetchAll($query);
//            exit('<pre>'.print_r($results, true).'</pre>');

            $dt_map = array();
            $data = array();
            foreach ($results as $result) {
                $dt_id = intval($result['dt_id']);
                $pt_id = intval($result['parent_theme_id']);
                $child_theme_id = intval($result['child_theme_id']);
                $theme_id = intval($result['t_id']);

                if ( !isset($data[$pt_id]) )
                    $data[$pt_id] = array();

                $data[$pt_id][$child_theme_id] = $theme_id;

                $dt_map[$theme_id] = $dt_id;
            }
            print '<pre>'.print_r($data, true).'</pre>';

            print '<pre>';
            foreach ($data as $t_id => $datum) {
                foreach ($datum as $child_theme_id => $parent_theme_id) {
                    if ( $parent_theme_id !== $t_id && !isset($datum[$parent_theme_id]) )
                        print 'theme '.$child_theme_id.' does not have an ancestor inside parent theme '.$t_id."\n";
                }
            }
            print '</pre>';

            exit();
        }
        catch (\Exception $e) {
            $source = 0x5fc5aa02;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * A number of datafields in the database have a master datafield, but don't also have its
     * associated field_uuid as their template_field_uuid...
     *
     * @param Request $request
     *
     * @return Response
     */
    public function fixmissingdatafielduuidsAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        $save = false;
//        $save = true;

        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();
        $conn = $em->getConnection();

        try {
            $conn->beginTransaction();

            $query = $em->createQuery(
               'SELECT df.id AS df_id, mdf.id AS mdf_id
                FROM ODRAdminBundle:DataFields df
                JOIN ODRAdminBundle:DataFields mdf WITH df.masterDataField = mdf
                WHERE df.masterDataField IS NOT NULL AND df.templateFieldUuid IS NULL
                AND df.deletedAt IS NULL AND mdf.deletedAt IS NULL'
            );
            $results = $query->getArrayResult();

            print '<pre>'.count($results).' datafields have a master datafield but no template_uuid'."\n";
            foreach ($results as $result)
                print '-- '.$result['df_id'].' => '.$result['mdf_id']."\n";

            $sql =
               'UPDATE odr_data_fields mdf, odr_data_fields df
                SET df.template_field_uuid = mdf.field_uuid
                WHERE df.master_datafield_id = mdf.id
                AND df.master_datafield_id IS NOT NULL AND df.template_field_uuid IS NULL
                AND df.deletedAt IS NULL AND mdf.deletedAt IS NULL';
            $rows = $conn->executeUpdate($sql);
            print 'updated '.$rows.' rows';
            print '</pre>';

            if ($save)
                $conn->commit();
            else
                $conn->rollBack();
        }
        catch (\Exception $e) {
            if ( !is_null($conn) && $conn->isTransactionActive() )
                $conn->rollBack();

            $source = 0x5fc5aa03;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'text/html');
        return $response;
    }


    /**
     * Should only ever be a single active (user, group) pair in the database...
     *
     * @param Request $request
     *
     * @return Response
     */
    public function findduplicateusergroupsAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            $query = $em->createQuery(
               'SELECT u.id AS user_id, g.id AS group_id
                FROM ODROpenRepositoryUserBundle:User AS u
                JOIN ODRAdminBundle:UserGroup AS ug WITH ug.user = u
                JOIN ODRAdminBundle:Group AS g WITH ug.group = g
                WHERE ug.deletedAt IS NULL AND g.deletedAt IS NULL
                ORDER BY u.id, g.id'
            );
            $results = $query->getArrayResult();

            print '<pre>';
            $data = array();
            foreach ($results as $result) {
                $user_id = $result['user_id'];
                $group_id = $result['group_id'];

                if ( !isset($data[$user_id]) )
                    $data[$user_id] = array();

                if ( isset($data[$user_id][$group_id]) )
                    print ' user '.$user_id.' has multiple entries for group '.$group_id."\n";

                $data[$user_id][$group_id] = 1;
            }
            print '</pre>';
        }
        catch (\Exception $e) {
            $source = 0xc0b59c97;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'text/html');
        return $response;
    }


    /**
     * Datatypes shouldn't claim external_id/name/sort/background_image datafields that actually
     * belong to another Datatype...
     *
     * @param Request $request
     *
     * @return Response
     */
    public function finddatatypemetaerrorsAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        $save = false;
//        $save = true;

        $conn = null;

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $conn = $em->getConnection();
            $conn->beginTransaction();

            // ----------------------------------------
            // If a datatype has
            $relationships = array(
                'externalIdField',
                'nameField',
//                'sortField',
                'backgroundImageField'
            );

            foreach ($relationships as $relationship) {
                $query = $em->createQuery(
                   'SELECT dt_1.id AS dt_1_id, dtm_1.shortName AS dt_1_name, dfm.fieldName AS df_name, dt_2.id AS dt_2_id, dtm_2.shortName AS dt_2_name
                    FROM ODRAdminBundle:DataType AS dt_1
                    JOIN ODRAdminBundle:DataTypeMeta AS dtm_1 WITH dtm_1.dataType = dt_1
                    JOIN ODRAdminBundle:DataFields AS df WITH dtm_1.'.$relationship.' = df
                    JOIN ODRAdminBundle:DataFieldsMeta AS dfm WITH dfm.dataField = df
                    JOIN ODRAdminBundle:DataType AS dt_2 WITH df.dataType = dt_2
                    JOIN ODRAdminBundle:DataTypeMeta AS dtm_2 WITH dtm_2.dataType = dt_2
                    WHERE dt_1.id != dt_2.id
                    AND dt_1.deletedAt IS NULL AND dtm_1.deletedAt IS NULL
                    AND df.deletedAt IS NULL AND dfm.deletedAt IS NULL
                    AND dt_2.deletedAt IS NULL AND dtm_2.deletedAt IS NULL'
                );
                $results = $query->getArrayResult();

                print_r( '<pre>Datatypes claiming a '.$relationship.' datafield that belongs to another Datatype: '.print_r($results, true).'</pre>' );

                $datatype_ids = array();
                foreach ($results as $result) {
                    $dt_1_id = $result['dt_1_id'];
                    $datatype_ids[] = $dt_1_id;
                }

                print_r( '<pre>clearing '.$relationship.' for datatypes: '.print_r($datatype_ids, true).'</pre>' );

                $update_query = $em->createQuery(
                   'UPDATE ODRAdminBundle:DataTypeMeta AS dtm
                    SET dtm.'.$relationship.' = NULL
                    WHERE dtm.deletedAt IS NULL AND dtm.dataType IN (:datatype_ids)'
                )->setParameters( array('datatype_ids' => $datatype_ids) );
                $rows = $update_query->execute();
                print '<pre>updated '.$rows.' rows</pre>';
            }


            // ----------------------------------------
            // Sortfields can technically belong to another datatype, but only if the ancestor
            //  datatype doesn't allow multiple records of the descendant datatype

            // Find all instances where datatype A's sortfield belongs to datatype B...
            $query = $em->createQuery(
               'SELECT dt_1.id AS dt_1_id, dtm_1.shortName AS dt_1_name, df.id AS df_id, dfm.fieldName AS df_name, dt_2.id AS dt_2_id, dtm_2.shortName AS dt_2_name
                FROM ODRAdminBundle:DataType AS dt_1
                JOIN ODRAdminBundle:DataTypeMeta AS dtm_1 WITH dtm_1.dataType = dt_1
                JOIN ODRAdminBundle:DataFields AS df WITH dtm_1.sortField = df
                JOIN ODRAdminBundle:DataFieldsMeta AS dfm WITH dfm.dataField = df
                JOIN ODRAdminBundle:DataType AS dt_2 WITH df.dataType = dt_2
                JOIN ODRAdminBundle:DataTypeMeta AS dtm_2 WITH dtm_2.dataType = dt_2
                WHERE dt_1.id != dt_2.id
                AND dt_1.deletedAt IS NULL AND dtm_1.deletedAt IS NULL
                AND df.deletedAt IS NULL AND dfm.deletedAt IS NULL
                AND dt_2.deletedAt IS NULL AND dtm_2.deletedAt IS NULL'
            );
            $results = $query->getArrayResult();

            print_r( '<pre>Datatypes claiming a sortField datafield that belongs to another Datatype: '.print_r($results, true).'</pre>' );

            // If there are any instances of datatype A's sortfield belonging to datatype B...
            $affected_datatypes = array();
            foreach ($results as $result) {
                // ...check whether datatype A links to datatype B
                $ancestor_id = $result['dt_1_id'];
                $descendant_id = $result['dt_2_id'];

                $df_id = $result['df_id'];
                $df_name = $result['df_name'];
                $ancestor_name = $result['dt_1_name'];
                $descendant_name = $result['dt_2_name'];

                $query = $em->createQuery(
                   'SELECT dtm.is_link, dtm.multiple_allowed
                    FROM ODRAdminBundle:DataTree AS dt
                    JOIN ODRAdminBundle:DataTreeMeta AS dtm WITH dtm.dataTree = dt
                    WHERE dt.ancestor = :ancestor_id AND dt.descendant = :descendant_id
                    AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL'
                )->setParameters(
                    array(
                        'ancestor_id' => $ancestor_id,
                        'descendant_id' => $descendant_id
                    )
                );
                $sub_results = $query->getArrayResult();

                if ( empty($sub_results) ) {
                    // Datatype A isn't even related to Datatype B
                    $affected_datatypes[] = $ancestor_id;
                }
                else {
                    $is_link = $sub_results[0]['is_link'];
                    $multiple_allowed = $sub_results[0]['multiple_allowed'];

                    if ( $is_link == 0 ) {
                        // Datatype B is a child of Datatype A
                        $affected_datatypes[] = $ancestor_id;
                    }
                    else if ( $multiple_allowed == 1 ) {
                        // Datatype A can link to multiple datarecords of Datatype B...sorting will
                        //  be nonsense
                        $affected_datatypes[] = $ancestor_id;
                    }
                    else {
                        print '<pre><b>Datatype '.$ancestor_id.' ("'.$ancestor_name.'") is allowed to sort with the field '.$df_id.' ("'.$df_name.'"), belonging to Datatype '.$descendant_id.' ("'.$descendant_name.'")</b></pre>';
                    }
                }
            }

            print_r( '<pre>clearing sortField for datatypes: '.print_r($affected_datatypes, true).'</pre>' );

            $update_query = $em->createQuery(
               'UPDATE ODRAdminBundle:DataTypeMeta AS dtm
                SET dtm.sortField = NULL
                WHERE dtm.deletedAt IS NULL AND dtm.dataType IN (:datatype_ids)'
            )->setParameters( array('datatype_ids' => $datatype_ids) );
            $rows = $update_query->execute();
            print '<pre>updated '.$rows.' rows</pre>';


            // ----------------------------------------
            if (!$save)
                $conn->rollBack();
            else
                $conn->commit();
        }
        catch (\Exception $e) {

            if (!is_null($conn) && $conn->isTransactionActive())
                $conn->rollBack();

            $source = 0x3e162dec;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'text/html');
        return $response;
    }


    /**
     * Checks whether the odr_field_type table is up to date
     * @param Request $request
     *
     * @return Response
     */
    public function checkFieldtypeTableAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            // TODO - needs to be able to define existing fieldtypes?
            // Define what the fieldtype table should have...arrays is [typeclass] => typename
            $config = array(
                'Boolean' => 'Boolean',
                'File' => 'File',
                'Image' => 'Image',
                'Integer' => 'IntegerValue',
                'Decimal' => 'DecimalValue',
                'Paragraph Text' => 'LongText',
                'Long Text' => 'LongVarchar',
                'Medium Text' => 'MediumVarchar',
                'Short Text' => 'ShortVarchar',
                'Single Radio' => 'Radio',
                'Single Select' => 'Radio',
                'Multiple Radio' => 'Radio',
                'Multiple Select' => 'Radio',
                'DateTime' => 'DatetimeValue',
                'Markdown' => 'Markdown',
                'Tags' => 'Tag'
            );

            $can_be_unique = array(
//                'Boolean' => 'Boolean',
//                'File' => 'File',
//                'Image' => 'Image',
                'Integer' => 'IntegerValue',
                'Decimal' => 'DecimalValue',
//                'Paragraph Text' => 'LongText',
                'Long Text' => 'LongVarchar',
                'Medium Text' => 'MediumVarchar',
                'Short Text' => 'ShortVarchar',
//                'Single Radio' => 'Radio',
//                'Single Select' => 'Radio',
//                'Multiple Radio' => 'Radio',
//                'Multiple Select' => 'Radio',
//                'DateTime' => 'DatetimeValue',
//                'Markdown' => 'Markdown',
//                'Tags' => 'Tag'
            );
            $can_be_sort_field = array(
//                'Boolean' => 'Boolean',
//                'File' => 'File',
//                'Image' => 'Image',
                'Integer' => 'IntegerValue',
                'Decimal' => 'DecimalValue',
//                'Paragraph Text' => 'LongText',
                'Long Text' => 'LongVarchar',
                'Medium Text' => 'MediumVarchar',
                'Short Text' => 'ShortVarchar',
                'Single Radio' => 'Radio',
                'Single Select' => 'Radio',
//                'Multiple Radio' => 'Radio',
//                'Multiple Select' => 'Radio',
                'DateTime' => 'DatetimeValue',
//                'Markdown' => 'Markdown',
//                'Tags' => 'Tag'
            );

            // Get the same set of data from the database...
            $query = $em->createQuery(
                'SELECT ft.id, ft.typeName, ft.typeClass, ft.canBeUnique, ft.canBeSortField
                FROM ODRAdminBundle:FieldType AS ft
                WHERE ft.deletedAt IS NULL'
            );
            $results = $query->getArrayResult();

            // Check whether the two arrays are the same
            $changes = array();
            print '<pre>';
            foreach ($results as $ft) {
                $id = $ft['id'];
                $typename = $ft['typeName'];
                $typeclass = $ft['typeClass'];
                $canBeUnique = $ft['canBeUnique'];
                $canBeSortField = $ft['canBeSortField'];

                if ( !isset($config[$typename]) ) {
                    print "Unrecognized fieldtype \"".$typename."\"\n";
                    continue;
                }
                if ( $config[$typename] !== $typeclass ) {
                    print "The fieldtype \"".$typename."\" expects the typeclass \"".$config[$typename]."\", but got \"".$typeclass."\"\n";
                    $changes[] = 'UPDATE odr_field_type SET type_class = "'.$config[$typename].'" WHERE id = '.$id.';';
                }

                // can_be_unique...
                if ( isset($can_be_unique[$typename]) && $canBeUnique == 0 ) {
                    print "The fieldtype \"".$typename."\" should be allowed to be unique, but isn't currently\n";
                    $changes[] = 'UPDATE odr_field_type SET can_be_unique = 1 WHERE id = '.$id.';';
                }
                if ( !isset($can_be_unique[$typename]) && $canBeUnique == 1 ) {
                    print "The fieldtype \"".$typename."\" isn't allowed to be unique, but currently is\n";
                    $changes[] = 'UPDATE odr_field_type SET can_be_unique = 0 WHERE id = '.$id.';';
                }

                // can_be_sort_field...
                if ( isset($can_be_sort_field[$typename]) && $canBeSortField == 0 ) {
                    print "The fieldtype \"".$typename."\" should be allowed to be a sortfield, but isn't currently\n";
                    $changes[] = 'UPDATE odr_field_type SET can_be_sort_field = 1 WHERE id = '.$id.';';
                }
                if ( !isset($can_be_sort_field[$typename]) && $canBeSortField == 1 ) {
                    print "The fieldtype \"".$typename."\" is not allowed to be a sortfield, but currently is\n";
                    $changes[] = 'UPDATE odr_field_type SET can_be_sort_field = 0 WHERE id = '.$id.';';
                }

                // Found the info for this fieldtype in the database...unset so we can track missing fieldtypes
                unset( $config[$typename] );
            }

            foreach ($config as $typename => $typeclass)
                print "The fieldtype \"".$typename."\" (\"".$typeclass."\") is not defined in the database\n";

            print '</pre>';

            print '<pre>';
            foreach ($changes as $change)
                print $change."\n";
            print '</pre>';
        }
        catch (\Exception $e) {
            $source = 0x4394c297;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'text/html');
        return $response;
    }


    /**
     * Returns a page that displays the cached array version of two datatypes side by side
     *
     * @param $left_datatype_id
     * @param $right_datatype_id
     * @param Request $request
     *
     * @return Response
     */
    public function comparedatatypesAction($left_datatype_id, $right_datatype_id, Request $request)
    {
        $ret = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $site_baseurl = $this->getParameter('site_baseurl');

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');

            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();

            /** @var DataType $left_datatype */
            $left_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($left_datatype_id);
            if ( is_null($left_datatype) )
                throw new ODRNotFoundException('Datatype');

            /** @var DataType $right_datatype */
            $right_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($right_datatype_id);
            if ( is_null($right_datatype) )
                throw new ODRNotFoundException('Datatype');

            if ( $left_datatype->getGrandparent()->getId() !== $left_datatype->getId() )
                throw new ODRBadRequestException();
            if ( $right_datatype->getGrandparent()->getId() !== $right_datatype->getId() )
                throw new ODRBadRequestException();

            // Load the array version of both datatypes
            $left = $dti_service->getDatatypeArray($left_datatype->getId());
            $right = $dti_service->getDatatypeArray($right_datatype->getId());

            // Get rid of the masterDataType and masterDataField entries since they cause mis-alignments
            self::inflateTemplateInfo($left);
            self::inflateTemplateInfo($right);

            $left = self::removeDates($left);
            $right = self::removeDates($right);

            $ret = '
<html>
    <head>
        <script src="'.$site_baseurl.'/js/bundle.js"></script>
        <link rel="stylesheet" href="'.$site_baseurl.'/css/external/pure-grids-responsive-min.css">
        <link href="'.$site_baseurl.'/css/odr.1.8.0.css" type="text/css" rel="stylesheet">
        <link href="'.$site_baseurl.'/css/themes/css_smart/style.1.8.0.css" type="text/css" rel="stylesheet">
    </head>
    <body class="pure-skin-odr">
        <div class="pure-g">
            <div class="pure-u-1-2">
                <pre>'.print_r($left, true).'</pre>
            </div>
            <div class="pure-u-1-2">
                <pre>'.print_r($right, true).'</pre>
            </div>
        </div>
    </body>
</html>';

        }
        catch (\Exception $e) {
            $source = 0x4d2e246e;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response($ret);
        $response->headers->set('Content-Type', 'text/html');
        return $response;
    }


    /**
     * Adds entries to masterDataType and masterDataField properties so they line up better
     *
     * @param array &$array @see DatatypeInfoService::buildDatatypeData()
     */
    private function inflateTemplateInfo(&$array) {
        foreach ($array as $dt_id => $dt) {
            if ( !isset($dt['masterDataType']) )
                $array[$dt_id]['masterDataType'] = array('id' => '', 'unique_id' => '');

            if ( !isset($dt['masterDataType']['id']) )
                $array[$dt_id]['masterDataType']['id'] = '';
            if ( !isset($dt['masterDataType']['unique_id']) )
                $array[$dt_id]['masterDataType']['unique_id'] = '';

            if ( isset($dt['dataFields']) && is_array($dt['dataFields']) ) {
                foreach ($dt['dataFields'] as $df_id => $df) {
                    if ( !isset($df['masterDataField']) )
                        $array[$dt_id]['dataFields'][$df_id]['masterDataField'] = array('id' => '');

                    if ( !isset($df['masterDataField']['id']) )
                        $array[$dt_id]['dataFields'][$df_id]['masterDataField']['id'] = '';
                }
            }
        }
    }


    /**
     * Gets old seeing all the full DateTime objects in the array output...
     *
     * @param array $src
     *
     * @return array
     */
    private function removeDates($src) {

        $dest = array();

        foreach ($src as $key => $value) {
            if ( is_array($value) )
                $dest[$key] = self::removeDates($value);
            elseif ( $key === 'created' || $key === 'updated' || $key === 'publicDate' )
                $dest[$key] = date_format($value, "Y-m-d H:i:s");
            elseif ( $key !== 'deletedAt' )
                $dest[$key] = $value;
        }

        return $dest;
    }
}
