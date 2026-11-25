<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $row = DB::table('notification_scene_configs')->where('scene_code', 'post_review_approved')->first();
        $original = $row === null ? [] : (json_decode((string) $row->channels, true) ?: []);
        $channels = array_values(array_unique([...$original, 'in_app', 'wechat_mini', 'wechat_oa']));

        if ($row === null) {
            DB::table('notification_scene_configs')->insert([
                'scene_code' => 'post_review_approved',
                'enabled' => true,
                'channels' => json_encode($channels),
                'lock_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return;
        }
        if ($channels === $original) {
            return;
        }
        DB::table('notification_scene_configs')->where('id', $row->id)->update([
            'channels' => json_encode($channels),
            'lock_version' => (int) $row->lock_version + 1,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $row = DB::table('notification_scene_configs')->where('scene_code', 'post_review_approved')->first();
        if ($row === null) {
            return;
        }
        $channels = array_values(array_diff(json_decode((string) $row->channels, true) ?: [], ['wechat_oa']));
        DB::table('notification_scene_configs')->where('id', $row->id)->update([
            'channels' => json_encode($channels),
            'lock_version' => (int) $row->lock_version + 1,
            'updated_at' => now(),
        ]);
    }
};
