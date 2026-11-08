<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    qrRequest,
    useCountdown,
    useQrPolling,
} from '@/composables/useQrLogin';
import { approve, code, deny } from '@/routes/qr';
import { show as reauthenticateShow } from '@/routes/reauthenticate';
import { edit, store } from '@/routes/sign-in-another-device';
import type {
    QrFailure,
    QrIssued,
    QrRequester,
    QrStatusPayload,
} from '@/types/qr';

type Phase = 'idle' | 'loading' | 'showing' | 'deciding' | 'ended';

const props = defineProps<{
    confirmed: boolean;
    windowMinutes: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Sign in another device', href: edit() }],
    },
});

const phase = ref<Phase>('idle');
const qr = ref('');
const token = ref('');
const shortCode = ref('');
const message = ref('');
const requester = ref<QrRequester | null>(null);
const options = ref<number[]>([]);
const chosen = ref<number | null>(null);
const busy = ref(false);

const countdown = useCountdown();

const polling = useQrPolling((payload: QrStatusPayload) => {
    if (payload.status === 'pending' && payload.claimed && payload.request) {
        requester.value = payload.request;
        options.value = payload.options ?? [];
        phase.value = 'deciding';
    } else if (payload.status === 'approved' || payload.status === 'redeemed') {
        end('Approved. The other device is being signed in.');
    } else if (payload.status === 'denied') {
        end('That request was declined, nobody was signed in.');
    } else if (['expired', 'none'].includes(payload.status)) {
        end('This code has expired.');
    }
});

function end(text: string): void {
    polling.stop();
    countdown.stop();
    phase.value = 'ended';
    message.value = text;
}

async function show(): Promise<void> {
    phase.value = 'loading';
    message.value = '';
    chosen.value = null;

    const result = await qrRequest<QrIssued & QrFailure>(store.url());

    if (!result.ok) {
        end(
            result.code === 409
                ? 'Confirm it is you again, then try once more.'
                : (result.data.message ?? 'Could not create a code.'),
        );

        return;
    }

    qr.value = result.data.qr;
    token.value = result.data.token ?? '';
    shortCode.value = result.data.short_code;
    countdown.start(result.data.expires_in);
    phase.value = 'showing';
    polling.start();
}

async function answer(action: 'approve' | 'deny'): Promise<void> {
    busy.value = true;

    const result =
        action === 'approve'
            ? await qrRequest<QrFailure>(approve.url(token.value), 'POST', {
                  number: chosen.value,
              })
            : await qrRequest<QrFailure>(deny.url(token.value));

    busy.value = false;

    if (result.ok) {
        end(
            action === 'approve'
                ? 'Approved. The other device is being signed in.'
                : 'Declined. Nobody was signed in.',
        );

        return;
    }

    if (result.data.reauthenticate) {
        window.location.assign(result.data.reauthenticate);

        return;
    }

    end(result.data.message ?? 'That did not work.');
}
</script>

