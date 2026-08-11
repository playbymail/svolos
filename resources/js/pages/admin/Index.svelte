<script module lang="ts">
    import { index as adminIndex } from '@/routes/admin';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Administration',
                href: adminIndex(),
            },
        ],
    };
</script>

<script lang="ts">
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
</script>

<AppHead title="Administration" />

<h1 class="sr-only">Administration</h1>

<div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
    <Heading
        title="Administration"
        description="Everything here is restricted to administrators."
    />
</div>
