<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    protected $table = 'accounts';
    protected $hidden = ['pin'];
    protected $fillable = ['pin', 'role'];
}
