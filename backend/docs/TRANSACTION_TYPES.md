# Transaction Types Reference

All transaction types are defined as constants in `App\Models\Transaction`.
All query groups are defined as array constants on the same model.

**Any new type must be added to `Transaction` model constants first, then used via constant reference.**

---

## Types

| Constant | Value | Direction | Description |
|---|---|---|---|
| `TYPE_PURCHASE` | `purchase` | negative | Customer purchase subscription (self-service or admin-provisioned) |
| `TYPE_RENEW` | `renew` | negative/zero | Subscription renewal (auto or manual; zero when offline-paid) |
| `TYPE_DEDUCTION` | `deduction` | negative | Forward upgrade fee, test-to-production conversion |
| `TYPE_TOPUP` | `topup` | positive | Balance deposit (payment gateway or admin manual) |
| `TYPE_REFUND` | `refund` | positive | Subscription cancellation refund (credited to balance) |
| `TYPE_GATEWAY_REFUND` | `gateway_refund` | negative | Refund via original payment channel (deducted from balance) |
| `TYPE_WITHDRAWAL` | `withdrawal` | negative | Commission withdrawal or admin private refund |
| `TYPE_ADJUSTMENT_IN` | `adjustment_in` | positive | Admin manual balance increase |
| `TYPE_ADJUSTMENT_OUT` | `adjustment_out` | negative | Admin manual balance decrease |
| `TYPE_COMMISSION` | `commission` | positive | Referral/sales commission credit (to commission_balance) |
| `TYPE_COMMISSION_TRANSFER` | `commission_transfer` | positive | Commission balance -> regular balance transfer |
| `TYPE_COMMISSION_REVERSAL` | `commission_reversal` | negative | Reverse commission on subscription refund |

---

## Query Groups

| Constant | Value | Usage |
|---|---|---|
| `REVENUE_TYPES` | `[purchase, renew, deduction]` | Revenue/transaction stats (SalesStats, Finance, BigData) |
| `SPENDING_EXCLUDE_TYPES` | `[withdrawal, adjustment_out, refund, gateway_refund, commission_reversal]` | Exclude when calculating customer spending |
| `TOPUP_TYPES` | `[topup, adjustment_in]` | Deposit/topup stats |
| `REFUND_TYPES` | `[refund, gateway_refund]` | Refund stats |

---

## Write Locations (where transactions are created)

| Type | File | Line | Context |
|---|---|---|---|
| `purchase` | `Services/Customer/CheckoutService.php` | 145, 270, 502 | Customer self-service purchase |
| `purchase` | `Controllers/Api/V1/SparkController.php` | 213 | Spark provision deduct |
| `purchase` | `Controllers/Api/V1/IpipvController.php` | 104 | IPIPV provision deduct |
| `purchase` | `Controllers/Api/V1/SubscriptionController.php` | 1188 | Admin batch provision deduct |
| `purchase` | `Controllers/Api/V1/SubscriptionController.php` | 1417 | Subscription transfer (target) |
| `renew` | `Services/SubscriptionService.php` | 350 | Auto/manual renewal |
| `deduction` | `Controllers/Api/V1/SubscriptionController.php` | 632 | Monthly forward fee deduction |
| `deduction` | `Controllers/Api/V1/SubscriptionController.php` | 1069 | Test-to-production conversion |
| `deduction` | `Controllers/Api/V1/Customer/SubscriptionController.php` | 723 | Customer forward upgrade |
| `topup` | `Controllers/Api/V1/CustomerController.php` | 759 | Admin manual topup |
| `topup` | `Controllers/Api/V1/Payment/EPayNotifyController.php` | 105 | EPay payment callback |
| `topup` | `Controllers/Api/V1/Payment/AlipayNotifyController.php` | 107 | Alipay payment callback |
| `refund` | `Controllers/Api/V1/SubscriptionController.php` | 941 | Admin unsubscribe refund |
| `refund` | `Controllers/Api/V1/SubscriptionController.php` | 1434 | Subscription transfer refund (source) |
| `refund` | `Controllers/Api/V1/Customer/SubscriptionController.php` | 262 | Customer self-refund |
| `gateway_refund` | `Services/Payment/PaymentRefundService.php` | 94 | Payment gateway original-channel refund |
| `withdrawal` | `Controllers/Api/V1/ApprovalController.php` | 238 | Commission withdrawal approval |
| `withdrawal` | `Controllers/Api/V1/CustomerController.php` | 812 | Admin private refund |
| `adjustment_in` | `Controllers/Api/V1/CustomerController.php` | 807 | Admin balance increase |
| `adjustment_out` | `Controllers/Api/V1/CustomerController.php` | 812 | Admin balance decrease |
| `commission` | `Services/ReferralService.php` | 136 | Referral commission credit |
| `commission` | `Controllers/Api/V1/CustomerController.php` | 685, 698 | Referrer transfer (commission reallocation) |
| `commission_transfer` | `Controllers/Api/V1/Customer/ReferralController.php` | 223 | Customer commission cash-out to balance |
| `commission_reversal` | `Services/ReferralService.php` | 175 | Reverse commission on refund |

