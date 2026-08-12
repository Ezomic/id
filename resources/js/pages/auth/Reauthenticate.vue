<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasskeyVerify from '@/components/PasskeyVerify.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { confirm } from '@/routes/reauthenticate';

const props = defineProps<{
    hasPasskeys: boolean;
    windowMinutes: number;
}>();

defineOptions({
    layout: {
        title: 'Confirm it is you',
        description: 'This action changes who can reach the estate',
    },
});
</script>

<template>
    <Head title="Confirm it is you" />

    <div class="flex flex-col gap-6">
        <p class="text-sm text-muted-foreground">
            You are already signed in, but this action is one that changes
            access, so it needs a fresh check. You won't be asked again for
            {{ props.windowMinutes }} minutes.
        </p>

        <PasskeyVerify
            v-if="props.hasPasskeys"
            :autofill="false"
            :routes="{
                options: { url: '/passkeys/confirm/options', method: 'get' },
                submit: { url: '/passkeys/confirm', method: 'post' },
            }"
            label="Confirm with a passkey"
            loadingLabel="Confirming..."
            separator="Or use a recovery code"
        />

        <Form
            v-bind="confirm.form()"
            v-slot="{ errors, processing }"
            class="flex flex-col gap-4"
        >
            <div class="grid gap-2">
                <Label for="code">Recovery code</Label>
                <Input
                    id="code"
                    name="code"
                    required
                    autofocus
                    autocomplete="one-time-code"
                    placeholder="ABCD-EFGH-IJKL"
                />
                <InputError :message="errors.code" />
                <p class="text-sm text-muted-foreground">
                    Uses up one of your recovery codes. A passkey is the better
                    way to do this if you have one.
                </p>
            </div>

            <Button type="submit" :disabled="processing">
                <Spinner v-if="processing" />
                Confirm
            </Button>
        </Form>
    </div>
</template>
