<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('data', function (Blueprint $table) {
            $table->id()->autoIncrement();
            $table->date('tanggal');
            $table->string('nopol');
            $table->string('driver')->nullable();
            $table->string('origin');
            $table->string('destinasi');
            $table->integer('uj');
            $table->integer('harga');
            $table->string('status');
            $table->string('status_sj');
            $table->date('tanggal_update_sj');
            $table->string('foto')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('data');
    }
};
