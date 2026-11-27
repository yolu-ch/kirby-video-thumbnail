export async function uploadFrame(panel, id, blob, duration, adopt = false) {
    if (!id) throw new Error('The video needs a Kirby file UUID.');
    const form = new FormData();
    const ext = { 'image/jpeg': 'jpg', 'image/png': 'png', 'image/webp': 'webp' }[blob.type];
    if (!ext) throw new Error('Unsupported poster format.');
    form.append('file', blob, 'frame.' + ext);
    if (duration != null) form.append('duration', duration);
    if (adopt) form.append('adopt', 'true');
    const response = await fetch(panel.urls.api + '/video-thumbnail/frame/' + encodeURIComponent(id.replace(/^file:\/\//, '')), {
        method: 'POST', headers: { 'x-csrf': panel.system.csrf }, body: form
    });
    const result = await response.json();
    if (!response.ok || result.status === 'error') throw new Error(result.message || 'Poster upload failed.');
}
