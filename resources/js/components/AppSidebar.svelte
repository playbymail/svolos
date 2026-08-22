<script lang="ts">
    import { Link, page } from '@inertiajs/svelte';
    import BookOpen from '@lucide/svelte/icons/book-open';
    import LayoutGrid from '@lucide/svelte/icons/layout-grid';
    import Package from '@lucide/svelte/icons/package';
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
    import { index as kitTemplatesIndex } from '@/routes/gamemaster/kit-templates';
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

    /*
     * Hidden the same way and for the same reason, but off a different question. Running a game is a
     * fact about seats rather than about `users.role` (see `.ai/rules/roles.md`), so it cannot be read
     * off `auth.user` — the server answers it in `HandleInertiaRequests::runsAGame()` through the very
     * scope the `runs-a-game` middleware gates the area with.
     *
     * The library is per person rather than per game, which is why it belongs here rather than on the
     * screen for running one game: a gamemaster writes a kit once and uses it at as many games as they
     * like, including before any of them has reached the stage that consumes it.
     */
    const runsAGame = $derived(page.props.auth.runsAGame === true);

    const mainNavItems: NavItem[] = $derived([
        {
            title: 'Dashboard',
            href: dashboard(),
            icon: LayoutGrid,
        },
        ...(runsAGame
            ? [
                  {
                      title: 'Kit templates',
                      href: kitTemplatesIndex(),
                      icon: Package,
                  },
              ]
            : []),
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
