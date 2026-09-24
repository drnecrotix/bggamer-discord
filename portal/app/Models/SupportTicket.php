<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportTicket extends Model
{
    protected $guarded = [];

    protected $hidden = ['email', 'message'];
}
