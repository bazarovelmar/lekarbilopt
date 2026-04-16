<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DialogSession extends Model
{
    protected $fillable = [
        'chat_id',
        'telegram_user_id',
        'state',
        'photo_file_id',
        'price_raw',
        'price_value',
        'last_service_message_id',
        'data',
    ];

    protected function casts(): array
    {
        return [
            'price_value' => 'decimal:2',
            'data' => 'array',
        ];
    }
}
