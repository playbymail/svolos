<?php

namespace App\Concerns;

use App\Models\AgentCredential;
use App\Models\GameSeat;
use App\Models\User;

/**
 * Shapes agent accounts and their seats for the administration screens.
 *
 * Shared by the two controllers behind `/admin/agents` so the index and the detail screen describe a
 * credential in the same words. Nothing here ever reaches for `AgentCredential::$token`: the column
 * holds a hash, so putting it on the wire would be shipping a useless secret to the browser. What the
 * screens show instead is whether a token *exists* and when it was last used, which is what an
 * administrator can actually act on.
 */
trait PresentsAgents
{
    /**
     * Shape one agent account for a listing.
     *
     * @return array{
     *     id: int,
     *     name: string,
     *     email: string,
     *     created_at: string,
     *     created_at_diff: string|null,
     *     active_seats_count: int,
     *     credentials_count: int,
     *     last_used_at_diff: string|null,
     * }
     */
    private function presentAgent(User $agent): array
    {
        $seats = $agent->gameSeats;

        $credentials = $seats
            ->map(fn (GameSeat $seat): ?AgentCredential => $seat->agentCredential)
            ->filter();

        return [
            'id' => $agent->id,
            'name' => $agent->name,
            'email' => $agent->email,
            'created_at' => $agent->created_at?->toDayDateTimeString() ?? '',
            'created_at_diff' => $agent->created_at?->diffForHumans(),
            'active_seats_count' => $seats->where('is_active', true)->count(),
            'credentials_count' => $credentials->count(),

            /*
             * The most recent use across every seat, so the list answers "is this agent alive?" in one
             * column without the reader having to open each agent to find out.
             */
            'last_used_at_diff' => $credentials
                ->pluck('last_used_at')
                ->filter()
                ->sortDesc()
                ->first()?->diffForHumans(),
        ];
    }

    /**
     * Shape one of an agent's seats, and the state of its credential.
     *
     * `can_issue` is presentation only — it keeps the screen from offering a button on a retired
     * seat, whose token would be refused by `App\Http\Middleware\AuthenticateAgent` anyway.
     * `AgentCredentialController` is the boundary; do not turn a hidden button into the check.
     *
     * @return array{
     *     id: int,
     *     game: array{id: int, name: string, short_name: string, status_label: string},
     *     role_label: string,
     *     is_active: bool,
     *     can_issue: bool,
     *     has_credential: bool,
     *     issued_at_diff: string|null,
     *     issued_by: string|null,
     *     last_used_at_diff: string|null,
     * }
     */
    private function presentSeat(GameSeat $seat): array
    {
        $credential = $seat->agentCredential;

        return [
            'id' => $seat->id,
            'game' => [
                'id' => $seat->game->id,
                'name' => $seat->game->name,
                'short_name' => $seat->game->short_name,
                'status_label' => $seat->game->status->label(),
            ],
            'role_label' => $seat->role->label(),
            'is_active' => $seat->is_active,
            'can_issue' => $seat->is_active,
            'has_credential' => $credential instanceof AgentCredential,
            'issued_at_diff' => $credential?->created_at?->diffForHumans(),
            'issued_by' => $credential?->issuedBy?->name,
            'last_used_at_diff' => $credential?->last_used_at?->diffForHumans(),
        ];
    }
}
