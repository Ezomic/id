<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import Heading from '@/components/Heading.vue';
import { Card, CardContent } from '@/components/ui/card';
import { index } from '@/routes/admin/access-audit';

interface Audit {
    id: number;
    actor: string;
    action: string;
    subject: string | null;
    application: string | null;
    group: string | null;
    at_diff: string | null;
}

interface Option {
    id: number;
    name: string;
}

const props = defineProps<{
    audits: Audit[];
    users: Option[];
    applications: Option[];
    filters: { user: number | null; application: number | null };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Audit log', href: index() }],
    },
});

const filters = reactive({
    user: props.filters.user ?? '',
    application: props.filters.application ?? '',
});

function applyFilters() {
    router.get(
        index().url,
        {
            user: filters.user || undefined,
            application: filters.application || undefined,
        },
        { preserveState: true, replace: true },
    );
}

function describe(audit: Audit): string {
    const app = audit.application ?? 'an app';
    const subject = audit.subject ?? 'a user';
    const group = audit.group ?? 'a group';

    switch (audit.action) {
        case 'grant':
            return `granted ${app} to ${subject}`;
        case 'revoke':
            return `revoked ${app} from ${subject}`;
        case 'group_member_add':
            return `added ${subject} to group ${group}`;
        case 'group_member_remove':
            return `removed ${subject} from group ${group}`;
        case 'group_app_grant':
            return `granted ${app} to group ${group}`;
        case 'group_app_revoke':
            return `revoked ${app} from group ${group}`;
        default:
            return audit.action;
    }
}
</script>

<template>
    <Head title="Audit log" />

    <div class="space-y-6">
        <Heading
            title="Access audit log"
            description="Every access grant and revoke, and who made it."
        />

        <div class="flex flex-wrap gap-3">
            <select
                v-model="filters.user"
                class="h-9 rounded-lg border border-border bg-card px-3 text-sm"
                @change="applyFilters"
            >
                <option value="">All users</option>
                <option
                    v-for="user in props.users"
                    :key="user.id"
                    :value="user.id"
                >
                    {{ user.name }}
                </option>
            </select>
            <select
                v-model="filters.application"
                class="h-9 rounded-lg border border-border bg-card px-3 text-sm"
                @change="applyFilters"
            >
                <option value="">All apps</option>
                <option
                    v-for="application in props.applications"
                    :key="application.id"
                    :value="application.id"
                >
                    {{ application.name }}
                </option>
            </select>
        </div>

        <Card>
            <CardContent class="p-0">
                <ul class="divide-y divide-border">
                    <li
                        v-for="audit in props.audits"
                        :key="audit.id"
                        class="flex items-center justify-between gap-4 p-4"
                    >
                        <p class="text-sm">
                            <span class="font-semibold">{{ audit.actor }}</span>
                            {{ describe(audit) }}
                        </p>
                        <span class="shrink-0 text-xs text-muted-foreground">
                            {{ audit.at_diff }}
                        </span>
                    </li>
                    <li
                        v-if="!props.audits.length"
                        class="p-6 text-center text-sm text-muted-foreground"
                    >
                        Nothing recorded yet.
                    </li>
                </ul>
            </CardContent>
        </Card>
    </div>
</template>
