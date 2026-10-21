<?php

use Database\Seeders\DispatcherRoleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Platform dispatch (Prompt 3, Phase 4).
//
// logistics_companies: vendor_id becomes nullable — null is a PLATFORM
// logistics company, the outside firms GadgetPlug sends online orders through.
// Vendor-owned rows are untouched and never offered for online orders. Adds
// the contact person, notes, and which areas a company covers (LGA and state
// ids), used to suggest companies when assigning.
//
// orders: who is delivering (company, plus the rider's name and phone as free
// text — riders are the company's people, not our records), what we pay the
// company (pre-filled from the delivery rule's expected cost) and whether it
// has been paid, the customer's delivery code (hashed only, with an attempt
// count and lock), a link version that invalidates an old rider link on
// reassignment, how delivery was confirmed, and failed-delivery returns.
//
// The dispatcher role and manage_dispatch permission are created here so
// production never depends on a seeder being remembered.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('logistics_companies', function (Blueprint $table) {
            $table->foreignId('vendor_id')->nullable()->change();
            $table->string('contact_person')->nullable()->after('name');
            $table->text('notes')->nullable();
            $table->json('coverage_lga_ids')->nullable();
            $table->json('coverage_state_ids')->nullable();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('rider_name', 120)->nullable();
            $table->string('rider_phone', 30)->nullable();
            $table->timestamp('dispatch_assigned_at')->nullable();
            $table->foreignId('dispatch_assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('dispatch_link_version')->default(0);

            $table->decimal('company_cost', 12, 2)->nullable();
            $table->timestamp('company_paid_at')->nullable();
            $table->foreignId('company_paid_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('delivery_code_hash')->nullable();
            $table->timestamp('delivery_code_set_at')->nullable();
            $table->unsignedTinyInteger('delivery_code_attempts')->default(0);
            $table->timestamp('delivery_code_locked_at')->nullable();
            $table->string('delivered_via', 20)->nullable();

            $table->timestamp('returned_at')->nullable();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('return_reason', 500)->nullable();
        });

        (new DispatcherRoleSeeder)->run();
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            foreach (['dispatch_assigned_by', 'company_paid_by', 'returned_by'] as $fk) {
                $table->dropConstrainedForeignId($fk);
            }
            $table->dropColumn([
                'rider_name', 'rider_phone', 'dispatch_assigned_at', 'dispatch_link_version',
                'company_cost', 'company_paid_at',
                'delivery_code_hash', 'delivery_code_set_at', 'delivery_code_attempts', 'delivery_code_locked_at', 'delivered_via',
                'returned_at', 'return_reason',
            ]);
        });

        Schema::table('logistics_companies', function (Blueprint $table) {
            $table->dropColumn(['contact_person', 'notes', 'coverage_lga_ids', 'coverage_state_ids']);
        });
        // vendor_id stays nullable: platform companies may exist by now.

        $roleId = DB::table('roles')->where('name', DispatcherRoleSeeder::ROLE)->whereNull('team_id')->value('id');

        if ($roleId) {
            DB::table('model_has_roles')->where('role_id', $roleId)->delete();
            DB::table('role_has_permissions')->where('role_id', $roleId)->delete();
            DB::table('roles')->where('id', $roleId)->delete();
        }

        $permissionId = DB::table('permissions')->where('name', DispatcherRoleSeeder::PERMISSION)->value('id');

        if ($permissionId) {
            DB::table('model_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};
