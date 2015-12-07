<?php
/**
 * Open Data Repository Data Publisher
 * RadioOptions Entity
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The RadioOptions Entity is automatically generated from
 * ./Resources/config/doctrine/RadioOptions.orm.yml
 */

namespace ODR\AdminBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * RadioOptions
 */
class RadioOptions
{
    /**
     * @var integer
     */
    private $id;

    /**
     * @var integer
     */
    private $value;

    /**
     * @var string
     */
    private $optionName;

    /**
     * @var integer
     */
    private $displayOrder;

    /**
     * @var boolean
     */
    private $isDefault;

    /**
     * @var string
     */
    private $external_id;

    /**
     * @var \DateTime
     */
    private $deletedAt;

    /**
     * @var \DateTime
     */
    private $created;

    /**
     * @var \DateTime
     */
    private $updated;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $children;

    /**
     * @var \ODR\AdminBundle\Entity\RadioOptions
     */
    private $parent;

    /**
     * @var \ODR\AdminBundle\Entity\DataFields
     */
    private $dataFields;

    /**
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     */
    private $createdBy;

    /**
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     */
    private $updatedBy;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->children = new \Doctrine\Common\Collections\ArrayCollection();
    }

    /**
     * Get id
     *
     * @return integer 
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set value
     *
     * @param integer $value
     * @return RadioOptions
     */
    public function setValue($value)
    {
        $this->value = $value;

        return $this;
    }

    /**
     * Get value
     *
     * @return integer 
     */
    public function getValue()
    {
        return $this->value;
    }

    /**
     * Set optionName
     *
     * @param string $optionName
     * @return RadioOptions
     */
    public function setOptionName($optionName)
    {
        $this->optionName = $optionName;

        return $this;
    }

    /**
     * Get optionName
     *
     * @return string 
     */
    public function getOptionName()
    {
        return $this->optionName;
    }

    /**
     * Set displayOrder
     *
     * @param integer $displayOrder
     * @return RadioOptions
     */
    public function setDisplayOrder($displayOrder)
    {
        $this->displayOrder = $displayOrder;

        return $this;
    }

    /**
     * Get displayOrder
     *
     * @return integer 
     */
    public function getDisplayOrder()
    {
        return $this->displayOrder;
    }

    /**
     * Set isDefault
     *
     * @param boolean $isDefault
     * @return RadioOptions
     */
    public function setIsDefault($isDefault)
    {
        $this->isDefault = $isDefault;

        return $this;
    }

    /**
     * Get isDefault
     *
     * @return boolean 
     */
    public function getIsDefault()
    {
        return $this->isDefault;
    }

    /**
     * Set external_id
     *
     * @param string $externalId
     * @return RadioOptions
     */
    public function setExternalId($externalId)
    {
        $this->external_id = $externalId;

        return $this;
    }

    /**
     * Get external_id
     *
     * @return string 
     */
    public function getExternalId()
    {
        return $this->external_id;
    }

    /**
     * Set deletedAt
     *
     * @param \DateTime $deletedAt
     * @return RadioOptions
     */
    public function setDeletedAt($deletedAt)
    {
        $this->deletedAt = $deletedAt;

        return $this;
    }

    /**
     * Get deletedAt
     *
     * @return \DateTime 
     */
    public function getDeletedAt()
    {
        return $this->deletedAt;
    }

    /**
     * Set created
     *
     * @param \DateTime $created
     * @return RadioOptions
     */
    public function setCreated($created)
    {
        $this->created = $created;

        return $this;
    }

    /**
     * Get created
     *
     * @return \DateTime 
     */
    public function getCreated()
    {
        return $this->created;
    }

    /**
     * Set updated
     *
     * @param \DateTime $updated
     * @return RadioOptions
     */
    public function setUpdated($updated)
    {
        $this->updated = $updated;

        return $this;
    }

    /**
     * Get updated
     *
     * @return \DateTime 
     */
    public function getUpdated()
    {
        return $this->updated;
    }

    /**
     * Add children
     *
     * @param \ODR\AdminBundle\Entity\RadioOptions $children
     * @return RadioOptions
     */
    public function addChild(\ODR\AdminBundle\Entity\RadioOptions $children)
    {
        $this->children[] = $children;

        return $this;
    }

    /**
     * Remove children
     *
     * @param \ODR\AdminBundle\Entity\RadioOptions $children
     */
    public function removeChild(\ODR\AdminBundle\Entity\RadioOptions $children)
    {
        $this->children->removeElement($children);
    }

    /**
     * Get children
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getChildren()
    {
        return $this->children;
    }

    /**
     * Set parent
     *
     * @param \ODR\AdminBundle\Entity\RadioOptions $parent
     * @return RadioOptions
     */
    public function setParent(\ODR\AdminBundle\Entity\RadioOptions $parent = null)
    {
        $this->parent = $parent;

        return $this;
    }

    /**
     * Get parent
     *
     * @return \ODR\AdminBundle\Entity\RadioOptions 
     */
    public function getParent()
    {
        return $this->parent;
    }

    /**
     * Set dataFields
     *
     * @param \ODR\AdminBundle\Entity\DataFields $dataFields
     * @return RadioOptions
     */
    public function setDataFields(\ODR\AdminBundle\Entity\DataFields $dataFields = null)
    {
        $this->dataFields = $dataFields;

        return $this;
    }

    /**
     * Get dataFields
     *
     * @return \ODR\AdminBundle\Entity\DataFields 
     */
    public function getDataFields()
    {
        return $this->dataFields;
    }

    /**
     * Set createdBy
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $createdBy
     * @return RadioOptions
     */
    public function setCreatedBy(\ODR\OpenRepository\UserBundle\Entity\User $createdBy = null)
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    /**
     * Get createdBy
     *
     * @return \ODR\OpenRepository\UserBundle\Entity\User 
     */
    public function getCreatedBy()
    {
        return $this->createdBy;
    }

    /**
     * Set updatedBy
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $updatedBy
     * @return RadioOptions
     */
    public function setUpdatedBy(\ODR\OpenRepository\UserBundle\Entity\User $updatedBy = null)
    {
        $this->updatedBy = $updatedBy;

        return $this;
    }

    /**
     * Get updatedBy
     *
     * @return \ODR\OpenRepository\UserBundle\Entity\User 
     */
    public function getUpdatedBy()
    {
        return $this->updatedBy;
    }

    /**
     * Get XMLOptionName
     *
     * @return string
     */
    public function getXMLOptionName()
    {
        // http://unicode-table.com/en/
        // http://www.xml.com/axml/target.html#sec-common-syn
        // http://www.xml.com/axml/target.html#NT-Letter
        $pattern = '/[\\x0-\\x1F]|[\\x21-\\x2C]|[\\x2F][\\x3A-\\x40]|[\\x5B-\\x5E]|[\\x60]|[\\x7B-\\xBF]|[\\xD7]|[\\xF7]/';  // allow dash, period, alphanumeric characters...in name TODO
        $str = preg_replace($pattern, '', $this->optionName);

        if ( strpos($str, '-') === 0 || strpos($str, '.') === 0 )
            $str = substr($str, 1);

        return str_replace(' ', '_', $str);
/*
        $search = array(" ", "\'", "\"", "<", ">", "&", "?", "(", ")");
        $replacements = array("_", "", "", "&lt;", "&gt;", "&amp;", "", "", "");

        return str_replace($search, $replacements, $this->optionName);
*/
    }
}
