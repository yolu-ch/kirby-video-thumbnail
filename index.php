<?php

use Kirby\Cms\App as Kirby;

Kirby::plugin('yolu/video-thumbnail', [
    'blueprints' => [
        'files/thumb' => __DIR__ . '/blueprints/files/thumb.yml'
    ],
    'hooks' => [
        'file.create:after' => function ($file) {
            if (str_ends_with($file->filename(), '_thumb.jpg')) {
                $file->changeTemplate('thumb');
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
