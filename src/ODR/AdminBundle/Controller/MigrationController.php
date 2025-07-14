<?php

/**
 * Open Data Repository Data Publisher
 * Migration Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This controller holds functions specifically for migrating the database when ODR is upgraded.
 *
 */

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\RenderPluginInstance;
use ODR\AdminBundle\Entity\RenderPluginOptionsDef;
use ODR\AdminBundle\Entity\RenderPluginOptionsMap;
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
use ODR\AdminBundle\Component\Service\DatatreeInfoService;
// Symfony
use FOS\UserBundle\Doctrine\UserManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Templating\EngineInterface;


class MigrationController extends ODRCustomController
{


    /**
     * Performs the following migration actions to update the backend database from ODR v1.0 to v1.1
     * 1) ROLE_ADMIN is removed from all users
     * 2) users with ROLE_SUPER_ADMIN are removed from all groups they were previously members of
     * 3) "Edit All" and "Admin" groups receive the "can_change_public_status" permission
     * 4) Update the description for the "Edit All" group
     * 5) Since there's only at most one themeDatatype entry per themeElement, turn all
     *    instances of a "hidden" themeDatatype into a "hidden" themeElement instead
     * 6) Delete ancient/unused database tables
     * 7) Change the chemin references plugin classname to "odr_plugins.chemin.chemin_references"
     *
     * @param Request $request
     *
     * @return Response
     */
    public function migrate_1_0_Action(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        $conn = null;

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');
            /** @var UserManager $user_manager */
            $user_manager = $this->container->get('fos_user.user_manager');

            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();

            $ret = '<html>';
            $ret .= '<br>----------------------------------------<br>';


            // ----------------------------------------
            // 1) ROLE_ADMIN is removed from all users
            /** @var ODRUser[] $users */
            $users = $user_manager->findUsers();

            $ret .= '<div>Removing ROLE_ADMIN from the following users:<br>';
            foreach ($users as $user) {
                if ( $user->hasRole("ROLE_ADMIN") ) {
                    $user->removeRole("ROLE_ADMIN");
                    $user->addRole("ROLE_USER");    // <-- not technically needed since all users have this role by default, but be safe...
                    $user_manager->updateUser($user);

                    $ret .= '-- '.$user->getUserString().'<br>';
                }
            }
            $ret .= '</div>';
            $ret .= '<br>----------------------------------------<br>';


            // The rest of these can be done in a transaction
            $conn = $em->getConnection();
            $conn->beginTransaction();


            // ----------------------------------------
            // 2) users with ROLE_SUPER_ADMIN are removed from all groups they were previously members of
            $ret .= '<div>Removing the following users with ROLE_SUPER_ADMIN from all groups:<br>';
            
            $super_admins = array();
            foreach ($users as $user) {
                if ( $user->hasRole("ROLE_SUPER_ADMIN") ) {
                    $super_admins[] = $user->getId();
                    $ret .= '-- '.$user->getUserString().'<br>';
                }
            }

            // Need to delete soft-deleted group membership too
            $em->getFilters()->disable('softdeleteable');

            $query = $em->createQuery(
               'DELETE FROM ODRAdminBundle:UserGroup AS ug
                WHERE ug.user IN (:user_list)'
            )->setParameters( array('user_list' => $super_admins) );
            $rows = $query->execute();

            $em->getFilters()->enable('softdeleteable');

            $ret .= '<br>** Deleted '.$rows.' rows total';

            // Will recache the user's permission arrays later...

            $ret .= '</div>';
            $ret .= '<br>----------------------------------------<br>';


            // ----------------------------------------
            // 3) "Edit All" and "Admin" groups receive the "can_change_public_status" permission
            $ret .= '<div>Adding the "can_change_public_status" permission to all "Edit" and "Admin" groups:<br>';

            // Want to be able to update deleted entities as well
            $em->getFilters()->disable('softdeleteable');

            // Doctrine can't do multi-table updates, so need to find all the "Edit" and "Admin"
            //  groups beforehand...
            $query = $em->createQuery(
               'SELECT g.id
                FROM ODRAdminBundle:Group AS g
                WHERE g.purpose IN (:groups)'
            )->setParameters( array('groups' => array('admin', 'edit_all')) );
            $results = $query->getArrayResult();

            $groups = array();
            foreach ($results as $result)
                $groups[] = $result['id'];

            // Update each of the GroupDatatypePermission entries for every "Edit" and "Admin" group
            //  to give them the "can_change_public_status" permission
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:GroupDatatypePermissions AS gdtp
                SET gdtp.can_change_public_status = 1
                WHERE gdtp.group IN (:groups)'
            )->setParameters( array('groups' => $groups) );
            $rows = $query->execute();

            $ret .= '<br>** Updated '.$rows.' rows total';

            // Will recache the group permission arrays later...

            $ret .= '</div>';
            $ret .= '<br>----------------------------------------<br>';

            // Re-enable the softdeleteable filter
            $em->getFilters()->enable('softdeleteable');


            // ----------------------------------------
            // 4) Update the description for the "Edit All" group
            $ret .= '<div>Updating description for the "Edit" group:<br>';

            // Want to be able to update deleted entities as well
            $em->getFilters()->disable('softdeleteable');

            // Doctrine can't do multi-table updates, so need to find all the "Edit" groups beforehand...
            $query = $em->createQuery(
               'SELECT g.id
                FROM ODRAdminBundle:Group AS g
                WHERE g.purpose IN (:groups)'
            )->setParameters( array('groups' => array('edit_all')) );
            $results = $query->getArrayResult();

            $groups = array();
            foreach ($results as $result)
                $groups[] = $result['id'];

            // Update the meta entries for every "Edit" group to have a description that mentions
            //  they're able to change public status now
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:GroupMeta AS gm
                SET gm.groupDescription = :new_description
                WHERE gm.group IN (:groups)'
            )->setParameters(
                array(
                    'new_description' => "Users in this default Group are always allowed to view, edit, and change public status of Datarecords.",
                    'groups' => $groups
                )
            );
            $rows = $query->execute();

            $ret .= '<br> ** Updated '.$rows.' rows total';

            // Re-enable the softdeleteable filter
            $em->getFilters()->enable('softdeleteable');

            $ret .= '</div>';
            $ret .= '<br>----------------------------------------<br>';


            // ----------------------------------------
            // 5) Since there's only at most one themeDatatype entry per themeElement, turn all
            //    instances of a "hidden" themeDatatype into a "hidden" themeElement instead
            $ret .= '<div>Preparation for removing the "hidden" attribute from themeDatatype entities:<br>';

            // Doctrine can't do multi-table updates, so need to find all the themeElements with
            //  "hidden" themeDatatypes beforehand...
            $query = $em->createQuery(
               'SELECT te.id
                FROM ODRAdminBundle:ThemeDataType AS tdt
                JOIN ODRAdminBundle:ThemeElement AS te WITH tdt.themeElement = te
                WHERE tdt.hidden = 1
                AND tdt.deletedAt IS NULL AND te.deletedAt IS NULL'
            );
            $results = $query->getArrayResult();

            $theme_element_ids = array();
            foreach ($results as $result)
                $theme_element_ids[] = $result['id'];

            // Update the meta entries for each themeElement to make them "hidden" now
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:ThemeElementMeta AS tem
                SET tem.hidden = 1
                WHERE tem.themeElement IN (:theme_elements)
                AND tem.deletedAt IS NULL'
            )->setParameters(
                array(
                    'theme_elements' => $theme_element_ids
                )
            );
            $rows = $query->execute();

            $ret .= '<br> ** Updated '.$rows.' themeElements total';

            $ret .= '</div>';
            $ret .= '<br>----------------------------------------<br>';


            // ----------------------------------------
            // 6) Delete ancient/unused database tables
            $ret .= '<div>Dropping unused database tables:<br>';

            // odr_layout is the only table that has foreign key issues...solved by having it after
            //  the rest of the tables with 'layout' in their name
            $old_tables = array(
                'odr_checkbox',
                'odr_file_storage',
                'odr_image_storage',
                'odr_layout_meta',
                'odr_layout_data',
                'odr_user_layout_preferences',
                'odr_layout',
                'odr_radio',
                'odr_theme_element_field',
                'odr_user_field_permissions',
                'odr_user_permissions',
                'odr_xyz_value',
            );

            $conn = $em->getConnection();
            foreach ($old_tables as $table_name) {
                $ret .= '<br> attempting to drop table "'.$table_name.'"...';

                $query = 'DROP TABLE IF EXISTS '.$table_name.';';
                $conn->executeQuery($query);

                $ret .= 'success';
            }

            $ret .= '</div>';
            $ret .= '<br>----------------------------------------<br>';


            // ----------------------------------------
            // 7) Change the chemin references plugin classname to "odr_plugins.chemin.chemin_references"
            $ret .= '<div>Updating "Chemin References" plugin:<br>';
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:RenderPlugin rp
                SET rp.pluginClassName = :new_classname
                WHERE rp.pluginClassName = :old_classname'
            );
            $query->execute(
                array(
                    'new_classname' => "odr_plugins.chemin.chemin_references",
                    'old_classname' => "odr_plugins.base.chemin_references"
                )
            );

            $ret .= 'success';

            $ret .= '</div>';
            $ret .= '<br>----------------------------------------<br>';


            // ----------------------------------------
            // Done with the changes
