<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { edit } from '@/routes/sign-in-history';
import {
    destroy as untrustDevice,
    store as trustDevice,
} from '@/routes/trusted-devices';

interface SignInEvent {
    id: number;
    method: string;
    outcome: string;
    ip_address: string | null;
    device: string;
    application: string | null;
    created_at_diff: string | null;
}

interface TrustedDevice {
    id: number;
    label: string;
    expires_diff: string;
    is_current: boolean;
}

const props = defineProps<{
    events: SignInEvent[];
    currentDeviceTrusted: boolean;
    trustedDevices: TrustedDevice[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Sign-in history', href: edit() }],
    },
});

const methodLabels: Record<string, string> = {
    passkey: 'Passkey',
    email_code: 'Email code',
    recovery_code: 'Recovery code',
    other: 'Other',
};
</script>

<template>
    <Head title="Sign-in history" />

    <div class="space-y-6">
        <header class="space-y-1">
            <h1 class="text-lg font-semibold tracking-tight">
                Sign-in history
            </h1>
            <p class="text-sm text-muted-foreground">
                Recent sign-ins to your account, successful and failed. We email
                you when one happens from a device or network we haven't seen
                before.
            </p>
        </header>

        <div class="rounded-xl border border-border p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-semibold">This device</p>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        Trusting it stops the new-device emails for this
                        browser. A sign-in from a new network still tells you,
                        because travel and theft look different.
                    </p>
                </div>
                <Form
                    v-if="!props.currentDeviceTrusted"
                    v-bind="trustDevice.form()"
                    v-slot="{ processing }"
                >
                    <Button size="sm" variant="outline" :disabled="processing">
                        Trust this device
                    </Button>
                </Form>
                <span v-else class="text-xs text-muted-foreground">
                    Trusted
                </span>
            </div>

            <ul
                v-if="props.trustedDevices.length"
                class="mt-4 divide-y divide-border border-t border-border"
            >
                <li
                    v-for="device in props.trustedDevices"
                    :key="device.id"
                    class="flex items-center justify-between gap-4 py-3"
                >
                    <div class="min-w-0">
                        <p class="text-sm">
                            {{ device.label }}
                            <span
                                v-if="device.is_current"
                                class="text-muted-foreground"
                            >
                                · this one
                            </span>
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Trust expires {{ device.expires_diff }}
                        </p>
                    </div>
                    <Form
                        v-bind="untrustDevice.form(device.id)"
                        v-slot="{ processing }"
                    >
                        <Button
                            size="sm"
                            variant="ghost"
                            :disabled="processing"
                        >
                            Revoke trust
                        </Button>
                    </Form>
                </li>
            </ul>
        </div>

        <ul class="divide-y divide-border rounded-xl border border-border">
            <li
                v-for="event in events"
                :key="event.id"
                class="flex items-center justify-between gap-4 p-4"
            >
                <div class="min-w-0">
                    <p class="text-sm font-semibold">
                        <span
                            v-if="event.outcome === 'failure'"
                            class="mr-1.5 rounded bg-destructive/10 px-1.5 py-0.5 text-xs font-medium text-destructive"
                        >
                            Failed
                        </span>
                        {{ methodLabels[event.method] ?? event.method }}
                        <span
                            v-if="event.application"
                            class="text-muted-foreground"
                        >
                            · via {{ event.application }}
                        </span>
                    </p>
                    <p class="mt-0.5 truncate text-xs text-muted-foreground">
                        {{ event.device }} ·
                        {{ event.ip_address ?? 'Unknown IP' }}
                    </p>
                </div>
                <span class="shrink-0 text-xs text-muted-foreground">
                    {{ event.created_at_diff }}
                </span>
            </li>
            <li v-if="!events.length" class="p-4 text-sm text-muted-foreground">
                No sign-ins recorded yet.
            </li>
        </ul>
    </div>
</template>
