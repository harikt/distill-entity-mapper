<?php

namespace Distill\EntityMapper;

class Criteria
{
    protected $entity = null;
    protected $embeddedRelations = [];
    protected $entityPredicates = [];
    protected $relationPredicates = [];
    protected $order = [];

    protected $numberPerPage = null;
    protected $page = 1;

    public function __construct($entity = null, array $embeddedRelations = [])
    {
        $this->entity = $entity;
        $this->embeddedRelations = $embeddedRelations;
    }

    public function setEntity($entity)
    {
        $this->entity = $entity;
    }

    public function setEmbeddedRelations(array $embeddedRelations)
    {
        $this->embeddedRelations = $embeddedRelations;
    }

    public function getRelations()
    {
        return array_unique(array_merge($this->embeddedRelations, array_keys($this->relationPredicates)));
    }

    public function addEntityPredicate($left, $op, $right)
    {
        $this->entityPredicates[] = [$left, $op, $right];
    }

    public function addRelationPredicate($relation, $left, $op, $right)
    {
        if (!isset($this->relationPredicates[$relation])) {
            $this->relationPredicates[$relation] = [];
        }
        $this->relationPredicates[$relation][] = [$left, $op, $right];
    }

    public function getEntity()
    {
        return $this->entity;
    }

    public function getEmbeddedRelations()
    {
        return $this->embeddedRelations;
    }

    public function hasEntityPredicates()
    {
        return (bool) $this->entityPredicates;
    }

    public function getEntityPredicates()
    {
        return $this->entityPredicates;
    }

    public function hasRelationPredicates($relation)
    {
        return isset($this->relationPredicates[$relation]);
    }

    /**
     * @param string $relation
     * @return array
     */
    public function getRelationPredicates($relation)
    {
        return $this->relationPredicates[$relation];
    }

    public function setNumberPerPage($numberPerPage)
    {
        $this->numberPerPage = (int) $numberPerPage;
    }

    public function getNumberPerPage()
    {
        return $this->numberPerPage;
    }

    public function setPage($page)
    {
        $this->page = (int) $page;
    }

    public function getPage()
    {
        return $this->page;
    }

    public function hasOrder()
    {
        return $this->order;
    }

    public function setOrder($order)
    {
        $this->order = (array) $order;
        return $this;
    }

    public function getOrder()
    {
        return $this->order;
    }
}
