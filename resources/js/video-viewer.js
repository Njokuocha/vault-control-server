let videojs = null;
let dialog = null;
let stage = null;
let playerHost = null;
let title = null;
let download = null;
let fallback = null;
let professionalHud = null;
let professionalControls = null;
let player = null;
let video = null;
let source = null;
let sourceType = null;
let activeMode = 'normal';
let returnFocus = null;
let autoplayOnOpen = false;
let modeChange = 0;
let grayscaleEnabled = false;
let downloadBusyTimer = null;
let standardControlsTimer = null;
let videoFitIndex = 0;
const videoFitModes = [
    { key: 'auto', label: 'AUTO', title: 'Auto fit' },
    { key: '16:9', label: '16:9', title: '16:9 aspect ratio' },
    { key: '4:3', label: '4:3', title: '4:3 aspect ratio' },
    { key: '1:1', label: '1:1', title: '1:1 aspect ratio' },
    { key: 'fill', label: 'FILL', title: 'Fill frame and crop edges' },
];

const videoJsOptions = {
    autoplay: false,
    controls: true,
    fluid: true,
    responsive: true,
    preload: 'metadata',
    playbackRates: [0.5, 0.75, 1, 1.25, 1.5, 2],
    controlBar: {
        children: [
            'playToggle',
            'volumePanel',
            'currentTimeDisplay',
            'timeDivider',
            'durationDisplay',
            'progressControl',
            'playbackRateMenuButton',
            'pictureInPictureToggle',
            'fullscreenToggle',
        ],
    },
};

const formatTime = (time, hours = false) => {
    if (!Number.isFinite(time) || time < 0) return hours ? '00:00:00' : '00:00';
    const seconds = Math.floor(time);
    const hh = Math.floor(seconds / 3600);
    const mm = Math.floor((seconds % 3600) / 60);
    const ss = seconds % 60;
    return hours || hh > 0
        ? `${String(hh).padStart(2, '0')}:${String(mm).padStart(2, '0')}:${String(ss).padStart(2, '0')}`
        : `${String(mm).padStart(2, '0')}:${String(ss).padStart(2, '0')}`;
};

const currentTime = () => player ? player.currentTime() : video?.currentTime ?? 0;
const duration = () => player ? player.duration() : video?.duration ?? 0;
const isPaused = () => player ? player.paused() : video?.paused ?? true;
const currentVolume = () => player ? player.volume() : video?.volume ?? 1;
const isMuted = () => player ? player.muted() : video?.muted ?? false;
const isLooping = () => player ? player.loop() : video?.loop ?? false;
const playbackRate = () => player ? player.playbackRate() : video?.playbackRate ?? 1;

const playbackState = () => ({
    currentTime: currentTime(),
    paused: isPaused(),
    volume: currentVolume(),
    muted: isMuted(),
    loop: isLooping(),
    playbackRate: playbackRate(),
    grayscale: grayscaleEnabled,
});

const setCurrentTime = (time) => {
    if (player) player.currentTime(time);
    else if (video) video.currentTime = time;
};

const handlePlaybackError = (error) => {
    if (error?.name === 'NotAllowedError') return;
    if (fallback) {
        fallback.textContent = 'Video playback could not start. Try another player mode or download the original file.';
        fallback.hidden = false;
    }
    window.vaultAlert?.({
        type: 'error',
        message: error instanceof Error ? error.message : 'Video playback could not start.',
    });
};

const requestPlayback = () => {
    const request = player ? player.play() : video?.play();
    request?.catch(handlePlaybackError);
};

