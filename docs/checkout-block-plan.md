# Checkout Block 実装計画

作成日: 2026-05-03  
完了日: 2026-05-05（全 Branch 実装済み）

## 実装状況

| Branch | ブランチ名 | 対象ゲートウェイ | 状態 |
|--------|-----------|----------------|------|
| Branch 1 | `feature/block-redirect-gateways` | ATM / BN / PayPay / 楽天ペイ | ✅ trunk にマージ済み |
| Branch 2 | `feature/block-cc` | CC + 保存カード + 3DS2 | ✅ trunk にマージ済み |
| Branch 3 | `feature/block-cs-mb-paidy` | コンビニ / キャリア / Paidy / MCCC | ✅ 実装完了

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
  class-abstract-wc-paygent-block-payment.php ← 全 Block クラスの抽象基底
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

> **注意**: 計画時に想定した `components/` サブディレクトリは作成せず、  
> 各ゲートウェイの UI・ロジックをすべて単一の `index.js` に収めた。

```
src/blocks/
  shared/
    components/
      PaymentLabel.jsx          ← 全ゲートウェイ共通ラベル
      PaymentDescription.jsx    ← 説明文表示
      index.js
    utils/
      tokenize.js               ← PaygentToken.js の Promise ラッパー (Branch 2)
  paygent-redirect/
    index.js                    ← ATM/BN/PayPay/楽天ペイを1ファイルで一括登録 (Branch 1)
  paygent-cc/
    index.js                    ← CardForm / SavedCardSelector / InstallmentSelector を内包 (Branch 2)
  paygent-cs/
    index.js                    ← ConvenienceStoreForm を内包 (Branch 3)
  paygent-mb/
    index.js                    ← CarrierForm を内包 (Branch 3)
  paygent-paidy/
    index.js                    ← PaidyContent を内包・リダイレクト型 (Branch 3)
  paygent-mccc/
    index.js                    ← McccCardForm を内包（CCとは独立実装、分割なし）(Branch 3)
```

### ビルド出力: `build/`

```
build/
  paygent-redirect.js         ← Branch 1 ✅
  paygent-redirect.asset.php
  paygent-cc.js               ← Branch 2 ✅
  paygent-cc.asset.php
  paygent-cs.js               ← Branch 3 ✅
  paygent-cs.asset.php
  paygent-mb.js               ← Branch 3 ✅
  paygent-mb.asset.php
  paygent-paidy.js            ← Branch 3 ✅
  paygent-paidy.asset.php
  paygent-mccc.js             ← Branch 3 ✅
  paygent-mccc.asset.php
```

---

## ブランチ構成

```
trunk
 │
 ├── feature/block-redirect-gateways  ─ PR → trunk ✅ マージ済み
 │   基盤 + ATM/BN/PayPay/楽天ペイ
 │
 ├── feature/block-cc                 ─ PR → trunk ✅ マージ済み
 │   CC + Addon_CC + 3DS2 + 保存カード
 │
 └── feature/block-cs-mb-paidy        ─ PR → trunk ✅ 実装完了
     コンビニ + キャリア + Paidy + MCCC
```

---

## Branch 1: `feature/block-redirect-gateways` ✅ 完了

**基盤構築 + ATM・BN・PayPay・楽天ペイ**

### 変更・作成ファイル

| 操作 | ファイル |
|------|---------|
| 新規 | `docs/checkout-block-plan.md` |
| 新規 | `.claude/skills/wc-block-payment/SKILL.md` |
| 新規 | `includes/gateways/paygent/includes/block/class-abstract-wc-paygent-block-payment.php` |
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

## Branch 2: `feature/block-cc` ✅ 完了

**CC + Addon_CC (Subscriptions) + 3DS2 + 保存カード**

### 変更・作成ファイル

| 操作 | ファイル |
|------|---------|
| 新規 | `includes/gateways/paygent/includes/block/class-wc-paygent-block-cc.php` |
| 新規 | `src/blocks/shared/utils/tokenize.js` |
| 新規 | `src/blocks/paygent-cc/index.js`（CardForm / SavedCardSelector / InstallmentSelector を内包） |
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

