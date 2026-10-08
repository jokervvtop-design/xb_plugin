<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('v2_tg_ai_knowledge')) {
            Schema::create('v2_tg_ai_knowledge', function (Blueprint $table) {
                $table->integer('id', true);
                $table->string('title');
                $table->text('content');
                $table->string('source', 32)->default('manual');
                $table->char('language', 5)->default('zh-CN');
                $table->boolean('show')->default(true);
                $table->integer('created_at');
                $table->integer('updated_at');
            });
        }

        if (!Schema::hasTable('v2_tg_ai_group_memory')) {
            Schema::create('v2_tg_ai_group_memory', function (Blueprint $table) {
                $table->integer('id', true);
                $table->bigInteger('chat_id');
                $table->bigInteger('telegram_user_id')->nullable();
                $table->text('message_text');
                $table->string('status', 16)->default('pending');
                $table->integer('created_at');
                $table->index(['chat_id', 'status']);
            });
        }

        if (!Schema::hasTable('v2_tg_ai_conversations')) {
            Schema::create('v2_tg_ai_conversations', function (Blueprint $table) {
                $table->integer('id', true);
                $table->bigInteger('chat_id');
                $table->string('role', 16);
                $table->text('content');
                $table->integer('created_at');
                $table->index(['chat_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_tg_ai_conversations');
        Schema::dropIfExists('v2_tg_ai_group_memory');
        Schema::dropIfExists('v2_tg_ai_knowledge');
    }
};
