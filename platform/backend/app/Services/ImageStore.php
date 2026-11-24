<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Saves uploaded images on the public disk. Files are re-encoded with GD when available, which strips
 * metadata (EXIF/GPS) and any non-image payload, and a smaller copy is made for product grids.
 */
class ImageStore
{
    public const MAX = 1800;

    public const THUMB = 600;

    /** @return array{path:string,thumb_path:?string} */
    public function store(UploadedFile $file, string $folder): array
    {
        return $this->storeBytes((string) file_get_contents($file->getRealPath()), $folder);
    }

    /** @return array{path:string,thumb_path:?string} */
    public function storeBytes(string $bytes, string $folder): array
    {
        $info = @getimagesizefromstring($bytes);
        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
            throw new \InvalidArgumentException('Not a supported image (use JPG, PNG or WebP).');
        }
        $dir = trim($folder, '/').'/'.date('Y/m');
        $name = Str::random(24);

        if (function_exists('imagecreatefromstring') && ($src = @imagecreatefromstring($bytes))) {
            $path = $dir.'/'.$name.'.jpg';
            $thumb = $dir.'/'.$name.'-t.jpg';
            $hasAlpha = $info[2] === IMAGETYPE_PNG || $info[2] === IMAGETYPE_WEBP;
            if ($hasAlpha && function_exists('imagewebp')) {
                $path = $dir.'/'.$name.'.webp';
                $thumb = $dir.'/'.$name.'-t.webp';
            }
            Storage::disk('public')->put($path, $this->encode($src, self::MAX, $hasAlpha));
            Storage::disk('public')->put($thumb, $this->encode($src, self::THUMB, $hasAlpha));
            imagedestroy($src);

            return ['path' => $path, 'thumb_path' => $thumb];
        }

        // No GD on this server: keep the original file (already verified to be an image).
        $ext = image_type_to_extension($info[2], false) ?: 'jpg';
        $path = $dir.'/'.$name.'.'.($ext === 'jpeg' ? 'jpg' : $ext);
        Storage::disk('public')->put($path, $bytes);

        return ['path' => $path, 'thumb_path' => null];
    }

    private function encode(\GdImage $src, int $max, bool $alpha): string
    {
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1, $max / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        if ($alpha && function_exists('imagewebp')) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        } else {
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start();
        if ($alpha && function_exists('imagewebp')) {
            imagewebp($dst, null, 85);
        } else {
            imageinterlace($dst, true);
            imagejpeg($dst, null, 85);
        }
        imagedestroy($dst);

        return (string) ob_get_clean();
    }

    public function delete(?string ...$paths): void
    {
        foreach (array_filter($paths) as $p) {
            Storage::disk('public')->delete($p);
        }
    }
}
