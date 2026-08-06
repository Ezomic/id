<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { destroy } from '@/routes/connections';

export interface Connection {
    id: number | null;
    name: string;
    slug: string | null;
    connected_since: string | null;
    last_token_at: string | null;
    expires_at: string | null;
    tokens: number;
}

export interface Props {
    connections: Connection[];
}

const props = defineProps<Props>();
</script>

<template>
    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Connected apps"
            description="Apps currently holding a sign-in on your behalf. Disconnecting signs you out of one without giving up your access to it."
        />

        <Card>
            <CardContent class="p-0">
                <ul class="divide-y divide-border">
                    <li
                        v-for="connection in props.connections"
                        :key="connection.name"
                        class="flex flex-wrap items-center justify-between gap-4 p-4"
                    >
                        <div class="min-w-0">
                            <p class="text-sm font-semibold">
                                {{ connection.name }}
                            </p>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                Connected {{ connection.connected_since }} ·
                                last signed in {{ connection.last_token_at }}
                            </p>
                        </div>
                        <Form
                            v-if="connection.id"
                            v-bind="destroy.form(connection.id)"
                            v-slot="{ processing }"
                        >
                            <Button
                                variant="outline"
                                size="sm"
                                :disabled="processing"
                            >
                                Disconnect
                            </Button>
                        </Form>
                    </li>
                    <li
                        v-if="!props.connections.length"
                        class="p-6 text-center text-sm text-muted-foreground"
                    >
                        No apps are holding a sign-in right now.
                    </li>
                </ul>
            </CardContent>
        </Card>
    </div>
</template>