const syncControls = () => {
    if (!dialog) return;
    const elapsed = currentTime();
    const total = duration();
    const position = dialog.querySelector('[data-video-seek]');
    const elapsedLabel = dialog.querySelector('[data-video-current-time]');
    const durationLabel = dialog.querySelector('[data-video-duration]');
    const hudTime = dialog.querySelector('[data-video-hud-time]');
    const playButton = dialog.querySelector('[data-video-play]');
    const muteButton = dialog.querySelector('[data-video-mute]');
    const loopButton = dialog.querySelector('[data-video-loop]');
    const grayscaleButton = dialog.querySelector('[data-video-grayscale]');
    const volumeSlider = dialog.querySelector('[data-video-volume]');
    const speed = dialog.querySelector('[data-video-speed]');

    if (position instanceof HTMLInputElement) {
        position.value = total > 0 ? String(Math.round((elapsed / total) * 1000)) : '0';
    }
    if (elapsedLabel) elapsedLabel.textContent = formatTime(elapsed);
    if (durationLabel) durationLabel.textContent = formatTime(total);
    if (hudTime) hudTime.textContent = `${formatTime(elapsed, true)} / ${formatTime(total, true)}`;
    if (playButton instanceof HTMLButtonElement) {
        const paused = isPaused();
        playButton.setAttribute('aria-label', paused ? 'Play video' : 'Pause video');
        playButton.title = paused ? 'Play' : 'Pause';
        const icon = playButton.querySelector('i');
        icon?.classList.toggle('ri-play-fill', paused);
        icon?.classList.toggle('ri-pause-fill', !paused);
    }
    if (muteButton instanceof HTMLButtonElement) {
        const muted = isMuted() || currentVolume() === 0;
        muteButton.setAttribute('aria-label', muted ? 'Unmute video' : 'Mute video');
        muteButton.title = muted ? 'Unmute' : 'Mute';
        const icon = muteButton.querySelector('i');
        icon?.classList.toggle('ri-volume-mute-line', muted);
        icon?.classList.toggle('ri-volume-up-line', !muted);
    }
    if (loopButton instanceof HTMLButtonElement) {
        const looping = isLooping();
        loopButton.setAttribute('aria-pressed', String(looping));
        loopButton.setAttribute('aria-label', looping ? 'Disable video loop' : 'Enable video loop');
    }
    if (grayscaleButton instanceof HTMLButtonElement) {
        grayscaleButton.setAttribute('aria-pressed', String(grayscaleEnabled));
        grayscaleButton.setAttribute('aria-label', grayscaleEnabled ? 'Disable grayscale video' : 'Enable grayscale video');
    }
    if (volumeSlider instanceof HTMLInputElement) {
        volumeSlider.value = String(isMuted() ? 0 : currentVolume());
    }
    if (speed instanceof HTMLSelectElement) {
        speed.value = String(playbackRate());
    }
};

const hideStandardControls = () => {
    if (standardControlsTimer !== null) {
        window.clearTimeout(standardControlsTimer);
        standardControlsTimer = null;
    }
    stage?.classList.remove('video-standard-controls-active');
    stage?.classList.remove('video-standard-controls-idle');
};

const showStandardControls = () => {
    if (activeMode !== 'standard' || !dialog?.open || !stage) return;
    if (standardControlsTimer !== null) window.clearTimeout(standardControlsTimer);
    stage.classList.add('video-standard-controls-active');
    stage.classList.remove('video-standard-controls-idle');
    standardControlsTimer = window.setTimeout(() => {
        standardControlsTimer = null;
        stage?.classList.remove('video-standard-controls-active');
        stage?.classList.add('video-standard-controls-idle');
    }, 2800);
};

const setVideoFit = (index) => {
    videoFitIndex = (index + videoFitModes.length) % videoFitModes.length;
    const fit = videoFitModes[videoFitIndex];
    stage?.setAttribute('data-video-fit', fit.key);
    const button = dialog?.querySelector('[data-video-fit]');
    const label = button?.querySelector('[data-video-fit-label]');
    if (label) label.textContent = fit.label;
    button?.setAttribute('aria-label', `Video fit: ${fit.title}. Change video fit`);
    button?.setAttribute('title', `Video fit: ${fit.title}`);
};

const getPlaybackState = () => {
    const state = playbackState();
    return {
        ...state,
        currentTime: Number.isFinite(state.currentTime) ? state.currentTime : 0,
        volume: Number.isFinite(state.volume) ? state.volume : 1,
        playbackRate: Number.isFinite(state.playbackRate) ? state.playbackRate : 1,
    };
};

