# E2E テスト 開発ドキュメント

## 概要

本ドキュメントはテストの設計・構成・実装上の判断を記録する開発者向けリファレンスです。
テストの実行手順は [e2e-usage.md](./e2e-usage.md) を参照してください。

---

## テストピラミッド

```
          ┌──────────────────────────────┐
          │   E2E Sandbox (Playwright)   │  実 Paygent API + ブラウザ操作
          │  checkout-sandbox.*.spec.js  │  手動実行のみ（IP 登録必須）
          ├──────────────────────────────┤
          │   E2E Functional (Playwright)│  wp-env + ブラウザ操作
          │  smoke / checkout / webhook  │  push/PR ごとに CI 自動実行
          ├──────────────────────────────┤
          │   Integration (PHPUnit)      │  wp-env + WordPress/WooCommerce
          │   tests/Integration/         │  push/PR ごとに CI 自動実行
          ├──────────────────────────────┤
          │   Unit (PHPUnit)             │  PHP ロジック単体・依存なし
          │   tests/Unit/               │  push/PR ごとに CI 自動実行
          └──────────────────────────────┘
```

---

## ディレクトリ構成

```
tests/
├── bootstrap.php                   Unit テスト用 PHPUnit ブートストラップ
├── Unit/                           ユニットテスト（WordPress 不要）
│   ├── TestCase.php                共通 setUp (Brain\Monkey 初期化)
│   ├── HashCalculationTest.php     SHA-256 ハッシュ計算ロジック
│   ├── StatusTransitionTest.php    Webhook ステータス遷移ガード
│   ├── WebhookIpCheckTest.php      IP 許可リストフィルター
│   ├── WebhookStatusMappingTest.php payment_status → WC ステータスマッピング
│   ├── CcWebhookTest.php           CC Webhook ハンドラ
│   ├── DigitalPaymentWebhookTest.php PayPay/Paidy/楽天ペイ Webhook
│   ├── MbSingleOrderWebhookTest.php キャリア決済 Webhook
│   ├── AmountCorrectionTest.php    金額修正ロジック
│   ├── CardSaveLogicTest.php       カード保存・fingerprint ロジック
│   └── HposComplianceTest.php      HPOS 準拠チェック（post_meta 使用禁止）
│
├── Integration/                    統合テスト（wp-env + WordPress/WooCommerce 必須）
│   ├── bootstrap.php               WordPress + WooCommerce 読み込み
│   ├── TestCase.php                共通 setUp
│   ├── WebhookEndpointTest.php     REST ルート登録・IP 拒否の確認
│   ├── HposOrderMetaTest.php       HPOS 注文メタ CRUD
│   ├── SubscriptionHookTest.php    サブスクリプションフック
│   └── Sandbox/
│       ├── SandboxApiTestCase.php  Paygent API 共通基底クラス
│       ├── CcSandboxApiTest.php    クレジットカード実 API テスト
│       └── CsSandboxApiTest.php    コンビニ決済実 API テスト
│
└── E2E/                            E2E テスト（Playwright）
    ├── setup/
    │   └── global.setup.js         全テスト共通の前処理（認証・商品作成など）
    ├── helpers/
    │   └── wp-cli.js               WP-CLI ラッパーと共通ユーティリティ
    ├── fixtures/
    │   ├── index.js                Playwright フィクスチャ定義
    │   └── paygent-test-ip.php     Webhook IP 許可テスト用 mu-plugin
    ├── pages/                      Page Object クラス（未実装・予約）
    ├── .auth/                      認証セッション保存先（gitignore）
    │   ├── admin.json              管理者セッション
    │   └── member.json             会員セッション
    ├── smoke.spec.js               環境稼働確認
    ├── checkout.spec.js            チェックアウト UI（モック決済）
    ├── admin-order.spec.js         管理画面・注文管理
    ├── webhook.spec.js             Webhook エンドポイント
    ├── checkout-sandbox.guest.spec.js  実 API サンドボックス（ゲスト）
    └── checkout-sandbox.member.spec.js 実 API サンドボックス（会員）
```

