<?php

namespace Distill\EntityMapper\Map;

use Distill\EntityMapper\Collection;

class Entity // implements \ArrayAccess
{
    public $entityClass;
    public $collectionClass = Collection::class;
    public $table;
    public $idColumn;
    public $properties = [];
    public $columns = [];
    public $transformers = [];
    /** @var Relation[] */
    public $relations = [];
    /** @var \ReflectionProperty[] */
    protected $reflections;

    public function __construct($entityClass, $table, $idColumn, $collectionClass = Collection::class)
    {
        $this->entityClass = $entityClass;
        $this->table = $table;

        $this->idColumn = $idColumn;
        $this->properties($idColumn);

        $this->collectionClass = $collectionClass;
    }

    public function setEntityClass($entityClass)
    {
        $this->entityClass = $entityClass;
    }

    public function properties(...$properties)
    {
        $index = count($this->properties);
        foreach ($properties as $property) {
            $columnName = $propertyName = $property;
            if (is_array($property)) {
                $propertyName = key($property);
                $columnName = $property[$propertyName];
            }
            $this->properties[$index] = $propertyName;
            $this->columns[$index] = $columnName;
            $index++;
        }
    }

    public function transform($property, $transformer)
    {
        if (!isset($this->transformers[$property])) {
            $this->transformers[$property] = [];
        }
        $this->transformers[$property][] = $transformer;
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
