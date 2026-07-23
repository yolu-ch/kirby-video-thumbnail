<template>
    <a v-if="!isVideo" class="k-upload-item-preview" :href="url" target="_blank">
        <k-image
            v-if="isPreviewable"
            :cover="cover"
            :src="url"
            :back="back ?? 'pattern'"
        />
        <k-icon-frame
            v-else
            :color="color ?? fallbackColor"
            :icon="icon ?? fallbackIcon"
            :back="back ?? 'black'"
            ratio="1/1"
        />
    </a>
    <div v-else class="k-upload-item-preview k-upload-item-preview--video">
        <canvas ref="canvasEl" v-show="hasFrame" class="k-upload-item-canvas" />
        <k-icon-frame
            v-show="!hasFrame"
            color="yellow-500"
            icon="video"
            back="black"
            ratio="16/9"
        />
    </div>
</template>

<script>
export default {
    props: {
        back: String,
        color: String,
        cover: { type: Boolean, default: true },
        icon: String,
        type: String,
        url: String
    },

    data() {
        return { hasFrame: false };
    },

    computed: {
        isVideo() {
            return this.type?.startsWith('video/');
        },
        isPreviewable() {
            return [
                'image/jpeg', 'image/jpg', 'image/gif',
                'image/png', 'image/webp', 'image/avif', 'image/svg+xml'
            ].includes(this.type);
        },
        fallbackColor() {
            if (this.type?.startsWith('image/')) return 'orange-500';
            if (this.type?.startsWith('audio/')) return 'aqua-500';
            if (this.type?.startsWith('video/')) return 'yellow-500';
            return 'white';
        },
        fallbackIcon() {
            if (this.type?.startsWith('image/')) return 'image';
            if (this.type?.startsWith('audio/')) return 'audio';
            if (this.type?.startsWith('video/')) return 'video';
            return 'file';
        }
    },

    mounted() {
        if (this.isVideo && this.url) this.captureFrame(this.url);
        window.addEventListener('vt:seek-preview', this.onSeekPreview);
        window.addEventListener('vt:capture-mime', this.onCaptureMime);
    },

    beforeDestroy() {
        this.teardown();
    },

    beforeUnmount() {
        this.teardown();
    },

    watch: {
        url(newUrl) {
            if (this.isVideo && newUrl) {
                this.hasFrame = false;
                this.captureFrame(newUrl);
            }
        }
    },

    methods: {
        teardown() {
            window.removeEventListener('vt:seek-preview', this.onSeekPreview);
            window.removeEventListener('vt:capture-mime', this.onCaptureMime);
            clearTimeout(this._captureTimer);
            if (this.videoEl) { this.videoEl.src = ''; this.videoEl = null; }
            this._pendingSeek = null;
            this._seeking = false;
        },

        onCaptureMime({ detail: { mimeType } }) {
            this._captureMime = mimeType;
        },

        captureFrame(url) {
            if (this.videoEl) this.videoEl.src = '';
            const video = document.createElement('video');
            video.muted = true;
            video.playsInline = true;
            video.preload = 'auto';
            this.videoEl = video;

            video.addEventListener('loadedmetadata', () => {
                const duration = isFinite(video.duration) ? video.duration : 0;
                window.dispatchEvent(new CustomEvent('vt:duration-ready', {
                    detail: { videoUrl: url, duration }
                }));
                this.seekTo(Math.min(0.5, duration), () => {
                    this.drawFrame();
                    this.scheduleCapture();
                });
            }, { once: true });

            video.addEventListener('error', () => {}, { once: true });
            video.src = url;
            video.load();
        },

        onSeekPreview({ detail: { videoUrl, time } }) {
            if (videoUrl !== this.url || !this.videoEl) return;
            this._pendingSeek = time;
            if (!this._seeking) this._doNextSeek();
        },

        _doNextSeek() {
            if (this._pendingSeek == null || !this.videoEl) return;
            const time = this._pendingSeek;
            this._pendingSeek = null;
            this._targetTime = time;
            this._seeking = true;
            // Use a fast (keyframe-snapping) seek while dragging WebM. Exact
            // seeks are cheap for MP4/H.264 (hardware-decoded, dense keyframes)
            // but expensive for WebM (VP8/VP9), which usually isn't
            // hardware-decoded and has sparse keyframes, so an exact seek must
            // decode every frame from the last keyframe. fastSeek keeps the
            // WebM scrub responsive; scheduleCapture does one exact seek for
            // the frame the user finally lands on. MP4 keeps exact seeking so
            // its live preview stays frame-accurate while dragging.
            const fast = (this.type || '').includes('webm');
            this.seekTo(time, () => {
                this._seeking = false;
                // draw the live preview right away for a responsive scrub…
                this.drawFrame();
                if (this._pendingSeek != null) {
                    this._doNextSeek();
                } else {
                    // …and only encode/emit the upload blob once the user
                    // has settled on a frame (see scheduleCapture).
                    this.scheduleCapture();
                }
            }, fast);
        },

        // Seeks to `time`, then invokes `callback` as soon as the `seeked`
        // event fires (or after a timeout guard if it never does). Kept off
        // the frame-decode wait so scrubbing stays responsive; the careful
        // Safari-safe decode happens later in scheduleCapture. When `fast` is
        // set, prefers video.fastSeek (Safari/Firefox) to snap to the nearest
        // keyframe instead of decoding to the exact frame.
        seekTo(time, callback, fast = false) {
            const video = this.videoEl;
            if (!video) return;

            const duration = Number.isFinite(video.duration) ? video.duration : 0;
            const target = Math.max(0, Math.min(Number(time) || 0, duration));
            let finished = false;
            let timeout = null;

            const finish = () => {
                if (finished === true) return;
                finished = true;
                clearTimeout(timeout);
                video.removeEventListener('seeked', finish);
                callback();
            };

            timeout = setTimeout(finish, 800);

            if (video.readyState >= 2 && Math.abs(video.currentTime - target) < 0.01) {
                finish();
                return;
            }

            video.addEventListener('seeked', finish, { once: true });

            try {
                if (fast && typeof video.fastSeek === 'function') {
                    video.fastSeek(target);
                } else {
                    video.currentTime = target;
                }
            } catch (error) {
                finish();
            }
        },

        // Safari fires `seeked` before the frame is decoded into the video
        // element, so drawing immediately yields a black/previous frame.
        // Wait for the next painted frame via requestVideoFrameCallback when
        // available, with a timeout fallback for browsers without it.
        afterFrameReady(video, callback) {
            let done = false;

            const finish = () => {
                if (done === true) return;
                done = true;
                callback();
            };

            if (typeof video.requestVideoFrameCallback === 'function') {
                const timeout = setTimeout(finish, 300);
                video.requestVideoFrameCallback(() => {
                    clearTimeout(timeout);
                    setTimeout(finish, 50);
                });
            } else {
                setTimeout(finish, 300);
            }
        },

        // Debounced, Safari-safe capture of the settled frame. Does one exact
        // seek to the frame the user landed on (the interactive scrub may have
        // fast-seeked to a nearby keyframe), then runs the
        // requestVideoFrameCallback wait — which the scrub path skips — so the
        // *saved* thumbnail is a correctly decoded, exact frame. Debouncing
        // means a rapid scrub triggers this exactly once, when the user stops.
        scheduleCapture() {
            clearTimeout(this._captureTimer);
            this._captureTimer = setTimeout(() => {
                const video = this.videoEl;
                if (!video) return;
                const target = this._targetTime ?? video.currentTime;
                this.seekTo(target, () => {
                    this.afterFrameReady(video, () => {
                        const canvas = this.drawFrame();
                        if (canvas) this.emitFrame(canvas);
                    });
                });
            }, 120);
        },

        drawFrame() {
            const canvas = this.$refs.canvasEl;
            if (!canvas || !this.videoEl) return null;
            canvas.width  = this.videoEl.videoWidth  || 320;
            canvas.height = this.videoEl.videoHeight || 180;
            canvas.getContext('2d').drawImage(this.videoEl, 0, 0);
            this.hasFrame = true;
            return canvas;
        },

        // Hand the freshly drawn frame to the items component as a blob. This
        // is the same canvas shown in the preview, so the saved thumbnail
        // always matches what the editor sees — and it avoids a second, cold
        // video decode that produces black frames for WebM in Safari.
        emitFrame(canvas) {
            const mimeType = this._captureMime || 'image/jpeg';
            canvas.toBlob(blob => {
                if (!blob) return;
                window.dispatchEvent(new CustomEvent('video-thumbnail-frame', {
                    detail: { videoUrl: this.url, blob }
                }));
            }, mimeType, 0.85);
        }
    }
};
</script>

<style>
.k-upload-item-preview--video {
    aspect-ratio: 16 / 9;
    height: auto;
    background: black;
}

.k-upload-item-canvas {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
}
</style>
