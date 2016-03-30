<?php

/**
 * Open Data Repository Data Publisher
 * RadioSelection Entity
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The RadioSelection Entity is automatically generated from
 * ./Resources/config/doctrine/RadioSelection.orm.yml
 */

namespace ODR\AdminBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * RadioSelection
 */
class RadioSelection
{
    /**
     * @var integer
     */
    private $id;

    /**
     * @var integer
     */
    private $selected;

    /**
     * @var \DateTime
     */
    private $deletedAt;

    /**
     * @var \DateTime
     */
    private $created;

    /**
     * @var \ODR\AdminBundle\Entity\RadioOptions
     */
    private $radioOption;

    /**
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     */
    private $createdBy;

    /**
     * @var \ODR\AdminBundle\Entity\DataRecordFields
     */
    private $dataRecordFields;


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
     * Set selected
     *
     * @param integer $selected
     * @return RadioSelection
     */
    public function setSelected($selected)
    {
        $this->selected = $selected;

        return $this;
    }

    /**
     * Get selected
     *
     * @return integer 
     */
    public function getSelected()
    {
        return $this->selected;
    }

    /**
     * Set deletedAt
     *
     * @param \DateTime $deletedAt
     * @return RadioSelection
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
     * @return RadioSelection
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
     * Set radioOption
     *
     * @param \ODR\AdminBundle\Entity\RadioOptions $radioOption
     * @return RadioSelection
     */
    public function setRadioOption(\ODR\AdminBundle\Entity\RadioOptions $radioOption = null)
    {
        $this->radioOption = $radioOption;

        return $this;
    }

    /**
     * Get radioOption
     *
     * @return \ODR\AdminBundle\Entity\RadioOptions 
     */
    public function getRadioOption()
    {
        return $this->radioOption;
    }

    /**
     * Set createdBy
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $createdBy
     * @return RadioSelection
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
     * Set dataRecordFields
     *
     * @param \ODR\AdminBundle\Entity\DataRecordFields $dataRecordFields
     * @return RadioSelection
     */
    public function setDataRecordFields(\ODR\AdminBundle\Entity\DataRecordFields $dataRecordFields = null)
    {
        $this->dataRecordFields = $dataRecordFields;

        return $this;
    }

    /**
     * Get dataRecordFields
     *
     * @return \ODR\AdminBundle\Entity\DataRecordFields 
     */
    public function getDataRecordFields()
    {
        return $this->dataRecordFields;
    }
}
