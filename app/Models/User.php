<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\CustomResetPassword;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'avatar',       ///* Added
        'status'  ,
        'is_verified_passenger',
        'is_verified_driver',
        'verification_status'
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
    public function rides() {
        return $this->hasMany(Ride::class, 'driver_id');
    }
    public function photos()
    {
        return $this->hasMany(Photo::class);
    }
    public function bookings() {
        return $this->hasMany(Booking::class);
    }
    // Add these relationships to your User model
    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class, 'conversation_participants')
            ->withPivot(['role', 'joined_at', 'last_read_at'])
            ->withTimestamps();
    }

    public function sentMessages(): HasMany
    {
        return $this->hasMany(Message::class, 'sender_id');
    }
    /*protected static function booted()
    {
        static::created(function (User $user) {
            $user->profile()->create([
                'full_name' => $user->first_name . ' ' . $user->last_name,
                'address' => $user->address,
                'gender' => $user->gender
            ]);
        });
    }*/

    public function notifications()
    {
        return $this->belongsToMany(Notification::class, 'user_notifications')
            ->withPivot('read_at')
            ->withTimestamps();
    }

    public function userNotifications()
    {
        return $this->hasMany(UserNotification::class);
    }

    public function pushTokens()
    {
        return $this->hasMany(PushNotificationToken::class);
    }

    public function unreadNotifications()
    {
        return $this->userNotifications()->unread()->with('notification');
    }

    public function getUnreadNotificationCountAttribute()
    {
        return $this->userNotifications()->unread()->count();
    }

    public function getUnreadNotificationsCountAttribute()
    {
        return $this->notifications()->whereNull('read_at')->count();
    }
    public function givenRatings()
    {
        return $this->hasMany(UserRating::class, 'rater_id');
    }

    /**
     * Ratings received by this user
     */
    public function receivedRatings()
    {
        return $this->hasMany(UserRating::class, 'rated_user_id');
    }

    /**
     * Get average rating for this user
     */
    public function getAverageRatingAttribute()
    {
        return $this->receivedRatings()->avg('rating') ?? 0;
    }

    /**
     * Get total ratings count for this user
     */
    public function getTotalRatingsAttribute()
    {
        return $this->receivedRatings()->count();
    }
}
