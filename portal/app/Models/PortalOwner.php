<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PortalOwner extends Model
{
    protected $table = 'portal_owners';

    protected $guarded = [];

    protected $hidden = ['password'];
}
