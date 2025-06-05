<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserRating extends Model
{
    use HasFactory;

    protected $fillable = [
        'rater_id',
        'rated_user_id',
        'rating',
    ];

    protected $casts = [
        'rating' => 'float',
    ];

    /**
     * The user who gave the rating
     */
    public function rater()
    {
        return $this->belongsTo(User::class, 'rater_id');
    }

    /**
     * The user who received the rating
     */
    public function ratedUser()
    {
        return $this->belongsTo(User::class, 'rated_user_id');
    }

}
