<?php

declare(strict_types=1);

namespace app\optional\Model;

use Illuminate\Database\Eloquent\Relations\HasMany;

if (!defined('APP_ROOT')) exit;

final class Forum extends Base
{
    protected $table = 'app_forums';

    public function topics(): HasMany
    {
        return $this->hasMany(Topic::class, 'forum_id');
    }
}
