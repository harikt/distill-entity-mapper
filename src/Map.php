<?php

namespace Distill\EntityMapper;

class Map implements \ArrayAccess
{
    /** one to one, one to many */
    const RELATION_TYPE_COLLECTION = 'collection';
    const RELATION_TYPE_ENTITY = 'entity';

    /** @var Map\Entity[] */
    protected $entityMaps = [];

    /**
     * @param $entityClass
     * @param $table
     * @param $idColumn
     * @return Map\Entity
     */
    public function entity($entityClass, $table, $idColumn, $properties = '*')
    {
        $this->entityMaps[$entityClass] = new Map\Entity($entityClass, $table, $idColumn);
        return $this->entityMaps[$entityClass];
    }

    public function entityAlias($aliasEntityClass, $primaryEntityClass)
    {
        $entity = clone $this->entityMaps[$primaryEntityClass];
        $this->entityMaps[$aliasEntityClass] = $entity;
        $entity->setEntityClass($aliasEntityClass);
        return $entity;
    }

    /**
     * @param $entityClass
     * @return Map\Entity
     */
    public function get($entityClass)
    {
        if (!isset($this->entityMaps[$entityClass])) {
            return false;
        }
        return $this->entityMaps[$entityClass];
    }

    public function offsetExists($offset)
    {
        return array_key_exists($offset, $this->entityMaps);
    }

    /**
     * @param mixed $offset
     * @return Map\Entity
     */
    public function offsetGet($offset)
    {
        return $this->entityMaps[$offset];
    }

    public function offsetSet($offset, $value)
    {
        throw new \BadMethodCallException('offsetSet is not supported');
    }

    public function offsetUnset($offset)
    {
        throw new \BadMethodCallException('offsetUnset is not supported');
    }
}
