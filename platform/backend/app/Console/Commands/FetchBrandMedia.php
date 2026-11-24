<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * php artisan shop:fetch-brand-media
 *
 * Copies the homepage video, poster and brand logos (Setting::MEDIA_FALLBACK, files on perfumarathi.com)
 * into this site's storage, so the new site no longer loads them from the live site.
 * Read-only for the live site: plain HTTPS downloads, like a visitor's browser.
 * Media the admin already uploaded is never replaced.
 */
class FetchBrandMedia extends Command
{
    protected $signature = 'shop:fetch-brand-media {--force : replace media that is already stored here}';

    protected $description = 'Copy the homepage video and brand logos into this site';

    private const LIMITS = ['video' => 150 * 1024 * 1024, 'image' => 15 * 1024 * 1024];

    private const TYPES = [
        'video/mp4' => 'mp4', 'video/webm' => 'webm',
        'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp',
    ];

    public function handle(): int
    {
        $failed = 0;
        $downloads = []; // url => [tmp file, mime] (the poster and the Perfumarathi logo are the same file)
        try {
            foreach (Setting::MEDIA_FALLBACK as $key => $url) {
                $current = Setting::get($key);
                if ($current && ! $this->option('force') && Storage::disk('public')->exists($current)) {
                    $this->line("  $key: already stored ($current), kept");

                    continue;
                }
                $kind = str_contains($key, 'video') ? 'video' : 'image';
                try {
                    [$tmp, $mime] = $downloads[$url] ??= $this->download($url, $kind);
                    if (! str_starts_with($mime, $kind.'/')) {
                        throw new \RuntimeException("not a $kind file ($mime)");
                    }
                    // Each setting gets its own copy: replacing one later must not delete the other.
                    $path = 'branding/'.Str::random(24).'.'.self::TYPES[$mime];
                    $stream = fopen($tmp, 'r');
                    Storage::disk('public')->put($path, $stream);
                    is_resource($stream) && fclose($stream);
                    if ($current && $current !== $path) {
                        Storage::disk('public')->delete($current);
                    }
                    Setting::put([$key => $path]);
                    $this->info("  $key: saved ".round(filesize($tmp) / 1024)." KB as $path");
                } catch (Throwable $e) {
                    $failed++;
                    $this->warn("  $key: not copied ({$e->getMessage()}). The site keeps using $url");
                }
            }
        } finally {
            foreach ($downloads as [$tmp]) {
                @unlink($tmp);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return array{0: string, 1: string} temp file and its detected type */
    private function download(string $url, string $kind): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pmmedia');
        try {
            $res = Http::timeout(180)->withOptions(['sink' => $tmp])->withHeaders(['User-Agent' => 'Perfumarathi-shop/1.0'])->get($url);
            if (! $res->successful()) {
                throw new \RuntimeException('HTTP '.$res->status());
            }
            clearstatcache(true, $tmp);
            $size = filesize($tmp);
            if ($size === false || $size === 0 || $size > self::LIMITS[$kind]) {
                throw new \RuntimeException('unexpected size ('.(int) $size.' bytes)');
            }
            $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
            if (! isset(self::TYPES[$mime])) {
                throw new \RuntimeException("not a supported file ($mime)");
            }
            if (str_starts_with($mime, 'image/') && @getimagesize($tmp) === false) {
                throw new \RuntimeException('damaged image');
            }

            return [$tmp, $mime];
        } catch (Throwable $e) {
            @unlink($tmp);
            throw $e;
        }
    }
}
