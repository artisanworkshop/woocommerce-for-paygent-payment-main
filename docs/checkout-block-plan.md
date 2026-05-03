# Checkout Block 実装計画

作成日: 2026-05-03

## アーキテクチャ概要

```
PHP側                                    JS側
────────────────────────────────────────────────────────────
AbstractPaymentMethodType                registerPaymentMethod()
  └─ Abstract_WC_Paygent_Block_Payment     └─ label / content (React)
       └─ WC_Paygent_Block_Redirect              └─ onPaymentSetup
       └─ WC_Paygent_Block_CC                         └─ paymentMethodData → process_payment()
       └─ WC_Paygent_Block_CS
       └─ WC_Paygent_Block_MB
       └─ WC_Paygent_Block_Paidy
       └─ WC_Paygent_Block_MCCC  ← WC_Paygent_Block_CC を継承
```

`process_payment()` は変更不要。JS 側から token を `paymentMethodData` として渡す。
3DS2 も `process_payment()` が redirect URL を返す既存フローをそのまま活用。

---

## ディレクトリ構成

### PHP: `includes/gateways/paygent/includes/block/`

```
includes/gateways/paygent/includes/block/
  abstract-wc-paygent-block-payment.php   ← 全 Block クラスの抽象基底
  class-wc-paygent-block-redirect.php     ← ATM/BN/PayPay/楽天ペイ【1クラスで4役】
  class-wc-paygent-block-cc.php           ← CC + Addon_CC (Branch 2)
  class-wc-paygent-block-cs.php           ← コンビニ (Branch 3)
  class-wc-paygent-block-mb.php           ← キャリア + Addon_MB (Branch 3)
  class-wc-paygent-block-paidy.php        ← Paidy (Branch 3)
  class-wc-paygent-block-mccc.php         ← MCCC【CCクラスを継承】(Branch 3+)
```

**重複削減ポイント**:
- リダイレクト系 4 ゲートウェイ → `WC_Paygent_Block_Redirect` に `$name` を渡すだけ
- MCCC → `WC_Paygent_Block_CC` を継承し `get_name()` と通貨設定のみオーバーライド

### JS: `src/blocks/`

```
src/blocks/
  shared/
    components/
      PaymentLabel.jsx          ← 全ゲートウェイ共通ラベル
      PaymentDescription.jsx    ← 説明文表示
      index.js
    utils/
      tokenize.js               ← PaygentToken.js の Promise ラッパー (Branch 2)
      saved-cards.js            ← 保存カード共通ロジック (Branch 2)
  paygent-cc/
    index.js
    components/
      PaymentForm.jsx
      SavedCardSelector.jsx
      InstallmentSelector.jsx
  paygent-cs/
    index.js
    components/ConvenienceStoreSelector.jsx
  paygent-mb/
    index.js
    components/CarrierSelector.jsx
  paygent-redirect/
    index.js                    ← ATM/BN/PayPay/楽天ペイを1ファイルで一括登録
  paygent-paidy/
    index.js
    components/PaidyContent.jsx
  paygent-mccc/
    index.js                    ← paygent-cc/components をそのまま import
```

### ビルド出力: `build/`

```
build/
  paygent-redirect.js         ← Branch 1
  paygent-redirect.asset.php
  paygent-cc.js               ← Branch 2
  paygent-cc.asset.php
  paygent-cs.js               ← Branch 3
  paygent-mb.js
  paygent-paidy.js
  paygent-mccc.js
```

---

## ブランチ構成

```
trunk
 │
 ├── feature/block-redirect-gateways  ─ PR → trunk
 │   基盤 + ATM/BN/PayPay/楽天ペイ
 │
 ├── feature/block-cc                 ─ PR → trunk（Branch 1 マージ後）
 │   CC + Addon_CC + 3DS2 + 保存カード
 │
 └── feature/block-cs-mb-paidy        ─ PR → trunk（Branch 1 マージ後）
     コンビニ + キャリア + Paidy + MCCC
```

---

## Branch 1: `feature/block-redirect-gateways`

**基盤構築 + ATM・BN・PayPay・楽天ペイ**

### 変更・作成ファイル

| 操作 | ファイル |
|------|---------|
| 新規 | `docs/checkout-block-plan.md` |
| 新規 | `.claude/skills/wc-block-payment/skill.md` |
| 新規 | `includes/gateways/paygent/includes/block/abstract-wc-paygent-block-payment.php` |
| 新規 | `includes/gateways/paygent/includes/block/class-wc-paygent-block-redirect.php` |
| 新規 | `src/blocks/shared/components/PaymentLabel.jsx` |
| 新規 | `src/blocks/shared/components/PaymentDescription.jsx` |
| 新規 | `src/blocks/shared/components/index.js` |
| 新規 | `src/blocks/paygent-redirect/index.js` |
| 新規 | `webpack.config.js` |
| 修正 | `class-wc-gateway-paygent.php`（Block 登録フック・includes 追加） |

