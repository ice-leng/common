<?php

declare(strict_types=1);

namespace Lengbin\Common\Component;

use Lengbin\Helper\Util\FormatHelper;
use MabeEnum\Enum;
use phpDocumentor\Reflection\Types\Array_;
use phpDocumentor\Reflection\Types\Object_;
use Roave\BetterReflection\BetterReflection;
use Roave\BetterReflection\Reflection\ReflectionClass;

class BaseObject
{
    /**
     * ObjectHelper constructor.
     *
     * @param array $config
     */
    public function __construct(array $config = [])
    {
        if (!empty($config)) {
            $this->configure($this, $config);
        }
        $this->init();
    }

    /**
     * Initializes the object.
     * This method is invoked at the end of the constructor after the object is initialized with the
     * given configuration.
     */
    public function init()
    {
    }

    /**
     * 创建对象
     *
     * @param string $className
     * @param mixed  $value
     *
     * @return object
     */
    private function createObject(string $className, $value): object
    {
        if (method_exists($className, 'byValue')) {
            return $className::byValue($value);
        }

        $class = new $className;
        if ($class instanceof BaseObject) {
            $class->configure($class, $value);
        }
        return $class;
    }

    /**
     * 属性 注解
     *
     * @param array $docBlockTypes
     * @param mixed $value
     *
     * @return mixed
     */
    private function fromDocBlock($value, array $docBlockTypes = [])
    {
        foreach ($docBlockTypes as $docBlockType) {
            if ($docBlockType instanceof Array_) {
                $valueType = $docBlockType->getValueType();
                if ($valueType instanceof Object_) {
                    foreach ($value as $k => $v) {
                        $value[$k] = $this->createObject($valueType->__toString(), $v);
                    }
                }
            }
            if ($docBlockType instanceof Object_) {
                $value = $this->createObject($docBlockType->getFqsen()->__toString(), $value);
            }
        }
        return $value;
    }

    /**
     * method
     *
     * @param ReflectionClass $classInfo
     * @param string          $fun
     * @param mixed           $value
     *
     * @return mixed
     */
    private function fromMethod(ReflectionClass $classInfo, string $fun, $value)
    {
        $method = $classInfo->getMethod($fun);
        $parameters = $method->getParameters();
        if (count($parameters) === 1) {
            $value = [$value];
        }
        foreach ($parameters as $key => $parameter) {
            $value[$key] = $this->fromDocBlock($value[$key], $parameter->getDocBlockTypes());
        }
        return $value;
    }

    /**
     * @param $object
     * @param $properties
     *
     * @return mixed
     */
    public function configure($object, $properties)
    {
        $className = get_class($object);
        $classInfo = (new BetterReflection())->classReflector()->reflect($className);
        foreach ($properties as $name => $value) {
            $fun = 'set' . ucfirst(FormatHelper::camelize($name));
            $property = $classInfo->getProperty($name);
            if (property_exists($this, $name)) {
                if (is_null($property)) {
                    $object->$name = $this->fromMethod($classInfo, $fun, $value);
                } else {
                    $object->$name = $this->fromDocBlock($value, $property->getDocBlockTypes());
                }
            } elseif ($classInfo->hasMethod($fun)) {
                if (is_null($property)) {
                    $object->$fun(...$this->fromMethod($classInfo, $fun, $value));
                } else {
                    $object->$fun($this->fromDocBlock($value, $property->getDocBlockTypes()));
                }
            } else {
                $object->$name = $value;
            }
        }
        return $object;
    }

    /**
     * getter
     *
     * @param $name
     *
     * @return mixed
     * @throws \RuntimeException
     */
    public function __get($name)
    {
        $getter = 'get' . $name;
        if (method_exists($this, $getter)) {
            return $this->$getter();
        } elseif (property_exists($this, $name)) {
            return $this->{$name};
        } elseif (method_exists($this, 'set' . $name)) {
            throw new \RuntimeException('Getting write-only property: ' . get_class($this) . '::' . $name);
        }
        throw new \RuntimeException('Getting unknown property: ' . get_class($this) . '::' . $name);
    }

    /**
     * setter
     *
     * @param $name
     * @param $value
     *
     * @throws \RuntimeException
     */
    public function __set($name, $value)
    {
        $setter = 'set' . $name;
        if (method_exists($this, $setter)) {
            $this->$setter($value);
        } elseif (property_exists($this, $name)) {
            $this->{$name} = $value;
        } elseif (method_exists($this, 'get' . $name)) {
            throw new \RuntimeException('Setting read-only property: ' . get_class($this) . '::' . $name);
        }
    }

    /**
     * object  public method toArray
     *
     * @param object|null $object
     *
     * @return array
     */
    public function toArray(?object $object = null): array
    {
        $data = [];
        $object = is_null($object) ? $this : $object;
        $classInfo = (new BetterReflection())->classReflector()->reflect(get_class($object));
        $properties = $classInfo->getProperties();
        foreach ($properties as $property) {
            $name = $property->getName();
            if (!$property->isPublic()) {
                $getter = 'get' . ucfirst(FormatHelper::camelize($name));
                if (!method_exists($object, $getter)) {
                    continue;
                }
                $value = $object->$getter();
            } else {
                $value = $object->$name;
            }
            if (is_array($value)) {
                $result = [];
                foreach ($value as $key => $item) {
                    if (method_exists($item, 'toArray')) {
                        $result[$key] = $item->toArray();
                    } elseif ($item instanceof Enum) {
                        $result[$key] = $item->getValue();
                    } elseif (is_object($item)) {
                        $result[$key] = $this->toArray($item);
                    }else {
                        $result[$key] = $item;
                    }
                }
                $data[$name] = $result;
            } elseif (is_object($value)) {
                if (method_exists($value, 'toArray')) {
                    $data[$name] = $value->toArray();
                } elseif ($value instanceof Enum) {
                    $data[$name] = $value->getValue();
                } else {
                    $data[$name] = $this->toArray($value);
                }
            } else {
                $data[$name] = $value;
            }
        }
        return $data;
    }
}
