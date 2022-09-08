<?php

/**
 * Open Data Repository Data Publisher
 * ODR UserGroup Management Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Contains functions to change user membership in datatype permissions groups.
 */

namespace ODR\AdminBundle\Component\Service;

// Entities
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\Group;
use ODR\AdminBundle\Entity\UserGroup;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exception
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Symfony
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;


class ODRUserGroupMangementService
{
    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var CacheService
     */
    private $cache_service;

    /**
     * @var EntityCreationService
     */
    private $ec_service;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * ODRUserGroupMangementService constructor.
     *
     * @param EntityManager $entity_manager
     * @param CacheService $cache_service
     * @param EntityCreationService $entity_creation_service
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        CacheService $cache_service,
        EntityCreationService $entity_creation_service,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->cache_service = $cache_service;
        $this->ec_service = $entity_creation_service;
        $this->logger = $logger;
    }


    // TODO - anything else that's useful here?

    /**
     * Adds a user to one of the default groups for a datatype.  The user is removed from all other
     * default groups for the datatype as part of this, so you don't have to call the function
     * removeUserFromDefaultGroups() first.
     *
     * @param ODRUser $admin_user The user making the change
     * @param ODRUser $user The user that is being added to the datatype's default group
     * @param DataType $datatype
     * @param string $default_group_purpose one of "admin", "edit_all", "view_all", "view_only"
     */
    public function addUserToDefaultGroup($admin_user, $user, $datatype, $default_group_purpose)
    {
        $default_groups = array('admin', 'edit_all', 'view_all', 'view_only');
        if ( !in_array($default_group_purpose, $default_groups) )
            throw new ODRBadRequestException('default group must be one of "admin", "edit_all", "view_all", or "view_only"');
        
        // ----------------------------------------
        // Typically, users shouldn't be able to change permissions if they're not an admin of the
        //  relevant datatype...
//        if ( !$this->pm_service->isDatatypeAdmin($admin_user, $datatype) )
//            throw new ODRForbiddenException();

        // ...and users also shouldn't be able to change their own permissions...
//        if ( $user->getId() == $admin_user->getId() )
//            throw new ODRBadRequestException('Unable to change own group membership.');

        // ...but this service needs to be callable in situations where neither of the above should
        //  be enforced...such as creation of a new datatype, or some API stuff.

        // The caller MUST ENSURE that random users CAN NOT elevate their own permissions.


        // However, a super-admin should never be a member of a group...
        if ( $user->hasRole('ROLE_SUPER_ADMIN') )
            throw new ODRBadRequestException('Unable to change group membership for a Super-Admin.');
        // ...and only top-level datatypes have groups.
        if ( $datatype->getId() !== $datatype->getGrandparent()->getId() )
            throw new ODRBadRequestException('Unable to change group membership for a child datatype, since only top-level datatypes have groups');


        // ----------------------------------------
        // Determine whether the user is already a member of one of this datatype's default groups
        /** @var Group $group */
        $group = $this->em->getRepository('ODRAdminBundle:Group')->findOneBy(
            array(
                'dataType' => $datatype->getId(),
                'purpose' => $default_group_purpose,
            )
        );
        if ($group == null)
            throw new ODRNotFoundException('Group');

        /** @var UserGroup $user_group */
        $user_group = $this->em->getRepository('ODRAdminBundle:UserGroup')->findOneBy(
            array(
                'group' => $group,
                'user' => $user,
            )
        );

        // The user is already a member of the requested group, so do nothing
        if ($user_group != null)
            return;


        // ----------------------------------------
        // Otherwise, need to determine all default groups the user is a member of, since a user is
        //  only supposed to be a member of a single default group per datatype
        $query = $this->em->createQuery(
           'SELECT ug
            FROM ODRAdminBundle:Group AS g
            JOIN ODRAdminBundle:UserGroup AS ug WITH ug.group = g
            WHERE ug.user = :user_id AND g.purpose != :purpose AND g.dataType = :datatype_id
            AND ug.deletedAt IS NULL AND g.deletedAt IS NULL'
        )->setParameters( array('user_id' => $user->getId(), 'purpose' => '', 'datatype_id' => $datatype->getId()) );
        $results = $query->getResult();

        // Only supposed to be in a single default group, but use foreach incase the
        //  database got messed up somehow...
        $changes_made = false;
        foreach ($results as $ug) {
            /** @var UserGroup $ug */

            // Don't remove the user from the group that they're supposed to be added to
            if ( $ug->getGroup()->getId() !== $group->getId() ) {
                // Can't just call $em->remove($ug)...that won't set deletedBy
                $ug->setDeletedBy($admin_user);
                $ug->setDeletedAt(new \DateTime());
                $this->em->persist($ug);

                $changes_made = true;
            }
        }

        // Flush now that all the updates have been made
        if ($changes_made)
            $this->em->flush();

        // Calling $em->remove($ug) on a $ug that's already soft-deleted completely
        //  deletes the $ug out of the backend database

        // Add this user to the desired group
        $this->ec_service->createUserGroup($user, $group, $admin_user);


        // ----------------------------------------
        // Delete cached version of user's permissions
        $this->cache_service->delete('user_'.$user->getId().'_permissions');
    }


    /**
     * Removes a user from the default groups for a datatype.  There's really no point specifying
     * which group to remove from, since the user is only allowed to be a member of one of the
     * default groups at a time.
     *
     * @param ODRUser $admin_user The user making the change
     * @param ODRUser $user The user that is being removed from the datatype's default groups
     * @param DataType $datatype
     */
    public function removeUserFromDefaultGroups($admin_user, $user, $datatype)
    {

        // ----------------------------------------
        // Typically, users shouldn't be able to change permissions if they're not an admin of the
        //  relevant datatype...
//        if ( !$this->pm_service->isDatatypeAdmin($admin_user, $datatype) )
//            throw new ODRForbiddenException();

        // ...and users also shouldn't be able to change their own permissions...
//        if ( $user->getId() == $admin_user->getId() )
//            throw new ODRBadRequestException('Unable to change own group membership.');

        // ...but this service needs to be callable in situations where neither of the above should
        //  be enforced...such as creation of a new datatype, or some API stuff.

        // The caller MUST ENSURE that random users CAN NOT elevate their own permissions.


        // However, a super-admin should never be a member of a group...
        if ( $user->hasRole('ROLE_SUPER_ADMIN') )
            throw new ODRBadRequestException('Unable to change group membership for a Super-Admin.');
        // ...and only top-level datatypes have groups.
        if ( $datatype->getId() !== $datatype->getGrandparent()->getId() )
            throw new ODRBadRequestException('Unable to change group membership for a child datatype, since only top-level datatypes have groups');


        // ----------------------------------------
        // Determine all default groups the user is a member of, so they can be removed from them
        $query = $this->em->createQuery(
           'SELECT ug
            FROM ODRAdminBundle:Group AS g
            JOIN ODRAdminBundle:UserGroup AS ug WITH ug.group = g
            WHERE ug.user = :user_id AND g.purpose != :purpose AND g.dataType = :datatype_id
            AND ug.deletedAt IS NULL AND g.deletedAt IS NULL'
        )->setParameters( array('user_id' => $user->getId(), 'purpose' => '', 'datatype_id' => $datatype->getId()) );
        $results = $query->getResult();

        // Only supposed to be in a single default group, but use foreach incase the
        //  database got messed up somehow...
        $changes_made = false;
        foreach ($results as $ug) {
            /** @var UserGroup $ug */

            // Can't just call $em->remove($ug)...that won't set deletedBy
            $ug->setDeletedBy($admin_user);
            $ug->setDeletedAt(new \DateTime());
            $this->em->persist($ug);

            $changes_made = true;
        }

        // Flush now that all the updates have been made
        if ($changes_made)
            $this->em->flush();

        // Calling $em->remove($ug) on a $ug that's already soft-deleted completely
        //  deletes the $ug out of the backend database

        // Can't just setDeletedBy() then remove()...doctrine only commits the remove()


        // ----------------------------------------
        // Delete cached version of user's permissions
        $this->cache_service->delete('user_'.$user->getId().'_permissions');
    }


    /**
     * Adds a user to one of the groups for the datatype.  If passed a "default" group, then the
     * correct function is automatically called.
     *
     * @param ODRUser $admin_user The user making the change
     * @param ODRUser $user The user that is being added to requested group
     * @param Group $group
     */
    public function addUserToGroup($admin_user, $user, $group)
    {
        // ----------------------------------------
        // If this is a default group, then use that function instead
        if ( $group->getPurpose() !== '' ) {
            self::addUserToDefaultGroup($admin_user, $user, $group->getDataType(), $group->getPurpose());
            return;
        }


        // ----------------------------------------
        // Can get the datatype from the group
        $datatype = $group->getDataType();

        // Typically, users shouldn't be able to change permissions if they're not an admin of the
        //  relevant datatype...
//        if ( !$this->pm_service->isDatatypeAdmin($admin_user, $datatype) )
//            throw new ODRForbiddenException();

        // ...and users also shouldn't be able to change their own permissions...
//        if ( $user->getId() == $admin_user->getId() )
//            throw new ODRBadRequestException('Unable to change own group membership.');

        // ...but this service needs to be callable in situations where neither of the above should
        //  be enforced...such as creation of a new datatype, or some API stuff.

        // The caller MUST ENSURE that random users CAN NOT elevate their own permissions.


        // However, a super-admin should never be a member of a group...
        if ( $user->hasRole('ROLE_SUPER_ADMIN') )
            throw new ODRBadRequestException('Unable to change group membership for a Super-Admin.');
        // ...and only top-level datatypes have groups.
        if ( $datatype->getId() !== $datatype->getGrandparent()->getId() )
            throw new ODRBadRequestException('Unable to change group membership for a child datatype, since only top-level datatypes have groups');


        // ----------------------------------------
        // Determine whether the user is already a member of this group
        /** @var UserGroup $user_group */
        $user_group = $this->em->getRepository('ODRAdminBundle:UserGroup')->findOneBy(
            array(
                'group' => $group,
                'user' => $user,
            )
        );

        // The user is already a member of this group, do nothing
        if ($user_group != null)
            return;

        // Otherwise, add this user to the desired group
        $this->ec_service->createUserGroup($user, $group, $admin_user);


        // ----------------------------------------
        // Delete cached version of user's permissions
        $this->cache_service->delete('user_'.$user->getId().'_permissions');
    }


    /**
     * Removes a user from one of the groups for the datatype.  If passed a "default" group, then the
     * correct function is automatically called.
     *
     * @param ODRUser $admin_user The user making the change
     * @param ODRUser $user The user that is being added to requested group
     * @param Group $group
     */
    public function removeUserFromGroup($admin_user, $user, $group)
    {
        // ----------------------------------------
        // If this is a default group, then use that function instead
        if ( $group->getPurpose() !== '' ) {
            self::removeUserFromDefaultGroups($admin_user, $user, $group->getDataType());
            return;
        }

        // ----------------------------------------
        // Can get the datatype from the group
        $datatype = $group->getDataType();

        // Typically, users shouldn't be able to change permissions if they're not an admin of the
        //  relevant datatype...
//        if ( !$this->pm_service->isDatatypeAdmin($admin_user, $datatype) )
//            throw new ODRForbiddenException();

        // ...and users also shouldn't be able to change their own permissions...
//        if ( $user->getId() == $admin_user->getId() )
//            throw new ODRBadRequestException('Unable to change own group membership.');

        // ...but this service needs to be callable in situations where neither of the above should
        //  be enforced...such as creation of a new datatype, or some API stuff.

        // The caller MUST ENSURE that random users CAN NOT elevate their own permissions.


        // However, a super-admin should never be a member of a group...
        if ( $user->hasRole('ROLE_SUPER_ADMIN') )
            throw new ODRBadRequestException('Unable to change group membership for a Super-Admin.');
        // ...and only top-level datatypes have groups.
        if ( $datatype->getId() !== $datatype->getGrandparent()->getId() )
            throw new ODRBadRequestException('Unable to change group membership for a child datatype, since only top-level datatypes have groups');


        // ----------------------------------------
        // Determine whether the user is already a member of this group
        /** @var UserGroup $user_group */
        $user_group = $this->em->getRepository('ODRAdminBundle:UserGroup')->findOneBy(
            array(
                'group' => $group,
                'user' => $user,
            )
        );

        // The user isn't a member of this group, so don't need to do anything
        if ($user_group == null)
            return;


        // Can't just call $em->remove($ug)...that won't set deletedBy
        $user_group->setDeletedBy($admin_user);
        $user_group->setDeletedAt(new \DateTime());
        $this->em->persist($user_group);

        // Flush now that all the updates have been made
        $this->em->flush();

        // Calling $em->remove($ug) on a $ug that's already soft-deleted completely
        //  deletes the $ug out of the backend database

        // Can't just setDeletedBy() then remove()...doctrine only commits the remove()


        // ----------------------------------------
        // Delete cached version of user's permissions
        $this->cache_service->delete('user_'.$user->getId().'_permissions');
    }
}
