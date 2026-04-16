<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WbProduct extends Model
{
    protected $fillable = [
        'wb_id',
        'title',
        'brand',
        'supplier',
        'supplier_id',
        'subject_id',
        'subject_parent_id',
        'category_id',
        'subcategory_id',
        'image_path',
        'data',
        'characteristics',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'characteristics' => 'array',
        ];
    }
}
