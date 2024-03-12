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

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataFieldsMeta;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataRecordMeta;
use ODR\AdminBundle\Entity\DataTree;
use ODR\AdminBundle\Entity\DataTreeMeta;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTypeMeta;
use ODR\AdminBundle\Entity\DataTypeSpecialFields;
use ODR\AdminBundle\Entity\FieldType;
use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Entity\FileMeta;
use ODR\AdminBundle\Entity\Image;
use ODR\AdminBundle\Entity\ImageMeta;
use ODR\AdminBundle\Entity\RadioOptions;
use ODR\AdminBundle\Entity\RadioOptionsMeta;
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
use ODR\AdminBundle\Exception\ODRNotImplementedException;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\AdminBundle\Component\Service\UUIDService;
// Symfony
use Doctrine\DBAL\Connection as DBALConnection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


class ValidationController extends ODRCustomController
{

    const SAVE = false;
//    const SAVE = true;

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

            if (self::SAVE)
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

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();

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

            if (!self::SAVE) {
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

        $conn = null;

        try {
            // ----------------------------------------
            // Load required objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();

            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
            $repo_fieldtype = $em->getRepository('ODRAdminBundle:FieldType');
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
//            $repo_datatree = $em->getRepository('ODRAdminBundle:DataTree');
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_file = $em->getRepository('ODRAdminBundle:File');
            $repo_image = $em->getRepository('ODRAdminBundle:Image');
            $repo_radio_options = $em->getRepository('ODRAdminBundle:RadioOptions');
            $repo_theme = $em->getRepository('ODRAdminBundle:Theme');
            $repo_theme_element = $em->getRepository('ODRAdminBundle:ThemeElement');

            /** @var FieldType $default_fieldtype */
            $default_fieldtype = $repo_fieldtype->find(9);    // shortvarchar

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

            if (self::SAVE) {
                foreach ($missing_datafields as $num => $df_id) {
                    /** @var DataFields $df */
                    $df = $repo_datafield->find($df_id);

                    $dfm = new DataFieldsMeta();
                    $dfm->setDataField($df);
                    $dfm->setFieldType($default_fieldtype);

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
                    $dfm->setPreventUserEdits(false);
                    $dfm->setSearchable(DataFields::NOT_SEARCHED);
                    $dfm->setPublicDate( new \DateTime('2200-01-01 00:00:00') );    // not public

                    $dfm->setChildrenPerRow(1);
                    $dfm->setRadioOptionNameSort(false);
                    $dfm->setRadioOptionDisplayUnselected(false);
                    $dfm->setAllowMultipleUploads(false);
                    $dfm->setShortenFilename(false);
                    $dfm->setNewFilesArePublic(false);
                    $dfm->setQualityStr('');


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

            if (self::SAVE) {
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

            if (self::SAVE) {
                foreach ($missing_datatypes as $num => $dt_id) {
                    /** @var DataType $dt */
                    $dt = $repo_datatype->find($dt_id);

                    $dtm = new DataTypeMeta();
                    $dtm->setDataType($dt);

                    $dtm->setSearchSlug($dt->getUniqueId());
                    $dtm->setShortName("New Datatype");
                    $dtm->setLongName("New Datatype");
                    $dtm->setDescription("New DataType Description");
                    $dtm->setXmlShortName('');

                    $dtm->setSearchNotesUpper(null);
                    $dtm->setSearchNotesLower(null);
                    $dtm->setNewRecordsArePublic(false);

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

            if (self::SAVE) {
                foreach ($missing_files as $num => $f_id) {
                    /** @var File $file */
                    $file = $repo_file->find($f_id);

                    $fm = new FileMeta();
                    $fm->setFile($file);
                    $fm->setOriginalFileName('file_name');
                    $fm->setQuality(0);
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

            if (self::SAVE) {
                foreach ($missing_images as $num => $i_id) {
                    /** @var Image $image */
                    $image = $repo_image->find($i_id);

                    $im = new ImageMeta();
                    $im->setImage($image);
                    $im->setDisplayorder(0);
                    $im->setOriginalFileName('image name');
                    $im->setCaption('image caption');
                    $im->setQuality(0);
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

            if (self::SAVE) {
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

            if (self::SAVE) {
                foreach ($missing_themes as $num => $t_id) {
                    /** @var Theme $theme */
                    $theme = $repo_theme->find($t_id);

                    $tm = new ThemeMeta();
                    $tm->setTheme($theme);
                    $tm->setTemplateName('');
                    $tm->setTemplateDescription('');
                    $tm->setDefaultFor(0);
                    $tm->setDisplayOrder(0);
                    $tm->setShared(false);
                    $tm->setSourceSyncVersion(0);
                    $tm->setIsTableTheme(false);
                    $tm->setDisplaysAllResults(false);

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

            if (self::SAVE) {
                foreach ($missing_theme_elements as $num => $te_id) {
                    /** @var ThemeElement $te */
                    $te = $repo_theme_element->find($te_id);

                    $tem = new ThemeElementMeta();
                    $tem->setThemeElement($te);
                    $tem->setDisplayOrder(999);
                    $tem->setCssWidthMed('1-1');
                    $tem->setCssWidthXL('1-1');
                    $tem->setHidden(0);
                    $tem->setHideBorder(false);

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
            if (self::SAVE)
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
                    ORDER BY e.id, em.id'
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

            if (self::SAVE)
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

            if (self::SAVE)
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
     * @param Request $request
     * @return Response
     */
    public function findduplicatespecialfieldsAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if (!$user->hasRole('ROLE_SUPER_ADMIN'))
                throw new ODRForbiddenException();

            $conn = $em->getConnection();
            $query =
               'SELECT dtsf.id AS dtsf_id, dtsf.field_purpose, dtsf.display_order, dtsf.data_type_id AS dt_id, df.id AS df_id
                FROM odr_data_type_special_fields dtsf
                LEFT JOIN odr_data_fields df ON dtsf.data_field_id = df.id
                WHERE dtsf.deletedAt IS NULL';
            $results = $conn->fetchAll($query);

            print '<pre>';

            $entries = array();
            $duplicates = array();
            foreach ($results as $result) {
                $dtsf_id = $result['dtsf_id'];
                $dt_id = $result['dt_id'];
                $df_id = $result['df_id'];
                $field_purpose = $result['field_purpose'];
                $display_order = $result['display_order'];

                if ( !isset($entries[$dt_id]) )
                    $entries[$dt_id] = array();
                if ( !isset($entries[$dt_id][$field_purpose]) )
                    $entries[$dt_id][$field_purpose] = array();

                if ( !isset($entries[$dt_id][$field_purpose][$df_id]) )
                    $entries[$dt_id][$field_purpose][$df_id] = $display_order;
                else {
                    print 'duplicate entry for dt '.$dt_id.' df '.$df_id.' field_purpose '.$field_purpose."\n";
                    $duplicates[] = $dtsf_id;
                }
            }

            $query = 'UPDATE odr_data_type_special_fields dtsf SET dtsf.deletedAt = NOW() WHERE dtsf.id IN ('.implode(',', $duplicates).') AND deletedAt IS NULL';
            print "\n\n".$query."\n";

            print '</pre>';
        }
        catch (\Exception $e) {
            $source = 0x8bc7022b;
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
            /** @var DataType[] $dt_cache */

            $new_dts = array();
            foreach ($extra_ancestors as $ancestor_id => $tmp) {
                foreach ($tmp as $num => $descendant_id) {
                    $dt = new DataTree();
                    $dt->setAncestor( $dt_cache[$ancestor_id] );
                    $dt->setDescendant( $dt_cache[$descendant_id] );
                    $dt->setCreated( new \DateTime() );
                    $dt->setCreatedBy($user);

                    print "-- created datatree entry for ancestor ".$ancestor_id.", descendant ".$descendant_id."...\n";

                    if (self::SAVE) {
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

                if (self::SAVE) {
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

            if (self::SAVE)
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

            if (self::SAVE)
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
//            'RenderPluginInstance',
//            'RenderPluginMap',
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
        if ( isset($query_targets['RenderPluginOptionsMap']['renderPluginInstance']) ) {
            unset( $query_targets['RenderPluginOptionsMap']['renderPluginInstance'] );
            print '>> ignoring undeleted RenderPluginOptionsMap entities that reference deleted renderPluginInstances'."\n";
        }
        print "\n";

        if ( isset($query_targets['DataFields']['masterDataField']) ) {
            unset( $query_targets['DataFields']['masterDataField'] );
            print '>> TODO - modify undeleted DataField entities to no longer reference deleted masterDataFields??'."\n";
        }
        if ( isset($query_targets['DataType']['masterDataType']) ) {
            unset( $query_targets['DataType']['masterDataType'] );
            print '>> TODO - modify undeleted DataType entities to no longer reference deleted masterDataTypes??'."\n";
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
        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();

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

            exit( '<pre>'.print_r($tmp, true).'</pre>' );
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
     * @param int $check_top_level
     * @param Request $request
     *
     * @return Response
     */
    public function finddatatypeswithoutmasterthemesAction($check_top_level, Request $request)
    {
        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();

            $top_level = true;
            if ( $check_top_level == 0 )
                $top_level = false;

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

            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();

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
     * UUIDs are supposed to be unique, but if they're not...
     *
     * @param Request $request
     *
     * @return Response
     */
    public function fixduplicateuuidsAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $conn = $em->getConnection();

            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();

            /** @var UUIDService $uuid_service */
            $uuid_service = $this->container->get('odr.uuid_service');


            // ----------------------------------------
            // The first set of entities should never have any duplicates...
            $entities = array(
                'odr_data_type' => 'unique_id',
                'odr_data_fields' => 'field_uuid',
                'odr_data_record' => 'unique_id',
                'odr_file' => 'unique_id',
                'odr_image' => 'unique_id',
            );

            print '<pre>';
            foreach ($entities as $table_name => $field_name) {
                print 'checking '.$table_name.'...'."\n";

                $query =
                   'SELECT e.id AS id, e.'.$field_name.' AS uuid
                    FROM '.$table_name.' e';
                // Do NOT want to exclude deleted rows
                $results = $conn->fetchAll($query);

                $duplicates = array();
                $tmp = array();
                foreach ($results as $result) {
                    $id = $result['id'];
                    $uuid = $result['uuid'];

                    if ( is_null($uuid) ) {
                        print "\t".'entity '.$id.' does not have a uuid'."\n";
                        $duplicates[] = $id;
                    }
                    else if ( !isset($tmp[$uuid]) ) {
                        $tmp[$uuid] = $id;
                    }
                    else {
                        print "\t".'entity '.$id.' has the same uuid as entity '.$tmp[$uuid]."\n";
                        $duplicates[] = $id;
                    }
                }
                print "\n\n";

                // Regardless of whether any entity has a duplicate uuid, or is missing a uuid...
                //  the fix is the same
                foreach ($duplicates as $id) {
                    switch ($table_name) {
                        case 'odr_data_type':
                            $new_uuid = $uuid_service->generateDatatypeUniqueId();
                            print 'UPDATE '.$table_name.' SET template_group = "'.$new_uuid.'" WHERE grandparent_id = '.$id.';'."\n";
                            print 'UPDATE '.$table_name.' SET '.$field_name.' = "'.$new_uuid.'" WHERE id = '.$id.';'."\n";
                            break;
                        case 'odr_data_fields':
                            $new_uuid = $uuid_service->generateDatafieldUniqueId();
                            print 'UPDATE '.$table_name.' SET template_field_uuid = "'.$new_uuid.'" WHERE master_datafield_id = '.$id.';'."\n";
                            print 'UPDATE '.$table_name.' SET '.$field_name.' = "'.$new_uuid.'" WHERE id = '.$id.';'."\n";
                            break;
                        case 'odr_data_record':
                            $new_uuid = $uuid_service->generateDatarecordUniqueId();
                            print 'UPDATE '.$table_name.' SET '.$field_name.' = "'.$new_uuid.'" WHERE id = '.$id.';'."\n";
                            break;
                        case 'odr_file':
                            $new_uuid = $uuid_service->generateFileUniqueId();
                            print 'UPDATE '.$table_name.' SET '.$field_name.' = "'.$new_uuid.'" WHERE id = '.$id.';'."\n";
                            break;
                        case 'odr_image':
                            $new_uuid = $uuid_service->generateImageUniqueId();
                            print 'UPDATE '.$table_name.' SET '.$field_name.' = "'.$new_uuid.'" WHERE id = '.$id.';'."\n";
                            break;
                    }
                }
                print "\n\n";
            }


            // ----------------------------------------
            // The second set of entities are allowed to have duplicates
            $entities = array(
                'odr_radio_options' => 'radio_option_uuid',
                'odr_tags' => 'tag_uuid',
            );

            foreach ($entities as $table_name => $field_name) {
                print 'checking '.$table_name.'...'."\n";

                $query =
                   'SELECT e.id AS id, e.'.$field_name.' AS uuid, df.id AS df_id
                    FROM '.$table_name.' e
                    LEFT JOIN odr_data_fields df ON e.data_fields_id = df.id
                    WHERE df.master_datafield_id IS NULL';
                // Do NOT want to exclude deleted rows
                $results = $conn->fetchAll($query);

                $duplicates = array();
                $tmp = array();
                foreach ($results as $result) {
                    $id = $result['id'];
                    $uuid = $result['uuid'];
                    $df_id = $result['df_id'];

                    if ( is_null($uuid) ) {
                        print "\t".'entity '.$id.' does not have a uuid'."\n";
                        $duplicates[] = $id;
                    }
                    else if ( !isset($tmp[$uuid]) ) {
                        $tmp[$uuid] = array('id' => $id, 'df_id' => $df_id);
                    }
                    else {
                        print "\t".'entity '.$id.' (df '.$tmp[$uuid]['df_id'].') has the same uuid as entity '.$tmp[$uuid]['id']."\n";
                        $duplicates[] = array('id' => $id, 'df_id' => $df_id);
                    }
                }
                print "\n\n";

                foreach ($duplicates as $id) {
                    switch ($table_name) {
                        case 'odr_radio_options':
                            $new_uuid = $uuid_service->generateRadioOptionUniqueId();
                            print 'TODO'."\n";
                            break;
                        case 'odr_tags':
                            $new_uuid = $uuid_service->generateTagUniqueId();
                            print 'TODO'."\n";
                            break;
                    }
                }
                print "\n\n";
            }

            print '</pre>';
        }
        catch (\Exception $e) {
            $source = 0x291bd39f;
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

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();


            $conn = $em->getConnection();
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

            if (self::SAVE)
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

            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();

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

        $conn = null;

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $conn = $em->getConnection();
            $conn->beginTransaction();

            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();


            // ----------------------------------------
            // Determine whether a datatypeMeta entry is claiming a datafield that belongs to another
            //  datatype
            $relationships = array(
                'externalIdField',
//                'nameField',
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
            // A datatype can have more than one namefield/sortfield...need to find all instances
            //  where the field in question doesn't belong to the datatype...
            $query = $em->createQuery(
               'SELECT dtsf.id AS dtsf_id, dtsf.field_purpose,
                    ancestor.id AS ancestor_id, ancestor_meta.shortName AS ancestor_name,
                    df.id AS df_id, dfm.fieldName AS df_name,
                    descendant.id AS descendant_id, descendant_meta.shortName AS descendant_name
                FROM ODRAdminBundle:DataTypeSpecialFields AS dtsf
                JOIN ODRAdminBundle:DataFields AS df WITH dtsf.dataField = df
                JOIN ODRAdminBundle:DataFieldsMeta AS dfm WITH dfm.dataField = df

                JOIN ODRAdminBundle:DataType AS ancestor WITH dtsf.dataType = ancestor
                JOIN ODRAdminBundle:DataTypeMeta AS ancestor_meta WITH ancestor_meta.dataType = ancestor
                JOIN ODRAdminBundle:DataType AS descendant WITH df.dataType = descendant
                JOIN ODRAdminBundle:DataTypeMeta AS descendant_meta WITH descendant_meta.dataType = descendant
                WHERE ancestor.id != descendant.id
                AND dtsf.deletedAt IS NULL
                AND df.deletedAt IS NULL AND dfm.deletedAt IS NULL
                AND ancestor.deletedAt IS NULL AND ancestor_meta.deletedAt IS NULL
                AND descendant.deletedAt IS NULL AND descendant_meta.deletedAt IS NULL'
            );
            $results = $query->getArrayResult();

            print_r( '<pre>Datatypes claiming a name/sortField datafield that belongs to another Datatype: '.print_r($results, true).'</pre>' );

            // If there are any instances of one datatype's field belonging to another datatype...
            $dtsf_entries_to_delete = array();
            foreach ($results as $result) {
                // ...check whether datatype A links to datatype B
                $ancestor_id = $result['ancestor_id'];
                $descendant_id = $result['descendant_id'];

                $dtsf_id = $result['dtsf_id'];
                $field_purpose = 'name';
                if ( $result['field_purpose'] === DataTypeSpecialFields::SORT_FIELD )
                    $field_purpose = 'sort';

                $df_id = $result['df_id'];
                $df_name = $result['df_name'];
                $ancestor_name = $result['ancestor_name'];
                $descendant_name = $result['descendant_name'];

                if ( $field_purpose === 'name' ) {
                    // If this is a name field, then it's not allowed to come from another datatype
                    $dtsf_entries_to_delete[] = $dtsf_id;
                }
                else {
                    // If this is this a sort field, then it is allowed to come from another datatype
                    //  ...but only if the ancestor -> descendant link only allows a single record
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
                        $dtsf_entries_to_delete[] = $dtsf_id;
                    }
                    else {
                        $is_link = $sub_results[0]['is_link'];
                        $multiple_allowed = $sub_results[0]['multiple_allowed'];

                        if ($is_link == 0) {
                            // Datatype B is a child of Datatype A
                            $dtsf_entries_to_delete[] = $dtsf_id;
                        }
                        else if ($multiple_allowed == 1) {
                            // Datatype A can link to multiple datarecords of Datatype B
                            $dtsf_entries_to_delete[] = $dtsf_id;
                        }
                        else {
                            print '<pre><b>Datatype '.$ancestor_id.' ("'.$ancestor_name.'") is allowed to sort with the field '.$df_id.' ("'.$df_name.'"), belonging to Datatype '.$descendant_id.' ("'.$descendant_name.'")</b></pre>';
                        }
                    }
                }
            }

            print_r( '<pre>clearing special fields: '.print_r($dtsf_entries_to_delete, true).'</pre>' );

            $update_query = $em->createQuery(
               'UPDATE ODRAdminBundle:DataTypeMeta AS dtm
                SET dtm.sortField = NULL
                WHERE dtm.deletedAt IS NULL AND dtm.dataType IN (:datatype_ids)'
            )->setParameters( array('datatype_ids' => $dtsf_entries_to_delete) );
            $rows = $update_query->execute();
            print '<pre>updated '.$rows.' rows</pre>';


            // ----------------------------------------
            if (!self::SAVE)
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

            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();

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

            // These aren't strictly related to the fieldtype table...but they shouldn't exist either
            print "DROP TABLE IF EXISTS odr_checkbox, odr_file_storage, odr_image_storage, odr_radio, odr_xyz_value;\n";
            print "DROP TABLE IF EXISTS odr_user_layout_permissions, odr_user_layout_preferences, odr_layout_meta, odr_layout_data, odr_layout;\n";
            print "DROP TABLE IF EXISTS odr_theme_element_field;\n";
            print "DROP TABLE IF EXISTS odr_user_field_permissions, odr_user_permissions;\n";

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
     * @param int $left_datatype_id
     * @param int $right_datatype_id
     * @param bool $filter
     * @param Request $request
     *
     * @return Response
     */
    public function comparedatatypesAction($left_datatype_id, $right_datatype_id, $filter, Request $request)
    {
        $ret = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $site_baseurl = $this->getParameter('site_baseurl');

            /** @var DatabaseInfoService $dbi_service */
            $dbi_service = $this->container->get('odr.database_info_service');

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
            $left = $dbi_service->getDatatypeArray($left_datatype->getId());
            $right = $dbi_service->getDatatypeArray($right_datatype->getId());

            // Get rid of the masterDataType and masterDataField entries since they cause mis-alignments
            self::inflateTemplateInfo($left);
            self::inflateTemplateInfo($right);

            $left = self::removeDates($left);
            $right = self::removeDates($right);

            $filtered_left = $left;
            $filtered_right = $right;
            if ($filter) {
                $filtered_left = self::removeDuplicates($left, $right);
                $filtered_right = self::removeDuplicates($right, $left);
            }

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
                <pre style="font-size: 0.8em;">'.print_r($filtered_left, true).'</pre>
            </div>
            <div class="pure-u-1-2">
                <pre style="font-size: 0.8em;">'.print_r($filtered_right, true).'</pre>
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
     * @param array &$array @see DatabaseInfoService::buildDatatypeData()
     */
    private function inflateTemplateInfo(&$array)
    {
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
    private function removeDates($src)
    {
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


    /**
     * Copies values from $subject that aren't identical to the "same" values in $control.
     * Only really works on the cached datatype arrays.
     *
     * @param array $subject
     * @param array $control
     *
     * @return array
     */
    private function removeDuplicates($subject, $control)
    {
        $filtered = array();

        $subject_keys = array_keys($subject);
        $control_keys = array_keys($control);

        for ($i = 0; $i < count($subject_keys); $i++) {
            $subject_key = $subject_keys[$i];
            $subject_value = $subject[$subject_key];
            $control_key = $control_keys[$i];
            $control_value = $control[$control_key];

            if ( $subject_key === 'tagTree' && $control_key === 'tagTree' ) {
                // Don't attempt to compare tag trees...the results are useless, assuming they're
                //  even in "the same order" in the first place
            }
            else if ( is_array($subject_value) ) {
                if ( !is_null($control_value) ) {
                    // Can only continue recursion when both $subject_value and $control_value are
                    //  not null...
                    $ret = self::removeDuplicates($subject[$subject_key], $control[$control_key]);
                    if ( !empty($ret) )
                        $filtered[$subject_key] = $ret;
                }
                else {
                    // ...if one is null but the other isn't, then the output won't be aligned
                    $filtered[$subject_key] = $subject_value;
                }
            }
            else {
                switch ($subject_key) {
                    case 'shortName':
                    case 'longName':
                    case 'pluginClassName':
                    case 'fieldName':
                    case 'typeName':
                    case 'optionName':
                    case 'tagName':
                        // Always want the name fields in there
                        $filtered[$subject_key] = $subject_value;
                        break;

                    default:
                        // Don't care about any other value unless it's different
                        if ( $subject_value !== $control_value )
                            $filtered[$subject_key] = $subject_value;
                }
            }
        }

        return $filtered;
    }


    /**
     * Returns a sequence of SQL statements that will delete every single entity from the backend
     * database that are related to the given datatype ids.
     *
     * @param string $datatype_ids
     * @param Request $request
     *
     * @return Response
     */
    public function purgeAction($datatype_ids, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $conn = $em->getConnection();

            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();

            $dt_ids_for_filename = $datatype_ids;
            $initial_datatype_ids = explode(',', $datatype_ids);


            // ----------------------------------------
            // Grandparent Datatypes
            $query =
                'SELECT dt.id AS dt_id
                 FROM odr_data_type AS dt
                 WHERE dt.id IN (?) AND dt.id = dt.grandparent_id';
            $parameters = array(1 => $initial_datatype_ids);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY);
            $results = $conn->fetchAll($query, $parameters, $types);

            $grandparent_datatype_ids = array();
            foreach ($results as $result)
                $grandparent_datatype_ids[] = $result['dt_id'];

            $datatype_ids = array();
            foreach ($grandparent_datatype_ids as $num => $gp_dt_id) {
                $new_datatype_ids = array($gp_dt_id);
                $datatype_ids[$gp_dt_id] = 1;

                while ( !empty($new_datatype_ids) ) {
                    // Get all direct descendants of this datatype, or any metadata datatypes it has
                    $query =
                       'SELECT dt.id AS dt_id
                        FROM odr_data_type AS dt
                        WHERE dt.grandparent_id IN (?) OR dt.metadata_for_id IN (?)';
                    $parameters = array( 1 => $new_datatype_ids, 2 => $new_datatype_ids );
                    $types = array( 1 => DBALConnection::PARAM_INT_ARRAY, 2 => DBALConnection::PARAM_INT_ARRAY );
                    $results = $conn->fetchAll($query, $parameters, $types);

                    $new_datatype_ids = array();
                    foreach ($results as $result) {
                        if ( !isset($datatype_ids[ $result['dt_id'] ]) )
                            $new_datatype_ids[] = $result['dt_id'];
                        $datatype_ids[ $result['dt_id'] ] = 1;
                    }
                }
            }

            $datatype_ids = array_keys($datatype_ids);


            // Datatrees
            $query_str =
                'SELECT dt.id AS dt_id
                 FROM odr_data_tree AS dt
                 WHERE (dt.ancestor_id IN (?) OR dt.descendant_id IN (?))';
            $parameters = array(1 => $datatype_ids, 2 => $datatype_ids);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY, 2 => DBALConnection::PARAM_INT_ARRAY);
            $results = $conn->fetchAll($query_str, $parameters, $types);
            $datatree_ids = array();
            foreach ($results as $result)
                $datatree_ids[] = $result['dt_id'];


            // Datafields
            $query_str =
                'SELECT df.id AS df_id
                 FROM odr_data_fields AS df
                 WHERE df.data_type_id IN (?)';
            $parameters = array(1 => $datatype_ids);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY);
            $results = $conn->fetchAll($query_str, $parameters, $types);
            $datafield_ids = array();
            foreach ($results as $result)
                $datafield_ids[] = $result['df_id'];


            // Datatype special fields
            $query_str =
               'SELECT dtsf.id AS dtsf_id
                FROM odr_data_type_special_fields AS dtsf
                WHERE dtsf.data_type_id IN (?) OR dtsf.data_field_id IN (?)';
            $parameters = array(1 => $datatype_ids, 2 => $datafield_ids);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY, 2 => DBALConnection::PARAM_INT_ARRAY);
            $results = $conn->fetchAll($query_str, $parameters, $types);
            $dtsf_ids = array();
            foreach ($results as $result)
                $dtsf_ids[] = $result['dtsf_id'];


            // Themes
            $query_str =
                'SELECT t.id AS t_id
                 FROM odr_theme AS t
                 WHERE t.data_type_id IN (?)';
            $parameters = array(1 => $datatype_ids);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY);
            $results = $conn->fetchAll($query_str, $parameters, $types);
            $theme_ids = array();
            foreach ($results as $result)
                $theme_ids[] = $result['t_id'];

            // Also need to get all themes that are descendants of the themes getting deleted
            $query_str =
                'SELECT t.id AS t_id
                 FROM odr_theme AS t
                 WHERE t.parent_theme_id IN (?) OR t.source_theme_id IN (?)';
            $parameters = array(1 => $theme_ids, 2 => $theme_ids);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY, 2 => DBALConnection::PARAM_INT_ARRAY);
            $results = $conn->fetchAll($query_str, $parameters, $types);
            foreach ($results as $result)
                $theme_ids[] = $result['t_id'];
            $theme_ids = array_unique($theme_ids);


            // ThemeElements
            $query_str =
                'SELECT te.id AS te_id
                 FROM odr_theme_element AS te
                 WHERE te.theme_id IN (?)';
            $parameters = array(1 => $theme_ids);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY);
            $results = $conn->fetchAll($query_str, $parameters, $types);
            $theme_element_ids = array();
            foreach ($results as $result)
                $theme_element_ids[] = $result['te_id'];


            // RenderPluginInstances
            $query_str =
                'SELECT rpi.id AS rpi_id
                 FROM odr_render_plugin_instance AS rpi
                 WHERE (rpi.data_type_id IN (?) OR rpi.data_field_id IN (?))';
            $parameters = array(1 => $datatype_ids, 2 => $datafield_ids);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY, 2 => DBALConnection::PARAM_INT_ARRAY);
            $results = $conn->fetchAll($query_str, $parameters, $types);
            $render_plugin_instance_ids = array();
            foreach ($results as $result)
                $render_plugin_instance_ids[] = $result['rpi_id'];


            // Groups
            $query_str =
                'SELECT g.id AS g_id
                 FROM odr_group AS g
                 WHERE g.data_type_id IN (?)';
            $parameters = array(1 => $datatype_ids);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY);
            $results = $conn->fetchAll($query_str, $parameters, $types);
            $group_ids = array();
            foreach ($results as $result)
                $group_ids[] = $result['g_id'];


            // Tags
            $query_str =
                'SELECT t.id AS t_id
                 FROM odr_tags AS t
                 WHERE t.data_fields_id IN (?)';
            $parameters = array(1 => $datafield_ids);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY);
            $results = $conn->fetchAll($query_str, $parameters, $types);
            $tag_ids = array();
            foreach ($results as $result)
                $tag_ids[] = $result['t_id'];


            // Radio Options
            $query_str =
                'SELECT ro.id AS ro_id
                 FROM odr_radio_options AS ro
                 WHERE ro.data_fields_id IN (?)';
            $parameters = array(1 => $datafield_ids);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY);
            $results = $conn->fetchAll($query_str, $parameters, $types);
            $radio_option_ids = array();
            foreach ($results as $result)
                $radio_option_ids[] = $result['ro_id'];


            // Images
            $query_str =
                'SELECT i.id AS i_id
                 FROM odr_image AS i
                 WHERE i.data_field_id IN (?)';
            $parameters = array(1 => $datafield_ids);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY);
            $results = $conn->fetchAll($query_str, $parameters, $types);
            $image_ids = array();
            foreach ($results as $result)
                $image_ids[] = $result['i_id'];


            // Files
            $query_str =
                'SELECT f.id AS f_id
                 FROM odr_file AS f
                 WHERE f.data_field_id IN (?)';
            $parameters = array(1 => $datafield_ids);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY);
            $results = $conn->fetchAll($query_str, $parameters, $types);
            $file_ids = array();
            foreach ($results as $result)
                $file_ids[] = $result['f_id'];


            // Datarecords
            $query_str =
                'SELECT dr.id AS dr_id
                 FROM odr_data_record AS dr
                 WHERE dr.data_type_id IN (?)';
            $parameters = array(1 => $datatype_ids);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY);
            $results = $conn->fetchAll($query_str, $parameters, $types);
            $datarecord_ids = array();
            foreach ($results as $result)
                $datarecord_ids[] = $result['dr_id'];


            // Linked Datatree
            $query_str =
                'SELECT ldt.id AS ldt_id
                 FROM odr_linked_data_tree AS ldt
                 WHERE (ldt.ancestor_id IN (?) OR ldt.descendant_id IN (?))';
            $parameters = array(1 => $datarecord_ids, 2 => $datarecord_ids);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY, 2 => DBALConnection::PARAM_INT_ARRAY);
            $results = $conn->fetchAll($query_str, $parameters, $types);
            $linked_datatree_ids = array();
            foreach ($results as $result)
                $linked_datatree_ids[] = $result['ldt_id'];


            // ----------------------------------------
            // Originally, this dumped "DELETE FROM" statements to the browser, which I would then
            //  copy/paste into a terminal to execute.  Unfortunately, this seems to not be entirely
            //  reliable, so had to modify it to create mysqldump files
            $user_tmp_dir = $this->getParameter('odr_tmp_directory').'/user_'.$user->getId();
            if ( !file_exists($user_tmp_dir) )
                mkdir( $user_tmp_dir );

//            $handle = fopen($user_tmp_dir.'/purge_'.$dt_ids_for_filename.'.dmp', 'w');
            $handle = fopen($user_tmp_dir.'/purge.dmp', 'w');
            if ( !$handle )
                throw new ODRException('Unable to open dmp file');

            // ...still is useful to return how many datarecords will be deleted though
            print
                '<html>
                    <pre>number of datarecords: '.count($datarecord_ids).'</pre>
                    <pre>dmp file written to /app/tmp/user_'.$user->getId().'/purge.dmp</pre>
                </html>';

            // ----------------------------------------
            // Set up the dmp file...
            $db_name = $this->getParameter('database_name');
            fprintf($handle, "USE ".$db_name.";\n\n");

            // Layout stuff first...
            if ( !empty($theme_element_ids) ) {
                fprintf($handle, "DELETE FROM odr_theme_data_field tdf WHERE tdf.theme_element_id IN (".implode(',', $theme_element_ids).");\n");
                fprintf($handle, "DELETE FROM odr_theme_data_type tdt WHERE tdt.theme_element_id IN (".implode(',', $theme_element_ids).");\n");
                fprintf($handle, "DELETE FROM odr_theme_element_meta tem WHERE tem.theme_element_id IN (".implode(',', $theme_element_ids).");\n");
                fprintf($handle, "DELETE FROM odr_theme_element te WHERE te.id IN (".implode(',', $theme_element_ids).");\n");
            }
            else {
                fprintf($handle, "# No theme elements to delete\n");
            }

            // sanity check for both of these
            if ( !empty($datafield_ids) )
                fprintf($handle, "DELETE FROM odr_theme_data_field tdf WHERE tdf.data_field_id IN (".implode(',', $datafield_ids).");\n");
            if ( !empty($datatype_ids) )
                fprintf($handle, "DELETE FROM odr_theme_data_type tdt WHERE tdt.data_type_id IN (".implode(',', $datatype_ids).");\n");

            if ( !empty($theme_ids) ) {
                // also need to get all themeDataType entries that reference the themes being deleted
                fprintf($handle, "DELETE FROM odr_theme_data_type tdt WHERE tdt.child_theme_id IN (".implode(',', $theme_ids).");\n");

                fprintf($handle, "DELETE FROM odr_theme_preferences tp WHERE tp.theme_id IN (".implode(',', $theme_ids).");\n");
                fprintf($handle, "DELETE FROM odr_theme_meta tm WHERE tm.theme_id IN (".implode(',', $theme_ids).");\n");
                fprintf($handle, "UPDATE odr_theme t SET t.parent_theme_id = NULL, t.source_theme_id = NULL WHERE t.id IN (".implode(',', $theme_ids).");\n");
                fprintf($handle, "DELETE FROM odr_theme t WHERE t.id IN (".implode(',', $theme_ids).");\n");
            }
            else {
                fprintf($handle, "# No themes to delete\n");
            }

            // ...then storage entities...
            if ( !empty($datafield_ids) ) {
                fprintf($handle, "DELETE FROM odr_boolean e WHERE e.data_field_id IN (".implode(',', $datafield_ids).");\n");
                fprintf($handle, "DELETE FROM odr_datetime_value e WHERE e.data_field_id IN (".implode(',', $datafield_ids).");\n");
                fprintf($handle, "DELETE FROM odr_decimal_value e WHERE e.data_field_id IN (".implode(',', $datafield_ids).");\n");
                fprintf($handle, "DELETE FROM odr_integer_value e WHERE e.data_field_id IN (".implode(',', $datafield_ids).");\n");
                fprintf($handle, "DELETE FROM odr_long_text e WHERE e.data_field_id IN (".implode(',', $datafield_ids).");\n");
                fprintf($handle, "DELETE FROM odr_long_varchar e WHERE e.data_field_id IN (".implode(',', $datafield_ids).");\n");
                fprintf($handle, "DELETE FROM odr_medium_varchar e WHERE e.data_field_id IN (".implode(',', $datafield_ids).");\n");
                fprintf($handle, "DELETE FROM odr_short_varchar e WHERE e.data_field_id IN (".implode(',', $datafield_ids).");\n");
            }
            else {
                fprintf($handle, "# No storage entities to delete\n");
            }

            if ( !empty($radio_option_ids) )
                fprintf($handle, "DELETE FROM odr_radio_selection rs WHERE rs.radio_option_id IN (".implode(',', $radio_option_ids).");\n");
            else {
                fprintf($handle, "# No radio selections to delete\n");
            }
            if ( !empty($tag_ids) )
                fprintf($handle, "DELETE FROM odr_tag_selection ts WHERE ts.tag_id IN (".implode(',', $tag_ids).");\n");
            else {
                fprintf($handle, "# No tag selections to delete\n");
            }

            if ( !empty($file_ids) ) {
                fprintf($handle, "DELETE FROM odr_file_checksum fc WHERE fc.file_id IN (".implode(',', $file_ids).");\n");
                fprintf($handle, "DELETE FROM odr_file_meta fm WHERE fm.file_id IN (".implode(',', $file_ids).");\n");
            }
            else {
                fprintf($handle, "# No files to delete\n");
            }
            if ( !empty($datafield_ids) )
                fprintf($handle, "DELETE FROM odr_file f WHERE f.data_field_id IN (".implode(',', $datafield_ids).");\n");


            if ( !empty($image_ids) ) {
                fprintf($handle, "DELETE FROM odr_image_checksum ic WHERE ic.image_id IN (".implode(',', $image_ids).");\n");
                fprintf($handle, "DELETE FROM odr_image_meta im WHERE im.image_id IN (".implode(',', $image_ids).");\n");
                fprintf($handle, "UPDATE odr_image i SET i.parent_id = NULL, i.image_size_id = NULL WHERE i.id IN (".implode(',', $image_ids).");\n");
            }
            else {
                fprintf($handle, "# No images to delete\n");
            }
            if ( !empty($datafield_ids) ) {
                fprintf($handle, "DELETE FROM odr_image_sizes i_s WHERE i_s.data_fields_id IN (".implode(',', $datafield_ids).");\n");
                fprintf($handle, "DELETE FROM odr_image i WHERE i.data_field_id IN (".implode(',', $datafield_ids).");\n");
            }

            if ( !empty($datafield_ids) ) {
                // list of datafield ids is going to be shorter
                fprintf($handle, "DELETE FROM odr_data_record_fields drf WHERE drf.data_field_id IN (".implode(',', $datafield_ids).");\n");
            }
            else {
                fprintf($handle, "# No datarecords to delete\n");
            }

            if ( !empty($linked_datatree_ids) )
                fprintf($handle, "DELETE FROM odr_linked_data_tree ldt WHERE ldt.id IN (".implode(',', $linked_datatree_ids).");\n");
            else {
                fprintf($handle, "# No linked datatree entries to delete\n");
            }

            if ( !empty($datarecord_ids) ) {
                fprintf($handle, "DELETE FROM odr_data_record_meta drm WHERE drm.data_record_id IN (".implode(',', $datarecord_ids).");\n");
                fprintf($handle, "UPDATE odr_data_record dr SET dr.parent_id = NULL, dr.grandparent_id = NULL WHERE dr.id IN (".implode(',', $datarecord_ids).");\n");
                fprintf($handle, "DELETE FROM odr_data_record dr WHERE dr.id IN (".implode(',', $datarecord_ids).");\n");
            }
            else {
                fprintf($handle, "# No datarecords to delete\n");
            }

            // ...then datatype stuff last
            if ( !empty($radio_option_ids) ) {
                fprintf($handle, "DELETE FROM odr_radio_options_meta rom WHERE rom.radio_option_id IN (".implode(',', $radio_option_ids).");\n");
                fprintf($handle, "DELETE FROM odr_radio_options ro WHERE ro.id IN (".implode(',', $radio_option_ids).");\n");
            }
            else {
                fprintf($handle, "# No radio options to delete\n");
            }
            if ( !empty($tag_ids) ) {
                fprintf($handle, "DELETE FROM odr_tag_meta tm WHERE tm.tag_id IN (".implode(',', $tag_ids).");\n");
                fprintf($handle, "DELETE FROM odr_tag_tree tt WHERE (tt.parent_id IN (".implode(',', $tag_ids).") OR tt.child_id IN (".implode(',', $tag_ids)."));\n");
                fprintf($handle, "DELETE FROM odr_tags t WHERE t.id IN (".implode(',', $tag_ids).");\n");
            }
            else {
                fprintf($handle, "# No tags to delete\n");
            }

            if ( !empty($group_ids) ) {
                fprintf($handle, "DELETE FROM odr_group_datafield_permissions gdfp WHERE gdfp.group_id IN (".implode(',', $group_ids).");\n");
                fprintf($handle, "DELETE FROM odr_group_datatype_permissions gdtp WHERE gdtp.group_id IN (".implode(',', $group_ids).");\n");
                fprintf($handle, "DELETE FROM odr_user_group ug WHERE ug.group_id IN (".implode(',', $group_ids).");\n");
                fprintf($handle, "DELETE FROM odr_group_meta gm WHERE gm.group_id IN (".implode(',', $group_ids).");\n");
                fprintf($handle, "DELETE FROM odr_group g WHERE g.id IN (".implode(',', $group_ids).");\n");
            }
            else {
                fprintf($handle, "# No groups to delete\n");
            }

            if ( !empty($render_plugin_instance_ids) ) {
                fprintf($handle, "DELETE FROM odr_render_plugin_options rpo WHERE rpo.render_plugin_instance_id IN (".implode(',', $render_plugin_instance_ids).");\n");
                fprintf($handle, "DELETE FROM odr_render_plugin_options_map rpom WHERE rpom.render_plugin_instance_id IN (".implode(',', $render_plugin_instance_ids).");\n");
                fprintf($handle, "DELETE FROM odr_render_plugin_map rpm WHERE rpm.render_plugin_instance_id IN (".implode(',', $render_plugin_instance_ids).");\n");
                fprintf($handle, "DELETE FROM odr_render_plugin_instance rpi WHERE rpi.id IN (".implode(',', $render_plugin_instance_ids).");\n");
            }
            else {
                fprintf($handle, "# No renderPluginInstances to delete\n");
            }

            if ( !empty($dtsf_ids) ) {
                fprintf($handle, "DELETE FROM odr_data_type_special_fields dtsf WHERE dtsf.data_type_id IN (".implode(',', $datatype_ids).");\n");
                fprintf($handle, "DELETE FROM odr_data_type_special_fields dtsf WHERE dtsf.data_field_id IN (".implode(',', $datafield_ids).");\n");
            }
            else {
                fprintf($handle, "# No datatypeSpecialField entries to delete\n");
            }

            if ( !empty($datatype_ids) ) {
                fprintf($handle, "DELETE FROM odr_stored_search_keys ssk WHERE ssk.data_type_id IN (".implode(',', $datatype_ids).");\n");
            }
            else {
                fprintf($handle, "# No storedSearchKey entries to delete\n");
            }

            if ( !empty($datatype_ids) ) {
                fprintf($handle, "UPDATE odr_data_type_meta dtm SET dtm.external_datafield_id = NULL, dtm.type_name_datafield_id = NULL, dtm.sort_datafield_id = NULL, dtm.background_image_datafield_id = NULL WHERE dtm.data_type_id IN (".implode(',', $datatype_ids).");\n");
                fprintf($handle, "DELETE FROM odr_data_type_meta dtm WHERE dtm.data_type_id IN (".implode(',', $datatype_ids).");\n");
            }
            else {
                fprintf($handle, "# No datatypeMeta entries to delete\n");
            }

            if ( !empty($datatree_ids) ) {
                fprintf($handle, "DELETE FROM odr_data_tree_meta dtm WHERE dtm.data_tree_id IN (".implode(',', $datatree_ids).");\n");
                fprintf($handle, "DELETE FROM odr_data_tree dt WHERE dt.id IN (".implode(',', $datatree_ids).");\n");
            }
            else {
                fprintf($handle, "# No datatree entries to delete\n");
            }

            if ( !empty($datafield_ids) ) {
                // need to also catch all datatypes using one of these datafields as their sort field
                fprintf($handle, "DELETE FROM odr_data_fields_meta dfm WHERE dfm.data_field_id IN (".implode(',', $datafield_ids).");\n");
                fprintf($handle, "DELETE FROM odr_data_fields df WHERE df.id IN (".implode(',', $datafield_ids).");\n");
            }
            else {
                fprintf($handle, "# No datafield entries to delete\n");
            }

            if ( !empty($datatype_ids) ) {
                fprintf($handle, "UPDATE odr_data_type dt SET dt.parent_id = NULL, dt.grandparent_id = NULL, dt.master_datatype_id = NULL, dt.metadata_datatype_id = NULL, dt.metadata_for_id = NULL WHERE dt.id IN (".implode(',', $datatype_ids).");\n");
                fprintf($handle, "DELETE FROM odr_data_type dt WHERE dt.id IN (".implode(',', $datatype_ids).");\n");
            }
            else {
                fprintf($handle, "# No datatype entries to delete\n");
            }

            // ----------------------------------------
            print '</pre></body></html>';

            fclose($handle);
        }
        catch (\Exception $e) {
            $source = 0xca53aaf6;
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
     * Returns a sequence of SQL statements that will delete every single datarecord-related entity
     * from the backend database that is:
     * 1) related to the given datatype id
     * 2) already deleted
     *
     * @param string $datatype_ids
     * @param Request $request
     *
     * @return Response
     */
    public function purgedeletedrecordsAction($datatype_ids, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $conn = $em->getConnection();

            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();

            $dt_ids_for_filename = $datatype_ids;
            $initial_datatype_ids = explode(',', $datatype_ids);


            // ----------------------------------------
            // Grandparent Datatypes
            $query =
                'SELECT dt.id AS dt_id
                 FROM odr_data_type AS dt
                 WHERE dt.id IN (?) AND dt.id = dt.grandparent_id';
            $parameters = array(1 => $initial_datatype_ids);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY);
            $results = $conn->fetchAll($query, $parameters, $types);

            $grandparent_datatype_ids = array();
            foreach ($results as $result)
                $grandparent_datatype_ids[] = $result['dt_id'];

            $datatype_ids = array();
            foreach ($grandparent_datatype_ids as $num => $gp_dt_id) {
                $new_datatype_ids = array($gp_dt_id);
                $datatype_ids[$gp_dt_id] = 1;

                while ( !empty($new_datatype_ids) ) {
                    // Get all direct descendants of this datatype, or any metadata datatypes it has
                    $query =
                        'SELECT dt.id AS dt_id
                        FROM odr_data_type AS dt
                        WHERE dt.grandparent_id IN (?) OR dt.metadata_for_id IN (?)';
                    $parameters = array( 1 => $new_datatype_ids, 2 => $new_datatype_ids );
                    $types = array( 1 => DBALConnection::PARAM_INT_ARRAY, 2 => DBALConnection::PARAM_INT_ARRAY );
                    $results = $conn->fetchAll($query, $parameters, $types);

                    $new_datatype_ids = array();
                    foreach ($results as $result) {
                        if ( !isset($datatype_ids[ $result['dt_id'] ]) )
                            $new_datatype_ids[] = $result['dt_id'];
                        $datatype_ids[ $result['dt_id'] ] = 1;
                    }
                }
            }

            $datatype_ids = array_keys($datatype_ids);

            // Don't need to touch any of these:
            // Datatrees
            // Datatype special fields
            // Themes
            // ThemeElements
            // RenderPluginInstances
            // Groups


            // Datafields
            $query_str =
                'SELECT df.id AS df_id
                 FROM odr_data_fields AS df
                 WHERE df.data_type_id IN (?)';
            $parameters = array(1 => $datatype_ids);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY);
            $results = $conn->fetchAll($query_str, $parameters, $types);
            $datafield_ids = array();
            foreach ($results as $result)
                $datafield_ids[] = $result['df_id'];

            // Not going to delete any datatypes or datafields, but need the ids for later

            // Datarecords
            $query_str =
                'SELECT dr.id AS dr_id
                 FROM odr_data_record AS dr
                 WHERE dr.data_type_id IN (?)
                 AND dr.deletedAt IS NOT NULL';    // only want deleted datarecords
            $parameters = array(1 => $datatype_ids);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY);
            $results = $conn->fetchAll($query_str, $parameters, $types);
            $datarecord_ids = array();
            foreach ($results as $result)
                $datarecord_ids[] = $result['dr_id'];


            // Linked Datatree
            $query_str =
                'SELECT ldt.id AS ldt_id
                 FROM odr_linked_data_tree AS ldt
                 WHERE (ldt.ancestor_id IN (?) OR ldt.descendant_id IN (?))';
            $parameters = array(1 => $datarecord_ids, 2 => $datarecord_ids);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY, 2 => DBALConnection::PARAM_INT_ARRAY);
            $results = $conn->fetchAll($query_str, $parameters, $types);
            $linked_datatree_ids = array();
            foreach ($results as $result)
                $linked_datatree_ids[] = $result['ldt_id'];

            // Tags
            $query_str =
                'SELECT t.id AS t_id
                 FROM odr_tags AS t
                 WHERE t.data_fields_id IN (?)';
            $parameters = array(1 => $datafield_ids);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY);
            $results = $conn->fetchAll($query_str, $parameters, $types);
            $tag_ids = array();
            foreach ($results as $result)
                $tag_ids[] = $result['t_id'];

            // Radio Options
            $query_str =
                'SELECT ro.id AS ro_id
                 FROM odr_radio_options AS ro
                 WHERE ro.data_fields_id IN (?)';
            $parameters = array(1 => $datafield_ids);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY);
            $results = $conn->fetchAll($query_str, $parameters, $types);
            $radio_option_ids = array();
            foreach ($results as $result)
                $radio_option_ids[] = $result['ro_id'];

            // Similar to datafields, need the tag/radio option ids in order to delete their selections

            // Images
            $query_str =
                'SELECT i.id AS i_id
                 FROM odr_image AS i
                 WHERE i.data_field_id IN (?)
                 AND i.data_record_id IN (?)';
            $parameters = array(1 => $datafield_ids, 2 => $datarecord_ids);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY, 2 => DBALConnection::PARAM_INT_ARRAY);
            $results = $conn->fetchAll($query_str, $parameters, $types);
            $image_ids = array();
            foreach ($results as $result)
                $image_ids[] = $result['i_id'];


            // Files
            $query_str =
                'SELECT f.id AS f_id
                 FROM odr_file AS f
                 WHERE f.data_field_id IN (?)
                 AND f.data_record_id IN (?)';
            $parameters = array(1 => $datafield_ids, 2 => $datarecord_ids);
            $types = array(1 => DBALConnection::PARAM_INT_ARRAY, 2 => DBALConnection::PARAM_INT_ARRAY);
            $results = $conn->fetchAll($query_str, $parameters, $types);
            $file_ids = array();
            foreach ($results as $result)
                $file_ids[] = $result['f_id'];


            // ----------------------------------------
            // Originally, this dumped "DELETE FROM" statements to the browser, which I would then
            //  copy/paste into a terminal to execute.  Unfortunately, this seems to not be entirely
            //  reliable, so had to modify it to create mysqldump files
            $user_tmp_dir = $this->getParameter('odr_tmp_directory').'/user_'.$user->getId();
            if ( !file_exists($user_tmp_dir) )
                mkdir( $user_tmp_dir );

//            $handle = fopen($user_tmp_dir.'/purge_'.$dt_ids_for_filename.'.dmp', 'w');
            $handle = fopen($user_tmp_dir.'/purge.dmp', 'w');
            if ( !$handle )
                throw new ODRException('Unable to open dmp file');

            // ...still is useful to return how many datarecords will be deleted though
            print
                '<html>
                    <pre>number of datarecords: '.count($datarecord_ids).'</pre>
                    <pre>dmp file written to /app/tmp/user_'.$user->getId().'/purge.dmp</pre>
                </html>';

            // ----------------------------------------
            // Set up the dmp file...
            $db_name = $this->getParameter('database_name');
            fprintf($handle, "USE ".$db_name.";\n\n");

//            // Layout stuff first...
//            if ( !empty($theme_element_ids) ) {
//                fprintf($handle, "DELETE FROM odr_theme_data_field tdf WHERE tdf.theme_element_id IN (".implode(',', $theme_element_ids).");\n");
//                fprintf($handle, "DELETE FROM odr_theme_data_type tdt WHERE tdt.theme_element_id IN (".implode(',', $theme_element_ids).");\n");
//                fprintf($handle, "DELETE FROM odr_theme_element_meta tem WHERE tem.theme_element_id IN (".implode(',', $theme_element_ids).");\n");
//                fprintf($handle, "DELETE FROM odr_theme_element te WHERE te.id IN (".implode(',', $theme_element_ids).");\n");
//            }
//            else {
//                fprintf($handle, "# No theme elements to delete\n");
//            }

//            // sanity check for both of these
//            if ( !empty($datafield_ids) )
//                fprintf($handle, "DELETE FROM odr_theme_data_field tdf WHERE tdf.data_field_id IN (".implode(',', $datafield_ids).");\n");
//            if ( !empty($datatype_ids) )
//                fprintf($handle, "DELETE FROM odr_theme_data_type tdt WHERE tdt.data_type_id IN (".implode(',', $datatype_ids).");\n");

//            if ( !empty($theme_ids) ) {
//                // also need to get all themeDataType entries that reference the themes being deleted
//                fprintf($handle, "DELETE FROM odr_theme_data_type tdt WHERE tdt.child_theme_id IN (".implode(',', $theme_ids).");\n");
//
//                fprintf($handle, "DELETE FROM odr_theme_preferences tp WHERE tp.theme_id IN (".implode(',', $theme_ids).");\n");
//                fprintf($handle, "DELETE FROM odr_theme_meta tm WHERE tm.theme_id IN (".implode(',', $theme_ids).");\n");
//                fprintf($handle, "UPDATE odr_theme t SET t.parent_theme_id = NULL, t.source_theme_id = NULL WHERE t.id IN (".implode(',', $theme_ids).");\n");
//                fprintf($handle, "DELETE FROM odr_theme t WHERE t.id IN (".implode(',', $theme_ids).");\n");
//            }
//            else {
//                fprintf($handle, "# No themes to delete\n");
//            }

            // ...then storage entities...
            if ( !empty($datafield_ids) && !empty($datarecord_ids) ) {
                fprintf($handle, "DELETE FROM odr_boolean e WHERE e.data_field_id IN (".implode(',', $datafield_ids).") AND e.data_record_id IN (".implode(',', $datarecord_ids).");\n");
                fprintf($handle, "DELETE FROM odr_datetime_value e WHERE e.data_field_id IN (".implode(',', $datafield_ids).") AND e.data_record_id IN (".implode(',', $datarecord_ids).");\n");
                fprintf($handle, "DELETE FROM odr_decimal_value e WHERE e.data_field_id IN (".implode(',', $datafield_ids).") AND e.data_record_id IN (".implode(',', $datarecord_ids).");\n");
                fprintf($handle, "DELETE FROM odr_integer_value e WHERE e.data_field_id IN (".implode(',', $datafield_ids).") AND e.data_record_id IN (".implode(',', $datarecord_ids).");\n");
                fprintf($handle, "DELETE FROM odr_long_text e WHERE e.data_field_id IN (".implode(',', $datafield_ids).") AND e.data_record_id IN (".implode(',', $datarecord_ids).");\n");
                fprintf($handle, "DELETE FROM odr_long_varchar e WHERE e.data_field_id IN (".implode(',', $datafield_ids).") AND e.data_record_id IN (".implode(',', $datarecord_ids).");\n");
                fprintf($handle, "DELETE FROM odr_medium_varchar e WHERE e.data_field_id IN (".implode(',', $datafield_ids).") AND e.data_record_id IN (".implode(',', $datarecord_ids).");\n");
                fprintf($handle, "DELETE FROM odr_short_varchar e WHERE e.data_field_id IN (".implode(',', $datafield_ids).") AND e.data_record_id IN (".implode(',', $datarecord_ids).");\n");
            }
            else {
                fprintf($handle, "# No storage entities to delete\n");
            }

            if ( !empty($radio_option_ids) && !empty($datarecord_ids) )
                fprintf($handle, "DELETE FROM odr_radio_selection rs WHERE rs.radio_option_id IN (".implode(',', $radio_option_ids).") AND rs.data_record_id IN (".implode(',', $datarecord_ids).");\n");
            else {
                fprintf($handle, "# No radio selections to delete\n");
            }
            if ( !empty($tag_ids) && !empty($datarecord_ids) )
                fprintf($handle, "DELETE FROM odr_tag_selection ts WHERE ts.tag_id IN (".implode(',', $tag_ids).") AND ts.data_record_id IN (".implode(',', $datarecord_ids).");\n");
            else {
                fprintf($handle, "# No tag selections to delete\n");
            }

            if ( !empty($file_ids) ) {
                fprintf($handle, "DELETE FROM odr_file_checksum fc WHERE fc.file_id IN (".implode(',', $file_ids).");\n");
                fprintf($handle, "DELETE FROM odr_file_meta fm WHERE fm.file_id IN (".implode(',', $file_ids).");\n");
            }
            else {
                fprintf($handle, "# No files to delete\n");
            }
            if ( !empty($datafield_ids) && !empty($datarecord_ids) )
                fprintf($handle, "DELETE FROM odr_file f WHERE f.data_field_id IN (".implode(',', $datafield_ids).") AND f.data_record_id IN (".implode(',', $datarecord_ids).");\n");


            if ( !empty($image_ids) ) {
                fprintf($handle, "DELETE FROM odr_image_checksum ic WHERE ic.image_id IN (".implode(',', $image_ids).");\n");
                fprintf($handle, "DELETE FROM odr_image_meta im WHERE im.image_id IN (".implode(',', $image_ids).");\n");
                fprintf($handle, "UPDATE odr_image i SET i.parent_id = NULL, i.image_size_id = NULL WHERE i.id IN (".implode(',', $image_ids).");\n");
            }
            else {
                fprintf($handle, "# No images to delete\n");
            }
            if ( !empty($image_ids) && !empty($datafield_ids) && !empty($datarecord_ids) ) {
//                fprintf($handle, "DELETE FROM odr_image_sizes i_s WHERE i_s.data_fields_id IN (".implode(',', $datafield_ids).");\n");
                fprintf($handle, "DELETE FROM odr_image i WHERE i.data_field_id IN (".implode(',', $datafield_ids).") AND i.data_record_id IN (".implode(',', $datarecord_ids).");\n");
            }

            if ( !empty($datarecord_ids) ) {
                // can't use list of datafield ids
                fprintf($handle, "DELETE FROM odr_data_record_fields drf WHERE drf.data_record_id IN (".implode(',', $datarecord_ids).");\n");
            }
            else {
                fprintf($handle, "# No datarecords to delete\n");
            }

            if ( !empty($linked_datatree_ids) )
                fprintf($handle, "DELETE FROM odr_linked_data_tree ldt WHERE ldt.id IN (".implode(',', $linked_datatree_ids).");\n");
            else {
                fprintf($handle, "# No linked datatree entries to delete\n");
            }

            if ( !empty($datarecord_ids) ) {
                fprintf($handle, "DELETE FROM odr_data_record_meta drm WHERE drm.data_record_id IN (".implode(',', $datarecord_ids).");\n");
                fprintf($handle, "UPDATE odr_data_record dr SET dr.parent_id = NULL, dr.grandparent_id = NULL WHERE dr.id IN (".implode(',', $datarecord_ids).");\n");
                fprintf($handle, "DELETE FROM odr_data_record dr WHERE dr.id IN (".implode(',', $datarecord_ids).");\n");
            }
            else {
                fprintf($handle, "# No datarecords to delete\n");
            }

//            // ...then datatype stuff last
//            if ( !empty($radio_option_ids) ) {
//                fprintf($handle, "DELETE FROM odr_radio_options_meta rom WHERE rom.radio_option_id IN (".implode(',', $radio_option_ids).");\n");
//                fprintf($handle, "DELETE FROM odr_radio_options ro WHERE ro.id IN (".implode(',', $radio_option_ids).");\n");
//            }
//            else {
//                fprintf($handle, "# No radio options to delete\n");
//            }
//            if ( !empty($tag_ids) ) {
//                fprintf($handle, "DELETE FROM odr_tag_meta tm WHERE tm.tag_id IN (".implode(',', $tag_ids).");\n");
//                fprintf($handle, "DELETE FROM odr_tag_tree tt WHERE (tt.parent_id IN (".implode(',', $tag_ids).") OR tt.child_id IN (".implode(',', $tag_ids)."));\n");
//                fprintf($handle, "DELETE FROM odr_tags t WHERE t.id IN (".implode(',', $tag_ids).");\n");
//            }
//            else {
//                fprintf($handle, "# No tags to delete\n");
//            }
//
//            if ( !empty($group_ids) ) {
//                fprintf($handle, "DELETE FROM odr_group_datafield_permissions gdfp WHERE gdfp.group_id IN (".implode(',', $group_ids).");\n");
//                fprintf($handle, "DELETE FROM odr_group_datatype_permissions gdtp WHERE gdtp.group_id IN (".implode(',', $group_ids).");\n");
//                fprintf($handle, "DELETE FROM odr_user_group ug WHERE ug.group_id IN (".implode(',', $group_ids).");\n");
//                fprintf($handle, "DELETE FROM odr_group_meta gm WHERE gm.group_id IN (".implode(',', $group_ids).");\n");
//                fprintf($handle, "DELETE FROM odr_group g WHERE g.id IN (".implode(',', $group_ids).");\n");
//            }
//            else {
//                fprintf($handle, "# No groups to delete\n");
//            }
//
//            if ( !empty($render_plugin_instance_ids) ) {
//                fprintf($handle, "DELETE FROM odr_render_plugin_options rpo WHERE rpo.render_plugin_instance_id IN (".implode(',', $render_plugin_instance_ids).");\n");
//                fprintf($handle, "DELETE FROM odr_render_plugin_options_map rpom WHERE rpom.render_plugin_instance_id IN (".implode(',', $render_plugin_instance_ids).");\n");
//                fprintf($handle, "DELETE FROM odr_render_plugin_map rpm WHERE rpm.render_plugin_instance_id IN (".implode(',', $render_plugin_instance_ids).");\n");
//                fprintf($handle, "DELETE FROM odr_render_plugin_instance rpi WHERE rpi.id IN (".implode(',', $render_plugin_instance_ids).");\n");
//            }
//            else {
//                fprintf($handle, "# No renderPluginInstances to delete\n");
//            }
//
//            if ( !empty($dtsf_ids) ) {
//                fprintf($handle, "DELETE FROM odr_data_type_special_fields dtsf WHERE dtsf.data_type_id IN (".implode(',', $datatype_ids).");\n");
//                fprintf($handle, "DELETE FROM odr_data_type_special_fields dtsf WHERE dtsf.data_field_id IN (".implode(',', $datafield_ids).");\n");
//            }
//            else {
//                fprintf($handle, "# No datatypeSpecialField entries to delete\n");
//            }
//
//            if ( !empty($datatype_ids) ) {
//                fprintf($handle, "DELETE FROM odr_stored_search_keys ssk WHERE ssk.data_type_id IN (".implode(',', $datatype_ids).");\n");
//            }
//            else {
//                fprintf($handle, "# No storedSearchKey entries to delete\n");
//            }
//
//            if ( !empty($datatype_ids) ) {
//                fprintf($handle, "UPDATE odr_data_type_meta dtm SET dtm.external_datafield_id = NULL, dtm.type_name_datafield_id = NULL, dtm.sort_datafield_id = NULL, dtm.background_image_datafield_id = NULL WHERE dtm.data_type_id IN (".implode(',', $datatype_ids).");\n");
//                fprintf($handle, "DELETE FROM odr_data_type_meta dtm WHERE dtm.data_type_id IN (".implode(',', $datatype_ids).");\n");
//            }
//            else {
//                fprintf($handle, "# No datatypeMeta entries to delete\n");
//            }
//
//            if ( !empty($datatree_ids) ) {
//                fprintf($handle, "DELETE FROM odr_data_tree_meta dtm WHERE dtm.data_tree_id IN (".implode(',', $datatree_ids).");\n");
//                fprintf($handle, "DELETE FROM odr_data_tree dt WHERE dt.id IN (".implode(',', $datatree_ids).");\n");
//            }
//            else {
//                fprintf($handle, "# No datatree entries to delete\n");
//            }
//
//            if ( !empty($datafield_ids) ) {
//                // need to also catch all datatypes using one of these datafields as their sort field
//                fprintf($handle, "DELETE FROM odr_data_fields_meta dfm WHERE dfm.data_field_id IN (".implode(',', $datafield_ids).");\n");
//                fprintf($handle, "DELETE FROM odr_data_fields df WHERE df.id IN (".implode(',', $datafield_ids).");\n");
//            }
//            else {
//                fprintf($handle, "# No datafield entries to delete\n");
//            }
//
//            if ( !empty($datatype_ids) ) {
//                fprintf($handle, "UPDATE odr_data_type dt SET dt.parent_id = NULL, dt.grandparent_id = NULL, dt.master_datatype_id = NULL, dt.metadata_datatype_id = NULL, dt.metadata_for_id = NULL WHERE dt.id IN (".implode(',', $datatype_ids).");\n");
//                fprintf($handle, "DELETE FROM odr_data_type dt WHERE dt.id IN (".implode(',', $datatype_ids).");\n");
//            }
//            else {
//                fprintf($handle, "# No datatype entries to delete\n");
//            }

            // ----------------------------------------
            print '</pre></body></html>';

            fclose($handle);
        }
        catch (\Exception $e) {
            $source = 0x3f28e570;
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
     * Looks through the crypto directory and mentions every file/image that doesn't have an entry
     * in the database...files/images directories for soft-deleted files/images are ignored.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function purgefilesAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            // Don't want to deal with the soft-deleted filter, so use raw sql
            $conn = $em->getConnection();

            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if (!$user->hasRole('ROLE_SUPER_ADMIN'))
                throw new ODRForbiddenException();


            // ----------------------------------------
            // Get the directories from the crypto dir
            $crypto_dir = realpath($this->getParameter('dterranova_crypto.temp_folder'));
            $encrypted_folders = array('File' => array(), 'Image' => array());

            $contents = scandir($crypto_dir);
            foreach ($contents as $dir) {
                if ($dir === '.' || $dir === '..')
                    continue;

                $name = explode('_', $dir);
                $filetype = $name[0];
                $id = $name[1];

                $encrypted_folders[$filetype][$id] = 1;
            }
//            print '<pre>'.print_r($encrypted_folders, true).'</pre>';

            // Get the list of files that exist in the database (including soft-deleted)
            $query_str = 'SELECT id FROM odr_file';
            $results = $conn->fetchAll($query_str);

            foreach ($results as $result) {
                $file_id = $result['id'];

                // If the file has a database entry, then want to preserve its encrypted folder
                if ( isset($encrypted_folders['File'][$file_id]) )
                    unset( $encrypted_folders['File'][$file_id] );
                else
                    print 'File '.$file_id.' does not have an encrypted directory<br>';
            }

            // Do the same for the images...
            $query_str = 'SELECT id FROM odr_image';
            $results = $conn->fetchAll($query_str);

            foreach ($results as $result) {
                $file_id = $result['id'];

                // If the image has a database entry, then want to preserve its encrypted folder
                if ( isset($encrypted_folders['Image'][$file_id]) )
                    unset( $encrypted_folders['Image'][$file_id] );
                else
                    print 'Image '.$file_id.' does not have an encrypted directory<br>';
            }


            // ----------------------------------------
            if ( !self::SAVE ) {
                print '<pre>'.print_r($encrypted_folders, true).'</pre>';
            }
            else {
                throw new ODRNotImplementedException();
            }
        }
        catch (\Exception $e) {
            $source = 0xccf9f540;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'text/html');
        return $response;
    }
}
