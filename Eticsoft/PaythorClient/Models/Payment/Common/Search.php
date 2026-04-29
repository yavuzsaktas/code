<?php

namespace Eticsoft\PaythorClient\Models\Payment\Common;

class Search
{
    /**
     * @var array Search conditions
     */
    private array $conditions = [];

    /**
     * Constructor
     *
     * @param array $conditions Initial search conditions
     */
    public function __construct(array $conditions = [])
    {
        $this->conditions = $conditions;
    }

    /**
     * Set search conditions
     *
     * @param array $conditions Search conditions
     * @return self
     */
    public function setConditions(array $conditions): self
    {
        $this->conditions = $conditions;
        return $this;
    }

    /**
     * Add a search condition
     *
     * @param string $where Field to search in
     * @param string $operator Comparison operator
     * @param mixed $what Value to compare with
     * @return self
     */
    public function addCondition(string $where, string $operator, $what): self
    {
        $this->conditions[] = [
            'where' => $where,
            'operator' => $operator,
            'what' => $what
        ];
        return $this;
    }

    /**
     * Convert the object to an array
     *
     * @return array
     */
    public function toArray(): array
    {
        return $this->conditions ? $this->conditions : [];
    }
}