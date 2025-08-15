<?php

/**
 * Open Data Repository Data Publisher
 * AMCSD Update Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * TODO
 */

namespace ODR\OpenRepository\GraphBundle\Component\Service;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\File;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Events
use ODR\AdminBundle\Component\Event\DatarecordCreatedEvent;
use ODR\AdminBundle\Component\Event\DatarecordLinkStatusChangedEvent;
use ODR\AdminBundle\Component\Event\DatatypeImportedEvent;
// Exceptions
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\AdminBundle\Component\Service\CryptoService;
use ODR\AdminBundle\Component\Service\EntityCreationService;
use ODR\AdminBundle\Component\Service\ODRUploadService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchService;
// Symfony
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class AMCSDUpdateService
{

    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var string
     */
    private $odr_tmp_directory;

    /**
     * @var string
     */
    private $odr_web_directory;

    /**
     * @var EventDispatcherInterface
     */
    private $event_dispatcher;

    // NOTE - $event_dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
    //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode

    /**
     * @var CryptoService
     */
    private $crypto_service;

    /**
     * @var EntityCreationService
     */
    private $entity_creation_service;

    /**
     * @var ODRUploadService
     */
    private $odr_upload_service;

    /**
     * @var SearchService
     */
    private $search_service;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * AMCSD Update Service constructor
     *
     * @param EntityManager $entity_manager
     * @param string $odr_tmp_directory
     * @param string $odr_web_directory
     * @param EventDispatcherInterface $event_dispatcher
     * @param CryptoService $crypto_service
     * @param EntityCreationService $entity_creation_service
     * @param ODRUploadService $odr_upload_service
     * @param SearchService $search_service
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        string $odr_tmp_directory,
        string $odr_web_directory,
        EventDispatcherInterface $event_dispatcher,
        CryptoService $crypto_service,
        EntityCreationService $entity_creation_service,
        ODRUploadService $odr_upload_service,
        SearchService $search_service,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->odr_tmp_directory = $odr_tmp_directory;
        $this->odr_web_directory = $odr_web_directory;
        $this->event_dispatcher = $event_dispatcher;
        $this->crypto_service = $crypto_service;
        $this->entity_creation_service = $entity_creation_service;
        $this->odr_upload_service = $odr_upload_service;
        $this->search_service = $search_service;
        $this->logger = $logger;
    }


    /**
     * First step in the AMCSD update sequence is to parse the given input files...
     *
     * @param int $user_id
     * @param OutputInterface $output
     */
    public function parseFiles($user_id, $output)
    {
        // Determine user privileges
        /** @var ODRUser $user */
        $user = $this->em->getRepository('ODROpenRepositoryUserBundle:User')->find($user_id);
        if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
            throw new ODRForbiddenException();

        // Create the base directory for the master files to reside in
        $dir = $this->odr_tmp_directory;
        $dir .= '/user_'.$user_id.'/amcsd/';
        if ( !file_exists($dir) )
            mkdir($dir);


        $amc_codes = self::splitFile($dir, 'amc');
        $output->writeln('duplicate database codes in amc file...');
        foreach ($amc_codes as $code => $count) {
            if ( $count > 1 )
                $output->writeln($code.': '.$count);
        }
        $output->writeln('');

        $cif_codes = self::splitFile($dir, 'cif');
        $output->writeln('duplicate database codes in cif file...');
        foreach ($cif_codes as $code => $count) {
            if ( $count > 1 )
                $output->writeln($code.': '.$count);
        }
        $output->writeln('');

        $dif_codes = self::splitFile($dir, 'dif');
        $output->writeln('duplicate database codes in dif file...');
        foreach ($dif_codes as $code => $count) {
            if ( $count > 1 )
                $output->writeln($code.': '.$count);
        }
        $output->writeln('');
    }


    /**
     * The AMC/CIF/DIF files are provided as concatenations of each of the thousands of individual
     * AMC/CIF/DIF files...in order to check for changes, these three massive files need to be
     * split apart.
     *
     * @param string $filepath
     * @param string $key
     *
     * @return array
     */
    private function splitFile($filepath, $key)
    {
        if ( !file_exists($filepath.$key) )
            mkdir( $filepath.$key );

        $filename = '../'.$key.'data.txt';
        $input_file = fopen($filepath.$filename, 'r');
        if ( !$input_file )
            throw new ODRException('unable to read '.$filepath.$filename);

        $start = 0;
        $curr = 0;
        $buffer = '';
        $codes = array();
        while ( true ) {
            // No point reading past the end of the file
            if ( feof($input_file) )
                break;

            $char = fgetc($input_file);
            if ( $char === "\n" ) {
                // End of line reached, reset to beginning of line
                $curr = ftell($input_file);
                $ret = fseek($input_file, $start);

                // Read the entire line from the file
                $line = fread($input_file, ($curr - $start) );
                $trimmed_line = trim($line)."\r\n";
                // Can't get rid of carriage returns
//                $line = str_replace("\r", "", $line);

                // If this is the end of the file fragment...
                if ( $trimmed_line === "END\r\n" || $trimmed_line === "_END_\r\n" ) {
                    // Files don't have the filno in them, so have to resort to database code
                    $matches = array();
                    preg_match('/_database_code_amcsd (\d{7,7})/', $buffer, $matches);
                    $database_code = $matches[1];

                    if ( !isset($codes[$database_code]) )
                        $codes[$database_code] = 1;
                    else
                        $codes[$database_code] += 1;

                    $output_file = fopen($filepath.$key.'/'.$database_code.'.txt', 'w');
                    // Ensure there are no newlines before the compound name...
                    $buffer = trim($buffer, "\r\n\t\v\x00");    // don't strip spaces, dif file needs them
                    // Write the file fragment to disk, replacing the ending newlines that were trimmed
//                    fwrite($output_file, $buffer."\r\n\r\n");
                    fwrite($output_file, $buffer."\r\n");
                    fclose($output_file);

                    // Reset for next file
                    $start = $curr;
                    $buffer = '';
                }
                else {
                    // Reset for next line
                    $buffer .= $line;
                    $start = $curr;
                }
            }
        }

        fclose($input_file);
        return $codes;
    }


    /**
     * The second step in the AMCSD update sequence is to decrypt all the existing files, so they
     * can get compared against the master file list...
     *
     * @param int $user_id
     * @param OutputInterface $output
     */
    public function decryptExistingFiles($user_id, $output)
    {
        // Determine user privileges
        /** @var ODRUser $user */
        $user = $this->em->getRepository('ODROpenRepositoryUserBundle:User')->find($user_id);
        if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
            throw new ODRForbiddenException();

        // Create the directories to store the decrypted files...
        $dir = $this->odr_tmp_directory;
        $dir .= '/user_'.$user->getId().'/amcsd_current/';
        if ( !file_exists($dir) )
            mkdir($dir);
        if ( !file_exists($dir.'amc') )
            mkdir($dir.'amc');
        if ( !file_exists($dir.'cif') )
            mkdir($dir.'cif');
        if ( !file_exists($dir.'dif') )
            mkdir($dir.'dif');


        // ----------------------------------------
        // Query the database to locate relevant information about the AMCSD database
        $info = self::getAMCSDInfo( $this->em->getConnection() );
        $database_codes = $info['database_codes'];
        $amc_files = $info['amc_files'];
        $cif_files = $info['cif_files'];
        $dif_files = $info['dif_files'];

        // Now that the ids of each of the files are known, decrypt them into the temp directory
        $count = 0;
        foreach ($database_codes as $dr_id => $filename) {
            $amc_file_id = null;
            if ( isset($amc_files[$dr_id]) )
                $amc_file_id = $amc_files[$dr_id];
            $cif_file_id = null;
            if ( isset($cif_files[$dr_id]) )
                $cif_file_id = $cif_files[$dr_id];
            $dif_file_id = null;
            if ( isset($dif_files[$dr_id]) )
                $dif_file_id = $dif_files[$dr_id];

            if ( is_null($amc_file_id) )
                throw new ODRException('no amc file for datarecord '.$dr_id);
            if ( is_null($cif_file_id) )
                throw new ODRException('no cif file for datarecord '.$dr_id);
            if ( is_null($dif_file_id) )
                throw new ODRException('no dif file for datarecord '.$dr_id);

            if ( !file_exists($dir.'amc/'.$filename) ) {
                $amc_filepath = $this->crypto_service->decryptFile($amc_file_id, $filename);
                rename($amc_filepath, $dir.'amc/'.$filename);
            }

            if ( !file_exists($dir.'cif/'.$filename) ) {
                $cif_filepath = $this->crypto_service->decryptFile($cif_file_id, $filename);
                rename($cif_filepath, $dir.'cif/'.$filename);
            }

            if ( !file_exists($dir.'dif/'.$filename) ) {
                $dif_filepath = $this->crypto_service->decryptFile($dif_file_id, $filename);
                rename($dif_filepath, $dir.'dif/'.$filename);
            }

            $count++;
            if ( ($count % 1000) === 0 )
                $output->writeln('decrypted files for '.$count.' AMCSD records...');
        }

    }


    /**
     * Several places need ids of datatypes/datafields/etc used by the AMCSD datatype...
     *
     * @param \Doctrine\DBAL\Connection $conn
     * @return array
     */
    private function getAMCSDInfo($conn)
    {
        // ----------------------------------------
        // Need to get the dataype id...
        $query =
           'SELECT dt.id AS dt_id
            FROM odr_data_type dt
            LEFT JOIN odr_data_type_meta dtm ON dtm.data_type_id = dt.id
            WHERE dtm.short_name = "AMCSD"
            AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL';
        $results = $conn->fetchAll($query);

        $dt_id = 0;
        foreach ($results as $result)
            $dt_id = $result['dt_id'];


        // ----------------------------------------
        // ...so that the relevant datafield ids can be located
        $query =
           'SELECT rpm.data_field_id AS df_id, rpf.field_name
            FROM odr_render_plugin rp
            LEFT JOIN odr_render_plugin_instance rpi ON rpi.render_plugin_id = rp.id
            LEFT JOIN odr_render_plugin_map rpm ON rpm.render_plugin_instance_id = rpi.id
            LEFT JOIN odr_render_plugin_fields rpf ON rpm.render_plugin_fields_id = rpf.id
            WHERE rp.plugin_class_name = "odr_plugins.rruff.amcsd" AND rpi.data_type_id = '.$dt_id.'
            AND rp.deletedAt IS NULL AND rpf.deletedAt IS NULL
            AND rpi.deletedAt IS NULL AND rpm.deletedAt IS NULL';
        $results = $conn->fetchAll($query);

        $database_code_df_id = $amc_df_id = $cif_df_id = $dif_df_id = 0;
        foreach ($results as $result) {
            $df_id = $result['df_id'];
            $rpf_name = $result['field_name'];

            switch ($rpf_name) {
                case 'database_code_amcsd':
                    $database_code_df_id = $df_id;
                    break;
                case 'AMC File':
                    $amc_df_id = $df_id;
                    break;
                case 'CIF File':
                    $cif_df_id = $df_id;
                    break;
                case 'DIF File':
                    $dif_df_id = $df_id;
                    break;
            }
        }


        // ----------------------------------------
        // ...and now that both the datatype id and the datafield ids are known, get the values
        //  for each of the four fields for each AMCSD datarecord on ODR
        $database_codes = array();
        $amc_files = array();
        $cif_files = array();
        $dif_files = array();

        $query =
           'SELECT dr.id AS dr_id, sv.value AS val
            FROM odr_data_record dr
            LEFT JOIN odr_data_record_fields drf ON drf.data_record_id = dr.id
            LEFT JOIN odr_short_varchar sv ON sv.data_record_fields_id = drf.id
            WHERE dr.data_type_id = '.$dt_id.' AND sv.data_field_id = '.$database_code_df_id.'
            AND dr.deletedAt IS NULL AND drf.deletedAt IS NULL
            AND sv.deletedAt IS NULL';
        $results = $conn->fetchAll($query);

        foreach ($results as $result) {
            $dr_id = $result['dr_id'];
            $val = $result['val'];
            $database_codes[$dr_id] = $val.'.txt';
        }

/*
        $count = 0;
        $baseurl = 'https://odr.io/admin#/view/';
        $codes = array();
        foreach ($database_codes as $dr_id => $str) {
            if ( isset($codes[$str]) ) {
                if ( ($count % 5) === 0 )
                    print '<br>';
                $count++;

                $previous_dr_id = $codes[$str];

                $code = substr($str, 0, -4);
                print '_database_code_amcsd '.$code.'&nbsp;<a href="'.$baseurl.$previous_dr_id.'" target="_blank">'.$baseurl.$previous_dr_id.'</a>&nbsp;<a href="'.$baseurl.$dr_id.'" target="_blank">'.$baseurl.$dr_id.'</a><br>';
            }
            else {
                $codes[$str] = $dr_id;
            }
        }
*/
        // NOTE: this is temporary
        $codes = array();
        foreach ($database_codes as $dr_id => $str) {
            if ( isset($codes[$str]) ) {
                $query_1 = 'UPDATE odr_data_record dr SET deletedAt = NOW() WHERE dr.id = '.$dr_id;
                $query_2 = 'UPDATE odr_data_record_meta drm SET deletedAt = NOW() WHERE drm.data_record_id = '.$dr_id;
                $query_3 = 'UPDATE odr_data_record_fields drf SET deletedAt = NOW() WHERE drf.data_record_id = '.$dr_id;

                $conn->executeUpdate($query_1);
                $conn->executeUpdate($query_2);
                $conn->executeUpdate($query_3);

                unset( $database_codes[$dr_id] );
                $this->logger->debug('deleting duplicate datarecord '.$dr_id);
            }
            else {
                $codes[$str] = $dr_id;
            }
        }


        $query =
           'SELECT dr.id AS dr_id, amc_file.id AS val
            FROM odr_data_record dr
            LEFT JOIN odr_data_record_fields drf ON drf.data_record_id = dr.id
            LEFT JOIN odr_file amc_file ON amc_file.data_record_fields_id = drf.id
            WHERE dr.data_type_id = '.$dt_id.' AND amc_file.data_field_id = '.$amc_df_id.'
            AND dr.deletedAt IS NULL AND drf.deletedAt IS NULL
            AND amc_file.deletedAt IS NULL';
        $results = $conn->fetchAll($query);

        foreach ($results as $result) {
            $dr_id = $result['dr_id'];
            $val = $result['val'];
            $amc_files[$dr_id] = $val;
        }

        $query =
           'SELECT dr.id AS dr_id, cif_file.id AS val
            FROM odr_data_record dr
            LEFT JOIN odr_data_record_fields drf ON drf.data_record_id = dr.id
            LEFT JOIN odr_file cif_file ON cif_file.data_record_fields_id = drf.id
            WHERE dr.data_type_id = '.$dt_id.' AND cif_file.data_field_id = '.$cif_df_id.'
            AND dr.deletedAt IS NULL AND drf.deletedAt IS NULL
            AND cif_file.deletedAt IS NULL';
        $results = $conn->fetchAll($query);

        foreach ($results as $result) {
            $dr_id = $result['dr_id'];
            $val = $result['val'];
            $cif_files[$dr_id] = $val;
        }

        $query =
           'SELECT dr.id AS dr_id, dif_file.id AS val
            FROM odr_data_record dr
            LEFT JOIN odr_data_record_fields drf ON drf.data_record_id = dr.id
            LEFT JOIN odr_file dif_file ON dif_file.data_record_fields_id = drf.id
            WHERE dr.data_type_id = '.$dt_id.' AND dif_file.data_field_id = '.$dif_df_id.'
            AND dr.deletedAt IS NULL AND drf.deletedAt IS NULL
            AND dif_file.deletedAt IS NULL';
        $results = $conn->fetchAll($query);

        foreach ($results as $result) {
            $dr_id = $result['dr_id'];
            $val = $result['val'];
            $dif_files[$dr_id] = $val;
        }

        $info = array(
            'datatype_id' => $dt_id,
            'database_code_df_id' => $database_code_df_id,
            'database_codes' => $database_codes,
            'amc_file_df_id' => $amc_df_id,
            'amc_files' => $amc_files,
            'cif_file_df_id' => $cif_df_id,
            'cif_files' => $cif_files,
            'dif_file_df_id' => $dif_df_id,
            'dif_files' => $dif_files,
        );

        return $info;
    }


    /**
     * The third step in the AMCSD update sequence is to compare the decrypted files against the
     * given master files...existing files could need modified, or could be completely new entries.
     *
     * @param int $user_id
     * @param OutputInterface $output
     */
    public function computeDiff($user_id, $output)
    {
        $datafield_repository = $this->em->getRepository('ODRAdminBundle:DataFields');

        // Determine user privileges
        /** @var ODRUser $user */
        $user = $this->em->getRepository('ODROpenRepositoryUserBundle:User')->find($user_id);
        if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
            throw new ODRForbiddenException();

        // Ensure the directories to store the new/modified files exist
        $dir = $this->odr_tmp_directory.'/user_'.$user->getId().'/';
        $new_basedir = $dir.'amcsd/';
        $current_basedir = $dir.'amcsd_current/';
        $modified_basedir = $dir.'amcsd_modified/';

        if ( !file_exists($modified_basedir) )
            mkdir($modified_basedir);
        if ( !file_exists($modified_basedir.'amc') )
            mkdir($modified_basedir.'amc');
        if ( !file_exists($modified_basedir.'cif') )
            mkdir($modified_basedir.'cif');
        if ( !file_exists($modified_basedir.'dif') )
            mkdir($modified_basedir.'dif');


        // New AMCSD entries are also likely going to need to create new references...
        $reference_info = self::getReferenceInfo( $this->em->getConnection() );
        $reference_links = array();

        // ...but want to use the existing references if possible, which requires hydrated datafields
        //  for ODR's search system
        /** @var DataFields[] $df_lookup */
        $df_lookup = array();


        // ----------------------------------------
        // Going to compare each file in the two directory trees created during the previous two steps...
        $new = array('amc' => 0, 'cif' => 0, 'dif' => 0);
        $changed = array('amc' => 0, 'cif' => 0, 'dif' => 0);
        $filetypes = array('amc', 'cif', 'dif');
        foreach ($filetypes as $filetype) {
            $new_filelist = scandir($new_basedir.$filetype);
            $count = 0;
            foreach ($new_filelist as $filename) {
                if ( $filename === '.' || $filename === '..' )
                    continue;

                // If the file from the master list doesn't already exist in the directory of
                //  decrypted files...
                if ( !file_exists($current_basedir.$filetype.'/'.$filename) ) {
                    // ...then it's a new AMCSD entry.  Copy it into another directory to deal with later
                    copy($new_basedir.$filetype.'/'.$filename, $modified_basedir.$filetype.'/'.$filename);
                    $new[$filetype] += 1;

                    if ($filetype === 'cif') {
                        // Since this is a new AMCSD entry, that means it also has to interact with
                        //  the references database somehow...
                        $fileno = substr($filename, 0, -4);
                        $cif_contents = file_get_contents($modified_basedir.$filetype.'/'.$filename);
                        $ref_from_cif = self::buildFromCifContents($fileno, $cif_contents, $reference_info);

                        // The hope is that the reference data in the CIF matches an existing
                        //  reference...
                        $dr_list = array();
                        foreach ($ref_from_cif as $df_id => $value) {
                            // buildFromCifContents() will have created an array with <df_id> => <val>
                            //  pairs, plus a couple additional non-numeric keys
                            if ( !is_numeric($df_id) )
                                continue;

                            // Need the hydrated datafields to be able to run ODR's search system
                            if ( !isset($df_lookup[$df_id]) )
                                $df_lookup[$df_id] = $datafield_repository->find($df_id);
                            $df = $df_lookup[$df_id];

                            // Each of the six fields with reference data need to be searched
                            if ( $df->getFieldType()->getTypeClass() !== 'Boolean' ) {
                                $tmp = $this->search_service->searchTextOrNumberDatafield($df, $value);
                                $dr_list[] = $tmp['records'];
                            }
                        }

                        // There are now six list of datarecords that matched the six pieces of
                        //  reference criteria...
                        $dr_id = null;
                        if ( empty($dr_list[0]) || empty($dr_list[1]) || empty($dr_list[2]) || empty($dr_list[3]) || empty($dr_list[4]) || empty($dr_list[5]) ) {
                            // ...if any of the lists is empty, then nothing matched exactly

                            // I'm not good enough with fuzzy matching to make an informed guess, so
                            //  creating a new reference will have to suffice
                            $reference_links[$fileno] = $ref_from_cif;
                        }
                        else {
                            // If all of the lists have at least one datarecord, then intersect them
                            //  all to figure out if any reference datarecord matched
                            $tmp = array_intersect_key($dr_list[0],$dr_list[1],$dr_list[2],$dr_list[3],$dr_list[4],$dr_list[5]);
                            if ( count($tmp) > 1 )
                                throw new ODRException('multiple references matched');

                            if ( empty($tmp) ) {
                                // ...no matches, so create a new reference
                                $reference_links[$fileno] = $ref_from_cif;
                            }
                            else {
                                // ...the new AMCSD record should link to the matching reference
                                foreach ($tmp as $key => $val) {
                                    $reference_links[$fileno] = $key;
                                    break;
                                }
                            }
                        }
                    }
                }
                else {
                    // This is not a new AMCSD record...check whether it's different from the
                    //  existing file
                    $current_hash = md5_file($current_basedir.$filetype.'/'.$filename);
                    $new_hash = md5_file($new_basedir.$filetype.'/'.$filename);

                    if ( $current_hash === $new_hash ) {
//                    unlink($new_basedir.$filetype.'/'.$filename);
//                    unlink($current_basedir.$filetype.'/'.$filename);
                    }
                    else {
                        // ...it is different, so copy into another directory to deal with later
                        copy($new_basedir.$filetype.'/'.$filename, $modified_basedir.$filetype.'/'.$filename);
                        $changed[$filetype] += 1;
                    }
                }

                $count++;
                if ( ($count % 1000) === 0 )
                    $output->writeln('checked '.$count.' '.$filetype.' files...');
            }
        }

        $output->writeln('');
        $output->writeln('----- new AMCSD entries -----');
        foreach ($new as $filetype => $num)
            $output->writeln('  '.$filetype.': '.$num);

        $output->writeln('----- changed AMCSD entries -----');
        foreach ($changed as $filetype => $num)
            $output->writeln('  '.$filetype.': '.$num);

//        print 'reference info: <pre>'.print_r($reference_links, true).'</pre>';

        // Need to save the reference information for later
        $handle = fopen($dir.'parsed_reference_data.txt', 'w');
        if ( !$handle )
            throw new ODRException('unable to open references file');

        fwrite($handle, json_encode($reference_links));
        fclose($handle);

    }


    /**
     * Several places need ids of datatypes/datafields/etc used by the RRUFF References datatype...
     *
     * @param \Doctrine\DBAL\Connection $conn
     * @return array
     */
    private function getReferenceInfo($conn)
    {
        // ----------------------------------------
        // Need to get the dataype id...
        $query =
           'SELECT dt.id AS dt_id
            FROM odr_data_type dt
            LEFT JOIN odr_data_type_meta dtm ON dtm.data_type_id = dt.id
            WHERE dtm.short_name = "RRUFF References"
            AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL';
        $results = $conn->fetchAll($query);

        $dt_id = 0;
        foreach ($results as $result)
            $dt_id = $result['dt_id'];


        // ----------------------------------------
        // ...so that the relevant datafield ids can be located
        $query =
           'SELECT rpm.data_field_id AS df_id, rpf.field_name
            FROM odr_render_plugin rp
            LEFT JOIN odr_render_plugin_instance rpi ON rpi.render_plugin_id = rp.id
            LEFT JOIN odr_render_plugin_map rpm ON rpm.render_plugin_instance_id = rpi.id
            LEFT JOIN odr_render_plugin_fields rpf ON rpm.render_plugin_fields_id = rpf.id
            WHERE rp.plugin_class_name = "odr_plugins.rruff.rruff_references" AND rpi.data_type_id = '.$dt_id.'
            AND rp.deletedAt IS NULL AND rpf.deletedAt IS NULL
            AND rpi.deletedAt IS NULL AND rpm.deletedAt IS NULL';
        $results = $conn->fetchAll($query);

        $ref_id_df_id = $authors_df_id = $article_title_df_id = $journal_df_id = $year_df_id = $month_df_id = $volume_df_id = $pages_df_id = 0;
        foreach ($results as $result) {
            $df_id = $result['df_id'];
            $rpf_name = $result['field_name'];

            switch ($rpf_name) {
                case 'Reference ID':
                    $ref_id_df_id = intval($df_id);
                    break;
                case 'Authors':
                    $authors_df_id = intval($df_id);
                    break;
                case 'Article Title':
                    $article_title_df_id = intval($df_id);
                    break;
                case 'Journal':
                    $journal_df_id = intval($df_id);
                    break;
                case 'Year':
                    $year_df_id = intval($df_id);
                    break;
                case 'Month':
                    $month_df_id = intval($df_id);
                    break;
                case 'Volume':
                    $volume_df_id = intval($df_id);
                    break;
                case 'Pages':
                    $pages_df_id = intval($df_id);
                    break;
            }
        }

        // ...need one more datafield id from the reference datatype
        $query =
           'SELECT e.data_field_id AS df_id
            FROM odr_boolean e
            LEFT JOIN odr_data_fields df ON e.data_field_id = df.id
            WHERE df.data_type_id = '.$dt_id.'
            LIMIT 0,1';
        $results = $conn->fetchAll($query);

        $needs_review_df_id = 0;
        foreach ($results as $result)
            $needs_review_df_id = intval( $result['df_id'] );


        // ----------------------------------------
        $info = array(
            'datatype_id' => $dt_id,
            'ref_id_df_id' => $ref_id_df_id,
            'authors_df_id' => $authors_df_id,
            'article_title_df_id' => $article_title_df_id,
            'journal_df_id' => $journal_df_id,
            'year_df_id' => $year_df_id,
            'month_df_id' => $month_df_id,
            'volume_df_id' => $volume_df_id,
            'pages_df_id' => $pages_df_id,
            'needs_review_df_id' => $needs_review_df_id,
        );

        return $info;
    }


    /**
     * Parses the contents of the CIF file to extract reference data from it.
     *
     * @param string $fileno
     * @param string $cif_contents
     * @param array $info
     * @return array
     */
    private function buildFromCifContents($fileno, $cif_contents, $info) {
        $cif_contents = str_replace("\r", '', $cif_contents);
        $chunks = explode("loop_", $cif_contents);
        $lines = explode("\n", $chunks[1]);

        $authors = '';
        $journal = '';
        $volume = '';
        $year = '';
        $pages = '';
        $article_title = '';

        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];
            if ( $line === '_publ_author_name' ) {
                $buffer = array();
                while (true) {
                    $i++;
                    $line = $lines[$i];
                    if ( strpos($line, "_") === false )
                        $buffer[] = substr($line, 1, -1);
                    else
                        break;
                }
                $i--;

                $authors = implode(', ', $buffer);
                $authors = str_replace('  ', ' ', $authors);
            }
            else if ( strpos($line, '_journal_name_full') !== false ) {
                $journal = substr($line, strlen('_journal_name_full')+2, -1);
            }
            else if ( strpos($line, '_journal_volume') !== false ) {
                $volume = substr($line, strlen('_journal_volume')+1);
            }
            else if ( strpos($line, '_journal_year') !== false ) {
                $year = substr($line, strlen('_journal_year')+1);
            }
            else if ( strpos($line, '_journal_page_first') !== false ) {
                $pages = substr($line, strlen('_journal_page_first')+1);

                $line = $lines[$i+1];
                $pages .= '-'.substr($line, strlen('_journal_page_last')+1);
                $i++;

                if ( strpos($pages, '-') === 0 )
                    $pages = substr($pages, 1);
            }
            else if ( $line === '_publ_section_title') {
                $buffer = array();
                $semicolons = 0;
                while ($semicolons < 2) {
                    $i++;
                    $line = $lines[$i];
                    if ( $line[0] === ';')
                        $semicolons++;
                    else
                        $buffer[] = $line;
                }
                $i--;

                $article_title = trim( implode(' ', $buffer) );
                $article_title = str_replace('  ', ' ', $article_title);
                $article_title = str_replace(array("\n", "\r"), '', $article_title);

                // Don't want this in the title
                if ( strpos($article_title, '_cod_database_code') !== false )
                    $article_title = substr($article_title, 0, strpos($article_title, '_cod_database_code'));
            }
            else if ( strpos($line, '_database_code_amcsd') !== false ) {
                // Shouldn't have any article data after this
                break;
            }
        }


        if ( $authors == '' )
            throw new \Exception('could not find author in cif contents for fileno '.$fileno);
        if ( $journal == '' )
            throw new \Exception('could not find journal in cif contents for fileno '.$fileno);
        if ( $volume == '' )
            throw new \Exception('could not find volume in cif contents for fileno '.$fileno);
        if ( $year == '' )
            throw new \Exception('could not find year in cif contents for fileno '.$fileno);
        if ( $pages == '' )
            throw new \Exception('could not find pages in cif contents for fileno '.$fileno);
        if ( $article_title == '' )
            throw new \Exception('could not find article_title in cif contents for fileno '.$fileno);

        // AMCSD has part of what should be the volume in the article title for this journal...
        if ( strpos($journal, 'Acta Crystallographica') !== false ) {
            if ( strpos($journal, 'Section') !== false ) {
                $letter = substr($journal, -1);
                $volume = $letter.$volume;
                $journal = 'Acta Crystallographica';
            }
        }

        // This one is a good replacement
        $journal = str_replace('Zeitschrift fur', 'Zeitschrift für', $journal);

        if ( strlen($pages) > 15 )
            $pages = '';

        $ret = array(
            $info['authors_df_id'] => $authors,
            $info['journal_df_id'] => $journal,
            $info['volume_df_id'] => $volume,
            $info['year_df_id'] => $year,
            $info['pages_df_id'] => $pages,
            $info['article_title_df_id'] => $article_title,
            $info['needs_review_df_id'] => true,
        );

        $str = '';
        foreach ($ret as $key => $val) {
            $str .= ' '.$val;
            $ret[$key] = trim($val);
        }

        $ret['hash'] = md5( trim($str) );

