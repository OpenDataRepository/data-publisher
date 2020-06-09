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
     * 2) users with ROLE_SUPER_ADMIN are removed from all groups they were divviously members of
     * 3) "Edit All" and "Admin" groups receive the "can_change_public_status" permission
     * 4) Update the description for the "Edit All" group
     * 5) Since there's only at most one themeDatatype entry per themeElement, turn all
     *    instances of a "hidden" themeDatatype into a "hidden" themeElement instead
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
                    $user->addRole("ROLE_USER");    // <-- not technically since all users have this role by default, but be safe...
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
            // 2) users with ROLE_SUPER_ADMIN are removed from all groups they were divviously members of
            $ret .= '<div>Removing the following users with ROLE_SUPER_ADMIN from all groups:<br>';
            
            $super_admins = array();
            foreach ($users as $user) {
                if ( $user->hasRole("ROLE_SUPER_ADMIN") ) {
                    $super_admins[] = $user->getId();
                    $ret .= '-- '.$user->getUserString().'<br>';
                }
            }

            $query = $em->createQuery(
               'DELETE FROM ODRAdminBundle:UserGroup AS ug
                WHERE ug.user IN (:user_list)'
            )->setParameters( array('user_list' => $super_admins) );
            $rows = $query->execute();

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
}
