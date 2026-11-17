<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suggested actions under a weekly brief. The AI proposes a kind and a target (type + an id it
 * was given in the facts); the server builds the admin link — never a model-made URL. A person
 * marks each one done or not useful (`decided_by`, `decided_at`); nothing runs automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_brief_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_brief_id')->constrained('ai_briefs')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('priority', 10)->comment('high|medium|low');
            $table->string('kind', 20)->comment('restock|campaign|verify_orders|bangla_copy|other');
            $table->string('title', 160);
            $table->string('why', 600);
            $table->json('figures');
            $table->string('target_type', 30);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('status', 12)->default('open')->comment('open|done|dismissed');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['ai_brief_id', 'status']);
            $table->index(['status', 'decided_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_brief_actions');
    }
};
