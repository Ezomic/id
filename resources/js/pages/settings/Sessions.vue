<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { destroy, destroyOthers, edit } from '@/routes/sessions';

interface Session {
    id: string;
    ip_address: string | null;
    user_agent: string | null;
    last_active_diff: string;
    is_current: boolean;
}

const props = defineProps<{ sessions: Session[] }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Sessions', href: edit() }],
    },
});

const page = usePage();
const sessionError = computed(
    () => (page.props.errors as Record<string, string>)?.session,
);
const hasOthers = computed(() => props.sessions.some((s) => !s.is_current));

function deviceLabel(ua: string | null): string {
    if (!ua) {
        return 'Unknown device';
    }

    const browser = /Edg/.test(ua)
        ? 'Edge'
        : /OPR|Opera/.test(ua)
          ? 'Opera'
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

function revoke(id: string) {
    router.delete(destroy(id).url, { preserveScroll: true });
}

function revokeOthers() {
    router.delete(destroyOthers().url, { preserveScroll: true });
}
</script>

<template>
    <Head title="Sessions" />

    <div class="space-y-6">
        <header class="space-y-1">
            <h1 class="text-lg font-semibold tracking-tight">
                Active sessions
            </h1>
            <p class="text-sm text-muted-foreground">
                Devices signed in to your account. Revoke any you don't
                recognise; that browser is signed out on its next request.
            </p>
        </header>

        <p v-if="sessionError" class="text-sm font-medium text-red-600">
            {{ sessionError }}
        </p>

        <ul class="divide-y divide-border rounded-xl border border-border">
            <li
                v-for="session in props.sessions"
                :key="session.id"
                class="flex items-center justify-between gap-4 p-4"
            >
                <div class="min-w-0">
                    <p class="flex items-center gap-2 text-sm font-semibold">
                        {{ deviceLabel(session.user_agent) }}
                        <span
                            v-if="session.is_current"
                            class="rounded-full bg-emerald-500/10 px-2 py-0.5 text-[11px] font-semibold text-emerald-600"
                        >
                            This device
                        </span>
                    </p>
                    <p class="mt-0.5 truncate text-xs text-muted-foreground">
                        {{ session.ip_address ?? 'Unknown IP' }} · active
                        {{ session.last_active_diff }}
                    </p>
                </div>

                <Button
                    v-if="!session.is_current"
                    variant="outline"
                    size="sm"
                    @click="revoke(session.id)"
                >
                    Revoke
                </Button>
            </li>
        </ul>

        <Button v-if="hasOthers" variant="destructive" @click="revokeOthers">
            Sign out all other sessions
        </Button>
    </div>
</template>
