<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

class RecordAttachmentTypes
{
    public const PERSON_MAIN_IMAGE = 'person-main-image';

    public const PERSON_GALLERY = 'person-gallery';

    public const CASE_EVIDENCE = 'case-evidence';

    public const STAFF_GALLERY = 'staff-gallery';

    private const IMAGE_MIMES = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'bmp' => ['image/bmp', 'image/x-ms-bmp'],
        'tif' => ['image/tiff'],
        'tiff' => ['image/tiff'],
        'heic' => ['image/heic', 'image/heic-sequence', 'image/heif'],
        'heif' => ['image/heif', 'image/heic', 'image/heif-sequence'],
        'avif' => ['image/avif'],
    ];

    private const VIDEO_MIMES = [
        'mp4' => ['video/mp4', 'application/mp4', 'application/x-isobmff'],
        'mov' => ['video/quicktime'],
        'avi' => ['video/x-msvideo', 'video/avi'],
        'mkv' => ['video/x-matroska'],
        'webm' => ['video/webm'],
        'wmv' => ['video/x-ms-wmv'],
        'flv' => ['video/x-flv', 'video/flv'],
        'm4v' => ['video/x-m4v', 'video/mp4'],
        '3gp' => ['video/3gpp', 'video/3gpp2'],
        'mpeg' => ['video/mpeg'],
        'mpg' => ['video/mpeg'],
    ];

    private const CASE_DOCUMENT_MIMES = [
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword', 'application/x-ole-storage', 'application/vnd.ms-office'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'txt' => ['text/plain'],
        'rtf' => ['application/rtf', 'text/rtf'],
        'odt' => ['application/vnd.oasis.opendocument.text'],
        'csv' => ['text/csv', 'text/plain', 'application/csv'],
        'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/vnd.ms-office'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'ods' => ['application/vnd.oasis.opendocument.spreadsheet'],
        'ppt' => ['application/vnd.ms-powerpoint', 'application/x-ole-storage', 'application/vnd.ms-office'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation'],
    ];

    private const AUDIO_MIMES = [
        'mp3' => ['audio/mpeg', 'audio/mp3'],
        'wav' => ['audio/wav', 'audio/x-wav', 'audio/vnd.wave'],
        'm4a' => ['audio/mp4', 'audio/x-m4a'],
        'aac' => ['audio/aac', 'audio/x-aac', 'audio/aacp'],
        'ogg' => ['audio/ogg', 'application/ogg'],
    ];

    private const ADDITIONAL_IMAGE_EXTENSIONS = [
        'jpe', 'jfif', 'pjpeg', 'pjp', 'dib', 'hif', 'avifs', 'jxl', 'jp2', 'j2k',
        'jpf', 'jpx', 'jpm', 'ico', 'cur', 'tga', 'pcx', 'psd', 'exr', 'hdr',
        'dds', 'icns', 'wbmp',
    ];

    private const ADDITIONAL_VIDEO_EXTENSIONS = [
        'qt', '3g2', 'ogv', 'mj2', 'ts', 'mts', 'm2ts', 'm2t', 'mpe', 'm1v', 'm2v',
        'mpv', 'vob', 'f4v', 'asf', 'rm', 'rmvb', 'mxf', 'divx',
    ];

    private const AMBIGUOUS_MIMES = [
        'application/octet-stream',
        'application/x-binary',
        'application/x-empty',
        'application/x-unknown',
        'application/unknown',
        'binary/octet-stream',
        'inode/x-empty',
    ];

    private const FALLBACK_MIMES = [
        'jpe' => 'image/jpeg',
        'jfif' => 'image/jpeg',
        'pjpeg' => 'image/jpeg',
        'pjp' => 'image/jpeg',
        'dib' => 'image/bmp',
        'hif' => 'image/heif',
        'avifs' => 'image/avif',
        'jxl' => 'image/jxl',
        'jp2' => 'image/jp2',
        'j2k' => 'image/jp2',
        'jpf' => 'image/jp2',
        'jpx' => 'image/jp2',
        'jpm' => 'image/jpm',
        'ico' => 'image/vnd.microsoft.icon',
        'cur' => 'image/vnd.microsoft.icon',
        'tga' => 'image/x-targa',
        'pcx' => 'image/x-pcx',
        'psd' => 'image/vnd.adobe.photoshop',
        'exr' => 'image/x-exr',
        'hdr' => 'image/vnd.radiance',
        'dds' => 'image/vnd-ms.dds',
        'icns' => 'image/icns',
        'wbmp' => 'image/vnd.wap.wbmp',
        'qt' => 'video/quicktime',
        '3g2' => 'video/3gpp2',
        'ogv' => 'video/ogg',
        'mj2' => 'video/mj2',
        'ts' => 'video/mp2t',
        'mts' => 'video/mp2t',
        'm2ts' => 'video/mp2t',
        'm2t' => 'video/mp2t',
        'mpe' => 'video/mpeg',
        'm1v' => 'video/mpeg',
        'm2v' => 'video/mpeg',
        'mpv' => 'video/mpeg',
        'vob' => 'video/mpeg',
        'f4v' => 'video/x-f4v',
        'asf' => 'video/x-ms-asf',
        'rm' => 'video/vnd.rn-realvideo',
        'rmvb' => 'video/vnd.rn-realvideo',
        'mxf' => 'video/x-mxf',
        'divx' => 'video/divx',
    ];

    public function allowedExtensions(string $purpose): array
    {
        return match ($purpose) {
            self::PERSON_MAIN_IMAGE => $this->imageExtensions(),
            self::PERSON_GALLERY => [...$this->imageExtensions(), ...$this->videoExtensions()],
            self::STAFF_GALLERY => [...$this->imageExtensions(), ...$this->videoExtensions()],
            self::CASE_EVIDENCE => [
                ...$this->imageExtensions(),
                ...$this->videoExtensions(),
                ...array_keys(self::CASE_DOCUMENT_MIMES),
                ...array_keys(self::AUDIO_MIMES),
            ],
            default => [],
        };
    }

    public function storageDirectory(?string $purpose): ?string
    {
        return match ($purpose) {
            null => null,
            self::PERSON_MAIN_IMAGE => 'people/main',
            self::PERSON_GALLERY => 'people/gallery',
            self::STAFF_GALLERY => 'staff/gallery',
            self::CASE_EVIDENCE => 'cases/evidence',
            default => throw new \InvalidArgumentException('The upload attachment purpose is not supported.'),
        };
    }

    public function acceptAttribute(string $purpose): string
    {
        return implode(',', array_map(
            static fn (string $extension): string => '.'.$extension,
            $this->allowedExtensions($purpose),
        ));
    }

    public function assertAllowedExtension(string $filename, string $purpose): void
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (! in_array($extension, $this->allowedExtensions($purpose), true)) {
            throw ValidationException::withMessages([
                'file' => 'This file extension is not allowed for the selected record. Choose a supported file type.',
            ]);
        }
    }

    public function assertAllowedFile(string $filename, string $mimeType, string $purpose): void
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mimeType = strtolower($mimeType);
        $isAllowedImage = $this->allowsImage($purpose)
            && $this->isImageExtension($extension)
            && str_starts_with($mimeType, 'image/')
            && ! str_contains($mimeType, 'svg');
        $isAllowedVideo = $this->allowsVideo($purpose)
            && $this->isVideoExtension($extension)
            && str_starts_with($mimeType, 'video/');
        $allowedMimes = $this->allowedMimes($purpose)[$extension] ?? [];
        $isAllowedMappedType = in_array($extension, $this->allowedExtensions($purpose), true)
            && in_array($mimeType, $allowedMimes, true);

        if (! $isAllowedImage && ! $isAllowedVideo && ! $isAllowedMappedType) {
            throw ValidationException::withMessages([
                'file' => 'The detected file type does not match an allowed extension for this record. Choose a supported image, video, audio, or document file.',
            ]);
        }
    }

    public function normalizeDetectedMimeType(string $filename, string $mimeType, string $path): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mimeType = strtolower($mimeType);

        if ($extension === 'mp4'
            && in_array($mimeType, ['application/mp4', 'application/x-isobmff'], true)) {
            return 'video/mp4';
        }

        if (! in_array($mimeType, self::AMBIGUOUS_MIMES, true)) {
            return $mimeType;
        }

        if ($extension === 'mp4') {
            $header = file_get_contents($path, false, null, 0, 16);
            $fileSize = filesize($path);
            if (is_string($header)
                && strlen($header) >= 16
                && is_int($fileSize)) {
                $boxSize = unpack('Nsize', substr($header, 0, 4))['size'];
                $majorBrand = substr($header, 8, 4);
                if ($boxSize >= 16
                    && $boxSize <= $fileSize
                    && substr($header, 4, 4) === 'ftyp'
                    && preg_match('/^[A-Za-z0-9 ]{4}$/D', $majorBrand) === 1) {
                    return 'video/mp4';
                }
            }
        }

        $mappedMimeTypes = [
            ...self::IMAGE_MIMES,
            ...self::VIDEO_MIMES,
            ...self::CASE_DOCUMENT_MIMES,
            ...self::AUDIO_MIMES,
        ];
        if (isset($mappedMimeTypes[$extension][0])) {
            return $mappedMimeTypes[$extension][0];
        }

        if (isset(self::FALLBACK_MIMES[$extension])) {
            return self::FALLBACK_MIMES[$extension];
        }

        return $mimeType;
    }

    public function mediaType(string $category): string
    {
        return in_array($category, ['image', 'video', 'audio'], true) ? $category : 'document';
    }

    private function imageExtensions(): array
    {
        return [...array_keys(self::IMAGE_MIMES), ...self::ADDITIONAL_IMAGE_EXTENSIONS];
    }

    private function videoExtensions(): array
    {
        return [...array_keys(self::VIDEO_MIMES), ...self::ADDITIONAL_VIDEO_EXTENSIONS];
    }

    private function isImageExtension(string $extension): bool
    {
        return in_array($extension, $this->imageExtensions(), true);
    }

    private function isVideoExtension(string $extension): bool
    {
        return in_array($extension, $this->videoExtensions(), true);
    }

    private function allowsImage(string $purpose): bool
    {
        return in_array($purpose, [
            self::PERSON_MAIN_IMAGE,
            self::PERSON_GALLERY,
            self::STAFF_GALLERY,
            self::CASE_EVIDENCE,
        ], true);
    }

    private function allowsVideo(string $purpose): bool
    {
        return in_array($purpose, [
            self::PERSON_GALLERY,
            self::STAFF_GALLERY,
            self::CASE_EVIDENCE,
        ], true);
    }

    private function allowedMimes(string $purpose): array
    {
        $mimes = self::IMAGE_MIMES;

        if (in_array($purpose, [self::PERSON_GALLERY, self::STAFF_GALLERY, self::CASE_EVIDENCE], true)) {
            $mimes = [...$mimes, ...self::VIDEO_MIMES];
        }

        if ($purpose === self::CASE_EVIDENCE) {
            $mimes = [...$mimes, ...self::CASE_DOCUMENT_MIMES, ...self::AUDIO_MIMES];
        }

        return $mimes;
    }
}
