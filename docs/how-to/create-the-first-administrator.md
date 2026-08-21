# How to create the first administrator

This guide shows you how to get the first account onto a freshly deployed installation, and how to
let everybody else in afterwards.

The application is invite-only and has no registration form, so a new installation has no accounts
at all. There is exactly one supported way to create the first one, and it works in production.

---

## Mint the administrator

```bash
cd /srv/svolos
php artisan app:create-admin you@example.com
```

The command prompts for the password interactively, so it never lands in shell history. It
validates the password against the same rules the rest of the application uses, and marks a newly
created administrator's email as already verified.

It is idempotent. Run against an address that is already an administrator, it says so and exits
without erroring; run against an existing member, it asks for confirmation before promoting them.

## Confirm mail leaves the box before inviting anyone real

Sign in at `https://svolos.pbbgaming.com`, go to **`/admin/invitations`**, and invite a second
address of your own. If it does not arrive:

```bash
tail -50 /srv/svolos/storage/logs/laravel.log
```

See [how to troubleshoot a deployment](troubleshoot-a-deployment.md#invitations-never-arrive) for
what the log will be telling you.

Two things are worth knowing before the first real invitation goes out:

- **A token cannot be recovered or re-sent as-is.** Invitation tokens are stored as a sha256 hash,
  and only the emailed link ever carries the plain text. "Resend" issues a *new* token and
  invalidates the previous link. If mail delivery is misconfigured, the invitation is unrecoverable
  and must be resent after fixing it.
- **Accepting an invitation does not verify the email address.** A new account still completes the
  standard verification flow, which needs working mail as well.

## Let everybody else in

`/admin/invitations` is the only path to every subsequent account. See `.ai/rules/invitations.md`
for how the mechanism works.
