<?php

namespace Distill\EntityMapper\Collection;

use Distill\EntityMapper\Criteria;

class Collection implements \IteratorAggregate, \Countable
{
    protected $criteria = null;
    protected $entities = [];

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
}