const restorePlaybackState = (state, expectedSource) => {
    if (!video || source !== expectedSource) return;
    if (Number.isFinite(state.currentTime) && state.currentTime > 0
        && Number.isFinite(video.duration) && state.currentTime < video.duration) {
        setCurrentTime(state.currentTime);
    }
    if (player) {
        player.volume(state.volume);
        player.muted(state.muted);
        player.loop(state.loop);
        player.playbackRate(state.playbackRate);
    } else {
        video.volume = state.volume;
        video.muted = state.muted;
        video.loop = state.loop;
        video.playbackRate = state.playbackRate;
    }
    grayscaleEnabled = state.grayscale;
    video.classList.toggle('video-viewer-grayscale', grayscaleEnabled);
    syncControls();

    if (!state.paused || autoplayOnOpen) {
        requestPlayback();
    }
    autoplayOnOpen = false;
};

const disposePlayer = () => {
    if (player) {
        player.pause();
        player.dispose();
        player = null;
    } else {
        video?.pause();
    }
    video = null;
    playerHost?.replaceChildren();
};

const createVideoElement = (mode) => {
    const element = document.createElement('video');
    element.playsInline = true;
    element.preload = 'metadata';
    element.setAttribute('aria-label', title?.textContent || 'Video playback');
    if (mode === 'normal') {
        element.controls = true;
        element.className = 'video-viewer-native';
    } else {
        element.className = 'video-js vjs-big-play-centered';
    }

    return element;
};

const createPlayerForMode = async (mode, state, transition) => {
    if (!(playerHost instanceof HTMLElement) || !source) return;

    if (mode !== 'normal') {
        const videoJsModule = await import('video.js');
        await import('video.js/dist/video-js.css');
        if (transition !== modeChange || !dialog?.open) return;
        videojs = videoJsModule.default;
    }

    disposePlayer();
    const expectedSource = source;
    video = createVideoElement(mode);
    playerHost.append(video);
    stage?.setAttribute('data-video-mode', mode);
    if (professionalHud) professionalHud.hidden = mode !== 'professional';
    if (professionalControls) professionalControls.hidden = mode !== 'professional';
    hideStandardControls();
    if (mode === 'standard') showStandardControls();
    const mediaEvents = ['timeupdate', 'play', 'pause', 'volumechange', 'ratechange', 'durationchange'];
    mediaEvents.forEach((eventName) => video.addEventListener(eventName, () => {
        syncControls();
        if (eventName === 'play') showStandardControls();
    }));
    video.addEventListener('error', showPlaybackFallback);

    const restore = () => restorePlaybackState(state, expectedSource);
    video.addEventListener('loadedmetadata', restore, { once: true });

    if (mode === 'normal') {
        video.loop = state.loop;
        video.muted = state.muted;
        video.volume = state.volume;
        video.playbackRate = state.playbackRate;
        video.classList.toggle('video-viewer-grayscale', state.grayscale);
        video.src = expectedSource;
        video.load();
    } else {
        const options = {
            ...videoJsOptions,
            controls: mode === 'standard',
        };
        player = videojs(video, options);
        player.on('error', showPlaybackFallback);
        player.one('loadedmetadata', restore);
        mediaEvents.forEach((eventName) => player.on(eventName, () => {
            syncControls();
            if (eventName === 'play') showStandardControls();
        }));
        player.src(sourceType ? { src: expectedSource, type: sourceType } : expectedSource);
        player.ready(syncControls);
    }
    syncControls();
};

const updateModeButtons = (mode) => {
    dialog?.querySelector('[data-video-mode-selector]')?.setAttribute('data-active-mode', mode);
    dialog?.querySelectorAll('button[data-video-mode]').forEach((button) => {
        button.setAttribute('aria-pressed', String(button.dataset.videoMode === mode));
    });
};

const switchMode = async (mode) => {
    if (!['normal', 'standard', 'professional'].includes(mode) || mode === activeMode) return;
    const transition = ++modeChange;
    const state = getPlaybackState();
    activeMode = mode;
    updateModeButtons(mode);

    try {
        await createPlayerForMode(mode, state, transition);
    } catch (error) {
        if (transition !== modeChange) return;
        activeMode = 'normal';
        updateModeButtons(activeMode);
        stage?.setAttribute('data-video-mode', activeMode);
        if (fallback) {
            fallback.textContent = 'The selected player mode could not be loaded. Normal mode is available.';
            fallback.hidden = false;
        }
        await createPlayerForMode(activeMode, state, transition);
        window.vaultAlert?.({
            type: 'error',
            message: error instanceof Error ? error.message : 'Could not load the selected video player mode.',
        });
    }
};

