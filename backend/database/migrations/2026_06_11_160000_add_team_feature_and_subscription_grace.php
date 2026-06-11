<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('max_team_members')->nullable()->after('max_stores');
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->timestamp('subscription_grace_ends_at')->nullable()->after('subscription_ends_at');
        });

        DB::table('plans')->where('slug', 'trial')->update(['max_team_members' => 0]);
        DB::table('plans')->where('slug', 'starter')->update(['max_team_members' => 0]);
        DB::table('plans')->where('slug', 'pro')->update(['max_team_members' => 5]);
        DB::table('plans')->where('slug', 'premium')->update(['max_team_members' => 20]);

        foreach (DB::table('plans')->get() as $plan) {
            $features = json_decode($plan->features ?: '{}', true) ?: [];

            if ($plan->slug === 'premium') {
                $features['team'] = true;
            } else {
                $features['team'] = false;
            }

            DB::table('plans')->where('id', $plan->id)->update([
                'features' => json_encode($features),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('subscription_grace_ends_at');
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('max_team_members');
        });
    }
};
