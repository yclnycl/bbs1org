<?php

declare(strict_types=1);

namespace app\optional\Model;

use Illuminate\Database\Eloquent\Model;

if (!defined('APP_ROOT')) exit;

/**
 * 所有模型的共用部分。
 *
 * 两点必须和现有表结构一致，不能按 Eloquent 的默认行为走：
 *
 * 1. created_at / updated_at 存的是 INTEGER 的 unix 秒，由调用方显式写值。所以既不开
 *    $timestamps 自动维护，也不能把它们 cast 成日期：日期 cast 在写入时会格式化成
 *    'Y-m-d H:i:s'，SQLite 对 INTEGER 列存不进数字就按 TEXT 存，新旧行混在一起之后
 *    ORDER BY created_at 会错乱（SQLite 里整数恒小于文本）。
 * 2. 写入值都来自已经校验过的表单字段，不需要批量赋值白名单。
 */
abstract class Base extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}
