<?php

namespace Distill\EntityMapper\Map;

class RelationMap
{
    public $name;
    /** @var EntityMap */
    public $entityMap;
    /** @var EntityMap */
    public $relationEntityMap;
    public $property;
    public $type;
    public $remoteIdColumn;
    public $localIdColumn;
    public $sqlAlias;

    protected $normalizedProperty;
    protected $reflections;

    public function __construct($name, EntityMap $entityMap, EntityMap $relationEntityMap, $property = null, $sqlAlias = null)
    {
        $this->name = $name;
        $this->entityMap = $entityMap;
        $this->relationEntityMap = $relationEntityMap;
        $this->normalizedProperty = strtolower(str_replace('_', '', $name));
        $this->sqlAlias = ($sqlAlias) ?: strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', ltrim(strrchr($name, '\\'), '\\')));
    }

    public function remoteIdColumn($remoteIdColumn)
    {
        if ($this->type === null) {
            $this->type = 'collection';
        }
        $this->remoteIdColumn = $remoteIdColumn;

        return $this;
    }

    public function localIdColumn($localIdColumn)
    {
        if ($this->type === null) {
            $this->type = 'entity';
        }
        $this->localIdColumn = $localIdColumn;
        return $this;
    }

    public function setRelationState($entity, $state)
    {
        $reflections = $this->entityMap->getReflections();
        if (isset($this->property)) {
            $ref = $reflections['properties'][$this->property];
        } elseif (isset($reflections['normalized_property_names'][$this->normalizedProperty])) {
            $propertyName = $reflections['normalized_property_names'][$this->normalizedProperty];
            $ref = $reflections['properties'][$propertyName];
        }
        $ref->setValue($entity, $state);
    }

    public function getRelationEntityIdentity($entity)
    {
        $reflections = $this->entityMap->getReflections();
        if (isset($this->property)) {
            $ref = $reflections['properties'][$this->property];
        } elseif (isset($reflections['normalized_property_names'][$this->normalizedProperty])) {
            $propertyName = $reflections['normalized_property_names'][$this->normalizedProperty];
            $ref = $reflections['properties'][$propertyName];
        }
        $relationEntity = $ref->getValue($entity);
        return $this->relationEntityMap->getEntityIdentity($relationEntity);
    }

}
