<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::transaction(function (): void {
            $tenants = DB::table('tenants')->select('id')->get();
            $now = now();

            foreach ($tenants as $tenant) {
                $exists = DB::table('tenant_languages')
                    ->where('tenant_id', $tenant->id)
                    ->where('code', 'uz')
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('tenant_languages')->insert([
                    'tenant_id'   => $tenant->id,
                    'code'        => 'uz',
                    'name'        => 'Uzbek',
                    'native_name' => "O'zbekcha",
                    'flag_emoji'  => '🇺🇿',
                    'is_default'  => true,
                    'is_active'   => true,
                    'sort_order'  => 0,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);
            }
        });
    }

    public function down(): void
    {
        DB::table('tenant_languages')->where('code', 'uz')->delete();
    }
};
