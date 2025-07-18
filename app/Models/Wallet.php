<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Wallet extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'stripe_customer_id',
        'stripe_payment_method_id',
        'balance',
        'phone_number'
    ];

    protected $casts = [
        'balance' => 'decimal:2'
    ];
// app/Models/Wallet.php
    protected static function booted()
    {
        static::creating(function ($wallet) {
            $wallet->wallet_number = self::generateWalletNumber();
        });
    }

    private static function generateWalletNumber()
    {
        do {
            // Generate 16-digit number (credit card format)
            $number = '';
            for ($i = 0; $i < 16; $i++) {
                $number .= mt_rand(0, 9);
            }
        } while (self::where('wallet_number', $number)->exists());

        return $number;
    }
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
