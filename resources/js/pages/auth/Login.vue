<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PasskeyVerify from '@/components/PasskeyVerify.vue';
import QrLoginPanel from '@/components/QrLoginPanel.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { recoveryCode } from '@/routes/login';
import { send, verify } from '@/routes/login/code';
import { code as qrCode } from '@/routes/qr';

defineOptions({
    layout: {
        title: 'Log in to your account',
        description: 'Sign in with a passkey, or get a one-time code by email',
    },
});

const props = defineProps<{
    status?: string;
    email?: string;
    codeSent?: boolean;
    recoveryMode?: boolean;
    qrLoginEnabled?: boolean;
}>();

const usingRecovery = ref(props.recoveryMode ?? false);
</script>

<template>
    <Head title="Log in" />

    <div
        v-if="status"
        class="mb-4 text-center text-sm font-medium text-green-600"
    >
        {{ status }}
    </div>

    <template v-if="usingRecovery">
        <Form
            v-bind="recoveryCode.form()"
            v-slot="{ errors, processing }"
            class="flex flex-col gap-6"
        >
            <div class="grid gap-2">
                <Label for="recovery-email">Email address</Label>
                <Input
                    id="recovery-email"
                    type="email"
                    name="email"
                    required
                    autocomplete="username webauthn"
                    placeholder="email@example.com"
                    :default-value="email"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="recovery-code">Recovery code</Label>
                <Input
                    id="recovery-code"
                    name="code"
                    required
                    autofocus
                    autocomplete="one-time-code"
                    placeholder="ABCD-EFGH-IJKL"
                />
                <InputError :message="errors.code" />
                <p class="text-sm text-muted-foreground">
                    One of the codes you saved when you set up your account.
                    Each one works once.
                </p>
            </div>

            <Button type="submit" class="w-full" :disabled="processing">
                <Spinner v-if="processing" />
                Sign in
            </Button>

            <div class="text-center text-sm text-muted-foreground">
                <button
                    type="button"
                    class="underline-offset-4 hover:underline"
                    @click="usingRecovery = false"
                >
                    Back to the usual ways in
                </button>
            </div>
        </Form>
    </template>

    <template v-else>
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
                    autocomplete="username webauthn"
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
                    We sent a 6-digit code to {{ email }}. It expires in 10
                    minutes.
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

        <section
            v-if="qrLoginEnabled"
            class="space-y-4 border-t border-border pt-6"
            aria-labelledby="qr-heading"
        >
            <div class="space-y-1 text-center">
                <h2 id="qr-heading" class="text-sm font-medium">
                    Sign in with your phone
                </h2>
                <p class="text-sm text-muted-foreground">
                    Scan this with a phone that is already signed in to ID.
                </p>
            </div>

            <QrLoginPanel />

            <Form
                v-bind="qrCode.form()"
                v-slot="{ errors, processing }"
                class="grid gap-2"
            >
                <Label for="qr-code-entry" class="text-sm font-normal">
                    Showing a code on a signed-in device instead? Type it here.
                </Label>
                <div class="flex gap-2">
                    <Input
                        id="qr-code-entry"
                        name="code"
                        required
                        autocomplete="off"
                        autocapitalize="characters"
                        placeholder="ABCD-EFGH"
                        class="font-mono uppercase"
                    />
                    <Button
                        type="submit"
                        variant="outline"
                        :disabled="processing"
                    >
                        Go
                    </Button>
                </div>
                <InputError :message="errors.code" />
            </Form>
        </section>

        <div class="mt-6 text-center text-sm text-muted-foreground">
            <button
                type="button"
                class="underline-offset-4 hover:underline"
                @click="usingRecovery = true"
            >
                Can't reach your email? Use a recovery code
            </button>
        </div>
    </template>
</template>
