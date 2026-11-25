<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SCENES = ['community_post_commented', 'community_comment_replied'];

    public function up(): void
    {
        $now = now();
        foreach (self::SCENES as $scene) {
            $row = DB::table('notification_scene_configs')->where('scene_code', $scene)->first();
            $original = $row === null ? [] : (json_decode((string) $row->channels, true) ?: []);
            $channels = array_values(array_unique([...$original, 'in_app', 'wechat_mini', 'wechat_oa']));

            if ($row === null) {
                DB::table('notification_scene_configs')->insert([
                    'scene_code' => $scene,
                    'enabled' => true,
                    'channels' => json_encode($channels),
                    'lock_version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                continue;
            }
            if ($channels === $original) {
                continue;
            }
            DB::table('notification_scene_configs')->where('id', $row->id)->update([
                'channels' => json_encode($channels),
                'lock_version' => (int) $row->lock_version + 1,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        foreach (self::SCENES as $scene) {
            $row = DB::table('notification_scene_configs')->where('scene_code', $scene)->first();
            if ($row === null) {
                continue;
            }
            $channels = array_values(array_diff(json_decode((string) $row->channels, true) ?: [], ['wechat_mini', 'wechat_oa']));
            DB::table('notification_scene_configs')->where('id', $row->id)->update([
                'channels' => json_encode($channels),
                'lock_version' => (int) $row->lock_version + 1,
                'updated_at' => now(),
            ]);
        }
    }
};
