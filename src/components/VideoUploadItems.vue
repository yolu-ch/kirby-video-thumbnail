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
            // videoUrl -> current thumbnail Blob, kept locally so it never
            // takes a slot in $panel.upload.files (and thus never counts
            // towards a field's `max`)
            blobs: new Map(),
            // videoUrl -> true once its thumbnail has been uploaded
            uploaded: new Set(),
            durations: {},
            seekTimes: {}
        };
    },

    mounted() {
        this.processAdditions(this.items);
        this.processCompletions(this.items);
        window.addEventListener('vt:duration-ready', this.onDurationReady);
    },

    unmounted() {
        window.removeEventListener('vt:duration-ready', this.onDurationReady);
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
                if (blob) this.blobs.set(videoUrl, blob);
            });
        },

        processAdditions(newItems) {
            newItems?.forEach(item => {
                if (item.type?.startsWith('video/') && !this.blobs.has(item.url)) {
                    this.blobs.set(item.url, null);
                    this.captureBlob(item.url, 0.5).then(blob => {
                        if (blob) this.blobs.set(item.url, blob);
                    });
                }
            });
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
            const blob = this.blobs.get(videoItem.url);
            if (!blob) return;

            const filename = videoItem.name + '_thumb.jpg';
            const file = new File([blob], filename, { type: 'image/jpeg' });
            const formData = new FormData();
            formData.append('file', file, filename);

            try {
                await fetch(this.$panel.upload.url, {
                    method: 'POST',
                    headers: { 'x-csrf': this.$panel.system.csrf },
                    body: formData
                });
                // the video's own upload already refreshed the field/section
                // before this thumbnail existed on disk, so its preview image
                // needs a second, full view refresh once the thumbnail is
                // actually there — sections/fields don't reliably reload on
                // a plain model.update event
                await this.$panel.view.refresh();
            } catch (error) {
                this.$panel.error(error);
            }
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