//            $conn->rollBack();
            $conn->commit();

            // Force a recache of permissions for all users
            foreach ($users as $user)
                $cache_service->delete('user_'.$user->getId().'_permissions');

            $ret .= '</html>';
            print $ret;
        }
        catch (\Exception $e) {

            if ( !is_null($conn) && $conn->isTransactionActive() )
                $conn->rollBack();

            $source = 0xe36aba84;
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
     * Performs the following migration actions to update the backend database from ODR v1.1 to v1.2
     * 1) Delete the database entry for the default render plugin
     * 2a) Load the relevant parts of the existing RenderPluginOption entries
     * 2b) ...hydrate all the ODR entities found in step 1a...
     * 2c) ...then create a new RenderPluginOptionsMap entry for each of the existing RenderPluginOptions entries
     * 3) RenderPluginInstances should only mention either a Datatype or a Datafield, not both
     *
     * @param Request $request
     *
     * @return Response
     */
    public function migrate_1_1_Action(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        $conn = null;

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();

            $ret = '<html>';
            $ret .= '<br>----------------------------------------<br>';

            $conn = $em->getConnection();

            // ----------------------------------------
            // 1) Delete the database entry for the default render plugin
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:RenderPlugin rp
                SET rp.deletedAt = :now
                WHERE rp.pluginClassName = :classname AND rp.deletedAt IS NULL'
            )->setParameters(
                array(
                    'now' => new \DateTime(),
                    'classname' => 'odr_plugins.base.default'
                )
            );
            $query->execute();

            $ret .= 'default render_plugin deleted';

            $ret .= '</div>';
            $ret .= '<br>----------------------------------------<br>';


            // Want the above to always execute, but the rest of this stuff should be in a transaction
            $conn->beginTransaction();


            // ----------------------------------------
            // Ensure there's already stuff in the new RenderPluginOptionsDef table
            $query = $em->createQuery(
               'SELECT rpod
                FROM ODRAdminBundle:RenderPluginOptionsDef rpod'
            );
            $results = $query->getArrayResult();
            if ( empty($results) ) {
                $ret .= 'Update all existing RenderPlugins first, then re-run this controller action';
                $ret .= '</html>';
                print $ret;
                exit();
            }

            // 2a) Load the relevant parts of the existing RenderPluginOption entries
            $query =
               'SELECT rp.id AS rp_id, rpi.id AS rpi_id, rpo.option_name, rpo.option_value,
                    rpo.created, rpo.createdBy, rpo.updated, rpo.updatedBy
                FROM odr_render_plugin_instance rpi
                JOIN odr_render_plugin_options rpo ON rpo.render_plugin_instance_id = rpi.id
                JOIN odr_render_plugin rp ON rpi.render_plugin_id = rp.id
                WHERE rpo.deletedAt IS NULL AND rpi.deletedAt IS NULL';
            $conn = $em->getConnection();
            $results = $conn->executeQuery($query);

            $existing_rpo = array();
            $check = array();

            // Going to need to hydrate these
            $rpi_ids = array();
            $user_ids = array();
            foreach ($results as $result) {
                $rp_id = $result['rp_id'];
                $rpi_id = $result['rpi_id'];
                $optionName = $result['option_name'];
                $optionValue = $result['option_value'];
                $created = $result['created'];
                $updated = $result['updated'];
                $createdBy = $result['createdBy'];
                $updatedBy = $result['updatedBy'];

                if ( $createdBy === '' )
                    $createdBy = null;
                if ( $updatedBy === '' )
                    $updatedBy = null;

                if ( is_null($createdBy) && !is_null($updatedBy) )
                    $createdBy = $updatedBy;
                if ( is_null($updatedBy) && !is_null($createdBy) )
                    $updatedBy = $createdBy;

                // Save ids for hydration...
                if ( !isset($rpi_ids[$rpi_id]) )
                    $rpi_ids[$rpi_id] = 1;
                if ( !isset($user_ids[$createdBy]) )
                    $user_ids[$createdBy] = 1;
                if ( !isset($user_ids[$updatedBy]) )
                    $user_ids[$updatedBy] = 1;

                // Save actual data...
                if ( !isset($existing_rpo[$rpi_id]) )
                    $existing_rpo[$rpi_id] = array();
                $existing_rpo[$rpi_id][$optionName] = array(
                    'value' => $optionValue,
                    'created' => $created,
                    'createdBy' => intval($createdBy),
                    'updated' => $updated,
                    'updatedBy' => intval($updatedBy),
                );

                // Apparently there's a possibility that an rpi entry has a "leftover" rpo entry that
                //  belongs to a different render plugin than the current rpi is using
                $check[$rpi_id] = intval($rp_id);
            }
