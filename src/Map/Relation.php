<?php


namespace Distill\EntityMapper\Map;

class Relation // implements \ArrayAccess
{
    public $entityClass;
    public $property;
    public $type;
    public $remoteIdColumn;
    public $localIdColumn;

    public function __construct($entityClass, $property, $sqlAlias = null)
    {
        $this->entityClass = $entityClass;
        $this->property = $property;
        $this->sqlAlias = ($sqlAlias) ?: strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', ltrim(strrchr($entityClass, '\\'), '\\')));
    }

    public function collection()
    {
        $this->type = 'collection';
        return $this;
    }

    public function entity()
    {
        $this->type = 'entity';
        return $this;
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

    public function pivotColumns()
    {
        // @todo
    }

}
