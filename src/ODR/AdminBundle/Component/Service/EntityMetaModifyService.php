<?php

/**
 * Open Data Repository Data Publisher
 * Entity Meta Modify Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * TODO -
 *
 */

namespace ODR\AdminBundle\Component\Service;

// Entities
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeMeta;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
// Other
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;


class EntityMetaModifyService
{
    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * EntityMetaModifyService constructor.
     *
     * @param EntityManager $entityManager
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entityManager,
        Logger $logger
    ) {
        $this->em = $entityManager;
        $this->logger = $logger;
    }


    /**
     * Returns true if caller should create a new meta entry, or false otherwise.
     * Currently, this decision is based on when the last change was made, and who made the change
     * ...if change was made by a different person, or within the past hour, don't create a new entry
     *
     * @param ODRUser $user
     * @param mixed $meta_entry
     *
     * @return boolean
     */
    private function createNewMetaEntry($user, $meta_entry)
    {
        $current_datetime = new \DateTime();

        /** @var \DateTime $last_updated */
        /** @var ODRUser $last_updated_by */
        $last_updated = $meta_entry->getUpdated();
        $last_updated_by = $meta_entry->getUpdatedBy();

        // If this change is being made by a different user, create a new meta entry
        if ( $last_updated == null || $last_updated_by == null || $last_updated_by->getId() !== $user->getId() )
            return true;

        // If change was made over an hour ago, create a new meta entry
        $interval = $last_updated->diff($current_datetime);
        if ( $interval->y > 0 || $interval->m > 0 || $interval->d > 0 || $interval->h > 1 )
            return true;

        // Otherwise, update the existing meta entry
        return false;
    }


    // TODO - could just copy all the relevant stuff from ODRCustomController into here, but that doesn't solve the "needs to be kept up to date" problem...
    // TODO - refactor to use PropertyInfo and PropertyAccessor instead?

    /**
     * Copies the contents of the given ThemeMeta entity into a new ThemeMeta entity if something
     * was changed
     *
     * The $properties parameter must contain at least one of the following keys...
     * 'templateName', 'templateDescription', 'isDefault'
     *
     * @param ODRUser $user The user requesting the modification of this meta entry.
     * @param Theme $theme  The Theme entity being modified
     * @param array $properties
     *
     * @return ThemeMeta
     */
    public function copyThemeMeta($user, $theme, $properties)
    {
        // Load the old meta entry
        /** @var ThemeMeta $old_meta_entry */
        $old_meta_entry = $this->em->getRepository('ODRAdminBundle:ThemeMeta')->findOneBy( array('theme' => $theme->getId()) );

        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'templateName' => $old_meta_entry->getTemplateName(),
            'templateDescription' => $old_meta_entry->getTemplateDescription(),
            'isDefault' => $old_meta_entry->getIsDefault(),
            'displayOrder' => $old_meta_entry->getDisplayOrder(),
            'shared' => $old_meta_entry->getShared(),
            'sourceSyncVersion' => $old_meta_entry->getSourceSyncVersion(),
            'isTableTheme' => $old_meta_entry->getIsTableTheme(),
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        if (!$changes_made)
            return $old_meta_entry;


        // Determine whether to create a new meta entry or modify the previous one
        $remove_old_entry = false;
        $new_theme_meta = null;
        if ( self::createNewMetaEntry($user, $old_meta_entry) ) {
            // Clone the old ThemeMeta entry
            $remove_old_entry = true;

            $new_theme_meta = clone $old_meta_entry;

            // These properties aren't automatically updated when persisting the cloned entity...
            $new_theme_meta->setCreated(new \DateTime());
            $new_theme_meta->setUpdated(new \DateTime());
            $new_theme_meta->setCreatedBy($user);
            $new_theme_meta->setUpdatedBy($user);
        }
        else {
            // Update the existing meta entry
            $new_theme_meta = $old_meta_entry;
        }


        // Set any new properties
        if ( isset($properties['templateName']) )
            $new_theme_meta->setTemplateName( $properties['templateName'] );
        if ( isset($properties['templateDescription']) )
            $new_theme_meta->setTemplateDescription( $properties['templateDescription'] );
        if ( isset($properties['isDefault']) )
            $new_theme_meta->setIsDefault( $properties['isDefault'] );
        if ( isset($properties['displayOrder']) )
            $new_theme_meta->setDisplayOrder( $properties['displayOrder'] );
        if ( isset($properties['shared']) )
            $new_theme_meta->setShared( $properties['shared'] );
        if ( isset($properties['sourceSyncVersion']) )
            $new_theme_meta->setSourceSyncVersion( $properties['sourceSyncVersion'] );

        if ( isset($properties['isTableTheme']) ) {
            $new_theme_meta->setIsTableTheme( $properties['isTableTheme'] );

            if ($theme->getThemeType() == 'search_results' && $new_theme_meta->getIsTableTheme()) {
                $theme->setThemeType('table');
                $this->em->persist($theme);
            }
            else if ($theme->getThemeType() == 'table' && !$new_theme_meta->getIsTableTheme()) {
                $theme->setThemeType('search_results');
                $this->em->persist($theme);
            }
        }

        $new_theme_meta->setUpdatedBy($user);


        // Delete the old meta entry if needed
        if ($remove_old_entry)
            $this->em->remove($old_meta_entry);

        // Save the new meta entry
        $this->em->persist($new_theme_meta);
        $this->em->flush();
        $this->em->refresh($theme);

        // Return the new entry
        return $new_theme_meta;
    }
}
