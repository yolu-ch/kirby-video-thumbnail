# Kirby Video Thumbnail

A Kirby Panel plugin that automatically generates a JPEG thumbnail from a video frame when uploading a video file. A scrubber lets editors pick the exact frame before the upload is confirmed.

[video-thumbnail.webm](https://github.com/user-attachments/assets/de2a339d-8af4-473d-b855-0d7ee0d027f9)

## Features

- Captures a frame from the video client-side (no server-side processing)
- Live preview of the selected frame in the upload item
- Slider to scrub through the video and choose any frame as the thumbnail
- Thumbnail is uploaded alongside the video as `{videoname}_thumb.jpg`
- Thumbnail file automatically gets the `thumb` template applied via a hook
- Works with any browser-supported video format (MP4, WebM, etc.) — WebM requires Safari 16+

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

## License

MIT — see [LICENSE](LICENSE)