//    print print_r($ret, true);  exit();
        return $ret;
    }


    /**
     * The fourth step in the AMCSD update sequence is to create any references required by the
     * new AMCSD records.
     * 
     * @param int $user_id
     * @param OutputInterface $output
     *
     * @return string
     */
    public function createReferences($user_id, $output)
    {
        // Determine user privileges
        /** @var ODRUser $user */
        $user = $this->em->getRepository('ODROpenRepositoryUserBundle:User')->find($user_id);
        if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
            throw new ODRForbiddenException();

        $datatype_repository = $this->em->getRepository('ODRAdminBundle:DataType');
        $datafield_repository = $this->em->getRepository('ODRAdminBundle:DataFields');

        $dir = $this->odr_tmp_directory.'/user_'.$user->getId().'/';


        // ----------------------------------------
        // Need to get info about the RRUFF References datatype
        $reference_info = self::getReferenceInfo( $this->em->getConnection() );
        /** @var DataType $reference_dt */
        $reference_dt = $datatype_repository->find( $reference_info['datatype_id'] );
        if ($reference_dt == null)
            throw new ODRNotFoundException('Reference Datatype');


        // This function is only going to create one reference at a time...
        $reference_links = file_get_contents($dir.'parsed_reference_data.txt');
        $reference_links = json_decode($reference_links, true);

        $hash = $new_dr = null;
        foreach ($reference_links as $fileno => $data) {
            if ( is_numeric($data) ) {
                // The diff function determined that this reference already exists...continue looking
                //  through the array for a reference to create
            }
            else {
                // Multiple new amcsd entries could've come from the same reference...
                $hash = $data['hash'];

                // Create the requested reference
                $new_dr = $this->entity_creation_service->createDatarecord($user, $reference_dt);    // no point delaying flush
                $this->em->refresh($new_dr);
                $this->logger->debug('amcsd update: created new reference datarecord, id '.$new_dr->getId().', hash "'.$hash.'"');
                $output->writeln('created new reference datarecord, id '.$new_dr->getId().', hash "'.$hash.'"');

                // Need to fire the DatarecordCreatedEvent so the newly created reference gets the
                //  correct Reference ID
                try {
                    $event = new DatarecordCreatedEvent($new_dr, $user, null);
                    $this->event_dispatcher->dispatch(DatarecordCreatedEvent::NAME, $event);
                }
                catch (\Exception $e) {
                    // ...don't want to rethrow the error since it'll interrupt everything after this
                    //  event
//                    if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                        throw $e;
                }

                // Fill in the fields with the data from the CIF file
                foreach ($data as $key => $value) {
                    // ...ignore anything that's not actually a datafield value
                    if ( !is_numeric($key) )
                        continue;

                    // Need to get the datafield...
                    /** @var DataFields $df */
                    $df = $datafield_repository->find($key);
                    // ...which then lets us create the storage entity
                    $this->entity_creation_service->createStorageEntity($user, $new_dr, $df, $value);    // firing events won't do much, but this is a background process so meh
                    $this->logger->debug('amcsd update: -- set df "'.$df->getFieldName().'" to "'.$value.'"');
                }

                // Now that the reference exists, replace the reference data with the id of the
                //  new datarecord
                $reference_links[$fileno] = $new_dr->getId();
                // Only going to create one datarecord per call
                break;
            }
        }

        // Despite only wanting to create a single reference datarecord per call, go through the
        //  array again...
        foreach ($reference_links as $fileno => $data) {
            if ( !is_numeric($data) ) {
                // ...if this fileno refers to a block of data, then check whether that data matches
                //  the reference that was just created
                if ( $data['hash'] === $hash ) {
                    // ...if so, then replace that data with the id of the reference that got
                    //  created, so that the next call doesn't create a duplicate reference
                    $reference_links[$fileno] = $new_dr->getId();
                }
            }
        }

        // Now that the reference data array has been modified, save it for the next iteration
        $handle = fopen($dir.'parsed_reference_data.txt', 'w');
        if ( !$handle )
            throw new ODRException('unable to open created_references.txt');

        fwrite($handle, json_encode($reference_links));
        fclose($handle);

        $ret = 'continue';
        if ( is_null($hash) )
            $ret = 'finished';

        return $ret;
    }


    /**
     * The fifth step in the AMCSD update sequence is to modify the AMCSD database...either by
     * creating AMCSD entries that don't exist, or by replacing files in entries that got modified
     *
     * @param int $user_id
     * @param OutputInterface|null $output
     */
    public function amcsdupdateAction($user_id, $output)
    {
        // Determine user privileges
        /** @var ODRUser $user */
        $user = $this->em->getRepository('ODROpenRepositoryUserBundle:User')->find($user_id);
        if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
            throw new ODRForbiddenException();

        $conn = $this->em->getConnection();

        $datatype_repository = $this->em->getRepository('ODRAdminBundle:DataType');
        $datafield_repository = $this->em->getRepository('ODRAdminBundle:DataFields');
        $datarecord_repository = $this->em->getRepository('ODRAdminBundle:DataRecord');
        $file_repository = $this->em->getRepository('ODRAdminBundle:File');

        $dir = $this->odr_tmp_directory.'/user_'.$user->getId().'/';
        $new_basedir = $dir.'amcsd/';
        $current_basedir = $dir.'amcsd_current/';
        $modified_basedir = $dir.'amcsd_modified/';


        // Need information about the AMCSD database to be able to make changes to it...
        $info = self::getAMCSDInfo( $this->em->getConnection() );
        $database_codes = array_flip( $info['database_codes'] );
        ksort($database_codes);
        $files = array(
            'amc' => $info['amc_files'],
            'cif' => $info['cif_files'],
            'dif' => $info['dif_files'],
        );

        /** @var DataType $amcsd_dt */
        $amcsd_dt = $datatype_repository->find( $info['datatype_id'] );
        if ( $amcsd_dt == null )
            throw new ODRNotFoundException('AMCSD Datatype');

        $amc_file_df_id = $info['amc_file_df_id'];
        $cif_file_df_id = $info['cif_file_df_id'];
        $dif_file_df_id = $info['dif_file_df_id'];
        $df_lookup = array(
            'amc' => $datafield_repository->find($amc_file_df_id),
            'cif' => $datafield_repository->find($cif_file_df_id),
            'dif' => $datafield_repository->find($dif_file_df_id),
        );
        foreach ($df_lookup as $df) {
            if ( $df == null )
                throw new ODRNotFoundException('datafield');
        }

        // Also going to need info on the RRUFF References database...
        $reference_info = self::getReferenceInfo( $this->em->getConnection() );
        /** @var DataType $reference_dt */
        $reference_dt = $datatype_repository->find( $reference_info['datatype_id'] );
        if ( $reference_dt == null )
            throw new ODRNotFoundException('Reference Datatype');

        // ...and the results of the previous step which created the references
        $reference_links = file_get_contents($dir.'parsed_reference_data.txt');
        $reference_links = json_decode($reference_links, true);
        ksort($reference_links);


        // ----------------------------------------
        // Could NOT get this service to properly update AMCSD itself...if called via URL, then
        //  timeout issues.  If called by background jobs, then inexplicable invalid array entries...
        //  ...ODRUploadService was also apparently unable to upload because symfony router was
        //  generating invalid URLs.

        // So, in the interest of not wasting any more time than the 2-3 weeks already spent on this
        // ...convert the data into a CSV file so that CSVImport can do it.


        // ----------------------------------------
        // In order for CSVImport to work, two CSV files are needed...the easier one to make links
        //  the new AMCSD records with the correct RRUFF Reference records
        $query =
           'SELECT dr.id AS dr_id, e.value AS ref_id
            FROM odr_data_record dr
            LEFT JOIN odr_data_record_fields drf ON drf.data_record_id = dr.id
            LEFT JOIN odr_integer_value e ON e.data_record_fields_id = drf.id
            WHERE dr.id IN ('.implode(',', $reference_links).') AND drf.data_field_id = '.$reference_info['ref_id_df_id'].'
            AND dr.deletedAt IS NULL AND drf.deletedAt IS NULL
            AND e.deletedAt IS NULL';
        $results = $conn->fetchAll($query);

        $ref_id_mapping = array();
        foreach ($results as $result) {
            $dr_id = $result['dr_id'];
            $ref_id = $result['ref_id'];

            $ref_id_mapping[$dr_id] = $ref_id;
        }

        // ...then write amcsd -> reference linking data
        $handle = fopen($dir.'reference_links.csv', 'w');
        if ( !$handle )
            throw new ODRException('unable to open reference_links.csv');

        fwrite($handle, "_database_code_amcsd\treference_id\n");
        foreach ($reference_links as $code => $dr_id)
            fwrite($handle, $code."\t".$ref_id_mapping[$dr_id]."\n");
        fclose($handle);


        // ----------------------------------------
        // The other CSV file needs to either update existing AMCSD records, or create new ones
        // This means it needs the database code and the filenames
        $amc_modified_filelist = scandir($modified_basedir.'amc');
        $cif_modified_filelist = scandir($modified_basedir.'cif');
        $dif_modified_filelist = scandir($modified_basedir.'dif');
        if ( count($amc_modified_filelist) > 2 )
            $filetype = 'amc';
        else if ( count($cif_modified_filelist) > 2 )
            $filetype = 'cif';
        else if ( count($dif_modified_filelist) > 2 )
            $filetype = 'dif';
        else
            return 'finished';

        // Need to merge the three different filelists into one...
        $count = 0;
        $filelist = array();
        foreach ($amc_modified_filelist as $num => $filename) {
            // Ignore linux directories...
            if ( $filename === '.' || $filename === '..' )
                continue;

            // The existing arrays only connect filenames with datarecord ids...but want to connect
            //  database codes with filenames for CSVImporting...
            $code = self::getDatabaseCode($modified_basedir.'amc/'.$filename);
            if ( !isset($filelist[$code]) )
                $filelist[$code] = array('amc' => '', 'cif' => '', 'dif' => '');

            // If the file is in this directory, then copy it into the CSVImport storage directory
            $modified_filename = substr($filename, 0, -3);
            $filelist[$code]['amc'] = $modified_filename.'amc';
            copy($modified_basedir.'amc/'.$filename, $dir.'csv_storage/'.$modified_filename.'amc');
        }

        foreach ($cif_modified_filelist as $num => $filename) {
            // Ignore linux directories...
            if ( $filename === '.' || $filename === '..' )
                continue;

            // The existing arrays only connect filenames with datarecord ids...but want to connect
            //  database codes with filenames for CSVImporting...
            $code = self::getDatabaseCode($modified_basedir.'cif/'.$filename);
            if ( !isset($filelist[$code]) )
                $filelist[$code] = array('amc' => '', 'cif' => '', 'dif' => '');

            // If the file is in this directory, then copy it into the CSVImport storage directory
            $modified_filename = substr($filename, 0, -3);
            $filelist[$code]['cif'] = $modified_filename.'cif';
            copy($modified_basedir.'cif/'.$filename, $dir.'csv_storage/'.$modified_filename.'cif');
        }

        foreach ($dif_modified_filelist as $num => $filename) {
            // Ignore linux directories...
            if ( $filename === '.' || $filename === '..' )
                continue;

            // The existing arrays only connect filenames with datarecord ids...but want to connect
            //  database codes with filenames for CSVImporting...
            $code = self::getDatabaseCode($modified_basedir.'dif/'.$filename);
            if ( !isset($filelist[$code]) )
                $filelist[$code] = array('amc' => '', 'cif' => '', 'dif' => '');

            // If the file is in this directory, then copy it into the CSVImport storage directory
            $modified_filename = substr($filename, 0, -3);
            $filelist[$code]['dif'] = $modified_filename.'txt';
            copy($modified_basedir.'dif/'.$filename, $dir.'csv_storage/'.$modified_filename.'txt');
        }

        // All the files listed in $filelist are either new or modified...but need to ensure that
        //  none of the entries are blank, otherwise CSVImport will delete the existing file
        foreach ($filelist as $code => $files) {
            if ( $files['amc'] === '' ) {
                $current_filename = $code.'.txt';
                $import_filename = $code.'.amc';

                $filelist[$code]['amc'] = $import_filename;
                copy($current_basedir.'amc/'.$current_filename, $dir.'csv_storage/'.$import_filename);
            }
            if ( $files['cif'] === '' ) {
                $current_filename = $code.'.txt';
                $import_filename = $code.'.cif';

                $filelist[$code]['cif'] = $import_filename;
                copy($current_basedir.'cif/'.$current_filename, $dir.'csv_storage/'.$import_filename);
            }
            if ( $files['dif'] === '' ) {
                $current_filename = $code.'.txt';
                $import_filename = $code.'.txt';

                $filelist[$code]['dif'] = $import_filename;
                copy($current_basedir.'dif/'.$current_filename, $dir.'csv_storage/'.$import_filename);
            }
        }

        // Write the AMCSD data into a file
        $handle = fopen($dir.'import_amcsd.csv', 'w');
        if ( !$handle )
            throw new ODRException('unable to open import_amcsd.csv');

        fwrite($handle, "_database_code_amcsd\tamc_filename\tcif_filename\tdif_filename\n");
        foreach ($filelist as $code => $files) {
            $amc_filename = $files['amc'];
            $cif_filename = $files['cif'];
            $dif_filename = $files['dif'];
            fwrite($handle, $code."\t".$amc_filename."\t".$cif_filename."\t".$dif_filename."\n");
        }
        fclose($handle);

       return 'finished';
    }


    /**
     * @param string $filename
     * @return string
     */
    private function getDatabaseCode($filename)
    {
        $contents = file_get_contents($filename);
        $pattern = '/_database_code_amcsd (\d{7,7})/';
        $matches = array();
        preg_match($pattern, $contents, $matches);

        return $matches[1];
    }

}