//            $ret .= '<pre>'.print_r($existing_rpo, true).'</pre>';

            // To prevent this migration from running more than once, load any existing
            //  RenderPluginOptionsMap entries
            $query = $em->createQuery(
               'SELECT partial rpom.{id}, partial rpi.{id}, partial rpo.{id}
                FROM ODRAdminBundle:RenderPluginOptionsMap rpom
                JOIN rpom.renderPluginInstance rpi
                JOIN rpom.renderPluginOptionsDef rpo'
            );
            $results = $query->getArrayResult();

            $existing_rpom = array();
            foreach ($results as $rpom) {
                $rpi_id = $rpom['renderPluginInstance']['id'];
                $rpod_id = $rpom['renderPluginOptionsDef']['id'];

                if ( !isset($existing_rpom[$rpi_id]) )
                    $existing_rpom[$rpi_id] = array();
                $existing_rpom[$rpi_id][$rpod_id] = 0;
            }

            // To prevent problems with the messages for deleted datatypes/datafields, also load the
            //  array version of the rpi entities
            $rpi_ids = array_keys($rpi_ids);

            $em->getFilters()->disable('softdeleteable');
            $query = $em->createQuery(
               'SELECT partial rpi.{id},
                    partial dt.{id}, partial dtm.{id, shortName},
                        partial gdt.{id}, partial gdt_dtm.{id, shortName},
                    partial df.{id}, partial dfm.{id, fieldName},
                        partial df_dt.{id}, partial df_dtm.{id, shortName},
                            partial df_gdt.{id}, partial df_gdt_dtm.{id, shortName}

                FROM ODRAdminBundle:RenderPluginInstance rpi

                LEFT JOIN rpi.dataType AS dt
                LEFT JOIN dt.dataTypeMeta AS dtm
                LEFT JOIN dt.grandparent AS gdt
                LEFT JOIN gdt.dataTypeMeta AS gdt_dtm

                LEFT JOIN rpi.dataField AS df
                LEFT JOIN df.dataFieldMeta AS dfm
                LEFT JOIN df.dataType AS df_dt
                LEFT JOIN df_dt.dataTypeMeta AS df_dtm
                LEFT JOIN df_dt.grandparent AS df_gdt
                LEFT JOIN df_gdt.dataTypeMeta AS df_gdt_dtm

                WHERE rpi IN (:rpi_ids)'
            )->setParameters(array('rpi_ids' => $rpi_ids));
            $results = $query->getArrayResult();
            $em->getFilters()->enable('softdeleteable');

            $rpi_array = array();
            foreach ($results as $result) {
                $rpi_id = $result['id'];
                $rpi_array[$rpi_id] = $result;
            }

            // ----------------------------------------
            // 2b) ...hydrate all the ODR entities found in step 1a...
            $query = $em->createQuery(
               'SELECT rpi
                FROM ODRAdminBundle:RenderPluginInstance rpi
                WHERE rpi IN (:rpi_ids)'
            )->setParameters(array('rpi_ids' => $rpi_ids));
            $results = $query->getResult();

            $rpi_entries = array();
            foreach ($results as $rpi)
                $rpi_entries[$rpi->getId()] = $rpi;
            /** @var RenderPluginInstance[] $rpi_entries */

            // ...and all the Users...
            $user_ids = array_keys($user_ids);
            $query = $em->createQuery(
               'SELECT u
                FROM ODROpenRepositoryUserBundle:User u
                WHERE u IN (:user_ids)'
            )->setParameters(array('user_ids' => $user_ids));
            $results = $query->getResult();

            $user_entries = array();
            foreach ($results as $u)
                $user_entries[$u->getId()] = $u;
            /** @var ODRUser[] $user_entries */

            // ...and hydrate all the existing RenderPluginOptionDefs too
            $query = $em->createQuery(
               'SELECT rpod
                FROM ODRAdminBundle:RenderPluginOptionsDef rpod'
            );
            $results = $query->getResult();

            $rpod_entries = array();
            foreach ($results as $rpo)
                $rpod_entries[$rpo->getName()] = $rpo;
            /** @var RenderPluginOptionsDef[] $rpod_entries */


            // ----------------------------------------
            // 2c) ...then create a new RenderPluginOptionsMap entry for each of the existing
            //  RenderPluginOptions entries
            foreach ($existing_rpo as $rpi_id => $rpo) {
                $rpi = $rpi_entries[$rpi_id];

                foreach ($rpo as $option_key => $data) {
                    // Find the RenderPluginOptionsDef entry
                    if ( !isset($rpod_entries[$option_key]) ) {
                        $ret .= '<p>Unrecognized RenderPluginOption name: "'.$option_key.'" for RenderPlugin "'.$rpi->getRenderPlugin()->getPluginClassName().'", skipping...</p>';
                        continue;
                    }

                    $rpod = $rpod_entries[$option_key];
                    $rpod_id = $rpod->getId();

                    // Verify that this RenderPluginOption belongs to the correct RenderPlugin...
                    if ( $rpod->getRenderPlugin()->getId()  !== $check[$rpi_id] ) {
                        $ret .= '<p>RenderPluginOption "'.$option_key.'" does not belong to the RenderPlugin "'.$rpod->getRenderPlugin()->getPluginClassName().'", skipping...</p>';
                    }
                    else {
                        // If this RenderPluginOption has already been transferred, don't create a
                        //  new RenderPluginOptionsMap
                        if ( isset($existing_rpom[$rpi_id]) && isset($existing_rpom[$rpi_id][$rpod_id]) )
                            continue;

                        // Otherwise, this is a valid RenderPluginOption...transfer the setting
                        //  value into a new RenderPluginOptionsMap entry
                        $rpom = new RenderPluginOptionsMap();
                        $rpom->setRenderPluginInstance($rpi);
                        $rpom->setRenderPluginOptionsDef($rpod);
                        $rpom->setValue($data['value']);

                        $rpom->setCreated(new \DateTime($data['created']));
                        $rpom->setUpdated(new \DateTime($data['updated']));

                        $createdBy = $user_entries[$data['createdBy']];
                        $rpom->setCreatedBy($createdBy);
                        $updatedBy = $user_entries[$data['updatedBy']];
                        $rpom->setUpdatedBy($updatedBy);

                        $em->persist($rpom);

                        // ...can't use $rpi->getDataField() or $rpi->getDataType() because those
                        //  will return NULL when the entity in question is deleted
                        if ( is_null($rpi_array[$rpi_id]['dataField']) ) {
                            $tmp = $rpi_array[$rpi_id];
                            $dt_id = $tmp['dataType']['id'];
                            $dt_name = $tmp['dataType']['dataTypeMeta'][0]['shortName'];
                            $gp_dt_id = $tmp['dataType']['grandparent']['id'];
                            $gp_dt_name = $tmp['dataType']['grandparent']['dataTypeMeta'][0]['shortName'];
                            $rp_name = $rpi->getRenderPlugin()->getPluginClassName();

                            $ret .= 'Grandparent Datatype '.$gp_dt_id.' "'.$gp_dt_name.'", Datatype '.$dt_id.' "'.$dt_name.'"...render_plugin "'.$rp_name.'": transferred option "'.$rpod->getName().'"<br>';
                        }
                        else {
                            $tmp = $rpi_array[$rpi_id];
                            $df_id = $tmp['dataField']['id'];
                            $df_name = $tmp['dataField']['dataFieldMeta'][0]['fieldName'];
                            $dt_name = $tmp['dataField']['dataType']['dataTypeMeta'][0]['shortName'];
                            $dt_id = $tmp['dataField']['dataType']['id'];
                            $gp_dt_id = $tmp['dataField']['dataType']['grandparent']['id'];
                            $gp_dt_name = $tmp['dataField']['dataType']['grandparent']['dataTypeMeta'][0]['shortName'];
                            $rp_name = $rpi->getRenderPlugin()->getPluginClassName();

                            $ret .= 'Grandparent Datatype '.$gp_dt_id.' "'.$gp_dt_name.'", Datatype '.$dt_id.' "'.$dt_name.'" Datafield '.$df_id.' "'.$df_name.'"...render_plugin "'.$rp_name.'": transferred option "'.$rpod->getName().'"<br>';
                        }
                    }
                }
            }

            $ret .= '</div>';
            $ret .= '<br>----------------------------------------<br>';

            // ----------------------------------------
            // 3) RenderPluginInstances should only mention either a Datatype or a Datafield, not both
            $ret .= '<div>Clearing the datatype_id from all RenderPluginInstances that mention both datatypes and datafields:<br>';

            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:RenderPluginInstance rpi
                SET rpi.dataType = NULL
                WHERE rpi.dataField IS NOT NULL AND rpi.dataType IS NOT NULL'
            );
            $num = $query->execute();

            $ret .= 'success, '.$num.' rows updated';

            $ret .= '</div>';
            $ret .= '<br>----------------------------------------<br>';


            // ----------------------------------------
            // Done with the changes
