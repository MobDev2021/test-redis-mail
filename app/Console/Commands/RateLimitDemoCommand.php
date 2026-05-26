<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Redisレートリミット デモコマンド
 *
 * 【番外編】記事本文には掲載していないが、実際に動かして確認できるデモ。
 *
 * ## 実行方法
 *
 *   # デフォルト：上限5回、8リクエスト送信
 *   php artisan demo:rate-limit
 *
 *   # オプションで上限・リクエスト数を変更できる
 *   php artisan demo:rate-limit --limit=3 --requests=10
 *
 * ## 期待される出力
 *
 *   [1] ✅ リクエスト通過  残り 4/5 回
 *   [2] ✅ リクエスト通過  残り 3/5 回
 *   ...
 *   [5] ✅ リクエスト通過  残り 0/5 回
 *   [6] 🚫 ブロック — 59秒後にリセット
 *   [7] 🚫 ブロック — 59秒後にリセット
 *   [8] 🚫 ブロック — 59秒後にリセット
 *
 * ## 前提条件
 *
 *   - Docker で Redis が起動していること（docker compose up -d）
 *   - .env の CACHE_STORE=redis が設定されていること
 */
class RateLimitDemoCommand extends Command
{
    protected $signature = 'demo:rate-limit {--limit=5 : 1分間の最大リクエスト数} {--requests=8 : 送信するリクエスト数}';

    protected $description = '【番外編】Redisレートリミットのデモ — 複数サーバーで共有されるカウンター';

    public function handle(): void
    {
        $limit    = (int) $this->option('limit');
        $requests = (int) $this->option('requests');

        // 前回のデモのカウンターが残っている場合に備えてキャッシュクリア
        RateLimiter::clear('demo');

        $this->newLine();
        $this->line('┌─────────────────────────────────────────────────┐');
        $this->line('│       Redis レートリミット デモ                  │');
        $this->line('│  複数サーバーで同じカウンターを共有できる         │');
        $this->line('└─────────────────────────────────────────────────┘');
        $this->newLine();
        $this->line("  設定: <comment>{$limit}リクエスト / 1分</comment>");
        $this->line("  送信: <comment>{$requests}リクエスト</comment>");
        $this->newLine();

        for ($i = 1; $i <= $requests; $i++) {
            $executed = RateLimiter::attempt(
                key:          'demo',
                maxAttempts:  $limit,
                callback:     fn() => true,
                decaySeconds: 60
            );

            $remaining = max(0, $limit - RateLimiter::attempts('demo'));

            if ($executed) {
                $this->line(
                    "  <info>[{$i}]</info> ✅ リクエスト通過  " .
                    "<fg=gray>残り {$remaining}/{$limit} 回</>"
                );
            } else {
                $wait = RateLimiter::availableIn('demo');
                $this->line(
                    "  <info>[{$i}]</info> 🚫 <fg=red>ブロック</> — " .
                    "{$wait}秒後にリセット"
                );
            }

            usleep(100000); // 出力が流れすぎないよう0.1秒ずつ間を空ける
        }

        $this->newLine();
        $this->line('─────────────────────────────────────────────────');
        $this->newLine();
        $this->line('  <comment>なぜ Redis が向いているのか：</comment>');
        $this->line('  DBの場合  → カウンターのINSERT/UPDATEが毎回発生');
        $this->line('  Redisの場合 → INCR コマンド1つ、インメモリで atomic');
        $this->newLine();
        $this->line('  <comment>2サーバー構成での違い：</comment>');
        $this->line('  DBカウンター → 可能だが行ロック競合が発生しやすい');
        $this->line('  Redisカウンター → 全サーバーが同じカウンターを共有、競合なし');
        $this->newLine();
    }
}
