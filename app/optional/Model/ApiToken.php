<?php

declare(strict_types=1);

namespace app\optional\Model;

if (!defined('APP_ROOT')) exit;

/** MCP 接入令牌：token_hash 是明文的 SHA-256，明文本身不落库 */
final class ApiToken extends Base
{
    protected $table = 'app_api_tokens';
}
