<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

class RecordAttachmentTypes
{
    public const PERSON_MAIN_IMAGE = 'person-main-image';

    public const PERSON_GALLERY = 'person-gallery';

    public const CASE_EVIDENCE = 'case-evidence';

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
        'mp4' => ['video/mp4'],
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

    public function allowedExtensions(string $purpose): array
    {
        return match ($purpose) {
            self::PERSON_MAIN_IMAGE => array_keys(self::IMAGE_MIMES),
            self::PERSON_GALLERY => [...array_keys(self::IMAGE_MIMES), ...array_keys(self::VIDEO_MIMES)],
            self::CASE_EVIDENCE => [
                ...array_keys(self::IMAGE_MIMES),
                ...array_keys(self::VIDEO_MIMES),
                ...array_keys(self::CASE_DOCUMENT_MIMES),
                ...array_keys(self::AUDIO_MIMES),
            ],
            default => [],
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
        $allowedMimes = $this->allowedMimes($purpose)[$extension] ?? [];

        if (! in_array($extension, $this->allowedExtensions($purpose), true)
            || ! in_array(strtolower($mimeType), $allowedMimes, true)) {
            throw ValidationException::withMessages([
                'file' => 'The detected file type does not match an allowed extension for this record. Choose a supported image, video, audio, or document file.',
            ]);
        }
    }

    public function mediaType(string $category): string
    {
        return in_array($category, ['image', 'video', 'audio'], true) ? $category : 'document';
    }

    private function allowedMimes(string $purpose): array
    {
        $mimes = self::IMAGE_MIMES;

        if (in_array($purpose, [self::PERSON_GALLERY, self::CASE_EVIDENCE], true)) {
            $mimes = [...$mimes, ...self::VIDEO_MIMES];
        }

        if ($purpose === self::CASE_EVIDENCE) {
            $mimes = [...$mimes, ...self::CASE_DOCUMENT_MIMES, ...self::AUDIO_MIMES];
        }

        return $mimes;
    }
}
