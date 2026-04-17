# PAYGENT for WooCommerce — Claude Code ガイド

## プロジェクト概要

Paygent決済をWooCommerceに統合するWordPressプラグイン（v2.4.8）。
日本国内の主要決済手段（クレジットカード、コンビニ、キャリア決済、QRコード等）をサポート。

## 技術スタック・要件

| 項目 | バージョン |
| --- | --- |
| PHP | >= 7.4（推奨 8.2+） |
| WordPress | >= 5.0（推奨 6.7+） |
| WooCommerce | >= 8.0.0（推奨 9.0+） |
| WooCommerce Subscriptions | 継続課金使用時のみ必須 |

## ファイル構成

```
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
│       └── class-wc-gateway-paygent-request.php ← コアAPIクライアント
│
├── vendor-wc/paygent/connect/src/paygent_module/
│   └── System/PaygentB2BModule.php            ← Paygent公式通信モジュール
│
├── 2025docs/                                  ← Paygent公式仕様書PDF（要確認）
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
| `022` | CC | 売上（キャプチャ） |
| `023` | CC | オーソリ取消 |
| `028` | CC | 売上取消 |
| `030` | コンビニ | 申込 |
| `010` | 仮想口座 | 申込 |
| `040` | 銀行ネット | 申込 |
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

SHA-256による改ざん検知。`order_id + telegram_kind + amount + hash_code` を連結してハッシュ化。

### Webhook

`POST /wp-json/paygent/v1/check` — コンビニ・仮想口座の入金通知を受信。
`WC_Paygent_Endpoint::paygent_check_webhook()` でステータス更新。

### HPOS対応

WooCommerce High Performance Order Storage（HPOS）完全対応済み。
`$order->get_meta()` / `$order->update_meta_data()` を使用し、`get_post_meta()` は使わない。

## WordPress コーディング標準

- **入力**: `sanitize_text_field()` / `absint()` / `wp_unslash()` 等でサニタイズ
- **出力**: `esc_html()` / `esc_attr()` / `wp_kses_post()` でエスケープ
- **nonce**: フォーム送信は必ず nonce 検証
- `phpcs` / `phpstan` が設定されている場合はコミット前に実行

## Claude Code スキル

`.claude/skills/` に5つのスキルが定義されています。関連するキーワードやファイルを編集する際に自動的に発動します。

| スキル | 発動キーワード例 | 参照先 |
| --- | --- | --- |
| `paygent-core` | `telegram_kind`, `PaygentB2BModule`, `send_paygent_request`, `hash_code` | `.claude/skills/paygent-core/` |
| `paygent-cc` | `paygent_cc`, `card_token`, `3dsecure`, `tds2`, `WC_Gateway_Paygent_CC` | `.claude/skills/paygent-cc/` |
| `paygent-digital` | `paygent_paypay`, `paygent_paidy`, `PayPay`, `楽天ペイ`, `Apple Pay` | `.claude/skills/paygent-digital/` |
| `paygent-bank` | `paygent_cs`, `paygent_atm`, `paygent_bn`, `コンビニ`, `仮想口座` | `.claude/skills/paygent-bank/` |
| `paygent-carrier` | `paygent_mb`, `career_type`, `キャリア決済`, `auかんたん決済`, `d払い` | `.claude/skills/paygent-carrier/` |

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

### 返金・取消を実装するとき

- `process_refund()` 内で `$telegram_array` を構築して `paygent_process_refund()` に渡す
- キャリア決済の取消は `career_type` に関係なく telegram_kind `102` で統一

### サブスクリプション対応を追加するとき

- `WC_Subscriptions_Order` クラスが存在する場合に `_Addon_` クラスが使われる
- CC継続課金: `fingerprint`（カード識別子）をオーダーメタに保存して再利用
- キャリア継続課金: `120`（申込）→ `121`（売上）、継続課金IDは `_paygent_running_id` に保存
