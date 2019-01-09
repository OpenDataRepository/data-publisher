<?php

/**
 * Open Data Repository Data Publisher
 * Entity Creation Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * TODO -
 *
 */

namespace ODR\AdminBundle\Component\Service;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataRecordMeta;
use ODR\AdminBundle\Entity\DataTree;
use ODR\AdminBundle\Entity\DataTreeMeta;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTypeMeta;
use ODR\AdminBundle\Entity\FieldType;
use ODR\AdminBundle\Entity\RenderPlugin;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeDataField;
use ODR\AdminBundle\Entity\ThemeDataType;
use ODR\AdminBundle\Entity\ThemeElement;
use ODR\AdminBundle\Entity\ThemeElementMeta;
use ODR\AdminBundle\Entity\ThemeMeta;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRNotImplementedException;
// Other
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;


class EntityCreationService
{

    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var DatarecordInfoService
     */
    private $dri_service;

    /**
     * @var DatatypeInfoService
     */
    private $dti_service;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * EntityCreationService constructor.
     *
     * @param EntityManager $entityManager
     * @param DatarecordInfoService $datarecordInfoService
     * @param DatatypeInfoService $datatypeInfoService
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entityManager,
        DatarecordInfoService $datarecordInfoService,
        DatatypeInfoService $datatypeInfoService,
        Logger $logger
    ) {
        $this->em = $entityManager;
        $this->dri_service = $datarecordInfoService;
        $this->dti_service = $datatypeInfoService;

        $this->logger = $logger;
    }


    /**
     * Creates and persists a new Datafield and DatafieldMeta entity.  TODO - should the creation of groups for the datafield go in here or not?
     *
     * @param ODRUser $user
     * @param DataType $datatype
     * @param FieldType $fieldtype
     * @param bool $delay_flush
     *
     * @return DataFields
     */
    public function createDatafield($user, $datatype, $fieldtype, $delay_flush = false)
    {
        throw new ODRNotImplementedException();
    }


    /**
     * Creates and persists a new Datarecord and a new DatarecordMeta entity.  The user will need
     * to set the provisioned property back to false eventually.
     *
     * @param ODRUser $user
     * @param DataType $datatype The datatype this datarecord will belong to
     * @param bool $delay_flush
     *
     * @return DataRecord
     */
    public function createDatarecord($user, $datatype, $delay_flush = false)
    {
        // Initial create
        $datarecord = new DataRecord();

        $datarecord->setDataType($datatype);
        $datarecord->setCreatedBy($user);
        $datarecord->setUpdatedBy($user);

        // Default to assuming this is a top-level datarecord
        $datarecord->setParent($datarecord);
        $datarecord->setGrandparent($datarecord);

        $datarecord->setProvisioned(true);  // Prevent most areas of the site from doing anything with this datarecord...whatever created this datarecord needs to eventually set this to false
        $datarecord->setUniqueId( $this->dri_service->generateDatarecordUniqueId() );

        $this->em->persist($datarecord);

        $datarecord_meta = new DataRecordMeta();
        $datarecord_meta->setDataRecord($datarecord);
        $datarecord_meta->setPublicDate(new \DateTime('2200-01-01 00:00:00'));   // default to not public

        $datarecord_meta->setCreatedBy($user);
        $datarecord_meta->setUpdatedBy($user);

        $datarecord->addDataRecordMetum($datarecord_meta);
        $this->em->persist($datarecord_meta);

        if ( !$delay_flush )
            $this->em->flush();

        return $datarecord;
    }


    /**
     * Creates and persists a new Datatree and a new DatatreeMeta entry.
     *
     * @param ODRUser $user
     * @param DataType $ancestor The parent datatype (or the datatpe linking to something) in this relationship
     * @param DataType $descendant The child datatype (or the datatype getting linked to) in this relationship
     * @param bool $is_link
     * @param bool $multiple_allowed If true, this relationship permits more than one child/linked datarecord
     * @param bool $delay_flush
     *
     * @return DataTree
     */
    public function createDatatree($user, $ancestor, $descendant, $is_link, $multiple_allowed, $delay_flush = false)
    {
        $datatree = new DataTree();
        $datatree->setAncestor($ancestor);
        $datatree->setDescendant($descendant);

        $datatree->setCreatedBy($user);

        $this->em->persist($datatree);

        $datatree_meta = new DataTreeMeta();
        $datatree_meta->setDataTree($datatree);
        $datatree_meta->setIsLink($is_link);
        $datatree_meta->setMultipleAllowed($multiple_allowed);

        $datatree_meta->setCreatedBy($user);
        $datatree_meta->setUpdatedBy($user);

        $datatree->addDataTreeMetum($datatree_meta);
        $this->em->persist($datatree_meta);

        if ( !$delay_flush )
            $this->em->flush();

        return $datatree;
    }


    /**
     * Creates and persists a new Datatype and DatatypeMeta entity. The caller MUST also create a
     * master theme and call the odr.permissions_management_service to create groups for the new
     * datatype.  After that, they need to set the 'setup_step' property to 'operational'.
     *
     * @param ODRUser $user
     * @param string $datatype_name
     * @param bool $delay_flush
     *
     * @return DataType
     */
    public function createDatatype($user, $datatype_name, $delay_flush = false)
    {
        // Initial create
        $datatype = new DataType();
        $datatype->setSetupStep(DataType::STATE_INITIAL);
        $datatype->setRevision(0);
        $datatype->setIsMasterType(false);

        // TODO - what is this supposed to be used for?
        $datatype->setDatatypeType(null);

        // Assume top-level datatype
        $datatype->setParent($datatype);
        $datatype->setGrandparent($datatype);

        $unique_id = $this->dti_service->generateDatatypeUniqueId();
        $datatype->setUniqueId($unique_id);
        $datatype->setTemplateGroup($unique_id);

        $datatype->setCreatedBy($user);
        $datatype->setUpdatedBy($user);

        $this->em->persist($datatype);


        $datatype_meta = new DataTypeMeta();
        $datatype_meta->setDataType($datatype);
        $datatype_meta->setShortName($datatype_name);
        $datatype_meta->setLongName($datatype_name);
        $datatype_meta->setDescription('');
        $datatype_meta->setXmlShortName('');

        $datatype_meta->setSearchSlug(null);
        $datatype_meta->setSearchNotesUpper(null);
        $datatype_meta->setSearchNotesLower(null);

        // Default to "not-public"
        $datatype_meta->setPublicDate(new \DateTime('2200-01-01 00:00:00'));

        $datatype_meta->setMasterPublishedRevision(0);
        $datatype_meta->setMasterRevision(0);
        $datatype_meta->setTrackingMasterRevision(0);

        // These would be null by default, but are specified here for completeness
        $datatype_meta->setExternalIdField(null);
        $datatype_meta->setNameField(null);
        $datatype_meta->setSortField(null);
        $datatype_meta->setBackgroundImageField(null);

        /** @var RenderPlugin $default_render_plugin */
        $default_render_plugin = $this->em->getRepository('ODRAdminBundle:RenderPlugin')->findOneBy(
            array('pluginClassName' => 'odr_plugins.base.default')
        );
        $datatype_meta->setRenderPlugin($default_render_plugin);

        $datatype_meta->setCreatedBy($user);
        $datatype_meta->setUpdatedBy($user);

        $datatype->addDataTypeMetum($datatype_meta);
        $this->em->persist($datatype_meta);

        if ( !$delay_flush )
            $this->em->flush();

        return $datatype;
    }


    /**
     * Creates and persists a new Theme and its ThemeMeta entry.
     *
     * @param ODRUser $user
     * @param DataType $datatype The Datatype this Theme will belong to
     * @param bool $delay_flush
     *
     * @return Theme
     */
    public function createTheme($user, $datatype, $delay_flush = false)
    {
        // Initial create
        $theme = new Theme();
        $theme->setDataType($datatype);

        // Assume top-level master theme
        $theme->setThemeType('master');
        $theme->setParentTheme($theme);
        $theme->setSourceTheme($theme);

        $theme->setCreatedBy($user);
        $theme->setUpdatedBy($user);

        $datatype->addTheme($theme);
        $this->em->persist($theme);

        $theme_meta = new ThemeMeta();
        $theme_meta->setTheme($theme);
        $theme_meta->setTemplateName('');
        $theme_meta->setTemplateDescription('');

        $theme_meta->setIsDefault(false);
        $theme_meta->setShared(false);
        $theme_meta->setIsTableTheme(false);

        $theme_meta->setSourceSyncVersion(1);

        // Currently unused...
        $theme_meta->setDisplayOrder(null);

        $theme_meta->setCreatedBy($user);
        $theme_meta->setUpdatedBy($user);

        $theme->addThemeMetum($theme_meta);
        $this->em->persist($theme_meta);

        if ( !$delay_flush )
            $this->em->flush();

        return $theme;
    }


    /**
     * Creates and persists a new ThemeDataField entity.
     *
     * @param ODRUser $user
     * @param DataFields $datafield The datafield this ThemeDatafield will point to
     * @param ThemeElement $theme_element The ThemeElement this ThemeDatafield will belong to
     *
     * @return ThemeDataField
     */
    public function createThemeDataField($user, $theme_element, $datafield, $delay_flush = false)
    {
        // Create theme entry
        $theme_datafield = new ThemeDataField();
        $theme_datafield->setDataField($datafield);
        $theme_datafield->setThemeElement($theme_element);

        $theme_datafield->setDisplayOrder(999);
        $theme_datafield->setCssWidthMed('1-3');
        $theme_datafield->setCssWidthXL('1-3');
        $theme_datafield->setHidden(0);

        $theme_datafield->setCreatedBy($user);
        $theme_datafield->setUpdatedBy($user);

        $theme_element->addThemeDataField($theme_datafield);
        $this->em->persist($theme_datafield);

        if ( !$delay_flush )
            $this->em->flush();

        return $theme_datafield;
    }


    /**
     * Creates and persists a new ThemeDataType entity.
     *
     * @param ODRUser $user
     * @param ThemeElement $theme_element The ThemeElement this ThemeDatatype will belong to
     * @param DataType $datatype The child/linked datatype this ThemeDatatype will point to
     * @param Theme $child_theme The Theme of the child/linked datatype to point to...required because a datatype could be linked to multiple times inside the same top-level datatype
     * @param bool $delay_flush
     *
     * @return ThemeDataType
     */
    public function createThemeDatatype($user, $theme_element, $datatype, $child_theme, $delay_flush = false)
    {
        // Create theme entry
        $theme_datatype = new ThemeDataType();
        $theme_datatype->setDataType($datatype);
        $theme_datatype->setThemeElement($theme_element);
        $theme_datatype->setChildTheme($child_theme);

        $theme_datatype->setDisplayType(0);     // 0 is accordion, 1 is tabbed, 2 is dropdown, 3 is list
        $theme_datatype->setHidden(0);

        $theme_datatype->setCreatedBy($user);
        $theme_datatype->setUpdatedBy($user);

        $theme_element->addThemeDataType($theme_datatype);
        $this->em->persist($theme_datatype);

        if ( !$delay_flush )
            $this->em->flush();

        return $theme_datatype;
    }


    /**
     * Creates and persists a new ThemeElement and its ThemeElementMeta entry.
     *
     * @param ODRUser $user
     * @param Theme $theme The Theme this ThemeElement will belong to
     * @param bool $delay_flush
     *
     * @return ThemeElement
     */
    public function createThemeElement($user, $theme, $delay_flush = false)
    {
        // Initial create
        $theme_element = new ThemeElement();

        $theme_element->setTheme($theme);
        $theme_element->setCreatedBy($user);

        $theme->addThemeElement($theme_element);
        $this->em->persist($theme_element);

        $theme_element_meta = new ThemeElementMeta();
        $theme_element_meta->setThemeElement($theme_element);

        $theme_element_meta->setDisplayOrder(-1);
        $theme_element_meta->setHidden(0);
        $theme_element_meta->setCssWidthMed('1-1');
        $theme_element_meta->setCssWidthXL('1-1');

        $theme_element_meta->setCreatedBy($user);
        $theme_element_meta->setUpdatedBy($user);

        $theme_element->addThemeElementMetum($theme_element_meta);
        $this->em->persist($theme_element_meta);

        if ( !$delay_flush )
            $this->em->flush();

        return $theme_element;
    }

    // TODO - add rest of entity creation stuff into here?
}
