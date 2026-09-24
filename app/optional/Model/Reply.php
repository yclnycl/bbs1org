<?php

declare(strict_types=1);

namespace app\optional\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

if (!defined('APP_ROOT')) exit;

final class Reply extends Base
{
    protected $table = 'app_replies';

    /** 回帖人，外键 user_id */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class, 'topic_id');
    }
}