---

## 環境変数リファレンス

| 変数名 | 必須 | デフォルト | 説明 |
|--------|------|-----------|------|
| `E2E_BASE_URL` | - | `http://localhost:8888` | テスト対象の WordPress URL |
| `E2E_WEBHOOK_FROM_ALLOWED_IP` | - | `false` | `true` にすると Webhook シミュレーション (Layer 2) が有効になる |
| `E2E_DEMO_SSH` | デモサイト時 | - | SSH 接続先（例: `user@example.com`） |
| `E2E_DEMO_WP_PATH` | デモサイト時 | - | リモートの WordPress ルートパス |
| `PAYGENT_TEST_MID` | サンドボックス時 | - | Paygent サンドボックス マーチャント ID |
| `PAYGENT_TEST_CID` | サンドボックス時 | - | Paygent サンドボックス コネクト ID |
| `PAYGENT_TEST_CPASS` | サンドボックス時 | - | Paygent サンドボックス コネクトパスワード |
| `PAYGENT_TEST_TOKENKEY` | サンドボックス時 | - | Paygent トークン生成キー |

---

## Global Setup（`tests/E2E/setup/global.setup.js`）

全 E2E テスト実行前に一度だけ走る前処理。以下を順番に実行する。

1. **WooCommerce オプション設定** — 通貨（JPY）、税設定、ゲストチェックアウト有効化など
2. **CA バンドルコピー** — Paygent B2B モジュールが参照する SSL 証明書を wp-env コンテナ内に配置
   - パス: `/var/www/html/wp-content/uploads/wc-paygent/curl-ca-bundle.crt`
   - コンテナ内に存在しない場合、`/etc/ssl/certs/ca-certificates.crt` からコピー
3. **テスト商品作成** — スラッグ `paygent-e2e-test-product` の商品が存在しない場合のみ作成
4. **管理者認証** — `admin` / `password` でログインしてセッションを `.auth/admin.json` に保存
5. **初回リダイレクト回避** — Paidy オンボーディング画面など初回管理者ページ遷移時のリダイレクトを消化
6. **会員ユーザー作成** — `paygent-e2e-member` ユーザーが存在しない場合のみ作成
7. **会員認証** — 会員セッションを `.auth/member.json` に保存

---

## E2E テストファイルの概要

### `smoke.spec.js`
環境が正常に稼働しているかの最小確認。

| テスト | 内容 |
|--------|------|
| wp-admin アクセス確認 | 管理者ログイン済みで `/wp-admin/` が開けるか |
| WooCommerce 有効確認 | WC 設定ページが表示されるか |
| Paygent ゲートウェイ登録確認 | 支払い設定に Paygent CC が表示されるか |
| テスト商品存在確認 | ショップページに商品が表示されるか |
| チェックアウトページアクセス確認 | カートに商品を入れてチェックアウトページが 200 を返すか |

### `checkout.spec.js`
実際の API 通信は行わず、チェックアウトページの UI のみ検証。

- Paygent Token JS を Playwright で**インターセプト**して `test-token` を返すスタブに差し替え（外部 JS の読み込みタイムアウト防止）
- カード番号フォームの表示・バリデーション UI の確認

### `admin-order.spec.js`
WP-CLI で注文を作成し、管理画面の HPOS 注文一覧・詳細表示を確認。

- `wp wc shop_order create` で注文を作成
- 注文ステータス変更（キャンセル）の UI 操作

### `webhook.spec.js`
`POST /wp-json/paygent/v1/check` エンドポイントの 2 層テスト。詳細は後述。

### `checkout-sandbox.guest.spec.js`
実 Paygent サンドボックス API を使ったゲストチェックアウト全フロー。

| グループ | 内容 |
|----------|------|
| A | 3DS 無効 + 通常カード ¥1,000 → オーソリ OK |
| B | 3DS2 有効 + Frictionless フロー (cardholder=BAVYA) |
| C | 3DS2 有効 + Challenge フロー (¥0、パスワード: 14012) |
| D | 部分返金（3DS 無効の完了注文から） |

