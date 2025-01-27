<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Data extends Model
{
    protected $fillable = [
        'tanggal',
        'nopol',
        'driver',
        'origin',
        'destinasi',
        'uj',
        'harga',
        'status',
        'status_sj',
        'tanggal_update_sj'
    ];
}
