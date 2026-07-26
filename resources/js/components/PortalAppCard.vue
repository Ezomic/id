<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import AppIcon from '@/components/AppIcon.vue';
import { store as requestAccessRoute } from '@/routes/access-requests';
import { launch as launchApp, pin as pinRoute } from '@/routes/portal';

interface PortalApp {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    initials: string;
    accent: string | null;
    launch_url: string | null;
    can_access: boolean;
    pinned: boolean;
    status: 'up' | 'degraded' | 'down' | 'unknown' | null;
    requested: boolean;
}

const props = defineProps<{ app: PortalApp }>();

const accent = props.app.accent ?? '#B7863A';

const statusDotClass: Record<string, string> = {
    up: 'bg-emerald-500',
    degraded: 'bg-amber-500',
    down: 'bg-red-500',
};

const statusLabel: Record<string, string> = {
    up: 'Operational',
    degraded: 'Degraded',
    down: 'Down',
};

function showsStatus(): boolean {
    return props.app.status != null && props.app.status in statusDotClass;
}

function togglePin() {
    router.patch(
        pinRoute().url,
        { type: 'app', id: props.app.id, pinned: !props.app.pinned },
        { preserveScroll: true },
    );
}

function requestAccess() {
    router.post(
        requestAccessRoute().url,
        { application_id: props.app.id },
        { preserveScroll: true },
    );
}
</script>

<template>
    <component
        :is="app.can_access ? 'a' : 'div'"
        :href="app.can_access ? launchApp(app.id).url : undefined"
        :style="{ '--app': accent }"
        class="group relative flex min-h-[158px] flex-col overflow-hidden rounded-xl border border-border bg-card p-5 no-underline"
        :class="
            app.can_access
                ? 'transition hover:-translate-y-0.5 hover:border-[var(--app)] hover:shadow-lg'
                : 'opacity-80'
        "
    >
        <span
            class="absolute inset-y-0 left-0 w-1"
            :style="{ background: app.can_access ? accent : 'var(--border)' }"
        />

        <button
            v-if="app.can_access"
            type="button"
            :aria-label="app.pinned ? 'Unpin' : 'Pin'"
            class="absolute top-3 right-3 rounded-md p-1 transition"
            :class="
                app.pinned
                    ? 'text-brand'
                    : 'text-muted-foreground/40 opacity-0 group-hover:opacity-100 hover:text-foreground'
            "
            @click.prevent.stop="togglePin"
        >
            <svg
                viewBox="0 0 24 24"
                :fill="app.pinned ? 'currentColor' : 'none'"
                stroke="currentColor"
                stroke-width="1.8"
                stroke-linecap="round"
                stroke-linejoin="round"
                class="size-4"
            >
                <path d="M12 17v5M9 10.8V4h6v6.8l2 3.2H7l2-3.2Z" />
            </svg>
        </button>

        <AppIcon
            :launch-url="app.launch_url"
            :initials="app.initials"
            :accent="app.accent"
            :disabled="!app.can_access"
            size="md"
            class="mb-3"
        />
        <h3
            class="flex items-center gap-2 text-base font-semibold tracking-tight"
        >
            <span
                v-if="showsStatus()"
                class="size-2 shrink-0 rounded-full"
                :class="statusDotClass[app.status!]"
                :title="statusLabel[app.status!]"
            />
            {{ app.name }}
        </h3>
        <p class="mt-0.5 text-sm text-muted-foreground">
            {{ app.description }}
        </p>

        <div class="mt-auto flex items-center justify-between pt-4">
            <template v-if="app.can_access">
                <span class="font-mono text-[11px] text-muted-foreground/70">
                    {{
                        app.launch_url?.replace(/^https?:\/\//, '') ?? app.slug
                    }}
                </span>
                <span
                    class="inline-flex items-center gap-1.5 text-xs font-semibold opacity-0 transition group-hover:opacity-100"
                    :style="{ color: accent }"
                >
                    Launch
                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2.2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        class="size-3.5"
                    >
                        <path d="M7 17 17 7M8 7h9v9" />
                    </svg>
                </span>
            </template>
            <span
                v-else-if="app.requested"
                class="inline-flex items-center gap-1.5 rounded-full border border-border bg-muted/50 px-2.5 py-1 text-xs font-medium text-muted-foreground"
            >
                Access requested
            </span>
            <button
                v-else
                type="button"
                class="inline-flex items-center gap-1.5 rounded-full border border-border bg-muted/50 px-2.5 py-1 text-xs font-medium text-muted-foreground transition hover:border-brand hover:text-foreground"
                @click.prevent.stop="requestAccess"
            >
                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    class="size-3"
                >
                    <rect x="4" y="11" width="16" height="9" rx="2" />
                    <path d="M8 11V8a4 4 0 0 1 8 0v3" />
                </svg>
                Request access
            </button>
        </div>
    </component>
</template>
