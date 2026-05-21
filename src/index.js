import VideoUploadItemPreview from './components/VideoUploadItemPreview.vue';
import VideoUploadItems from './components/VideoUploadItems.vue';

panel.plugin('yolu/video-thumbnail', {
    components: {
        'k-upload-item-preview': VideoUploadItemPreview,
        'k-upload-items': VideoUploadItems
    }
});
