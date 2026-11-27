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

require_once __DIR__ . '/poster.php';

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
            ],
            [
                'pattern' => 'video-thumbnail/frame/(:any)',
                'method' => 'POST',
                'action' => function (string $id) {
                    $video = kirby()->file('file://' . $id);
                    if (!$video || $video->type() !== 'video' || !$video->isAccessible()) {
                        throw new \Kirby\Exception\NotFoundException();
                    }
                    $recent = kirby()->session()->get('video-thumbnail.uploads', []);
                    if (!$video->guards()->isAvailable('replace') && ($recent[$id] ?? 0) < time()) {
                        throw new \Kirby\Exception\PermissionException();
                    }
                    return $this->upload(
                        callback: function (string $source) use ($video) {
                            try {
                                return saveFrame($video, $source, $this->requestBody('duration'), $this->requestBody('adopt') === 'true');
                            } finally {
                                \Kirby\Filesystem\F::remove($source);
                            }
                        },
                        single: true
                    );
                }
            ]
        ]
    ],
    'areas' => [
        'video-thumbnail' => fn () => [
            'buttons' => [
                'file.videoPoster' => function ($model) {
                    if (!$model instanceof File || $model->type() !== 'video' || !$model->guards()->isAvailable('replace') || !$model->uuid()) {
                        return null;
                    }
                    return [
                        'text' => t('video-thumbnail.frame'), 'icon' => 'image',
                        'dialog' => 'video-thumbnail/frame/' . $model->uuid()->id()
                    ];
                }
            ],
            'dialogs' => [
                'video-thumbnail/frame/(:any)' => [
                    'load' => function (string $id) {
                        $video = kirby()->file('file://' . $id);
                        if (!$video || $video->type() !== 'video' || !$video->isAccessible() || !$video->guards()->isAvailable('replace')) {
                            throw new \Kirby\Exception\PermissionException();
                        }
                        return [
                            'component' => 'video-thumbnail-frame-dialog',
                            'props' => [
                                'id' => $id,
                                'url' => $video->url(),
                                'mime' => $video->mime(),
                                'legacy' => displayPoster($video) !== null && !ownedPoster($video),
                                'extension' => thumbnailExtension()
                            ]
                        ];
                    }
                ]
            ]
        ]
    ],
    'fileMethods' => [
        'videoThumb' => function () {
            return $this->type() === 'video' ? displayPoster($this) : null;
        },
        'isManagedVideoPoster' => function () {
            return isManagedPoster($this);
        }
    ],
    'hooks' => [
        'file.create:after' => function (File $file) {
            if ($file->type() !== 'video') {
                return $file;
            }
            if ($file->template() !== 'video') {
                $file = $file->kirby()->impersonate('kirby', fn () => $file->update(['template' => 'video']));
            }
            if ($file->uuid() && $file->kirby()->user()) {
                $session = $file->kirby()->session();
                $recent = array_filter($session->get('video-thumbnail.uploads', []), fn ($expiry) => $expiry >= time());
                $recent[$file->uuid()->id()] = time() + 900;
                $session->set('video-thumbnail.uploads', $recent);
            }
            return $file;
        },
        'file.changeName:before' => function (File $file, string $name) {
            if (!PosterRename::allows($file) && isManagedPoster($file)) {
                throw new \Kirby\Exception\LogicException(message: t('video-thumbnail.rename'));
            }
            if ($file->type() === 'video' && $file->name() !== $name) {
                ensureDestination($file, $name);
            }
        },
        'file.changeName:after' => function (File $oldFile, File $newFile) {
            if ($newFile->type() === 'video' && $oldFile->name() !== $newFile->name()) {
                // Kirby has moved the UUID to the renamed video. Its poster still has the old name.
                $poster = ownedPoster($newFile, recover: true, videoName: $oldFile->name());
                if ($poster) {
                    PosterRename::with($poster, fn () => $poster->kirby()->impersonate('kirby', fn () => $poster->changeName(pathinfo(thumbnailFilenameFor($newFile->name()), PATHINFO_FILENAME))));
                }
            }
        },
        'file.delete:after' => function (File $file) {
            if ($file->type() === 'video' && $poster = ownedPoster($file, true)) {
                $file->kirby()->impersonate('kirby', fn () => $poster->delete());
            }
        }
    ],
    'translations' => [
        'en' => [
            'video-thumbnail.position' => 'Position (seconds)',
            'video-thumbnail.decodeError' => 'The browser could not load a frame from this video.',
            'video-thumbnail.adopt' => 'Replace the existing poster and manage it with this video',
            'video-thumbnail.namingChanged' => 'The poster uses an earlier naming configuration. Restore those options before replacing its frame.',
            'video-thumbnail.frame' => 'Choose poster frame',
            'video-thumbnail.rename' => 'Rename the video to rename its poster.',
            'video-thumbnail.collision' => 'Another file uses this poster name. Resolve the conflict in Files.',
            'video-thumbnail.slider.help' => 'Choose the frame to use as the video thumbnail.'
        ],
        'de' => [
            'video-thumbnail.position' => 'Position (Sekunden)',
            'video-thumbnail.decodeError' => 'Der Browser konnte kein Standbild aus diesem Video laden.',
            'video-thumbnail.adopt' => 'Vorhandenes Standbild ersetzen und mit diesem Video verwalten',
            'video-thumbnail.namingChanged' => 'Das Standbild verwendet frühere Namenseinstellungen. Stelle sie vor dem Ersetzen wieder her.',
            'video-thumbnail.frame' => 'Standbild wählen',
            'video-thumbnail.rename' => 'Benenne das Video um, um sein Standbild umzubenennen.',
            'video-thumbnail.collision' => 'Eine andere Datei verwendet diesen Standbildnamen. Prüfe die Dateien.',
            'video-thumbnail.slider.help' => 'Wähle das Vorschaubild aus, welches aus dem Video generiert werden soll.'
        ]
    ]
]);
