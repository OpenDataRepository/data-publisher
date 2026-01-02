<?php

namespace ODR\AdminBundle\Entity;

/**
 * StatisticsHourly
 */
class StatisticsHourly
{
    /**
     * @var int
     */
    private $id;

    /**
     * @var \DateTime
     */
    private $hourTimestamp;

    /**
     * @var int
     */
    private $viewCount;

    /**
     * @var int
     */
    private $downloadCount;

    /**
     * @var int
     */
    private $searchResultViewCount;

    /**
     * @var string|null
     */
    private $country;

    /**
     * @var string|null
     */
    private $province;

    /**
     * @var bool
     */
    private $isBot;

    /**
     * @var \DateTime
     */
    private $created;

    /**
     * @var \DateTime
     */
    private $updated;

    /**
     * @var \DateTime|null
     */
    private $deletedAt;

    /**
     * @var \ODR\AdminBundle\Entity\DataType
     */
    private $dataType;

    /**
     * @var \ODR\AdminBundle\Entity\DataRecord
     */
    private $dataRecord;

    /**
     * @var \ODR\AdminBundle\Entity\File
     */
    private $file;

    /**
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     */
    private $createdBy;

    /**
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     */
    private $updatedBy;


    /**
     * Get id.
     *
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set hourTimestamp.
     *
     * @param \DateTime $hourTimestamp
     *
     * @return StatisticsHourly
     */
    public function setHourTimestamp($hourTimestamp)
    {
        $this->hourTimestamp = $hourTimestamp;

        return $this;
    }

    /**
     * Get hourTimestamp.
     *
     * @return \DateTime
     */
    public function getHourTimestamp()
    {
        return $this->hourTimestamp;
    }

    /**
     * Set viewCount.
     *
     * @param int $viewCount
     *
     * @return StatisticsHourly
     */
    public function setViewCount($viewCount)
    {
        $this->viewCount = $viewCount;

        return $this;
    }

    /**
     * Get viewCount.
     *
     * @return int
     */
    public function getViewCount()
    {
        return $this->viewCount;
    }

    /**
     * Set downloadCount.
     *
     * @param int $downloadCount
     *
     * @return StatisticsHourly
     */
    public function setDownloadCount($downloadCount)
    {
        $this->downloadCount = $downloadCount;

        return $this;
    }

    /**
     * Get downloadCount.
     *
     * @return int
     */
    public function getDownloadCount()
    {
        return $this->downloadCount;
    }

    /**
     * Set searchResultViewCount.
     *
     * @param int $searchResultViewCount
     *
     * @return StatisticsHourly
     */
    public function setSearchResultViewCount($searchResultViewCount)
    {
        $this->searchResultViewCount = $searchResultViewCount;

        return $this;
    }

    /**
     * Get searchResultViewCount.
     *
     * @return int
     */
    public function getSearchResultViewCount()
    {
        return $this->searchResultViewCount;
    }

    /**
     * Set country.
     *
     * @param string|null $country
     *
     * @return StatisticsHourly
     */
    public function setCountry($country = null)
    {
        $this->country = $country;

        return $this;
    }

    /**
     * Get country.
     *
     * @return string|null
     */
    public function getCountry()
    {
        return $this->country;
    }

    /**
     * Set province.
     *
     * @param string|null $province
     *
     * @return StatisticsHourly
     */
    public function setProvince($province = null)
    {
        $this->province = $province;

        return $this;
    }

    /**
     * Get province.
     *
     * @return string|null
     */
    public function getProvince()
    {
        return $this->province;
    }

    /**
     * Set isBot.
     *
     * @param bool $isBot
     *
     * @return StatisticsHourly
     */
    public function setIsBot($isBot)
    {
        $this->isBot = $isBot;

        return $this;
    }

    /**
     * Get isBot.
     *
     * @return bool
     */
    public function getIsBot()
    {
        return $this->isBot;
    }

    /**
     * Set created.
     *
     * @param \DateTime $created
     *
     * @return StatisticsHourly
     */
    public function setCreated($created)
    {
        $this->created = $created;

        return $this;
    }

    /**
     * Get created.
     *
     * @return \DateTime
     */
    public function getCreated()
    {
        return $this->created;
    }

    /**
     * Set updated.
     *
     * @param \DateTime $updated
     *
     * @return StatisticsHourly
     */
    public function setUpdated($updated)
    {
        $this->updated = $updated;

        return $this;
    }

    /**
     * Get updated.
     *
     * @return \DateTime
     */
    public function getUpdated()
    {
        return $this->updated;
    }

    /**
     * Set deletedAt.
     *
     * @param \DateTime|null $deletedAt
     *
     * @return StatisticsHourly
     */
    public function setDeletedAt($deletedAt = null)
    {
        $this->deletedAt = $deletedAt;

        return $this;
    }

    /**
     * Get deletedAt.
     *
     * @return \DateTime|null
     */
    public function getDeletedAt()
    {
        return $this->deletedAt;
    }

    /**
     * Set dataType.
     *
     * @param \ODR\AdminBundle\Entity\DataType|null $dataType
     *
     * @return StatisticsHourly
     */
    public function setDataType(\ODR\AdminBundle\Entity\DataType $dataType = null)
    {
        $this->dataType = $dataType;

        return $this;
    }

    /**
     * Get dataType.
     *
     * @return \ODR\AdminBundle\Entity\DataType|null
     */
    public function getDataType()
    {
        return $this->dataType;
    }

    /**
     * Set dataRecord.
     *
     * @param \ODR\AdminBundle\Entity\DataRecord|null $dataRecord
     *
     * @return StatisticsHourly
     */
    public function setDataRecord(\ODR\AdminBundle\Entity\DataRecord $dataRecord = null)
    {
        $this->dataRecord = $dataRecord;

        return $this;
    }

    /**
     * Get dataRecord.
     *
     * @return \ODR\AdminBundle\Entity\DataRecord|null
     */
    public function getDataRecord()
    {
        return $this->dataRecord;
    }

    /**
     * Set file.
     *
     * @param \ODR\AdminBundle\Entity\File|null $file
     *
     * @return StatisticsHourly
     */
    public function setFile(\ODR\AdminBundle\Entity\File $file = null)
    {
        $this->file = $file;

        return $this;
    }

    /**
     * Get file.
     *
     * @return \ODR\AdminBundle\Entity\File|null
     */
    public function getFile()
    {
        return $this->file;
    }

    /**
     * Set createdBy.
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User|null $createdBy
     *
     * @return StatisticsHourly
     */
    public function setCreatedBy(\ODR\OpenRepository\UserBundle\Entity\User $createdBy = null)
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    /**
     * Get createdBy.
     *
     * @return \ODR\OpenRepository\UserBundle\Entity\User|null
     */
    public function getCreatedBy()
    {
        return $this->createdBy;
    }

    /**
     * Set updatedBy.
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User|null $updatedBy
     *
     * @return StatisticsHourly
     */
    public function setUpdatedBy(\ODR\OpenRepository\UserBundle\Entity\User $updatedBy = null)
    {
        $this->updatedBy = $updatedBy;

        return $this;
    }

    /**
     * Get updatedBy.
     *
     * @return \ODR\OpenRepository\UserBundle\Entity\User|null
     */
    public function getUpdatedBy()
    {
        return $this->updatedBy;
    }
}
