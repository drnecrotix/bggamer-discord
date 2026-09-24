<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BanAppeal extends Model
{
    protected $table = 'discord_ban_appeals';

    protected $guarded = [];

    protected $hidden = ['contact', 'additional_information', 'attachment_path'];
}
