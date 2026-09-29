<?php

declare(strict_types=1);

/**
 * 固定验收入口：quotaacct 的「对外契约」。**别改这个文件。**
 *
 * 用法：
 *   php check/check.php                  跑全部 9 个场景，全过才退 0
 *   php check/check.php -list            列出全部场景
 *   php check/check.php --only refund    只跑一组
 *
 * 五组共 9 个场景：
 *   legacy   2 —— 既有 reserve/commit 不回归 / 既有错误语义；
 *   refund   3 —— exactly-once / 只有 committed 可退 / 退到原桶；
 *   capacity 2 —— 容量上界 / 退款不让 used 变负；
 *   expiry   1 —— 过期回收与手动 release 幂等（注入时钟）；
 *   balance  1 —— 会计平衡式成立。
 *
 * 判据完全确定：不读墙钟（时间来自注入时钟）、不用随机源、不依赖数组哈希顺序。
 * 失败不早退；每个场景用 try/catch(\Throwable) 兜住后继续。
 */

require __DIR__ . '/../autoload.php';

use Quotaacct\Clock;
use Quotaacct\QuotaException;
use Quotaacct\QuotaLedger;

/** 可推进的假时钟。 */
final class FakeClock implements Clock
{
    public function __construct(private int $t = 0)
    {
    }

    public function now(): int
    {
        return $this->t;
    }

    public function advance(int $delta): void
    {
        $this->t += $delta;
    }
}

final class CheckFailure extends \Exception
{
    public string $expected;

    public string $actual;

    public function __construct(string $message, string $expected = '-', string $actual = '-')
    {
        parent::__construct($message);
        $this->expected = $expected;
        $this->actual = $actual;
    }
}

function tk(string $name, string $why, callable $fn): array
{
    return ['name' => $name, 'why' => $why, 'fn' => $fn];
}

function export(mixed $v): string
{
    $s = var_export($v, true);
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;

    return strlen($s) > 320 ? substr($s, 0, 317) . '...' : $s;
}

function expect_same(mixed $want, mixed $got, string $what): void
{
    if ($want !== $got) {
        throw new CheckFailure($what, export($want), export($got));
    }
}

function throws_quota(string $what, callable $fn): void
{
    try {
        $fn();
    } catch (QuotaException $exc) {
        return;
    } catch (\Throwable $exc) {
        throw new CheckFailure(
            $what . ' 应抛 QuotaException',
            'QuotaException',
            get_class($exc) . ': ' . $exc->getMessage()
        );
    }

    throw new CheckFailure($what . ' 应抛 QuotaException', 'QuotaException', '正常返回');
}

$groups = [];

// ---------------------------------------------------------------------------
// legacy
// ---------------------------------------------------------------------------

$groups['legacy'] = [
    tk('reserve-commit-basics', '保证 1 / 5 / 8：既有扣减不回归', static function (): void {
        $l = new QuotaLedger(['a' => 100, 'b' => 50], new FakeClock(0));

        expect_same(100, $l->available('a'), 'a 初始可用');
        $l->reserve('t1', 'a', 30);
        expect_same(30, $l->reserved('a'), 'a 预留');
        expect_same(0, $l->used('a'), 'a 未用');
        $l->commit('t1');
        expect_same(0, $l->reserved('a'), 'a 预留归零');
        expect_same(30, $l->used('a'), 'a 已用');
        expect_same(70, $l->available('a'), 'a 可用');

        throws_quota('超容量 reserve', static fn () => $l->reserve('t2', 'a', 80));
        throws_quota('重复 txnId', static fn () => $l->reserve('t1', 'a', 1));
    }),
    tk('legacy-errors', '保证 1 / 7：既有错误语义', static function (): void {
        $l = new QuotaLedger(['a' => 100], new FakeClock(0));

        throws_quota('未知桶', static fn () => $l->reserve('t1', 'z', 10));
        throws_quota('amount 为 0', static fn () => $l->reserve('t1', 'a', 0));
        throws_quota('amount 为负', static fn () => $l->reserve('t1', 'a', -1));
        throws_quota('commit 未知', static fn () => $l->commit('nope'));

        $l->reserve('t9', 'a', 10);
        $l->commit('t9');
        throws_quota('重复 commit', static fn () => $l->commit('t9'));
    }),
];

