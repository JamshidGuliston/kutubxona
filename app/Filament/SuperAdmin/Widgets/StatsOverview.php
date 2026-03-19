<?php

declare(strict_types=1);

namespace App\Filament\SuperAdmin\Widgets;

use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class StatsOverview extends BaseWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('Jami tenantlar', Tenant::withoutGlobalScopes()->count())
                ->description('Barcha tenantlar')
                ->color('primary')
                ->icon('heroicon-o-building-library'),

            Stat::make('Faol tenantlar', Tenant::withoutGlobalScopes()->where('status', 'active')->count())
                ->description('Hozir faol')
                ->color('success')
                ->icon('heroicon-o-check-circle'),

            Stat::make('Jami foydalanuvchilar', User::withoutGlobalScopes()->count())
                ->description('Barcha foydalanuvchilar')
                ->color('info')
                ->icon('heroicon-o-users'),
        ];
    }
}
