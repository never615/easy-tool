<?php
/**
 * Copyright (c) 2019. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */
namespace Mallto\Tool\Data\Traits;


use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use ReflectionClass;

/**
 * User: never615 <never615.com>
 * Date: 2019-11-20
 * Time: 16:03
 */
trait HasParentModel{
    public function getTable()
    {
        if (! isset($this->table)) {
            return str_replace('\\', '', Str::snake(Str::plural(class_basename($this->getParentClass()))));
        }
        return $this->table;
    }
    public function getForeignKey()
    {
        return Str::snake(class_basename($this->getParentClass())).'_'.$this->primaryKey;
    }
    public function joiningTable($related,$instance = null)
    {
        $models = [
            Str::snake(class_basename($related)),
            Str::snake(class_basename($this->getParentClass())),
        ];
        sort($models);
        return strtolower(implode('_', $models));
    }
    protected function getParentClass()
    {
        return (new ReflectionClass($this))->getParentClass()->getName();
    }

    public function getMorphClass()
    {
        $class= $this->getParentClass();


        if ($this->morphClass !== null) {
            return $this->morphClass;
        }
        $morphMap = Relation::morphMap();

        if (! empty($morphMap) && in_array($class, $morphMap)) {
            return array_search($class, $morphMap, true);
        }

        return $class;
    }

}