const loadVideoThumbnail = (video) => {
    if (!(video instanceof HTMLVideoElement) || video.dataset.thumbnailLoaded === 'true') return;
    const url = video.dataset.thumbnailUrl;
    if (!url) return;

    video.dataset.thumbnailLoaded = 'true';
    video.preload = 'metadata';
    video.muted = true;
    video.playsInline = true;
    video.addEventListener('loadedmetadata', () => {
        if (video.duration > 0) {
            video.currentTime = Math.min(0.1, video.duration / 2);
        } else {
            video.pause();
        }
    }, { once: true });
    video.addEventListener('loadeddata', () => {
        video.hidden = false;
        video.pause();
    }, { once: true });
    video.addEventListener('seeked', () => video.pause(), { once: true });
    video.addEventListener('error', () => {
        video.hidden = true;
        video.closest('.video-gallery-tile, .case-evidence-thumb')?.classList.add('has-no-video-thumbnail');
    }, { once: true });
    video.src = url;
    video.load();
};

window.vaultLoadVideoThumbnail = loadVideoThumbnail;

const observeCaseVideoThumbnails = () => {
    const videos = document.querySelectorAll('[data-case-video-thumbnail]:not([data-thumbnail-observed])');
    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver((entries, currentObserver) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;
                const video = entry.target instanceof HTMLVideoElement
                    ? entry.target
                    : entry.target.querySelector('[data-case-video-thumbnail]');
                if (video) loadVideoThumbnail(video);
                currentObserver.unobserve(entry.target);
            });
        }, { rootMargin: '220px' });

        videos.forEach((video) => {
            video.dataset.thumbnailObserved = 'true';
            const tile = video.closest('.case-evidence-thumb, .existing-upload-image, .video-gallery-tile, .evidence-gallery-tile');
            observer.observe(tile ?? video);
        });
        return;
    }

    videos.forEach(loadVideoThumbnail);
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', observeCaseVideoThumbnails, { once: true });
} else {
    observeCaseVideoThumbnails();
}
document.addEventListener('livewire:navigated', observeCaseVideoThumbnails);
