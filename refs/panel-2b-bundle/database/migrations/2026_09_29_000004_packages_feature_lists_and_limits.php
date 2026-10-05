<?php

declare(strict_types=1);

use App\Support\PackageLimits;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Step 4 — feature lists + remaining cPanel-compatible package limit keys. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('feature_lists')) {
            Schema::create('feature_lists', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 100);
                $table->json('features');
                $table->boolean('is_default')->default(false);
                $table->timestamps();
            });
        }

        Schema::table('packages', function (Blueprint $table): void {
            if (! Schema::hasColumn('packages', 'feature_list_id')) {
                $table->unsignedBigInteger('feature_list_id')->nullable()->after('description');
            }
            foreach ([
                'MAXFWD', 'MAXRESP', 'MAXPASS', 'MAXLST', 'MAILBOXQUOTA', 'DBQUOTA',
                'MAXEMAILPERHOUR', 'MAXMSGSIZE', 'CPULIMIT', 'RAMLIMIT', 'IOLIMIT',
                'NPROCLIMIT', 'EPLIMIT',
            ] as $col) {
                if (! Schema::hasColumn('packages', $col)) {
                    $default = PackageLimits::DEFAULTS[$col] ?? -1;
                    $table->integer($col)->default($default);
                }
            }
            if (! Schema::hasColumn('packages', 'DEDICATEDIP')) {
                $table->boolean('DEDICATEDIP')->default(false);
            }
        });

        $now = now();
        if (DB::table('feature_lists')->count() === 0) {
            $id = DB::table('feature_lists')->insertGetId([
                'name' => 'default',
                'features' => json_encode(PackageLimits::defaultFeatures()),
                'is_default' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('packages')->whereNull('feature_list_id')->update(['feature_list_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table): void {
            if (Schema::hasColumn('packages', 'feature_list_id')) {
                $table->dropColumn('feature_list_id');
            }
        });
        Schema::dropIfExists('feature_lists');
    }
};