<template>
    <Head title="Sign in another device" />

    <div class="space-y-6">
        <header class="space-y-1">
            <h1 class="text-lg font-semibold tracking-tight">
                Sign in another device
            </h1>
            <p class="text-sm text-muted-foreground">
                Show a QR code here and open it on a phone that is signed out.
                You approve it from this device. It works once and lasts two
                minutes.
            </p>
        </header>

        <Card v-if="!props.confirmed">
            <CardContent class="space-y-4 p-6">
                <p class="text-sm">
                    Signing in another device hands out a full session, so
                    confirm it is you first. The check is good for
                    {{ props.windowMinutes }} minutes.
                </p>
                <Button as-child>
                    <Link :href="reauthenticateShow()">Confirm it is you</Link>
                </Button>
            </CardContent>
        </Card>

        <Card v-else>
            <CardContent
                class="flex flex-col items-center gap-4 p-6 text-center"
            >
                <template v-if="phase === 'idle' || phase === 'ended'">
                    <p
                        v-if="message"
                        class="text-sm text-muted-foreground"
                        role="status"
                    >
                        {{ message }}
                    </p>
                    <Button type="button" @click="show">
                        Show a QR code
                    </Button>
                </template>

                <div
                    v-else-if="phase === 'loading'"
                    class="flex size-56 items-center justify-center rounded-lg border border-border"
                >
                    <Spinner class="size-6" />
                </div>

                <template v-else-if="phase === 'showing'">
                    <img
                        :src="qr"
                        alt="QR code to sign in another device"
                        width="224"
                        height="224"
                        class="size-56 rounded-lg border border-border bg-white p-2"
                    />
                    <p class="text-sm text-muted-foreground">
                        Scan it with the other device's camera, or open
                        <span class="font-medium text-foreground"
                            >this site's sign-in page</span
                        >
                        there and type
                        <span
                            class="font-mono font-semibold text-foreground"
                            data-testid="qr-short-code"
                            >{{ shortCode }}</span
                        >.
                    </p>
                    <p class="text-xs text-muted-foreground">
                        Expires in {{ countdown.format() }}
                    </p>
                </template>

                <template v-else-if="phase === 'deciding' && requester">
                    <h2 class="text-base font-semibold">
                        A device opened your code
                    </h2>
                    <dl
                        class="grid w-full grid-cols-[auto_1fr] gap-x-4 gap-y-1 rounded-lg border border-border p-4 text-left text-sm"
                    >
                        <dt class="text-muted-foreground">Device</dt>
                        <dd class="font-medium">{{ requester.device }}</dd>
                        <dt class="text-muted-foreground">Network</dt>
                        <dd class="font-medium break-all">
                            {{ requester.network ?? 'Unknown' }}
                        </dd>
                        <dt class="text-muted-foreground">Address</dt>
                        <dd class="font-medium break-all">
                            {{ requester.ip ?? 'Unknown' }}
                        </dd>
                    </dl>
                    <p class="text-sm font-medium">
                        Pick the number shown on that device
                    </p>
                    <div class="grid w-full grid-cols-3 gap-3">
                        <button
                            v-for="option in options"
                            :key="option"
                            type="button"
                            :aria-pressed="chosen === option"
                            class="rounded-lg border border-border py-4 text-2xl font-semibold tabular-nums transition-colors hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            :class="{
                                'border-foreground bg-muted': chosen === option,
                            }"
                            @click="chosen = option"
                        >
                            {{ option }}
                        </button>
                    </div>
                    <div class="flex w-full flex-col gap-3 sm:flex-row">
                        <Button
                            type="button"
                            class="flex-1"
                            :disabled="chosen === null || busy"
                            @click="answer('approve')"
                        >
                            Approve sign-in
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            class="flex-1"
                            :disabled="busy"
                            @click="answer('deny')"
                        >
                            Decline
                        </Button>
                    </div>
                </template>
            </CardContent>
        </Card>

        <Card>
            <CardContent class="space-y-3 p-6">
                <h2 class="text-base font-semibold">
                    Approve a sign-in on another device
                </h2>
                <p class="text-sm text-muted-foreground">
                    A computer showing a QR code on its sign-in page also shows
                    a short code. Type it here if you cannot scan.
                </p>
                <Form
                    v-bind="code.form()"
                    v-slot="{ errors, processing }"
                    class="flex flex-col gap-3 sm:flex-row sm:items-start"
                >
                    <div class="grid flex-1 gap-2">
                        <Label for="short-code" class="sr-only"
                            >Short code</Label
                        >
                        <Input
                            id="short-code"
                            name="code"
                            required
                            autocomplete="off"
                            autocapitalize="characters"
                            placeholder="ABCD-EFGH"
                            class="font-mono uppercase"
                        />
                        <InputError :message="errors.code" />
                    </div>
                    <Button type="submit" :disabled="processing">
                        <Spinner v-if="processing" />
                        Continue
                    </Button>
                </Form>
            </CardContent>
        </Card>
    </div>
</template>