---

## Read Locations (where transactions are queried)

### Revenue queries (use `REVENUE_TYPES`)

| File | Line | Context |
|---|---|---|
| `Controllers/Api/V1/SalesStatsController.php` | 180, 195 | Sales stats revenue |
| `Controllers/Api/V1/BigDataController.php` | 467-475, 507 | Big data dashboard revenue |
| `Controllers/Api/V1/BigDataController.php` | 428-433 | Sales person revenue |
| `Controllers/Api/V1/FinanceController.php` | 27-28, 77 | Finance overview + trend |
| `Controllers/Api/V1/CustomerController.php` | 871, 973 | Sales commission calculation |

### Spending exclusion queries (use `SPENDING_EXCLUDE_TYPES`)

| File | Line | Context |
|---|---|---|
| `Controllers/Api/V1/SalesStatsController.php` | 321 | Sales stats spending |
| `Controllers/Api/V1/FinanceController.php` | 97 | Customer ranking |
| `Controllers/Api/V1/PerformanceController.php` | 152 | Customer performance summary |
| `Controllers/Api/V1/Customer/DashboardController.php` | 41 | Customer dashboard spending |
| `Services/VipService.php` | 19 | VIP tier calculation |

### Topup queries (use `TOPUP_TYPES`)

| File | Line | Context |
|---|---|---|
| `Controllers/Api/V1/PerformanceController.php` | 155 | Customer topup total |

### Refund queries (use `REFUND_TYPES`)

| File | Line | Context |
|---|---|---|
| `Controllers/Api/V1/FinanceController.php` | 30-31 | Finance overview refund totals |

### Single-type queries (use `TYPE_*` constant)

| File | Line | Type | Context |
|---|---|---|---|
| `Controllers/Api/V1/BigDataController.php` | 479 | `TYPE_TOPUP` | Today topup |
| `Controllers/Api/V1/BigDataController.php` | 484 | `TYPE_REFUND` | Today refund |
| `Controllers/Api/V1/FinanceController.php` | 22-24, 76, 98 | `TYPE_TOPUP` | Finance topup stats |
| `Controllers/Api/V1/FinanceController.php` | 78 | `TYPE_REFUND` | Finance refund trend |
| `Controllers/Api/V1/DashboardController.php` | 54 | `TYPE_TOPUP` | Admin dashboard total revenue |
| `Controllers/Api/V1/Customer/DashboardController.php` | 54 | `TYPE_TOPUP` | Customer dashboard topup |
| `Controllers/Api/V1/SalesStatsController.php` | 237, 289 | `TYPE_RENEW` | Renewal cost join |

---

## Migration History

| Migration | Changes |
|---|---|
| `000120_unify_transaction_types` | `subscription_purchase` -> `purchase`, `subscription_renew` -> `renew`, `withdraw` -> `withdrawal`, `adjustment` -> `adjustment_out` |
