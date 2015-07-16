<?php

namespace Distill\EntityMapper;

class Command
{
    protected $name;
    protected $entity;
    protected $context;

    public static function createFromArray(array $parameters)
    {
        $name = $entity = $context = null;
        foreach ($parameters as $n => $v) {
            switch ($n) {
                case 'name': $name = $v; break;
                case 'entity': $entity = $v; break;
                case 'context': $context = $v; break;
            }
        }
        return new self($name, $entity, $context);
    }

    public function __construct($name, $entity, $context = null)
    {
        $this->name = $name;
        $this->entity = $entity;
        $this->context = $context;
    }

    public function getName()
    {
        return $this->name;
    }

    public function getEntity()
    {
        return $this->entity;
    }

    public function getContext()
    {
        return $this->context;
    }
}
