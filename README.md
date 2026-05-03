# PAYGENT for WooCommerce

PAYGENT Payment Gateway plugin provides all popular online payment methods for your WooCommerce webshop in Japan.

## Description

PAYGENTと契約することで、日本の主要な決済方法をWooCommerceに導入できるプラグインです。

## Supported Payment Methods

| # | Payment Method | Subscriptions |
| --- | --- | :---: |
| 1 | Credit Card Payment (VISA, MASTER, AMEX, Diners, JCB) | Yes |
| 2 | Convenience Store Payment (Seven Eleven, Lawson, Ministop, FamilyMart) | - |
| 3 | Multi Currency Credit Card Payment (23 currencies) | - |
| 4 | Bank Net Payment | - |
| 5 | ATM Payment | - |
| 6 | Carrier Payment (docomo, SoftBank, au) | Yes |
| 7 | Paidy Payment | - |
| 8 | PayPay Payment | - |
| 9 | Rakuten Payment | - |

## Requirements

| Requirement | Version |
| --- | --- |
| PHP | >= 7.4 |
| WordPress | >= 5.0 |
| WooCommerce | >= 8.0.0 |

## Features

- 3D Secure 2.0 authentication for credit card payments
- WooCommerce Subscriptions support (Credit Card / Carrier Payment)
- High-Performance Order Storage (HPOS) compatible
- Multi-currency support (23 currencies including USD, EUR, GBP, KRW, CNY, etc.)
- Webhook endpoint for payment status notifications
- Production / Test / Sandbox environment switching
- Card expiry notification

## Installation

1. Upload the plugin files to the `/wp-content/plugins/woocommerce-for-paygent-payment-main` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to WooCommerce > Paygent Setting to configure the plugin.

