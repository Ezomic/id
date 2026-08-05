<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { edit } from '@/routes/sign-in-history';

interface SignInEvent {
    id: number;
    method: string;
    outcome: string;
    ip_address: string | null;
    device: string;
    application: string | null;
    created_at_diff: string | null;
}

defineProps<{ events: SignInEvent[] }>();

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
