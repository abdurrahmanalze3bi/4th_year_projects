<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Profile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'profile_photo',
        'full_name',
        'number_of_rides',
        'description',
        'car_pic',
        'type_of_car',
        'color_of_car',
        'number_of_seats',
        'comments',
        'radio',
        'smoking',
        'face_id_pic',
        'back_id_pic',
        'driving_license_pic',
        'mechanic_card_pic',
        'address',
        'gender'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
