<?php

namespace App\Services;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;
use Throwable;

/**
 * Полностью локальная генерация QR-кодов (chillerlan/php-qrcode).
 * Данные (включая TOTP-секреты) никогда не покидают сервер.
 */
class QrService
{
    /**
     * Генерирует QR-код в виде SVG-строки (<svg ...>...</svg>).
     * $size задаёт атрибуты width/height в пикселях.
     */
    public static function generateQrSvg(string $data, int $size = 200): ?string
    {
        if ($data === '') {
            return null;
        }

        try {
            $options = new QROptions([
                'outputInterface' => QRMarkupSVG::class,
                'outputBase64'    => false,
                'eccLevel'        => EccLevel::M,
                'addQuietzone'    => true,
                'svgAddXmlHeader' => false,
            ]);

            $svg = (new QRCode($options))->render($data);
        } catch (Throwable $e) {
            error_log('QrService: генерация QR не удалась: ' . $e->getMessage());
            return null;
        }

        if (!is_string($svg) || $svg === '') {
            return null;
        }

        // Задаём явные размеры, чтобы <img> отображал корректный масштаб
        $size = max(50, min(1000, $size));
        $svg = preg_replace(
            '/<svg\b/',
            sprintf('<svg width="%d" height="%d"', $size, $size),
            $svg,
            1
        );

        return $svg;
    }

    /**
     * Возвращает data-URI (data:image/svg+xml;base64,...) — подходит для <img src>.
     */
    public static function toBase64Src(string $data, int $size = 200): string
    {
        $svg = self::generateQrSvg($data, $size);
        if ($svg === null) {
            return '';
        }
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