### 核心設計

```php
// WC_Paygent_Block_Redirect — 4ゲートウェイを1クラスで処理
new WC_Paygent_Block_Redirect( 'paygent_atm',        [ 'products', 'refunds' ] );
new WC_Paygent_Block_Redirect( 'paygent_bn',         [ 'products', 'refunds' ] );
new WC_Paygent_Block_Redirect( 'paygent_paypay',     [ 'products', 'refunds' ] );
new WC_Paygent_Block_Redirect( 'paygent_rakutenpay', [ 'products', 'refunds' ] );
```

```js
// src/blocks/paygent-redirect/index.js — 4ゲートウェイを1ファイルで登録
['paygent_atm', 'paygent_bn', 'paygent_paypay', 'paygent_rakutenpay'].forEach((name) => {
    const settings = getSetting(`${name}_data`, null);
    if (!settings) return;
    registerPaymentMethod({ name, label, content, ... });
});
```

---

## Branch 2: `feature/block-cc`

**CC + Addon_CC (Subscriptions) + 3DS2 + 保存カード**

### 変更・作成ファイル

| 操作 | ファイル |
|------|---------|
| 新規 | `includes/gateways/paygent/includes/block/class-wc-paygent-block-cc.php` |
| 新規 | `src/blocks/shared/utils/tokenize.js` |
| 新規 | `src/blocks/shared/utils/saved-cards.js` |
| 新規 | `src/blocks/paygent-cc/index.js` |
| 新規 | `src/blocks/paygent-cc/components/PaymentForm.jsx` |
| 新規 | `src/blocks/paygent-cc/components/SavedCardSelector.jsx` |
| 新規 | `src/blocks/paygent-cc/components/InstallmentSelector.jsx` |
| 修正 | `webpack.config.js`（CC エントリ追加） |
| 修正 | `class-wc-gateway-paygent.php`（CC Block 登録追加） |

### 核心設計（PHP）

```php
// class-wc-paygent-block-cc.php
class WC_Paygent_Block_CC extends Abstract_WC_Paygent_Block_Payment {
    protected string $name = 'paygent_cc';

    public function get_payment_method_data(): array {
        return array_merge( parent::get_payment_method_data(), [
            'merchantId'     => /* get_option から取得 */,
            'tokenKey'       => /* get_option から取得 */,
            'savedCards'     => $this->get_saved_cards(),
            'installments'   => $this->get_installment_options(),
            'enableSaveCard' => /* 設定から取得 */,
        ] );
    }
}
```

### 核心設計（JS）

```js
// src/blocks/shared/utils/tokenize.js — CC・MCCC 共用
export const createToken = (merchantId, tokenKey, cardData) =>
    new Promise((resolve, reject) => {
        new window.PaygentToken().createToken(
            merchantId, tokenKey, cardData,
            (res) => res.resultCode === '0000' ? resolve(res) : reject(res)
        );
    });
```

### 3DS2 フロー

```
JS onPaymentSetup → token 取得 → paymentMethodData に乗せる
    ↓
WooCommerce が process_payment() を呼ぶ（変更不要）
    ↓
process_payment() が ['result'=>'success','redirect'=>'3DS_URL'] を返す
    ↓
WooCommerce Blocks が自動的に 3DS_URL へリダイレクト
```

---

## Branch 3: `feature/block-cs-mb-paidy`

**コンビニ + キャリア + Addon_MB + Paidy + MCCC**

### MCCC の重複ゼロ化

```php
// class-wc-paygent-block-mccc.php — CC を継承、差分のみ
class WC_Paygent_Block_MCCC extends WC_Paygent_Block_CC {
    protected string $name = 'paygent_mccc';
}
```

```js
// src/blocks/paygent-mccc/index.js — CC コンポーネントをそのまま再利用
import PaymentForm from '../paygent-cc/components/PaymentForm';
```

---

## 重複削減サマリー

| 共通化した部分 | 効果 |
|-------------|------|
| `Abstract_WC_Paygent_Block_Payment` | 初期化・is_active・基本データ取得を全クラスで共用 |
| `WC_Paygent_Block_Redirect` 1クラス | ATM/BN/PayPay/楽天ペイの4クラスを1つに集約 |
| `src/blocks/shared/utils/tokenize.js` | CC と MCCC で同じトークン処理を共用 |
| `src/blocks/shared/components/` | 全ゲートウェイのラベル・説明文表示を統一 |
| `WC_Paygent_Block_MCCC extends WC_Paygent_Block_CC` | MCCC 固有コードをほぼゼロに |
