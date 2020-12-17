<?php

declare(strict_types=1);

namespace Lengbin\Common\Component;

use Lengbin\Helper\Util\FormatHelper;
use MabeEnum\Enum;
use phpDocumentor\Reflection\Types\Array_;
use phpDocumentor\Reflection\Types\Object_;
use Roave\BetterReflection\BetterReflection;
use Roave\BetterReflection\Reflection\ReflectionClass;
use Throwable;

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
     * @return array
     */
    public function toArray(): array
    {
        $data = [];
        $classInfo = (new BetterReflection())->classReflector()->reflect(get_class($this));
        $properties = $classInfo->getProperties();
        foreach ($properties as $property) {
            try {
                $name = $property->getName();
                if (!$property->isPublic()) {
                    // 如果 非公共属性， 没有 getter 方法 直接排除
                    $getter = 'get' . ucfirst(FormatHelper::camelize($name));
                    if (!method_exists($this, $getter)) {
                        continue;
                    }
                    $value = $this->$getter();
                } else {
                    $value = $this->$name;
                }

                // 如果是 null 直接排除
                if (is_null($value)) {
                    continue;
                }

                if (is_array($value)) {
                    $result = [];
                    foreach ($value as $key => $item) {
                        if (is_object($item)) {
                            if ($item instanceof Enum) {
                                $result[$key] = $item->getValue();
                            } elseif (method_exists($item, 'toArray')) {
                                $result[$key] = $item->toArray();
                            }
                        } else {
                            $result[$key] = $item;
                        }
                    }
                    $data[$name] = $result;
                } elseif (is_object($value)) {
                    if ($value instanceof Enum) {
                        $data[$name] = $value->getValue();
                    } elseif (method_exists($value, 'toArray')) {
                        $data[$name] = $value->toArray();
                    }
                } else {
                    $data[$name] = $value;
                }
            } catch (Throwable $exception) {
                // 获取数据 异常， 直接排除
                continue;
            }
        }
        return $data;
    }

    /**
     * @return false|string
     */
    public function __toString()
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function __getClassname(): string
    {
        $classInfo = (new BetterReflection())->classReflector()->reflect(get_class($this));
        return $classInfo->getShortName();
    }

    /**
     * @param int $level
     *
     * @return string
     */
    public function __toObjectString(int $level = 0): string
    {
        $spaces = str_repeat(' ', $level * 4);
        $classInfo = (new BetterReflection())->classReflector()->reflect(get_class($this));
        $classname = lcfirst($classInfo->getShortName());
        $string = $spaces . '$' . "{$classname} = new {$classInfo->getName()}();\n";

        $properties = $classInfo->getProperties();
        foreach ($properties as $property) {
            try {
                $name = $property->getName();
                if ($property->isPublic()) {
                    $setter = '';
                    $value = $this->$name;
                } else {
                    // 非公共属性
                    $getter = 'get' . ucfirst(FormatHelper::camelize($name));
                    $setter = 'set' . ucfirst(FormatHelper::camelize($name));
                    $value = $this->$getter();
                    //如果 没有 getter 方法 或者 没有 setter 方法 或者 空置 直接排除
                    if (!method_exists($this, $getter) || empty($value) || !method_exists($this, $setter)) {
                        continue;
                    }
                }

                if (is_array($value)) {
                    $subSpace = str_repeat(' ', ($level + 1) * 4);
                    $str = '[';
                    foreach ($value as $k => $v) {
                        $str .= "\n" . $subSpace;
                        if (is_string($k)) {
                            $k = "'" . $k . "'";
                            $str .= "{$k} => ";
                        }
                        if (is_object($v)) {
                            if ($v instanceof Enum) {
                                $v = '\\' . get_class($v) . '::' . $v->getName() . '()';
                            } else {
                                $info = (new BetterReflection())->classReflector()->reflect(get_class($v));
                                $cname = lcfirst($info->getShortName());
                                $cSpace = str_repeat(' ', ($level + 2) * 4);
                                $v = "(function () {\n" . $v->__toObjectString($level + 2) . "{$cSpace}return $" . $cname . ";" . "\n{$subSpace}})()";
                            }
                        } else {
                            switch (gettype($v)) {
                                case 'boolean':
                                    $v = $v ? 'true' : 'false';
                                    break;
                                case 'integer':
                                    $v = (string)$v;
                                    break;
                                case 'double':
                                    $v = (string)$v;
                                    break;
                                case 'string':
                                    $v = "'" . $v . "'";
                                    break;
                                case 'resource':
                                    $v = '{resource}';
                                    break;
                                case 'NULL':
                                    $v = 'null';
                                    break;
                                case 'unknown type':
                                    $v = '{unknown}';
                                    break;
                            }
                        }
                        $str .= $v . ',';
                    }
                    $value = $str . "\n" . $spaces . ']';
                } elseif (is_object($value)) {
                    if ($value instanceof Enum) {
                        $value = '\\' . get_class($value) . '::' . $value->getName() . '()';
                    } else {
                        $info = (new BetterReflection())->classReflector()->reflect(get_class($value));
                        $cname = lcfirst($info->getShortName());
                        $cSpace = str_repeat(' ', ($level + 1) * 4);
                        $value = "(function () {\n" . $value->__toObjectString($level + 1) . "{$cSpace}return $" . $cname . ";" . "\n{$spaces}})()";
                    }
                } else {
                    switch (gettype($value)) {
                        case 'boolean':
                            $value = $value ? 'true' : 'false';
                            break;
                        case 'integer':
                            $value = (string)$value;
                            break;
                        case 'double':
                            $value = (string)$value;
                            break;
                        case 'string':
                            $value = "'" . $value . "'";
                            break;
                        case 'resource':
                            $value = '{resource}';
                            break;
                        case 'NULL':
                            $value = 'null';
                            break;
                        case 'unknown type':
                            $value = '{unknown}';
                            break;
                    }
                }
                if ($property->isPublic()) {
                    $string .= ($spaces . '$' . "{$classname}->{$name} = {$value};\n");
                } else {
                    $string .= ($spaces . '$' . "{$classname}->{$setter}({$value});\n");
                }

            } catch (Throwable $exception) {
                // 获取数据 异常， 直接排除
                continue;
            }
        }
        return $string;
    }

}
