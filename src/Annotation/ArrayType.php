<?php
/**
 * Created by PhpStorm.
 * Date:  2022/3/17
 * Time:  10:10 PM
 */

declare(strict_types=1);

namespace Lengbin\Common\Annotation;

use Attribute;

/**
 * @Annotation
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class ArrayType
{
    public ?string $className;

    public ?string $type;

    public function __construct( string $className = null, string $type = null)
    {
        $this->className = $className;
        $this->type = $type;
    }
}
