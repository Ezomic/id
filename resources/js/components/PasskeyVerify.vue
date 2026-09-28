<script setup lang="ts">
import type { UrlMethodPair } from '@inertiajs/core';
import { usePasskeyVerify } from '@laravel/passkeys/vue';
import { KeyRound } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import { usePasskeyAutofill } from '@/composables/usePasskeyAutofill';

type Props = {
    routes?: {
        options: UrlMethodPair;
        submit: UrlMethodPair;
    };
    label?: string;
    loadingLabel?: string;
    separator?: string;
    /** Conditional mediation belongs on sign-in, not on a re-auth prompt. */
    autofill?: boolean;
};

const props = defineProps<Props>();

const { verify, isLoading, error, isSupported } = usePasskeyVerify({
    ...(props.routes
        ? {
              routes: {
                  options: props.routes.options.url,
                  submit: props.routes.submit.url,
              },
          }
        : {}),
    // A full page load, not an Inertia visit: after a sign-in that began at an
    // app the redirect is /oauth/authorize, which sends the browser on to that
    // app's origin, and an XHR cannot follow it there.
    onSuccess: (response) => {
        window.location.assign(response.redirect ?? '/dashboard');
    },
});

// Progressive enhancement alongside the button above, not a replacement for it.
if (props.autofill !== false) {
    usePasskeyAutofill({
        optionsUrl: props.routes?.options.url ?? '/passkeys/login/options',
        submitUrl: props.routes?.submit.url ?? '/passkeys/login',
        onSuccess: (redirect) => window.location.assign(redirect),
    });
}
</script>

<template>
    <div v-if="isSupported">
        <div class="grid gap-2">
            <Button
                type="button"
                variant="outline"
                class="w-full"
                @click="verify"
                :disabled="isLoading"
            >
                <Spinner v-if="isLoading" />
                <KeyRound v-else class="h-4 w-4" />
                {{
                    isLoading
                        ? (props.loadingLabel ?? 'Authenticating...')
                        : (props.label ?? 'Sign in with a passkey')
                }}
            </Button>

            <div v-if="error" class="text-center">
                <InputError :message="error" />
            </div>
        </div>

        <div class="relative my-6">
            <div class="absolute inset-0 flex items-center">
                <Separator class="w-full" />
            </div>
            <div class="relative flex justify-center text-xs uppercase">
                <span class="bg-background px-2 text-muted-foreground">
                    {{ props.separator ?? 'Or continue with email' }}
                </span>
            </div>
        </div>
    </div>
</template>
