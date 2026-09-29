<?php

declare(strict_types=1);

/**
 * quotaacct 既有用例：12 个。**别改这个文件。**
 *
 * 用法： php tests/run.php     全绿 → 退出码 0，否则 1
 *
 * 只覆盖既有能力（reserve / commit / used / reserved / available）与错误语义，
 * 不触碰本次要补的 refund / release / 过期回收。
 */

require __DIR__ . '/../autoload.php';

use Quotaacct\Clock;
use Quotaacct\QuotaException;
use Quotaacct\QuotaLedger;

/** 固定时钟：既有用例不推进时间。 */
final class FixedClock implements Clock
{
    public function __construct(private int $t = 0)
    {
    }

    public function now(): int
    {
        return $this->t;
    }
}

$GLOBALS['t_total'] = 0;
$GLOBALS['t_pass'] = 0;

function tcase(string $name, callable $fn): void
{
    $GLOBALS['t_total']++;

    try {
        $fn();
    } catch (\Throwable $exc) {
        printf("FAIL %s —— %s: %s\n", $name, get_class($exc), $exc->getMessage());

        return;
    }

    $GLOBALS['t_pass']++;
}

function same(mixed $want, mixed $got, string $what): void
{
    if ($want !== $got) {
        throw new \RuntimeException(
            $what . ' 期望=' . var_export($want, true) . ' 实际=' . var_export($got, true)
        );
    }
}

function throws_quota(string $what, callable $fn): void
{
    try {
        $fn();
    } catch (QuotaException $exc) {
        return;
    } catch (\Throwable $exc) {
        throw new \RuntimeException(
            $what . ' 应抛 QuotaException，实际 ' . get_class($exc) . ': ' . $exc->getMessage()
        );
    }

    throw new \RuntimeException($what . ' 应抛 QuotaException，实际正常返回');
}

tcase('初始三个量为容量 / 0 / 0', static function (): void {
    $l = new QuotaLedger(['a' => 100], new FixedClock(0));

    same(100, $l->available('a'), 'available');
    same(0, $l->reserved('a'), 'reserved');
    same(0, $l->used('a'), 'used');
});

tcase('reserve 减少可用、增加预留', static function (): void {
    $l = new QuotaLedger(['a' => 100], new FixedClock(0));
    $l->reserve('t1', 'a', 30);

    same(70, $l->available('a'), 'available');
    same(30, $l->reserved('a'), 'reserved');
    same(0, $l->used('a'), 'used 不变');
});

tcase('commit 把预留转入已用', static function (): void {
    $l = new QuotaLedger(['a' => 100], new FixedClock(0));
    $l->reserve('t1', 'a', 30);
    $l->commit('t1');

    same(70, $l->available('a'), 'available 不变');
    same(0, $l->reserved('a'), '预留归零');
    same(30, $l->used('a'), '已用等于交易额');
});

tcase('未知桶 reserve 抛错', static function (): void {
    $l = new QuotaLedger(['a' => 100], new FixedClock(0));

    throws_quota('reserve 未知桶', static fn () => $l->reserve('t1', 'z', 10));
});

tcase('amount 非正抛错', static function (): void {
    $l = new QuotaLedger(['a' => 100], new FixedClock(0));

    throws_quota('amount = 0', static fn () => $l->reserve('t1', 'a', 0));
    throws_quota('amount < 0', static fn () => $l->reserve('t2', 'a', -5));
});

tcase('txnId 重复抛错', static function (): void {
    $l = new QuotaLedger(['a' => 100], new FixedClock(0));
    $l->reserve('t1', 'a', 10);

    throws_quota('重复 txnId', static fn () => $l->reserve('t1', 'a', 10));
});

tcase('超容量 reserve 抛错', static function (): void {
    $l = new QuotaLedger(['a' => 100], new FixedClock(0));
    $l->reserve('t1', 'a', 70);

    throws_quota('超过可用量', static fn () => $l->reserve('t2', 'a', 40));
});

tcase('可用量刚好够时可以 reserve', static function (): void {
    $l = new QuotaLedger(['a' => 100], new FixedClock(0));
    $l->reserve('t1', 'a', 70);
    $l->reserve('t2', 'a', 30);

    same(0, $l->available('a'), '刚好占满');
});

tcase('commit 未知交易抛错', static function (): void {
    $l = new QuotaLedger(['a' => 100], new FixedClock(0));

    throws_quota('commit 未知', static fn () => $l->commit('nope'));
});

tcase('重复 commit 抛错', static function (): void {
    $l = new QuotaLedger(['a' => 100], new FixedClock(0));
    $l->reserve('t1', 'a', 10);
    $l->commit('t1');

    throws_quota('重复 commit', static fn () => $l->commit('t1'));
});

tcase('多桶独立记账', static function (): void {
    $l = new QuotaLedger(['a' => 100, 'b' => 50], new FixedClock(0));
    $l->reserve('t1', 'a', 40);
    $l->reserve('t2', 'b', 20);
    $l->commit('t1');

    same(60, $l->available('a'), 'a 可用');
    same(30, $l->available('b'), 'b 可用');
    same(40, $l->used('a'), 'a 已用');
    same(0, $l->used('b'), 'b 已用');
});

tcase('未 commit 的预留不影响 used', static function (): void {
    $l = new QuotaLedger(['a' => 100], new FixedClock(0));
    $l->reserve('t1', 'a', 25);
    $l->reserve('t2', 'a', 15);

    same(60, $l->available('a'), '可用');
    same(40, $l->reserved('a'), '预留合计');
    same(0, $l->used('a'), '已用仍为 0');
});

printf("通过 %d/%d\n", $GLOBALS['t_pass'], $GLOBALS['t_total']);

exit($GLOBALS['t_pass'] === $GLOBALS['t_total'] ? 0 : 1);