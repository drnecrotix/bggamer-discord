<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DiscordBan extends Model
{
    protected $table = 'discord_bans';

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['internal_notes', 'moderator_notes', 'discord_user_id'];
}
