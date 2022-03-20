<?php

declare(strict_types=1);

namespace Lengbin\Common;

use Lengbin\Common\Annotation\ArrayType;
use Lengbin\Common\Annotation\EnumView;
use Lengbin\Helper\Util\FormatHelper;
use MabeEnum\Enum;
use phpDocumentor\Reflection\DocBlock\Tags\TagWithType;
use phpDocumentor\Reflection\DocBlockFactory;
use phpDocumentor\Reflection\Types\Array_;
use phpDocumentor\Reflection\Types\Compound;
use phpDocumentor\Reflection\Types\Nullable;
use phpDocumentor\Reflection\Types\Object_;
use phpDocumentor\Reflection\Types\Context;
use ReflectionClass;
use ReflectionObject;
use ReflectionProperty;
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

        if (class_exists(Enum::class) && is_subclass_of($classname, Enum::class)) {
            return $classname::byValue($value);
        }

        $class = new ReflectionClass($classname);
        return $class->newInstance($value);
    }

    private function fromDocBlock(TagWithType $tagWithType, $value)
    {
        $type = $tagWithType->getType();
        switch (true) {
            case $type instanceof Compound:
                foreach ($type->getIterator() as $item) {
                    if ($item instanceof Object_) {
                        $value = $this->createObject($item->getFqsen()->__toString(), $value);
                        break;
                    }
                }
                break;
            case $type instanceof Nullable && method_exists($type->getActualType(), 'getFqsen'):
                $value = $this->createObject($type->getActualType()->getFqsen()->__toString(), $value);
                break;
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

    private function getDocBlockByProperty($class, $value, $isPhp8): array
    {
        $isHandle = false;
        if ($class instanceof ReflectionProperty) {
            $type = $class->getType();
            if (!$type) {
                return [$value, $isHandle];
            }
            if (!$type->isBuiltin()) {
                $isHandle = true;
                $value = $this->createObject($type->getName(), $value);
            }
            if ($type->getName() === 'array' && $isPhp8) {
                $arrayTypes = $class->getAttributes(ArrayType::class);
                if (!empty($arrayTypes)) {
                    $isHandle = true;
                    $arrayType = $arrayTypes[0]->newInstance();
                    if ($arrayType->className) {
                        foreach ($value as $key => $item) {
                            $value[$key] = $this->createObject($arrayType->className, $item);
                        }
                    }
                }
            }
        }
        return [$value, $isHandle];
    }

    private function getDocBlock($class, $factory, $context, $value, $tagName, $isPhp8)
    {
        [$value, $isHandle] = $this->getDocBlockByProperty($class, $value, $isPhp8);
        if ($isHandle) {
            return $value;
        }

        $docComment = $class->getDocComment();
        if (empty($docComment)) {
            return $value;
        }
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
        $factory = DocBlockFactory::createInstance();
        $context = new Context($class->getNamespaceName(), Reflection::getUseStatements($class));
        $isPhp8 = version_compare(PHP_VERSION, '8.0.0', '>');
        foreach ($properties as $name => $value) {
            $camelize = FormatHelper::camelize($name);
            $setter = 'set' . ucfirst($camelize);
            switch (true) {
                case $class->hasMethod($setter):
                    $value = $this->getDocBlock($class->getMethod($setter), $factory, $context, $value, 'param', $isPhp8);
                    $object->{$setter}($value);
                    break;
                case $class->hasProperty($name):
                    $object->{$name} = $this->getDocBlock($class->getProperty($name), $factory, $context, $value, 'var', $isPhp8);
                    break;
                case $class->hasProperty($camelize):
                    $object->{$camelize} = $this->getDocBlock($class->getProperty($camelize), $factory, $context, $value, 'var', $isPhp8);
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

    private function fromValue($property, $value, $isPhp8)
    {
        switch (true) {
            case is_array($value):
                foreach ($value as $key => $item) {
                    $value[$key] = $this->fromValue($property, $item, $isPhp8);
                }
                break;
            case is_object($value):
                if (class_exists(Enum::class) && $value instanceof Enum) {
                    $flags = EnumView::ENUM_VALUE;
                    if ($isPhp8 && $enumViews = $property->getAttributes(EnumView::class)) {
                        $flags = $enumViews[0]->newInstance()->flags;
                    }
                    switch ($flags) {
                        case EnumView::ENUM_NAME;
                            $value = $value->getName();
                            break;
                        case EnumView::ENUM_VALUE;
                            $value = $value->getValue();
                            break;
                        case EnumView::ENUM_MESSAGE;
                            $value = $value->getMessage();
                            break;
                        case EnumView::ENUM_ALL;
                            $value = [
                                'value'   => $value->getValue(),
                                'message' => $value->getMessage(),
                            ];
                            break;
                    }
                } elseif (method_exists($value, 'toArray')) {
                    $value = $value->toArray();
                }
                break;
        }
        return $value;
    }

    private function getObjectData(ReflectionClass $class, $object, $isPhp8)
    {
        $data = [];
        $properties = $class->getProperties();

        foreach ($properties as $property) {
            if ($property->isPrivate()) {
                continue;
            }
            $name = $property->getName();
            $value = $object->{$name};
            if (is_null($value)) {
                continue;
            }
            $data[$name] = $this->fromValue($property, $value, $isPhp8);
        }
        return $data;
    }

    public function toArray(): array
    {
        $class = new ReflectionObject($this);
        $isPhp8 = version_compare(PHP_VERSION, '8.0.0', '>');
        $data = $this->getObjectData($class, $this, $isPhp8);
        while ($class->getParentClass()) {
            $class = new ReflectionClass($class->getParentClass()->getName());
            if (!$class->isInstantiable()) {
                continue;
            }
            $parent = $this->getObjectData($class, $this, $isPhp8);
            $data = array_merge($data, $parent);
        }
        return $data;
    }

    public function __toString(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
