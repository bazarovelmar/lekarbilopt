<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WbCategory extends Model
{
    protected $fillable = [
        'wb_subject_id',
        'parent_wb_subject_id',
        'name',
        'entity',
    ];
}
