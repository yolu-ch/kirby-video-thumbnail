<?php

use Kirby\Cms\App as Kirby;

Kirby::plugin('yolu/video-thumbnail', [
    'blueprints' => [
        'files/thumb' => __DIR__ . '/blueprints/files/thumb.yml',
        'files/video' => __DIR__ . '/blueprints/files/video.yml'
    ],
    'fileMethods' => [
        'videoThumb' => function () {
            if (str_starts_with($this->type(), 'video') === false) {
                return null;
            }

            return $this->parent()->file($this->name() . '_thumb.jpg');
        }
    ],
    'hooks' => [
        'file.create:after' => function ($file) {
            if (str_ends_with($file->filename(), '_thumb.jpg')) {
                $file->changeTemplate('thumb');

                $duration = kirby()->request()->body()->get('duration');
                if ($duration !== null) {
                    $baseName = substr($file->filename(), 0, -strlen('_thumb.jpg'));
                    $video = $file->parent()->files()
                        ->filterBy('name', $baseName)
                        ->filterBy('type', 'video')
                        ->first();

                    $video?->update(['duration' => round((float) $duration, 2)]);
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

            $thumb = $file->parent()->file($file->name() . '_thumb.jpg');
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
