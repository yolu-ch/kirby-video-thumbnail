<template>
    <ul class="k-upload-items">
        <template v-for="item in visibleItems">
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
            >
                <k-range-field
                    :min="0"
                    :max="durations[item.url]"
                    :step="0.01"
                    :value="seekTimes[item.url] ?? 0"
                    :help="$t('video-thumbnail.slider.help')"
                    :tooltip="{ before: formatTime(seekTimes[item.url] ?? 0) }"
                    @input="onScrub(item.url, $event)"
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
            thumbMap: new Map(),
            thumbnailJobs: new Map(),
            thumbnailOptions: null,
            thumbnailOptionsPromise: null,
            durations: {},
            seekTimes: {}
        };
    },

    computed: {
        visibleItems() {
            return this.items?.filter(item => !this.isHiddenThumbnailItem(item)) ?? [];
        }
    },

    mounted() {
        this.processAdditions(this.items, []);
        window.addEventListener('vt:duration-ready', this.onDurationReady);
        window.addEventListener('video-thumbnail-update', this.handleThumbnailUpdate);

        // The generated thumb rides along in the field's upload queue so the
        // slider preview works, but FilesField.on.done() selects every completed
        // upload unconditionally. Strip the thumb from that selection so only the
        // uploaded media lands in the field; the thumb still uploads as a sibling
        // file, to be found by naming convention (e.g. File::poster()).
        const upload = this.$panel.upload;
        const done = upload.on?.done;
        if (done && !done.vtWrapped) {
            const wrapped = (files) =>
                done(files.filter(file => !this.isThumbnailItem(file)));
            wrapped.vtWrapped = true;
            upload.on.done = wrapped;
        }

        this.wrapUploadSubmit();
    },

    unmounted() {
        window.removeEventListener('vt:duration-ready', this.onDurationReady);
        window.removeEventListener('video-thumbnail-update', this.handleThumbnailUpdate);
        this.restoreUploadSubmit();
    },

    watch: {
        items(newItems, oldItems) {
            this.processAdditions(newItems, oldItems);
            this.processRemovals(newItems, oldItems);
        }
    },

    methods: {
        onDurationReady({ detail: { videoUrl, duration } }) {
            this.$set(this.durations, videoUrl, duration);
            if (this.seekTimes[videoUrl] == null) {
                this.$set(this.seekTimes, videoUrl, 0);
            }
        },

        onScrub(videoUrl, time) {
            time = Number(time);
            if (Number.isFinite(time) === false) return;

            this.$set(this.seekTimes, videoUrl, time);
            window.dispatchEvent(new CustomEvent('vt:seek-preview', {
                detail: { videoUrl, time }
            }));
        },

        processAdditions(newItems, oldItems) {
            newItems?.forEach(item => {
                if (item.type?.startsWith('video/') && !this.thumbMap.has(item.url)) {
                    this.thumbMap.set(item.url, null);
                    this.ensureThumbnail(item, 0.5).catch(error => this.handleThumbnailError(error));
                }
            });
        },

        processRemovals(newItems, oldItems) {
            oldItems?.forEach(item => {
                if (!item.type?.startsWith('video/')) return;
                if (newItems?.some(i => i.id === item.id)) return;
                const thumbId = this.thumbMap.get(item.url);
                if (thumbId) this.$panel.upload.remove(thumbId);
                this.thumbMap.delete(item.url);
                this.thumbnailJobs.delete(item.url);
                this.$delete(this.durations, item.url);
                this.$delete(this.seekTimes, item.url);
            });
        },

        async prepareThumbnailsForSubmit() {
            const videos = this.visibleItems.filter(item => item.type?.startsWith('video/'));

            for (const videoItem of videos) {
                const time = this.seekTimes[videoItem.url] ?? 0.5;
                const ready = await this.ensureThumbnail(videoItem, time);

                if (ready !== true) {
                    throw new Error('The video thumbnail could not be generated.');
                }
            }
        },

        async ensureThumbnail(videoItem, time) {
            const activeJob = this.thumbnailJobs.get(videoItem.url);
            if (activeJob) await activeJob;

            const job = this.writeThumbnail(videoItem, time).finally(() => {
                if (this.thumbnailJobs.get(videoItem.url) === job) {
                    this.thumbnailJobs.delete(videoItem.url);
                }
            });

            this.thumbnailJobs.set(videoItem.url, job);

            return job;
        },

        async writeThumbnail(videoItem, time) {
            const options = await this.getThumbnailOptions();
            const extension = this.thumbnailExtension(options);
            const mimeType = this.thumbnailMimeType(extension);
            const blob = await this.captureBlob(videoItem.url, time, mimeType);

            if (!blob) return false;

            const name     = this.thumbnailName(videoItem, options);
            const filename = this.thumbnailFilename(name, extension);

            if (this.updateThumb(videoItem.url, blob, { extension, filename, mimeType, name }) === true) {
                return true;
            }

            const file     = new File([blob], filename, { type: mimeType });
            const id       = Date.now().toString(36) + Math.random().toString(36).slice(2);

            const thumbItem = {
                completed: false,
                error:     null,
                extension,
                filename,
                id,
                model:     null,
                name,
                niceSize:  this.formatSize(file.size),
                progress:  0,
                size:      file.size,
                src:       file,
                type:      mimeType,
                url:       URL.createObjectURL(file)
            };
            thumbItem.videoThumbnail = true;

            this.thumbMap.set(videoItem.url, id);
            this.$panel.upload.files = [...this.$panel.upload.files, thumbItem];

            return true;
        },

        captureBlob(url, time, mimeType = 'image/jpeg') {
            return new Promise(resolve => {
                const video = document.createElement('video');
                video.muted = true;
                video.playsInline = true;
                video.preload = 'auto';
                let done = false;

                const draw = () => {
                    if (done) return;
                    done = true;
                    const canvas = document.createElement('canvas');
                    canvas.width  = video.videoWidth  || 320;
                    canvas.height = video.videoHeight || 180;
                    canvas.getContext('2d').drawImage(video, 0, 0);
                    video.src = '';
                    canvas.toBlob(blob => resolve(blob), mimeType, 0.85);
                };

                const capture = () => {
                    if (typeof video.requestVideoFrameCallback === 'function') {
                        video.requestVideoFrameCallback(() => {
                            setTimeout(draw, 50);
                        });
                    } else {
                        setTimeout(draw, 300);
                    }
                };

                video.addEventListener('seeked', capture, { once: true });
                video.addEventListener('loadedmetadata', () => {
                    video.currentTime = Math.min(time, video.duration);
                }, { once: true });
                video.addEventListener('error', () => { if (!done) { done = true; resolve(null); } }, { once: true });

                video.src = url;
                video.load();
            });
        },

        updateThumb(videoUrl, blob, options = {}) {
            const thumbId = this.thumbMap.get(videoUrl);
            if (!thumbId) return false;
            const files = this.$panel.upload.files;
            const idx = files.findIndex(f => f.id === thumbId);
            if (idx === -1) return false;

            const old = files[idx];
            const file = new File([blob], options.filename ?? old.filename, {
                type: options.mimeType ?? old.type ?? 'image/jpeg'
            });
            if (old.url?.startsWith('blob:')) URL.revokeObjectURL(old.url);
            const url = URL.createObjectURL(file);

            const newFiles = [...files];
            newFiles[idx] = {
                ...old,
                extension: options.extension ?? old.extension,
                filename: options.filename ?? old.filename,
                name: options.name ?? old.name,
                niceSize: this.formatSize(file.size),
                size: file.size,
                src: file,
                type: options.mimeType ?? old.type,
                url
            };
            this.$panel.upload.files = newFiles;

            return true;
        },

        handleThumbnailUpdate({ detail: { videoUrl, blob } }) {
            this.updateThumb(videoUrl, blob);
        },

        async getThumbnailOptions() {
            if (this.thumbnailOptions) {
                return this.thumbnailOptions;
            }

            if (!this.thumbnailOptionsPromise) {
                this.thumbnailOptionsPromise = this.$api.get('video-thumbnail/options')
                    .then(options => {
                        this.thumbnailOptions = {
                            ...defaultThumbnailOptions,
                            ...options
                        };

                        return this.thumbnailOptions;
                    });
            }

            return this.thumbnailOptionsPromise;
        },

        wrapUploadSubmit() {
            const upload = this.$panel.upload;
            if (!upload?.submit || upload.submit.vtWrapped) return;

            this._originalUploadSubmit = upload.submit;
            this._wrappedUploadSubmit = async (...args) => {
                try {
                    await this.prepareThumbnailsForSubmit();
                } catch (error) {
                    this.handleThumbnailError(error);
                    return;
                }

                return this._originalUploadSubmit.apply(upload, args);
            };

            this._wrappedUploadSubmit.vtWrapped = true;
            upload.submit = this._wrappedUploadSubmit;
        },

        restoreUploadSubmit() {
            const upload = this.$panel.upload;

            if (upload?.submit === this._wrappedUploadSubmit) {
                upload.submit = this._originalUploadSubmit;
            }
        },

        handleThumbnailError(error) {
            if (this.$panel?.error) {
                this.$panel.error(error, false);
            } else {
                console.error(error);
            }
        },

        isThumbnailItem(item) {
            if (!item) return false;
            if (item.videoThumbnail === true) return true;
            if (item.id && [...this.thumbMap.values()].includes(item.id)) return true;

            return item.filename?.endsWith('_thumb.jpg') === true;
        },

        isHiddenThumbnailItem(item) {
            return this.isThumbnailItem(item) === true && !item.error;
        },

        thumbnailName(videoItem, options) {
            return `${options.prefix ?? ''}${videoItem.name}${options.suffix ?? ''}`;
        },

        thumbnailFilename(name, extension) {
            return `${name}.${extension}`;
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
        },

        formatSize(bytes) {
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / 1048576).toFixed(1) + ' MB';
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
