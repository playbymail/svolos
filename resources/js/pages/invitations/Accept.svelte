<script module lang="ts">
    export const layout = {
        title: 'Accept your invitation',
        description: 'Choose a name and a password to create your account',
    };
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import PasswordInput from '@/components/PasswordInput.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { Spinner } from '@/components/ui/spinner';
    import { store } from '@/routes/invitations';

    let {
        token,
        email,
        roleLabel,
        expiresAtDiff,
    }: {
        token: string;
        email: string;
        roleLabel: string;
        expiresAtDiff: string;
    } = $props();
</script>

<AppHead title="Accept your invitation" />

<Form
    {...store.form(token)}
    resetOnError={['password', 'password_confirmation']}
    class="flex flex-col gap-6"
>
    {#snippet children({ errors, processing })}
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <!--
                    Read-only, and read-only for real: the server takes the address from the
                    invitation and ignores whatever this field posts, so editing it in the browser
                    cannot point the invitation at a different mailbox. It is still named and
                    submitted so password managers can save the credential against the right
                    account.
                -->
                <Input
                    id="email"
                    type="email"
                    name="email"
                    value={email}
                    readonly
                    autocomplete="username"
                    class="bg-muted text-muted-foreground"
                />
                <p class="text-xs text-muted-foreground">
                    This invitation was sent to this address and cannot be
                    changed. You will join as
                    <Badge variant="secondary">{roleLabel}</Badge>.
                </p>
                <InputError message={errors.email} />
            </div>

            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    type="text"
                    name="name"
                    required
                    autofocus
                    autocomplete="name"
                    placeholder="Your full name"
                />
                <InputError message={errors.name} />
            </div>

            <div class="grid gap-2">
                <Label for="password">Password</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    autocomplete="new-password"
                    placeholder="Password"
                />
                <InputError message={errors.password} />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">Confirm password</Label>
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    required
                    autocomplete="new-password"
                    placeholder="Confirm password"
                />
                <InputError message={errors.password_confirmation} />
            </div>

            <Button
                type="submit"
                class="mt-2 w-full"
                disabled={processing}
                data-test="accept-invitation-button"
            >
                {#if processing}<Spinner />{/if}
                Create account
            </Button>

            <p class="text-center text-xs text-muted-foreground">
                This invitation expires {expiresAtDiff}. We will send you an
                email afterwards to confirm this address.
            </p>
        </div>
    {/snippet}
</Form>
