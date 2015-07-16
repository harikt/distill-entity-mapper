<?php

namespace Distill\EntityMapper;

class Collection implements \IteratorAggregate, \Countable
{
    protected $criteria = null;
    protected $entities = [];
    protected $offset = null;
    protected $limit = null;
    protected $total = null;

    public function setCriteria(Criteria $criteria)
    {
        $this->criteria = $criteria;
    }

    public function getCriteria()
    {
        return $this->criteria;
    }

    public function append($entity)
    {
        $this->entities[] = $entity;
    }

    public function getIterator()
    {
        return new \ArrayIterator($this->entities);
    }

    public function count()
    {
        return count($this->entities);
    }

    public function getOffset()
    {
        return $this->offset;
    }

    public function setOffset($offset)
    {
        $this->offset = $offset;
    }

    public function getLimit()
    {
        return $this->limit;
    }

    public function setLimit($limit)
    {
        $this->limit = $limit;
    }

    public function setTotal($total)
    {
        $this->total = $total;
    }

    public function getTotal()
    {
        return $this->total;
    }

    public function toArray()
    {
        return $this->entities;
    }

}
