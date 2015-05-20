<?php

namespace Distill\EntityMapper;

class Collection implements \IteratorAggregate
{
    protected $page = null;
    protected $numberPerPage = null;
    protected $total = null;
    protected $entities;

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

    public function append($entity)
    {
        $this->entities[] = $entity;
    }

    public function getIterator()
    {
        return new \ArrayIterator($this->entities);
    }
}

