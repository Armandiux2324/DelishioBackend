<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('pin', 255)->change();
        });

        DB::table('employees')->orderBy('user_id')->each(function (object $employee) {
            if (preg_match('/^\d{4,6}$/', $employee->pin)) {
                DB::table('employees')->where('user_id', $employee->user_id)->update(['pin' => Hash::make($employee->pin)]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Los hashes no se pueden convertir de nuevo en PINs; se conserva la columna ampliada.
    }
};
