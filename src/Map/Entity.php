<?php

namespace Distill\EntityMapper\Map;

use Distill\EntityMapper\Collection;

class Entity // implements \ArrayAccess
{
    public $entityClass;
    public $table;
    public $idColumn;
    public $columns = '*';
    /** @var Relation[] */
    public $relations = [];
    /** @var \ReflectionProperty[] */
    protected $reflections;

    public function __construct($entityClass, $table, $idColumn, $columns = '*')
    {
        $this->entityClass = $entityClass;
        $this->table = $table;

        $this->idColumn = $idColumn;
        if ($columns !== '*') {
            $this->columns($columns);
        }
    }

    public function setEntityClass($entityClass)
    {
        $this->entityClass = $entityClass;
    }

    public function columns($columns)
    {
        if ($this->columns = '*') {
            $this->columns = [];
        }
        if (is_string($columns) && func_num_args() > 1) {
            $columns = func_get_args();
        }
        if (is_array($columns)) {
            foreach ($columns as $column) {
                if (!is_string($column)) {
                    throw new \InvalidArgumentException('columns must be strings');
                }
                $this->columns[] = $column;
            }
        }
    }

    /**
     * @return Relation
     */
    public function relation($relatedEntityClass, $property, $sqlAlias = null)
    {
        $this->relations[$relatedEntityClass] = new Relation($relatedEntityClass, $property, $sqlAlias);
        return $this->relations[$relatedEntityClass];
    }

    public function getReflections()
    {
        if (!$this->reflections) {
            $refClass = new \ReflectionClass($this->entityClass);
            $properties = [];
            foreach ($refClass->getProperties() as $property) {
                $property->setAccessible(true);
                $properties[$property->getName()] = $property;
            }
            $this->reflections = ['class' => $refClass, 'properties' => $properties];
        }
        return $this->reflections;
    }

}
