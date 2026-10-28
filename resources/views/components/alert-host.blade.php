@php
    $initialAlerts = [];
    if (session('status')) {
        $initialAlerts[] = ['type' => 'success', 'message' => session('status')];
    }
    if ($errors->any()) {
        $initialAlerts[] = ['type' => 'error', 'message' => $errors->first()];
    }
@endphp

<div x-data>
    <div class="vault-alert-stack" aria-label="System alerts">
        <template x-for="alert in $store.vaultAlerts.items" :key="alert.id">
            <article class="vault-alert" :class="`vault-alert-${alert.type}`"
                :role="['error', 'critical'].includes(alert.type) ? 'alert' : 'status'"
                :aria-live="['error', 'critical'].includes(alert.type) ? 'assertive' : 'polite'"
                @mouseenter="$store.vaultAlerts.pause(alert.id)"
                @mouseleave="$store.vaultAlerts.resume(alert.id)">
                <i class="vault-alert-icon" :class="{
                    'ri-checkbox-circle-line': alert.type === 'success',
                    'ri-information-line': alert.type === 'info',
                    'ri-alert-line': alert.type === 'warning',
                    'ri-close-circle-line': alert.type === 'error',
                    'ri-shield-keyhole-line': alert.type === 'critical'
                }" aria-hidden="true"></i>
                <div class="vault-alert-copy">
                    <strong x-text="({
                        success: 'AUTHORIZED',
                        info: 'NOTICE',
                        warning: 'CAUTION',
                        error: 'OPERATION FAILED',
                        critical: 'SECURITY ALERT'
                    })[alert.type]"></strong>
                    <span x-text="alert.message"></span>
                    <small x-text="`${alert.reference} · ${alert.time}`"></small>
                </div>
                <button type="button" class="vault-alert-close" aria-label="Dismiss alert" @click="$store.vaultAlerts.close(alert.id)">×</button>
                <span class="vault-alert-progress" x-show="alert.duration > 0"
                    :class="{ 'is-paused': alert.paused }"
                    :style="`animation-duration:${alert.remaining}ms;`"></span>
            </article>
        </template>
    </div>

    <div class="vault-confirm-backdrop" x-show="$store.vaultAlerts.confirmation" x-cloak
        x-transition.opacity @click.self="$store.vaultAlerts.cancelConfirmation()">
        <section class="vault-confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="vault-confirm-title"
            @keydown.tab.prevent="
                const nodes = [...$el.querySelectorAll('button:not([disabled])')];
                const index = nodes.indexOf(document.activeElement);
                nodes[(index + (event.shiftKey ? nodes.length - 1 : 1)) % nodes.length]?.focus();
            "
            @keydown.enter.prevent="if (!$store.vaultAlerts.confirmation?.destructive) $store.vaultAlerts.finishConfirmation()">
            <div class="vault-confirm-strip"></div>
            <p class="vault-confirm-kicker">OPERATION AUTHORIZATION</p>
            <h2 id="vault-confirm-title" x-text="$store.vaultAlerts.confirmation?.title"></h2>
            <p class="vault-confirm-message" x-text="$store.vaultAlerts.confirmation?.message"></p>
            <div class="vault-confirm-actions">
                <button type="button" class="button-secondary" data-confirm-cancel @click="$store.vaultAlerts.cancelConfirmation()">CANCEL</button>
                <button type="button" class="button-primary vault-confirm-submit"
                    :class="{ 'is-holding': $store.vaultAlerts.confirmation?.hold }"
                    @pointerdown.prevent="$event.currentTarget.setPointerCapture($event.pointerId); $store.vaultAlerts.beginHold()"
                    @pointerup="$store.vaultAlerts.endHold()"
                    @pointercancel="$store.vaultAlerts.endHold()"
                    @keydown.enter.prevent="if ($store.vaultAlerts.confirmation?.destructive) $store.vaultAlerts.beginHold(); else $store.vaultAlerts.finishConfirmation()"
                    @keyup.enter="$store.vaultAlerts.endHold()"
                    @keydown.space.prevent="if ($store.vaultAlerts.confirmation?.destructive) $store.vaultAlerts.beginHold(); else $store.vaultAlerts.finishConfirmation()"
                    @keyup.space="$store.vaultAlerts.endHold()"
                    @click="if (!$store.vaultAlerts.confirmation?.destructive) $store.vaultAlerts.finishConfirmation()"
                    x-text="$store.vaultAlerts.confirmation?.destructive ? 'HOLD TO CONFIRM' : $store.vaultAlerts.confirmation?.confirmLabel"></button>
            </div>
        </section>
    </div>

    <div x-init="$nextTick(() => $store.vaultAlerts.seed(@js($initialAlerts)))"></div>
</div>
