<?php

namespace Classes;

use RuntimeException;

class IdentityImageService
{
    private const MAX_BYTES = 8 * 1024 * 1024;
    private const MAX_PIXELS = 16000000;
    private const MAX_DIMENSION = 1800;
    private const JPEG_QUALITY = 82;

    /**
     * Procesa una imagen recibida mediante $_FILES.
     *
     * $storageRoot debe apuntar a una carpeta privada,
     * fuera de public_html.
     *
     * Devuelve la ruta relativa y el SHA-256.
     */
    public static function save(
        array $file,
        string $storageRoot
    ): array {
        if (!extension_loaded('gd')) {
            throw new RuntimeException(
                'La extensión GD no está habilitada.'
            );
        }

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException(
                'No se recibió correctamente la imagen.'
            );
        }

        $tmp = $file['tmp_name'] ?? '';

        if (!is_uploaded_file($tmp)) {
            throw new RuntimeException('Archivo no válido.');
        }

        $size = filesize($tmp);

        if (!$size || $size > self::MAX_BYTES) {
            throw new RuntimeException(
                'La imagen debe pesar máximo 8 MB.'
            );
        }

        $info = @getimagesize($tmp);

        if (!$info) {
            throw new RuntimeException('La imagen no es válida.');
        }

        [$width, $height] = $info;

        if (
            $width < 1 ||
            $height < 1 ||
            ($width * $height) > self::MAX_PIXELS
        ) {
            throw new RuntimeException(
                'Las dimensiones de la imagen no son válidas.'
            );
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))
            ->file($tmp);

        $source = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($tmp),
            'image/png' => @imagecreatefrompng($tmp),
            'image/webp' => @imagecreatefromwebp($tmp),
            default => false,
        };

        if ($source === false) {
            throw new RuntimeException(
                'Solo se permiten imágenes JPG, PNG o WebP.'
            );
        }

        $scale = min(
            1,
            self::MAX_DIMENSION / max($width, $height)
        );

        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $image = imagecreatetruecolor(
            $newWidth,
            $newHeight
        );

        if ($image === false) {
            imagedestroy($source);
            throw new RuntimeException(
                'No se pudo procesar la imagen.'
            );
        }

        imagefill(
            $image,
            0,
            0,
            imagecolorallocate($image, 255, 255, 255)
        );

        imagecopyresampled(
            $image,
            $source,
            0,
            0,
            0,
            0,
            $newWidth,
            $newHeight,
            $width,
            $height
        );

        imagedestroy($source);

        $storageRoot = rtrim($storageRoot, '/');
        $folder = 'identity/' . date('Y/m');
        $directory = $storageRoot . '/' . $folder;

        if (
            !is_dir($directory) &&
            !mkdir($directory, 0700, true) &&
            !is_dir($directory)
        ) {
            imagedestroy($image);
            throw new RuntimeException(
                'No se pudo crear el directorio privado.'
            );
        }

        $fileName = bin2hex(random_bytes(24)) . '.jpg';
        $relativePath = $folder . '/' . $fileName;
        $fullPath = $storageRoot . '/' . $relativePath;

        $saved = imagejpeg(
            $image,
            $fullPath,
            self::JPEG_QUALITY
        );

        imagedestroy($image);

        if (!$saved) {
            @unlink($fullPath);
            throw new RuntimeException(
                'No se pudo guardar la imagen.'
            );
        }

        chmod($fullPath, 0600);

        return [
            'path' => $relativePath,
            'sha256' => hash_file('sha256', $fullPath),
            'size' => filesize($fullPath),
            'mime' => 'image/jpeg',
            'width' => $newWidth,
            'height' => $newHeight,
        ];
    }
}
