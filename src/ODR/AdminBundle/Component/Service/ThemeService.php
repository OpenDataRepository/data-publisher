<?php

namespace ODR\AdminBundle\Component\Service;

// Controllers/Classes
use ODR\OpenRepository\SearchBundle\Controller\DefaultController as SearchController;
use ODR\AdminBundle\Controller\ODRCustomController as ODRCustomController;
// Entities
use ODR\AdminBundle\Entity\Boolean AS ODRBoolean;
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataFieldsMeta;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataRecordFields;
use ODR\AdminBundle\Entity\DataRecordMeta;
use ODR\AdminBundle\Entity\DataTree;
use ODR\AdminBundle\Entity\DataTreeMeta;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTypeMeta;
use ODR\AdminBundle\Entity\DatetimeValue;
use ODR\AdminBundle\Entity\DecimalValue;
use ODR\AdminBundle\Entity\FieldType;
use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Entity\FileChecksum;
use ODR\AdminBundle\Entity\FileMeta;
use ODR\AdminBundle\Entity\Group;
use ODR\AdminBundle\Entity\GroupDatafieldPermissions;
use ODR\AdminBundle\Entity\GroupDatatypePermissions;
use ODR\AdminBundle\Entity\GroupMeta;
use ODR\AdminBundle\Entity\Image;
use ODR\AdminBundle\Entity\ImageChecksum;
use ODR\AdminBundle\Entity\ImageMeta;
use ODR\AdminBundle\Entity\ImageSizes;
use ODR\AdminBundle\Entity\IntegerValue;
use ODR\AdminBundle\Entity\LinkedDataTree;
use ODR\AdminBundle\Entity\LongText;
use ODR\AdminBundle\Entity\LongVarchar;
use ODR\AdminBundle\Entity\MediumVarchar;
use ODR\AdminBundle\Entity\RadioOptions;
use ODR\AdminBundle\Entity\RadioOptionsMeta;
use ODR\AdminBundle\Entity\RadioSelection;
use ODR\AdminBundle\Entity\RenderPlugin;
use ODR\AdminBundle\Entity\RenderPluginFields;
use ODR\AdminBundle\Entity\RenderPluginInstance;
use ODR\AdminBundle\Entity\RenderPluginMap;
use ODR\AdminBundle\Entity\RenderPluginOptions;
use ODR\AdminBundle\Entity\ShortVarchar;
use ODR\AdminBundle\Entity\TrackedJob;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeDataField;
use ODR\AdminBundle\Entity\ThemeDataType;
use ODR\AdminBundle\Entity\ThemeElement;
use ODR\AdminBundle\Entity\ThemeElementMeta;
use ODR\AdminBundle\Entity\ThemeMeta;
use ODR\AdminBundle\Entity\TrackedError;
use ODR\OpenRepository\UserBundle\Entity\User;
use ODR\AdminBundle\Entity\UserGroup;
// Forms
// Symfony

use Symfony\Component\DependencyInjection\ContainerInterface as Container;
use Doctrine\ORM\EntityManager;


/**
 * Created by PhpStorm.
 * User: nate
 * Date: 10/14/16
 * Time: 11:59 AM
 */
class ThemeService
{


    /**
     * @var mixed
     */
    private $logger;

    /**
     * @var
     */
    private $user;
    /**
     * @var
     */
    private $created_datatypes;

    /**
     * @var mixed
     */
    private $container;

    /**
     * CreateDatatypeService constructor.
     * @param Container $container
     * @param EntityManager $entity_manager
     * @param $logger
     */
    public function __construct(Container $container, EntityManager $entity_manager, $logger) {
        $this->container = $container;
        $this->em = $entity_manager;
        $this->logger = $logger;
    }

    // availableThemes($datatype_id)
    //    - restricted to current user

