import type { LinkComponentBaseProps } from '@inertiajs/core';
import type { Component, SvelteComponent } from 'svelte';

type NavIcon =
    | Component<{ class?: string }>
    | (new (...args: any[]) => SvelteComponent<{ class?: string }>);

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<LinkComponentBaseProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<LinkComponentBaseProps['href']>;
    icon?: NavIcon;
    isActive?: boolean;
    /**
     * Leaves the application. Rendered as a plain anchor opening a new tab rather than an Inertia
     * `Link`, which would try to visit the URL as an Inertia page. Defaults to an internal link.
     */
    external?: boolean;
};
