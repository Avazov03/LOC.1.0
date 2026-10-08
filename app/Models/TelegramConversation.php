<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramConversation extends Model
{
    protected $fillable = ['telegram_user_id', 'state', 'context', 'expires_at'];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'expires_at' => 'datetime',
        ];
    }
}
