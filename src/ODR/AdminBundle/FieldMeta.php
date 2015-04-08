<?php 


class FieldMeta {

    private choice_type = "";

    /**
     * Set ChoiceType
     *
     * @param string $choice_type
     * @return DataFields
     */
    public function setChoiceType($choice_type)
    {
        $this->choice_type = $choice_type;

        return $this;
    }

    /**
     * Get fieldName
     *
     * @return string 
     */
    public function getChoiceType()
    {
        return $this->choice_type;
    }
    
}

?>
