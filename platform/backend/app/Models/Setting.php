<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Key/value store settings managed in the admin panel (store name, contacts, policies, shipping...).
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['value' => 'array'];

    /** Defaults (also the list of keys the admin can edit). */
    public const DEFAULTS = [
        'store_name' => 'Perfumarathi',
        'store_tagline' => 'Secret of Attractiveness',
        'logo_path' => null,
        'currency' => 'AED',
        'contact_phone' => '+971 52 820 9716',
        'whatsapp_number' => '971528209716',
        'contact_email' => null,
        'store_address' => null,
        'social_instagram' => 'https://www.instagram.com/perfumarathi/',
        'social_facebook' => 'https://www.facebook.com/share/19hAJq7n2X/?mibextid=wwXIfr',
        'social_tiktok' => 'https://www.tiktok.com/@perfumarathi',
        'social_youtube' => 'https://youtube.com/@perfumarathi',
        'shipping_flat_rate' => 0,
        'free_shipping_over' => null,
        'shipping_countries' => ['AE', 'SA', 'QA', 'KW', 'OM', 'BH', 'IN'],
        'shipping_info' => 'Delivery across UAE, Saudi Arabia, Qatar, Kuwait, Oman, Bahrain, India / Kerala, GCC and worldwide.',
        'return_policy' => null,
        'privacy_policy' => null,
        'terms' => null,
        'about_us' => null,
        'low_stock_threshold' => 3,
        'card_payment_timeout_minutes' => 60,
        'order_notification_email' => null,
        'apple_pay_domain_association' => null,
    ];

    /** Keys safe to send to the public website. */
    public const PUBLIC_KEYS = [
        'store_name', 'store_tagline', 'logo_path', 'currency', 'contact_phone', 'whatsapp_number', 'contact_email',
        'store_address', 'social_instagram', 'social_facebook', 'social_tiktok', 'social_youtube', 'shipping_flat_rate',
        'free_shipping_over', 'shipping_countries', 'shipping_info', 'return_policy', 'privacy_policy', 'terms', 'about_us',
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = static::allValues();

        return array_key_exists($key, $all) && $all[$key] !== null ? $all[$key] : ($default ?? (self::DEFAULTS[$key] ?? null));
    }

    public static function allValues(): array
    {
        return Cache::rememberForever('settings.all', function () {
            $stored = static::query()->pluck('value', 'key')->all();

            return array_merge(self::DEFAULTS, $stored);
        });
    }

    public static function put(array $values): void
    {
        foreach ($values as $k => $v) {
            if (array_key_exists($k, self::DEFAULTS)) {
                static::query()->updateOrCreate(['key' => $k], ['value' => $v]);
            }
        }
        Cache::forget('settings.all');
    }
}
