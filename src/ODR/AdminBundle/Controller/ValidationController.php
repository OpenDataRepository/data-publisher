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
use ODR\AdminBundle\Entity\DataFieldsMeta;
use ODR\AdminBundle\Entity\DataRecordMeta;
use ODR\AdminBundle\Entity\DataTree;
use ODR\AdminBundle\Entity\DataTreeMeta;
use ODR\AdminBundle\Entity\DataTypeMeta;
use ODR\AdminBundle\Entity\FileMeta;
use ODR\AdminBundle\Entity\ImageMeta;
use ODR\AdminBundle\Entity\RadioOptionsMeta;
use ODR\AdminBundle\Entity\RenderPlugin;
use ODR\AdminBundle\Entity\ThemeMeta;
use ODR\AdminBundle\Entity\ThemeElementMeta;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


class ValidationController extends ODRCustomController
{

    /**
     * Debug function to force the correct datetime format in the database
     *
     * @param Request $request
     */
    public function fixdatabasedatesAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        $save = false;
//        $save = true;

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

//print "bad created: \n".print_r($bad_created);
//print "bad updated: \n".print_r($bad_updated);
//print "bad deleted: \n".print_r($bad_deleted);
//print "bad publicDate: \n".print_r($bad_publicdate);

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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
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
            $default_render_plugin = $repo_render_plugin->find(1);

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
                    $df = $repo_datafield->find($df_id);

                    $dfm = new DataFieldsMeta();
                    $dfm->setDataField($df);
                    $dfm->setFieldType( $repo_fieldtype->find(9) );     // shortvarchar
                    $dfm->setRenderPlugin($default_render_plugin);

                    $dfm->setMasterRevision(0);
                    $dfm->setTrackingMasterRevision(0);
                    $dfm->setMasterPublishedRevision(0);

                    $dfm->setFieldName('New Field');
                    $dfm->setDescription('Field description');
                    $dfm->setXmlFieldName('');
                    $dfm->setRegexValidator('');
                    $dfm->setPhpValidator('');

                    $dfm->setMarkdownText('');
                    $dfm->setIsUnique(false);
                    $dfm->setRequired(false);
                    $dfm->setSearchable(0);
                    $dfm->setPublicDate( new \DateTime('2200-01-01 00:00:00') );

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
                    $dr = $repo_datarecord->find($dr_id);

                    $drm = new DataRecordMeta();
                    $drm->setDataRecord($dr);
                    $drm->setPublicDate(new \DateTime('2200-01-01 00:00:00'));   // default to not public

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
                    $dt = $repo_datatype->find($dt_id);

                    $dtm = new DataTypeMeta();
                    $dtm->setDataType($dt);
                    $dtm->setRenderPlugin($default_render_plugin);

                    $dtm->setSearchSlug($dt_id);
                    $dtm->setShortName("New Datatype");
                    $dtm->setLongName("New Datatype");
                    $dtm->setDescription("New DataType Description");
                    $dtm->setXmlShortName('');

                    $dtm->setSearchNotesUpper(null);
                    $dtm->setSearchNotesLower(null);

                    $dtm->setPublicDate( new \DateTime('1980-01-01 00:00:00') );

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
                    $file = $repo_file->find($f_id);

                    $fm = new FileMeta();
                    $fm->setFile($file);
                    $fm->setOriginalFileName('file_name');
                    $fm->setExternalId('');
                    $fm->setPublicDate( new \DateTime('1980-01-01 00:00:00') );

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
                 WHERE i.id NOT IN (
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
                    $image = $repo_image->find($i_id);

                    $im = new ImageMeta();
                    $im->setImage($image);
                    $im->setDisplayorder(0);
                    $im->setOriginalFileName('image name');
                    $im->setCaption('image caption');
                    $im->setExternalId('');
                    $im->setPublicDate( new \DateTime('1980-01-01 00:00:00') );

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
                    $theme = $repo_theme->find($t_id);

                    $tm = new ThemeMeta();
                    $tm->setTheme($theme);
                    $tm->setTemplateName('');
                    $tm->setTemplateDescription('');
                    $tm->setIsDefault(false);
                    $tm->setDisplayOrder(0);
                    $tm->setShared(false);
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

            $source = 0xabcdef00;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Looks for and creates any missing meta entries
     *
     * @param Request $request
     *
     * @return Response
     */
    public function deleteextrametaentriesAction(Request $request)
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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
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

            $source = 0xed5c5053;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
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

            // Certain relationships are currently allowed to have undeleted child_entities that
            //  reference a deleted parent entity...
            $query_targets = self::removeAcceptableEntries($query_targets);

            // Load everything regardless of deleted status
            $em->getFilters()->disable('softdeleteable');
            $conn->beginTransaction();

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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * TODO -
     *
     * @param $query_targets
     *
     * @return mixed
     */
    private function removeAcceptableEntries($query_targets)
    {
        // Some entities currently don't check for or delete other entities that relate to them...
        // Therefore, they shouldn't be reported
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
            'ImageSizes',
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

        print "\n";
        return $query_targets;
    }
}
