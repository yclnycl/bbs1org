<?php

declare(strict_types=1);

namespace app\optional\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

if (!defined('APP_ROOT')) exit;

final class Notification extends Base
{
    protected $table = 'app_notifications';

    /** 接收人，外键 recipient_id */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    /** 触发人，外键 sender_id */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
