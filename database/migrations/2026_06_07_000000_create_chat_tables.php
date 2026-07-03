<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('friends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requester_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('recipient_id')->constrained('users')->onDelete('cascade');
            $table->enum('status', ['pending', 'accepted', 'blocked'])->default('pending');
            $table->timestamps();
            
            $table->unique(['requester_id', 'recipient_id']);
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->string('chat_name')->nullable();
            $table->boolean('is_group_chat')->default(false);
            $table->unsignedBigInteger('latest_message_id')->nullable();
            $table->foreignId('creator_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
        });

        Schema::create('conversation_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->boolean('is_admin')->default(false);
            $table->timestamps();
            
            $table->unique(['conversation_id', 'user_id']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('conversation_id')->constrained('conversations')->onDelete('cascade');
            $table->text('content')->nullable();
            $table->enum('message_type', ['text', 'image', 'video', 'file', 'system', 'poll', 'note'])->default('text');
            $table->string('file_url')->nullable();
            $table->string('file_provider')->default('local');
            $table->string('file_public_id')->nullable();
            $table->string('file_name')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('mime_type')->nullable();
            $table->boolean('is_deleted_by_sender')->default(false);
            $table->boolean('is_deleted_for_all')->default(false);
            $table->timestamp('deleted_at')->nullable();
            $table->unsignedBigInteger('reply_to_message_id')->nullable();
            $table->timestamps();
        });

        // Update conversations with foreign key for latest_message_id
        Schema::table('conversations', function (Blueprint $table) {
            $table->foreign('latest_message_id')->references('id')->on('messages')->onDelete('set null');
        });

        // Self-referencing foreign key for reply_to_message_id
        Schema::table('messages', function (Blueprint $table) {
            $table->foreign('reply_to_message_id')->references('id')->on('messages')->onDelete('set null');
        });

        Schema::create('message_read_by', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('messages')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->timestamps();
            
            $table->unique(['message_id', 'user_id']);
        });

        Schema::create('polls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->onDelete('cascade');
            $table->string('question');
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('poll_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_id')->constrained('polls')->onDelete('cascade');
            $table->string('text');
            $table->timestamps();
        });

        Schema::create('poll_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_option_id')->constrained('poll_options')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->timestamps();
            
            $table->unique(['poll_option_id', 'user_id']);
        });

        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->onDelete('cascade');
            $table->text('content');
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['reply_to_message_id']);
        });
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign(['latest_message_id']);
        });
        
        Schema::dropIfExists('notes');
        Schema::dropIfExists('poll_votes');
        Schema::dropIfExists('poll_options');
        Schema::dropIfExists('polls');
        Schema::dropIfExists('message_read_by');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversation_user');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('friends');
    }
};