    /**
     * Finds available themes for the selected datatype.
     *
     * @param $datatype_id
     * @param string $theme_type
     * @param null $user_id
     * @return array
     * @throws \Exception
     */
    public function getAvailableThemes(
        $datatype_id,
        $theme_type = 'master',
        $user_id = null
    ) {

        $datatype_info_service = $this->container->get('odr.datatype_info_service');

        // Get the DataType to work with
        $repo_datatype = $this->em->getRepository('ODRAdminBundle:DataType');
        $datatype = $repo_datatype->find($datatype_id);

        if($datatype == null) {
            throw new \Exception("Datatype is null.");
        }

        $qb = $this->em->createQueryBuilder();
        $qb->select('t')
            ->from('ODRAdminBundle:Theme', 't')
            ->leftJoin('t.themeMeta', 'tm')
            ->where('t.dataType = :datatype_id')
            ->andWhere('t.themeType like :theme_type')
            ->addOrderBy('tm.displayOrder', 'ASC')
            ->addOrderBy('tm.templateName', 'ASC')
            ->setParameters(array(
                'datatype_id' => $datatype_id,
                'theme_type' => $theme_type
            ));

        $query = $qb->getQuery();
        $available_themes = $query->getResult();

        $filtered_themes = array();
        if(count($available_themes) > 0) {

            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            foreach($available_themes as $theme){

                $add_theme = false;
                if($theme->getThemeMeta()->getPublic() != null
                   && $theme->getThemeMeta()->getPublic() <= date()
                ) {
                    // Check if theme is public
                    $add_theme = true;
                }
                else if(
                    $user != "anon."
                    && $theme->getCreatedBy()->getId() != $user->getId()
                ) {
                    // Check if user has access
                    $add_theme = true;
                }
                else if($theme->getThemeMeta()->getIsDefault() != null) {
                    // Check if is default theme for datatype and theme type
                    $add_theme = true;
                }

                if($add_theme){
                    $theme_record = array();
                    $theme_record['id'] = $theme->getId();
                    $theme_record['name'] = $theme->getThemeMeta()->getTemplateName();
                    $theme_record['description'] = $theme->getThemeMeta()->getTemplateDescription();
                    $theme_record['public'] = $theme->getThemeMeta()->getPublic();
                    $theme_record['is_default'] = $theme->getThemeMeta()->getIsDefault();
                    $theme_record['created_by'] = $theme->getCreatedBy()->getId();
                    $theme_record['display_order'] = $theme->getThemeMeta()->getDisplayOrder();
                    array_push($filtered_themes, $theme_record);
                }
            }
        }

        return $filtered_themes;

    }

    /**
     * @param $datatype_id
     * @param $theme_type
     * @return mixed
     */
    public function getDefaultTheme($datatype_id, $theme_type, $flush = false) {

        $cache_service = $this->container->get('odr.cache_service');

        $theme = $cache_service->get('default_theme_'.$datatype_id.'_'.$theme_type);

        if($theme == false || $flush) {
            // Get default theme for datatype and theme type
            $qb = $this->em->createQueryBuilder();
            $qb->select('t')
                ->from('ODRAdminBundle:Theme', 't')
                ->leftJoin('t.themeMeta', 'tm')
                ->where('t.dataType = :datatype_id')
                ->andWhere('t.themeType like :theme_type')
                ->andWhere('tm.isDefault = 1')
                ->setParameters(array(
                    'datatype_id' => $datatype_id,
                    'theme_type' => $theme_type
                ));

            $query = $qb->getQuery();
            $default_themes_result = $query->getResult();

            if(count($default_themes_result) > 0) {
                $theme = $default_themes_result[0];
                $cache_service->set('default_theme_'.$datatype_id.'_'.$theme_type, $theme);
            }
        }

        // Should pull from cache
        return $theme;
    }

    /**
     * Check current session variables for Datatype
     *
     * @param $datatype_id
     * @param $theme_id
     */
    public function setSessionTheme($datatype_id, $theme_id) {
        // Ensure theme is cached
        foreach($session_themes as $theme_datatype_id => $theme) {
            if($datatype_id == $theme_datatype_id) {
                // Redis Key
                $session_theme = "user_session_theme_" . $theme_preference['id'] . "_" . $theme['id'];

                // Store theme in REDIS for user (short expiry).

            }
        }
    }

