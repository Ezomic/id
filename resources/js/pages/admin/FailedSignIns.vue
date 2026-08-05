<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Card, CardContent } from '@/components/ui/card';
import { index } from '@/routes/admin/failed-sign-ins';

interface Attempt {
    id: number;
    method: string;
    ip_address: string | null;
    device: string;
    account: string | null;
    created_at_diff: string | null;
}

const props = defineProps<{ attempts: Attempt[] }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Failed sign-ins', href: index() }],
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
    <Head title="Failed sign-ins" />

    <div class="space-y-6">
        <Heading
            title="Failed sign-ins"
            description="The 200 most recent failed attempts. Attempts against an address with no account are shown as unknown; the address itself is not stored."
        />

        <Card>
            <CardContent class="p-0">
                <ul class="divide-y divide-border">
                    <li
                        v-for="attempt in props.attempts"
                        :key="attempt.id"
                        class="flex items-center justify-between gap-4 p-4"
                    >
                        <div class="min-w-0">
                            <p class="text-sm font-semibold">
                                {{ attempt.account ?? 'Unknown account' }}
                                <span class="text-muted-foreground">
                                    ·
                                    {{
                                        methodLabels[attempt.method] ??
                                        attempt.method
                                    }}
                                </span>
                            </p>
                            <p
                                class="mt-0.5 truncate text-xs text-muted-foreground"
                            >
                                {{ attempt.device }} ·
                                {{ attempt.ip_address ?? 'Unknown IP' }}
                            </p>
                        </div>
                        <span class="shrink-0 text-xs text-muted-foreground">
                            {{ attempt.created_at_diff }}
                        </span>
                    </li>
                    <li
                        v-if="!props.attempts.length"
                        class="p-6 text-center text-sm text-muted-foreground"
                    >
                        No failed attempts recorded.
                    </li>
                </ul>
            </CardContent>
        </Card>
    </div>
</template>
