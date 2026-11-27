<?php

namespace Yolu\VideoThumbnail;

use Kirby\Cms\File;
use Kirby\Panel\Ui\FilePreview;

/**
 * Kirby's video preview in the file view, with the video's
 * thumbnail as the poster of the player.
 */
class VideoFilePreview extends FilePreview
{
    public function __construct(
        public File $file,
        public string $component = 'video-thumbnail-file-preview'
    ) {
    }

    public static function accepts(File $file): bool
    {
        return $file->type() === 'video';
    }

    public function props(): array
    {
        $poster = displayPoster($this->file);

        return [
            ...parent::props(),
            'poster' => $poster?->isAccessible() === true ? $poster->url() : null
        ];
    }
}