## Branch 3: `feature/block-cs-mb-paidy` ✅ 完了

**コンビニ + キャリア + Addon_MB + Paidy + MCCC**

### 変更・作成ファイル

| 操作 | ファイル |
|------|---------|
| 新規 | `includes/gateways/paygent/includes/block/class-wc-paygent-block-cs.php` |
| 新規 | `includes/gateways/paygent/includes/block/class-wc-paygent-block-mb.php` |
| 新規 | `includes/gateways/paygent/includes/block/class-wc-paygent-block-paidy.php` |
| 新規 | `includes/gateways/paygent/includes/block/class-wc-paygent-block-mccc.php` |
| 新規 | `src/blocks/paygent-cs/index.js` |
| 新規 | `src/blocks/paygent-mb/index.js` |
| 新規 | `src/blocks/paygent-paidy/index.js` |
| 新規 | `src/blocks/paygent-mccc/index.js` |
| 新規 | `assets/css/paygent-block-select.css`（CS/MB 店舗・キャリア選択UI用） |
| 修正 | `webpack.config.js`（CS/MB/Paidy/MCCC エントリ追加） |
| 修正 | `class-wc-gateway-paygent.php`（4クラスの require + 登録追加） |

### 核心設計

**コンビニ（CS）**: 店舗セレクタを `onPaymentSetup` で `cvs_company_id` として送信。  
有効店舗リストは `setting_cs_se/lm/f/sm/ctd` の設定値から PHP 側で生成し JS へ渡す。

**キャリア（MB）**: キャリアセレクタを `onPaymentSetup` で `career_type` として送信。  
有効キャリアは `setting_ct_04/05/06` の設定値から生成。

**Paidy**: リダイレクト型のため `onPaymentSetup` 不要。説明文と `paidy_logo_100_2023.png` を表示するのみ。

**MCCC**: `WC_Paygent_Block_CC` を継承し `$name = 'paygent_mccc'` をオーバーライド。  
JS は CC コンポーネントを共有せず独立した `McccCardForm`（分割払い選択なし）として実装。  
`paymentMethodData` のキーは `paygent_mccc-token` / `paygent_mccc-cvc_token`（CC の `paygent_cc-` プレフィックスとは別）。

```php
// class-wc-paygent-block-mccc.php — CC を継承、name と script handles のみオーバーライド
class WC_Paygent_Block_MCCC extends WC_Paygent_Block_CC {
    protected $name = 'paygent_mccc';
    // get_payment_method_data() で paymentMethods / numberOfPayments を除去
}
```

---

## 重複削減サマリー

| 共通化した部分 | 効果 |
|-------------|------|
| `Abstract_WC_Paygent_Block_Payment` | 初期化・is_active・基本データ取得を全クラスで共用 |
| `WC_Paygent_Block_Redirect` 1クラス | ATM/BN/PayPay/楽天ペイの4クラスを1つに集約 |
| `src/blocks/shared/utils/tokenize.js` | CC と MCCC で同じトークン処理を共用 |
| `src/blocks/shared/components/` | 全ゲートウェイのラベル・説明文表示を統一 |
| `WC_Paygent_Block_MCCC extends WC_Paygent_Block_CC` | PHP 側の重複をゼロに。JS は安全優先で独立実装 |
| `assets/css/paygent-block-select.css` | CS・MB のセレクタ UI を共通スタイルで統一 |

## 実装メモ（計画との差異）

- JS の各ゲートウェイは `components/` サブディレクトリを作成せず、すべて単一 `index.js` に収めた。コンポーネント数が少なく分割による恩恵が見込めなかったため。
- `src/blocks/shared/utils/saved-cards.js` は計画にあったが作成しなかった。保存カードの取得・表示ロジックは `paygent-cc/index.js` 内に直接実装している。
- MCCC の JS は CC の `CardForm` を import せず独立した `McccCardForm` として実装した。既存 CC 実装を壊すリスクを避け、MCCC 固有の差分（分割払いなし）を明確にするため。
