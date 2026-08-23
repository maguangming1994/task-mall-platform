<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_claims', function (Blueprint $table) {
            $table->string('execution_status', 32)->default('reserved')->after('status')->index();
            $table->string('review_status', 32)->nullable()->after('execution_status')->index();
        });

        Schema::table('task_claim_status_changes', function (Blueprint $table) {
            $table->string('from_execution_status', 32)->nullable()->after('to_status');
            $table->string('to_execution_status', 32)->nullable()->after('from_execution_status');
            $table->string('from_review_status', 32)->nullable()->after('to_execution_status');
            $table->string('to_review_status', 32)->nullable()->after('from_review_status');
        });

        DB::table('task_claims')->orderBy('id')->eachById(function (object $claim): void {
            [$executionStatus, $reviewStatus] = match ($claim->status) {
                'claimed' => ['reserved', null],
                'submitted' => ['under_review', 'queued'],
                'reviewing' => ['under_review', 'reviewing'],
                'withdrawn' => ['in_progress', 'voided'],
                'rejected' => ['revision_required', 'needs_revision'],
                'appealed' => ['under_appeal', 'rejected'],
                'approved' => ['completed', 'approved'],
                'cancelled' => ['abandoned', 'voided'],
                'expired' => ['expired', 'voided'],
                'appeal_rejected' => ['terminated', 'rejected'],
                default => ['reserved', null],
            };

            DB::table('task_claims')->where('id', $claim->id)->update([
                'execution_status' => $executionStatus,
                'review_status' => $reviewStatus,
            ]);
        });

        DB::table('task_claim_status_changes')->orderBy('id')->eachById(function (object $change): void {
            $fromStates = $change->from_status === null ? [null, null] : $this->statesFor($change->from_status);
            $toStates = $this->statesFor($change->to_status);

            DB::table('task_claim_status_changes')->where('id', $change->id)->update([
                'from_execution_status' => $fromStates[0],
                'to_execution_status' => $toStates[0],
                'from_review_status' => $fromStates[1],
                'to_review_status' => $toStates[1],
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('task_claim_status_changes', function (Blueprint $table) {
            $table->dropColumn(['from_execution_status', 'to_execution_status', 'from_review_status', 'to_review_status']);
        });

        Schema::table('task_claims', function (Blueprint $table) {
            $table->dropIndex(['execution_status']);
            $table->dropIndex(['review_status']);
            $table->dropColumn(['execution_status', 'review_status']);
        });
    }

    /** @return array{string,?string} */
    private function statesFor(string $legacyStatus): array
    {
        return match ($legacyStatus) {
            'claimed' => ['reserved', null],
            'submitted' => ['under_review', 'queued'],
            'reviewing' => ['under_review', 'reviewing'],
            'withdrawn' => ['in_progress', 'voided'],
            'rejected' => ['revision_required', 'needs_revision'],
            'appealed' => ['under_appeal', 'rejected'],
            'approved' => ['completed', 'approved'],
            'cancelled' => ['abandoned', 'voided'],
            'expired' => ['expired', 'voided'],
            'appeal_rejected' => ['terminated', 'rejected'],
            default => ['reserved', null],
        };
    }
};