const showPlaybackFallback = () => {
    if (fallback) fallback.hidden = false;
};

const setDownloadBusy = (busy) => {
    if (downloadBusyTimer !== null) {
        window.clearTimeout(downloadBusyTimer);
        downloadBusyTimer = null;
    }
    if (!(download instanceof HTMLAnchorElement)) return;

    download.setAttribute('aria-busy', String(busy));
    download.setAttribute('aria-label', busy ? 'Preparing video download' : 'Download video');
    download.title = busy ? 'Preparing video download' : 'Download video';
    const icon = download.querySelector('[data-video-viewer-download-icon]');
    const spinner = download.querySelector('[data-video-viewer-download-spinner]');
    if (icon instanceof HTMLElement) icon.hidden = busy;
    if (spinner instanceof HTMLElement) spinner.hidden = !busy;

    if (busy) {
        downloadBusyTimer = window.setTimeout(() => {
            downloadBusyTimer = null;
            setDownloadBusy(false);
        }, 900);
    }
};

const refreshViewer = () => {
    dialog = document.querySelector('[data-video-viewer-dialog]');
    if (!(dialog instanceof HTMLDialogElement)) return;
    stage = dialog.querySelector('[data-video-viewer-stage]');
    playerHost = dialog.querySelector('[data-video-viewer-player-host]');
    title = dialog.querySelector('[data-video-viewer-title]');
    download = dialog.querySelector('[data-video-viewer-download]');
    fallback = dialog.querySelector('[data-video-viewer-fallback]');
    professionalHud = dialog.querySelector('[data-video-professional-hud]');
    professionalControls = dialog.querySelector('[data-video-professional-controls]');
    setVideoFit(videoFitIndex);
};

export async function openVaultVideoViewer({
    src,
    source: requestedSource = src,
    name = 'Video preview',
    downloadUrl = requestedSource,
    mimeType,
    returnFocus: focusTarget = document.activeElement,
    autoplay = false,
} = {}) {
    refreshViewer();
    if (!(dialog instanceof HTMLDialogElement) || !(playerHost instanceof HTMLElement)) {
        window.vaultAlert?.({ type: 'error', message: 'The shared video viewer is unavailable on this page.' });
        return;
    }
    if (!requestedSource) {
        window.vaultAlert?.({ type: 'error', message: 'The video preview is missing its authorized source URL.' });
        return;
    }

    modeChange += 1;
    const transition = modeChange;
    disposePlayer();
    returnFocus = focusTarget instanceof HTMLElement ? focusTarget : null;
    source = String(requestedSource);
    sourceType = mimeType || null;
    autoplayOnOpen = autoplay;
    grayscaleEnabled = false;
    videoFitIndex = 0;
    setVideoFit(videoFitIndex);
    if (title) title.textContent = name;
    if (download instanceof HTMLAnchorElement) {
        setDownloadBusy(false);
        download.href = downloadUrl;
        download.download = name;
    }
    if (fallback) fallback.hidden = true;
    updateModeButtons(activeMode);
    if (!dialog.open) dialog.showModal();
    try {
        await createPlayerForMode(activeMode, {
            currentTime: 0,
            paused: !autoplay,
            volume: 1,
            muted: false,
            loop: false,
            playbackRate: 1,
            grayscale: false,
        }, transition);
    } catch (error) {
        if (fallback) {
            fallback.textContent = 'The selected player mode could not be loaded. Normal mode is available.';
            fallback.hidden = false;
        }
        activeMode = 'normal';
        updateModeButtons(activeMode);
        await createPlayerForMode(activeMode, {
            currentTime: 0,
            paused: !autoplay,
            volume: 1,
            muted: false,
            loop: false,
            playbackRate: 1,
            grayscale: false,
        }, transition);
        window.vaultAlert?.({
            type: 'error',
            message: error instanceof Error ? error.message : 'Could not load the selected video player mode.',
        });
    }
};

const closeViewer = () => {
    modeChange += 1;
    hideStandardControls();
    disposePlayer();
    source = null;
    sourceType = null;
    autoplayOnOpen = false;
    grayscaleEnabled = false;
    if (download instanceof HTMLAnchorElement) {
        setDownloadBusy(false);
        download.removeAttribute('href');
    }
    if (fallback) fallback.hidden = true;
    const trigger = returnFocus;
    returnFocus = null;
    trigger?.focus();
};

