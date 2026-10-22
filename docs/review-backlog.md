# レビューバックログ(今回対応しない指摘)
| 追記日 | ID | 重大度 | 場所 | 内容 | 起票状況 |
|---|---|---|---|---|---|
| 2026-10-23 | R1-X1 | Low | includes/artws-php-security-notice/class-notice.php:558-567 | 「今後表示しない」の送信失敗（nonce 切れ等）を握りつぶし、user meta が保存されず次の画面で再表示される。共通コピーのため JP4WC 側で修正し Notice::VERSION を上げて配り直す | 未起票（JP4WC 側の課題） |
| 2026-10-23 | R1-L1 | Low | .distignore | .mo 配布により、言語パックが無いサイトでは PHP 文字列は同梱和訳・Block JS（.json 配布除外）は英語の混在が起きる。避けるなら通知専用 .mo に分ける | 未起票 |
| 2026-10-23 | R1-L2 | Low | tests/Integration/PhpSecurityNoticeTest.php | `test_plugin_registers_its_copy_when_wordpress_loads_it` は bootstrap が手動 require するため名前の経路を通っていない（中身は unit と同等） | 未起票 |
| 2026-10-23 | R1-L3 | Low | woocommerce-for-paygent-payment-main.php | `@since 3.0.1` は次の版数の想定。リリース時に合わせる | 未起票 |
| 2026-10-23 | G1-1 | 誤検知 | woocommerce-for-paygent-payment-main.php:85 | Copilot: WP 5.0〜5.4 の gettext は 2 引数という指摘。WP 5.0/5.4 の l10n.php は 3 引数で apply しており誤り | 対応不要 |
| 2026-10-23 | G1-2 | 誤検知 | includes/artws-php-security-notice/class-notice.php:458 | Codex: WP 5.0 では src=false のハンドルのインライン JS が出ないという指摘。WP 5.0 の do_item は src 無しでも出力しており誤り | 対応不要 |
| 2026-10-23 | G1-3 | Low | woocommerce-for-paygent-payment-main.php:89 | Copilot: 補完が determine_locale() で、paygent_for_wc_plugin_locale の上書きと食い違い得る（独自ディレクトリの .mo＋上書き構成のみ） | 未起票 |
