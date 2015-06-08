<?php

namespace Distill\EntityMapper\Collection;

class PaginatedCollection extends Collection
{
    protected $page = null;
    protected $numberPerPage = null;
    protected $total = null;

    public function getPage()
    {
        return $this->page;
    }

    public function setPage($page)
    {
        $this->page = $page;
    }

    public function getNumberPerPage()
    {
        return $this->numberPerPage;
    }

    public function setNumberPerPage($numberPerPage)
    {
        $this->numberPerPage = $numberPerPage;
    }

    public function setTotal($total)
    {
        $this->total = $total;
    }

    public function getTotal()
    {
        return $this->total;
    }
}

