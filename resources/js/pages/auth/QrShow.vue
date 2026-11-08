<script setup lang="ts">
import { Form, Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { redeemQrLogin, useQrPolling } from '@/composables/useQrLogin';
import { dashboard, login } from '@/routes';
import { approve, claim, deny } from '@/routes/qr';
import { edit as signInAnotherDevice } from '@/routes/sign-in-another-device';
import type { QrRequester, QrStatusPayload } from '@/types/qr';

type Mode =
    | 'invalid'
    | 'approve'
    | 'approved'
    | 'denied'
    | 'expired'
    | 'taken'
    | 'claim'
    | 'waiting'
    | 'owner';

const props = defineProps<{
    mode: Mode;
    notice?: string | null;
    request?: QrRequester;
    credential?: string | null;
    options?: number[];
    number?: number | null;
    email?: string | null;
}>();

defineOptions({
    layout: {
        title: 'Sign in with a QR code',
        description: 'Approve a sign-in from another device',
    },
});

const chosen = ref<number | null>(null);
const waitingText = ref('');
const signingIn = ref(false);

const polling = useQrPolling(async (payload: QrStatusPayload) => {
    if (payload.status === 'approved') {
        polling.stop();
        signingIn.value = true;

        const failure = await redeemQrLogin();

        if (failure) {
            signingIn.value = false;
            waitingText.value = failure;
        }
    } else if (payload.status === 'denied') {
        polling.stop();
        waitingText.value = 'The other device declined this request.';
    } else if (['expired', 'none', 'redeemed'].includes(payload.status)) {
        polling.stop();
        waitingText.value = 'This request has expired.';
    }
});

// A claim lands here as a visit to the same page component, so the mode
// changes without a remount.
watch(
    () => props.mode,
    (mode) => {
        if (mode === 'waiting') {
            polling.start();
        } else {
            polling.stop();
        }
    },
    { immediate: true },
);

function submitApproval(): void {
    if (props.credential && chosen.value !== null) {
        router.post(approve.url(props.credential), { number: chosen.value });
    }
}
</script>

<template>
    <Head title="Sign in with a QR code" />

    <div class="flex flex-col gap-6">
        <p
            v-if="notice"
            class="rounded-lg border border-border bg-muted p-3 text-sm"
            role="status"
        >
            {{ notice }}
        </p>

        <template v-if="mode === 'invalid'">
            <p class="text-sm text-muted-foreground">
                This QR code or short code is not valid, or it has expired. Ask
                for a new one on the other device.
            </p>
            <Button as-child variant="outline">
                <Link :href="login()">Back to sign in</Link>
            </Button>
        </template>

        <template v-else-if="mode === 'approve' && request">
            <div class="space-y-1 text-center">
                <h2 class="text-lg font-semibold">Is this you signing in?</h2>
                <p class="text-sm text-muted-foreground">
                    Approving signs the device below in as
                    <span class="font-medium text-foreground">{{ email }}</span
                    >, with access to every app you can reach.
                </p>
            </div>

            <dl
                class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 rounded-lg border border-border p-4 text-sm"
            >
                <dt class="text-muted-foreground">Device</dt>
                <dd class="font-medium">{{ request.device }}</dd>
                <dt class="text-muted-foreground">Network</dt>
                <dd class="font-medium break-all">
                    {{ request.network ?? 'Unknown' }}
                </dd>
                <dt class="text-muted-foreground">Address</dt>
                <dd class="font-medium break-all">
                    {{ request.ip ?? 'Unknown' }}
                </dd>
                <dt class="text-muted-foreground">Asked</dt>
                <dd class="font-medium">
                    {{
                        request.requested_at
                            ? new Date(
                                  request.requested_at,
                              ).toLocaleTimeString()
                            : 'Just now'
                    }}
                </dd>
            </dl>

            <fieldset class="space-y-3">
                <legend class="text-sm font-medium">
                    Pick the number shown on that device
                </legend>
                <div class="grid grid-cols-3 gap-3">
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
                <p class="text-xs text-muted-foreground">
                    If you don't recognise this device, or the number is not
                    there, decline. A wrong pick cancels the request.
                </p>
            </fieldset>

            <div class="flex flex-col gap-3">
                <Button
                    type="button"
                    class="w-full"
                    :disabled="chosen === null"
                    @click="submitApproval"
                >
                    Approve sign-in
                </Button>
                <Form
                    v-if="credential"
                    v-bind="deny.form(credential)"
                    v-slot="{ processing }"
                >
                    <Button
                        type="submit"
                        variant="outline"
                        class="w-full"
                        :disabled="processing"
                    >
                        Decline
                    </Button>
                </Form>
            </div>
        </template>

        <template v-else-if="mode === 'approved'">
            <p class="text-sm">
                Approved. The other device is being signed in now. You can close
                this page.
            </p>
            <Button as-child variant="outline">
                <Link :href="dashboard()">Go to the portal</Link>
            </Button>
        </template>

        <template v-else-if="mode === 'denied'">
            <p class="text-sm">
                This request was declined, nobody was signed in.
            </p>
            <Button as-child variant="outline">
                <Link :href="dashboard()">Go to the portal</Link>
            </Button>
        </template>

        <template v-else-if="mode === 'expired' || mode === 'taken'">
            <p class="text-sm text-muted-foreground">
                {{
                    mode === 'expired'
                        ? 'This request has expired. Ask for a new code on the other device.'
                        : 'This code is no longer available. It was used, cancelled or opened on another device.'
                }}
            </p>
            <Button as-child variant="outline">
                <Link :href="login()">Back to sign in</Link>
            </Button>
        </template>

        <template v-else-if="mode === 'claim'">
            <div class="space-y-1 text-center">
                <h2 class="text-lg font-semibold">Sign in on this device?</h2>
                <p class="text-sm text-muted-foreground">
                    You will see a number here. Your other, signed-in device
                    will ask you to pick it before this one is signed in. Only
                    continue if you just scanned that code yourself.
                </p>
                <p v-if="email" class="text-sm">
                    This signs this device in as
                    <span class="font-medium">{{ email }}</span
                    >. If that is not your account, stop here.
                </p>
            </div>
            <Form
                v-if="credential"
                v-bind="claim.form(credential)"
                v-slot="{ processing, errors }"
            >
                <Button type="submit" class="w-full" :disabled="processing">
                    <Spinner v-if="processing" />
                    Continue
                </Button>
                <InputError :message="errors.credential" />
            </Form>
        </template>

        <template v-else-if="mode === 'waiting'">
            <div class="space-y-1 text-center">
                <p
                    class="text-xs tracking-wide text-muted-foreground uppercase"
                >
                    Pick this number on your signed-in device
                </p>
                <p
                    class="text-6xl font-semibold tracking-tight tabular-nums"
                    data-testid="qr-number"
                >
                    {{ number }}
                </p>
            </div>
            <p
                v-if="signingIn"
                class="flex items-center justify-center gap-2 text-sm"
            >
                <Spinner class="size-4" /> Signing you in
            </p>
            <p
                v-else-if="waitingText"
                class="text-center text-sm text-muted-foreground"
            >
                {{ waitingText }}
            </p>
            <p v-else class="text-center text-sm text-muted-foreground">
                Waiting for approval...
            </p>
            <Button v-if="waitingText" as-child variant="outline">
                <Link :href="login()">Back to sign in</Link>
            </Button>
        </template>

        <template v-else-if="mode === 'owner'">
            <p class="text-sm text-muted-foreground">
                You are already signed in on this device. This code signs in a
                different device: open it there.
            </p>
            <Button as-child variant="outline">
                <Link :href="signInAnotherDevice()"
                    >Sign in another device</Link
                >
            </Button>
        </template>
    </div>
</template>
