<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Events;

class PaymentTerm extends Model
{
    protected $table = 'payment_terms';

    protected $fillable = ['term_order', 'term_name', 'percentage'];

    protected $casts = [
        'percentage' => 'decimal:2',
    ];

    // FIX Bug #8: Clear cache on any write operation via model events.
    protected static function booted(): void
    {
        $clear = fn () => self::clearCache();

        static::saved($clear);
        static::deleted($clear);
    }

    public static function getTerms()
    {
        return cache()->remember('payment_terms', 3600, function () {
            return self::orderBy('term_order')->get();
        });
    }

    public static function validatePercentages(): bool
    {
        $total = self::sum('percentage');
        return abs($total - 100.0) < 0.01;
    }

    public static function clearCache(): void
    {
        cache()->forget('payment_terms');
    }
}