refreshViewer();

if (dialog instanceof HTMLDialogElement) {
    dialog.addEventListener('pointermove', showStandardControls);
    dialog.addEventListener('pointerdown', showStandardControls);
    dialog.addEventListener('keydown', showStandardControls);
    dialog.addEventListener('mouseenter', showStandardControls);
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog || event.target instanceof Element && event.target.closest('[data-close-video-viewer]')) {
            dialog.close();
            return;
        }

        const modeButton = event.target instanceof Element ? event.target.closest('button[data-video-mode]') : null;
        if (modeButton instanceof HTMLButtonElement) {
            switchMode(modeButton.dataset.videoMode || 'normal');
            return;
        }

        const downloadLink = event.target instanceof Element
            ? event.target.closest('a[data-video-viewer-download]')
            : null;
        if (downloadLink instanceof HTMLAnchorElement) {
            if (downloadLink.getAttribute('aria-busy') === 'true') {
                event.preventDefault();
                return;
            }
            setDownloadBusy(true);
            return;
        }

        const videoElement = video;
        if (!videoElement || activeMode !== 'professional') return;
        const button = event.target instanceof Element ? event.target.closest('button') : null;
        if (!(button instanceof HTMLButtonElement)) return;

        if (button.matches('[data-video-fit]')) {
            setVideoFit(videoFitIndex + 1);
        } else if (button.matches('[data-video-play]')) {
            if (isPaused()) requestPlayback();
            else if (player) player.pause();
            else videoElement.pause();
        } else if (button.matches('[data-video-seek-back]')) {
            setCurrentTime(Math.max(0, currentTime() - 10));
        } else if (button.matches('[data-video-seek-forward]')) {
            const total = duration();
            setCurrentTime(total > 0 ? Math.min(total, currentTime() + 10) : currentTime() + 10);
        } else if (button.matches('[data-video-loop]')) {
            const looping = !isLooping();
            if (player) player.loop(looping);
            else videoElement.loop = looping;
            button.setAttribute('aria-pressed', String(looping));
            button.setAttribute('aria-label', looping ? 'Disable video loop' : 'Enable video loop');
        } else if (button.matches('[data-video-mute]')) {
            if (player) player.muted(!isMuted());
            else videoElement.muted = !videoElement.muted;
        } else if (button.matches('[data-video-grayscale]')) {
            grayscaleEnabled = !grayscaleEnabled;
            videoElement.classList.toggle('video-viewer-grayscale', grayscaleEnabled);
            button.setAttribute('aria-pressed', String(grayscaleEnabled));
            button.setAttribute('aria-label', grayscaleEnabled ? 'Disable grayscale video' : 'Enable grayscale video');
        } else if (button.matches('[data-video-fullscreen]')) {
            const fullscreen = document.fullscreenElement
                ? document.exitFullscreen()
                : stage?.requestFullscreen();
            fullscreen?.catch((error) => {
                window.vaultAlert?.({
                    type: 'error',
                    message: error instanceof Error ? error.message : 'Fullscreen is unavailable.',
                });
            });
        }
        syncControls();
    });

    dialog.addEventListener('input', (event) => {
        if (!(event.target instanceof HTMLInputElement) || !video) return;
        if (event.target.matches('[data-video-seek]')) {
            const total = duration();
            if (total > 0) setCurrentTime((Number(event.target.value) / 1000) * total);
        } else if (event.target.matches('[data-video-volume]')) {
            const volume = Number(event.target.value);
            if (player) {
                player.volume(volume);
                player.muted(volume === 0);
            } else {
                video.volume = volume;
                video.muted = volume === 0;
            }
        }
    });

    dialog.addEventListener('change', (event) => {
        if (!(event.target instanceof HTMLSelectElement) || !event.target.matches('[data-video-speed]') || !video) return;
        const rate = Number(event.target.value);
        if (player) player.playbackRate(rate);
        else video.playbackRate = rate;
    });

    dialog.addEventListener('close', closeViewer);
}

document.addEventListener('livewire:navigating', () => {
    if (dialog?.open) dialog.close();
});