### `checkout-sandbox.member.spec.js`
実 Paygent サンドボックス API を使った会員チェックアウト全フロー。

| グループ | 内容 |
|----------|------|
| E | カード保存 → 次回は保存済みカードで決済 |
| F | 金額修正（オーソリ後に金額変更） |

---

## Webhook テスト設計（`webhook.spec.js`）

### 2 層構造の理由

Webhook テストは 2 つの異なる目的を持つため、実行条件で分離している。

```
Layer 1 — セキュリティ・登録確認（常時実行）
  → mu-plugin 不要、外部サービス不要、CI で毎回動く

Layer 2 — 実際の通知シミュレーション（E2E_WEBHOOK_FROM_ALLOWED_IP=true 時のみ）
  → mu-plugin が必要、注文作成・ステータス確認が必要
```

Layer 1 と Layer 2 を同時に実行できない理由: mu-plugin が **全 IP を許可**するため、
「IP 拒否」を確認する Layer 1 の一部テストが成立しなくなる。
そのため `E2E_WEBHOOK_FROM_ALLOWED_IP=true` のとき Layer 1 の IP 拒否テストを
`test.skip` でスキップするよう実装している。

### Webhook シミュレーションの仕組み

```
Playwright (ホスト)
   │  POST /wp-json/paygent/v1/check
   ▼
localhost:8888 → Docker ポートマッピング
   ▼
WordPress コンテナ（permission_callback）
   │  apply_filters('paygent_permitted_ips', [...])
   ▼
mu-plugin: REMOTE_ADDR を許可リストに動的追加
   │  → IP チェック通過
   ▼
paygent_check_webhook() → 注文ステータス更新
```

### WP-CLI による注文管理（HPOS 対応）

WooCommerce HPOS（High Performance Order Storage）有効時、注文データは
`wp_orders` テーブルに保存されており、`wp post create/meta` コマンドは
オーダーに使用できない。すべての注文操作は WC ネイティブのコマンドを使用する。

| 操作 | コマンド |
|------|---------|
| 注文作成 | `wp wc shop_order create --status=pending --payment_method=paygent_cs --porcelain --user=1` |
| メタ保存 | `wp eval "wc_get_order(ID)->update_meta_data('key','val'); wc_get_order(ID)->save();"` |
| ステータス取得 | `wp eval "echo wc_get_order(ID)->get_status();"` |
| 注文削除 | `wp wc shop_order delete ID --force=true --user=1` |

> **注意**: `wp wc order create` は WooCommerce 10.x では未登録。`wp wc shop_order create` を使う。

### Deprecated 警告のパース

wp-env の PHP 環境では `WP_DEBUG=true` のため、`wp eval` や `wp wc shop_order` の
stdout に PHP Deprecated 警告が混入する場合がある。

```
Deprecated: Creation of dynamic property WC_Gateway_Paygent_CC::$testmode ...
134
```

`extractId()` 関数で末尾の数字を、`getOrderStatus()` で末尾の非空行を取り出すことで対応。

### Cloudflare WARP 問題と解決策

ホストマシンに Cloudflare WARP が有効の場合、`localhost:8888` へのリクエストでも
`REMOTE_ADDR` が `172.67.x.x`（Cloudflare CDN IP）になる。

**試みた対策:**
- `127.0.0.1` をハードコード → WARP 環境では不一致
- Docker gateway IP（`172.18.0.1`）を検出 → WARP 環境では不一致

**採用した解決策:**  
`paygent-test-ip.php` mu-plugin でリクエスト時の `REMOTE_ADDR` をそのまま
許可リストに追加する。これにより WARP、Docker、CI など任意のネットワーク環境で動作する。

```php
add_filter('paygent_permitted_ips', static function(array $ips): array {
    if (!empty($_SERVER['REMOTE_ADDR'])) {
        $ips[] = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
    }
    return $ips;
});
```

> **警告**: このファイルは任意の IP からの Webhook を許可する。本番・ステージング環境に残さないこと。

