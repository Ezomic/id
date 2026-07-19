<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { index as appsIndex, store, update } from '@/routes/admin/applications';

interface ManagedApp {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    initials: string;
    accent: string | null;
    launch_url: string | null;
    redirect_uri: string | null;
    client_id: string | null;
    active: boolean;
    user_ids: number[];
    users_count: number;
}

interface AccessUser {
    id: number;
    name: string;
    email: string;
    initials: string;
}

const props = defineProps<{
    applications: ManagedApp[];
    users: AccessUser[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Applications', href: appsIndex() }],
    },
});

const page = usePage();

const SWATCHES = [
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

const userMap = computed(() => new Map(props.users.map((u) => [u.id, u])));

function accent(app: ManagedApp): string {
    return app.accent ?? '#B7863A';
}

function maskId(id: string | null): string {
    if (!id) {
return '—';
}

    return id.length > 14 ? `${id.slice(0, 8)}…${id.slice(-4)}` : id;
}

function shortRedirect(uri: string | null): string {
    if (!uri) {
return '—';
}

    return uri.replace(/^https?:\/\//, '');
}

async function copy(value: string | null, label: string) {
    if (!value) {
return;
}

    await navigator.clipboard.writeText(value);
    toast.success(`${label} copied`);
}

const stats = computed(() => ({
    total: props.applications.length,
    active: props.applications.filter((a) => a.active).length,
    grants: props.applications.reduce((sum, a) => sum + a.users_count, 0),
}));

/* ---------- edit drawer ---------- */
const editOpen = ref(false);
const editingId = ref<number | null>(null);
const editingName = ref('');

const editForm = useForm<{
    name: string;
    slug: string;
    description: string;
    initials: string;
    accent: string;
    launch_url: string;
    redirect_uri: string;
    active: boolean;
    users: number[];
}>({
    name: '',
    slug: '',
    description: '',
    initials: '',
    accent: '',
    launch_url: '',
    redirect_uri: '',
    active: true,
    users: [],
});

function openEdit(app: ManagedApp) {
    editingId.value = app.id;
    editingName.value = app.name;
    editForm.defaults({
        name: app.name,
        slug: app.slug,
        description: app.description ?? '',
        initials: app.initials,
        accent: app.accent ?? '#B7863A',
        launch_url: app.launch_url ?? '',
        redirect_uri: app.redirect_uri ?? '',
        active: app.active,
        users: [...app.user_ids],
    });
    editForm.reset();
    editForm.clearErrors();
    editOpen.value = true;
}

function toggleUser(id: number) {
    editForm.users = editForm.users.includes(id)
        ? editForm.users.filter((v) => v !== id)
        : [...editForm.users, id];
}

function saveEdit() {
    if (editingId.value === null) {
return;
}

    editForm.put(update(editingId.value).url, {
        preserveScroll: true,
        onSuccess: () => {
            editOpen.value = false;
            toast.success('Application saved');
        },
    });
}

/* ---------- register modal ---------- */
const registerOpen = ref(false);
const regStep = ref<'form' | 'done'>('form');
const createdClient = ref<{
    name: string;
    client_id: string;
    client_secret: string;
} | null>(null);

const createForm = useForm<{
    name: string;
    slug: string;
    description: string;
    initials: string;
    accent: string;
    launch_url: string;
    redirect_uri: string;
}>({
    name: '',
    slug: '',
    description: '',
    initials: '',
    accent: SWATCHES[0],
    launch_url: '',
    redirect_uri: '',
});

function openRegister() {
    createForm.reset();
    createForm.clearErrors();
    createdClient.value = null;
    regStep.value = 'form';
    registerOpen.value = true;
}

function submitRegister() {
    createForm.post(store.url(), {
        preserveScroll: true,
        onSuccess: () => {
            const flashed = page.props.flash?.createdClient ?? null;

            if (flashed) {
                createdClient.value = flashed;
                regStep.value = 'done';
            } else {
                registerOpen.value = false;
            }
        },
    });
}
</script>

<template>
    <Head title="Applications" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <Heading
                title="Applications & single sign-on"
                description="Register OAuth clients, tune redirect URLs and control who gets in."
            />
            <Button class="gap-2" @click="openRegister">
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
                Register application
            </Button>
        </div>

        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
            <div class="rounded-xl border border-border bg-card p-4">
                <p
                    class="font-mono text-[11px] tracking-[0.1em] text-muted-foreground uppercase"
                >
                    Applications
                </p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">
                    {{ stats.total }}
                    <span class="text-sm font-normal text-muted-foreground"
                        >&middot; {{ stats.active }} active</span
                    >
                </p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4">
                <p
                    class="font-mono text-[11px] tracking-[0.1em] text-muted-foreground uppercase"
                >
                    People
                </p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">
                    {{ users.length }}
                </p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4">
                <p
                    class="font-mono text-[11px] tracking-[0.1em] text-muted-foreground uppercase"
                >
                    Access grants
                </p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">
                    {{ stats.grants }}
                </p>
            </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-border bg-card">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] border-collapse text-sm">
                    <thead>
                        <tr class="border-b border-border">
                            <th
                                v-for="h in [
                                    'Application',
                                    'Redirect URI',
                                    'Client ID',
                                    'Access',
                                    'Status',
                                    '',
                                ]"
                                :key="h"
                                class="px-5 py-3 text-left font-mono text-[10.5px] font-semibold tracking-[0.1em] text-muted-foreground/80 uppercase"
                            >
                                {{ h }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="app in applications"
                            :key="app.id"
                            class="border-b border-border transition last:border-0 hover:bg-muted/40"
                        >
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-3">
                                    <span
                                        class="flex size-9 items-center justify-center rounded-lg text-sm font-extrabold text-white"
                                        :style="{
                                            background: `linear-gradient(150deg, color-mix(in srgb, ${accent(app)} 80%, white), ${accent(app)})`,
                                        }"
                                    >
                                        {{ app.initials }}
                                    </span>
                                    <div>
                                        <p class="font-semibold">
                                            {{ app.name }}
                                        </p>
                                        <p
                                            class="font-mono text-[11.5px] text-muted-foreground"
                                        >
                                            {{ app.slug }}
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td
                                class="px-5 py-3 font-mono text-xs text-muted-foreground"
                            >
                                {{ shortRedirect(app.redirect_uri) }}
                            </td>
                            <td class="px-5 py-3">
                                <span
                                    class="inline-flex items-center gap-2 font-mono text-xs text-foreground/80"
                                >
                                    {{ maskId(app.client_id) }}
                                    <button
                                        v-if="app.client_id"
                                        type="button"
                                        class="grid size-6 place-items-center rounded-md border border-border bg-muted/50 text-muted-foreground transition hover:text-foreground"
                                        aria-label="Copy client id"
                                        @click="
                                            copy(app.client_id, 'Client ID')
                                        "
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
                                            <rect
                                                x="9"
                                                y="9"
                                                width="11"
                                                height="11"
                                                rx="2"
                                            />
                                            <path
                                                d="M5 15V5a2 2 0 0 1 2-2h10"
                                            />
                                        </svg>
                                    </button>
                                </span>
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-2">
                                    <div class="flex">
                                        <span
                                            v-for="uid in app.user_ids.slice(
                                                0,
                                                3,
                                            )"
                                            :key="uid"
                                            class="-ml-1.5 grid size-6 place-items-center rounded-full border-2 border-card bg-muted text-[9px] font-bold first:ml-0"
                                        >
                                            {{ userMap.get(uid)?.initials }}
                                        </span>
                                    </div>
                                    <span
                                        class="text-xs text-muted-foreground tabular-nums"
                                    >
                                        {{ app.users_count }}
                                    </span>
                                </div>
                            </td>
                            <td class="px-5 py-3">
                                <span
                                    v-if="app.active"
                                    class="inline-flex items-center gap-1.5 rounded-full bg-emerald-500/12 px-2.5 py-1 text-xs font-semibold text-emerald-600 dark:text-emerald-400"
                                >
                                    <span
                                        class="size-1.5 rounded-full bg-current"
                                    />
                                    Active
                                </span>
                                <span
                                    v-else
                                    class="inline-flex items-center gap-1.5 rounded-full border border-border bg-muted/50 px-2.5 py-1 text-xs font-semibold text-muted-foreground"
                                >
                                    <span
                                        class="size-1.5 rounded-full bg-current"
                                    />
                                    Disabled
                                </span>
                            </td>
                            <td class="px-5 py-3 text-right">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    @click="openEdit(app)"
                                >
                                    Edit
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- edit drawer -->
    <Sheet v-model:open="editOpen">
        <SheetContent class="flex w-full flex-col gap-0 sm:max-w-md">
            <SheetHeader>
                <SheetTitle>Edit {{ editingName }}</SheetTitle>
                <SheetDescription>
                    Update the client details and who can reach this app.
                </SheetDescription>
            </SheetHeader>

            <div class="flex-1 space-y-5 overflow-y-auto px-4 py-4">
                <div class="grid gap-2">
                    <Label for="e-name">Display name</Label>
                    <Input id="e-name" v-model="editForm.name" />
                    <InputError :message="editForm.errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label for="e-slug">Slug</Label>
                    <Input
                        id="e-slug"
                        v-model="editForm.slug"
                        class="font-mono"
                    />
                    <InputError :message="editForm.errors.slug" />
                </div>
                <div class="grid gap-2">
                    <Label for="e-desc">Description</Label>
                    <Input id="e-desc" v-model="editForm.description" />
                    <InputError :message="editForm.errors.description" />
                </div>
                <div class="grid gap-2">
                    <Label for="e-redirect">Redirect URI</Label>
                    <Input
                        id="e-redirect"
                        v-model="editForm.redirect_uri"
                        class="font-mono"
                    />
                    <InputError :message="editForm.errors.redirect_uri" />
                </div>
                <div class="grid gap-2">
                    <Label for="e-launch">Launch URL</Label>
                    <Input
                        id="e-launch"
                        v-model="editForm.launch_url"
                        class="font-mono"
                    />
                    <InputError :message="editForm.errors.launch_url" />
                </div>
                <div class="grid gap-2">
                    <Label>Accent colour</Label>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="c in SWATCHES"
                            :key="c"
                            type="button"
                            class="size-7 rounded-lg border-2 transition"
                            :class="
                                editForm.accent.toLowerCase() ===
                                c.toLowerCase()
                                    ? 'border-foreground'
                                    : 'border-transparent'
                            "
                            :style="{ background: c }"
                            :aria-label="`Accent ${c}`"
                            @click="editForm.accent = c"
                        />
                    </div>
                </div>

                <label
                    class="flex items-center justify-between border-t border-border pt-4"
                >
                    <span class="text-sm font-medium">
                        Active
                        <span
                            class="block text-xs font-normal text-muted-foreground"
                            >Clients can request tokens</span
                        >
                    </span>
                    <button
                        type="button"
                        role="switch"
                        :aria-checked="editForm.active"
                        class="relative h-6 w-11 rounded-full transition"
                        :class="
                            editForm.active ? 'bg-emerald-500' : 'bg-border'
                        "
                        @click="editForm.active = !editForm.active"
                    >
                        <span
                            class="absolute top-0.5 left-0.5 size-5 rounded-full bg-white shadow transition"
                            :class="editForm.active ? 'translate-x-5' : ''"
                        />
                    </button>
                </label>

                <div>
                    <p
                        class="mb-2 font-mono text-[10.5px] tracking-[0.1em] text-muted-foreground uppercase"
                    >
                        Who can access &middot;
                        {{ editForm.users.length }} people
                    </p>
                    <div
                        class="overflow-hidden rounded-xl border border-border"
                    >
                        <label
                            v-for="u in users"
                            :key="u.id"
                            class="flex cursor-pointer items-center gap-3 border-b border-border px-3 py-2.5 last:border-0"
                        >
                            <span
                                class="grid size-7 place-items-center rounded-full bg-muted text-[11px] font-bold"
                            >
                                {{ u.initials }}
                            </span>
                            <span class="flex-1">
                                <span class="block text-sm font-medium">{{
                                    u.name
                                }}</span>
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >{{ u.email }}</span
                                >
                            </span>
                            <button
                                type="button"
                                role="switch"
                                :aria-checked="editForm.users.includes(u.id)"
                                class="relative h-6 w-11 rounded-full transition"
                                :class="
                                    editForm.users.includes(u.id)
                                        ? 'bg-emerald-500'
                                        : 'bg-border'
                                "
                                @click="toggleUser(u.id)"
                            >
                                <span
                                    class="absolute top-0.5 left-0.5 size-5 rounded-full bg-white shadow transition"
                                    :class="
                                        editForm.users.includes(u.id)
                                            ? 'translate-x-5'
                                            : ''
                                    "
                                />
                            </button>
                        </label>
                    </div>
                </div>
            </div>

            <SheetFooter
                class="flex-row justify-end gap-2 border-t border-border"
            >
                <Button variant="outline" @click="editOpen = false"
                    >Cancel</Button
                >
                <Button :disabled="editForm.processing" @click="saveEdit">
                    <Spinner v-if="editForm.processing" />
                    Save changes
                </Button>
            </SheetFooter>
        </SheetContent>
    </Sheet>

    <!-- register modal -->
    <Dialog v-model:open="registerOpen">
        <DialogContent class="sm:max-w-lg">
            <template v-if="regStep === 'form'">
                <DialogHeader>
                    <DialogTitle>Register an application</DialogTitle>
                    <DialogDescription>
                        This mirrors
                        <span class="font-mono text-xs">php artisan id:app</span
                        >. A confidential PKCE auth-code client is created.
                    </DialogDescription>
                </DialogHeader>
                <div class="space-y-4 py-2">
                    <div class="grid gap-2">
                        <Label for="r-name">Display name</Label>
                        <Input
                            id="r-name"
                            v-model="createForm.name"
                            placeholder="Chronos"
                        />
                        <InputError :message="createForm.errors.name" />
                    </div>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="r-slug">Slug</Label>
                            <Input
                                id="r-slug"
                                v-model="createForm.slug"
                                class="font-mono"
                                placeholder="chronos"
                            />
                            <InputError :message="createForm.errors.slug" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="r-initials">Initials</Label>
                            <Input
                                id="r-initials"
                                v-model="createForm.initials"
                                placeholder="K"
                            />
                            <InputError :message="createForm.errors.initials" />
                        </div>
                    </div>
                    <div class="grid gap-2">
                        <Label for="r-desc">Description</Label>
                        <Input
                            id="r-desc"
                            v-model="createForm.description"
                            placeholder="Calendar across the suite"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="r-redirect">Redirect URI</Label>
                        <Input
                            id="r-redirect"
                            v-model="createForm.redirect_uri"
                            class="font-mono"
                            placeholder="https://chronos.thijssensoftware.nl/auth/callback"
                        />
                        <InputError :message="createForm.errors.redirect_uri" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="r-launch">Launch URL</Label>
                        <Input
                            id="r-launch"
                            v-model="createForm.launch_url"
                            class="font-mono"
                            placeholder="https://chronos.thijssensoftware.nl"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label>Accent colour</Label>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="c in SWATCHES"
                                :key="c"
                                type="button"
                                class="size-7 rounded-lg border-2 transition"
                                :class="
                                    createForm.accent.toLowerCase() ===
                                    c.toLowerCase()
                                        ? 'border-foreground'
                                        : 'border-transparent'
                                "
                                :style="{ background: c }"
                                :aria-label="`Accent ${c}`"
                                @click="createForm.accent = c"
                            />
                        </div>
                    </div>
                </div>
                <DialogFooter>
                    <Button variant="outline" @click="registerOpen = false"
                        >Cancel</Button
                    >
                    <Button
                        :disabled="createForm.processing"
                        @click="submitRegister"
                    >
                        <Spinner v-if="createForm.processing" />
                        Create client
                    </Button>
                </DialogFooter>
            </template>

            <template v-else>
                <DialogHeader>
                    <DialogTitle
                        >{{ createdClient?.name }} is registered</DialogTitle
                    >
                    <DialogDescription>
                        Copy these into the app's
                        <span class="font-mono text-xs">.env</span>. The secret
                        is shown once.
                    </DialogDescription>
                </DialogHeader>
                <div class="space-y-3 py-2">
                    <div
                        v-for="cred in [
                            {
                                k: 'THIJSSENSOFTWARE_ID_CLIENT_ID',
                                v: createdClient?.client_id,
                            },
                            {
                                k: 'THIJSSENSOFTWARE_ID_CLIENT_SECRET',
                                v: createdClient?.client_secret,
                            },
                        ]"
                        :key="cred.k"
                        class="rounded-xl border border-border bg-muted/40 p-3"
                    >
                        <p
                            class="mb-1.5 font-mono text-[10.5px] tracking-[0.1em] text-muted-foreground uppercase"
                        >
                            {{ cred.k }}
                        </p>
                        <div class="flex items-center justify-between gap-2">
                            <code class="font-mono text-xs break-all">{{
                                cred.v
                            }}</code>
                            <button
                                type="button"
                                class="grid size-7 flex-none place-items-center rounded-md border border-border bg-card text-muted-foreground transition hover:text-foreground"
                                aria-label="Copy"
                                @click="copy(cred.v ?? null, 'Value')"
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
                                        x="9"
                                        y="9"
                                        width="11"
                                        height="11"
                                        rx="2"
                                    />
                                    <path d="M5 15V5a2 2 0 0 1 2-2h10" />
                                </svg>
                            </button>
                        </div>
                    </div>
                    <p
                        class="flex gap-2 rounded-xl border border-amber-500/30 bg-amber-500/10 p-3 text-xs text-amber-700 dark:text-amber-400"
                    >
                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            class="size-4 flex-none"
                        >
                            <path d="M12 9v4M12 17h.01" />
                            <path
                                d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"
                            />
                        </svg>
                        Store the secret now. For security it can't be shown
                        again, only rotated.
                    </p>
                </div>
                <DialogFooter>
                    <Button @click="registerOpen = false">Done</Button>
                </DialogFooter>
            </template>
        </DialogContent>
    </Dialog>
</template>
