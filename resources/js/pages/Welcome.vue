<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { dashboard, login } from '@/routes';

interface LandingApp {
    name: string;
    slug: string;
    initials: string;
    accent: string | null;
}

const props = defineProps<{
    applications: LandingApp[];
}>();

const page = usePage();
const user = computed(() => page.props.auth?.user ?? null);

const hubApps = computed(() => props.applications.slice(0, 8));

function nodeStyle(index: number, total: number) {
    const angle = (Math.PI * 2 * index) / total - Math.PI / 2;
    const radius = 40;

    return {
        left: `${50 + Math.cos(angle) * radius}%`,
        top: `${50 + Math.sin(angle) * radius}%`,
        animationDelay: `${0.4 + index * 0.08}s`,
    };
}

function linePoint(index: number, total: number) {
    const angle = (Math.PI * 2 * index) / total - Math.PI / 2;
    const radius = 150;

    return {
        x: 200 + Math.cos(angle) * radius,
        y: 200 + Math.sin(angle) * radius,
    };
}

function accent(app: LandingApp): string {
    return app.accent ?? '#B7863A';
}
</script>

<template>
    <Head title="Sign in" />

    <div
        class="relative min-h-screen overflow-hidden bg-background text-foreground"
    >
        <div
            class="pointer-events-none absolute inset-0 -z-10 bg-[radial-gradient(60%_50%_at_75%_10%,color-mix(in_srgb,var(--brand)_16%,transparent),transparent)]"
        />

        <header
            class="mx-auto flex max-w-6xl items-center justify-between px-6 py-6"
        >
            <div class="flex items-center gap-3">
                <span
                    class="flex size-9 items-center justify-center rounded-[11px] bg-brand text-brand-foreground shadow-sm"
                >
                    <svg viewBox="0 0 40 40" fill="none" class="size-5">
                        <path
                            d="M14 10v20M20 10l7 10-7 10"
                            stroke="currentColor"
                            stroke-width="3.4"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                    </svg>
                </span>
                <div class="leading-tight">
                    <p class="font-semibold tracking-tight">
                        Thijssensoftware
                        <span class="text-muted-foreground">ID</span>
                    </p>
                    <p
                        class="font-mono text-[10px] tracking-[0.14em] text-muted-foreground/70 uppercase"
                    >
                        identity &middot; single sign-on
                    </p>
                </div>
            </div>
            <Link
                v-if="user"
                :href="dashboard()"
                class="rounded-lg border border-border bg-card px-5 py-2 text-sm font-medium transition hover:border-brand"
            >
                Open portal
            </Link>
            <Link
                v-else
                :href="login()"
                class="rounded-lg border border-border bg-card px-5 py-2 text-sm font-medium transition hover:border-brand"
            >
                Sign in
            </Link>
        </header>

        <main
            class="mx-auto grid max-w-6xl items-center gap-10 px-6 pt-8 pb-20 lg:grid-cols-2 lg:pt-16"
        >
            <div>
                <p
                    class="font-mono text-xs font-semibold tracking-[0.16em] text-brand uppercase"
                >
                    One account &middot; every app
                </p>
                <h1
                    class="mt-4 text-4xl font-bold tracking-tight text-balance sm:text-5xl lg:text-6xl"
                >
                    Your single key to the
                    <span class="text-brand">Thijssensoftware</span> suite.
                </h1>
                <p class="mt-5 max-w-md text-lg text-muted-foreground">
                    Sign in once and reach every connected app. No passwords,
                    ever, only passkeys and one-time email codes.
                </p>

                <div class="mt-8 flex flex-wrap gap-3">
                    <Link
                        :href="user ? dashboard() : login()"
                        class="inline-flex items-center gap-2 rounded-xl bg-brand px-5 py-3 text-sm font-semibold text-brand-foreground shadow-sm transition hover:brightness-105"
                    >
                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2.2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            class="size-4"
                        >
                            <path
                                d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 17l5-5-5-5M15 12H3"
                            />
                        </svg>
                        {{
                            user
                                ? 'Go to your portal'
                                : 'Sign in with Thijssensoftware'
                        }}
                    </Link>
                </div>

                <div class="mt-8 flex flex-wrap gap-x-5 gap-y-2">
                    <span
                        v-for="feature in [
                            'Passkey & email-code sign in',
                            'No password stored',
                            'Per-app access control',
                        ]"
                        :key="feature"
                        class="inline-flex items-center gap-2 text-sm text-muted-foreground"
                    >
                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2.4"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            class="size-4 text-brand"
                        >
                            <path d="M20 6 9 17l-5-5" />
                        </svg>
                        {{ feature }}
                    </span>
                </div>
            </div>

            <div class="relative mx-auto aspect-square w-full max-w-[440px]">
                <svg
                    viewBox="0 0 400 400"
                    class="absolute inset-0 size-full"
                    aria-hidden="true"
                >
                    <line
                        v-for="(app, i) in hubApps"
                        :key="app.slug"
                        x1="200"
                        y1="200"
                        :x2="linePoint(i, hubApps.length).x"
                        :y2="linePoint(i, hubApps.length).y"
                        stroke="var(--border)"
                        stroke-width="1.4"
                        stroke-dasharray="4 5"
                    />
                </svg>

                <span
                    class="absolute top-1/2 left-1/2 z-10 flex size-24 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-[26px] bg-brand text-brand-foreground shadow-lg"
                >
                    <svg viewBox="0 0 40 40" fill="none" class="size-12">
                        <path
                            d="M14 10v20M20 10l7 10-7 10"
                            stroke="currentColor"
                            stroke-width="3.4"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                    </svg>
                </span>

                <span
                    v-for="(app, i) in hubApps"
                    :key="app.slug"
                    class="node absolute z-[5] flex size-[52px] -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-2xl border border-border bg-card text-base font-extrabold text-white shadow-sm"
                    :style="nodeStyle(i, hubApps.length)"
                    :title="app.name"
                >
                    <span
                        class="flex size-9 items-center justify-center rounded-[10px]"
                        :style="{
                            background: `linear-gradient(150deg, ${accent(app)}, color-mix(in srgb, ${accent(app)} 78%, black))`,
                        }"
                    >
                        {{ app.initials }}
                    </span>
                </span>
            </div>
        </main>

        <section class="border-t border-border" v-if="applications.length">
            <div
                class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-7 gap-y-3 px-6 py-6"
            >
                <span
                    class="font-mono text-[11px] tracking-[0.14em] text-muted-foreground/70 uppercase"
                >
                    Connected platforms
                </span>
                <span
                    v-for="app in applications"
                    :key="app.slug"
                    class="inline-flex items-center gap-2 text-sm font-semibold text-foreground/80"
                >
                    <span
                        class="flex size-[22px] items-center justify-center rounded-md text-[11px] font-extrabold text-white"
                        :style="{ background: accent(app) }"
                    >
                        {{ app.initials }}
                    </span>
                    {{ app.name }}
                </span>
            </div>
        </section>
    </div>
</template>

<style scoped>
.node {
    opacity: 0;
    animation: node-pop 0.5s ease forwards;
}
@keyframes node-pop {
    from {
        opacity: 0;
        transform: translate(-50%, -50%) scale(0.6);
    }
    to {
        opacity: 1;
        transform: translate(-50%, -50%) scale(1);
    }
}
@media (prefers-reduced-motion: reduce) {
    .node {
        opacity: 1;
        animation: none;
    }
}
</style>
