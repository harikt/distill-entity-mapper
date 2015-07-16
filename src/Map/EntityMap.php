<?php

namespace Distill\EntityMapper\Map;

use Distill\EntityMapper\GenericEntity;
use Distill\EntityMapper\Map;

class EntityMap
{
    public $name;
    public $table;
    public $idColumn;
    public $entityClass;
    public $columns = [];
    /** @var RelationMap[] */
    public $relations = [];
    public $initializeClassWith = null;
    public $initializeWith = null;
    protected $map;
    protected $normalizedColumns = [];
    protected $isClassInitialized = false;
    protected $reflections;

    public function __construct($name, $table, $idColumn, $entityClass = GenericEntity::class, $columns = [])
    {
        $this->name = $name;
        $this->table = $table;

        // id column
        $this->idColumn = $idColumn;
        $this->entityClass = $entityClass;

        array_unshift($columns, $idColumn);
        $this->columns($columns);
    }

    public function setMap(Map $map)
    {
        $this->map = $map;
        return $this;
    }

    public function setEntityClass($entityClass)
    {
        $this->entityClass = $entityClass;
        return $this;
    }

    public function columns($columns)
    {
        if (is_string($columns) && func_num_args() > 1) {
            $columns = func_get_args();
        }
        if (!is_array($columns)) {
            throw new \InvalidArgumentException('$columns must be an array');
        }

        foreach ($columns as $column) {
            if (!is_string($column)) {
                throw new \InvalidArgumentException('columns must be strings');
            }
            $this->columns[] = $column;
            $this->normalizedColumns[$column] = strtolower(str_replace('_', '', $column));
        }
    }

    /**
     * @return RelationMap
     */
    public function relationMap($name, Map\EntityMap $relationEntityMap, $property = null, $sqlAlias = null)
    {
        $this->relations[$name] = new RelationMap($name, $this, $relationEntityMap, $property, $sqlAlias);
        return $this->relations[$name];
    }

    public function initializeClassWith()
    {
        $this->initializeClassWith = func_get_args();
    }

    public function initializeWith()
    {
        $this->initializeWith = func_get_args();
    }

    public function createEntity()
    {
        $c = $this->entityClass;
        if (method_exists($c, 'createEntity')) {
            return $c::createEntity();
        }
        /** @var \ReflectionClass $ref */
        $ref = $this->getReflections()['class'];

        if (!$this->isClassInitialized) {
            $mRef = ($ref->hasMethod('initializeEntityClass')) ? $ref->getMethod('initializeEntityClass') : null;
            if ($mRef && $mRef->isStatic()) {
                $mRef->invokeArgs(null, $this->initializeClassWith);
            }
            $this->isClassInitialized = true;
        }

        return $ref->newInstanceWithoutConstructor();
    }

    public function setEntityIdentity($entity, $identity)
    {
        $entity->{$this->idColumn} = $identity;
    }

    public function setEntityState($entity, array $state)
    {
        if (method_exists($entity, 'setEntityState')) {
            $entity->setEntityState($state);
            goto INITIALIZE;
        }

        $reflections = $this->getReflections();
        if (!$reflections) {
            $refClass = new \ReflectionClass(get_called_class());
            foreach ($refClass->getProperties() as $prop) {
                $prop->setAccessible(true);
                $reflections[strtolower(str_replace('_', '', $prop->getName()))] = $prop;
            }
        }

        foreach ($state as $name => $value) {
            $normalName = (isset($this->normalizedColumns[$name])) ? $this->normalizedColumns[$name] : $name;
            if (isset($reflections['normalized_property_names'][$normalName], $reflections['properties'][$reflections['normalized_property_names'][$normalName]])) {
                $reflections['properties'][$reflections['normalized_property_names'][$normalName]]->setValue($entity, $value);
            }
        }

        INITIALIZE:
        if (method_exists($entity, 'initializeEntityState')) {
            switch (count($this->initializeWith)) {
                case 0: $entity->initializeEntityState(); break;
                case 1: $entity->initializeEntityState($this->initializeWith[0]); break;
                case 2: $entity->initializeEntityState($this->initializeWith[0], $this->initializeWith[1]); break;
                default: call_user_func_array([$this, 'initializeEntityState'], $this->initializeWith);
            }
        }
    }

    public function getEntityIdentity($entity)
    {
        $reflections = $this->getReflections();
        return $reflections['properties'][$this->idColumn]->getValue($entity); // @todo refactor this
    }

    public function getEntityColumnData($entity)
    {
        if (method_exists($entity, 'getEntityColumnData')) {
            return $entity->getEntityColumnData();
        }
        $reflections = $this->getReflections();
        $data = [];
        foreach ($this->normalizedColumns as $column => $normalName) {
            if (isset($reflections['normalized_property_names'][$normalName], $reflections['properties'][$reflections['normalized_property_names'][$normalName]])) {
                $data[$column] = $reflections['properties'][$reflections['normalized_property_names'][$normalName]]->getValue($entity);
            }
        }
        return $data;
    }

    public function getReflections()
    {
        if (!$this->reflections) {
            $refClass = new \ReflectionClass($this->entityClass);
            $properties = [];
            $nameMap = [];
            foreach ($refClass->getProperties() as $property) {
                $property->setAccessible(true);
                $properties[$property->getName()] = $property;
                $nameMap[strtolower(str_replace('_', '', $property->getName()))] = $property->getName();
            }
            $this->reflections = ['class' => $refClass, 'properties' => $properties, 'normalized_property_names' => $nameMap];
        }
        return $this->reflections;
    }

}
