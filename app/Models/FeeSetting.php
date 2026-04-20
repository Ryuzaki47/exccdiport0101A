<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class FeeSetting extends Model
{
    protected $table = 'fee_settings';

    protected $fillable = [
        'key',
        'label',
        'amount',
        'category',
        'is_active',
        'sort_order',
        'is_deletable',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'is_active'    => 'boolean',
        'is_deletable' => 'boolean',
        'sort_order'   => 'integer',
    ];

    private const CACHE_KEY = 'fee_settings_all';
    private const CACHE_TTL = 3600;

    /**
     * Boot: bust cache on any change.
     */
    protected static function boot(): void
    {
        parent::boot();
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    /**
     * Get all active settings keyed by 'key' column, cached for 1 hour.
     */
    public static function allActive()
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return self::where('is_active', true)->get()->keyBy('key');
        });
    }

    /**
     * Generate a unique key from a label for new misc items.
     * e.g. "Student Council Fee" → "misc_student_council_fee"
     */
    public static function generateKey(string $label, string $category): string
    {
        $prefix = $category === 'other' ? 'other' : 'misc';
        $slug   = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $label));
        $slug   = trim($slug, '_');
        $base   = "{$prefix}_{$slug}";

        // Ensure uniqueness
        $key  = $base;
        $i    = 2;
        while (self::where('key', $key)->exists()) {
            $key = "{$base}_{$i}";
            $i++;
        }

        return $key;
    }
}