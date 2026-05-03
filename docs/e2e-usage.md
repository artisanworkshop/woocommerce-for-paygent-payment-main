# E2E テスト 使い方ガイド

## 前提条件

| ツール | バージョン | 確認コマンド |
|--------|-----------|-------------|
| Node.js | 20 以上 | `node -v` |
| npm | 9 以上 | `npm -v` |
| Docker Desktop | 最新 | Docker Desktop を起動しておく |
| PHP | 8.1 以上 | `php -v` |
| Composer | 2 以上 | `composer -V` |

---

## 初回セットアップ

```bash
# 1. 依存パッケージをインストール
npm ci
composer install

# 2. Playwright ブラウザをインストール（Chromium のみ）
npx playwright install --with-deps chromium

# 3. wp-env を起動（Docker イメージのダウンロードに数分かかる）
npx wp-env start
```

> `npx wp-env start` 後、WordPress は `http://localhost:8888` で、
> phpMyAdmin は `http://localhost:9001` でアクセスできます。

---

## テスト種別と実行コマンド

### 1. Unit テスト（PHPUnit）

WordPress 不要。最も速い。

```bash
# 全 Unit テスト
composer test:unit

# PHP バージョンを指定して実行（ローカルに複数の PHP がある場合）
php8.3 vendor/bin/phpunit --testsuite unit
```

### 2. Integration テスト（PHPUnit + wp-env）

wp-env が起動していることが前提。

```bash
# wp-env 経由で実行（推奨）
composer test:integration:wp-env

# または直接実行（ローカル WordPress 環境が設定済みの場合）
composer test:integration
```

### 3. E2E Functional テスト（Playwright）

wp-env が起動していることが前提。実際の Paygent API 通信は行わない。

```bash
# 全 Functional テスト（smoke + checkout + admin-order + admin-refund + webhook）
npx playwright test \
  tests/E2E/smoke.spec.js \
  tests/E2E/checkout.spec.js \
  tests/E2E/admin-order.spec.js \
  tests/E2E/admin-refund.spec.js \
  tests/E2E/webhook.spec.js \
  --project=e2e

# ファイルを個別に指定
npx playwright test tests/E2E/smoke.spec.js --project=e2e

# ブラウザを表示しながら実行
npx playwright test tests/E2E/checkout.spec.js --project=e2e --headed

# ステップ実行（デバッグ）
npx playwright test tests/E2E/checkout.spec.js --project=e2e --debug
```

### 4. Webhook シミュレーションテスト（Layer 2）

IP 許可 mu-plugin のインストールが必要。

**Step 1 — mu-plugin をインストール**

```bash
npx wp-env run cli sh -c \
  "mkdir -p /var/www/html/wp-content/mu-plugins && \
   cp /var/www/html/wp-content/plugins/woocommerce-for-paygent-payment-main/tests/E2E/fixtures/paygent-test-ip.php \
      /var/www/html/wp-content/mu-plugins/paygent-test-ip.php"
```

**Step 2 — テスト実行**

```bash
E2E_WEBHOOK_FROM_ALLOWED_IP=true npx playwright test tests/E2E/webhook.spec.js --project=e2e
```

**Step 3 — テスト後に mu-plugin を削除**

```bash
npx wp-env run cli rm /var/www/html/wp-content/mu-plugins/paygent-test-ip.php
```

> **注意**: mu-plugin がインストールされた状態では任意の IP から Webhook を叩けます。
> テスト後は必ず削除してください。

### 5. E2E Sandbox テスト（実 Paygent API）

Paygent サンドボックスへの IP 登録と認証情報が必要。

```bash
# 環境変数を設定してから実行
export PAYGENT_TEST_MID=xxx
export PAYGENT_TEST_CID=yyy
export PAYGENT_TEST_CPASS=zzz
export PAYGENT_TEST_TOKENKEY=aaa

# ゲストチェックアウトテスト（グループ A–D）
npx playwright test tests/E2E/checkout-sandbox.guest.spec.js --project=e2e-guest

# 会員チェックアウトテスト（グループ E–F）
npx playwright test tests/E2E/checkout-sandbox.member.spec.js --project=e2e-member
```

> **IP 登録について**: Paygent サンドボックスは接続元 IP を事前登録する必要があります。
> 動的 IP の環境では実行できません。静的 IP の環境か、GitHub Actions の
> self-hosted runner を使用してください。

---

## リリース前チェックリスト

