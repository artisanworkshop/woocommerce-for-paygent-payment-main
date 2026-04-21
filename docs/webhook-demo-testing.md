# Webhook テスト ガイド

## 概要

`webhook.spec.js` の Layer 2 テストは、Paygent からの入金通知が正しく WooCommerce の
注文ステータスを更新するかを検証します。

エンドポイント（`POST /wp-json/paygent/v1/check`）は **IP 許可リスト**で保護されているため、
テストリクエストが Paygent の正規 IP から来たように扱われる仕組みが必要です。

対応する実行モードは 2 種類あります。

| モード | 用途 |
|--------|------|
| **ローカル wp-env** | 日常開発・CI（Paygent サンドボックスアクセス不要） |
| **デモサイト** | `paygent.demo01web.info` の本番エンドポイント確認 |

---

## mu-plugin の仕組み

`tests/E2E/fixtures/paygent-test-ip.php` を WordPress の `mu-plugins/` に配置すると、
`paygent_permitted_ips` フィルターにリクエスト元の `REMOTE_ADDR` を**動的に追加**します。

```php
// リクエスト時の REMOTE_ADDR をそのまま許可リストに追加
$ips[] = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
```

この方式により、以下のどの環境でも追加設定なしで動作します。

| 環境 | REMOTE_ADDR の例 |
|------|-----------------|
| 通常の Docker（mac/Linux） | `172.18.0.1`（Docker ブリッジゲートウェイ） |
| Cloudflare WARP 有効 | `172.67.x.x`（Cloudflare CDN の IP） |
| CI ランナー | 任意のグローバル IP |

> **⚠ 警告**: この mu-plugin がある状態では **任意の IP** から Webhook を叩けます。
> テスト後は必ず削除してください。本番・ステージング環境には絶対に配置しないでください。

---

## ローカル wp-env でのテスト

### Step 1 — mu-plugin をインストール

```bash
npx wp-env run cli sh -c \
  "mkdir -p /var/www/html/wp-content/mu-plugins && \
   cp /var/www/html/wp-content/plugins/woocommerce-for-paygent-payment-main/tests/E2E/fixtures/paygent-test-ip.php \
      /var/www/html/wp-content/mu-plugins/paygent-test-ip.php"
```

インストール確認:

```bash
npx wp-env run cli ls /var/www/html/wp-content/mu-plugins/
# paygent-test-ip.php が表示されればOK
```

### Step 2 — テストを実行

```bash
E2E_WEBHOOK_FROM_ALLOWED_IP=true npx playwright test tests/E2E/webhook.spec.js --project=e2e
```

期待される結果:

```
✓  GET returns 404 or 405 — only POST is registered
-  POST from non-Paygent IP is rejected with 401          ← mu-plugin インストール中はスキップ
-  POST with JSON body from non-Paygent IP is rejected    ← mu-plugin インストール中はスキップ
✓  Endpoint is registered — non-404 response confirms route exists
✓  CS payment notification (payment_status=40) updates order to processing
✓  CS payment notification (payment_status=12) cancels order
✓  Unknown trading_id still returns result=0 (webhook always ACKs)
```

> Layer 1 の「IP 拒否」テストは、mu-plugin がインストールされている間は
> 自動的にスキップされます（mu-plugin が全 IP を通過させるため）。

### Step 3 — mu-plugin を削除

```bash
npx wp-env run cli rm /var/www/html/wp-content/mu-plugins/paygent-test-ip.php
```

> **wp-env 再起動後の注意**: `npx wp-env start` で再起動すると `mu-plugins/` は
> リセットされます。再テスト時は Step 1 から繰り返してください。

---

## デモサイト（`paygent.demo01web.info`）でのテスト

### 前提条件

- Xserver ホスティングへの SSH アクセス
- リモートサーバーに WP-CLI がインストールされていること（`~/bin/wp` またはパス上）

### Step 1 — mu-plugin をアップロード

mu-plugin は `REMOTE_ADDR` を動的に許可リストへ追加するため、
**ファイルを編集せずそのままアップロード**できます。

`scp` を使う場合:

```bash
scp tests/E2E/fixtures/paygent-test-ip.php \
    <user>@<xserver-host>:~/public_html/wp-content/mu-plugins/
```

`ssh` + 標準入力を使う場合:

```bash
ssh <user>@<xserver-host> \
  "cat > ~/public_html/wp-content/mu-plugins/paygent-test-ip.php" \
  < tests/E2E/fixtures/paygent-test-ip.php
```

アップロード確認:

```bash
ssh <user>@<xserver-host> \
  "ls ~/public_html/wp-content/mu-plugins/"
```

### Step 2 — 環境変数を設定

```bash
export E2E_BASE_URL="https://paygent.demo01web.info/"
export E2E_WEBHOOK_FROM_ALLOWED_IP="true"
export E2E_DEMO_SSH="<user>@<xserver-host>"
export E2E_DEMO_WP_PATH="/home/<user>/public_html"
```

### Step 3 — テストを実行

```bash
npx playwright test tests/E2E/webhook.spec.js --project=e2e
```

### Step 4 — mu-plugin を削除（必須）

```bash
ssh <user>@<xserver-host> \
  "rm ~/public_html/wp-content/mu-plugins/paygent-test-ip.php"
```

削除確認:

```bash
ssh <user>@<xserver-host> \
  "ls ~/public_html/wp-content/mu-plugins/ | grep paygent-test-ip"
# 何も出力されなければOK
```

---

## Paygent サンドボックスから実通知でテストする（オプション）

mu-plugin を使わずに、Paygent サンドボックス管理コンソールから実際の通知を
トリガーする方法です。通知は `202.232.189.65`（デフォルト許可リスト済み）から届くため、
IP ホワイトリスト操作が不要です。

1. デモサイトに pending 状態の CS 注文を作成する
2. Paygent サンドボックス管理コンソールにログイン
3. 対象注文の `trading_id` に対して入金通知をトリガー
4. 通知が `202.232.189.65` から届き、注文ステータスが `processing` に変わることを確認

最も実態に近い E2E テストですが、Paygent サンドボックス管理コンソールへの手動操作が必要です。

---

## Paygent IP 許可リスト

`class-wc-paygent-endpoint.php` に定義されているデフォルト許可 IP:

| 環境 | IP アドレス |
|------|------------|
| 本番 | `27.110.52.4` |
| サンドボックス | `202.232.189.65` |

追加 IP は `paygent_permitted_ips` WordPress フィルターで設定できます。

```php
add_filter('paygent_permitted_ips', function(array $ips): array {
    $ips[] = '追加したいIPアドレス';
    return $ips;
});
```
