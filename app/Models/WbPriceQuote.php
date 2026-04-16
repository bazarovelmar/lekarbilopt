<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WbPriceQuote extends Model
{
    protected $fillable = [
        'chat_id',
        'telegram_user_id',
        'wb_id',
        'price_raw',
        'price_value',
        'image_path',
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
