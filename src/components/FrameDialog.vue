<template>
    <k-dialog
        :visible="visible"
        :submit-button="{ text: $panel.t('save'), disabled: busy || !blob || (legacy && !adopt) }"
        @cancel="$emit('cancel')"
        @submit="save"
    >
        <k-headline>{{ $panel.t('video-thumbnail.frame') }}</k-headline>
        <video-upload-item-preview
            :url="url" :type="mime" :capture-mime="captureMime"
            @capture-error="error = $panel.t('video-thumbnail.decodeError')"
        />
        <k-range-field
            :label="$panel.t('video-thumbnail.position')" :min="0" :max="duration" :step="0.01"
            :value="position" :disabled="!duration" @input="seek"
        />
        <k-toggle-field
            v-if="legacy" :label="$panel.t('video-thumbnail.adopt')" :value="adopt"
            @input="adopt = $event"
        />
        <k-box v-if="error" theme="negative">{{ error }}</k-box>
    </k-dialog>
</template>

<script>
import VideoUploadItemPreview from './VideoUploadItemPreview.vue';
import { uploadFrame } from '../frame.js';

export default {
    components: { VideoUploadItemPreview },
    props: { visible: Boolean, id: String, url: String, mime: String, extension: String, legacy: Boolean },
    emits: ['cancel', 'success'],
    data() {
        return { position: 0, duration: 0, blob: null, busy: false, error: '', adopt: false };
    },
    computed: {
        captureMime() { return 'image/' + (this.extension === 'jpg' ? 'jpeg' : this.extension); }
    },
    created() {
        window.addEventListener('vt:duration-ready', this.loaded);
        window.addEventListener('video-thumbnail-frame', this.captured);
    },
    beforeUnmount() {
        window.removeEventListener('vt:duration-ready', this.loaded);
        window.removeEventListener('video-thumbnail-frame', this.captured);
    },
    methods: {
        loaded({ detail }) {
            if (detail.videoUrl !== this.url) return;
            this.duration = detail.duration;
        },
        captured({ detail }) {
            if (detail.videoUrl === this.url && Math.abs(detail.time - this.position) < 0.05) this.blob = detail.blob;
        },
        seek(value) {
            this.position = Number(value);
            this.blob = null;
            window.dispatchEvent(new CustomEvent('vt:seek-preview', { detail: { videoUrl: this.url, time: this.position } }));
        },
        async save() {
            if (this.busy || !this.blob || (this.legacy && !this.adopt)) return;
            this.busy = true;
            try {
                await uploadFrame(this.$panel, this.id, this.blob, null, this.adopt);
                this.$emit('success');
                this.$panel.dialog.close();
                this.$panel.events.emit('model.update');
            } catch (error) {
                this.error = error.message;
            } finally {
                this.busy = false;
            }
        }
    }
};
</script>

<style>
.k-dialog .k-upload-item-canvas { max-width: 100%; max-height: 50vh; object-fit: contain; }
</style>
