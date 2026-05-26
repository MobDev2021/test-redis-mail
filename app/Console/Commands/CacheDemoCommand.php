<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

/**
 * Redisキャッシュ デモコマンド
 *
 * 【番外編】記事本文には掲載していないが、実際に動かして確認できるデモ。
 *
 * ## 実行方法
 *
 *   php artisan demo:cache
 *
 * ## 期待される出力（3つのセクションが順番に表示される）
 *
 *   ▼ APCu（PHP プロセス内キャッシュ）の場合
 *     [サーバー1] user:1:name を "Taro" でキャッシュ保存
 *     [サーバー2] user:1:name を取得 → null（取得できない）
 *     → サーバー1で保存したキャッシュはサーバー2から見えない
 *
 *   ▼ Redis キャッシュの場合
 *     [サーバー1] user:1:name を "Taro" でキャッシュ保存（TTL: 60秒）
 *     [サーバー2] user:1:name を取得 → Taro ✅
 *     → TTL: あと 60秒で自動削除される
 *
 *   ▼ キャッシュ無効化も一元管理できる
 *     [サーバー1] user:1 の各キャッシュを保存
 *     [サーバー1] user:1 のデータが更新された → 関連キャッシュを一括削除
 *     [サーバー2] 次回アクセス時に自動的にDBから再取得される ✅
 *
 * ## 前提条件
 *
 *   - Docker で Redis が起動していること（docker compose up -d）
 *   - .env の CACHE_STORE=redis が設定されていること
 */
class CacheDemoCommand extends Command
{
    protected $signature = 'demo:cache';

    protected $description = '【番外編】Redisキャッシュのデモ — 複数サーバー間でのキャッシュ共有';

    public function handle(): void
    {
        $this->newLine();
        $this->line('┌─────────────────────────────────────────────────┐');
        $this->line('│       Redis キャッシュ デモ                      │');
        $this->line('│  2サーバー構成でのキャッシュ共有を疑似体験        │');
        $this->line('└─────────────────────────────────────────────────┘');
        $this->newLine();

        // ── APCu のシミュレーション ──────────────────────────────────
        // APCu = PHP プロセス内のメモリにデータを保存するキャッシュ拡張
        //        高速だが「そのプロセスの中だけ」で有効。別サーバーのプロセスとは共有されない
        $this->line('<comment>▼ APCu（PHP プロセス内キャッシュ）の場合</comment>');
        $this->newLine();

        // 別プロセスのキャッシュを配列で模倣（実際の APCu もプロセスをまたいでは共有されない）
        $process_a_cache = [];
        $process_b_cache = [];

        $process_a_cache['user:1:name'] = 'Taro';
        $this->line('  [サーバー1] user:1:name を "Taro" でキャッシュ保存');

        // $process_b_cache はサーバー2の別プロセス — サーバー1の書き込みは届かない
        $result = $process_b_cache['user:1:name'] ?? null;
        $this->line(
            '  [サーバー2] user:1:name を取得 → '.
            ($result ? "<info>{$result}</info>" : '<fg=red>null（取得できない）</>')
        );
        $this->newLine();
        $this->line('  <fg=red>→ サーバー1で保存したキャッシュはサーバー2から見えない</fg=red>');
        $this->line('  <fg=red>→ サーバー2は毎回DBにアクセスすることになる</fg=red>');

        $this->newLine();
        $this->line('─────────────────────────────────────────────────');
        $this->newLine();

        // ── Redis キャッシュ ─────────────────────────────────────────
        $this->line('<comment>▼ Redis キャッシュの場合</comment>');
        $this->newLine();

        Cache::forget('demo:user:1:name'); // 前回のデモが残っている場合に備えてリセット

        Cache::put('demo:user:1:name', 'Taro', 60);
        $this->line('  [サーバー1] user:1:name を "Taro" でキャッシュ保存（TTL: 60秒）');

        $result = Cache::get('demo:user:1:name'); // サーバー2も同じRedisを参照するので取得できる
        $this->line(
            '  [サーバー2] user:1:name を取得 → '.
            "<info>{$result}</info> ✅"
        );
        $this->newLine();
        $this->line('  <info>→ どのサーバーからも同じキャッシュを参照できる</info>');

        // Cache は 'cache' 接続（database 1）を使う
        // Redis ファサードのデフォルト（database 0）とは別DBのため connection('cache') を明示する
        $prefix = config('cache.prefix', '');
        $redis_key = $prefix ? "{$prefix}demo:user:1:name" : 'demo:user:1:name';
        $ttl = Redis::connection('cache')->ttl($redis_key);
        $ttl_display = $ttl > 0 ? "{$ttl}秒" : '60秒以内';
        $this->line("  <info>→ TTL: あと {$ttl_display}で自動削除される</info>");

        $this->newLine();
        $this->line('─────────────────────────────────────────────────');
        $this->newLine();

        // ── キャッシュ更新の一元管理 ─────────────────────────────────
        $this->line('<comment>▼ キャッシュ無効化も一元管理できる</comment>');
        $this->newLine();

        Cache::put('demo:user:1:name', 'Taro', 60);
        Cache::put('demo:user:1:email', 'taro@example.com', 60);
        Cache::put('demo:user:1:plan', 'premium', 60);

        $this->line('  [サーバー1] user:1 の各キャッシュを保存');
        $this->line('  → demo:user:1:name  = Taro');
        $this->line('  → demo:user:1:email = taro@example.com');
        $this->line('  → demo:user:1:plan  = premium');
        $this->newLine();

        $cache_conn = Redis::connection('cache'); // Cache と同じ database を参照するため明示
        $opt_prefix = $cache_conn->client()->getOption(\Redis::OPT_PREFIX);

        // keys() は全キースキャンで本番NGのため、scan() でカーソル走査する
        $cursor = null;
        do {
            [$cursor, $keys] = $cache_conn->scan($cursor, ['match' => '*demo:user:1*', 'count' => 100]);
            foreach ($keys as $key) {
                // scan() は OPT_PREFIX 付きのキー名を返すが del() も内部で付加するため
                // 二重プレフィックスを避けるために除去してから渡す
                $cache_conn->del(substr($key, strlen($opt_prefix)));
            }
        } while ($cursor !== '0' && $cursor !== 0);

        $this->line('  [サーバー1] user:1 のデータが更新された → 関連キャッシュを一括削除');
        $this->line('  [サーバー2] 次回アクセス時に自動的にDBから再取得される ✅');

        $this->newLine();
        $this->line('─────────────────────────────────────────────────');
        $this->newLine();
        $this->line('  <comment>まとめ：</comment>');
        $this->line('  APCu（プロセス内）→ 速いが各プロセスで独立。2サーバー構成では使えない');
        $this->line('  Redis  → 少し遅いが全サーバーで共有。TTLで自動削除。一元管理');
        $this->newLine();
    }
}
