<template>
    <ul class="k-upload-items">
        <template v-for="item in items">
            <k-upload-item
                v-bind="item"
                :id="item.id"
                class="k-upload-item-video"
                @rename="$emit('rename', item, $event)"
                @remove="$emit('remove', item)"
            />
            <li
                v-if="item.type?.startsWith('video/') && durations[item.url]"
                :key="item.id + '-slider'"
                class="vt-slider-row"
                @pointermove="onSliderDrag(item.url, $event)"
                @input="onScrub(item.url, parseFloat($event.target.value))"
            >
                <k-range-field
                    :min="0"
                    :max="durations[item.url]"
                    :step="0.01"
                    :value="seekTimes[item.url] ?? 0"
                    :help="$t('video-thumbnail.slider.help')"
                    :tooltip="{ before: formatTime(seekTimes[item.url] ?? 0) }"
                />
            </li>
        </template>
    </ul>
</template>

<script>
const defaultThumbnailOptions = {
    template: 'thumb',
    prefix: '',
    suffix: '_thumb',
    extension: 'jpg'
};

export default {
    props: {
        items: Array
    },

    emits: ['remove', 'rename'],

    data() {
        return {
            // videoUrl -> current thumbnail Blob, kept locally so it never
            // takes a slot in $panel.upload.files (and thus never counts
            // towards a field's `max`)
            blobs: new Map(),
            // videoUrl -> true once its thumbnail has been uploaded
            uploaded: new Set(),
            // configurable filename/template options, fetched once from the API
            thumbnailOptions: null,
            thumbnailOptionsPromise: null,
            durations: {},
            seekTimes: {}
        };
    },

    mounted() {
        this.processAdditions(this.items);
        this.processCompletions(this.items);
        window.addEventListener('vt:duration-ready', this.onDurationReady);
        window.addEventListener('video-thumbnail-frame', this.onFrame);

        // tell the preview components which image format to encode frames in
        this.getThumbnailOptions().then(options => {
            const mimeType = this.thumbnailMimeType(this.thumbnailExtension(options));
            window.dispatchEvent(new CustomEvent('vt:capture-mime', {
                detail: { mimeType }
            }));
        });
    },

    beforeDestroy() {
        this.teardown();
    },

    unmounted() {
        this.teardown();
    },

    watch: {
        items: {
            deep: true,
            handler(newItems, oldItems) {
                this.processAdditions(newItems);
                this.processRemovals(newItems, oldItems);
                this.processCompletions(newItems);
            }
        }
    },

    methods: {
        teardown() {
            window.removeEventListener('vt:duration-ready', this.onDurationReady);
            window.removeEventListener('video-thumbnail-frame', this.onFrame);
        },

        // The preview component decodes and draws the frame the editor sees,
        // then hands it back here as a blob. That single decode is the source
        // of truth for the uploaded thumbnail.
        onFrame({ detail: { videoUrl, blob } }) {
            if (blob) this.blobs.set(videoUrl, blob);
        },

        onDurationReady({ detail: { videoUrl, duration } }) {
            this.$set(this.durations, videoUrl, duration);
            if (this.seekTimes[videoUrl] == null) {
                this.$set(this.seekTimes, videoUrl, 0);
            }
        },

        onScrub(videoUrl, time) {
            this.$set(this.seekTimes, videoUrl, time);
            window.dispatchEvent(new CustomEvent('vt:seek-preview', {
                detail: { videoUrl, time }
            }));
        },

        onSliderDrag(videoUrl, event) {
            if (event.buttons === 0) return;
            const input = event.currentTarget.querySelector('input[type=range]');
            if (input) this.onScrub(videoUrl, parseFloat(input.value));
        },

        processAdditions(newItems) {
            newItems?.forEach(item => {
                if (item.type?.startsWith('video/') && !this.blobs.has(item.url)) {
                    // seed the entry; the preview component fills it via onFrame
                    this.blobs.set(item.url, null);
                }
            });
        },

        // Fallback capture, used only if the preview never delivered a frame
        // (e.g. its own decode failed). Resolves options for the target format.
        async captureThumbnail(videoUrl, time) {
            const options = await this.getThumbnailOptions();
            const mimeType = this.thumbnailMimeType(this.thumbnailExtension(options));

            return this.captureBlob(videoUrl, time, mimeType);
        },

        processRemovals(newItems, oldItems) {
            oldItems?.forEach(item => {
                if (!item.type?.startsWith('video/')) return;
                if (newItems?.some(i => i.id === item.id)) return;
                this.blobs.delete(item.url);
                this.uploaded.delete(item.url);
                this.$delete(this.durations, item.url);
                this.$delete(this.seekTimes, item.url);
            });
        },

        processCompletions(items) {
            items?.forEach(item => {
                if (!item.type?.startsWith('video/')) return;
                if (!item.completed || !item.model) return;
                if (this.uploaded.has(item.url)) return;

                this.uploaded.add(item.url);
                this.uploadThumbnail(item);
            });
        },

        async uploadThumbnail(videoItem) {
            let blob = this.blobs.get(videoItem.url);
            if (!blob) {
                // preview never delivered a frame — try a direct capture
                const time = this.seekTimes[videoItem.url] ?? 0.5;
                blob = await this.captureThumbnail(videoItem.url, time);
            }
            if (!blob) return;

            const options   = await this.getThumbnailOptions();
            const extension = this.thumbnailExtension(options);
            const mimeType  = this.thumbnailMimeType(extension);
            const base      = `${options.prefix ?? ''}${videoItem.name}${options.suffix ?? ''}`;
            const filename  = `${base}.${extension}`;
            const file      = new File([blob], filename, { type: mimeType });
            const formData  = new FormData();
            formData.append('file', file, filename);

            const duration = this.durations[videoItem.url];
            if (duration != null) {
                formData.append('duration', duration);
            }

            try {
                await fetch(this.$panel.upload.url, {
                    method: 'POST',
                    headers: { 'x-csrf': this.$panel.system.csrf },
                    body: formData
                });
                // the video's own upload already refreshed the field/section
                // before this thumbnail existed on disk. Files sections only
                // re-fetch (and thus re-apply their `query` filter that hides
                // the thumbnail) in reaction to a `model.update` event — a
                // plain view refresh alone doesn't reliably trigger that.
                this.$events.emit('model.update');
                await this.$panel.view.refresh();
            } catch (error) {
                this.$panel.error(error);
            }
        },

        captureBlob(url, time, mimeType = 'image/jpeg') {
            return new Promise(resolve => {
                const video = document.createElement('video');
                video.muted = true;
                video.playsInline = true;
                video.preload = 'auto';
                let done = false;
                let metadataTimeout = null;
                let seekTimeout = null;
                let frameTimeout = null;
                let drawTimeout = null;

                const cleanup = () => {
                    clearTimeout(metadataTimeout);
                    clearTimeout(seekTimeout);
                    clearTimeout(frameTimeout);
                    clearTimeout(drawTimeout);
                    video.removeEventListener('seeked', capture);
                    video.src = '';
                };

                const finish = blob => {
                    if (done) return;
                    done = true;
                    cleanup();
                    resolve(blob);
                };

                const draw = () => {
                    if (done) return;
                    const canvas = document.createElement('canvas');
                    canvas.width  = video.videoWidth  || 320;
                    canvas.height = video.videoHeight || 180;
                    try {
                        canvas.getContext('2d').drawImage(video, 0, 0);
                        canvas.toBlob(blob => finish(blob), mimeType, 0.85);
                    } catch (error) {
                        finish(null);
                    }
                };

                // Safari fires `seeked` before the frame is painted, so wait
                // for the next decoded frame when requestVideoFrameCallback is
                // available, with timeout fallbacks otherwise.
                const capture = () => {
                    clearTimeout(seekTimeout);

                    if (typeof video.requestVideoFrameCallback === 'function') {
                        frameTimeout = setTimeout(draw, 300);
                        video.requestVideoFrameCallback(() => {
                            clearTimeout(frameTimeout);
                            drawTimeout = setTimeout(draw, 50);
                        });
                    } else {
                        drawTimeout = setTimeout(draw, 300);
                    }
                };

                video.addEventListener('seeked', capture, { once: true });
                video.addEventListener('loadedmetadata', () => {
                    clearTimeout(metadataTimeout);
                    const duration = Number.isFinite(video.duration) ? video.duration : 0;
                    const target = Math.max(0, Math.min(Number(time) || 0, duration));

                    // fall back to capturing even if `seeked` never fires
                    seekTimeout = setTimeout(capture, 800);

                    try {
                        video.currentTime = target;
                    } catch (error) {
                        capture();
                    }
                }, { once: true });
                video.addEventListener('error', () => finish(null), { once: true });

                // give up if metadata never loads (corrupt/unsupported file)
                metadataTimeout = setTimeout(() => finish(null), 10000);
                video.src = url;
                video.load();
            });
        },

        async getThumbnailOptions() {
            if (this.thumbnailOptions) {
                return this.thumbnailOptions;
            }

            if (!this.thumbnailOptionsPromise) {
                this.thumbnailOptionsPromise = this.$api.get('video-thumbnail/options')
                    .then(options => (this.thumbnailOptions = {
                        ...defaultThumbnailOptions,
                        ...options
                    }))
                    // never block thumbnail generation on an options failure —
                    // fall back to the built-in defaults
                    .catch(() => (this.thumbnailOptions = { ...defaultThumbnailOptions }));
            }

            return this.thumbnailOptionsPromise;
        },

        thumbnailExtension(options) {
            const extension = String(options.extension ?? defaultThumbnailOptions.extension)
                .replace(/^\.+/, '')
                .toLowerCase();

            return ['jpg', 'jpeg', 'png', 'webp'].includes(extension) ? extension : 'jpg';
        },

        thumbnailMimeType(extension) {
            return {
                jpg: 'image/jpeg',
                jpeg: 'image/jpeg',
                png: 'image/png',
                webp: 'image/webp'
            }[extension] ?? 'image/jpeg';
        },

        formatTime(seconds) {
            const h = Math.floor(seconds / 3600);
            const m = Math.floor((seconds % 3600) / 60);
            const s = Math.floor(seconds % 60);
            return `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
        }
    }
};
</script>

<style>
.k-upload-item-video {
    grid-template-areas:
        "preview input input"
        "preview body toggle";
    grid-template-columns: 1fr 2fr auto;
    grid-template-rows: auto var(--input-height) 1fr;
    min-height: 0;
    border-radius: var(--rounded) var(--rounded) 0 0;;
}

.vt-slider-row {
    list-style: none;
    background: var(--upload-item-color-back); 
    border-radius: 0 0 var(--rounded, 0.25rem) var(--rounded, 0.25rem);
    padding: 0.4rem 0.5rem 0.3rem;
    margin-top: -0.25rem;
    box-shadow: var(--shadow);
}

.vt-slider-row .k-range-input {
    width: 100%;
}

.vt-slider-row .k-range-input-tooltip-text {
    display: none;
}
</style>
