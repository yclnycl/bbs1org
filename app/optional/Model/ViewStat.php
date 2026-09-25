<?php

declare(strict_types=1);

namespace app\optional\Model;

if (!defined('APP_ROOT')) exit;

/**
 * 每日浏览量统计：view_date 是 'Y-m-d' 文本（站点时区），views 是当日浏览次数。
 * 只服务于后台报表的按日汇总，不参与前台展示；口径与 app_topics.view_count 一致（cookie 去重后的浏览）。
 * 主键是 view_date 而非自增 id，必须声明，否则 getKey()/increment() 会落空。
 */
final class ViewStat extends Base
{
    protected $table = 'app_view_stats';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $primaryKey = 'view_date';
}
