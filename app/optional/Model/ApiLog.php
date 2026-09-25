<?php

declare(strict_types=1);

namespace app\optional\Model;

if (!defined('APP_ROOT')) exit;

/** MCP 审计日志：一次调用一条，user_id/token_id 在鉴权失败时为空 */
final class ApiLog extends Base
{
    protected $table = 'app_api_logs';
}
