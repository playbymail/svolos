import type { AgentTokenFlash } from '@/types/agents';
import type { Auth } from '@/types/auth';
import type { FlashToast } from '@/types/ui';

// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(
            pattern: string,
            options?: { eager?: boolean },
        ) => Record<string, T>;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };

        /**
         * What `Inertia::flash()` may put in the page object's flash bag.
         *
         * Flash data does **not** arrive in props — it rides on the page object of the *next*
         * response and is gone on the one after. `agentToken` depends on exactly that: it is the one
         * time a freshly minted agent token is ever readable, and its being unrecoverable afterwards
         * is a property of the flash bag rather than of anything the screen remembers to clear.
         */
        flashDataType: {
            toast?: FlashToast;
            agentToken?: AgentTokenFlash;
        };
    }
}
