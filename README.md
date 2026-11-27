# Kirby Video Thumbnail

A Kirby Panel plugin that automatically generates a JPEG thumbnail from a video frame when uploading a video file. A scrubber lets editors pick the exact frame before the upload is confirmed.

<img width="847" height="416" alt="preview" src="https://github.com/user-attachments/assets/8f5e79d5-129f-4e45-aad3-20095123b35d" />


## Features

[video-thumbnail.webm](https://github.com/user-attachments/assets/8eec8345-3dc2-44fd-843d-1d3a888cec0b)


## Features

- Captures a frame from the video client-side (no server-side processing)
- Live preview of the selected frame in the upload item
- Slider to scrub through the video and choose any frame as the thumbnail
- Thumbnail is uploaded alongside the video as `{videoname}_thumb.jpg` by default, uploaded independently of the video so it never counts towards a `files` field's `max` (works the same in `files` sections and in `files` fields, including `multiple: false`)
- New thumbnails receive the configured template and store their video’s UUID
- Thumbnail filename (prefix/suffix/extension) and file template are configurable
- Video file automatically gets the `video` template applied, which uses the thumbnail as its Panel preview image instead of the generic video icon
- The video's file view uses the thumbnail as the player's poster
- **Choose poster frame** in the video's file view replaces the thumbnail later and keeps its UUID and metadata
- Thumbnail is renamed with its video and deleted automatically when its video is deleted
- Works with any browser-supported video format (MP4, WebM, etc.) — WebM requires Safari 16+
- Video duration (in seconds) is read client-side and stored in the video file's `duration` field

## Requirements

- Kirby 6 (PHP 8.3+)
- Modern browser (Canvas API + `<video>` element support)

> **Using Kirby 4 or 5?** Version 2 targets Kirby 6 only, since the Kirby 6 Panel runs on Vue 3. Use the 1.x releases instead: `composer require yolu/kirby-video-thumbnail:^1.3`

## Installation

### Composer (recommended)

```bash
composer require yolu/kirby-video-thumbnail
```

### Manual

Download or clone this repository and place it in `/site/plugins/video-thumbnail`.

## Usage

The plugin works automatically. When a video is uploaded through the Kirby Panel, a thumbnail is generated from the first frame. Use the scrubber that appears below the upload item to select a different frame before confirming the upload.

The thumbnail file is named `{videoname}_thumb.jpg` and receives the `thumb` file template, which you can extend in your own blueprints:

```yaml
# site/blueprints/files/thumb.yml
extends: files/thumb

fields:
  alt:
    type: text
```

The video file itself receives the `video` file template, which you can extend the same way:

```yaml
# site/blueprints/files/video.yml
extends: files/video

fields:
  caption:
    type: text
```

Open the video in the Panel and choose **Choose poster frame** to replace its thumbnail. Replacing a frame keeps the thumbnail's UUID and metadata. If your video blueprint does not extend `files/video`, add the button to its view buttons and use the thumbnail as its preview image:

```yaml
# site/blueprints/files/video.yml
buttons:
  - open
  - videoPoster
  - settings
  - languages

image:
  query: file.videoThumb
```

Use `$video->videoThumb()?->url()` in templates. Thumbnails from earlier versions are still displayed. The frame dialog asks before it adopts one. Other images at the thumbnail's filename are kept and reported as conflicts.

Since the thumbnail is a regular file living next to the video, hide it from your file lists:

```yaml
files:
  type: filelist
  query: model.files.filterBy("isManagedVideoPoster", false)
```

## Options

You can configure the generated thumbnail filename and file template in `site/config/config.php`:

```php
<?php

return [
    'yolu.video-thumbnail.template'  => 'thumb',
    'yolu.video-thumbnail.prefix'    => '',
    'yolu.video-thumbnail.suffix'    => '_thumb',
    'yolu.video-thumbnail.extension' => 'jpg'
];
```

With the defaults, a video named `example.mp4` creates `example_thumb.jpg`. For example, this configuration creates `poster-example.webp` and applies the `poster` file template:

```php
<?php

return [
    'yolu.video-thumbnail.template'  => 'poster',
    'yolu.video-thumbnail.prefix'    => 'poster-',
    'yolu.video-thumbnail.suffix'    => '',
    'yolu.video-thumbnail.extension' => 'webp'
];
```

Supported thumbnail extensions are `jpg`, `jpeg`, `png` and `webp`.

If `prefix` and `suffix` are both empty, the generated thumbnail keeps the video's basename. For example, `example.mp4` creates `example.jpg`.

## Development

Building the Panel bundle requires Node.js 24+ ([kirbyup](https://github.com/johannschopplich/kirbyup) 4).

```bash
npm install
npm run dev    # dev server with HMR (writes index.dev.js)
npm run build  # production build (index.js / index.css)
```

## License

MIT — see [LICENSE](LICENSE)
