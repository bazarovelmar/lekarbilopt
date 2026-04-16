<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GeneratedPost extends Model
{
    protected $fillable = [
        'chat_id',
        'telegram_user_id',
        'price_value',
        'price_raw',
        'description',
        'image_path',
        'channel_message_id',
        'status',
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
