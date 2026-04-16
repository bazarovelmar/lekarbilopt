<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WbPlaywrightNodeLog extends Model
{
    protected $fillable = [
        'node',
        'query',
        'status',
        'data',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
        ];
    }
}