    public function getUserDefaultTheme($datatype_id, $theme_type) {
        $user_theme = self::getDefaultTheme($datatype_id, $theme_type);
        $user = $this->container->get('security.token_storage')->getToken()->getUser();
        if($user === "anon.") {
            return $user_theme;
        }

        // Get user's default theme for datatype and theme type
        $qb = $this->em->createQueryBuilder();
        $qb->select('tp, t')
            ->from('ODRAdminBundle:ThemePreferences', 'tp')
            ->leftJoin('tp.theme', 't')
            ->leftJoin('t.themeMeta', 'tm')
            ->where('t.dataType = :datatype_id')
            ->andWhere('t.themeType like :theme_type')
            ->andWhere('tp.createdBy = :user_id')
            ->andWhere('tp.isDefault = 1')
            ->addOrderBy('tm.templateName', 'ASC')
            ->setParameters(array(
                'datatype_id' => $datatype_id,
                'theme_type' => $theme_type,
                'user_id' => $user->getId()
            ));

        $query = $qb->getQuery();
        $user_themes_result = $query->getResult();

        if(count($user_themes_result) > 0) {
            $user_theme = $user_themes_result[0]->getTheme();
        }

        return $user_theme;
    }

    /**
     * Determines the current theme choice for the user for the
     * selected datatype and theme type.
     *
     * Maybe this should be stored in REDIS - is the session slow?
     *
     * @param $datatype_id
     * @param $theme_type
     * @return mixed
     */
    public function getSelectedTheme($datatype_id, $theme_type){
        $theme = "default";
        if($this->container->get('session')->isStarted()) {
            $session = $this->container->get('session');

            // User theme is the user's default choice for a datatype
            $user_themes = array();
            if ($session->has("user_themes")) {
                $user_themes = $session->get("user_themes");
            }

            if(count($user_themes) > 0
                && isset($user_themes[$datatype_id])
                && isset($user_themes[$datatype_id][$theme_type])
                ) {
                $theme = $user_themes[$datatype_id][$theme_type];
            }
            else {
                // We set the session theme to default if user doesn't have one already.
                $theme = self::getUserDefaultTheme($datatype_id, $theme_type);

                // Set session themes
                $user_themes[$datatype_id][$theme_type] = $theme;
                $session->set('user_themes', $user_themes);
            }

            // Session themes are temporary and apply only to a single tab?
            $session_themes = array();
            if ($session->has("session_themes")) {
                $session_themes = $session->get("session_themes");
            }

            if(count($session_themes) > 0
                && isset($session_themes[$datatype_id])
                && isset($session_themes[$datatype_id][$theme_type])
            ) {
                $theme = $session_themes[$datatype_id][$theme_type];
            }
        }

        if($theme == "default") {
            $theme = self::getDefaultTheme($datatype_id, $theme_type);
        }

        return $theme;
    }


    // User Preferred Theme for Datatype


    // cacheTheme($theme_id)

    // checkThemeVersion($theme_id)
    // - recursively check all child themes

    // updateThemeFromSource($theme_id)


