<script lang="ts">
    import { Link, page } from '@inertiajs/svelte';
    import BookOpen from '@lucide/svelte/icons/book-open';
    import LayoutGrid from '@lucide/svelte/icons/layout-grid';
    import ShieldCheck from '@lucide/svelte/icons/shield-check';
    import type { Snippet } from 'svelte';
    import AppLogo from '@/components/AppLogo.svelte';
    import NavFooter from '@/components/NavFooter.svelte';
    import NavMain from '@/components/NavMain.svelte';
    import NavUser from '@/components/NavUser.svelte';
    import {
        Sidebar,
        SidebarContent,
        SidebarFooter,
        SidebarHeader,
        SidebarMenu,
        SidebarMenuButton,
        SidebarMenuItem,
    } from '@/components/ui/sidebar';
    import { toUrl } from '@/lib/utils';
    import { dashboard, docs } from '@/routes';
    import { index as adminIndex } from '@/routes/admin';
    import type { NavItem } from '@/types';

    let {
        children,
    }: {
        children?: Snippet;
    } = $props();

    /*
     * The administration link is hidden from members rather than rendered and refused: the server
     * is the boundary (the `admin` middleware on the whole `/admin` group), so this is only about
     * not offering a link that would 403.
     */
    const isAdmin = $derived(page.props.auth.user?.role === 'admin');

    const mainNavItems: NavItem[] = $derived([
        {
            title: 'Dashboard',
            href: dashboard(),
            icon: LayoutGrid,
        },
        ...(isAdmin
            ? [
                  {
                      title: 'Administration',
                      href: adminIndex(),
                      icon: ShieldCheck,
                  },
              ]
            : []),
    ]);

    const footerNavItems: NavItem[] = [
        {
            title: 'Documentation',
            href: docs(),
            icon: BookOpen,
        },
    ];
</script>

<Sidebar collapsible="icon" variant="inset">
    <SidebarHeader>
        <SidebarMenu>
            <SidebarMenuItem>
                <SidebarMenuButton size="lg" asChild>
                    {#snippet children(props)}
                        <Link
                            {...props}
                            href={toUrl(dashboard())}
                            class={props.class}
                        >
                            <AppLogo />
                        </Link>
                    {/snippet}
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarHeader>

    <SidebarContent>
        <NavMain items={mainNavItems} />
    </SidebarContent>

    <SidebarFooter>
        <NavFooter items={footerNavItems} />
        <NavUser />
    </SidebarFooter>
</Sidebar>
{@render children?.()}
