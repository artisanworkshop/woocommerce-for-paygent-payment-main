# PAYGENT for WooCommerce — Claude Code ガイド

## プロジェクト概要

Paygent決済をWooCommerceに統合するWordPressプラグイン（v3.0.0）。
日本国内の主要決済手段（クレジットカード、コンビニ、キャリア決済、QRコード等）をサポート。

## 技術スタック・要件

| 項目 | バージョン |
| --- | --- |
| PHP | >= 7.4（推奨 8.2+） |
| WordPress | >= 5.0（推奨 7.0+ / 7.1で検証済み） |
| WooCommerce | >= 8.0.0（推奨 11.0+ / 11.0.1で検証済み） |
| WooCommerce Subscriptions | 継続課金使用時のみ必須 |

## Git・PR 運用

- フォーク運用: `origin` が shoheitanaka のフォーク、`upstream` が artisanworkshop の本流。既定ブランチは **`trunk`**（`main` ではない）。
  PR は `gh pr create --repo artisanworkshop/woocommerce-for-paygent-payment-main --head shoheitanaka:<branch> --base trunk`
- ドキュメントだけの更新もフォークの `trunk` にコミットし、`trunk` から upstream へ PR を出す（PR #42・#45）。
  それが upstream に入る前に作業ブランチを切ると、その PR に混入する（PR #46 に #45 のコミットが入った）
- Codex は push では自動レビューしない。PR に `@codex review` とコメントして依頼する（dev-cycle は `--request-codex`）

## ファイル構成

```text
woocommerce-for-paygent-payment-main/
├── woocommerce-for-paygent-payment-main.php  ← プラグインエントリポイント
├── class-wc-gateway-paygent.php              ← メインクラス（ゲートウェイ登録・管理）
├── uninstall.php
│
├── includes/
│   ├── admin/
│   │   ├── class-wc-admin-screen-paygent.php      ← 管理画面
│   │   └── class-jp4wc-card-expiry-notifier.php   ← カード有効期限通知
│   ├── class-jp4wc-order-attempt-limiter.php       ← 注文試行制限
│   ├── artws-php-security-notice/                  ← 共通PHPセキュリティ通知（JP4WCから無編集コピー）
│   └── jp4wc-framework/                            ← JP4WC共通フレームワーク
│
├── includes/gateways/paygent/
│   ├── class-wc-gateway-paygent-cc.php        ← クレジットカード
│   ├── class-wc-gateway-paygent-addon-cc.php  ← クレジットカード（Subscriptions対応）
│   ├── class-wc-gateway-paygent-mccc.php      ← 多通貨クレジットカード
│   ├── class-wc-gateway-paygent-cs.php        ← コンビニ決済
│   ├── class-wc-gateway-paygent-atm.php       ← 仮想口座（ATM）
│   ├── class-wc-gateway-paygent-bn.php        ← 銀行ネット
│   ├── class-wc-gateway-paygent-mb.php        ← キャリア決済（Subscriptions対応含む）
│   ├── class-wc-gateway-paygent-addon-mb.php  ← キャリア継続課金アドオン（旧）
│   ├── class-wc-gateway-paygent-paidy.php     ← Paidy
│   ├── class-wc-gateway-paygent-paypay.php    ← PayPay
│   ├── class-wc-gateway-paygent-rakuten-pay.php ← 楽天ペイ
│   ├── class-wc-paygent-endpoint.php          ← REST API Webhook
│   └── includes/
│       ├── class-wc-gateway-paygent-request.php ← コアAPIクライアント
│       └── block/                              ← Block Checkout統合クラス（全9決済対応）
│
├── src/blocks/                                ← Block Checkout用JSソース（webpack → build/）
├── build/                                     ← ビルド成果物（コミット対象・npm run build）
│
├── vendor-wc/paygent/connect/src/paygent_module/
│   └── System/PaygentB2BModule.php            ← Paygent公式通信モジュール
│
├── 2025docs/                                  ← Paygent公式仕様書PDF（要確認）
├── report/                                    ← サンドボックス検証レポート（.distignore対象）
├── scripts/
│   ├── check-pdf-updates.sh                   ← PDF変更検知
│   └── update-pdf-hashes.sh                   ← PDFハッシュ記録
└── .claude/
    ├── pdf-hashes.txt                         ← PDFハッシュ（check-pdf-updates.sh用）
    └── skills/                                ← Claude Codeスキル
```