    /**
     * Clone a theme for a particular datatype.  Automatically recurses through related themes
     * and sets their source theme id as well as parent id.
     *
     * @param $datatype_id
     * @param $theme_id
     * @param $user_id
     * @param $to_theme_type
     * @return string
     */
    public function cloneThemeById(
        $datatype_id,
        $theme_id,
        $user_id,
        $to_theme_type = null
    ) {
        try {

            $user_manager = $this->container->get('fos_user.user_manager');
            $this->user = $user_manager->findUserBy(array('id' => $user_id));

            $datatype_info_service = $this->container->get('odr.datatype_info_service');

            // Get the DataType to work with
            $repo_datatype = $this->em->getRepository('ODRAdminBundle:DataType');
            $datatype = $repo_datatype->find($datatype_id);

            if($datatype == null) {
                throw new \Exception("Datatype is null.");
            }

            // Get the Master Datatype to Clone
            $associated_datatypes = $datatype_info_service->getAssociatedDatatypes(array($datatype->getId()));

            $repo_theme = $this->em->getRepository('ODRAdminBundle:Theme');
            $original_theme = $repo_theme->find($theme_id);

            // Clone datatype theme
            $theme = self::cloneDatatypeTheme(
                $datatype,
                $original_theme->getThemeType(),
                $to_theme_type,
                $original_theme
            );

            // Set new theme to have parent_theme_id = itself
            $theme->setParentTheme($theme);

            // Clone Associated Datatypes
            // The parent theme id is the "Custom" theme id to tie all the
            // related child themes to the same custom theme instance
            foreach($associated_datatypes as $dt_id) {
                $assoc_datatype = $repo_datatype->find($dt_id);
                if($dt_id != $datatype->getId() && $assoc_datatype != null) {
                    self::cloneDatatypeTheme($assoc_datatype, $original_theme->getThemeType(), $to_theme_type, $theme);
                }
            }

            return "Clone datatype complete.";
        }
        catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    public function cloneThemeByType(
        $datatype_id,
        $theme_id,
        $user_id,
        $to_theme_type = null
    ) {
        try {

            $user_manager = $this->container->get('fos_user.user_manager');
            $this->user = $user_manager->findUserBy(array('id' => $user_id));

            $datatype_info_service = $this->container->get('odr.datatype_info_service');

            // Get the DataType to work with
            $repo_datatype = $this->em->getRepository('ODRAdminBundle:DataType');
            $datatype = $repo_datatype->find($datatype_id);

            if($datatype == null) {
                throw new \Exception("Datatype is null.");
            }

            // Get the Master Datatype to Clone
            $associated_datatypes = $datatype_info_service->getAssociatedDatatypes(array($datatype->getId()));

            $repo_theme = $this->em->getRepository('ODRAdminBundle:Theme');
            $original_theme = $repo_theme->find($theme_id);

            // Clone datatype theme
            $theme = self::cloneDatatypeTheme(
                $datatype,
                $original_theme->getThemeType(),
                $to_theme_type,
                $original_theme
            );

            // Set new theme to have parent_theme_id = itself
            $theme->setParentTheme($theme);

            // Clone Associated Datatypes
            // The parent theme id is the "Custom" theme id to tie all the
            // related child themes to the same custom theme instance
            foreach($associated_datatypes as $dt_id) {
                $assoc_datatype = $repo_datatype->find($dt_id);
                if($dt_id != $datatype->getId() && $assoc_datatype != null) {
                    self::cloneDatatypeTheme($assoc_datatype, $original_theme->getThemeType(), $to_theme_type, $theme);
                }
            }

            return "Clone datatype complete.";
        }
        catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * @param $obj
     * @param bool $update_user_info
     */
    protected function persistObject($obj, $update_user_info = false) {
        if($update_user_info) {
            if(method_exists($obj, "setCreatedBy")) {
                $obj->setCreatedBy($this->user);
            }
            if(method_exists($obj, "setUpdatedBy")) {
                $obj->setUpdatedBy($this->user);
            }
            if(method_exists($obj, "setCreated")) {
                // $obj->setCreated(new DateTime(time()));
            }
            if(method_exists($obj, "setUpdated")) {
                // $obj->setUpdated(new DateTime(time()));
            }
        }
        $this->em->persist($obj);
        $this->em->flush();
        $this->em->refresh($obj);
    }


    /**
     * @param RenderPlugin $parent_render_plugin
     * @param DataType|null $datatype
     * @param DataFields|null $datafield
     */
    protected function cloneRenderPluginSettings(RenderPlugin $parent_render_plugin, Datatype $datatype = null, DataFields $datafield = null) {

        //  LEFT JOIN dt_rp.renderPluginInstance AS dt_rpi WITH (dt_rpi.dataType = dt)
        $repo_rpi = $this->em->getRepository('ODRAdminBundle:RenderPluginInstance');
        if($datatype != null) {
            $parent_rpi = $repo_rpi->findOneBy(
                array(
                    'dataType' => $datatype->getId(),
                    'renderPlugin' => $parent_render_plugin->getId()
                )
            );
        }
        else {
            $parent_rpi = $repo_rpi->findOneBy(
                array(
                    'dataField' => $datafield->getId(),
                    'renderPlugin' => $parent_render_plugin->getId()
                )
            );
        }

        if($parent_rpi != null) {
            $new_rpi = clone $parent_rpi;
            // TODO - This used to be $new_datatype - why is this?
            $new_rpi->setDataType($datatype);
            self::persistObject($new_rpi);

            //  LEFT JOIN dt_rpi.renderPluginOptions AS dt_rpo
            $parent_rpo_array = $parent_rpi->getRenderPluginOptions();
            foreach ($parent_rpo_array as $parent_rpo) {
                $new_rpo = clone $parent_rpo;
                $new_rpo->setRenderPluginInstance($new_rpi);
                self::persistObject($new_rpo);
            }

            //  LEFT JOIN dt_rpi.renderPluginMap AS dt_rpm
            $parent_rpm_array = $parent_rpi->getRenderPluginMap();
            foreach ($parent_rpm_array as $parent_rpm) {
                $new_rpm = clone $parent_rpm;
                $new_rpm->setRenderPluginInstance($new_rpi);
                if($datatype != null) {
                    $new_rpm->setDataType($datatype);
                }
                else {
                    $datatype = $datafield->getDataType();
                }
                $repo_df = $this->em->getRepository('ODRAdminBundle:DataFields');
                $matching_df = $repo_df->findOneBy(
                    array(
                        'dataType' => $datatype->getId(),
                        'masterDatafield' => $parent_rpm->getDataField()
                    )
                );
                $new_rpm->setDataField($matching_df);

                self::persistObject($new_rpm);
            }
        }
    }

    /**
     * cloneDatatypeThemeFromMaster - Default behavior is to clone the theme
     * associated with a master datatype and assign it to the cloned
     * datatype. If parent_datatype is not null, the function is used to
     * create a copy of parent_datatype's theme and assign it to datatype.
     * @param DataType $datatype
     */
    protected function cloneDatatypeThemeFromMaster(
        DataType $datatype
    ) {
        // Get Themes
        $repo_theme = $this->em->getRepository('ODRAdminBundle:Theme');
        $parent_datatype = $datatype->getMasterDataType();
        $parent_theme = $repo_theme->findOneBy(
            array(
                'dataType' => $parent_datatype->getId(),
                'themeType' => 'master'
            )
        );

        // This is the theme we will be copying to.
        $datatype_theme = $repo_theme->findOneBy(
            array(
                'dataType' => $datatype->getId(),
                'themeType' => 'master'
            )
        );

        // Need to delete any existing theme
        if($datatype_theme != null) {
            $this->em->remove($datatype_theme);
            $this->em->flush();
        }

        $new_theme = clone $parent_theme;
        $new_theme->setDataType($datatype);
        self::persistObject($new_theme);

        // Theme Meta
        $new_theme_meta = clone $parent_theme->getThemeMeta();
        $new_theme_meta->setTheme($new_theme);
        self::persistObject($new_theme_meta);

        // Get the DataFields
        $datafields = $datatype->getDataFields();

        // Get Theme Elements
        $parent_te_array = $parent_theme->getThemeElements();
        foreach($parent_te_array as  $parent_te) {
            $new_te = clone $parent_te;
            $new_te->setTheme($new_theme);
            self::persistObject($new_te);

            // Process Meta Records
            $parent_te_meta = $parent_te->getThemeElementMeta();
            $new_te_meta = clone $parent_te_meta;
            $new_te_meta->setThemeElement($new_te);
            self::persistObject($new_te_meta);

            // Theme Data Field
            $parent_theme_df_array = $parent_te->getThemeDataFields();
            foreach($parent_theme_df_array as $parent_tdf) {
                $new_tdf = clone $parent_tdf;
                $new_tdf->setThemeElement($new_te);
                foreach($datafields as $datafield) {
                    if($datafield->getMasterDataField()->getId() == $parent_tdf->getDataField()->getId()) {
                        $new_tdf->setDataField($datafield);
                        self::persistObject($new_tdf);
                        break;
                    }
                }
            }

            // Theme Data Type
            $parent_theme_dt_array = $parent_te->getThemeDataType();
            foreach($parent_theme_dt_array as $parent_tdt) {
                $new_tdt = clone $parent_tdt;
                $new_tdt->setThemeElement($new_te);
                foreach($this->created_datatypes as $created_datatype) {
                    if($created_datatype->getMasterDataType()->getId() == $parent_tdt->getDataType()->getId()) {
                        $new_tdt->setDataType($created_datatype);
                        self::persistObject($new_tdt);
                    }
                }
            }
        }
    }

    /**
     * cloneDatatypeTheme - Default behavior is to clone the theme
     * associated with a master datatype and assign it to the cloned
     * datatype. If parent_datatype is not null, the function is used to
     * create a copy of parent_datatype's theme and assign it to datatype.
     * @param DataType $datatype
     * @param Theme $source_theme
     * @param String $to_theme_type
     * @param Theme $cloned_theme - this is the theme parent
     * @param
     */
    protected function cloneDatatypeTheme(
        DataType $datatype,
        $from_theme_type = "master",
        $to_theme_type = "search_results",
        $cloned_theme = null
    ) {
        // This is the theme we will be copying to.
        $repo_theme = $this->em->getRepository('ODRAdminBundle:Theme');
        $datatype_theme = $repo_theme->findOneBy(
            array(
                'dataType' => $datatype->getId(),
                'themeType' => $from_theme_type
            )
        );

        $new_theme = clone $datatype_theme;
        if($cloned_theme != null) {
            $new_theme->setParentTheme($cloned_theme);
        }
        $new_theme->setThemeType($to_theme_type);

        // Use original source of parent if copying a derivative theme.
        if($datatype_theme->getSourceTheme() != null) {
            $new_theme->setSourceTheme($datatype_theme->getSourceTheme());
        }
        else {
            $new_theme->setSourceTheme($datatype_theme);
        }
        self::persistObject($new_theme);

        // Theme Meta
        $new_theme_meta = clone $datatype_theme->getThemeMeta();
        $new_theme_meta->setTheme($new_theme);
        self::persistObject($new_theme_meta);

        // Get the DataFields
        $datafields = $datatype->getDataFields();

        // Get Theme Elements
        $parent_te_array = $datatype_theme->getThemeElements();
        foreach($parent_te_array as  $parent_te) {
            $new_te = clone $parent_te;
            $new_te->setTheme($new_theme);
            self::persistObject($new_te);

            // Process Meta Records
            $parent_te_meta = $parent_te->getThemeElementMeta();
            $new_te_meta = clone $parent_te_meta;
            $new_te_meta->setThemeElement($new_te);
            self::persistObject($new_te_meta);

            // Theme Data Field
            $parent_theme_df_array = $parent_te->getThemeDataFields();
            foreach($parent_theme_df_array as $parent_tdf) {
                $new_tdf = clone $parent_tdf;
                $new_tdf->setThemeElement($new_te);
                // foreach($datafields as $datafield) {
                    // if($datafield->getMasterDataField()->getId() == $parent_tdf->getDataField()->getId()) {
                //         $new_tdf->setDataField($datafield);
                $new_tdf->setDataField($parent_tdf->getDataField());
                self::persistObject($new_tdf);
                        // break;
                    // }
                // }
            }

            // Theme Data Type
            $parent_theme_dt_array = $parent_te->getThemeDataType();
            foreach($parent_theme_dt_array as $parent_tdt) {
                $new_tdt = clone $parent_tdt;
                $new_tdt->setThemeElement($new_te);
                // $new_tdt->setDataType($created_datatype);
                self::persistObject($new_tdt);
            }
        }

        return $new_theme;

    }
}