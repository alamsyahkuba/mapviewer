<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Driver extends Model
{
    protected $table = '`list driver position`';

    protected $fillable = [
        'Driver Id',
        'CurrentLatitude',
        'CurrentLongitude',
        'OriginLatitude',
        'OriginLongitude',
        'DestinationLatitude',
        'DestinationLongitude',
    ];
}
