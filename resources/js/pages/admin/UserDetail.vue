<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { show, signOut } from '@/routes/admin/users';

interface ManagedUser {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
}

interface Session {
    id: string;
    ip_address: string | null;
    device: string;
    last_active_diff: string;
}

interface Connection {
    id: number | null;
    name: string;
    connected_since: string | null;
    last_token_at: string | null;
    expires_at: string | null;
    tokens: number;
}

const props = defineProps<{
    user: ManagedUser;
    sessions: Session[];
    connections: Connection[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'User', href: show(0) }],
    },
});
</script>

<template>
    <Head :title="props.user.name" />

    <div class="space-y-8">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading :title="props.user.name" :description="props.user.email" />

            <Form v-bind="signOut.form(props.user.id)" v-slot="{ processing }">
                <Button variant="destructive" :disabled="processing">
                    Sign out everywhere
                </Button>
            </Form>
        </div>

        <div class="space-y-3">
            <h2 class="text-sm font-semibold">
                Browser sessions ({{ props.sessions.length }})
            </h2>
            <Card>
                <CardContent class="p-0">
                    <ul class="divide-y divide-border">
                        <li
                            v-for="session in props.sessions"
                            :key="session.id"
                            class="flex items-center justify-between gap-4 p-4"
                        >
                            <div class="min-w-0">
                                <p class="text-sm font-semibold">
                                    {{ session.device }}
                                </p>
                                <p class="mt-0.5 text-xs text-muted-foreground">
                                    {{ session.ip_address ?? 'Unknown IP' }}
                                </p>
                            </div>
                            <span
                                class="shrink-0 text-xs text-muted-foreground"
                            >
                                {{ session.last_active_diff }}
                            </span>
                        </li>
                        <li
                            v-if="!props.sessions.length"
                            class="p-6 text-center text-sm text-muted-foreground"
                        >
                            No active sessions.
                        </li>
                    </ul>
                </CardContent>
            </Card>
        </div>

        <div class="space-y-3">
            <h2 class="text-sm font-semibold">
                Connected apps ({{ props.connections.length }})
            </h2>
            <Card>
                <CardContent class="p-0">
                    <ul class="divide-y divide-border">
                        <li
                            v-for="connection in props.connections"
                            :key="connection.name"
                            class="flex items-center justify-between gap-4 p-4"
                        >
                            <div class="min-w-0">
                                <p class="text-sm font-semibold">
                                    {{ connection.name }}
                                </p>
                                <p class="mt-0.5 text-xs text-muted-foreground">
                                    Connected {{ connection.connected_since }} ·
                                    last signed in
                                    {{ connection.last_token_at }} · expires
                                    {{ connection.expires_at }}
                                </p>
                            </div>
                            <span
                                class="shrink-0 text-xs text-muted-foreground"
                            >
                                {{ connection.tokens }}
                                {{
                                    connection.tokens === 1 ? 'token' : 'tokens'
                                }}
                            </span>
                        </li>
                        <li
                            v-if="!props.connections.length"
                            class="p-6 text-center text-sm text-muted-foreground"
                        >
                            No apps hold a sign-in for this user.
                        </li>
                    </ul>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
