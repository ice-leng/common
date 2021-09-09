<?php

declare(strict_types=1);

namespace Lengbin\Common;

use Lengbin\Helper\Util\FormatHelper;
use MabeEnum\Enum;
use phpDocumentor\Reflection\DocBlock\Tags\TagWithType;
use phpDocumentor\Reflection\DocBlockFactory;
use phpDocumentor\Reflection\Types\Array_;
use phpDocumentor\Reflection\Types\Object_;
use phpDocumentor\Reflection\Types\Context;
use ReflectionClass;
use ReflectionObject;
use RuntimeException;

class BaseObject
{
    public function __construct(array $config = [])
    {
        if (!empty($config)) {
            $this->configure($this, $config);
        }
        $this->init();
    }

    public function init()
    {

    }

    private function createObject(string $classname, $value): object
    {
        if (is_object($value)) {
            return $value;
        }

        $class = new ReflectionClass($classname);
        if (method_exists($classname, 'byValue')) {
            return $classname::byValue($value);
        }

        $object = $class->newInstance();

        if ($object instanceof BaseObject) {
            $object->configure($object, $value);
        }

        return $object;
    }

    private function fromDocBlock(TagWithType $tagWithType, $value)
    {
        $type = $tagWithType->getType();
        switch (true) {
            case $type instanceof Object_:
                $value = $this->createObject($type->getFqsen()->__toString(), $value);
                break;
            case ($type instanceof Array_ && $type->getValueType() instanceof Object_):
                foreach ($value as $key => $item) {
                    $value[$key] = $this->createObject($type->getValueType()->getFqsen()->__toString(), $item);
                }
                break;
        }
        return $value;
    }

    private function getDocBlock($class, $context, $value, $tagName)
    {
        $docComment = $class->getDocComment();
        if (empty($docComment)) {
            return $value;
        }
        $factory = DocBlockFactory::createInstance();
        $block = $factory->create($docComment, $context);
        $tags = $block->getTagsByName($tagName);
        if (empty($tags)) {
            return $value;
        }
        $tag = current($tags);
        return $this->fromDocBlock($tag, $value);
    }

    public function configure($object, array $properties)
    {
        $class = new ReflectionObject($object);
        $context = new Context($class->getNamespaceName(), Reflection::getUseStatements($class));
        foreach ($properties as $name => $value) {
            $camelize = FormatHelper::camelize($name);
            $setter = 'set' . ucfirst($camelize);
            switch (true) {
                case $class->hasProperty($name):
                    $object->{$name} = $this->getDocBlock($class->getProperty($name), $context, $value, 'var');
                    break;
                case $class->hasProperty($camelize):
                    $object->{$camelize} = $this->getDocBlock($class->getProperty($camelize), $context, $value, 'var');
                    break;
                case $class->hasMethod($setter):
                    $value = $this->getDocBlock($class->getMethod($setter), $context, $value, 'param');
                    $object->{$setter}($value);
                    break;
                default:
                    $object->{$name} = $value;
                    break;
            }
        }
    }

    /**
     * getter
     *
     * @param $name
     *
     * @return mixed
     * @throws RuntimeException
     */
    public function __get($name)
    {
        $getter = 'get' . $name;
        if (method_exists($this, $getter)) {
            return $this->$getter();
        }

        $camelize = FormatHelper::camelize($name);
        if (property_exists($this, $camelize)) {
            return $this->{$camelize};
        }

        return $this->{$name};
    }

    /**
     * setter
     *
     * @param $name
     * @param $value
     *
     * @throws RuntimeException
     */
    public function __set($name, $value)
    {
        $setter = 'set' . $name;
        if (method_exists($this, $setter)) {
            $this->$setter($value);
        }

        $camelize = FormatHelper::camelize($name);
        if (property_exists($this, $camelize)) {
            $this->{$camelize} = $value;
        }

        $this->{$name} = $value;
    }

    private function fromValue($value)
    {
        switch (true) {
            case is_array($value):
                foreach ($value as $key => $item) {
                    $value[$key] = $this->fromValue($item);
                }
                break;
            case is_object($value):
                if (class_exists(Enum::class) && $value instanceof Enum) {
                    $value = $value->getValue();
                } elseif (method_exists($value, 'toArray')) {
                    $value = $value->toArray();
                }
                break;
        }
        return $value;
    }

    private function getObjectData(ReflectionClass $class, $object)
    {
        $data = [];
        $properties = $class->getProperties();
        foreach ($properties as $property) {
            $name = $property->getName();
            $value = $object->{$name};
            if (is_null($value)) {
                continue;
            }
            $data[$name] = $this->fromValue($value);
        }
        return $data;
    }

    public function toArray(): array
    {
        $class = new ReflectionObject($this);
        $data = $this->getObjectData($class, $this);
        while ($class->getParentClass()) {
            $class = new ReflectionClass($class->getParentClass()->getName());
            if (!$class->isInstantiable()) {
                continue;
            }
            $parent = $this->getObjectData($class, $this);
            $data = array_merge($data, $parent);
        }
        return $data;
    }

    public function __toString(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
