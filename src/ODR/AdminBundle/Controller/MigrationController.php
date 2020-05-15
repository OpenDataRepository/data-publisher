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

            // Doctrine can't do multi-table updates, so need to find all groups that will be
            //  affected first...
            $query = $em->createQuery(
               'SELECT g.id
                FROM ODRAdminBundle:Group AS g
                WHERE g.purpose IN (:groups)'
            )->setParameters( array('groups' => array('admin', 'edit_all')) );
            $results = $query->getArrayResult();

            $groups = array();
            foreach ($results as $result)
                $groups[] = $result['id'];

//            $query = $em->createQuery(
//               'UPDATE ODRAdminBundle:GroupDatatypePermissions AS gdtp
//                SET gdtp.can_change_public_status = 1
//                WHERE gdtp.group IN (:groups)'
//            )->setParameters( array('groups' => $groups) );
//            $rows = $query->execute();
//
//            $ret .= '<br><br>Updated '.$rows.' rows total';

            // Will recache the group permission arrays later...

            $ret .= '</div>';
            $ret .= '<br>----------------------------------------<br>';

            // Re-enable the softdeleteable filter
            $em->getFilters()->enable('softdeleteable');


            // ----------------------------------------
            // Done with the changes
            $conn->rollBack();
//            $conn->commit();

            // Force a recache of all groups that were affected by this
            foreach ($groups as $num => $group_id)
                $cache_service->delete('group_'.$user->getId().'_permissions');

            // Force a recache of permissions for all users
            foreach ($users as $user)
                $cache_service->delete('user_'.$user->getId().'_permissions');

            $ret .= '</html>';
            print $ret;
        }
        catch (\Exception $e) {
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
