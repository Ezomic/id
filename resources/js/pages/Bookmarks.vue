<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import {
    index as bookmarksIndex,
    store,
    update,
    destroy,
} from '@/routes/bookmarks';

interface Bookmark {
    id: number;
    url: string;
    title: string | null;
    domain: string | null;
    image: string | null;
    favicon: string | null;
    note: string | null;
    tags: string[];
    read: boolean;
    archived: boolean;
    saved_ago: string | null;
}

const props = defineProps<{
    bookmarks: Bookmark[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Bookmarks', href: bookmarksIndex() }],
    },
});

const search = ref('');
const filter = ref<'unread' | 'all' | 'archived'>('unread');
const activeTag = ref<string | null>(null);

const counts = computed(() => ({
    all: props.bookmarks.filter((b) => !b.archived).length,
    unread: props.bookmarks.filter((b) => !b.archived && !b.read).length,
    archived: props.bookmarks.filter((b) => b.archived).length,
}));

const tags = computed(() =>
    [...new Set(props.bookmarks.flatMap((b) => b.tags))].sort(),
);

const filtered = computed(() =>
    props.bookmarks.filter((b) => {
        if (filter.value === 'archived' && !b.archived) {
            return false;
        }

        if (filter.value === 'unread' && (b.archived || b.read)) {
            return false;
        }

        if (filter.value === 'all' && b.archived) {
            return false;
        }

        if (activeTag.value && !b.tags.includes(activeTag.value)) {
            return false;
        }

        const q = search.value.trim().toLowerCase();

        if (!q) {
            return true;
        }

        return `${b.title ?? ''} ${b.domain ?? ''} ${b.tags.join(' ')}`
            .toLowerCase()
            .includes(q);
    }),
);

const addForm = useForm<{ url: string }>({ url: '' });

function save() {
    if (!addForm.url.trim()) {
        return;
    }

    addForm.post(store.url(), {
        preserveScroll: true,
        onSuccess: () => {
            addForm.reset();
            toast.success('Link saved');
        },
    });
}

function patch(
    bookmark: Bookmark,
    payload: Record<string, boolean>,
    message: string,
) {
    router.put(update(bookmark.id).url, payload, {
        preserveScroll: true,
        onSuccess: () => toast.success(message),
    });
}

function toggleRead(bookmark: Bookmark) {
    patch(
        bookmark,
        { read: !bookmark.read },
        bookmark.read ? 'Marked unread' : 'Marked read',
    );
}

function toggleArchived(bookmark: Bookmark) {
    patch(
        bookmark,
        { archived: !bookmark.archived },
        bookmark.archived ? 'Restored' : 'Archived',
    );
}

function remove(bookmark: Bookmark) {
    router.delete(destroy(bookmark.id).url, {
        preserveScroll: true,
        onSuccess: () => toast.success('Deleted'),
    });
}

/* A stable colour per domain so cards without an og:image still read apart. */
const PALETTE = [
    '#3B82F6',
    '#7C6BF0',
    '#10B981',
    '#E0A83E',
    '#14B8A6',
    '#E5484D',
    '#6366F1',
    '#EC4899',
    '#F97316',
    '#22C55E',
];

function tint(bookmark: Bookmark): string {
    const key = bookmark.domain ?? bookmark.url;
    let hash = 0;

    for (let i = 0; i < key.length; i++) {
        hash = (hash * 31 + key.charCodeAt(i)) % 997;
    }

    return PALETTE[hash % PALETTE.length];
}

function monogram(bookmark: Bookmark): string {
    return (bookmark.domain ?? bookmark.url)
        .replace(/^www\./, '')
        .charAt(0)
        .toUpperCase();
}

/* A preview image can rot after it is saved; fall back to the monogram. */
const brokenImages = ref(new Set<number>());
const brokenFavicons = ref(new Set<number>());

function showsImage(bookmark: Bookmark): boolean {
    return Boolean(bookmark.image) && !brokenImages.value.has(bookmark.id);
}

