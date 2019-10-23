<?php

/**
 * Open Data Repository Data Publisher
 * Tag Entity
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The Tag Entity is automatically generated from
 * ./Resources/config/doctrine/Tags.orm.yml
 */

namespace ODR\AdminBundle\Entity;

/**
 * Tags
 */
class Tags
{
    /**
     * @var integer
     */
    private $id;

    /**
     * @var string
     */
    private $tagName;

    /**
     * @var string
     */
    private $tagUuid;

    /**
     * @var \DateTime
     */
    private $created;

    /**
     * @var \DateTime
     */
    private $deletedAt;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $tagMeta;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $tagSelections;

    /**
     * @var \ODR\AdminBundle\Entity\DataFields
     */
    private $dataField;

    /**
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     */
    private $createdBy;

    /**
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     */
    private $deletedBy;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->tagMeta = new \Doctrine\Common\Collections\ArrayCollection();
        $this->tagSelections = new \Doctrine\Common\Collections\ArrayCollection();
        $this->userCreated = 0;
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
     * Set tagName
     *
     * @param string $tagName
     *
     * @return Tags
     */
    public function setTagName($tagName)
    {
        $this->tagName = $tagName;

        return $this;
    }

    /**
     * Get tagName
     *
     * @return string
     */
    public function getTagName()
    {
        return $this->getTagMeta()->getTagName();
    }

    /**
     * Set tagUuid
     *
     * @param string $tagUuid
     *
     * @return Tags
     */
    public function setTagUuid($tagUuid)
    {
        $this->tagUuid = $tagUuid;

        return $this;
    }

    /**
     * Get tagUuid
     *
     * @return string
     */
    public function getTagUuid()
    {
        return $this->tagUuid;
    }

    /**
     * Set created
     *
     * @param \DateTime $created
     *
     * @return Tags
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
     * Set deletedAt
     *
     * @param \DateTime $deletedAt
     *
     * @return Tags
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
     * Add tagMetum
     *
     * @param \ODR\AdminBundle\Entity\TagMeta $tagMetum
     *
     * @return Tags
     */
    public function addTagMetum(\ODR\AdminBundle\Entity\TagMeta $tagMetum)
    {
        $this->tagMeta[] = $tagMetum;

        return $this;
    }

    /**
     * Remove tagMetum
     *
     * @param \ODR\AdminBundle\Entity\TagMeta $tagMetum
     */
    public function removeTagMetum(\ODR\AdminBundle\Entity\TagMeta $tagMetum)
    {
        $this->tagMeta->removeElement($tagMetum);
    }

    /**
     * Get tagMeta
     *
     * @return \ODR\AdminBundle\Entity\TagMeta
     */
    public function getTagMeta()
    {
        return $this->tagMeta->first();
    }

    /**
     * Add tagSelection
     *
     * @param \ODR\AdminBundle\Entity\TagSelection $tagSelection
     *
     * @return Tags
     */
    public function addTagSelection(\ODR\AdminBundle\Entity\TagSelection $tagSelection)
    {
        $this->tagSelections[] = $tagSelection;

        return $this;
    }

    /**
     * Remove tagSelection
     *
     * @param \ODR\AdminBundle\Entity\TagSelection $tagSelection
     */
    public function removeTagSelection(\ODR\AdminBundle\Entity\TagSelection $tagSelection)
    {
        $this->tagSelections->removeElement($tagSelection);
    }

    /**
     * Get tagSelections
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getTagSelections()
    {
        return $this->tagSelections;
    }

    /**
     * Set dataField
     *
     * @param \ODR\AdminBundle\Entity\DataFields $dataField
     *
     * @return Tags
     */
    public function setDataField(\ODR\AdminBundle\Entity\DataFields $dataField = null)
    {
        $this->dataField = $dataField;

        return $this;
    }

    /**
     * Get dataField
     *
     * @return \ODR\AdminBundle\Entity\DataFields
     */
    public function getDataField()
    {
        return $this->dataField;
    }

    /**
     * Set createdBy
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $createdBy
     *
     * @return Tags
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
     * Set deletedBy
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $deletedBy
     *
     * @return Tags
     */
    public function setDeletedBy(\ODR\OpenRepository\UserBundle\Entity\User $deletedBy = null)
    {
        $this->deletedBy = $deletedBy;

        return $this;
    }

    /**
     * Get deletedBy
     *
     * @return \ODR\OpenRepository\UserBundle\Entity\User
     */
    public function getDeletedBy()
    {
        return $this->deletedBy;
    }


    /**
     * Get xml_tagName
     *
     * @return string
     */
    public function getXmlOptionName()
    {
        return $this->getTagMeta()->getXmlTagName();
    }

    /**
     * Get displayOrder
     *
     * @return integer
     */
    public function getDisplayOrder()
    {
        return $this->getTagMeta()->getDisplayOrder();
    }
    /**
     * @var int
     */
    private $userCreated;


    /**
     * Set userCreated.
     *
     * @param int $userCreated
     *
     * @return Tags
     */
    public function setUserCreated($userCreated)
    {
        $this->userCreated = $userCreated;

        return $this;
    }

    /**
     * Get userCreated.
     *
     * @return int
     */
    public function getUserCreated()
    {
        return $this->userCreated;
    }
}