// ---------------------------------------------------------------------------
// refund
// ---------------------------------------------------------------------------

$groups['refund'] = [
    tk('refund-exactly-once', '保证 2 / 5：每个交易最多退一次', static function (): void {
        $l = new QuotaLedger(['a' => 100], new FakeClock(0));

        $l->reserve('t1', 'a', 30);
        $l->commit('t1');
        expect_same(30, $l->used('a'), '提交后已用');

        $l->refund('t1');
        expect_same(0, $l->used('a'), '退款后已用归零');
        expect_same(100, $l->available('a'), '可用恢复');

        throws_quota('重复退款', static fn () => $l->refund('t1'));
        throws_quota('退款未知交易', static fn () => $l->refund('nope'));
    }),
    tk('refund-only-committed', '保证 2 / 4 / 7：只有 committed 可退，与 release 互斥', static function (): void {
        $l = new QuotaLedger(['a' => 100], new FakeClock(0));

        $l->reserve('t1', 'a', 20);
        throws_quota('未 commit 就退款', static fn () => $l->refund('t1'));

        $l->commit('t1');
        throws_quota('已 commit 再 release', static fn () => $l->release('t1'));
        expect_same(20, $l->used('a'), '状态未被误改');
    }),
    tk('refund-to-original-bucket', '保证 3：退款项回到原桶', static function (): void {
        $l = new QuotaLedger(['a' => 100, 'b' => 100], new FakeClock(0));

        $l->reserve('tA', 'a', 40);
        $l->commit('tA');
        $l->reserve('tB', 'b', 25);
        $l->commit('tB');
        expect_same(40, $l->used('a'), 'a 已用');
        expect_same(25, $l->used('b'), 'b 已用');

        $l->refund('tA');
        expect_same(0, $l->used('a'), 'a 退回');
        expect_same(25, $l->used('b'), 'b 不受影响');
        expect_same(100, $l->available('a'), 'a 可用恢复');
        expect_same(75, $l->available('b'), 'b 可用不变');
    }),
];

// ---------------------------------------------------------------------------
// capacity
// ---------------------------------------------------------------------------

$groups['capacity'] = [
    tk('capacity-upper-bound', '保证 5：预留 + 已用不超过容量', static function (): void {
        $l = new QuotaLedger(['a' => 100], new FakeClock(0));

        $l->reserve('t1', 'a', 60);
        $l->commit('t1');
        expect_same(60, $l->used('a'), '已用 60');
        expect_same(40, $l->available('a'), '可用 40');

        throws_quota('超预留', static fn () => $l->reserve('t2', 'a', 50));

        $l->reserve('t2', 'a', 40);
        expect_same(40, $l->reserved('a'), '预留 40');
        expect_same(0, $l->available('a'), '恰好占满');
        throws_quota('再加一处', static fn () => $l->reserve('t3', 'a', 1));
    }),
    tk('refund-not-negative', '保证 5：退款不让 used 变负、可重新占用', static function (): void {
        $l = new QuotaLedger(['a' => 100], new FakeClock(0));

        $l->reserve('t1', 'a', 30);
        $l->commit('t1');
        $l->refund('t1');

        expect_same(0, $l->used('a'), '退款后已用为 0 而不是负数');
        expect_same(100, $l->available('a'), '容量全额可用');

        $l->reserve('t2', 'a', 100);
        expect_same(0, $l->available('a'), '退款释放的额度可重新占用');
    }),
];

// ---------------------------------------------------------------------------
// expiry
// ---------------------------------------------------------------------------

$groups['expiry'] = [
    tk('expiry-reclaim-idempotent', '保证 6：过期回收与手动 release 幂等', static function (): void {
        $clock = new FakeClock(0);
        $l = new QuotaLedger(['a' => 100], $clock);

        $l->reserve('t1', 'a', 50);
        $l->reserve('t2', 'a', 20);
        $l->commit('t2');
        expect_same(50, $l->reserved('a'), '预留 50');

        $clock->advance(QuotaLedger::TTL);

        // 任意一次对外操作都应当先做回收。
        expect_same(80, $l->available('a'), '过期预留被回收，可用恢复（100 − 20 已用）');
        expect_same(0, $l->reserved('a'), '过期预留归零');
        expect_same(20, $l->used('a'), '已 commit 的交易不受影响');

        throws_quota('对已过期交易再 release', static fn () => $l->release('t1'));
        expect_same(80, $l->available('a'), '不会二次归还');
    }),
];

