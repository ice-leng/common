<?php
declare(strict_types=1);

namespace Lengbin\Common\Entity;

use Lengbin\Common\BaseObject;

class Page extends BaseObject
{
    /**
     * 页码
     * @var int
     */
    public $page = 1;

    /**
     * 页数
     * @var int
     */
    public $pageSize = 20;

    /**
     * 是否获取全部结果
     * @var bool
     */
    public $all = false;

    /**
     * 是否获取总数
     * @var bool
     */
    public $total = true;

    /**
     * @return Page
     */
    public static function all(): Page
    {
        return new Page([
            'all'   => true,
            'total' => false,
        ]);
    }
}
