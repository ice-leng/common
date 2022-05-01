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
    public int $page = 1;

    /**
     * 页数
     * @var int
     */
    public int $pageSize = 20;

    /**
     * 是否获取全部结果
     * @var int
     */
    public int $all = 0;

    /**
     * 是否获取总数
     * @var int
     */
    public int $total = 1;

    /**
     * @return Page
     */
    public static function all(): Page
    {
        return new Page([
            'all' => 1,
            'total' => 0,
        ]);
    }
}