// ---------------------------------------------------------------------------
// balance
// ---------------------------------------------------------------------------

$groups['balance'] = [
    tk('accounting-balance', '保证 8：逐桶会计平衡式成立', static function (): void {
        $l = new QuotaLedger(['a' => 50, 'b' => 50], new FakeClock(0));

        $l->reserve('t1', 'a', 20);
        $l->commit('t1');
        $l->reserve('t2', 'a', 15);            // 仍为预留
        $l->reserve('t3', 'b', 30);
        $l->commit('t3');
        $l->refund('t1');                      // a 退 20 → committed 总额 20、refunded 20
        $l->reserve('t4', 'a', 10);
        $l->commit('t4');                      // a committed 总额 30
        $l->reserve('t5', 'b', 5);             // b 仍为预留

        // a：used = (20+10) - 20 = 10；reserved = 15；available = 50-10-15 = 25
        expect_same(10, $l->used('a'), 'a 已用 = committed − refunded');
        expect_same(15, $l->reserved('a'), 'a 预留');
        expect_same(25, $l->available('a'), 'a 可用 = 容量 − 已用 − 预留');

        // b：used = 30；reserved = 5；available = 50-30-5 = 15
        expect_same(30, $l->used('b'), 'b 已用');
        expect_same(5, $l->reserved('b'), 'b 预留');
        expect_same(15, $l->available('b'), 'b 可用');
    }),
];

// ---------------------------------------------------------------------------
// 运行器
// ---------------------------------------------------------------------------

$flat = [];
foreach ($groups as $group => $entries) {
    foreach ($entries as $entry) {
        $flat[] = [$group, $entry['name'], $entry['why'], $entry['fn']];
    }
}

$doList = false;
$only = null;

for ($i = 1; $i < count($argv); $i++) {
    $arg = $argv[$i];

    if ($arg === '-list' || $arg === '--list') {
        $doList = true;
    } elseif ($arg === '--only' || $arg === '--group') {
        $i++;
        if ($i >= count($argv)) {
            fwrite(STDERR, "--only 需要一个组名（legacy/refund/capacity/expiry/balance）\n");
            exit(2);
        }
        $only = [];
        foreach (explode(',', $argv[$i]) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $only[$part] = true;
            }
        }
    } elseif ($arg === '-h' || $arg === '--help') {
        echo "用法: php check/check.php [-list] [--only <组名>]\n";
        exit(0);
    } else {
        fwrite(STDERR, "未知参数: {$arg}\n");
        exit(2);
    }
}

if ($doList) {
    foreach ($flat as [$group, $name, $why, $_fn]) {
        printf("[%-8s] %-26s %s\n", $group, $name, $why);
    }
    exit(0);
}

$selected = [];
foreach ($flat as $entry) {
    if ($only === null || isset($only[$entry[0]])) {
        $selected[] = $entry;
    }
}

if ($selected === []) {
    fwrite(STDERR, "没有匹配的场景\n");
    exit(2);
}

$passed = 0;
$failed = 0;

foreach ($selected as [$group, $name, $_why, $fn]) {
    try {
        $fn();
    } catch (CheckFailure $exc) {
        $failed++;
        printf("FAIL %s/%s  期望=%s 实际=%s（%s）\n", $group, $name, $exc->expected, $exc->actual, $exc->getMessage());
        continue;
    } catch (\Throwable $exc) {
        $failed++;
        printf("FAIL %s/%s  期望=场景正常结束 实际=%s: %s\n", $group, $name, get_class($exc), $exc->getMessage());
        continue;
    }

    $passed++;
    printf("PASS %s/%s\n", $group, $name);
}

printf("结果：通过 %d/%d\n", $passed, count($selected));
exit($failed === 0 ? 0 : 1);