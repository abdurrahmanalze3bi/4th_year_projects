<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\CustomResetPassword;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    // app/Models/User.php
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'password',
        'gender',       // Keep existing
        'address',      // Keep existing
        'google_id',    // Added
        'avatar',       // Added
        'status'        // Keep existing
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'google_id'     // Added to hidden
    ];
    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];
    // app/Models/User.php
    public function sendPasswordResetNotification($token)
    {
        $this->notify(new CustomResetPassword($token));
    }
    public function profile()
    {
        return $this->hasOne(Profile::class);
    }
    protected static function booted()
    {
        static::created(function (User $user) {
            $user->profile()->create([
                'full_name' => $user->first_name . ' ' . $user->last_name,
                'address' => $user->address,
                'gender' => $user->gender
            ]);
        });
    }
}