## ゲートウェイID一覧

| ゲートウェイID | クラス | 決済手段 | Subscriptions |
| --- | --- | --- | :---: |
| `paygent_cc` | `WC_Gateway_Paygent_CC` / `_Addon_CC` | クレジットカード | ○ |
| `paygent_mccc` | `WC_Gateway_Paygent_MCCC` | 多通貨クレジットカード | - |
| `paygent_cs` | `WC_Gateway_Paygent_CS` | コンビニ決済 | - |
| `paygent_atm` | `WC_Gateway_Paygent_ATM` | 仮想口座（ATM） | - |
| `paygent_bn` | `WC_Gateway_Paygent_BN` | 銀行ネット | - |
| `paygent_mb` | `WC_Gateway_Paygent_MB` / `_Addon_MB` | キャリア決済 | ○ |
| `paygent_paidy` | `WC_Gateway_Paygent_Paidy` | Paidy | - |
| `paygent_paypay` | `WC_Gateway_Paygent_PayPay` | PayPay | - |
| `paygent_rakutenpay` | `WC_Gateway_Paygent_Rakuten_Pay` | 楽天ペイ | - |

WooCommerce Subscriptionsが有効な場合、CC/MBは`_Addon_`クラスに自動切替。

## コア技術概念

### API通信

- **プロトコル**: HTTPS POST（`application/x-www-form-urlencoded`）
- **文字コード**: 全通信が**Shift_JIS**（リクエスト: UTF-8→SJIS変換、レスポンス: SJIS→UTF-8変換）
- **認証**: `merchant_id` / `connect_id` / `connect_password`（WordPress optionに保存）
- **電文種別**: `telegram_kind`（3桁コード）で処理種別を指定

### 主要電文種別コード

| コード | 決済 | 内容 |
| --- | --- | --- |
| `020` | CC | オーソリ申込 |
| `021` | CC | オーソリキャンセル |
| `022` | CC | 売上（キャプチャ） |
| `023` | CC | 売上キャンセル |
| `028` | CC | 補正オーソリ（金額変更） |
| `029` | CC | 補正売上（金額変更） |
| `030` | コンビニ | 申込（番号方式） |
| `010` | 仮想口座（ATM） | 申込（ATM決済電文） |
| `060` | 銀行ネット | 申込（ASP） |
| `100` | キャリア | 都度課金申込 |
| `101` | キャリア | 売上要求 |
| `102` | キャリア | 取消要求 |
| `120` | キャリア | 継続課金申込 |
| `270` | 楽天ペイ | 申込 |
| `300` | 銀聯 | 申込 |
| `310` | Alipay | 申込 |
| `320` | Apple Pay | オーソリ |
| `340` | Paidy | オーソリキャンセル |
| `350` | Google Pay | オーソリ |
| `420` | PayPay | 申込 |
| `421` | PayPay | 取消返金 |
| `094` | 全決済 | 照会（共通） |

### ハッシュチェック

SHA-256による改ざん検知。`merchant_id + connect_id + connect_password + telegram_kind + telegram_version + trading_id +（payment_id）+（payment_amount）+ request_date` を順に連結し、末尾に `hash_code` を付加してハッシュ化（`make_hash_data()`）。リクエストには `hc` と `request_date` として付与。

### Webhook

`POST /wp-json/paygent/v1/check` — コンビニ・仮想口座の入金通知を受信。
`WC_Paygent_Endpoint::paygent_check_webhook()` でステータス更新。

**既知の制限（ATM）**: `paygent_check_webhook()` の `payment_type` switch で ATM（`case 01`）は
ハンドラ未実装（`break`のみ）。入金通知を受けても注文メモが追加されるだけで、WooCommerce注文
ステータスは自動更新されない。ATM決済は加盟店管理者サイトで消込を確認し手動更新する運用が必要
（他決済 `paygent_cv_webhook()`/`paygent_bn_webhook()` 等との非対称）。

### HPOS対応

WooCommerce High Performance Order Storage（HPOS）完全対応済み。
`$order->get_meta()` / `$order->update_meta_data()` を使用し、`get_post_meta()` は使わない。

### 共通PHPセキュリティ通知（`includes/artws-php-security-notice/`）

