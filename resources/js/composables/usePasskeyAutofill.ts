import {
    browserSupportsWebAuthnAutofill,
    startAuthentication,
} from '@simplewebauthn/browser';
import { onBeforeUnmount, onMounted } from 'vue';

type Options = {
    optionsUrl: string;
    submitUrl: string;
    onSuccess: (redirect: string) => void;
};

/**
 * Conditional-mediation sign-in: the browser offers the passkey inside the
 * email field instead of behind a button, so it becomes the path of least
 * resistance rather than something you opt into after deciding.
 *
 * `@laravel/passkeys` does not expose a mediation option, so this drives
 * `@simplewebauthn/browser` directly. The wire contract is copied from that
 * package: GET options, then POST `{ credential }` to the submit route.
 *
 * Deliberately additive and silent on failure. The button path is untouched, so
 * the worst case here is that autofill does not appear, never that sign-in
 * breaks. That matters more than usual: this is the login page of the estate's
 * single point of failure, and the ceremony cannot be exercised in CI.
 */
export function usePasskeyAutofill({
    optionsUrl,
    submitUrl,
    onSuccess,
}: Options) {
    const controller = new AbortController();

    function csrfToken(): string {
        const cookie = document.cookie
            .split('; ')
            .find((row) => row.startsWith('XSRF-TOKEN='));

        return cookie ? decodeURIComponent(cookie.split('=')[1]) : '';
    }

    async function start() {
        if (!(await browserSupportsWebAuthnAutofill())) {
            return;
        }

        const optionsResponse = await fetch(optionsUrl, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: controller.signal,
        });

        if (!optionsResponse.ok) {
            return;
        }

        const { options } = await optionsResponse.json();

        // Resolves only when the user picks a passkey from the autofill list.
        // If they type an email instead, this never settles and is aborted on
        // unmount, which is the intended behaviour rather than an error.
        const credential = await startAuthentication({
            optionsJSON: options,
            useBrowserAutofill: true,
        });

        const submitResponse = await fetch(submitUrl, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': csrfToken(),
            },
            credentials: 'same-origin',
            body: JSON.stringify({ credential }),
        });

        if (!submitResponse.ok) {
            return;
        }

        const result = await submitResponse.json().catch(() => ({}));

        onSuccess(result.redirect ?? '/dashboard');
    }

    onMounted(() => {
        // Every failure here is non-fatal: an unsupported browser, an aborted
        // ceremony, a user who ignored the prompt. None should surface.
        start().catch(() => {});
    });

    onBeforeUnmount(() => controller.abort());
}
