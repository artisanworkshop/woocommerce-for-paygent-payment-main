# WooCommerce Block Payment Method スキル

## 発動キーワード

`AbstractPaymentMethodType`, `registerPaymentMethod`, `blocks-registry`, `onPaymentSetup`,
`wc-paygent-block`, `get_payment_method_data`, `get_payment_method_script_handles`,
`woocommerce_blocks_payment_method_type_registration`, `PaymentMethodRegistry`,
`block-cc`, `block-redirect`, `block-cs`, `block-mb`, `block-paidy`

---

## このプラグインの Block 統合アーキテクチャ

```
PHP 登録フロー:
  woocommerce_blocks_payment_method_type_registration
    └─ PaymentMethodRegistry::register( new WC_Paygent_Block_XXX() )
         └─ initialize()          ← DB から設定を読む
         └─ is_active()           ← enabled 設定を確認
         └─ get_payment_method_script_handles()  ← JS を登録・返却
         └─ get_payment_method_data()            ← JS に渡すデータ

JS 登録フロー:
  registerPaymentMethod({
    name,        ← ゲートウェイ ID (例: 'paygent_cc')
    label,       ← React element (PaymentLabel コンポーネント)
    content,     ← React element (チェックアウト画面に表示)
    edit,        ← React element (ブロックエディタ用プレビュー)
    canMakePayment,  ← () => boolean
    ariaLabel,
    supports: { features: [...] }
  });
```

---

## PHP クラス継承ツリー

```
AbstractPaymentMethodType  (WooCommerce Blocks コア)
  └─ Abstract_WC_Paygent_Block_Payment
       ├─ WC_Paygent_Block_Redirect   (ATM, BN, PayPay, 楽天ペイ — 1クラスで4役)
       ├─ WC_Paygent_Block_CC         (CC + Addon_CC)
       │    └─ WC_Paygent_Block_MCCC  (MCCC — CC を継承して name だけ変更)
       ├─ WC_Paygent_Block_CS         (コンビニ)
       ├─ WC_Paygent_Block_MB         (キャリア + Addon_MB)
       └─ WC_Paygent_Block_Paidy      (Paidy)
```

---

## Abstract_WC_Paygent_Block_Payment の責務

```php
// initialize() — DB から設定を読む（ゲートウェイインスタンスは作らない）
$this->settings = get_option( 'woocommerce_' . $this->name . '_settings', [] );

// is_active() — enabled フラグを確認
return filter_var( $this->settings['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN );

// get_payment_method_data() — JS へ渡す最小限のデータ
return [
    'title'       => $this->settings['title'] ?? '',
    'description' => $this->settings['description'] ?? '',
    'supports'    => $this->get_supported_features(),
];
```

---

## WC_Paygent_Block_Redirect の使い方

```php
// 1クラスで4ゲートウェイを処理
new WC_Paygent_Block_Redirect( 'paygent_atm',        [ 'products', 'refunds' ] );
new WC_Paygent_Block_Redirect( 'paygent_bn',         [ 'products', 'refunds' ] );
new WC_Paygent_Block_Redirect( 'paygent_paypay',     [ 'products', 'refunds' ] );
new WC_Paygent_Block_Redirect( 'paygent_rakutenpay', [ 'products', 'refunds' ] );

// スクリプトは build/paygent-redirect.js として共有
// asset ファイルから依存関係を自動解決
```

---

## JS データ受け取りパターン

```js
// PHP の get_payment_method_data() の返り値は getSetting で取得
import { getSetting } from '@woocommerce/settings';
const settings = getSetting('paygent_atm_data', null);
// キー名: {gateway_name}_data
```

---

## CC ゲートウェイの data フロー

