<?php

declare(strict_types=1);

namespace app\optional\Model;

if (!defined('APP_ROOT')) exit;

/** 楼层索引：记录被删除的回复占用的位置，删除后楼层号不再被顶替 */
final class TopicDeletion extends Base
{
    protected $table = 'app_topics_del';
}
