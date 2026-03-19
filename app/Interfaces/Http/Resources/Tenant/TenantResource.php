<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Resources\Tenant;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Domain\Tenant\Models\Tenant
 */
final class TenantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                    => $this->id,
            'ulid'                  => $this->ulid,
            'name'                  => $this->name,
            'slug'                  => $this->slug,
            'status'                => $this->status->value,
            'status_label'          => $this->status->label(),
            'settings'              => $this->settings,
            'storage_used_mb'       => $this->storage_used_mb,
            'storage_quota_mb'      => $this->storage_quota_mb,
            'storage_usage_percent' => $this->storage_usage_percent,
            'max_users'             => $this->max_users,
            'max_books'             => $this->max_books,
            'is_on_trial'           => $this->isOnTrial(),
            'trial_ends_at'         => $this->trial_ends_at?->toIso8601String(),
            'suspended_at'          => $this->suspended_at?->toIso8601String(),
            'primary_url'           => $this->primary_url,

            'plan' => $this->whenLoaded('plan', fn () => [
                'id'   => $this->plan->id,
                'name' => $this->plan->name,
                'slug' => $this->plan->slug,
            ]),

            'domains' => $this->whenLoaded('domains', fn () =>
                $this->domains->map(fn ($d) => [
                    'id'         => $d->id,
                    'domain'     => $d->domain,
                    'type'       => $d->type,
                    'is_primary' => $d->is_primary,
                    'ssl_status' => $d->ssl_status,
                    'verified_at'=> $d->verified_at?->toIso8601String(),
                ])
            ),

            'subscription' => $this->whenLoaded('subscription', fn () => $this->subscription ? [
                'status'       => $this->subscription->status,
                'plan_id'      => $this->subscription->plan_id,
                'billing_cycle'=> $this->subscription->billing_cycle,
                'period_end'   => $this->subscription->current_period_end?->toIso8601String(),
            ] : null),

            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
