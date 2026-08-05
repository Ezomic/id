<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { acknowledge, regenerate } from '@/routes/recovery-codes';

export interface Props {
    newRecoveryCodes?: string[] | null;
    unusedRecoveryCodes: number;
}

const props = defineProps<Props>();
</script>

<template>
    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Recovery codes"
            description="One-time codes that sign you in when you can't reach your inbox and don't have a passkey"
        />

        <Card v-if="props.newRecoveryCodes?.length">
            <CardContent class="space-y-4 p-6">
                <p class="text-sm">
                    Save these somewhere safe. Each one works once, and this is
                    the only time they are shown.
                </p>
                <ul
                    class="grid grid-cols-2 gap-2 rounded-lg border border-border bg-muted/40 p-4 font-mono text-sm"
                >
                    <li v-for="code in props.newRecoveryCodes" :key="code">
                        {{ code }}
                    </li>
                </ul>
                <Form v-bind="acknowledge.form()" v-slot="{ processing }">
                    <Button
                        :disabled="processing"
                        data-test="acknowledge-codes"
                    >
                        I've saved these
                    </Button>
                </Form>
            </CardContent>
        </Card>

        <Card v-else>
            <CardContent
                class="flex flex-wrap items-center justify-between gap-4 p-6"
            >
                <p class="text-sm text-muted-foreground">
                    {{ props.unusedRecoveryCodes }} unused
                    {{ props.unusedRecoveryCodes === 1 ? 'code' : 'codes' }}
                    remaining. Generating a new set invalidates every existing
                    code.
                </p>
                <Form v-bind="regenerate.form()" v-slot="{ processing }">
                    <Button
                        variant="outline"
                        :disabled="processing"
                        data-test="regenerate-codes"
                    >
                        Generate new codes
                    </Button>
                </Form>
            </CardContent>
        </Card>
    </div>
</template>
