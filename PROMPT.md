quotaacct 是 PHP 8 的配额记账库（php-cli，无 composer，无第三方依赖），README「对外契约」有 9 条。自检走 `scripts/check.sh`，既有用例走 `php tests/run.php`。

任务：补齐 `release` 与 `refund`，并让原有 reserve/commit 与新路径共同维护容量、余额、到期和确定性约束。

验收：
- php tests/run.php 全绿；
- php check/check.php 退出码 0，9 个场景全过（legacy 2 + refund 3 + capacity 2 + expiry 1 + balance 1）。

约束：
1. 不改 `check/`、`src/Clock.php`、`src/QuotaException.php`；对外签名不变。
2. 时间只来自注入 Clock；结果不得依赖数组哈希顺序。
3. 重复调用、错误状态转换、容量不足和跨交易累计余额都必须确定性处理。