Artisan Workshop の各プラグインが同梱する PHP 脆弱性の通知（Issue #44）。
- Japanized for WooCommerce のタグから**無編集でコピー**する（`diff -r` で一致を確認）。直す必要が出たら
  ここでは直さず、JP4WC 側で直して `Notice::VERSION` を上げ、全プラグインへコピーし直す
- PHP 7.0 で構文エラーにならない書き方のまま保つ。このリポジトリには PHPCompatibilityWP が無いので
  JP4WC の `vendor/bin/phpcs --standard=PHPCompatibilityWP --runtime-set testVersion 7.0- includes/artws-php-security-notice/` で確認する
- 登録した全コピーのうち最新版だけが `plugins_loaded` 5 で読み込まれる（フレームワークの「先に読んだ方」とは逆）。
  メインファイルが `artws_php_security_notice_register()` に渡す版数は `Notice::VERSION` と揃える
- メインファイルは `register.php` を `is_readable()` のときだけ読む（更新中のリクエストは通知なしで続行）。
  登録は WooCommerce の有無の判定より前・外で行う
- 文言は `wc_gateway_paygent_php_security_notice_strings()` が返す。言語パックに無い訳は、callback の実行中だけ
  同梱の `.mo` から補う（そのため `.mo` は配布する）。最低対応が WP 5.0 なので、JP4WC と違い
  `gettext_{$domain}`（WP 5.5+）ではなく `gettext` にフックしてテキストドメインで絞り、
  `WP_Translation_File`（WP 6.5+）が無いときは `MO` クラスで読む
- phpcs の `PrefixAllGlobals`（`artws_` / `ArtisanWorkshop\PhpSecurityNotice`）はこのディレクトリだけに効かせている
  （プラグイン本体は接頭辞を未設定）

## WordPress コーディング標準

- **入力**: `sanitize_text_field()` / `absint()` / `wp_unslash()` 等でサニタイズ
- **出力**: `esc_html()` / `esc_attr()` / `wp_kses_post()` でエスケープ
- **nonce**: フォーム送信は必ず nonce 検証
- **外部スクリプトURLは `https://` を明示**（プロトコル相対 `//` は禁止。httpサイトでは
  ポート80へ解決され、Paygent側が遮断するためページ描画が約75秒ブロックされる）
- `phpcs` / `phpstan` が設定されている場合はコミット前に実行
- **git commit / push はユーザーからの明示的な指示があるまで実行しない**。
  修正はワーキングツリーに残した状態で報告し、コミットするかどうかの判断はユーザーに委ねる
- 新しい開発専用ディレクトリ（`report/`等）を追加したら `.distignore` にも追記する
  （配布ZIPへの除外漏れをCopilotレビューで指摘された実績あり）

## Claude Code スキル

`.claude/skills/` に9つのプロジェクト固有スキルが定義されています。関連するキーワードやファイルを編集する際に自動的に発動します。
WordPress / WooCommerce 汎用スキル（`wc-development`, `wp-security-check` 等）は共有スキル（`~/.claude/skills/`）を使い、
このリポジトリには複製しない。

| スキル | 発動キーワード例 | 参照先 |
| --- | --- | --- |
| `paygent-core` | `telegram_kind`, `PaygentB2BModule`, `send_paygent_request`, `hash_code` | `.claude/skills/paygent-core/` |
| `paygent-cc` | `paygent_cc`, `card_token`, `3dsecure`, `tds2`, `WC_Gateway_Paygent_CC` | `.claude/skills/paygent-cc/` |
| `paygent-digital` | `paygent_paypay`, `paygent_paidy`, `PayPay`, `楽天ペイ`, `Apple Pay` | `.claude/skills/paygent-digital/` |
| `paygent-bank` | `paygent_cs`, `paygent_atm`, `paygent_bn`, `コンビニ`, `仮想口座` | `.claude/skills/paygent-bank/` |
| `paygent-carrier` | `paygent_mb`, `career_type`, `キャリア決済`, `auかんたん決済`, `d払い` | `.claude/skills/paygent-carrier/` |
| `wc-block-payment` | `AbstractPaymentMethodType`, `registerPaymentMethod`, `onPaymentSetup`, `block-cs` | `.claude/skills/wc-block-payment/` |
| `paygent-sandbox-check` | `サンドボックス検証`, `実決済テスト`, `sandbox check`, マージ前検証 | `.claude/skills/paygent-sandbox-check/` |
| `paygent-sb-cc` | `/paygent-sb-cc <URL> [フェーズ]`, `サンドボックス決済試験`, `試験環境動作確認`（WEB試験環境をClaude in Chromeで検証） | `.claude/skills/paygent-sb-cc/` |
| `paygent-sb-cs` | `/paygent-sb-cs <URL> [フェーズ]`, `sb-cs`, `コンビニ決済試験`, `statusupdatesearch`（WEB試験環境＋試験環境ツールで検証） | `.claude/skills/paygent-sb-cs/` |

