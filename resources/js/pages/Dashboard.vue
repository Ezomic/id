<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { dashboard } from '@/routes';

interface PortalApp {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    initials: string;
    accent: string | null;
    launch_url: string | null;
    can_access: boolean;
}

const props = defineProps<{
    applications: PortalApp[];
    accessibleCount: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Portal', href: dashboard() }],
    },
});

const page = usePage();
const firstName = computed(
    () => page.props.auth?.user?.name?.split(' ')[0] ?? 'there',
);

const search = ref('');
const filter = ref<'all' | 'mine' | 'locked'>('all');

const filtered = computed(() =>
    props.applications.filter((app) => {
        if (filter.value === 'mine' && !app.can_access) {
return false;
}

        if (filter.value === 'locked' && app.can_access) {
return false;
}

        const q = search.value.trim().toLowerCase();

        if (!q) {
return true;
}

        return `${app.name} ${app.description ?? ''} ${app.slug}`
            .toLowerCase()
            .includes(q);
    }),
);

function accent(app: PortalApp): string {
    return app.accent ?? '#B7863A';
}

const filters: { key: 'all' | 'mine' | 'locked'; label: string }[] = [
    { key: 'all', label: 'All' },
    { key: 'mine', label: 'My apps' },
    { key: 'locked', label: 'Locked' },
];
</script>

<template>
    <Head title="Portal" />

    <div class="flex flex-col gap-6 p-4">
        <div>
            <p
                class="font-mono text-xs font-semibold tracking-[0.16em] text-brand uppercase"
            >
                Your portal
            </p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight">
                Welcome back, {{ firstName }}.
            </h1>
            <p class="text-sm text-muted-foreground">
                You can reach {{ accessibleCount }} of
                {{ applications.length }} connected apps. Click one to sign
                straight in.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <div class="relative min-w-[220px] flex-1">
                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                >
                    <circle cx="11" cy="11" r="7" />
                    <path d="m21 21-4.3-4.3" />
                </svg>
                <input
                    v-model="search"
                    type="text"
                    placeholder="Search apps..."
                    aria-label="Search apps"
                    class="w-full rounded-xl border border-border bg-card py-2.5 pr-4 pl-10 text-sm transition outline-none focus:border-brand focus:ring-2 focus:ring-brand/25"
                />
            </div>
            <div
                class="flex gap-1 rounded-xl border border-border bg-muted/50 p-1"
            >
                <button
                    v-for="f in filters"
                    :key="f.key"
                    type="button"
                    class="rounded-lg px-3 py-1.5 text-sm font-medium transition"
                    :class="
                        filter === f.key
                            ? 'bg-card text-foreground shadow-sm'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    @click="filter = f.key"
                >
                    {{ f.label }}
                </button>
            </div>
        </div>

        <div class="grid grid-cols-[repeat(auto-fill,minmax(258px,1fr))] gap-4">
            <component
                :is="app.can_access ? 'a' : 'div'"
                v-for="app in filtered"
                :key="app.id"
                :href="
                    app.can_access ? (app.launch_url ?? undefined) : undefined
                "
                :style="{ '--app': accent(app) }"
                class="group relative flex min-h-[158px] flex-col overflow-hidden rounded-xl border border-border bg-card p-5 no-underline"
                :class="
                    app.can_access
                        ? 'transition hover:-translate-y-0.5 hover:border-[var(--app)] hover:shadow-lg'
                        : 'opacity-80'
                "
            >
                <span
                    class="absolute inset-y-0 left-0 w-1"
                    :style="{
                        background: app.can_access
                            ? accent(app)
                            : 'var(--border)',
                    }"
                />
                <span
                    class="mb-3 flex size-11 items-center justify-center rounded-xl text-lg font-extrabold text-white"
                    :style="
                        app.can_access
                            ? {
                                  background: `linear-gradient(150deg, color-mix(in srgb, ${accent(app)} 82%, white), ${accent(app)})`,
                              }
                            : {}
                    "
                    :class="
                        app.can_access
                            ? ''
                            : 'bg-muted text-muted-foreground grayscale'
                    "
                >
                    {{ app.initials }}
                </span>
                <h3 class="text-base font-semibold tracking-tight">
                    {{ app.name }}
                </h3>
                <p class="mt-0.5 text-sm text-muted-foreground">
                    {{ app.description }}
                </p>

                <div class="mt-auto flex items-center justify-between pt-4">
                    <template v-if="app.can_access">
                        <span
                            class="font-mono text-[11px] text-muted-foreground/70"
                        >
                            {{
                                app.launch_url?.replace(/^https?:\/\//, '') ??
                                app.slug
                            }}
                        </span>
                        <span
                            class="inline-flex items-center gap-1.5 text-xs font-semibold opacity-0 transition group-hover:opacity-100"
                            :style="{ color: accent(app) }"
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
                        v-else
                        class="inline-flex items-center gap-1.5 rounded-full border border-border bg-muted/50 px-2.5 py-1 text-xs font-medium text-muted-foreground"
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
                        No access
                    </span>
                </div>
            </component>

            <p
                v-if="!filtered.length"
                class="col-span-full py-10 text-center text-sm text-muted-foreground"
            >
                No apps match that search.
            </p>
        </div>
    </div>
</template>
