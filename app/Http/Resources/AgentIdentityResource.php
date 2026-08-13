<?php

namespace App\Http\Resources;

use App\Models\GameSeat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What an agent is told about itself: who it is, which game it is in, and what it may do there.
 *
 * The resource wraps a **seat** rather than an account, because a seat is what the token authenticates
 * as and what an order will be attributed to. The account's name is included because it is how an
 * administrator refers to the agent; its email address is not, because it is a `.invalid` address that
 * reaches nothing and an agent has no use for it.
 *
 * @mixin GameSeat
 */
class AgentIdentityResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'agent' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ],
            'game' => [
                'id' => $this->game->id,
                'name' => $this->game->name,
                'short_name' => $this->game->short_name,
                'status' => $this->game->status->value,
                'status_label' => $this->game->status->label(),
            ],
            'seat' => [
                'id' => $this->id,
                'role' => $this->role->value,
                'role_label' => $this->role->label(),
            ],
        ];
    }
}
