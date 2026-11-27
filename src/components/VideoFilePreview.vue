<template>
    <div class="k-video-file-preview">
        <k-file-preview-frame :options="options">
            <video controls preload="metadata" :poster="poster" :src="url" />
        </k-file-preview-frame>

        <k-file-preview-details :details="details" />
    </div>
</template>

<script>
// Kirby's video file preview with the thumbnail as poster.
export default {
    extends: 'k-video-file-preview',
    props: {
        poster: String
    }
};
</script>
