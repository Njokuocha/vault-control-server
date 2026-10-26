let alertStore = null;
const queuedAlerts = [];

const dispatchAlert = (detail) => {
    if (alertStore) {
        alertStore.push(detail);
    } else {
        queuedAlerts.push(detail);
    }
};

window.vaultAlert = (alert) => dispatchAlert(alert);
window.vaultConfirm = (options) => alertStore?.confirm(options) ?? Promise.resolve(false);
window.addEventListener('alert', (event) => dispatchAlert(event.detail ?? {}));
window.addEventListener('vault:alert', (event) => dispatchAlert(event.detail ?? {}));

document.addEventListener('alpine:init', () => {
    const durations = { success: 4000, info: 4000, warning: 6000, error: 8000, critical: 0 };

    alertStore = {
        items: [],
        confirmation: null,

        push(alert = {}) {
            const type = ['success', 'info', 'warning', 'error', 'critical'].includes(alert.type) ? alert.type : 'info';
            const message = String(alert.message ?? '').trim();
            if (!message) return;

            const existing = this.items.find((item) => item.type === type && item.message === message);
            if (existing) return;

            const duration = alert.duration ?? durations[type];
            const timestamp = new Date();
            const reference = `VLT-${timestamp.toISOString().replace(/[-:TZ.]/g, '').slice(0, 14)}-${Math.random().toString(36).slice(2, 6).toUpperCase()}`;
            const item = {
                id: `${Date.now()}-${Math.random()}`,
                type,
                message,
                reference,
                time: timestamp.toLocaleTimeString(),
                duration,
                remaining: duration,
                timer: null,
                startedAt: 0,
                paused: false,
            };

            this.items = [...this.items.slice(-3), item];
            if (duration > 0) this.resume(item.id);
        },

        seed(alerts) {
            alerts.forEach((alert) => this.push(alert));
        },

        close(id) {
            const item = this.items.find((entry) => entry.id === id);
            if (item?.timer) window.clearTimeout(item.timer);
            this.items = this.items.filter((entry) => entry.id !== id);
        },

        pause(id) {
            const item = this.items.find((entry) => entry.id === id);
            if (!item || item.paused || !item.timer) return;
            window.clearTimeout(item.timer);
            item.remaining = Math.max(0, item.remaining - (Date.now() - item.startedAt));
            item.timer = null;
            item.paused = true;
        },

        resume(id) {
            const item = this.items.find((entry) => entry.id === id);
            if (!item || item.timer || item.remaining <= 0) return;
            item.paused = false;
            item.startedAt = Date.now();
            item.timer = window.setTimeout(() => this.close(id), item.remaining);
        },

        confirm(options = {}) {
            if (this.confirmation) return Promise.resolve(false);

            return new Promise((resolve) => {
                this.confirmation = {
                    title: options.title ?? 'Confirm operation',
                    message: options.message ?? 'Continue with this operation?',
                    confirmLabel: options.confirmLabel ?? 'CONFIRM',
                    destructive: Boolean(options.destructive),
                    hold: false,
                    holdTimer: null,
                    resolve,
                };
                Alpine.nextTick(() => document.querySelector('[data-confirm-cancel]')?.focus());
            });
        },

        cancelConfirmation() {
            if (!this.confirmation) return;
            window.clearTimeout(this.confirmation.holdTimer);
            this.confirmation.resolve(false);
            this.confirmation = null;
        },

        beginHold() {
            if (!this.confirmation?.destructive) return;
            this.confirmation.hold = true;
            this.confirmation.holdTimer = window.setTimeout(() => this.finishConfirmation(), 1000);
        },

        endHold() {
            if (!this.confirmation?.destructive || !this.confirmation.hold) return;
            window.clearTimeout(this.confirmation.holdTimer);
            this.confirmation.hold = false;
        },

        finishConfirmation() {
            if (!this.confirmation) return;
            const resolve = this.confirmation.resolve;
            this.confirmation = null;
            resolve(true);
        },
    };

    Alpine.store('vaultAlerts', alertStore);
    alertStore.seed(queuedAlerts.splice(0));
});

window.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && alertStore?.confirmation) alertStore.cancelConfirmation();
});
