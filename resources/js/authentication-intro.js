const intro = document.querySelector('[data-authentication-intro]');

if (intro) {
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    let removalTimer;

    const dismissIntro = () => {
        if (intro.dataset.exiting === 'true') return;

        intro.dataset.exiting = 'true';
        intro.classList.add('is-exiting');
        window.clearTimeout(removalTimer);
        document.removeEventListener('keydown', handleKeydown);
        removalTimer = window.setTimeout(() => intro.remove(), reducedMotion ? 50 : 450);
    };

    const handleKeydown = (event) => {
        if (event.key === 'Escape') dismissIntro();
    };

    document.addEventListener('keydown', handleKeydown);
    window.setTimeout(dismissIntro, reducedMotion ? 400 : 5250);
}
