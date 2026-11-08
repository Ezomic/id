import { onBeforeUnmount, ref } from 'vue';
import { redeem, status } from '@/routes/qr';
import type { QrFailure, QrStatusPayload } from '@/types/qr';

const POLL_MS = 2000;
const THROTTLED_MS = 10000;

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

export async function qrRequest<T>(
    url: string,
    method: 'GET' | 'POST' = 'POST',
    body?: Record<string, unknown>,
): Promise<{ ok: boolean; code: number; data: T & QrFailure }> {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: body ? JSON.stringify(body) : undefined,
    });

    const data = (await response.json().catch(() => ({}))) as T & QrFailure;

    return { ok: response.ok, code: response.status, data };
}

/**
 * Asks once, two seconds apart, how this browser's request is doing. There is
 * no push: a plain poll keeps this to one small request per tick.
 */
export function useQrPolling(onPayload: (payload: QrStatusPayload) => void) {
    const running = ref(false);
    let timer: ReturnType<typeof setTimeout> | undefined;

    async function tick(): Promise<void> {
        if (!running.value) {
            return;
        }

        let delay = POLL_MS;

        try {
            const result = await qrRequest<QrStatusPayload>(
                status.url(),
                'GET',
            );

            if (result.code === 429) {
                delay = THROTTLED_MS;
            } else if (result.ok && running.value) {
                onPayload(result.data);
            }
        } catch {
            delay = THROTTLED_MS;
        }

        if (running.value) {
            timer = setTimeout(tick, delay);
        }
    }

    function start(): void {
        stop();
        running.value = true;
        timer = setTimeout(tick, POLL_MS);
    }

    function stop(): void {
        running.value = false;
        clearTimeout(timer);
    }

    onBeforeUnmount(stop);

    return { start, stop };
}

/**
 * The approved request is only turned into a session by this call, made by the
 * browser that started it. A full page visit follows, not a script redirect,
 * because a sign-in that began at an app ends at /oauth/authorize.
 */
export async function redeemQrLogin(): Promise<string | null> {
    const result = await qrRequest<{ redirect?: string }>(redeem.url());

    if (result.ok && result.data.redirect) {
        window.location.assign(result.data.redirect);

        return null;
    }

    return result.data.message ?? 'Could not sign you in. Try again.';
}

export function useCountdown() {
    const remaining = ref(0);
    let timer: ReturnType<typeof setInterval> | undefined;

    function start(seconds: number): void {
        stop();
        remaining.value = seconds;
        timer = setInterval(() => {
            remaining.value = Math.max(0, remaining.value - 1);

            if (remaining.value === 0) {
                stop();
            }
        }, 1000);
    }

    function stop(): void {
        clearInterval(timer);
    }

    function format(): string {
        const minutes = Math.floor(remaining.value / 60);
        const seconds = String(remaining.value % 60).padStart(2, '0');

        return `${minutes}:${seconds}`;
    }

    onBeforeUnmount(stop);

    return { remaining, start, stop, format };
}