//            $conn->rollBack();
            $conn->commit();

            // Only flush after the transaction is committed
            $em->flush();

            $ret .= '</html>';
            print $ret;
        }
        catch (\Exception $e) {

            if ( !is_null($conn) && $conn->isTransactionActive() )
                $conn->rollBack();

            $source = 0xe8094252;
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
     * Renders the cached array of a particular datatype.
     *
     * @param int $datatype_id
     * @param string $side
     * @param Request $request
     * @return Response
     */
    public function renderdatatypearrayAction($datatype_id, $side, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();

            if ( $this->container->getParameter('kernel.environment') !== 'dev' )
                throw new ODRForbiddenException();
            // --------------------

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatabaseInfoService $database_info_service */
            $database_info_service = $this->container->get('odr.database_info_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            if ( $datatype->getGrandparent()->getId() !== $datatype->getId() )
                throw new ODRBadRequestException('Only permitted on top-level datatypes');

            $datatype_array = $database_info_service->getDatatypeArray($datatype_id, false);    // TODO - ...don't want links, right?
            $datatype_array = $database_info_service->stackDatatypeArray($datatype_array, $datatype_id);

            // ----------------------------------------
            // Render and return a tree structure of data
            if ( $side === 'src' ) {
                $return['d'] = $templating->render(
                    'ODRAdminBundle:Migration:list.html.twig',
                    array(
                        'datatype' => $datatype_array,
                        'side' => 'src',
                    )
                );
            }
            else {
                $return['d'] = array(
                    'datafields' => $templating->render(
                        'ODRAdminBundle:Migration:list.html.twig',
                        array(
                            'datatype' => $datatype_array,
                            'side' => 'fields',
                        ),
                    ),
                    'datatypes' => $templating->render(
                        'ODRAdminBundle:Migration:list.html.twig',
                        array(
                            'datatype' => $datatype_array,
                            'side' => 'types',
                        )
                    ),
                );
            }
        }
        catch (\Exception $e) {
            $source = 0xcc07ffea;
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
     * Renders the cached array of a particular datatype.
     *
     * @param int $src_datafield_id
     * @param int $dest_datafield_id
     * @param Request $request
     * @return Response
     */
    public function renderradiooptionsarrayAction($src_datafield_id, $dest_datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();

            if ( $this->container->getParameter('kernel.environment') !== 'dev' )
                throw new ODRForbiddenException();
            // --------------------

            if ( $src_datafield_id == $dest_datafield_id )
                throw new ODRBadRequestException('datafields should not be the same');


            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatabaseInfoService $database_info_service */
            $database_info_service = $this->container->get('odr.database_info_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');

            /** @var DataFields $src_datafield */
            $src_datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($src_datafield_id);
            if ($src_datafield == null)
                throw new ODRNotFoundException('Source Datafield');
            $src_dt_id = $src_datafield->getDatatype()->getId();

            /** @var DataFields $dest_datafield */
            $dest_datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($dest_datafield_id);
            if ($dest_datafield == null)
                throw new ODRNotFoundException('Dest Datafield');
            $dest_dt_id = $dest_datafield->getDatatype()->getId();

            if ( $src_datafield->getFieldType()->getTypeClass() !== 'Radio'
                || $dest_datafield->getFieldType()->getTypeClass() !== 'Radio'
            ) {
                throw new ODRBadRequestException('datafields are wrong typeclass');
            }

            $src_dt_array = $database_info_service->getDatatypeArray($src_datafield->getDataType()->getGrandparent()->getId(), false);
            $src_ro_array = array();
            if ( isset($src_dt_array[$src_dt_id]['dataFields'][$src_datafield_id]['radioOptions']) )
                $src_ro_array = $src_dt_array[$src_dt_id]['dataFields'][$src_datafield_id]['radioOptions'];

            $dest_dt_array = $database_info_service->getDatatypeArray($dest_datafield->getDataType()->getGrandparent()->getId(), false);
            $dest_ro_array = array();
            if ( isset($dest_dt_array[$dest_dt_id]['dataFields'][$dest_datafield_id]['radioOptions']) )
                $dest_ro_array = $dest_dt_array[$dest_dt_id]['dataFields'][$dest_datafield_id]['radioOptions'];

            // ----------------------------------------
            // Render and return a tree structure of data
            $return['d'] = array(
                'radio_options' => $templating->render(
                    'ODRAdminBundle:Migration:radio_option_list.html.twig',
                    array(
                        'src_df' => $src_dt_array[$src_dt_id]['dataFields'][$src_datafield_id],

                        'src_ro_array' => $src_ro_array,
                        'dest_ro_array' => $dest_ro_array,
                    ),
                ),

            );
        }
        catch (\Exception $e) {
            $source = 0xcc07ffea;
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
     * Generates an HTML page so a super-admin can set options to create a "master template" from
     * an existing datatype.
     *
     * @param Request $request
     * @return Response
     */
    public function createtemplatefromdatatypestartAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();

            if ( $this->container->getParameter('kernel.environment') !== 'dev' )
                throw new ODRForbiddenException();
            // --------------------

            throw new ODRNotImplementedException();
        }
        catch (\Exception $e) {
            $source = 0xbf53149e;
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
     * Parses a POST into a set of mysql commands to generate a "master template" from an existing
     * datatype.
     *
     * @param Request $request
     * @return Response
     */
    public function createtemplatefromdatatypeAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();

            if ( $this->container->getParameter('kernel.environment') !== 'dev' )
                throw new ODRForbiddenException();
            // --------------------

            throw new ODRNotImplementedException();
        }
        catch (\Exception $e) {
            $source = 0xe05966f3;
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
     * Generates some HTML so a super-admin can map the fields in a datatype to use a master template.
     *
     * @param Request $request
     * @return Response
     */
    public function makedatatypeusetemplatestartAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();

            if ( $this->container->getParameter('kernel.environment') !== 'dev' )
                throw new ODRForbiddenException();
            // --------------------

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatatreeInfoService $datatree_info_service */
            $datatree_info_service = $this->container->get('odr.datatree_info_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');

            // Need to get a list of top-level datatypes and top-level templates...
            $top_level_datatypes = array_flip( $datatree_info_service->getTopLevelDatatypes() );
            $top_level_templates = array_flip( $datatree_info_service->getTopLevelTemplates() );

            // Don't want to make a template use another template, so filter out templates from the
            //  list of datatypes
            foreach ($top_level_templates as $dt_id => $num)
                unset( $top_level_datatypes[$dt_id] );

            $top_level_datatypes = array_keys($top_level_datatypes);
            $top_level_templates = array_keys($top_level_templates);

            // Get the names of all of these datatypes
            $query = $em->createQuery(
               'SELECT dt.id AS dt_id, dtm.shortName
                FROM ODRAdminBundle:DataType dt
                JOIN ODRAdminBundle:DataTypeMeta dtm WITH dtm.dataType = dt
                WHERE dt.id IN (:datatype_ids) OR dt.id IN (:template_ids)
                AND dt.metadata_for IS NULL
                AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL
                ORDER BY dtm.shortName'
            )->setParameters(
                array(
                    'datatype_ids' => $top_level_datatypes,
                    'template_ids' => $top_level_templates
                )
            );
            $results = $query->getArrayResult();

            $names = array();
            foreach ($results as $result) {
                $dt_id = $result['dt_id'];
                $dt_name = $result['shortName'];

                $names[$dt_id] = $dt_name;
            }

            // The previous query excluded metadata datatypes, so perform the same filtering on the
            //  two lists
            foreach ($top_level_datatypes as $num => $dt_id) {
                if ( !isset($names[$dt_id]) )
                    unset( $top_level_datatypes[$num] );
            }
            foreach ($top_level_templates as $num => $dt_id) {
                if ( !isset($names[$dt_id]) )
                    unset( $top_level_templates[$num] );
            }

            // Render and return a tree structure of data
            $return['d'] = array(
                'html' =>$templating->render(
                    'ODRAdminBundle:Migration:make_datatype_use_template.html.twig',
                    array(
                        'datatypes' => $top_level_datatypes,
                        'templates' => $top_level_templates,

                        'names' => $names,
                    )
                )
            );
        }
        catch (\Exception $e) {
            $source = 0x2c644397;
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
     * Parses a POST to generate some mysql to convert a datatype to use a master template.
     *
     * @param Request $request
     * @return Response
     */
    public function makedatatypeusetemplateAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();

            if ( $this->container->getParameter('kernel.environment') !== 'dev' )
                throw new ODRForbiddenException();
            // --------------------

            $post = $request->request->all();
            if ( !isset($post['src_dt_id']) || !isset($post['dest_dt_id']) || empty($post['datatypes']) || empty($post['datafields']) )
                throw new ODRBadRequestException('Invalid form');

            $src_dt_id = intval($post['src_dt_id']);
            $dest_dt_id = intval($post['dest_dt_id']);
            $datatypes = $post['datatypes'];
            $datafields = $post['datafields'];

            $radio_options = array();
            if ( isset($post['radio_options']) )
                $radio_options = $post['radio_options'];


            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatabaseInfoService $database_info_service */
            $database_info_service = $this->container->get('odr.database_info_service');

            /** @var DataType $src_datatype */
            $src_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($src_dt_id);
            if ($src_datatype == null)
                throw new ODRNotFoundException('Source Datatype');

            /** @var DataType $dest_datatype */
            $dest_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($dest_dt_id);
            if ($dest_datatype == null)
                throw new ODRNotFoundException('Destination Datatype');

            if ( $src_datatype->getGrandparent()->getId() !== $src_datatype->getId() )
                throw new ODRBadRequestException('Only permitted on top-level datatypes');
            if ( $dest_datatype->getGrandparent()->getId() !== $dest_datatype->getId() )
                throw new ODRBadRequestException('Only permitted on top-level datatypes');

            $src_datatype_array = $database_info_service->getDatatypeArray($src_dt_id, false);    // TODO - ...don't want links, right?
            $dest_datatype_array = $database_info_service->getDatatypeArray($dest_dt_id, false);    // TODO - ...don't want links, right?

            $src_types = array();
            $src_fields = array();
            $src_df_lookup = array();
            foreach ($src_datatype_array as $dt_id => $dt) {
                $src_types[$dt_id] = 1;
                if ( isset($dt['dataFields']) ) {
                    foreach ($dt['dataFields'] as $df_id => $df) {
                        if ( isset($datafields[$df_id]) ) {
                            $src_fields[$df_id] = $df['dataFieldMeta']['fieldType']['typeClass'];
                            $src_df_lookup[$df_id] = $df;
                        }
                    }
                }
            }

            // These are the things that belong to the template
            $dest_datatypes = array();
            $dest_fields = array();
            $dest_df_lookup = array();
            foreach ($dest_datatype_array as $dt_id => $dt) {
                $dest_datatypes[$dt_id] = 1;
                if ( isset($dt['dataFields']) ) {
                    foreach ($dt['dataFields'] as $df_id => $df) {
                        if ( in_array($df_id, $datafields) ) {
                            $dest_fields[$df_id] = $df['dataFieldMeta']['fieldType']['typeClass'];
                            $dest_df_lookup[$df_id] = $df;
                        }
                    }
                }
            }

            foreach ($datafields as $src_df_id => $dest_df_id) {
                if ( !isset($src_fields[$src_df_id]))
                    throw new ODRBadRequestException('src df '.$src_df_id.' not found');
                // ...dest df is allowed to be null here...it means a src df doesn't have a template
                //  field to map to
                if ( $dest_df_id !== '' && !isset($dest_fields[$dest_df_id]) )
                    throw new ODRBadRequestException('dest df '.$dest_df_id.' not found');

                // ...still need to ensure the typeclass matches if src/dest are defined though
                if ( $dest_df_id !== '' && $src_fields[$src_df_id] !== $dest_fields[$dest_df_id] )
                    throw new ODRBadRequestException('src df '.$src_df_id.' and dest df '.$dest_df_id.' typeclasses do not match');
            }

            foreach ($datatypes as $src_dt_id => $dest_dt_id) {
                if ( !isset($src_types[$src_dt_id]))
                    throw new ODRBadRequestException('src dt '.$src_dt_id.' not found');
                // ...dest dt is allowed to be null here...it means a src dt doesn't have a template
                //  to map to
                if ( $dest_dt_id !== '' && !isset($dest_datatypes[$dest_dt_id]) )
                    throw new ODRBadRequestException('dest dt '.$dest_dt_id.' not found');
            }

            $dest_ro_list = array();
            foreach ($radio_options as $src_df_id => $ro_list) {
                if ( !isset($src_fields[$src_df_id]) )
                    throw new ODRBadRequestException('df '.$src_df_id.' from radio option list not found');
                if ( $src_fields[$src_df_id] !== 'Radio' )
                    throw new ODRBadRequestException('df '.$src_df_id.' is not a radio typeclass');

                $src_df = $src_df_lookup[$src_df_id];
                $src_ro_list = array();
                foreach ($src_df['radioOptions'] as $num => $src_ro)
                    $src_ro_list[ $src_ro['id'] ] = 1;

                $dest_df =  $dest_df_lookup[ $datafields[$src_df_id] ];
                foreach ($dest_df['radioOptions'] as $num => $dest_ro)
                    $dest_ro_list[ $dest_ro['id'] ] = 1;

                foreach ($ro_list as $src_ro_id => $dest_ro_id) {
                    if ( !isset($src_ro_list[$src_ro_id]) )
                        throw new ODRBadRequestException('src ro '.$src_ro_id.' not found');
                    // ...dest ro is allowed to be null here...it means a src ro doesn't have a
                    //  corresponding template entity to map to
                    if ( $dest_ro_id !== '' && !isset($dest_ro_list[$dest_ro_id]) )
                        throw new ODRBadRequestException('dest ro '.$dest_ro_id.' not found');
                }
            }


            // ----------------------------------------
            // Now that the submitted form is valid enough...

            // ...need to gather the uuids for both datafields and radio options
            $dest_df_ids = array_keys($dest_fields);
            $dest_ro_ids = array_keys($dest_ro_list);

            $query = $em->createQuery(
               'SELECT df.id AS df_id, df.fieldUuid AS df_uuid
                FROM ODRAdminBundle:DataFields df
                WHERE df.id IN (:datafield_ids)
                AND df.deletedAt IS NULL'
            )->setParameters( array('datafield_ids' => $dest_df_ids) );
            $results = $query->getArrayResult();

            $df_uuid_mapping = array();
            foreach ($results as $result) {
                $df_id = $result['df_id'];
                $field_uuid = $result['df_uuid'];

                $df_uuid_mapping[$df_id] = $field_uuid;
            }

            $query = $em->createQuery(
               'SELECT ro.id AS ro_id, ro.radioOptionUuid AS ro_uuid
                FROM ODRAdminBundle:RadioOptions ro
                WHERE ro.id IN (:radio_option_ids)
                AND ro.deletedAt IS NULL'
            )->setParameters( array('radio_option_ids' => $dest_ro_ids) );
            $results = $query->getArrayResult();

            $ro_uuid_mapping = array();
            foreach ($results as $result) {
                $ro_id = $result['ro_id'];
                $ro_uuid = $result['ro_uuid'];

                $ro_uuid_mapping[$ro_id] = $ro_uuid;
            }


            // ----------------------------------------
            $lines = array();
            $lines[] = 'START TRANSACTION;';

            // All datatypes need to have their master_datatype_id updated
            foreach ($datatypes as $src_dt_id => $dest_dt_id)
                $lines[] = 'UPDATE odr_data_type dt SET dt.master_datatype_id = '.$dest_dt_id.' WHERE dt.id = '.$src_dt_id.';';
            $lines[] = '';

            // All datafields need to have their master_datafield_id updated
            foreach ($datafields as $src_df_id => $dest_df_id) {
                if ( $dest_df_id !== '' ) {
                    $dest_df_uuid = $df_uuid_mapping[$dest_df_id];
                    $lines[] = 'UPDATE odr_data_fields df SET df.master_datafield_id = '.$dest_df_id.', df.template_field_uuid = "'.$dest_df_uuid.'" WHERE df.id = '.$src_df_id.';';
                }
            }
            $lines[] = '';

            // All radio options need to have their radio_option_uuid updated
            foreach ($radio_options as $df_id => $ro_list) {
                foreach ($ro_list as $src_ro_id => $dest_ro_id) {
                    if ( $dest_ro_id !== '' ) {
                        $dest_ro_uuid = $ro_uuid_mapping[$dest_ro_id];
                        $lines[] = 'UPDATE odr_radio_options ro SET ro.radio_option_uuid = "'.$dest_ro_uuid.'" WHERE ro.id = '.$src_ro_id.';';
                    }
                }
            }
            $lines[] = '';

//            $lines[] = 'ROLLBACK;';
            $lines[] = 'COMMIT;';

            $return['d'] = implode("\n", $lines);
        }
        catch (\Exception $e) {
            $source = 0x8eb41a68;
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
     * Generates some HTML so a super-admin can convert all fields/records in one datatype to be
     * another datatype instead.
     *
     * @param Request $request
     * @return Response
     */
    public function movedatatypecontentsstartAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();

            if ( $this->container->getParameter('kernel.environment') !== 'dev' )
                throw new ODRForbiddenException();
            // --------------------

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatatreeInfoService $datatree_info_service */
            $datatree_info_service = $this->container->get('odr.datatree_info_service');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');

            // Need to get a list of all top-level datatypes...
            $top_level_datatypes = $datatree_info_service->getTopLevelDatatypes();
            // ...and then run another query to get their names for ease of use
            $query = $em->createQuery(
               'SELECT dt.id AS dt_id, dtm.shortName
                FROM ODRAdminBundle:DataType dt
                JOIN ODRAdminBundle:DataTypeMeta dtm WITH dtm.dataType = dt
                WHERE dt.id IN (:datatype_ids) AND dt.is_master_type = 0
                AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL
                ORDER BY dtm.shortName'
            )->setParameters( array('datatype_ids' => $top_level_datatypes) );
            $results = $query->getArrayResult();

            $datatypes = array();
            foreach ($results as $result) {
                $dt_id = $result['dt_id'];
                $dt_name = $result['shortName'];

                $datatypes[$dt_id] = $dt_name;
            }

            // Render and return a tree structure of data
            $return['d'] = array(
                'html' =>$templating->render(
                    'ODRAdminBundle:Migration:move_datatype_contents.html.twig',
                    array(
                        'datatypes' => $datatypes,
                    )
                )
            );
        }
        catch (\Exception $e) {
            $source = 0xde66f0db;
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
     * Parses a POST to generate mysql to convert all fields/records in one datatype to be another
     * datatype instead.
     *
     * @param Request $request
     * @return Response
     */
    public function movedatatypecontentsAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();

            if ( $this->container->getParameter('kernel.environment') !== 'dev' )
                throw new ODRForbiddenException();
            // --------------------

            $post = $request->request->all();
            if ( !isset($post['src_dt_id']) || !isset($post['dest_dt_id']) || empty($post['datatypes']) || empty($post['datafields']) )
                throw new ODRBadRequestException('Invalid form');

            $src_dt_id = intval($post['src_dt_id']);
            $dest_dt_id = intval($post['dest_dt_id']);
            $datatypes = $post['datatypes'];
            $datafields = $post['datafields'];

            $radio_options = array();
            if ( isset($post['radio_options']) )
                $radio_options = $post['radio_options'];


            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatabaseInfoService $database_info_service */
            $database_info_service = $this->container->get('odr.database_info_service');

            /** @var DataType $src_datatype */
            $src_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($src_dt_id);
            if ($src_datatype == null)
                throw new ODRNotFoundException('Source Datatype');

            /** @var DataType $dest_datatype */
            $dest_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($dest_dt_id);
            if ($dest_datatype == null)
                throw new ODRNotFoundException('Destination Datatype');

            if ( $src_datatype->getGrandparent()->getId() !== $src_datatype->getId() )
                throw new ODRBadRequestException('Only permitted on top-level datatypes');
            if ( $dest_datatype->getGrandparent()->getId() !== $dest_datatype->getId() )
                throw new ODRBadRequestException('Only permitted on top-level datatypes');

            $src_datatype_array = $database_info_service->getDatatypeArray($src_dt_id, false);    // TODO - ...don't want links, right?
            $dest_datatype_array = $database_info_service->getDatatypeArray($dest_dt_id, false);    // TODO - ...don't want links, right?

            $src_types = array();
            $src_fields = array();
            $src_df_lookup = array();
            foreach ($src_datatype_array as $dt_id => $dt) {
                $src_types[$dt_id] = 1;
                if ( isset($dt['dataFields']) ) {
                    foreach ($dt['dataFields'] as $df_id => $df) {
                        if ( isset($datafields[$df_id]) ) {
                            $src_fields[$df_id] = $df['dataFieldMeta']['fieldType']['typeClass'];
                            $src_df_lookup[$df_id] = $df;
                        }
                    }
                }
            }

            $dest_datatypes = array();
            $dest_fields = array();
            $dest_df_lookup = array();
            foreach ($dest_datatype_array as $dt_id => $dt) {
                $dest_datatypes[$dt_id] = 1;
                if ( isset($dt['dataFields']) ) {
                    foreach ($dt['dataFields'] as $df_id => $df) {
                        if ( in_array($df_id, $datafields) ) {
                            $dest_fields[$df_id] = $df['dataFieldMeta']['fieldType']['typeClass'];
                            $dest_df_lookup[$df_id] = $df;
                        }
                    }
                }
            }

            foreach ($datafields as $src_df_id => $dest_df_id) {
                if ( !isset($src_fields[$src_df_id]))
                    throw new ODRBadRequestException('src df '.$src_df_id.' not found');
                if ( !isset($dest_fields[$dest_df_id]) )
                    throw new ODRBadRequestException('dest df '.$dest_df_id.' not found');

                if ( $src_fields[$src_df_id] !== $dest_fields[$dest_df_id] )
                    throw new ODRBadRequestException('src df '.$src_df_id.' and dest df '.$dest_df_id.' typeclasses do not match');
            }

            foreach ($datatypes as $src_dt_id => $dest_dt_id) {
                if ( !isset($src_types[$src_dt_id]))
                    throw new ODRBadRequestException('src dt '.$src_dt_id.' not found');
                if ( !isset($dest_datatypes[$dest_dt_id]) )
                    throw new ODRBadRequestException('dest dt '.$dest_dt_id.' not found');
            }

            foreach ($radio_options as $src_df_id => $ro_list) {
                if ( !isset($src_fields[$src_df_id]) )
                    throw new ODRBadRequestException('df '.$src_df_id.' from radio option list not found');
                if ( $src_fields[$src_df_id] !== 'Radio' )
                    throw new ODRBadRequestException('df '.$src_df_id.' is not a radio typeclass');

                $src_df = $src_df_lookup[$src_df_id];
                $src_ro_list = array();
                foreach ($src_df['radioOptions'] as $num => $src_ro)
                    $src_ro_list[ $src_ro['id'] ] = 1;

                $dest_df =  $dest_df_lookup[ $datafields[$src_df_id] ];
                $dest_ro_list = array();
                foreach ($dest_df['radioOptions'] as $num => $dest_ro)
                    $dest_ro_list[ $dest_ro['id'] ] = 1;

                foreach ($ro_list as $src_ro_id => $dest_ro_id) {
                    if ( !isset($src_ro_list[$src_ro_id]) )
                        throw new ODRBadRequestException('src ro '.$src_ro_id.' not found');
                    if ( !isset($dest_ro_list[$dest_ro_id]) )
                        throw new ODRBadRequestException('dest ro '.$dest_ro_id.' not found');
                }
            }


            // ----------------------------------------
            // Now that the submitted form is valid enough...

            // ...need to gather some other sets of ids to make soft-deleting easier
            $src_datatype_ids = array_keys($datatypes);
            $query = $em->createQuery(
               'SELECT dt.id
                FROM ODRAdminBundle:DataType dt
                WHERE dt.id IN (:datatype_ids) OR dt.metadata_for IN (:datatype_ids) OR dt.grandparent IN (:datatype_ids)'
            ) ->setParameters( array('datatype_ids' => $src_datatype_ids) );
            $results = $query->getArrayResult();

            $src_datatype_ids = array();
            foreach ($results as $result)
                $src_datatype_ids[ $result['id'] ] = 1;
            $src_datatype_ids = array_keys($src_datatype_ids);

            // datafield ids
            $query = $em->createQuery(
               'SELECT df.id
                FROM ODRAdminBundle:DataFields df
                WHERE df.dataType IN (:datatype_ids)'
            ) ->setParameters( array('datatype_ids' => $src_datatype_ids) );
            $results = $query->getArrayResult();

            $src_datafield_ids = array();
            foreach ($results as $result)
                $src_datafield_ids[ $result['id'] ] = 1;
            $src_datafield_ids = array_keys($src_datafield_ids);

            // radio options
            $query = $em->createQuery(
               'SELECT ro.id
                FROM ODRAdminBundle:RadioOptions ro
                WHERE ro.dataField IN (:datafield_ids)'
            ) ->setParameters( array('datafield_ids' => $src_datafield_ids) );
            $results = $query->getArrayResult();

            $src_ro_ids = array();
            foreach ($results as $result)
                $src_ro_ids[ $result['id'] ] = 1;
            $src_ro_ids = array_keys($src_ro_ids);

            // render plugin instances
            $query = $em->createQuery(
               'SELECT rpi.id
                FROM ODRAdminBundle:RenderPluginInstance rpi
                WHERE rpi.dataType IN (:datatype_ids)'
            ) ->setParameters( array('datatype_ids' => $src_datatype_ids) );
            $results = $query->getArrayResult();

            $src_rpi_ids = array();
            foreach ($results as $result)
                $src_rpi_ids[ $result['id'] ] = 1;
            $src_rpi_ids = array_keys($src_rpi_ids);

            // groups
            $query = $em->createQuery(
               'SELECT g.id
                FROM ODRAdminBundle:Group g
                WHERE g.dataType IN (:datatype_ids)'
            ) ->setParameters( array('datatype_ids' => $src_datatype_ids) );
            $results = $query->getArrayResult();

            $src_group_ids = array();
            foreach ($results as $result)
                $src_group_ids[ $result['id'] ] = 1;
            $src_group_ids = array_keys($src_group_ids);

            // datatree entries
            $query = $em->createQuery(
               'SELECT dt.id
                FROM ODRAdminBundle:DataTree dt
                WHERE dt.ancestor IN (:datatype_ids) OR dt.descendant IN (:datatype_ids)'
            ) ->setParameters( array('datatype_ids' => $src_datatype_ids) );
            $results = $query->getArrayResult();

            $src_datatree_ids = array();
            foreach ($results as $result)
                $src_datatree_ids[ $result['id'] ] = 1;
            $src_datatree_ids  = array_keys($src_datatree_ids);

            // sidebar layouts
            $query = $em->createQuery(
               'SELECT sl.id
                FROM ODRAdminBundle:SidebarLayout sl
                WHERE sl.dataType IN (:datatype_ids)'
            ) ->setParameters( array('datatype_ids' => $src_datatype_ids) );
            $results = $query->getArrayResult();

            $src_sidebar_layout_ids = array();
            foreach ($results as $result)
                $src_sidebar_layout_ids[ $result['id'] ] = 1;
            $src_sidebar_layout_ids  = array_keys($src_sidebar_layout_ids);

            // themes
            $query = $em->createQuery(
               'SELECT t.id
                FROM ODRAdminBundle:Theme t
                WHERE t.dataType IN (:datatype_ids)'
            ) ->setParameters( array('datatype_ids' => $src_datatype_ids) );
            $results = $query->getArrayResult();

            $src_theme_ids = array();
            foreach ($results as $result)
                $src_theme_ids[ $result['id'] ] = 1;
            $src_theme_ids = array_keys($src_theme_ids);

            // themeelements
            $query = $em->createQuery(
               'SELECT te.id
                FROM ODRAdminBundle:ThemeElement te
                WHERE te.theme IN (:theme_ids)'
            ) ->setParameters( array('theme_ids' => $src_theme_ids) );
            $results = $query->getArrayResult();

            $src_themeelement_ids = array();
            foreach ($results as $result)
                $src_themeelement_ids[ $result['id'] ] = 1;
            $src_themeelement_ids = array_keys($src_themeelement_ids);


            // ----------------------------------------
            $lines = array();
            $lines[] = 'START TRANSACTION;';

            // All datarecords need to get moved to the destination datatypes
            foreach ($datatypes as $src_dt_id => $dest_dt_id)
                $lines[] = 'UPDATE odr_data_record dr SET dr.data_type_id = '.$dest_dt_id.' WHERE dr.data_type_id = '.$src_dt_id.';';
            $lines[] = '';
            // Don't need to do anything with datarecordMeta entries

            // All related datarecordfield entries need to update the datafield they point to
            foreach ($datafields as $src_df_id => $dest_df_id)
                $lines[] = 'UPDATE odr_data_record_fields drf SET drf.data_field_id = '.$dest_df_id.' WHERE drf.data_field_id = '.$src_df_id.';';
            $lines[] = '';

            // Each related storage entity also needs to change the datafield it points to
            $typeclass_map = array(
                'Boolean' => 'odr_boolean',
                'File' => 'odr_file',
                'Image' => 'odr_image',
                'IntegerValue' => 'odr_integer_value',
                'DecimalValue' =>  'odr_decimal_value',
                'ShortVarchar' =>  'odr_short_varchar',
                'MediumVarchar' =>  'odr_medium_varchar',
                'LongVarchar' =>  'odr_long_varchar',
                'LongText' => 'odr_long_text',
                'DatetimeValue' => 'odr_datetime_value',
                'XYZData' => 'odr_xyz_data'
            );
            foreach ($src_fields as $src_df_id => $typeclass) {
                if ( isset($typeclass_map[$typeclass]) ) {
                    $dest_df_id = $datafields[$src_df_id];
                    $lines[] = 'UPDATE '.$typeclass_map[$typeclass].' e SET e.data_field_id = '.$dest_df_id.' WHERE e.data_field_id = '.$src_df_id.';';

                    if ( $typeclass === 'Image' )
                        $lines[] = 'UPDATE odr_image_sizes i SET i.deletedAt = NOW() WHERE i.data_fields_id = '.$src_df_id.';';
                }
            }
            $lines[] = '';

            // Radio and tag fields need special attention...
            foreach ($src_fields as $src_df_id => $typeclass) {
                if ( $typeclass === 'Radio' ) {
                    foreach ($radio_options[$src_df_id] as $src_ro_id => $dest_ro_id)
                        $lines[] = 'UPDATE odr_radio_selection rs SET rs.radio_option_id = '.$dest_ro_id.' WHERE rs.radio_option_id = '.$src_ro_id.';';
                    $lines[] = '';
                }
            }
            if ( !empty($src_ro_ids) ) {
                // This is not inside the loop because the user might not have mapped some of the radio options...
                //  ...but they all need to get marked as deleted
                $lines[] = 'UPDATE odr_radio_options_meta rom SET rom.deletedAt = NOW() WHERE rom.radio_option_id IN ('.implode(',', $src_ro_ids).') AND rom.deletedAt IS NULL;';
                $lines[] = 'UPDATE odr_radio_options ro SET ro.deletedAt = NOW(), ro.deletedBy = '.$user->getId().' WHERE ro.data_fields_id IN ('.implode(',', $src_ro_ids).') AND ro.deletedAt IS NULL;';
                $lines[] = '';
            }

            foreach ($src_fields as $src_df_id => $typeclass) {
                if ( $typeclass === 'Tags' ) {
                    throw new ODRNotImplementedException('tags are a pain');
                }
            }
            $lines[] = '';


            // ----------------------------------------
            // soft-delete anything remaining that references the old datatypes/datafields

            // render plugin instances...
            $lines[] = 'UPDATE odr_theme_render_plugin_instance trpi SET trpi.deletedAt = NOW(), trpi.deletedBy = '.$user->getId().' WHERE trpi.render_plugin_instance_id IN ('.implode(',', $src_rpi_ids).') AND trpi.deletedAt IS NULL;';
            $lines[] = 'UPDATE odr_render_plugin_map rpm SET rpm.deletedAt = NOW() WHERE rpm.render_plugin_instance_id IN ('.implode(',', $src_rpi_ids).') AND rpm.deletedAt IS NULL;';
            $lines[] = 'UPDATE odr_render_plugin_map rpm SET rpm.deletedAt = NOW() WHERE rpm.data_type_id IN ('.implode(',', $src_datatype_ids).') AND rpm.deletedAt IS NULL;';
            $lines[] = 'UPDATE odr_render_plugin_options_map rpom SET rpom.deletedAt = NOW() WHERE rpom.render_plugin_instance_id IN ('.implode(',', $src_rpi_ids).') AND rpom.deletedAt IS NULL;';
            $lines[] = 'UPDATE odr_render_plugin_instance rpi SET rpi.deletedAt = NOW() WHERE rpi.id IN ('.implode(',', $src_rpi_ids).') AND rpi.deletedAt IS NULL;';
            $lines[] = '';

            // sidebar layouts...
            if ( !empty($src_sidebar_layout_ids) ) {
                $lines[] = 'UPDATE odr_sidebar_layout_map slm SET slm.deletedAt = NOW() WHERE slm.sidebar_layout_id IN ('.implode(',', $src_sidebar_layout_ids).') AND slm.deletedAt IS NULL;';
                $lines[] = 'UPDATE odr_sidebar_layout_preferences slp SET slp.deletedAt = NOW() WHERE slp.sidebar_layout_id IN ('.implode(',', $src_sidebar_layout_ids).') AND slp.deletedAt IS NULL;';
                $lines[] = 'UPDATE odr_sidebar_layout_meta slm SET slm.deletedAt = NOW() WHERE slm.sidebar_layout_id IN ('.implode(',', $src_sidebar_layout_ids).') AND slm.deletedAt IS NULL;';
                $lines[] = 'UPDATE odr_sidebar_layout sl SET sl.deletedAt = NOW(), sl.deletedBy = '.$user->getId().' WHERE sl.id IN ('.implode(',', $src_sidebar_layout_ids).') AND sl.deletedAt IS NULL;';
                $lines[] = '';
            }

            // regular layouts...
            $lines[] = 'UPDATE odr_theme_data_type tdt SET tdt.deletedAt = NOW() WHERE tdt.theme_element_id IN ('.implode(',', $src_themeelement_ids).') AND tdt.deletedAt IS NULL;';
            $lines[] = 'UPDATE odr_theme_data_type tdt SET tdt.deletedAt = NOW() WHERE tdt.data_type_id IN ('.implode(',', $src_datatype_ids).') AND tdt.deletedAt IS NULL;';

            $lines[] = 'UPDATE odr_theme_data_field tdf SET tdf.deletedAt = NOW() WHERE tdf.theme_element_id IN ('.implode(',', $src_themeelement_ids).') AND tdf.deletedAt IS NULL;';
            $lines[] = 'UPDATE odr_theme_element_meta tem SET tem.deletedAt = NOW() WHERE tem.theme_element_id IN ('.implode(',', $src_themeelement_ids).') AND tem.deletedAt IS NULL;';
            $lines[] = 'UPDATE odr_theme_element te SET te.deletedAt = NOW() WHERE te.id IN ('.implode(',', $src_themeelement_ids).') AND te.deletedAt IS NULL;';

            $lines[] = 'UPDATE odr_theme_preferences tp SET tp.deletedAt = NOW() WHERE tp.theme_id IN ('.implode(',', $src_theme_ids).') AND tp.deletedAt IS NULL;';
            $lines[] = 'UPDATE odr_theme_meta tm SET tm.deletedAt = NOW() WHERE tm.theme_id IN ('.implode(',', $src_theme_ids).') AND tm.deletedAt IS NULL;';
            $lines[] = 'UPDATE odr_theme t SET t.deletedAt = NOW(), t.deletedBy = '.$user->getId().' WHERE t.id IN ('.implode(',', $src_theme_ids).') AND t.deletedAt IS NULL;';
            $lines[] = '';

            // groups...
            $lines[] = 'UPDATE odr_user_group ug SET ug.deletedAt = NOW() WHERE ug.group_id IN ('.implode(',', $src_group_ids).') AND ug.deletedAt IS NULL;';
            $lines[] = 'UPDATE odr_group_datatype_permissions gdtp SET gdtp.deletedAt = NOW() WHERE gdtp.group_id IN ('.implode(',', $src_group_ids).') AND gdtp.deletedAt IS NULL;';
            $lines[] = 'UPDATE odr_group_datafield_permissions gdfp SET gdfp.deletedAt = NOW() WHERE gdfp.group_id IN ('.implode(',', $src_group_ids).') AND gdfp.deletedAt IS NULL;';
            $lines[] = 'UPDATE odr_group_meta gm SET gm.deletedAt = NOW() WHERE gm.group_id IN ('.implode(',', $src_group_ids).') AND gm.deletedAt IS NULL;';
            $lines[] = 'UPDATE odr_group g SET g.deletedAt = NOW(), g.deletedBy = '.$user->getId().' WHERE g.id IN ('.implode(',', $src_group_ids).') AND g.deletedAt IS NULL;';
            $lines[] = '';

            // datatrees...
            $lines[] = 'UPDATE odr_data_tree_meta dtm SET dtm.deletedAt = NOW() WHERE dtm.data_tree_id IN ('.implode(',', $src_datatree_ids).') AND dtm.deletedAt IS NULL;';
            $lines[] = 'UPDATE odr_data_tree dt SET dt.deletedAt = NOW(), dt.deletedBy = '.$user->getId().' WHERE dt.id IN ('.implode(',', $src_datatree_ids).') AND dt.deletedAt IS NULL;';
            $lines[] = '';
            // NOTE: don't need to change odr_linked_data_tree...though that will leave behind dangling entries if not dealt with

            // other cleanup...
            $lines[] = 'UPDATE odr_data_type_special_fields dtsf SET dtsf.deletedAt = NOW(), dtsf.deletedBy = '.$user->getId().' WHERE (dtsf.data_type_id IN ('.implode(',', $src_datatype_ids).') OR dtsf.data_field_id IN ('.implode(',', $src_datafield_ids).')) AND dtsf.deletedAt IS NULL;';
            $lines[] = 'UPDATE odr_stored_search_keys ssk SET ssk.deletedAt = NOW(), ssk.deletedBy = '.$user->getId().' WHERE ssk.data_type_id IN ('.implode(',', $src_datatype_ids).') AND ssk.deletedAt IS NULL;';
            $lines[] = '';

            // datafields...
            $lines[] = 'UPDATE odr_data_fields_meta dfm SET dfm.deletedAt = NOW() WHERE dfm.data_field_id IN ('.implode(',', $src_datafield_ids).') AND dfm.deletedAt IS NULL;';
            $lines[] = 'UPDATE odr_data_fields df SET df.deletedAt = NOW(), df.deletedBy = '.$user->getId().' WHERE df.id IN ('.implode(',', $src_datafield_ids).') AND df.deletedAt IS NULL;';
            $lines[] = '';

            // datatypes...
            $lines[] = 'UPDATE odr_data_type_meta dtm SET dtm.deletedAt = NOW() WHERE dtm.data_type_id IN ('.implode(',', $src_datatype_ids).') AND dtm.deletedAt IS NULL;';
            $lines[] = 'UPDATE odr_data_type dt SET dt.deletedAt = NOW(), dt.deletedBy = '.$user->getId().' WHERE dt.id IN ('.implode(',', $src_datatype_ids).') AND dt.deletedAt IS NULL;';
            $lines[] = '';

//            $lines[] = 'ROLLBACK;';
            $lines[] = 'COMMIT;';

            $return['d'] = implode("\n", $lines);
        }
        catch (\Exception $e) {
            $source = 0xcdddc09c;
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
     * Generates some HTML so a super-admin can decide how to convert a linked datatype into a
     * childtype. Refuses to work when said linked datatype is not linked to by exactly one database.
     *
     * @param Request $request
     * @return Response
     */
    public function convertlinktochildtypestartAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();

            if ( $this->container->getParameter('kernel.environment') !== 'dev' )
                throw new ODRForbiddenException();
            // --------------------

            throw new ODRNotImplementedException();
        }
        catch (\Exception $e) {
            $source = 0x93fbe5f5;
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
     * Parses a POST to generate mysql to convert a linked datatype into a childtype.
     *
     * @param Request $request
     * @return Response
     */
    public function convertlinktochildtypeAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();

            if ( $this->container->getParameter('kernel.environment') !== 'dev' )
                throw new ODRForbiddenException();
            // --------------------

            throw new ODRNotImplementedException();
        }
        catch (\Exception $e) {
            $source = 0x86786c59;
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
     * Generates some HTML so a super-admin can decide how to to convert a linked datatype into a
     * childtype.
     *
     * @param Request $request
     * @return Response
     */
    public function convertchildtypetolinkstartAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();

            if ( $this->container->getParameter('kernel.environment') !== 'dev' )
                throw new ODRForbiddenException();
            // --------------------

            throw new ODRNotImplementedException();
        }
        catch (\Exception $e) {
            $source = 0xe051cdb8;
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
     * Parses a POST to generate mysql to convert a childtype into a linked datatype.
     *
     * @param Request $request
     * @return Response
     */
    public function convertchildtypetolinkAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();

            if ( $this->container->getParameter('kernel.environment') !== 'dev' )
                throw new ODRForbiddenException();
            // --------------------

            throw new ODRNotImplementedException();
        }
        catch (\Exception $e) {
            $source = 0xae0cf3f6;
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
