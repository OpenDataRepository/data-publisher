<?php

namespace ODR\AdminBundle\Component\Service;


// Symfony
// use ODR\AdminBundle\Entity\RadioOptions;
use ODR\AdminBundle\Entity\RenderPluginMap;
use ODR\AdminBundle\Entity\RenderPluginOptions;
use ODR\AdminBundle\Entity\ThemeElement;
use ODR\AdminBundle\Entity\ThemeMeta;
use ODR\AdminBundle\Entity\ThemePreferences;
use ODR\OpenRepository\UserBundle\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface as Container;
use Doctrine\ORM\EntityManager;

// Exceptions
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRBadRequestException;
// use ODR\AdminBundle\Exception\ODRForbiddenException;
// use ODR\AdminBundle\Exception\ODRNotFoundException;
// use ODR\AdminBundle\Exception\ODRNotImplementedException;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\RenderPlugin;
use ODR\AdminBundle\Entity\Theme;

// Forms


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
     * @var EntityManager
     */
    private $em;

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

    /**
     * Gets a cached theme.
     *
     * I think this is probably not needed.
     *
     * @param $theme_id
     * @param bool $force_rebuild
    public function getTheme($theme_id, $force_rebuild = false) {
        $theme = null;

        $cache_service = $this->container->get('odr.cache_service');
        $theme = $cache_service->get('default_theme_'.$datatype_id.'_'.$theme_type);

        if($theme == false || $flush) {
            // Get default theme for datatype and theme type
            $repo_theme = $this->em->getRepository('ODRAdminBundle:Theme');
            $theme = $repo_theme->find($theme_id);
        }

        return $theme;
    }
    */

    /**
     * Finds available themes for the selected datatype.
     *
     * @param $datatype_id
     * @param string $theme_type
     * @return array
     * @throws \Exception
     */
    public function getAvailableThemes(
        $datatype_id,
        $theme_type = 'master'
    ) {

        // Get the DataType to work with
        $repo_datatype = $this->em->getRepository('ODRAdminBundle:DataType');
        /** @var DataType $datatype */
        $datatype = $repo_datatype->find($datatype_id);

        if ($datatype == null) {
            throw new \Exception("Datatype is null.");
        }

        $qb = $this->em->createQueryBuilder();
        $qb->select('t')
            ->from('ODRAdminBundle:Theme', 't')
            ->leftJoin('t.themeMeta', 'tm')
            ->where('t.dataType = :datatype_id');

        if ($theme_type == "master") {
            // Custom views should also pull for master view lists
            $qb->andWhere("t.themeType like :theme_type OR t.themeType like 'custom_view'");
        }
        else {
            $qb->andWhere('t.themeType like :theme_type');
        }

        $qb->addOrderBy('tm.displayOrder', 'ASC')
            ->addOrderBy('tm.templateName', 'ASC')
            ->setParameters(array(
                'datatype_id' => $datatype_id,
                'theme_type' => $theme_type,
            ));

        $query = $qb->getQuery();
        $available_themes = $query->getResult();

        $filtered_themes = array();
        if(count($available_themes) > 0) {

            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            /** @var Theme $theme */
            foreach($available_themes as $theme){

                $add_theme = false;
                if($theme->getThemeMeta()->getPublic() != null
                   && $theme->getThemeMeta()->getPublic() <= time()
                ) {
                    // Check if theme is public
                    $add_theme = true;
                }
                else if(
                    $user != "anon."
                    && $theme->getCreatedBy()->getId() == $user->getId()
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
                    $theme_record['theme_type'] = $theme->getThemeType();
                    array_push($filtered_themes, $theme_record);
                }
            }
        }

        return $filtered_themes;

    }

    /**
     * Sets a user's personal default theme for a given datatype.
     * @param DataType $datatype
     * @param Theme $theme
     * @return ThemePreferences
     */
    public function setUserDefaultTheme($datatype, $theme) {

        $repo_theme_preferences = $this->em->getRepository('ODRAdminBundle:ThemePreferences');
        $theme_preferences = $repo_theme_preferences->findBy(array(
            'dataType' => $datatype->getId()
        ));

        // Check if we have a type match
        /** @var ThemePreferences $theme_preference */
        $theme_preference = null;
        /** @var ThemePreferences $tp */
        foreach($theme_preferences as $tp) {
            if($tp->getTheme()->getThemeType() == $theme->getThemeType()) {
                $theme_preference = $tp;
            }
            else if(
                $theme->getThemeType() == "custom_view"
                && $tp->getTheme()->getThemeType() == "master"
            ) {
                $theme_preference = $tp;
            }
        }


        /** @var User $user */
        $user = $this->container->get('security.token_storage')->getToken()->getUser();
        if($theme_preference == null) {
            $theme_preference = new ThemePreferences();
            $theme_preference->setCreatedBy($user);
            $theme_preference->setDatatype($datatype);
        }

        // Modify preferences
        $theme_preference->setTheme($theme);
        $theme_preference->setIsDefault(1);
        $theme_preference->setUpdatedBy($user);

        $this->em->persist($theme_preference);
        $this->em->flush();

        return $theme_preference;
    }

    /**
     * @param DataType $datatype
     * @param Theme $theme
     */
    /**
     * @param $datatype_id
     * @param $theme_type
     * @param bool $flush
     * @return mixed
     */
    public function getDefaultTheme($datatype_id, $theme_type, $flush = false) {

        /** @var CacheService $cache_service */
        $cache_service = $this->container->get('odr.cache_service');
        /** @var Theme $theme */
        $theme = $cache_service->get('default_theme_'.$datatype_id.'_'.$theme_type);

        if($theme == false || $flush) {
            // Get default theme for datatype and theme type
            $qb = $this->em->createQueryBuilder();
            $qb->select('t')
                ->from('ODRAdminBundle:Theme', 't')
                ->leftJoin('t.themeMeta', 'tm')
                ->where('t.dataType = :datatype_id');

            if ($theme_type == "master") {
                // Custom views should also pull for master view lists
                $qb->andWhere("t.themeType like :theme_type OR t.themeType like 'custom_view'");
            }
            else {
                $qb->andWhere('t.themeType like :theme_type');
            }

            $qb->andWhere('tm.isDefault = 1')
                ->setParameters(array(
                    'datatype_id' => $datatype_id,
                    'theme_type' => $theme_type,
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
     * Removes the session setting for a particular datatype and
     * theme type.
     *
     * @param DataType $datatype
     * @param Theme $theme
     */
    public function resetSessionTheme($datatype, $theme_type) {

        if($theme_type == "custom_view") {
            $theme_type = "master";
        }
        else {
            $theme_type = preg_replace('/^custom_/','', $theme_type);
        }

        $session = $this->container->get('session');
        $session_themes = array();
        if ($session->has("session_themes")) {
            $session_themes = $session->get("session_themes");
        }

        // Unset the theme
        if(
            isset($session_themes[$datatype->getId()])
            && isset($session_themes[$datatype->getId()][$theme_type])
        ) {
            unset($session_themes[$datatype->getId()][$theme_type]);
        }

        // Assign array to session
        $session->set("session_themes", $session_themes);

    }

    /**
     * @param DataType $datatype
     * @param Theme $theme
     */
    public function setSessionTheme($datatype, $theme) {

        $theme_type = $theme->getThemeType();
        if($theme->getThemeType() == "custom_view") {
            $theme_type = "master";
        }
        if($theme->getThemeType() == "custom_search_results") {
            $theme_type = "search_results";
        }

        $session = $this->container->get('session');
        $session_themes = array();
        if ($session->has("session_themes")) {
            $session_themes = $session->get("session_themes");
        }

        // Set the theme
        $session_themes[$datatype->getId()][$theme_type] = $theme;

        // Assign array to session
        $session->set("session_themes", $session_themes);

    }

    /**
     * @param $datatype_id
     * @param $theme_type
     * @return mixed|Theme
     */
    public function getUserDefaultTheme($datatype_id, $theme_type) {
        $user_theme = self::getDefaultTheme($datatype_id, $theme_type);
        /** @var User $user */
        $user = $this->container->get('security.token_storage')->getToken()->getUser();
        if($user === "anon.") {
            return $user_theme;
        }

        // Get user's default theme for datatype and theme type
        // Using Join to only pull non-deleted themes and theme preferences.
        $qb = $this->em->createQueryBuilder();
        $qb->select('tp, t')
            ->from('ODRAdminBundle:ThemePreferences', 'tp')
            ->Join('tp.theme', 't')
            ->leftJoin('t.themeMeta', 'tm')
            ->where('t.dataType = :datatype_id')
            ->andWhere('tp.createdBy = :user_id')
            ->andWhere('tp.isDefault = 1');

        if ($theme_type == "master") {
            // Custom views should also pull for master view lists
            $qb->andWhere("t.themeType like :theme_type OR t.themeType like 'custom_view'");
        }
        else {
            $qb->andWhere('t.themeType like :theme_type');
        }

        $qb->addOrderBy('tm.displayOrder', 'ASC')
            ->addOrderBy('tm.templateName', 'ASC')
            ->setParameters(array(
                'datatype_id' => $datatype_id,
                'theme_type' => $theme_type,
                'user_id' => $user->getId(),
            ));

        $query = $qb->getQuery();
        $user_themes_result = $query->getResult();

        if(count($user_themes_result) > 0) {
            /** @var ThemePreferences $user_theme_preference */
            $user_theme_preference = $user_themes_result[0];
            $user_theme = $user_theme_preference->getTheme();
        }

        return $user_theme;
    }

    /**
     * Returns the user's selected session theme
     *
     * @param $datatype_id
     * @param $theme_type
     * @return mixed|Theme|string
     */
    public function getSessionTheme($datatype_id, $theme_type) {
        $theme = null;
        if($this->container->get('session')->isStarted()) {
            $session = $this->container->get('session');

            // Session themes are temporary and apply only to a single tab?
            $session_themes = array();
            if ($session->has("session_themes")) {
                $session_themes = $session->get("session_themes");
            }

            // These don't need to be flushed because the array is directly modified
            // by set session theme.
            if(count($session_themes) > 0
                && isset($session_themes[$datatype_id])
                && isset($session_themes[$datatype_id][$theme_type])
            ) {
                $theme = $session_themes[$datatype_id][$theme_type];
            }
        }

        return $theme;
    }

    /**
     * Determines the current theme choice for the user for the
     * selected datatype and theme type.
     *
     * Maybe this should be stored in REDIS - is the session slow?
     *
     * @param $datatype_id
     * @param $theme_type
     * @param boolean $flush
     * @return mixed
     */
    public function getSelectedTheme($datatype_id, $theme_type, $flush = false){
        $theme = "default";
        if($this->container->get('session')->isStarted()) {
            $session = $this->container->get('session');

            // User theme is the user's default choice for a datatype
            $user_themes = array();
            if ($session->has("user_themes")) {
                $user_themes = $session->get("user_themes");
            }

            if (count($user_themes) > 0
                && isset($user_themes[$datatype_id])
                && isset($user_themes[$datatype_id][$theme_type])
                && !$flush
            ) {
                $theme = $user_themes[$datatype_id][$theme_type];
            } else {
                // We set the session theme to default if user doesn't have one already.
                $theme = self::getUserDefaultTheme($datatype_id, $theme_type);

                // Set session themes
                $user_themes[$datatype_id][$theme_type] = $theme;
                $session->set('user_themes', $user_themes);
            }

            // Session themes are temporary and apply only to a single tab?
            $session_theme = self::getSessionTheme($datatype_id, $theme_type);

            if ($session_theme != null) {
                $theme = $session_theme;
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
     * Always produces the same theme type as original.
     *
     * @param $datatype
     * @param $original_theme
     * @param $user_id
     * @param $tracked_job_id
     * @return string
     */
    public function cloneTheme (
        DataType $datatype,
        Theme $original_theme,
        $user_id,
        $tracked_job_id = 0

    ) {

        $theme = "";
        try {
            $user_manager = $this->container->get('fos_user.user_manager');
            $this->user = $user_manager->findUserBy(array('id' => $user_id));

            $datatype_info_service = $this->container->get('odr.datatype_info_service');

            // Get the Master Datatype to Clone
            $associated_datatypes = $datatype_info_service->getAssociatedDatatypes(array($datatype->getId()));

            $to_type = $original_theme->getThemeType();
            if ($original_theme->getThemeType() == "master") {
                $to_type = "custom_view";
            } else {
                if (!preg_match("/^custom_/", $original_theme->getThemeType())) {
                    $to_type = "custom_".$original_theme->getThemeType();
                }
            }

            // Clone datatype theme
            $theme = self::cloneDatatypeTheme(
                $datatype,
                $original_theme->getThemeType(),
                $to_type
            );

            // Update tracked job if id given
            if ($tracked_job_id > 0) {
                /** @var TrackedJobService $tracked_job_service */
                $tracked_job_service = $this->container->get('odr.tracked_job_service');
                $tracked_job_service->incrementJobProgress($tracked_job_id, (count($associated_datatypes) + 1));
            }
            // Set new theme to have parent_theme_id = itself
            $theme->setParentTheme($theme);
            self::persistObject($theme);

            // Unset "is_default" since all user copied themes must be
            // explicitly set to default by an admin or selected as the
            // user's personal default.
            $theme_meta = $theme->getThemeMeta();
            $theme_meta->setIsDefault(0);
            self::persistObject($theme_meta);


            // Clone Associated Datatypes
            // The parent theme id is the "Custom" theme id to tie all the
            // related child themes to the same custom theme instance
            $repo_datatype = $this->em->getRepository('ODRAdminBundle:DataType');
            /** @var DataType $assoc_datatype */
            foreach ($associated_datatypes as $dt_id) {
                $assoc_datatype = $repo_datatype->find($dt_id);
                if ($dt_id != $datatype->getId() && $assoc_datatype != null) {
                    self::cloneDatatypeTheme(
                        $assoc_datatype,
                        $original_theme->getThemeType(),
                        $to_type,
                        $theme
                    );
                }
                if ($tracked_job_id > 0) {
                    /** @var TrackedJobService $tracked_job_service */
                    $tracked_job_service->incrementJobProgress($tracked_job_id);
                }
            }

            if ($tracked_job_id > 0) {
                if (!isset($tracked_job_service)) {
                    $tracked_job_service = $this->container->get('odr.tracked_job_service');
                }
                $additional_data = array("theme_id" => $theme->getId());
                $tracked_job_service->setAdditionalData($tracked_job_id, json_encode($additional_data));
            }
            return $theme;
        }
        catch(\Exception $e) {
            // If we had an error, delete newly created parent theme
            if($theme != "") {
                $this->em->remove($theme);
                $this->em->flush();
            }
            throw new ODRBadRequestException("Error cloning theme/view.", 8238298);
        }
    }

    /*
     * This should only be used for cloning Master Themes - everything else will start
     * with a theme id.  Is this different from clone from master?
     */
    public function cloneThemesForDatatype(
        DataType $datatype,
        $to_theme_type = null,
        $user_id
    ) {

        $theme = "";

        try {

            if($to_theme_type == null) {
                throw new ODRBadRequestException("A theme type must be passed",0x238219);
            }

            $user_manager = $this->container->get('fos_user.user_manager');
            $this->user = $user_manager->findUserBy(array('id' => $user_id));

            $datatype_info_service = $this->container->get('odr.datatype_info_service');

            // Get the Master Datatype to Clone
            $associated_datatypes = $datatype_info_service->getAssociatedDatatypes(array($datatype->getId()));

            // Clone datatype theme
            $theme = self::cloneDatatypeTheme(
                $datatype,
                'master',
                $to_theme_type
            );

            // Set new theme to have parent_theme_id = itself
            $theme->setParentTheme($theme);
            self::persistObject($theme);

            // Clone Associated Datatypes
            // The parent theme id is the "Custom" theme id to tie all the
            // related child themes to the same custom theme instance
            $repo_datatype = $this->em->getRepository('ODRAdminBundle:DataType');
            foreach($associated_datatypes as $dt_id) {
                /** @var DataType $assoc_datatype */
                $assoc_datatype = $repo_datatype->find($dt_id);
                if($dt_id != $datatype->getId() && $assoc_datatype != null) {
                    self::cloneDatatypeTheme(
                        $assoc_datatype,
                       'master',
                        $to_theme_type,
                        $theme
                    );
                }
            }

            return $theme;
        }
        catch(\Exception $e) {
            // If we had an error, delete newly created parent theme
            if($theme != "") {
                $this->em->remove($theme);
                $this->em->flush();
            }
            $code = 0x12834;
            if ($e instanceof ODRException) {
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($code));
            }
            else {
                throw new ODRBadRequestException(
                    "Error cloning theme/view. Please try again.",
                    $code
                );
            }
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
                $obj->setCreated(new \DateTime(time()));
            }
            if(method_exists($obj, "setUpdated")) {
                $obj->setUpdated(new \DateTime(time()));
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
                    'renderPlugin' => $parent_render_plugin->getId(),
                )
            );
        }
        else {
            $parent_rpi = $repo_rpi->findOneBy(
                array(
                    'dataField' => $datafield->getId(),
                    'renderPlugin' => $parent_render_plugin->getId(),
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
            /** @var RenderPluginOptions $parent_rpo */
            foreach ($parent_rpo_array as $parent_rpo) {
                $new_rpo = clone $parent_rpo;
                $new_rpo->setRenderPluginInstance($new_rpi);
                self::persistObject($new_rpo);
            }

            //  LEFT JOIN dt_rpi.renderPluginMap AS dt_rpm
            $parent_rpm_array = $parent_rpi->getRenderPluginMap();
            /** @var RenderPluginMap $parent_rpm */
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
                        'masterDatafield' => $parent_rpm->getDataField(),
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
     *
     * @param DataType $datatype
     */
    protected function cloneDatatypeThemeFromMaster(
        $datatype
    ) {
        // Get Themes
        $repo_theme = $this->em->getRepository('ODRAdminBundle:Theme');
        /** @var DataType $parent_datatype */
        $parent_datatype = $datatype->getMasterDataType();
        /** @var Theme $parent_theme */
        $parent_theme = $repo_theme->findOneBy(
            array(
                'dataType' => $parent_datatype->getId(),
                'themeType' => 'master',
            )
        );

        // This is the theme we will be copying to.
        $datatype_theme = $repo_theme->findOneBy(
            array(
                'dataType' => $datatype->getId(),
                'themeType' => 'master',
            )
        );

        // Need to delete any existing theme
        if($datatype_theme != null) {
            $this->em->remove($datatype_theme);
            $this->em->flush();
        }

        /** @var Theme $new_theme */
        $new_theme = clone $parent_theme;
        $new_theme->setDataType($datatype);
        self::persistObject($new_theme);

        // Theme Meta
        /** @var ThemeMeta $new_theme_meta */
        $new_theme_meta = clone $parent_theme->getThemeMeta();
        $new_theme_meta->setTheme($new_theme);
        self::persistObject($new_theme_meta);

        // Get the DataFields
        $datafields = $datatype->getDataFields();

        // Get Theme Elements
        $parent_te_array = $parent_theme->getThemeElements();
        /** @var ThemeElement $parent_te */
        foreach($parent_te_array as  $parent_te) {
            /** @var ThemeElement $new_te */
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
                /** @var DataType $created_datatype */
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
     *
     * @param DataType $datatype
     * @param String $from_theme_type
     * @param String $to_theme_type
     * @param Theme $cloned_theme - this is the theme parent
     *
     * @return Theme $new_theme - the newly created theme clone.
     */
    protected function cloneDatatypeTheme(
        $datatype,
        $from_theme_type = "master",
        $to_theme_type = "search_results",
        $cloned_theme = null
    ) {
        // This is the theme we will be copying to.
        $repo_theme = $this->em->getRepository('ODRAdminBundle:Theme');
        $datatype_theme = $repo_theme->findOneBy(
            array(
                'dataType' => $datatype->getId(),
                'themeType' => $from_theme_type,
            )
        );

        /** @var Theme $new_theme */
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
        /** @var ThemeMeta $new_theme_meta */
        $new_theme_meta = clone $datatype_theme->getThemeMeta();
        $new_theme_meta->setTheme($new_theme);
        self::persistObject($new_theme_meta);

        // Get Theme Elements
        $parent_te_array = $datatype_theme->getThemeElements();
        /** @var ThemeElement $parent_te */
        foreach($parent_te_array as  $parent_te) {
            /** @var ThemeElement $new_te */
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
                $new_tdf->setDataField($parent_tdf->getDataField());
                self::persistObject($new_tdf);
            }

            // Theme Data Type
            $parent_theme_dt_array = $parent_te->getThemeDataType();
            foreach($parent_theme_dt_array as $parent_tdt) {
                $new_tdt = clone $parent_tdt;
                $new_tdt->setThemeElement($new_te);
                self::persistObject($new_tdt);
            }
        }

        return $new_theme;

    }
}