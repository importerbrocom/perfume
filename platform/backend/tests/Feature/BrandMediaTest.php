<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BrandMediaTest extends TestCase
{
    private function png(): string
    {
        $im = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }

    public function test_copies_brand_media_and_refuses_files_of_the_wrong_type(): void
    {
        Storage::fake('public');
        $png = $this->png();
        Http::fake([
            '*IMG_2654.mp4' => Http::response("\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 64), 200),
            '*IMG_2610.png' => Http::response($png, 200),
            '*IMG_2609.jpg' => Http::response('<html>blocked by firewall</html>', 200),
        ]);

        $this->artisan('shop:fetch-brand-media')->assertExitCode(1); // one file was refused

        $this->assertStringEndsWith('.mp4', Setting::get('home_video_path'));
        $this->assertStringEndsWith('.png', Setting::get('perfumarathi_logo_path'));
        Storage::disk('public')->assertExists(Setting::get('home_video_path'));
        Storage::disk('public')->assertExists(Setting::get('home_poster_path'));
        $this->assertNull(Setting::get('fixnote_logo_path')); // HTML instead of an image: not saved
        $this->assertSame(Setting::MEDIA_FALLBACK['fixnote_logo_path'], $this->getJson('/api/store')->json('settings.fixnote_logo_url'));

        // Second run keeps what is stored (no new downloads for those)
        $video = Setting::get('home_video_path');
        $this->artisan('shop:fetch-brand-media');
        $this->assertSame($video, Setting::get('home_video_path'));
    }
}
