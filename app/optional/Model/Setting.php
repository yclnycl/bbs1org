<?php

declare(strict_types=1);

namespace app\optional\Model;

if (!defined('APP_ROOT')) exit;

/** 键值设置表：主键是 name 而不是自增 id */
final class Setting extends Base
{
    protected $table = 'app_settings';

    protected $primaryKey = 'name';

    public $incrementing = false;

    protected $keyType = 'string';
}
