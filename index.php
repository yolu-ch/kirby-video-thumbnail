<?php

namespace Yolu\VideoThumbnail;

use Kirby\Cms\App as Kirby;
use Kirby\Cms\File;

function optionValue(string $key, mixed $default = null): mixed
{
    return option('yolu.video-thumbnail.' . $key, $default);
}

function thumbnailTemplate(): string
{
    $template = optionValue('template', 'thumb');

    return is_string($template) && $template !== '' ? $template : 'thumb';
}

function thumbnailPrefix(): string
{
    $prefix = optionValue('prefix', '');

    return is_scalar($prefix) ? (string)$prefix : '';
}

function thumbnailSuffix(): string
{
    $suffix = optionValue('suffix', '_thumb');

    return is_scalar($suffix) ? (string)$suffix : '_thumb';
}

function thumbnailExtension(): string
{
    $extension = optionValue('extension', 'jpg');
    $extension = is_scalar($extension) ? strtolower(ltrim((string)$extension, '.')) : 'jpg';

    return in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true) ? $extension : 'jpg';
}

function thumbnailOptions(): array
{
    return [
        'template'  => thumbnailTemplate(),
        'prefix'    => thumbnailPrefix(),
        'suffix'    => thumbnailSuffix(),
        'extension' => thumbnailExtension()
    ];
}

/**
 * Builds the thumbnail filename for a given video basename,
 * e.g. "clip" => "clip_thumb.jpg".
 */
function thumbnailFilenameFor(string $videoName): string
{
    return thumbnailPrefix() . $videoName . thumbnailSuffix() . '.' . thumbnailExtension();
}

/**
 * Reverses thumbnailFilenameFor(): given a thumbnail file, returns the
 * basename of the video it belongs to, or null if the name doesn't match
 * the configured prefix/suffix.
 */
function videoNameForThumb(File $file): ?string
{
    $name   = $file->name();
    $prefix = thumbnailPrefix();
    $suffix = thumbnailSuffix();

    if ($prefix !== '') {
        if (str_starts_with($name, $prefix) === false) {
            return null;
        }

        $name = substr($name, strlen($prefix));
    }

    if ($suffix !== '') {
        if (str_ends_with($name, $suffix) === false) {
            return null;
        }

        $name = substr($name, 0, -strlen($suffix));
    }

    return $name !== '' ? $name : null;
}

/**
 * A file is a generated thumbnail when its extension matches, its name
 * matches the configured prefix/suffix, and a sibling video with the
 * derived basename actually exists.
 */
function isThumbnailFile(File $file): bool
{
    if (strtolower($file->extension()) !== thumbnailExtension()) {
        return false;
    }

    $videoName = videoNameForThumb($file);

    if ($videoName === null) {
        return false;
    }

    $parent = $file->parent();

    if ($parent === null) {
        return false;
    }

    return $parent->files()->filter(
        static fn (File $sibling): bool =>
            $sibling->type() === 'video' &&
            $sibling->name() === $videoName
    )->first() instanceof File;
}

Kirby::plugin('yolu/video-thumbnail', [
    'options' => [
        'template'  => 'thumb',
        'prefix'    => '',
        'suffix'    => '_thumb',
        'extension' => 'jpg'
    ],
    'blueprints' => [
        'files/thumb' => __DIR__ . '/blueprints/files/thumb.yml',
        'files/video' => __DIR__ . '/blueprints/files/video.yml'
    ],
    'api' => [
        'routes' => [
            [
                'pattern' => 'video-thumbnail/options',
                'method'  => 'GET',
                'action'  => fn () => thumbnailOptions()
            ]
        ]
    ],
    'fileMethods' => [
        'videoThumb' => function () {
            if (str_starts_with($this->type(), 'video') === false) {
                return null;
            }

            return $this->parent()->file(thumbnailFilenameFor($this->name()));
        }
    ],
    'hooks' => [
        'file.create:after' => function ($file) {
            if (isThumbnailFile($file) === true) {
                $file->changeTemplate(thumbnailTemplate());

                $duration = kirby()->request()->body()->get('duration');
                if ($duration !== null) {
                    $videoName = videoNameForThumb($file);
                    if ($videoName !== null) {
                        $video = $file->parent()->files()
                            ->filterBy('name', $videoName)
                            ->filterBy('type', 'video')
                            ->first();

                        $video?->update(['duration' => round((float) $duration, 2)]);
                    }
                }

                return;
            }

            if (str_starts_with($file->type(), 'video')) {
                $file->changeTemplate('video');
            }
        },
        'file.delete:before' => function ($file) {
            if (str_starts_with($file->type(), 'video') === false) {
                return;
            }

            $thumb = $file->parent()->file(thumbnailFilenameFor($file->name()));
            $thumb?->delete();
        }
    ],
    'translations' => [
        'en' => [
            'video-thumbnail.slider.help' => 'Choose the frame to use as the video thumbnail.'
        ],
        'de' => [
            'video-thumbnail.slider.help' => 'Wähle das Vorschaubild aus, welches aus dem Video generiert werden soll.'
        ]
    ]
]);