スキルを追加・削除・改名したら、この表と `README.md` の「Claude Code スキル」表を両方更新する（PR #43 で README だけ古いまま残った）。
ファイル名は `SKILL.md`（大文字）で `name`/`description` の frontmatter 必須。このリポジトリは `core.ignorecase=true` なので、
大文字小文字だけの改名は一時名を経由して `git mv` を2回行う。

スキルの情報は **2025docs/ の仕様書PDFが正**。コードより仕様書を優先すること。

## PDF仕様書アップデート検知

仕様書PDFが更新された際にスキルのレビューが必要かどうかを検知するワークフロー。

```bash
# PDFが更新されたかチェック（変更あり→exit 1、変更なし→exit 0）
./scripts/check-pdf-updates.sh

# スキル更新後、ハッシュを記録
./scripts/update-pdf-hashes.sh
```

PDFの内容確認には `pdftotext`（`brew install poppler`）を使用。

```bash
pdftotext "2025docs/<path>.pdf" - | less
```

## よくある作業パターン

### 新しい決済電文を実装するとき

1. 対応するスキルを確認（`/paygent-core` または決済別スキル）
2. `class-wc-gateway-paygent-request.php` の `send_paygent_request()` でリクエスト送信
3. `telegram_kind` に正しいコードを設定（必ず仕様書で確認）
4. レスポンス `result === '0'` で正常、それ以外はエラー処理

### CC与信決済の売上確定（022）を扱うとき

`order_paygent_cc_status_completed()` は注文を「完了」にした際に売上確定（022）を送るが、
**成功時（`result === '0'`）は注文メモを追加しない**。失敗時のみ `error_response()` 経由でメモが
残る非対称な挙動なので、ユーザー向け案内やデバッグでは「メモが無い＝成功」と誤読しないよう注意する
（成功可否は加盟店管理者サイトまたは `094` 照会で確認する）。

### 決済ID（payment_id）の確認方法

CC/MCCC/CS/ATM/PayPay/楽天ペイ/BN/MB は `payment_complete()` または `set_transaction_id()`
経由で payment_id を WooCommerce 注文の「Transaction ID」欄に保存する。注文メモには載らないため、
ユーザー向け手順書や調査では加盟店管理者サイトを案内する前にまずこの欄を確認する。

### 返金・取消を実装するとき

- `process_refund()` 内で `$telegram_array` を構築して `paygent_process_refund()` に渡す
- キャリア決済の取消は `career_type` に関係なく telegram_kind `102` で統一
- 後続電文（返金・売上・照会）の trading_id は必ず
  `WC_Gateway_Paygent_Request::get_paygent_trading_id( $order, $has_payment_id )` で解決する。
  接頭辞オプションからの再構築は禁止（設定変更で不一致→33002系エラー。申込時の値は `_paygent_order_id` メタが正）
- 仕様: payment_id / trading_id はどちらか一方で処理可・両方送ると双方一致必須。
  メタなしの旧注文は payment_id のみ送る（trading_id は空文字＝未設定扱い）
- 注文接頭辞（`wc-paygent-prefix_order`）は半角英字のみ（保存時に強制）。
  Webhookが trading_id の非数字除去で注文IDを逆引きするため数字入りは不可
- 094照会応答は**全フィールド文字列**。許可ステータス表など
  と比較する際は型を正規化する（int配列とのstrict比較は不一致）
- 取消・補正の可能ステータスはキャリア別に仕様書の表と一致させる。
  d払いは40(消込済)では返金不可＝44(消込完了)待ち。40→44は
  キャリア完了通知契機で翌日3:30以降（翌々日になる場合あり）

### リダイレクト型決済（MB等）の注意

