<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { approve, deny, index } from '@/routes/admin/access-requests';

interface AccessRequest {
    id: number;
    user: { name: string; email: string };
    application: string;
    requested_at_diff: string | null;
}

defineProps<{ requests: AccessRequest[] }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Access requests', href: index() }],
    },
});

function approveRequest(id: number) {
    router.post(approve(id).url, {}, { preserveScroll: true });
}

function denyRequest(id: number) {
    router.post(deny(id).url, {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Access requests" />

    <div class="space-y-6">
        <Heading
            title="Access requests"
            description="Users waiting for access to an app. Approving grants it immediately."
        />

        <Card>
            <CardContent class="p-0">
                <ul class="divide-y divide-border">
                    <li
                        v-for="request in requests"
                        :key="request.id"
                        class="flex items-center justify-between gap-4 p-4"
                    >
                        <div class="min-w-0">
                            <p class="text-sm font-semibold">
                                {{ request.user.name }}
                                <span class="text-muted-foreground">
                                    wants {{ request.application }}
                                </span>
                            </p>
                            <p
                                class="mt-0.5 truncate text-xs text-muted-foreground"
                            >
                                {{ request.user.email }} ·
                                {{ request.requested_at_diff }}
                            </p>
                        </div>
                        <div class="flex shrink-0 gap-2">
                            <Button
                                size="sm"
                                @click="approveRequest(request.id)"
                            >
                                Approve
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                @click="denyRequest(request.id)"
                            >
                                Deny
                            </Button>
                        </div>
                    </li>
                    <li
                        v-if="!requests.length"
                        class="p-6 text-center text-sm text-muted-foreground"
                    >
                        No pending requests.
                    </li>
                </ul>
            </CardContent>
        </Card>
    </div>
</template>
