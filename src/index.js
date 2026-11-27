import VideoUploadItemPreview from './components/VideoUploadItemPreview.vue';
import VideoUploadItems from './components/VideoUploadItems.vue';

import FrameDialog from './components/FrameDialog.vue';
import VideoFilePreview from './components/VideoFilePreview.vue';

panel.plugin('yolu/video-thumbnail', {
    components: {
        'video-thumbnail-frame-dialog': FrameDialog,
        'video-thumbnail-file-preview': VideoFilePreview,
        'k-upload-item-preview': VideoUploadItemPreview,
        'k-upload-items': VideoUploadItems
    }
});