---

## mu-plugin（`tests/E2E/fixtures/paygent-test-ip.php`）

### 役割
`paygent_permitted_ips` フィルターにリクエスト元 IP を動的追加し、
テスト環境で Webhook エンドポイントの IP チェックを通過できるようにする。

### インストール方法
```bash
npx wp-env run cli sh -c \
  "mkdir -p /var/www/html/wp-content/mu-plugins && \
   cp /var/www/html/wp-content/plugins/woocommerce-for-paygent-payment-main/tests/E2E/fixtures/paygent-test-ip.php \
      /var/www/html/wp-content/mu-plugins/paygent-test-ip.php"
```

### アンインストール方法
```bash
npx wp-env run cli rm /var/www/html/wp-content/mu-plugins/paygent-test-ip.php
```

### wp-env 再起動後の注意
`npx wp-env start` でコンテナを再起動すると `mu-plugins/` の内容が**リセットされる**。
テスト再実行時は mu-plugin の再インストールが必要。

---

## CI ワークフロー（`.github/workflows/`）

### `tests.yml` — 自動実行（push / PR）

| ジョブ | 内容 |
|--------|------|
| `unit-tests` | PHPUnit Unit テスト（PHP 8.1 / 8.2 / 8.3 マトリクス） |
| `integration-tests` | PHPUnit Integration テスト（wp-env、PHP 8.3） |
| `phpcs` | WordPress Coding Standards チェック（non-blocking） |
| `e2e-functional` | Playwright E2E テスト（smoke / checkout / admin-order / webhook） |

### `e2e-sandbox.yml` — 手動実行（workflow_dispatch）

Paygent サンドボックスへの実 API 通信テスト。動的 IP の GitHub ホストランナーでは
IP 登録ができないため手動トリガー専用。

**必要な GitHub Secrets:**
- `PAYGENT_TEST_MID` / `PAYGENT_TEST_CID` / `PAYGENT_TEST_CPASS` / `PAYGENT_TEST_TOKENKEY`

**実行オプション:**
- `all`: guest + member 両方
- `guest`: グループ A–D（標準決済・3DS2・返金）
- `member`: グループ E–F（カード保存・金額修正）

**`cancel-in-progress: false` の理由:**  
途中キャンセルするとサンドボックス上に未完了注文が残るため。

---

## `.wp-env.json` 設定

```json
{
  "plugins": [
    "woocommerce", "wp-mail-logging", "debug-bar",
    "woocommerce-for-japan", "."
  ],
  "config": {
    "WP_DEBUG": true,
    "WP_DEBUG_LOG": true,
    "WP_DEBUG_DISPLAY": false
  }
}
```

`WP_DEBUG_DISPLAY: false` にしているため PHP エラーは画面に表示されず、
`wp-content/debug.log` に書き込まれる。

---

## テスト用アカウント情報

| アカウント | ユーザー名 | パスワード | 用途 |
|----------|-----------|-----------|------|
| 管理者 | `admin` | `password` | E2E 管理者テスト全般 |
| 会員 | `paygent-e2e-member` | `member-e2e-pass-1` | カード保存・会員チェックアウト |

> global.setup.js がない場合（wp-env 初回起動直後）は自動作成される。

---

## 既知の制限・注意事項

| 制限 | 内容 |
|------|------|
| `wp wc order` 未対応 | WooCommerce 10.x では `wp wc shop_order` を使う |
| `--total` パラメータ未対応 | `wp wc shop_order create` は `--total` を受け付けない。金額設定が必要な場合は `wp eval` で `wc_get_order()->set_total()` を使う |
| mu-plugin は起動ごとにリセット | `wp-env start` 後は毎回 mu-plugin を再インストールする |
| サンドボックステストは IP 登録必須 | 動的 IP の CI ランナーでは実行不可。静的 IP の self-hosted runner を使う |
| Playwright の `baseURL` は外部 URL 不可 | デモサイトを対象にする場合は `E2E_BASE_URL` を設定し、SSH + WP-CLI で注文管理する |