function showsFavicon(bookmark: Bookmark): boolean {
    return Boolean(bookmark.favicon) && !brokenFavicons.value.has(bookmark.id);
}

const filters: { key: 'unread' | 'all' | 'archived'; label: string }[] = [
    { key: 'unread', label: 'Unread' },
    { key: 'all', label: 'All' },
    { key: 'archived', label: 'Archived' },
];
</script>

<template>
    <Head title="Bookmarks" />

    <div class="flex flex-col gap-6 p-4">
        <div>
            <p
                class="font-mono text-xs font-semibold tracking-[0.16em] text-brand uppercase"
            >
                Read later
            </p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight">
                Bookmarks
            </h1>
            <p class="text-sm text-muted-foreground tabular-nums">
                {{ counts.all }} saved &middot; {{ counts.unread }} unread
            </p>
        </div>

        <form class="flex gap-2" @submit.prevent="save">
            <div class="relative flex-1">
                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    class="absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-muted-foreground"
                >
                    <path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1" />
                    <path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1" />
                </svg>
                <input
                    v-model="addForm.url"
                    type="text"
                    placeholder="Paste a link to save..."
                    aria-label="Link to save"
                    class="w-full rounded-xl border border-border bg-card py-3 pr-4 pl-10 text-sm transition outline-none focus:border-brand focus:ring-2 focus:ring-brand/25"
                />
            </div>
            <button
                type="submit"
                :disabled="addForm.processing"
                class="inline-flex items-center gap-2 rounded-xl bg-brand px-5 text-sm font-semibold text-brand-foreground transition hover:brightness-105 disabled:opacity-60"
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
                    <path d="M12 5v14M5 12h14" />
                </svg>
                Save link
            </button>
        </form>

        <div class="flex flex-wrap items-center gap-3">
            <div class="relative min-w-[200px] flex-1">
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
                    placeholder="Search bookmarks..."
                    aria-label="Search bookmarks"
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
                    class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition"
                    :class="
                        filter === f.key
                            ? 'bg-card text-foreground shadow-sm'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    @click="filter = f.key"
                >
                    {{ f.label }}
                    <span
                        class="text-xs tabular-nums"
                        :class="
                            filter === f.key
                                ? 'text-brand'
                                : 'text-muted-foreground/70'
                        "
                    >
                        {{ counts[f.key] }}
                    </span>
                </button>
            </div>
        </div>

        <div v-if="tags.length" class="flex flex-wrap gap-2">
            <button
                v-for="tag in tags"
                :key="tag"
                type="button"
                class="rounded-full border px-3 py-1 text-xs font-semibold transition"
                :class="
                    activeTag === tag
                        ? 'border-brand/45 bg-brand/12 text-brand'
                        : 'border-border bg-card text-muted-foreground hover:text-foreground'
                "
                @click="activeTag = activeTag === tag ? null : tag"
            >
                #{{ tag }}
            </button>
        </div>

        <div class="grid grid-cols-[repeat(auto-fill,minmax(268px,1fr))] gap-4">
            <div
                v-for="bookmark in filtered"
                :key="bookmark.id"
                class="group relative flex flex-col overflow-hidden rounded-xl border border-border bg-card transition hover:-translate-y-0.5 hover:border-border/80 hover:shadow-lg"
            >
                <a
                    :href="bookmark.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="relative grid aspect-video place-items-center overflow-hidden no-underline"
                    :style="
                        showsImage(bookmark)
                            ? {}
                            : {
                                  background: `linear-gradient(150deg, ${tint(bookmark)}, color-mix(in srgb, ${tint(bookmark)} 72%, black))`,
                              }
                    "
                >
                    <img
                        v-if="showsImage(bookmark)"
                        :src="bookmark.image!"
                        alt=""
                        class="size-full object-cover"
                        loading="lazy"
                        @error="brokenImages.add(bookmark.id)"
                    />
                    <img
                        v-else-if="showsFavicon(bookmark)"
                        :src="bookmark.favicon!"
                        alt=""
                        class="size-12 rounded-xl bg-white/10 object-contain p-2.5 backdrop-blur"
                        loading="lazy"
                        @error="brokenFavicons.add(bookmark.id)"
                    />
                    <span
                        v-else
                        class="text-4xl font-extrabold text-white/90"
                        aria-hidden="true"
                    >
                        {{ monogram(bookmark) }}
                    </span>
                    <span
                        v-if="!bookmark.read"
                        class="absolute top-3 left-3 size-2.5 rounded-full bg-brand ring-3 ring-card"
                        title="Unread"
                    />
                </a>

                <div class="flex flex-1 flex-col gap-2 p-4">
                    <p class="text-xs text-muted-foreground">
                        {{ bookmark.domain }}
                    </p>
                    <a
                        :href="bookmark.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="line-clamp-2 text-sm leading-snug font-semibold no-underline"
                        :class="bookmark.read ? 'text-foreground/75' : ''"
                    >
                        {{ bookmark.title }}
                    </a>

                    <div
                        v-if="bookmark.tags.length"
                        class="flex flex-wrap gap-1.5"
                    >
                        <span
                            v-for="tag in bookmark.tags"
                            :key="tag"
                            class="rounded-md border border-border bg-muted/50 px-2 py-0.5 text-[11px] font-semibold text-muted-foreground"
                        >
                            #{{ tag }}
                        </span>
                    </div>

                    <div class="mt-auto flex items-center justify-between pt-1">
                        <span class="text-[11px] text-muted-foreground/70">
                            {{ bookmark.saved_ago }}
                        </span>
                        <div
                            class="flex gap-1 opacity-0 transition group-hover:opacity-100 focus-within:opacity-100"
                        >
                            <button
                                type="button"
                                class="grid size-7 place-items-center rounded-md border border-border bg-muted/50 transition hover:text-foreground"
                                :class="
                                    bookmark.read
                                        ? 'text-emerald-600 dark:text-emerald-400'
                                        : 'text-muted-foreground'
                                "
                                :title="
                                    bookmark.read ? 'Mark unread' : 'Mark read'
                                "
                                :aria-label="
                                    bookmark.read ? 'Mark unread' : 'Mark read'
                                "
                                @click="toggleRead(bookmark)"
                            >
                                <svg
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="2.4"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    class="size-3.5"
                                >
                                    <path d="M20 6 9 17l-5-5" />
                                </svg>
                            </button>
                            <button
                                type="button"
                                class="grid size-7 place-items-center rounded-md border border-border bg-muted/50 text-muted-foreground transition hover:text-foreground"
                                :title="
                                    bookmark.archived ? 'Restore' : 'Archive'
                                "
                                :aria-label="
                                    bookmark.archived ? 'Restore' : 'Archive'
                                "
                                @click="toggleArchived(bookmark)"
                            >
                                <svg
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    class="size-3.5"
                                >
                                    <rect
                                        x="3"
                                        y="4"
                                        width="18"
                                        height="4"
                                        rx="1"
                                    />
                                    <path
                                        d="M5 8v11a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8M10 12h4"
                                    />
                                </svg>
                            </button>
                            <button
                                type="button"
                                class="grid size-7 place-items-center rounded-md border border-border bg-muted/50 text-muted-foreground transition hover:border-destructive hover:text-destructive"
                                title="Delete"
                                aria-label="Delete bookmark"
                                @click="remove(bookmark)"
                            >
                                <svg
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    class="size-3.5"
                                >
                                    <path
                                        d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"
                                    />
                                    <path
                                        d="M6 7l1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13"
                                    />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <p
                v-if="!filtered.length"
                class="col-span-full py-14 text-center text-sm text-muted-foreground"
            >
                {{
                    filter === 'archived'
                        ? 'Nothing archived yet.'
                        : filter === 'unread'
                          ? 'Nothing left to read.'
                          : 'No bookmarks yet. Paste a link above.'
                }}
            </p>
        </div>
    </div>
</template>
