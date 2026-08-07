<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { index, retry } from '@/routes/admin/logout-deliveries';

interface Delivery {
    id: number;
    application: string;
    endpoint: string;
    attempts: number;
    last_error: string | null;
    abandoned: boolean;
    age: string | null;
}

const props = defineProps<{ pending: Delivery[]; maxAttempts: number }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Logout deliveries', href: index() }],
    },
});
</script>

<template>
    <Head title="Logout deliveries" />

    <div class="space-y-6">
        <Heading
            title="Logout deliveries"
            :description="`Sign-outs that a consumer app has not accepted yet. After ${props.maxAttempts} attempts they stop retrying on their own and need a nudge here.`"
        />

        <Card>
            <CardContent class="p-0">
                <ul class="divide-y divide-border">
                    <li
                        v-for="delivery in props.pending"
                        :key="delivery.id"
                        class="flex flex-wrap items-center justify-between gap-4 p-4"
                    >
                        <div class="min-w-0">
                            <p class="text-sm font-semibold">
                                <span
                                    v-if="delivery.abandoned"
                                    class="mr-1.5 rounded bg-destructive/10 px-1.5 py-0.5 text-xs font-medium text-destructive"
                                >
                                    Gave up
                                </span>
                                {{ delivery.application }}
                                <span class="text-muted-foreground">
                                    · {{ delivery.attempts }} of
                                    {{ props.maxAttempts }} attempts
                                </span>
                            </p>
                            <p
                                class="mt-0.5 truncate text-xs text-muted-foreground"
                            >
                                {{ delivery.endpoint }}
                                <template v-if="delivery.last_error">
                                    · {{ delivery.last_error }}
                                </template>
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-3">
                            <span class="text-xs text-muted-foreground">
                                {{ delivery.age }}
                            </span>
                            <Form
                                v-bind="retry.form(delivery.id)"
                                v-slot="{ processing }"
                            >
                                <Button
                                    size="sm"
                                    variant="outline"
                                    :disabled="processing"
                                >
                                    Retry
                                </Button>
                            </Form>
                        </div>
                    </li>
                    <li
                        v-if="!props.pending.length"
                        class="p-6 text-center text-sm text-muted-foreground"
                    >
                        Every sign-out has been delivered.
                    </li>
                </ul>
            </CardContent>
        </Card>
    </div>
</template>
