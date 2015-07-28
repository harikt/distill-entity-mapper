<?php

namespace Distill\EntityMapper;

class Command
{
    protected $name;
    protected $parameters = [];

    public static function createFromArray(array $array)
    {
        if (!isset($array['name'])) {
            throw new \InvalidArgumentException('Command is missing name');
        }
        $name = $array['name'];
        unset($array['name']);
        return new self($name, $array);
    }

    public function __construct($name, array $parameters = [])
    {
        $this->name = $name;
        $this->parameters = $parameters;
    }

    public function getName()
    {
        return $this->name;
    }

    public function getParameters()
    {
        return $this->parameters;
    }
}
