<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import {
    qrRequest,
    redeemQrLogin,
    useCountdown,
    useQrPolling,
} from '@/composables/useQrLogin';
import { create } from '@/routes/qr';
import type { QrIssued, QrFailure, QrStatusPayload } from '@/types/qr';

type PanelState = 'loading' | 'ready' | 'signing-in' | 'ended';

const state = ref<PanelState>('loading');
const qr = ref('');
const number = ref<number | null>(null);
const shortCode = ref('');
const message = ref('');

const countdown = useCountdown();

const polling = useQrPolling(async (payload: QrStatusPayload) => {
    if (payload.status === 'approved') {
        polling.stop();
        state.value = 'signing-in';

        const failure = await redeemQrLogin();

        if (failure) {
            finish(failure);
        }

        return;
    }

    if (payload.status === 'denied') {
        finish('That request was declined on your phone.');
    } else if (['expired', 'none', 'redeemed'].includes(payload.status)) {
        finish('This code has expired.');
    }
});

function finish(text: string): void {
    polling.stop();
    countdown.stop();
    state.value = 'ended';
    message.value = text;
}

async function issue(): Promise<void> {
    state.value = 'loading';
    message.value = '';

    const result = await qrRequest<QrIssued & QrFailure>(create.url());

    if (!result.ok) {
        finish(
            result.code === 429
                ? 'Too many codes requested. Wait a minute and try again.'
                : (result.data.message ?? 'Could not create a code.'),
        );

        return;
    }

    qr.value = result.data.qr;
    number.value = result.data.number ?? null;
    shortCode.value = result.data.short_code;
    countdown.start(result.data.expires_in);
    state.value = 'ready';
    polling.start();
}

onMounted(issue);
</script>

<template>
    <div class="flex flex-col items-center gap-4 text-center">
        <div
            v-if="state === 'loading' || state === 'signing-in'"
            class="flex size-56 items-center justify-center rounded-lg border border-border"
        >
            <Spinner class="size-6" />
            <span class="sr-only">{{
                state === 'loading' ? 'Creating a code' : 'Signing you in'
            }}</span>
        </div>

        <template v-else-if="state === 'ready'">
            <img
                :src="qr"
                alt="QR code to sign in with a phone that is already signed in"
                width="224"
                height="224"
                class="size-56 rounded-lg border border-border bg-white p-2"
            />

            <div>
                <p
                    class="text-xs tracking-wide text-muted-foreground uppercase"
                >
                    Your phone will ask for this number
                </p>
                <p
                    class="text-4xl font-semibold tracking-tight tabular-nums"
                    data-testid="qr-number"
                >
                    {{ number }}
                </p>
            </div>

            <p class="text-sm text-muted-foreground">
                No camera? On the phone open Settings, Sign in another device,
                and type
                <span
                    class="font-mono font-semibold text-foreground"
                    data-testid="qr-short-code"
                    >{{ shortCode }}</span
                >.
            </p>

            <p class="text-xs text-muted-foreground" aria-live="off">
                Expires in {{ countdown.format() }}
            </p>
        </template>

        <template v-else>
            <p class="text-sm text-muted-foreground">{{ message }}</p>
            <Button type="button" variant="outline" @click="issue">
                Show a new code
            </Button>
        </template>
    </div>
</template>
