<?php

namespace Eticsoft\PaythorClient\Models\User;

class UserList
{
    /**
     * Pagination parameters
     *
     * @var array
     */
    protected $pagination = [
        'page' => 1,
        'items_per_page' => 20,
        'order_by' => 'id',
        'order_way' => 'ASC'
    ];

    /**
     * Search criteria
     *
     * @var array
     */
    protected $search = [];

    /**
     * Set page number
     *
     * @param int $page
     * @return self
     */
    public function setPage(int $page): self
    {
        $this->pagination['page'] = $page;
        return $this;
    }

    /**
     * Set items per page
     *
     * @param int $itemsPerPage
     * @return self
     */
    public function setItemsPerPage(int $itemsPerPage): self
    {
        $this->pagination['items_per_page'] = $itemsPerPage;
        return $this;
    }

    /**
     * Set order by field
     *
     * @param string $orderBy
     * @return self
     */
    public function setOrderBy(string $orderBy): self
    {
        $this->pagination['order_by'] = $orderBy;
        return $this;
    }

    /**
     * Set order direction (ASC or DESC)
     *
     * @param string $orderWay
     * @return self
     */
    public function setOrderWay(string $orderWay): self
    {
        $this->pagination['order_way'] = $orderWay;
        return $this;
    }

    /**
     * Add search criteria
     *
     * @param string $field Field to search in
     * @param string $operator Comparison operator (contains, equals, etc.)
     * @param string $value Value to search for
     * @return self
     */
    public function addSearchCriteria(string $field, string $operator, string $value): self
    {
        $this->search[] = [
            'where' => $field,
            'operator' => $operator,
            'what' => $value
        ];
        return $this;
    }

    /**
     * Convert the model to an array for API requests
     *
     * @return array
     */
    public function toArray(): array
    {
        $result = [
            'pagination' => $this->pagination
        ];

        if (!empty($this->search)) {
            $result['search'] = $this->search;
        }

        return $result;
    }
}