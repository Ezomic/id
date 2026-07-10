<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasskeyVerify from '@/components/PasskeyVerify.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { send, verify } from '@/routes/login/code';

defineOptions({
    layout: {
        title: 'Log in to your account',
        description: 'Sign in with a passkey, or get a one-time code by email',
    },
});

defineProps<{
    status?: string;
    email?: string;
    codeSent?: boolean;
}>();
</script>

<template>
    <Head title="Log in" />

    <div
        v-if="status"
        class="mb-4 text-center text-sm font-medium text-green-600"
    >
        {{ status }}
    </div>

    <PasskeyVerify />

    <Form
        v-if="!codeSent"
        v-bind="send.form()"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-2">
            <Label for="email">Email address</Label>
            <Input
                id="email"
                type="email"
                name="email"
                required
                autofocus
                autocomplete="email"
                placeholder="email@example.com"
                :default-value="email"
            />
            <InputError :message="errors.email" />
        </div>

        <Button type="submit" class="w-full" :disabled="processing">
            <Spinner v-if="processing" />
            Email me a login code
        </Button>
    </Form>

    <Form
        v-else
        v-bind="verify.form()"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <input type="hidden" name="email" :value="email" />

        <div class="grid gap-2">
            <Label for="code">Login code</Label>
            <Input
                id="code"
                name="code"
                required
                autofocus
                inputmode="numeric"
                autocomplete="one-time-code"
                placeholder="123456"
            />
            <InputError :message="errors.code" />
            <p class="text-sm text-muted-foreground">
                We sent a 6-digit code to {{ email }}. It expires in 10 minutes.
            </p>
        </div>

        <Button type="submit" class="w-full" :disabled="processing">
            <Spinner v-if="processing" />
            Sign in
        </Button>

        <div class="text-center text-sm text-muted-foreground">
            <TextLink :href="login()">Use a different email</TextLink>
        </div>
    </Form>
</template>
