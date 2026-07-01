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

function isThumbnailFile(File $file): bool
{
    if (strtolower($file->extension()) !== thumbnailExtension()) {
        return false;
    }

    $name   = $file->name();
    $prefix = thumbnailPrefix();
    $suffix = thumbnailSuffix();

    if ($prefix !== '' && str_starts_with($name, $prefix) === false) {
        return false;
    }

    if ($suffix !== '' && str_ends_with($name, $suffix) === false) {
        return false;
    }

    return $prefix !== '' || $suffix !== '';
}

Kirby::plugin('yolu/video-thumbnail', [
    'options' => [
        'template'  => 'thumb',
        'prefix'    => '',
        'suffix'    => '_thumb',
        'extension' => 'jpg'
    ],
    'blueprints' => [
        'files/thumb' => __DIR__ . '/blueprints/files/thumb.yml'
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
    'hooks' => [
        'file.create:after' => function ($file) {
            if (isThumbnailFile($file) === true) {
                $file->changeTemplate(thumbnailTemplate());
            }
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
