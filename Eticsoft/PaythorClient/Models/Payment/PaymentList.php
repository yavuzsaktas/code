<?php

namespace Eticsoft\PaythorClient\Models\Payment;

use Eticsoft\PaythorClient\Models\Payment\Common\Pagination;
use Eticsoft\PaythorClient\Models\Payment\Common\Search;

class PaymentList
{
    private array $pagination;
    private array $search;

    /**
     * Constructor
     *
     * @param Pagination $pagination Pagination parameters
     * @param Search $search Optional search parameters
     */
    public function __construct(Pagination $pagination, Search $search)
    {
        $this->pagination = $pagination->toArray();
        $this->search = $search->toArray();
    }

    public function setPagination(Pagination $pagination): self
    {
        $this->pagination = $pagination->toArray();
        return $this;
    }

    public function setSearch(Search $search): self
    {
        $this->search = $search->toArray();
        return $this;
    }

    public function toArray(): array
    {
        return [
            'pagination' => $this->pagination,
            'search' => $this->search
        ];
    }
}