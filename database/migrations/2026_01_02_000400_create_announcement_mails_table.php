<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gönderilen her anons talebi maili.
 *
 * Alıcı başına bir satır: kime, hangi dilde, hangi metinle gidildiği ve
 * gönderimin sonucu sonradan okunabilsin diye saklanır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcement_mails', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('recipient_label');
            $table->string('recipient_email');
            $table->string('locale', 5);

            $table->string('subject');
            $table->text('body');

            $table->text('prefixes');
            $table->string('asn', 20);
            $table->string('downstream_asn', 20)->nullable();

            $table->string('status', 20)->default('queued');
            $table->timestamp('sent_at')->nullable();
            $table->text('error')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_mails');
    }
};