- 外部認証へ出る決済は申込成功時に `$order->set_cart_hash( '' )`
  でStore API/クラシックのドラフト再利用を外す（payment_method
  上書き防止）。申込失敗でカートへ戻すときは現カートから復元する
- MBのキャンセル復帰(mb_cancel)は order_id + 注文キーの
  hash_equals 検証が必須（trading_id単独は推測可能）
- 完了時の消込(101/341等)は094で20/21を確認してからのみ送信。
  40/41/44はスキップ、121(継続課金)は125系のため094判定不可

### サブスクリプション対応を追加するとき

- `WC_Subscriptions_Order` クラスが存在する場合に `_Addon_` クラスが使われる
- CC継続課金: 更新決済は注文メタの固定値ではなく、`WC_Payment_Tokens::get_customer_default_token()`
  で取得した**顧客の現在のデフォルトWC決済トークン**の`customer_card_id`メタを使用
  （`_paygent_customer_card_id`オーダーメタは申込時の記録用）。複数カード保存時は
  デフォルトトークンの入れ替わりに注意
- キャリア継続課金: `120`（申込）→ `121`（売上）、継続課金IDは `_paygent_running_id` に保存

### Block Checkoutで保存カード（トークン）を扱うとき

- 保存カードUIはWC Blocksネイティブに任せる（`supports.showSavedCards` + `savedTokenComponent`）。フォーム内に独自トグルを作らない
- 保存トークンIDは `wc-{gateway_id}-payment-token` でPOSTされる。`onPaymentSetup` がsuccessを返すと初期paymentMethodDataは丸ごと置き換わるため、savedTokenComponent側でトークンIDを再送する
- Store APIは `process_payment()` の**前に** `validate_fields()` を呼ぶ。決済のPOSTキーを追加・変更したら `validate_fields()` の分岐も必ず更新する
- Block共有コンポーネントは `src/blocks/shared/components/` に置く

### process_payment() の戻り値・Block Checkoutでのエラー表示

- `process_payment()` の `result` は **`'success'` または `'failure'` のみ**が有効値。`'failed'` 等の
  独自文字列は WooCommerce が認識せず、Store API は `result==='failure'` の時だけ
  `wc_add_notice()` の内容をエラーレスポンスへ変換する（それ以外は通知を無言で破棄）。
  実際に MB/ATM/BN 等で `'failed'` を返しており、Block Checkoutで顧客にPaygentの実エラーが
  表示されず「Something went wrong」になるバグがあった（2026-07修正）
- Block Checkout（Store API）中かどうかの判定は `is_checkout()` だけでは不十分（Store API
  リクエストでは false）。`wc_is_store_api_request()`（WC 6.9+）を併用する
- 外部認証（3DS2等）からのリダイレクト復帰後は通常ページロードのため、Block Checkout では
  `wc_add_notice()` が顧客に届かない。復帰URLにはホワイトリスト化したエラーコードのみを付与し
  （自由文は禁止・例: `paygent_3ds2_error`）、`core/notices` へ `context:"wc/checkout"` で
  dispatch して表示する（2026-07 PR #34）

### 翻訳（i18n）を更新するとき

- 手順: `npm run make-pot` → `msgmerge` で ja.po 更新 → 翻訳 → `npm run make-json`、
  `.mo` は `msgfmt` で生成
- `i18n/*.json` は `.distignore` で配布除外（ローカル開発用）。本番翻訳は
  translate.wordpress.org の言語パックが配信するため、**リリース（タグpush）前に
  ja.po を GlotPress へインポートする**（怠ると新規文字列が英語のままになる）
- `i18n/*.mo` は配布する（共通PHPセキュリティ通知が、言語パックに無い訳を同梱 `.mo` から補うため。Issue #44）。
  `.po` と食い違わないよう、文字列の追加は wp-i18n スキルの `po-add-entry.py` か `msgfmt` で `.mo` まで更新する
- Block JS の翻訳は抽象クラスの `set_script_translations()` ヘルパーで読み込む。
  Blockクラスで新しいWP関数を呼んだら `tests/bootstrap.php` へプレーンスタブの追加が
  必要（単体テストはWPなしの Brain\Monkey 環境）
- wp-env での翻訳確認: `languages/plugins/` の言語パックが同梱 `.mo` より優先される。
  新規文字列の確認はパックの `.mo` を上書きし `.l10n.php` を削除する
