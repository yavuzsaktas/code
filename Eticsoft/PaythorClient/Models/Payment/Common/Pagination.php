<?php

namespace Eticsoft\PaythorClient\Models\Payment\Common;

class Pagination
{
    private int $page = 1;
    private int $itemsPerPage = 25;
    private string $orderBy = 'date_create';
    private string $orderType = 'DESC';

    /**
     * @param int $page Current page number
     * @param int $itemsPerPage Number of items per page
     * @param string $orderBy Field to order by
     * @param string $orderType Order direction (ASC or DESC)
     */
    public function __construct(
        int $page = 1,
        int $itemsPerPage = 25,
        string $orderBy = 'date_create',
        string $orderType = 'DESC'
    ) {
        $this->page = $page;
        $this->itemsPerPage = $itemsPerPage;
        $this->orderBy = $orderBy;
        $this->orderType = $orderType;
    }

    /**
     * Set the current page number
     * 
     * @param int $page Current page number
     * @return self
     */
    public function setPage(int $page): self
    {
        $this->page = $page;
        return $this;
    }

    /**
     * Set the number of items per page
     * 
     * @param int $itemsPerPage Number of items per page
     * @return self
     */
    public function setItemsPerPage(int $itemsPerPage): self
    {
        $this->itemsPerPage = $itemsPerPage;
        return $this;
    }

    /**
     * Set the field to order by
     * 
     * @param string $orderBy Field to order by
     * @return self
     */
    public function setOrderBy(string $orderBy): self
    {
        $this->orderBy = $orderBy;
        return $this;
    }

    /**
     * Set the order direction
     * 
     * @param string $orderType Order direction (ASC or DESC)
     * @return self
     */
    public function setOrderType(string $orderType): self
    {
        $this->orderType = $orderType;
        return $this;
    }

    /**
     * Convert pagination to array
     * 
     * @return array
     */
    public function toArray(): array
    {
        return [
            'page' => $this->page,
            'items_per_page' => $this->itemsPerPage,
            'order_by' => $this->orderBy,
            'order_type' => $this->orderType
        ];
    }
}