**Note:** A contract with PAYGENT is required to use this plugin. If you want to check the display, a demo environment is available. [Please apply from here.](https://wc.artws.info/payment-demo-apply/)

## FAQ

**Q: Do I need anything to use this plugin?**
A: Testing in a test environment requires a contract with Paygent. If you want to check the display, we provide a [demo environment](https://wc.artws.info/payment-demo-apply/).

**Q: I don't know how to set up. Can you support me?**
A: We provide [paid support](https://wc.artws.info/product/payment-support/).

## Screenshots

1. General setting
2. Environmental setting
3. Paygent Payment setting
4. Credit Card setting
5. Convenience Store Payment setting

## Upgrade Notice

Please back up your database and plugin files before updating. If you do a major update, be sure to test it on a staging site before applying it to your production environment.

## Changelog

### 2.4.8 - 2026-02-05

- Fixed - Refactor next payment date calculation for subscriptions to use a dedicated method.
- Fixed - Handle expired authorization status in Paygent webhook response.
- Fixed - Add allowed redirect hosts for Paygent payment gateway and improve IP address permission checks.
- Fixed - Enhance error handling by including detailed response information in order notes.

### 2.4.7 - 2026-01-06

- Security - Improved IP address acquisition reliability by prioritizing REMOTE_ADDR to prevent IP spoofing.

### 2.4.6 - 2026-01-05

- Fixed - Implement countermeasures for double display in 3D Secure redirection.
- Fixed - Fix 3D Secure handling and prevent duplicate actions in Paygent payment gateway.

### 2.4.5 - 2026-01-05

- Fixed - Fixed descriptions display.
- Fixed - Mobile Payment Subscriptions HPOS admin screen bugs.
- Fixed - Fixed Paygent endpoint.

### 2.4.4 - 2025-12-18

- Add - Add wordfence-vendor.txt for Security verification.
- Fixed - Convenience Store Payment's Lawson and Ministop text.
- Fixed - Function _load_textdomain_just_in_time bug.
- Fixed - Paidy deprecation bug.
- Fixed - Multi Currency Credit Card Payment bugs.

### 2.4.3 - 2025-12-18

- Fixed - Minor bug fixes.

### 2.4.2 - 2025-08-06

- Fixed - 3DS 2.0 Credit Card bugs.

### 2.4.1 - 2025-07-30

- Fixed - Update crt file and pem file bugs.

### 2.4.0 - 2025-07-16

- Fixed - Compliant with WordPress PHP coding standards.
- Fixed - Registering a credit card when there is no purchase history.
- Fixed - Endpoint bug fixed.
- Dev - Preparation for checkout & cart block support for the upcoming major version (3.0).

## Development with Claude Code

このプロジェクトはClaude Codeによる開発をサポートしています。決済仕様書（`2025docs/`）を正とするスキルファイルと、PDFアップデート検知ワークフローを整備しています。

### Claude Code スキル

`.claude/skills/` に5つのスキルが定義されており、Paygent決済の実装・修正時に自動的に発動します。

| スキル名 | 対象決済 |
| --- | --- |
| `paygent-core` | 共通API通信、ハッシュチェック、認証、エラーハンドリング |
| `paygent-cc` | クレジットカード（トークン決済、3DS2、継続課金、多通貨） |
| `paygent-digital` | PayPay、Paidy、楽天ペイ、Apple Pay、Google Pay、Alipay、銀聯 |
| `paygent-bank` | コンビニ決済、仮想口座（ATM）、銀行ネット、口座振替 |
| `paygent-carrier` | キャリア決済（auかんたん決済、d払い、ソフトバンク）都度課金・継続課金 |

各スキルの詳細は `.claude/skills/<skill-name>/SKILL.md` を参照してください。

### PDF仕様書アップデート検知ワークフロー

`2025docs/` 配下のPaygent公式仕様書PDFが更新された際に、対応するスキルのレビューが必要かどうかを検知するスクリプトを用意しています。

**前提条件**: `pdftotext` コマンドが必要です（`brew install poppler`）。

#### 基本的な使い方

```bash
# 1. PDFが更新されたかチェック
./scripts/check-pdf-updates.sh

# 2. 変更がある場合、更新されたPDFの内容を確認
pdftotext "2025docs/<更新されたPDF>" - | less

# 3. 対応するスキルファイルを更新
#    .claude/skills/<skill>/SKILL.md
#    .claude/skills/<skill>/references/*.md

# 4. レビュー完了後、ハッシュを更新
./scripts/update-pdf-hashes.sh
```

#### 各スクリプトの役割

| スクリプト | 説明 |
| --- | --- |
| `scripts/check-pdf-updates.sh` | 仕様書PDFのMD5ハッシュを `.claude/pdf-hashes.txt` と比較し、変更があったPDFとレビューが必要なスキルを表示 |
| `scripts/update-pdf-hashes.sh` | 現在のPDFのハッシュを記録（スキルレビュー完了後に実行） |

#### 出力例（変更あり）

```text
======================================
PDF FILES UPDATED - SKILL REVIEW NEEDED
======================================

Changed PDFs:
  - PayPay/02_PG外部インターフェース仕様説明書（別紙：PayPay）.pdf

Skills requiring review:
  - paygent-digital → .claude/skills/paygent-digital/
```

#### PDFとスキルのマッピング

| PDF（2025docs/内） | 対応スキル |
| --- | --- |
| `system/モジュールタイプ/02_PG外部インターフェース仕様説明書.pdf` | paygent-core, paygent-cc, paygent-bank |
| `system/モジュールタイプ/02_PG外部インターフェース仕様説明書（トークン決済）.pdf` | paygent-cc |
| `PayPay/02_PG外部インターフェース仕様説明書（別紙：PayPay）.pdf` | paygent-digital |
| `Paidy/02_PG外部インターフェース仕様説明書（別紙：Paidy）.pdf` | paygent-digital |
| `楽天ペイ/02_PG外部インターフェース仕様説明書（別紙：楽天ペイ）.pdf` | paygent-digital |
| `ApplePay/02_PG外部インターフェース仕様説明書（別紙：Apple Pay）.pdf` | paygent-digital |
| `GooglePay/02_PG外部インターフェース仕様説明書（別紙：Google Pay）.pdf` | paygent-digital |
| `Alipay国際決済/02_PG外部インターフェース仕様説明書（別紙：Alipay国際決済）.pdf` | paygent-digital |
| `銀聯ネット決済/02_PG外部インターフェース仕様説明書（別紙：銀聯ネット決済）.pdf` | paygent-digital |
| `携帯キャリア決済（都度課金）/02_PG外部インターフェース仕様説明書（別紙：携帯キャリア決済）.pdf` | paygent-carrier |
| `携帯キャリア決済（継続課金）/02_PG外部インターフェース仕様説明書（別紙：携帯キャリア決済継続課金）.pdf` | paygent-carrier |

## License

[GPLv3](http://www.gnu.org/licenses/gpl-3.0.html)

## Author

[Artisan Workshop](https://wc.artws.info/)
