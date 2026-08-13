import type { Auth } from '@/types/auth';

// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth & {
                unsavedRecoveryCodes: boolean;
                needsPasskey: boolean;
            };
            flash: {
                status: string | null;
                createdClient: {
                    name: string;
                    client_id: string;
                    client_secret: string;
                    logout_secret: string;
                    rotated?: boolean;
                } | null;
                connectionCheck: {
                    name: string;
                    healthy: boolean;
                    checks: {
                        name: string;
                        ok: boolean;
                        detail: string;
                    }[];
                } | null;
            };
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}

declare module 'vue' {
    interface ComponentCustomProperties {
        $inertia: typeof Router;
        $page: Page;
        $headManager: ReturnType<typeof createHeadManager>;
    }
}
