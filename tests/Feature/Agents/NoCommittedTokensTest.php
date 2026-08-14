<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

/*
|--------------------------------------------------------------------------
| No agent token is committed to this repository
|--------------------------------------------------------------------------
|
| The repository is public, and an agent token does not expire: it is revoked only by an
| administrator issuing a replacement. One that reaches a commit is live until somebody
| notices, and the git history keeps it after the file is fixed.
|
| This is not hypothetical either. The first draft of `docs/agent-api.md` illustrated the
| `Authorization` header with a token copied from a real config file while writing the
| examples. It was caught before the file was ever committed; this test is what makes the
| catch repeatable rather than lucky.
|
| The `svl_agent_` prefix exists so that scanners can recognise a token. This is the scanner
| for the one repository that most needs it.
|
*/

test('no agent token literal appears in the repository', function () {
    /*
     * Only the real shape is matched: the prefix followed by exactly 48 characters from the
     * generator's alphabet. Test fixtures spell obviously-fake tokens with hyphens or short
     * bodies (`svl_agent_example`, `svl_agent_burst_same_token`), which cannot match, so this
     * stays quiet about them without needing a list of exceptions.
     */
    $pattern = '/svl_agent_[A-Za-z0-9]{48}/';

    $searched = ['app', 'config', 'database', 'docs', 'resources', 'routes', 'tests', '.ai'];

    $offenders = collect($searched)
        ->filter(fn (string $directory): bool => File::isDirectory(base_path($directory)))
        ->flatMap(fn (string $directory): array => File::allFiles(base_path($directory)))
        ->reject(fn (SplFileInfo $file): bool => in_array($file->getExtension(), ['png', 'jpg', 'ico', 'woff2'], true))
        ->flatMap(function (SplFileInfo $file) use ($pattern): array {
            preg_match_all($pattern, (string) file_get_contents($file->getPathname()), $matches);

            return collect($matches[0])
                /*
                 * A documented example has to look like a real token to be useful, so the
                 * placeholder keeps the real length and says what it is instead.
                 */
                ->reject(fn (string $token): bool => str_contains($token, 'EXAMPLE'))
                ->map(fn (string $token): string => $file->getRelativePathname().': '.substr($token, 0, 18).'…')
                ->all();
        })
        ->values()
        ->all();

    expect($offenders)->toBe([], implode("\n", [
        'A value shaped like a live agent token is committed here.',
        'Tokens do not expire and this repository is public, so replace it with a placeholder',
        'containing EXAMPLE, and have an administrator issue a new token for that seat.',
        '',
        ...$offenders,
    ]));
});
