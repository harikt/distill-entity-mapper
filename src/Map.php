<?php

namespace Distill\EntityMapper;

class Map implements \ArrayAccess
{
    /** one to one, one to many */
    const RELATION_TYPE_COLLECTION = 'collection';
    const RELATION_TYPE_ENTITY = 'entity';

    /** @var Map\EntityMap[] */
    public $entityMaps = [];

    /**
     * @param $entityClass
     * @param $table
     * @param $idColumn
     * @return Map\EntityMap
     */
    public function entityMap($name, $table, $idColumn, $entityClass = GenericEntity::class, $columns = [])
    {
        $this->entityMaps[$name] = new Map\EntityMap($name, $table, $idColumn, $entityClass, $columns);
        return $this->entityMaps[$name];
    }

    public function entityMapAlias($aliasName, $primaryName)
    {
        $entity = clone $this->entityMaps[$primaryName];
        $this->entityMaps[$aliasName] = $entity;
        $entity->setName($aliasName);
        return $entity;
    }

    /**
     * @param $name
     * @return Map\EntityMap
     */
    public function get($name)
    {
        if (!isset($this->entityMaps[$name])) {
            return false;
        }
        return $this->entityMaps[$name];
    }

    public function offsetExists($offset)
    {
        if (isset($this->entityMaps[$offset])) {
            return true;
        } else {
            foreach ($this->entityMaps as $entity) {
                if ($entity->entityClass === $offset) {
                    return true;
                }
            }
            return false;
        }
    }

    /**
     * @param mixed $offset
     * @return Map\EntityMap
     */
    public function offsetGet($offset)
    {
        if (isset($this->entityMaps[$offset])) {
            return $this->entityMaps[$offset];
        } else {
            foreach ($this->entityMaps as $entity) {
                if ($entity->entityClass === $offset) {
                    return $entity;
                }
            }
            return false;
        }
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
