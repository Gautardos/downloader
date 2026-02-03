<?php

namespace App\Service;

class MediaTypeHelper
{
    public const TYPE_VIDEO = 'video';
    public const TYPE_TEXT = 'text';
    public const TYPE_EBOOK = 'ebook';
    public const TYPE_ARCHIVE = 'archive';
    public const TYPE_IMAGE = 'image'; // Disk images/ISO
    public const TYPE_PHOTO = 'photo';
    public const TYPE_AUDIO = 'audio';
    public const TYPE_OTHER = 'other';

    private const EXTENSIONS = [
        self::TYPE_VIDEO => ['mp4', 'mkv', 'avi', 'wmv', 'mov', 'flv', 'mpg', 'mpeg', 'm4v', '3gp'],
        self::TYPE_TEXT => ['nfo', 'txt', 'md', 'log', 'srt', 'sub'],
        self::TYPE_EBOOK => ['epub', 'cbz', 'cbr', 'pdf', 'mobi', 'azw3'],
        self::TYPE_ARCHIVE => ['zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz'],
        self::TYPE_IMAGE => ['iso', 'img', 'bin', 'cue', 'mdf'],
        self::TYPE_PHOTO => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff'],
        self::TYPE_AUDIO => ['mp3', 'flac', 'wav', 'm4a', 'ogg', 'opus', 'wma', 'aac'],
    ];

    private const ICONS = [
        self::TYPE_VIDEO => '🎥',
        self::TYPE_TEXT => '📄',
        self::TYPE_EBOOK => '📚',
        self::TYPE_ARCHIVE => '📦',
        self::TYPE_IMAGE => '💿',
        self::TYPE_PHOTO => '🖼️',
        self::TYPE_AUDIO => '🎵',
        self::TYPE_OTHER => '❓',
    ];

    public function getType(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        foreach (self::EXTENSIONS as $type => $extensions) {
            if (in_array($ext, $extensions)) {
                return $type;
            }
        }

        return self::TYPE_OTHER;
    }

    public function getIcon(string $type): string
    {
        return self::ICONS[$type] ?? self::ICONS[self::TYPE_OTHER];
    }

    /**
     * Map internal type to a user friendly label
     */
    public function getLabel(string $type): string
    {
        return ucfirst($type);
    }
}
