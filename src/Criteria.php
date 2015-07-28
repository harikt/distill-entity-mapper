<?php

namespace Distill\EntityMapper;

class Criteria
{
    protected $entity = null;
    protected $embeddedRelations = [];
    protected $entityPredicates = [];
    protected $relationPredicates = [];
    protected $order = [];

    protected $limit = null;
    protected $offset = 1;

    public static function createFromArray(array $parameters)
    {
        $criteria = new static;
        foreach ($parameters as $n => $v) {
            switch (strtolower(str_replace('_', '', $n))) {
                case 'entity':
                    $criteria->setEntity($v);
                    break;
                case 'embedded':
                case 'embeddedrelations':
                    $criteria->setEmbeddedRelations($v);
                    break;
                case 'predicates':
                case 'entitypredicates':
                    foreach ($v as $v2) {
                        $criteria->addEntityPredicate($v2[0], $v2[1], $v2[2]);
                    }
                    break;
                case 'relationpredicates':
                    throw new \RuntimeException('incomplete implementation');
                    foreach ($v as $v2) {
                        //$criteria->addRelationPredicate($v2[0], $v2[1], $v2[2]);
                    }
                    break;
                case 'limit':
                    $criteria->setLimit($v);
                    break;
            }
        }
        return $criteria;
    }

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
    public function getRelationPredicates($relation = null)
    {

        return ($relation) ? $this->relationPredicates[$relation] : $this->relationPredicates;
    }

    public function setLimit($limit)
    {
        $this->limit = (int) $limit;
    }

    public function getLimit()
    {
        return $this->limit;
    }

    public function setOffset($offset)
    {
        $this->offset = (int) $offset;
    }

    public function getOffset()
    {
        return $this->offset;
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
