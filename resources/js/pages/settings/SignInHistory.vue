<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { edit } from '@/routes/sign-in-history';

interface SignInEvent {
    id: number;
    method: string;
    ip_address: string | null;
    user_agent: string | null;
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
    other: 'Other',
};

function deviceLabel(ua: string | null): string {
    if (!ua) {
        return 'Unknown device';
    }

    const browser = /Edg/.test(ua)
        ? 'Edge'
        : /Chrome/.test(ua)
          ? 'Chrome'
          : /Firefox/.test(ua)
            ? 'Firefox'
            : /Safari/.test(ua)
              ? 'Safari'
              : 'Browser';

    const os = /Windows/.test(ua)
        ? 'Windows'
        : /Macintosh|Mac OS/.test(ua)
          ? 'macOS'
          : /iPhone|iPad/.test(ua)
            ? 'iOS'
            : /Android/.test(ua)
              ? 'Android'
              : /Linux/.test(ua)
                ? 'Linux'
                : 'Unknown OS';

    return `${browser} on ${os}`;
}
</script>

<template>
    <Head title="Sign-in history" />

    <div class="space-y-6">
        <header class="space-y-1">
            <h1 class="text-lg font-semibold tracking-tight">
                Sign-in history
            </h1>
            <p class="text-sm text-muted-foreground">
                Recent sign-ins to your account. We email you when one happens
                from a device we haven't seen before.
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
                        {{ methodLabels[event.method] ?? event.method }}
                        <span
                            v-if="event.application"
                            class="text-muted-foreground"
                        >
                            · via {{ event.application }}
                        </span>
                    </p>
                    <p class="mt-0.5 truncate text-xs text-muted-foreground">
                        {{ deviceLabel(event.user_agent) }} ·
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
