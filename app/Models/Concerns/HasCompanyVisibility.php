<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

trait HasCompanyVisibility
{
    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (! $user->canAccessWorkspace()) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->role->isSystemRole()) {
            return $query;
        }

        if ($user->company_id === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(
            $query->getModel()->qualifyColumn($this->companyVisibilityColumn()),
            $user->company_id,
        );
    }

    protected function companyVisibilityColumn(): string
    {
        return 'company_id';
    }
}
