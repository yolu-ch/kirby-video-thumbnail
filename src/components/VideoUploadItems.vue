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
                @pointerup="onScrubEnd(item.url)"
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
export default {
    props: {
        items: Array
    },

    emits: ['remove', 'rename'],

    data() {
        return {
            thumbMap: new Map(),
            durations: {},
            seekTimes: {}
        };
    },

    computed: {
        visibleItems() {
            return this.items?.filter(item => !item.filename?.endsWith('_thumb.jpg')) ?? [];
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
                done(files.filter(f => !f.filename?.endsWith('_thumb.jpg')));
            wrapped.vtWrapped = true;
            upload.on.done = wrapped;
        }
    },

    unmounted() {
        window.removeEventListener('vt:duration-ready', this.onDurationReady);
        window.removeEventListener('video-thumbnail-update', this.handleThumbnailUpdate);
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

        onScrubEnd(videoUrl) {
            const time = this.seekTimes[videoUrl] ?? 0.5;
            this.captureBlob(videoUrl, time).then(blob => {
                if (blob) this.updateThumb(videoUrl, blob);
            });
        },

        processAdditions(newItems, oldItems) {
            newItems?.forEach(item => {
                if (item.type?.startsWith('video/') && !this.thumbMap.has(item.url)) {
                    this.thumbMap.set(item.url, null);
                    this.addThumbnail(item);
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
                this.$delete(this.durations, item.url);
                this.$delete(this.seekTimes, item.url);
            });
        },

        async addThumbnail(videoItem) {
            const blob = await this.captureBlob(videoItem.url, 0.5);
            if (!blob) return;

            const name     = videoItem.name + '_thumb';
            const filename = name + '.jpg';
            const file     = new File([blob], filename, { type: 'image/jpeg' });
            const id       = Date.now().toString(36) + Math.random().toString(36).slice(2);

            const thumbItem = {
                completed: false,
                error:     null,
                extension: 'jpg',
                filename,
                id,
                model:     null,
                name,
                niceSize:  this.formatSize(file.size),
                progress:  0,
                size:      file.size,
                src:       file,
                type:      'image/jpeg',
                url:       URL.createObjectURL(file)
            };

            this.thumbMap.set(videoItem.url, id);
            this.$panel.upload.files = [...this.$panel.upload.files, thumbItem];
        },

        captureBlob(url, time) {
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
                    canvas.toBlob(blob => resolve(blob), 'image/jpeg', 0.85);
                };

                video.addEventListener('seeked', draw, { once: true });
                video.addEventListener('loadedmetadata', () => {
                    video.currentTime = Math.min(time, video.duration);
                }, { once: true });
                video.addEventListener('error', () => { if (!done) { done = true; resolve(null); } }, { once: true });

                video.src = url;
                video.load();
            });
        },

        updateThumb(videoUrl, blob) {
            const thumbId = this.thumbMap.get(videoUrl);
            if (!thumbId) return;
            const files = this.$panel.upload.files;
            const idx = files.findIndex(f => f.id === thumbId);
            if (idx === -1) return;

            const old = files[idx];
            const file = new File([blob], old.filename, { type: 'image/jpeg' });
            if (old.url?.startsWith('blob:')) URL.revokeObjectURL(old.url);
            const url = URL.createObjectURL(file);

            const newFiles = [...files];
            newFiles[idx] = { ...old, src: file, url, size: file.size, niceSize: this.formatSize(file.size) };
            this.$panel.upload.files = newFiles;
        },

        handleThumbnailUpdate({ detail: { videoUrl, blob } }) {
            this.updateThumb(videoUrl, blob);
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
