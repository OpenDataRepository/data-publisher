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
use ODR\AdminBundle\Entity\RenderPluginInstance;
use ODR\AdminBundle\Entity\RenderPluginOptionsDef;
use ODR\AdminBundle\Entity\RenderPluginOptionsMap;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
// Symfony
use FOS\UserBundle\Doctrine\UserManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

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
}
