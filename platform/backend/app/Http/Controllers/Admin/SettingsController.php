<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Banner;
use App\Models\HomeSection;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\User;
use App\Services\ImageStore;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Store settings, payment methods, homepage content and staff accounts. */
class SettingsController extends Controller
{
    public function __construct(private ImageStore $images) {}

    // ---------- Store settings ----------
    public function settings(): JsonResponse
    {
        $s = Setting::allValues();

        $media = [];
        foreach (Setting::MEDIA_FALLBACK as $key => $fallback) {
            $media[str_replace('_path', '_url', $key)] = ! empty($s[$key]) ? Present::url($s[$key]) : $fallback;
        }

        return response()->json($s + $media + ['logo_url' => Present::url($s['logo_path'] ?? null)]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $text = 'nullable|string|max:20000';
        $v = $request->validate([
            'store_name' => 'sometimes|required|string|max:100',
            'store_tagline' => 'nullable|string|max:200',
            'currency' => 'sometimes|required|string|size:3|alpha',
            'contact_phone' => 'nullable|string|max:40',
            'whatsapp_number' => ['nullable', 'string', 'max:20', 'regex:/^[0-9]{7,20}$/'],
            'contact_email' => 'nullable|email|max:150',
            'store_address' => 'nullable|string|max:500',
            'social_instagram' => 'nullable|url|max:300', 'social_facebook' => 'nullable|url|max:300',
            'social_tiktok' => 'nullable|url|max:300', 'social_youtube' => 'nullable|url|max:300',
            'shipping_flat_rate' => 'nullable|numeric|min:0|max:100000',
            'free_shipping_over' => 'nullable|numeric|min:0|max:10000000',
            'shipping_countries' => 'nullable|array', 'shipping_countries.*' => 'string|size:2|alpha',
            'shipping_info' => $text, 'return_policy' => $text, 'privacy_policy' => $text, 'terms' => $text, 'about_us' => $text,
            'low_stock_threshold' => 'nullable|integer|min:0|max:100000',
            'card_payment_timeout_minutes' => 'nullable|integer|min:15|max:1440',
            'order_notification_email' => 'nullable|email|max:150',
            'apple_pay_domain_association' => 'nullable|string|max:20000',
            'logo' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:4096',
            'home_title' => 'nullable|string|max:100', 'home_caption' => 'nullable|string|max:150',
            'perfumarathi_eyebrow' => 'nullable|string|max:100', 'perfumarathi_subtitle' => 'nullable|string|max:200', 'perfumarathi_foot' => 'nullable|string|max:200',
            'fixnote_eyebrow' => 'nullable|string|max:100', 'fixnote_subtitle' => 'nullable|string|max:200', 'fixnote_foot' => 'nullable|string|max:200',
            'presence_text' => 'nullable|string|max:200', 'trust_line' => 'nullable|string|max:200',
            'home_video' => 'nullable|file|mimetypes:video/mp4,video/webm|max:102400',
            'home_poster' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:8192',
            'perfumarathi_logo' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:8192',
            'fixnote_logo' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:8192',
        ]);
        foreach (['home_poster', 'perfumarathi_logo', 'fixnote_logo'] as $f) {
            if ($request->hasFile($f)) {
                $this->images->delete(Setting::get($f.'_path'));
                $v[$f.'_path'] = $this->images->store($request->file($f), 'branding')['path'];
            }
            unset($v[$f]);
        }
        if ($request->hasFile('home_video')) {
            $this->images->delete(Setting::get('home_video_path'));
            $v['home_video_path'] = $request->file('home_video')->storeAs('branding', Str::random(24).'.'.($request->file('home_video')->getMimeType() === 'video/webm' ? 'webm' : 'mp4'), 'public');
        }
        unset($v['home_video']);
        if (isset($v['currency'])) {
            $v['currency'] = strtoupper($v['currency']);
        }
        if (isset($v['shipping_countries'])) {
            $v['shipping_countries'] = array_values(array_unique(array_map('strtoupper', $v['shipping_countries'])));
        }
        if ($request->hasFile('logo')) {
            $this->images->delete(Setting::get('logo_path'));
            $v['logo_path'] = $this->images->store($request->file('logo'), 'branding')['path'];
        }
        unset($v['logo']);
        Setting::put($v);

        return $this->settings();
    }

    // ---------- Payment methods ----------
    private function presentMethod(PaymentMethod $m): array
    {
        $creds = $m->credentials ?? [];
        $mask = fn ($k) => ! empty($creds[$k]) ? '••••'.substr((string) $creds[$k], -4) : null;

        return $m->toArray() + [
            'is_configured' => $m->isConfigured(),
            'credentials_masked' => $m->provider === 'stripe' ? ['secret_key' => $mask('secret_key'), 'webhook_secret' => $mask('webhook_secret')] : [],
            'webhook_url' => $m->provider === 'stripe' ? url('/api/webhooks/stripe') : null,
        ];
    }

    public function paymentMethods(): JsonResponse
    {
        return response()->json(PaymentMethod::query()->orderBy('sort_order')->get()->map(fn ($m) => $this->presentMethod($m)));
    }

    /**
     * PUT /api/admin/payment-methods/{id} (admin only). Secrets are write-only: an empty value keeps the saved one.
     * Live keys are refused unless PAYMENTS_ALLOW_LIVE=true in .env.
     */
    public function updatePaymentMethod(Request $request, int $id): JsonResponse
    {
        $m = PaymentMethod::findOrFail($id);
        $v = $request->validate([
            'title' => 'sometimes|required|string|max:100',
            'description' => 'nullable|string|max:500',
            'is_enabled' => 'sometimes|boolean',
            'sort_order' => 'nullable|integer|min:0',
            'fee' => 'nullable|numeric|min:0|max:100000',
            'min_total' => 'nullable|numeric|min:0', 'max_total' => 'nullable|numeric|min:0',
            'countries' => 'nullable|array', 'countries.*' => 'string|size:2|alpha',
            'publishable_key' => ['nullable', 'string', 'max:255', 'regex:/^pk_(test|live)_[A-Za-z0-9]+$/'],
            'secret_key' => ['nullable', 'string', 'max:255', 'regex:/^(sk|rk)_(test|live)_[A-Za-z0-9]+$/'],
            'webhook_secret' => ['nullable', 'string', 'max:255', 'regex:/^whsec_[A-Za-z0-9]+$/'],
        ]);
        if ($m->provider === 'stripe') {
            $live = collect(['publishable_key', 'secret_key'])->contains(fn ($k) => ! empty($v[$k]) && str_contains($v[$k], '_live_'));
            abort_if($live && ! config('shop.allow_live_payments'), 422, 'Live Stripe keys are switched off on this site (PAYMENTS_ALLOW_LIVE=false). Use test keys (pk_test_ / sk_test_).');
            $pk = $v['publishable_key'] ?? $m->option('publishable_key');
            $sk = $v['secret_key'] ?? $m->credential('secret_key');
            if ($pk && $sk && (str_contains($pk, '_live_') !== str_contains($sk, '_live_'))) {
                abort(422, 'The publishable key and secret key must both be test keys or both be live keys.');
            }
        }
        $creds = $m->credentials ?? [];
        foreach (['secret_key', 'webhook_secret'] as $k) {
            if (! empty($v[$k])) {
                $creds[$k] = $v[$k];
            }
        }
        $options = $m->options ?? [];
        if (array_key_exists('publishable_key', $v) && $v['publishable_key'] !== null) {
            $options['publishable_key'] = $v['publishable_key'];
            $options['mode'] = str_contains($v['publishable_key'], '_live_') ? 'live' : 'test';
        }
        $m->fill(collect($v)->except(['publishable_key', 'secret_key', 'webhook_secret'])->all());
        $m->options = $options;
        $m->credentials = $creds;
        if ($m->is_enabled && ! $m->isConfigured()) {
            abort(422, 'Enter the Stripe publishable key and secret key before enabling card payments.');
        }
        $m->save();

        return response()->json($this->presentMethod($m));
    }

    // ---------- Banners ----------
    public function banners(): JsonResponse
    {
        return response()->json(Banner::query()->orderBy('placement')->orderBy('sort_order')->get());
    }

    public function saveBanner(Request $request, ?int $id = null): JsonResponse
    {
        $b = $id ? Banner::findOrFail($id) : new Banner;
        $v = $request->validate([
            'placement' => 'required|in:home,promo', 'title' => 'nullable|string|max:200', 'subtitle' => 'nullable|string|max:500',
            'button_text' => 'nullable|string|max:60', 'link_url' => ['nullable', 'string', 'max:500', 'regex:#^(/|https?://)#'],
            'is_active' => 'boolean', 'sort_order' => 'nullable|integer|min:0',
            'starts_at' => 'nullable|date', 'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'image' => [$b->exists ? 'nullable' : 'required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'mobile_image' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:8192',
        ]);
        $b->fill(collect($v)->except(['image', 'mobile_image'])->all());
        foreach (['image' => 'image_path', 'mobile_image' => 'mobile_image_path'] as $field => $col) {
            if ($request->hasFile($field)) {
                $this->images->delete($b->{$col});
                $b->{$col} = $this->images->store($request->file($field), 'banners')['path'];
            }
        }
        $b->save();

        return response()->json($b, $id ? 200 : 201);
    }

    public function deleteBanner(int $id): JsonResponse
    {
        $b = Banner::findOrFail($id);
        $this->images->delete($b->image_path, $b->mobile_image_path);
        $b->delete();

        return response()->json(['ok' => true]);
    }

    // ---------- Announcement bar ----------
    public function announcements(): JsonResponse
    {
        return response()->json(Announcement::query()->orderBy('sort_order')->get());
    }

    public function saveAnnouncement(Request $request, ?int $id = null): JsonResponse
    {
        $a = $id ? Announcement::findOrFail($id) : new Announcement;
        $a->fill($request->validate([
            'text' => 'required|string|max:300', 'link_url' => ['nullable', 'string', 'max:500', 'regex:#^(/|https?://)#'],
            'is_active' => 'boolean', 'sort_order' => 'nullable|integer|min:0',
            'starts_at' => 'nullable|date', 'ends_at' => 'nullable|date|after_or_equal:starts_at',
        ]))->save();

        return response()->json($a, $id ? 200 : 201);
    }

    public function deleteAnnouncement(int $id): JsonResponse
    {
        Announcement::findOrFail($id)->delete();

        return response()->json(['ok' => true]);
    }

    // ---------- Homepage sections ----------
    public function sections(): JsonResponse
    {
        return response()->json(HomeSection::query()->orderBy('sort_order')->get());
    }

    public function saveSection(Request $request, ?int $id = null): JsonResponse
    {
        $s = $id ? HomeSection::findOrFail($id) : new HomeSection;
        $v = $request->validate([
            'title' => 'required|string|max:120', 'subtitle' => 'nullable|string|max:500',
            'source' => ['required', Rule::in(HomeSection::SOURCES)],
            'source_id' => 'nullable|integer|required_if:source,category,brand',
            'product_ids' => 'nullable|array|max:24|required_if:source,manual', 'product_ids.*' => 'integer|exists:products,id',
            'limit' => 'nullable|integer|min:1|max:24', 'is_active' => 'boolean', 'sort_order' => 'nullable|integer|min:0',
        ]);
        $s->fill($v)->save();

        return response()->json($s, $id ? 200 : 201);
    }

    public function deleteSection(int $id): JsonResponse
    {
        HomeSection::findOrFail($id)->delete();

        return response()->json(['ok' => true]);
    }

    // ---------- Staff accounts (admin only) ----------
    public function staff(): JsonResponse
    {
        return response()->json(User::query()->orderBy('name')->get(['id', 'name', 'email', 'role', 'is_active', 'last_login_at']));
    }

    public function saveStaff(Request $request, ?int $id = null): JsonResponse
    {
        $u = $id ? User::findOrFail($id) : new User;
        $v = $request->validate([
            'name' => 'required|string|max:100',
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($id)],
            'role' => ['required', Rule::in(User::ROLES)],
            'is_active' => 'boolean',
            'password' => [$u->exists ? 'nullable' : 'required', Password::min(10)->letters()->numbers()],
        ]);
        if ($u->exists && $u->id === $request->user()->id && (($v['role'] ?? 'admin') !== 'admin' || ! ($v['is_active'] ?? true))) {
            abort(422, 'You cannot remove your own admin access.');
        }
        if (empty($v['password'])) {
            unset($v['password']);
        }
        $v['email'] = mb_strtolower($v['email']);
        $u->fill($v)->save();

        return response()->json($u->only(['id', 'name', 'email', 'role', 'is_active']), $id ? 200 : 201);
    }

    public function deleteStaff(Request $request, int $id): JsonResponse
    {
        abort_if($id === $request->user()->id, 422, 'You cannot delete your own account.');
        abort_if(User::query()->where('role', 'admin')->where('is_active', true)->whereKeyNot($id)->doesntExist(), 422, 'At least one active admin is required.');
        User::findOrFail($id)->delete();
        Cache::forget('settings.all');

        return response()->json(['ok' => true]);
    }
}
