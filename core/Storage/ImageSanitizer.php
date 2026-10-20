<?php

declare(strict_types=1);

namespace Kabuto\Storage;

use RuntimeException;

/**
 * Sanitizer untuk image metadata dan re-encoding.
 * Menghilangkan EXIF/IPTC/XMP dan menulis ulang image melalui GD.
 */
final class ImageSanitizer
{
    /**
     * Sanitize gambar: hapus metadata, normalisasi orientasi, re-encode.
     *
     * @param string $sourcePath Path file gambar sumber
     * @param string $targetPath Path target output (boleh sama dengan source)
     * @param int $quality Kualitas JPEG (0-100)
     * @return bool
     * @throws RuntimeException
     */
    public function sanitize(string $sourcePath, string $targetPath, int $quality = 85): bool
    {
        if (!is_file($sourcePath)) {
            throw new RuntimeException(sprintf('Source image [%s] does not exist.', $sourcePath));
        }

        if (!extension_loaded('gd')) {
            throw new RuntimeException('GD extension is required for image sanitization.');
        }

        $imageType = @exif_imagetype($sourcePath);
        if ($imageType === false) {
            throw new RuntimeException('Cannot determine image type.');
        }

        $image = match ($imageType) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($sourcePath),
            IMAGETYPE_PNG => @imagecreatefrompng($sourcePath),
            IMAGETYPE_GIF => @imagecreatefromgif($sourcePath),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : false,
            default => false,
        };

        if ($image === false) {
            throw new RuntimeException('Cannot load image with GD.');
        }

        if (in_array($imageType, [IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }

        $orientation = 1;
        if (extension_loaded('exif') && $imageType === IMAGETYPE_JPEG) {
            $exif = @exif_read_data($sourcePath);
            if (is_array($exif) && isset($exif['Orientation'])) {
                $orientation = (int) $exif['Orientation'];
            }
        }

        $rotated = $this->rotateByOrientation($image, $orientation);
        if ($rotated === false) {
            imagedestroy($image);
            throw new RuntimeException('Cannot normalize image orientation.');
        }
        if ($rotated !== $image) imagedestroy($image);
        $image = $rotated;

        $success = match ($imageType) {
            IMAGETYPE_JPEG => imagejpeg($image, $targetPath, $quality),
            IMAGETYPE_PNG => imagepng($image, $targetPath, (int) round((100 - $quality) / 11)),
            IMAGETYPE_GIF => imagegif($image, $targetPath),
            IMAGETYPE_WEBP => function_exists('imagewebp') ? imagewebp($image, $targetPath, $quality) : false,
            default => false,
        };

        imagedestroy($image);

        if ($success === false) {
            throw new RuntimeException('Failed to write sanitized image.');
        }

        return true;
    }

    /**
     * @param resource|\GdImage $image
     * @return resource|\GdImage|false
     */
    private function rotateByOrientation($image, int $orientation)
    {
        return match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };
    }
}
