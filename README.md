# Kirby Video Thumbnail

A Kirby Panel plugin that automatically generates a JPEG thumbnail from a video frame when uploading a video file. A scrubber lets editors pick the exact frame before the upload is confirmed.

<img width="847" height="416" alt="preview" src="https://github.com/user-attachments/assets/8f5e79d5-129f-4e45-aad3-20095123b35d" />


## Features

[video-thumbnail 18.41.22.webm](https://github.com/user-attachments/assets/0c427981-420c-4f57-8da2-cefbf615be3b)


## Features

- Captures a frame from the video client-side (no server-side processing)
- Live preview of the selected frame in the upload item
- Slider to scrub through the video and choose any frame as the thumbnail
- Thumbnail is uploaded alongside the video as `{videoname}_thumb.jpg` by default, uploaded independently of the video so it never counts towards a `files` field's `max` (works the same in `files` sections and in `files` fields, including `multiple: false`)
- Thumbnail file automatically gets the `thumb` template applied via a hook by default
- Thumbnail filename (prefix/suffix/extension) and file template are configurable
- Video file automatically gets the `video` template applied, which uses the thumbnail as its Panel preview image instead of the generic video icon
- Thumbnail is deleted automatically when its video is deleted
- Works with any browser-supported video format (MP4, WebM, etc.) — WebM requires Safari 16+
- Video duration (in seconds) is read client-side and stored in the video file's `duration` field

## Requirements

- Kirby 4 or 5
- Modern browser (Canvas API + `<video>` element support)

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

Since the thumbnail is a regular file living next to the video, hide it from your files sections by filtering out its template:

```yaml
files:
  type: files
  query: page.files.filterBy('template', '!=', 'thumb')
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

## License

MIT — see [LICENSE](LICENSE)
