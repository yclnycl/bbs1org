<?php

declare(strict_types=1);

namespace app\optional\Model;

if (!defined('APP_ROOT')) exit;

final class Group extends Base
{
    protected $table = 'app_groups';
}