```bash
# 1. Unit テスト（全 PHP バージョン）
composer test:unit

# 2. Integration テスト
composer test:integration:wp-env

# 3. PHPCS（コーディング標準）
composer phpcs

# 4. E2E Functional テスト
npx playwright test \
  tests/E2E/smoke.spec.js \
  tests/E2E/checkout.spec.js \
  tests/E2E/admin-order.spec.js \
  tests/E2E/webhook.spec.js \
  --project=e2e

# 5. Webhook シミュレーション
# （mu-plugin インストール後）
E2E_WEBHOOK_FROM_ALLOWED_IP=true \
  npx playwright test tests/E2E/webhook.spec.js --project=e2e
# （mu-plugin 削除）

# 6. Sandbox テスト（IP 登録済み環境のみ）
# GitHub Actions の e2e-sandbox.yml を手動トリガー
```

---

## テスト結果の確認

### HTML レポートを開く

```bash
npx playwright show-report
```

### 失敗時のスクリーンショット・トレースを確認

```bash
# トレースビューアで開く
npx playwright show-trace test-results/<テスト名>/trace.zip
```

---

## wp-env 操作

```bash
# 起動
npx wp-env start

# 停止
npx wp-env stop

# 再起動（コンテナをリセット）
npx wp-env clean all && npx wp-env start

# WP-CLI コマンドを実行
npx wp-env run cli wp <コマンド>

# WordPress のログを確認
npx wp-env run cli wp eval "echo file_get_contents(WP_CONTENT_DIR . '/debug.log');" | tail -50
```

> **wp-env 再起動後の注意**: mu-plugins ディレクトリはリセットされます。
> Webhook シミュレーションテストを再実行する場合は mu-plugin を再インストールしてください。

---

## よくあるトラブル

### `wp wc order create` が "not a registered subcommand" になる

WooCommerce 10.x では `wp wc order` は未登録です。`wp wc shop_order` を使ってください。

```bash
# NG
wp wc order create ...

# OK
wp wc shop_order create ...
```

### Webhook テストで全て 401 になる

mu-plugin がインストールされていないか、wp-env 再起動後にリセットされています。

```bash
# mu-plugin の存在確認
npx wp-env run cli ls /var/www/html/wp-content/mu-plugins/

# 再インストール
npx wp-env run cli sh -c \
  "mkdir -p /var/www/html/wp-content/mu-plugins && \
   cp /var/www/html/wp-content/plugins/woocommerce-for-paygent-payment-main/tests/E2E/fixtures/paygent-test-ip.php \
      /var/www/html/wp-content/mu-plugins/paygent-test-ip.php"
```

### Webhook テストで IP 拒否テスト（Layer 1）が 200 になる

mu-plugin がインストールされた状態では IP 拒否テストは成立しません。
`E2E_WEBHOOK_FROM_ALLOWED_IP=true` 時は自動的にスキップされます。
IP 拒否テストを確認したい場合は mu-plugin を削除してから実行してください。

### `createTestOrder` で `Failed to create test order (got: "")` になる

WP-CLI が失敗しています。以下を確認してください。

```bash
# WooCommerce が有効か確認
npx wp-env run cli wp plugin list | grep woocommerce

# 手動で注文作成を試みる
npx wp-env run cli wp wc shop_order create --status=pending --payment_method=paygent_cs --porcelain --user=1 2>&1
```

### グローバルセットアップが失敗する

```bash
# wp-env が起動しているか確認
npx wp-env status

# 起動していない場合
npx wp-env start

# WordPress が応答するか確認
curl -I http://localhost:8888/wp-admin/
```

### Playwright ブラウザが見つからない

```bash
npx playwright install --with-deps chromium
```

---

## デモサイトでのテスト

デモサイト（`paygent.demo01web.info`）を対象にテストする場合は
[webhook-demo-testing.md](./webhook-demo-testing.md) を参照してください。

---

## CI での実行（GitHub Actions）

| ワークフロー | トリガー | 内容 |
|-------------|---------|------|
| `tests.yml` | push / PR 自動 | Unit + Integration + PHPCS + E2E Functional |
| `e2e-sandbox.yml` | 手動（workflow_dispatch） | E2E Sandbox（要 IP 登録・要 Secrets 設定） |

GitHub Actions で Sandbox テストを手動実行する場合:
1. Actions タブ → `E2E Sandbox Tests` を選択
2. `Run workflow` → テストスイートを選択（all / guest / member）
3. Secrets が設定済みであることを確認して実行

必要な Secrets（リポジトリ設定 → Secrets and variables → Actions）:

| Secret 名 | 説明 |
|-----------|------|
| `PAYGENT_TEST_MID` | Paygent サンドボックス マーチャント ID |
| `PAYGENT_TEST_CID` | Paygent サンドボックス コネクト ID |
| `PAYGENT_TEST_CPASS` | Paygent サンドボックス コネクトパスワード |
| `PAYGENT_TEST_TOKENKEY` | Paygent トークン生成キー |
