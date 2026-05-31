<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\Migration;
use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

final class CreateUsersTable extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('email', 255)->unique();
            $table->string('username', 64)->unique();

            // Hashed via bcrypt, but the plain input must fit 32 chars max.
            $table->string('password_hash', 60);
            $table->string('password_plain_max_len_check', 32)->nullable();

            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->boolean('is_verified')->default(false);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->index('email');
            $table->index('username');
        });

        $this->seedAdminUser();
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }

    private function seedAdminUser(): void
    {
        $hash = password_hash('TempAdmin1!', PASSWORD_BCRYPT);
        Schema::raw(
            "INSERT INTO users (email, username, password_hash, first_name, last_name, is_verified)
             VALUES ('admin@example.com', 'admin', :hash, 'Site', 'Admin', 1)",
            ['hash' => $hash]
        );
    }
}
