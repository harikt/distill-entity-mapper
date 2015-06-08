<?php

namespace Distill\EntityMapper\Data;

trait SetDataSourceToPropertyTrait
{
    public static function setEntityState($entity, array $dataSourceData)
    {
        /** @var $reflections \ReflectionProperty[] */
        static $reflections = [];
        if (!$reflections) {
            $refClass = new \ReflectionClass(get_called_class());
            foreach ($refClass->getProperties() as $prop) {
                $prop->setAccessible(true);
                $reflections[strtolower(str_replace('_', '', $prop->getName()))] = $prop;
            }
        }
        foreach ($dataSourceData as $name => $value) {
            if (isset($reflections[strtolower(str_replace('_', '', $name))])) {
                $reflections[strtolower(str_replace('_', '', $name))]->setValue($entity, $value);
            }
        }
        if (method_exists($entity, 'initializeEntityState')) {
            $entity->initializeEntityState();
        }
    }
}