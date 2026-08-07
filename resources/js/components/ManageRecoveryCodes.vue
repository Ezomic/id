<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { acknowledge, regenerate } from '@/routes/recovery-codes';

export interface Props {
    newRecoveryCodes?: string[] | null;
    recoveryCodesUnsaved: boolean;
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

        <!-- The plaintext is in hand: this is the only moment it can be read. -->
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

        <!--
            Codes exist but were never confirmed and the plaintext is gone with
            the session that held it. They cannot be recovered, only replaced.
        -->
        <Card
            v-else-if="props.recoveryCodesUnsaved"
            class="border-destructive/40"
        >
            <CardContent class="space-y-4 p-6">
                <p class="text-sm font-semibold text-destructive">
                    You have recovery codes you never saved
                </p>
                <p class="text-sm text-muted-foreground">
                    They were generated but the copy you could read is gone, so
                    they can't be shown again. Generate a fresh set and save it,
                    otherwise you have no way back in if you lose access to your
                    email.
                </p>
                <Form v-bind="regenerate.form()" v-slot="{ processing }">
                    <Button
                        :disabled="processing"
                        data-test="replace-unsaved-codes"
                    >
                        Generate a set I can save
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
