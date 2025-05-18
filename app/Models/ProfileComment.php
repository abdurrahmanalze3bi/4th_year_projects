<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfileComment extends Model
{
    protected $fillable = [
        'profile_id',
        'user_id',
        'comment',
    ];

    /**
     * The profile that this comment belongs to.
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    /**
     * The user who wrote this comment.
     */
    public function commenter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
