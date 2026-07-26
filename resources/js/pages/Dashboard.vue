<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import PortalAppCard from '@/components/PortalAppCard.vue';
import { dashboard } from '@/routes';
import {
    index as bookmarksIndex,
    store as bookmarksStore,
} from '@/routes/bookmarks';
import { launch as launchApp, pin as pinRoute, reorder } from '@/routes/portal';

interface PortalApp {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    initials: string;
    accent: string | null;
    launch_url: string | null;
    category: string | null;
    can_access: boolean;
    pinned: boolean;
    position: number | null;
    status: 'up' | 'degraded' | 'down' | 'unknown' | null;
    requested: boolean;
}

interface PortalBookmark {
    id: number;
    url: string;
    title: string | null;
    domain: string | null;
    image: string | null;
    favicon: string | null;
    pinned: boolean;
    position: number | null;
}

interface RecentApp {
    id: number;
    name: string;
    initials: string;
    accent: string | null;
    launch_url: string | null;
}

const props = defineProps<{
    applications: PortalApp[];
    accessibleCount: number;
    bookmarks: PortalBookmark[];
    recentApps: RecentApp[];
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

// Local copies so a drag can reorder optimistically before the server confirms.
// Only uncategorized apps live in the reorderable main grid; categorized apps
// render in their own sections below.
const localApps = ref<PortalApp[]>(
    props.applications.filter((app) => !app.category),
);
const localBookmarks = ref<PortalBookmark[]>([...props.bookmarks]);

watch(
    () => props.applications,
    (value) => (localApps.value = value.filter((app) => !app.category)),
);
watch(
    () => props.bookmarks,
    (value) => (localBookmarks.value = [...value]),
);

const search = ref('');
const filter = ref<'all' | 'mine' | 'locked'>('all');

const query = computed(() => search.value.trim().toLowerCase());

// Reordering only makes sense against the full, unfiltered list.
const canReorder = computed(() => query.value === '' && filter.value === 'all');

function matches(app: PortalApp): boolean {
    if (filter.value === 'mine' && !app.can_access) {
        return false;
    }

    if (filter.value === 'locked' && app.can_access) {
        return false;
    }

    if (!query.value) {
        return true;
    }

    return `${app.name} ${app.description ?? ''} ${app.slug}`
        .toLowerCase()
        .includes(query.value);
}

const filtered = computed(() => localApps.value.filter(matches));

// Categorized apps grouped into their own sections (e.g. Games), in name order.
const categoryGroups = computed(() => {
    const groups = new Map<string, PortalApp[]>();

    for (const app of props.applications) {
        if (!app.category || !matches(app)) {
            continue;
        }

        (
            groups.get(app.category) ??
            groups.set(app.category, []).get(app.category)!
        ).push(app);
    }

    return [...groups.entries()]
        .map(([category, apps]) => ({ category, apps }))
        .sort((a, b) => a.category.localeCompare(b.category));
});

const filteredBookmarks = computed(() => {
    if (!query.value) {
        return localBookmarks.value;
    }

    return localBookmarks.value.filter((bookmark) =>
        `${bookmark.title ?? ''} ${bookmark.domain ?? ''} ${bookmark.url}`
            .toLowerCase()
            .includes(query.value),
    );
});

const brokenImages = ref(new Set<number>());
const brokenFavicons = ref(new Set<number>());

function showsImage(bookmark: PortalBookmark): boolean {
    return Boolean(bookmark.image) && !brokenImages.value.has(bookmark.id);
}

function showsFavicon(bookmark: PortalBookmark): boolean {
    return Boolean(bookmark.favicon) && !brokenFavicons.value.has(bookmark.id);
}

function monogram(value: string | null): string {
    return (value ?? '?').trim().charAt(0).toUpperCase();
}

function togglePin(type: 'app' | 'bookmark', id: number, pinned: boolean) {
    router.patch(
        pinRoute().url,
        { type, id, pinned: !pinned },
        { preserveScroll: true },
    );
}

// --- quick-add a bookmark without leaving the portal ---

const addingBookmark = ref(false);
const newBookmarkUrl = ref('');
const savingBookmark = ref(false);

function openAddBookmark() {
    addingBookmark.value = true;
}

function cancelAddBookmark() {
    addingBookmark.value = false;
    newBookmarkUrl.value = '';
}

function submitBookmark() {
    const url = newBookmarkUrl.value.trim();

    if (url === '' || savingBookmark.value) {
        return;
    }

    savingBookmark.value = true;

    // store() redirects back(), so Inertia reloads the portal props and the
    // new bookmark appears in place. No navigation away.
    router.post(
        bookmarksStore().url,
        { url },
        {
            preserveScroll: true,
            onSuccess: cancelAddBookmark,
            onFinish: () => (savingBookmark.value = false),
        },
    );
}

// --- drag to reorder ---

const dragging = ref<{ list: 'app' | 'bookmark'; index: number } | null>(null);

function onDragStart(list: 'app' | 'bookmark', index: number) {
    if (!canReorder.value) {
        return;
    }

    dragging.value = { list, index };
}

function onDrop(list: 'app' | 'bookmark', index: number) {
    const from = dragging.value;
    dragging.value = null;

    if (!from || from.list !== list || from.index === index) {
        return;
    }

    if (list === 'app') {
        const items = [...localApps.value];
        items.splice(index, 0, items.splice(from.index, 1)[0]);
        localApps.value = items;
        persist('app', items);

        return;
    }

    const items = [...localBookmarks.value];
    items.splice(index, 0, items.splice(from.index, 1)[0]);
    localBookmarks.value = items;
    persist('bookmark', items);
}

function persist(type: 'app' | 'bookmark', items: { id: number }[]) {
    router.patch(
        reorder().url,
        { type, ids: items.map((item) => item.id) },
        { preserveScroll: true, preserveState: true },
    );
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

        <div v-if="recentApps.length" class="flex flex-col gap-2">
            <h2
                class="font-mono text-xs font-semibold tracking-[0.16em] text-muted-foreground uppercase"
            >
                Recently used
            </h2>
            <div class="flex flex-wrap gap-2">
                <a
                    v-for="app in recentApps"
                    :key="app.id"
                    :href="launchApp(app.id).url"
                    class="group flex items-center gap-2.5 rounded-xl border border-border bg-card py-2 pr-4 pl-2 no-underline transition hover:-translate-y-0.5 hover:shadow-md"
                >
                    <AppIcon
                        :launch-url="app.launch_url"
                        :initials="app.initials"
                        :accent="app.accent"
                        size="sm"
                    />
                    <span class="text-sm font-semibold tracking-tight">{{
                        app.name
                    }}</span>
                </a>
            </div>
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
                    placeholder="Search apps and bookmarks..."
                    aria-label="Search apps and bookmarks"
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

        <h2
            class="font-mono text-xs font-semibold tracking-[0.16em] text-muted-foreground uppercase"
        >
            Connected apps
        </h2>

        <div class="grid grid-cols-[repeat(auto-fill,minmax(258px,1fr))] gap-4">
            <PortalAppCard
                v-for="(app, index) in filtered"
                :key="app.id"
                :app="app"
                :draggable="canReorder"
                :class="[
                    canReorder ? 'cursor-grab active:cursor-grabbing' : '',
                    dragging?.list === 'app' && dragging.index === index
                        ? 'opacity-40'
                        : '',
                ]"
                @dragstart="onDragStart('app', index)"
                @dragover.prevent
                @drop.prevent="onDrop('app', index)"
            />

            <p
                v-if="!filtered.length && !categoryGroups.length"
                class="col-span-full py-10 text-center text-sm text-muted-foreground"
            >
                No apps match that search.
            </p>
        </div>

        <template v-for="group in categoryGroups" :key="group.category">
            <h2
                class="font-mono text-xs font-semibold tracking-[0.16em] text-muted-foreground uppercase"
            >
                {{ group.category }}
            </h2>
            <div
                class="grid grid-cols-[repeat(auto-fill,minmax(258px,1fr))] gap-4"
            >
                <PortalAppCard
                    v-for="app in group.apps"
                    :key="app.id"
                    :app="app"
                />
            </div>
        </template>

        <template>
            <div class="flex items-center justify-between">
                <h2
                    class="font-mono text-xs font-semibold tracking-[0.16em] text-muted-foreground uppercase"
                >
                    Bookmarks
                </h2>
                <div class="flex items-center gap-3">
                    <form
                        v-if="addingBookmark"
                        class="flex items-center gap-2"
                        @submit.prevent="submitBookmark"
                    >
                        <input
                            v-model="newBookmarkUrl"
                            type="text"
                            inputmode="url"
                            autofocus
                            placeholder="Paste a URL…"
                            class="h-8 w-56 rounded-lg border border-border bg-card px-3 text-sm outline-none focus:border-brand"
                            @keydown.esc="cancelAddBookmark"
                        />
                        <button
                            type="submit"
                            :disabled="savingBookmark || !newBookmarkUrl.trim()"
                            class="inline-flex h-8 items-center rounded-lg bg-brand px-3 text-xs font-semibold text-white transition disabled:opacity-50"
                        >
                            {{ savingBookmark ? 'Saving…' : 'Save' }}
                        </button>
                        <button
                            type="button"
                            class="text-xs font-semibold text-muted-foreground transition hover:text-foreground"
                            @click="cancelAddBookmark"
                        >
                            Cancel
                        </button>
                    </form>
                    <button
                        v-else
                        type="button"
                        class="inline-flex items-center gap-1 text-xs font-semibold text-muted-foreground transition hover:text-foreground"
                        @click="openAddBookmark"
                    >
                        + Add
                    </button>
                    <Link
                        :href="bookmarksIndex()"
                        class="inline-flex items-center gap-1.5 text-xs font-semibold text-muted-foreground transition hover:text-foreground"
                    >
                        Manage
                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2.2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            class="size-3.5"
                        >
                            <path d="M5 12h14M13 6l6 6-6 6" />
                        </svg>
                    </Link>
                </div>
            </div>

            <div
                v-if="filteredBookmarks.length"
                class="grid grid-cols-[repeat(auto-fill,minmax(258px,1fr))] gap-4"
            >
                <a
                    v-for="(bookmark, index) in filteredBookmarks"
                    :key="bookmark.id"
                    :href="bookmark.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    :draggable="canReorder"
                    class="group relative flex min-h-[92px] items-center gap-3 rounded-xl border border-border bg-card p-4 no-underline transition hover:-translate-y-0.5 hover:border-brand hover:shadow-lg"
                    :class="[
                        canReorder ? 'cursor-grab active:cursor-grabbing' : '',
                        dragging?.list === 'bookmark' &&
                        dragging.index === index
                            ? 'opacity-40'
                            : '',
                    ]"
                    @dragstart="onDragStart('bookmark', index)"
                    @dragover.prevent
                    @drop.prevent="onDrop('bookmark', index)"
                >
                    <img
                        v-if="showsImage(bookmark)"
                        :src="bookmark.image!"
                        alt=""
                        class="size-11 shrink-0 rounded-lg object-cover"
                        @error="brokenImages.add(bookmark.id)"
                    />
                    <img
                        v-else-if="showsFavicon(bookmark)"
                        :src="bookmark.favicon!"
                        alt=""
                        class="size-11 shrink-0 rounded-lg bg-muted object-contain p-2.5"
                        @error="brokenFavicons.add(bookmark.id)"
                    />
                    <span
                        v-else
                        class="flex size-11 shrink-0 items-center justify-center rounded-lg bg-muted text-lg font-extrabold text-muted-foreground"
                    >
                        {{ monogram(bookmark.title ?? bookmark.domain) }}
                    </span>

                    <span class="min-w-0 flex-1">
                        <span
                            class="block truncate text-sm font-semibold tracking-tight"
                        >
                            {{ bookmark.title }}
                        </span>
                        <span
                            class="block truncate font-mono text-[11px] text-muted-foreground/70"
                        >
                            {{ bookmark.domain ?? bookmark.url }}
                        </span>
                    </span>

                    <button
                        type="button"
                        :aria-label="bookmark.pinned ? 'Unpin' : 'Pin'"
                        class="shrink-0 rounded-md p-1 transition"
                        :class="
                            bookmark.pinned
                                ? 'text-brand'
                                : 'text-muted-foreground/40 opacity-0 group-hover:opacity-100 hover:text-foreground'
                        "
                        @click.prevent.stop="
                            togglePin('bookmark', bookmark.id, bookmark.pinned)
                        "
                    >
                        <svg
                            viewBox="0 0 24 24"
                            :fill="bookmark.pinned ? 'currentColor' : 'none'"
                            stroke="currentColor"
                            stroke-width="1.8"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            class="size-4"
                        >
                            <path d="M12 17v5M9 10.8V4h6v6.8l2 3.2H7l2-3.2Z" />
                        </svg>
                    </button>
                </a>
            </div>
        </template>
    </div>
</template>
