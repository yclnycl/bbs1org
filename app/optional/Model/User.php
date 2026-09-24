<?php

declare(strict_types=1);

namespace app\optional\Model;

use Illuminate\Database\Eloquent\Relations\HasMany;

if (!defined('APP_ROOT')) exit;

final class User extends Base
{
    protected $table = 'app_users';

    public function topics(): HasMany
    {
        return $this->hasMany(Topic::class, 'user_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(Reply::class, 'user_id');
    }
}
