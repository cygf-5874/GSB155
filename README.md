# quotaacct —— PHP 8 的配额记账

按桶（bucket）记账的配额账本：每笔交易先**预留**（`reserve`）占住配额，**提交**（`commit`）
后从预留转入已用；本次要在既有能力上补出**退款**（`refund`）与**释放**（`release`），
并把「过期预留自动回收」接进来。

- 语言/框架：PHP 8，**仅标准库**（php-cli，无 composer，无第三方依赖，不用 mbstring）。
- 自检入口：`bash scripts/check.sh`（内部就是 `php check/check.php`）。
  `check/` 是**固定验收程序**，不要改。
- 类都在 `src/` 下：`QuotaLedger`（账本）、`Clock`（注入时钟）、`QuotaException`。

## 快速开始

```bash
php -l src/QuotaLedger.php       # 语法检查
php tests/run.php                # 既有用例，12 个
bash scripts/check.sh            # 跑全部 9 个场景，全过才 exit 0
bash scripts/check.sh -list      # 列出全部场景
bash scripts/check.sh --only refund
```

## 公开 API

```php
namespace Quotaacct;

final class QuotaLedger
{
    public const TTL = 300;   // 未 commit 的预留过期秒数

    public function __construct(array $capacities, Clock $clock);

    public function reserve(string $txnId, string $bucket, int $amount): void;
    public function commit(string $txnId): void;
    public function release(string $txnId): void;   // 本次要补
    public function refund(string $txnId): void;    // 本次要补

    public function used(string $bucket): int;
    public function reserved(string $bucket): int;
    public function available(string $bucket): int;
}

interface Clock { public function now(): int; }

final class QuotaException extends \RuntimeException {}
```

公开方法名与签名已定死，不得更改；可以新增类文件。

## 对外契约（9 条）

1. **既有能力不得回归**：`reserve(txnId, bucket, amount)` —— 空 `txnId`、未知桶、`amount <= 0`、
   `txnId` 重复、桶可用量不足，一律抛 `QuotaException`；成功则把 `amount` 记进该桶的预留，
   登记交易（状态 `reserved`，记录当时刻）。`commit(txnId)` 只接受状态为 `reserved` 的交易，
   把它从预留转入已用（状态 `committed`）；其余抛 `QuotaException`。
2. **退款 exactly-once**：`refund(txnId)` 只有**状态为 `committed`** 的交易可退；**同一交易最多退一次**，
   重复 `refund` 抛 `QuotaException`；对不存在 / `reserved` / 已释放 / 已过期的交易一律抛 `QuotaException`。
3. **按桶退回**：退款把 `amount` 记回该交易**原来那个桶**（交易里记着桶名），不得做全局汇总。
4. **释放**：`release(txnId)` 只接受状态为 `reserved`（未 commit）的交易，把它从预留撤掉
   （状态 `released`）；对已 `committed` 的交易调用抛 `QuotaException`；与 `refund` 互斥。
5. **容量不变量**：任意时刻、任意桶都满足 `reserved(bucket) + used(bucket) <= capacity(bucket)`，
   且 `reserved >= 0`、`used >= 0`；退款不得使 `used` 变负。
6. **过期回收**：`reserve` 记录时刻；**每次对外操作前**按注入时钟回收所有满足
   `clock->now() - at >= QuotaLedger::TTL` 的 `reserved` 交易（状态置 `expired`，从预留撤掉）。
   回收与手动 `release` 在**计数意义上幂等**：一个预留无论被回收还是被手动释放都只归还一次配额；
   对已被回收的交易再调 `release` 抛 `QuotaException`（与重复 release 同）。
7. **重复调用语义（写死为抛错）**：已 `committed` 再 `commit` 抛 `QuotaException`；
   已退 / 已释放 / 已过期 / 不存在再调 `refund` / `release` 抛 `QuotaException`；
   对已 `released` 或 `expired` 的交易 `commit` 也抛 `QuotaException`。
8. **会计平衡**：对每个桶，
   - `used(bucket) == 该桶 committed 总额 − 该桶 refunded 总额`；
   - `reserved(bucket) == 该桶当前仍为 reserved 的预留总额`；
   - `available(bucket) == capacity(bucket) − used(bucket) − reserved(bucket)`，且三个量都不得为负。
9. **确定性**：调用顺序固定时结果确定；不读墙钟（时间只来自注入时钟）；
   所有遍历 / 汇总不得依赖 PHP 数组哈希顺序。

## 本次要补的一层

`release` 与 `refund`（现在还是 `throw new \LogicException('not implemented')`），
以及第 6 条的过期回收。三者互相咬合：**过期回收 → 容量限批量 → 状态机决定计数（exactly-once）**。

## 目录

```
README.md             本文件
PROMPT.md             出题用的 User Prompt
autoload.php          极简 spl_autoload_register（__DIR__ 相对定位 src/）
src/QuotaLedger.php   账本          ← release / refund 是空实现
src/Clock.php         注入时钟接口
src/QuotaException.php 统一异常
tests/run.php         既有用例，12 个，起点全绿
check/check.php       固定验收程序（别改）
scripts/check.sh      固定验收的 shell 入口
```