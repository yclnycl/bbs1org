<?php

declare(strict_types=1);

namespace app\optional\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

if (!defined('APP_ROOT')) exit;

final class Topic extends Base
{
    protected $table = 'app_topics';

    /** 发帖人，外键 user_id */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** 最后回帖人，外键 last_reply_user_id */
    public function lastReplyUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_reply_user_id');
    }

    public function forum(): BelongsTo
    {
        return $this->belongsTo(Forum::class, 'forum_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(Reply::class, 'topic_id');
    }
}
