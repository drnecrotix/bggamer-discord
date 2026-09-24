<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PortalPage extends Model
{
    protected $table = 'portal_pages';

    protected $primaryKey = 'slug';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}