```
PHP get_payment_method_data():
  merchantId, tokenKey, savedCards, installments, enableSaveCard を返す

JS onPaymentSetup callback:
  1. PaygentToken.createToken() または createCvcToken() でトークン取得
  2. { type: 'success', meta: { paymentMethodData: { paygent_token, ... } } } を返す

PHP process_payment( $order_id ):
  1. $_POST から paygent_token 等を取得（変更不要）
  2. Paygent API へ送信（telegram_kind=020）
  3. 3DS2 が必要な場合: return ['result'=>'success', 'redirect'=>$tds2_url]
     → WooCommerce Blocks が自動的にリダイレクト
```

---

## PaygentToken.js の Promise ラッパー

```js
// src/blocks/shared/utils/tokenize.js
export const createToken = (merchantId, tokenKey, cardData) =>
    new Promise((resolve, reject) => {
        if (!window.PaygentToken) {
            reject(new Error('PaygentToken not loaded'));
            return;
        }
        new window.PaygentToken().createToken(
            merchantId, tokenKey, cardData,
            (res) => res.resultCode === '0000' ? resolve(res) : reject(res)
        );
    });

export const createCvcToken = (merchantId, tokenKey, cvc) =>
    new Promise((resolve, reject) => {
        new window.PaygentToken().createCvcToken(
            merchantId, tokenKey, { cvc },
            (res) => res.resultCode === '0000' ? resolve(res) : reject(res)
        );
    });
```

---

## スクリプト登録パターン（asset.php 使用）

```php
public function get_payment_method_script_handles(): array {
    if ( ! wp_script_is( 'wc-paygent-block-XXX', 'registered' ) ) {
        $asset_file = WC_PAYGENT_ABSPATH . 'build/paygent-XXX.asset.php';
        $asset      = file_exists( $asset_file )
            ? require $asset_file
            : [ 'dependencies' => [], 'version' => WC_PAYGENT_VERSION ];

        wp_register_script(
            'wc-paygent-block-XXX',
            WC_PAYGENT_PLUGIN_URL . 'build/paygent-XXX.js',
            $asset['dependencies'],
            $asset['version'],
            true
        );
    }
    return [ 'wc-paygent-block-XXX' ];
}
```

---

## webpack.config.js 構成

```js
// @woocommerce/dependency-extraction-webpack-plugin を使用
// @woocommerce/* パッケージを自動的に外部依存として扱う
// @wordpress/* パッケージも同様

// Entry ポイント（ブランチごとに追加）
entry: {
    'paygent-redirect': './src/blocks/paygent-redirect/index.js',  // Branch 1
    'paygent-cc':       './src/blocks/paygent-cc/index.js',        // Branch 2
    'paygent-cs':       './src/blocks/paygent-cs/index.js',        // Branch 3
    'paygent-mb':       './src/blocks/paygent-mb/index.js',        // Branch 3
    'paygent-paidy':    './src/blocks/paygent-paidy/index.js',     // Branch 3
    'paygent-mccc':     './src/blocks/paygent-mccc/index.js',      // Branch 3+
}
```

---

## ファイルパス早見表

| 役割 | パス |
|------|------|
| PHP 抽象基底 | `includes/gateways/paygent/includes/block/abstract-wc-paygent-block-payment.php` |
| PHP リダイレクト共通 | `includes/gateways/paygent/includes/block/class-wc-paygent-block-redirect.php` |
| PHP CC | `includes/gateways/paygent/includes/block/class-wc-paygent-block-cc.php` |
| JS 共通コンポーネント | `src/blocks/shared/components/` |
| JS トークンユーティリティ | `src/blocks/shared/utils/tokenize.js` |
| JS リダイレクト登録 | `src/blocks/paygent-redirect/index.js` |
| ビルド出力 | `build/paygent-{name}.js` + `build/paygent-{name}.asset.php` |

---

## 注意事項

- `process_payment()` は変更しない（既存ロジックをそのまま使う）
- `get_payment_method_data()` でゲートウェイインスタンスは作らず `get_option()` 直接呼び出し
- Paygent Token JS は外部 URL (`//token.paygent.co.jp/js/PaygentToken.js`)
- ビルドファイル (`build/`) はリポジトリにコミットする
- `build/paygent-XXX.asset.php` が存在しない場合のフォールバックを必ず実装
