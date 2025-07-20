<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    protected $fillable = ['seats', 'status','user_id','ride_id'];

    public function ride() {
        return $this->belongsTo(Ride::class);
    }

    public function user() {
        return $this->belongsTo(User::class);
    }
    protected $casts = [
        'completed_at' => 'datetime',
        'passenger_confirmed_at' => 'datetime',
    ];
}
