<?php

declare(strict_types=1);

namespace Quotaacct;

/**
 * 按桶记账的配额账本。
 *
 * 每笔交易（`txnId`）针对一个桶（`bucket`）扣减 `amount`：
 * 先 `reserve` 占住预留，`commit` 后从预留转入已用。
 *
 * 对外契约见 README「对外契约」的 9 条。
 * `reserve` / `commit` 是既有能力（已实现）；`release` / `refund` 是本次要补的一层，现在抛错。
 */
final class QuotaLedger
{
    /** 未 commit 的预留超过这么多秒即视为过期，操作前自动回收。 */
    public const TTL = 300;

    /** @var array<string, int> */
    private array $capacities = [];

    /** @var array<string, int> */
    private array $used = [];

    /** @var array<string, int> */
    private array $reserved = [];

    /** @var array<string, array{bucket: string, amount: int, state: string, at: int}> */
    private array $txns = [];

    private Clock $clock;

    /**
     * @param array<string, int> $capacities 桶名 → 容量
     */
    public function __construct(array $capacities, Clock $clock)
    {
        $this->clock = $clock;

        foreach ($capacities as $bucket => $capacity) {
            if (!is_string($bucket) || $bucket === '' || !is_int($capacity) || $capacity < 0) {
                throw new QuotaException('非法容量配置');
            }

            $this->capacities[$bucket] = $capacity;
            $this->used[$bucket] = 0;
            $this->reserved[$bucket] = 0;
        }
    }

    /** 为 `$txnId` 在 `$bucket` 上预留 `$amount`。 */
    public function reserve(string $txnId, string $bucket, int $amount): void
    {
        if ($txnId === '') {
            throw new QuotaException('txnId 不能为空');
        }
        if (!array_key_exists($bucket, $this->capacities)) {
            throw new QuotaException('未知桶：' . $bucket);
        }
        if ($amount <= 0) {
            throw new QuotaException('amount 必须为正');
        }
        if (array_key_exists($txnId, $this->txns)) {
            throw new QuotaException('txnId 重复：' . $txnId);
        }
        if ($this->available($bucket) < $amount) {
            throw new QuotaException('配额不足：' . $bucket);
        }

        $this->reserved[$bucket] += $amount;
        $this->txns[$txnId] = [
            'bucket' => $bucket,
            'amount' => $amount,
            'state' => 'reserved',
            'at' => $this->clock->now(),
        ];
    }

    /** 把 `$txnId` 的预留转为已用。 */
    public function commit(string $txnId): void
    {
        $txn = $this->txns[$txnId] ?? null;

        if ($txn === null || $txn['state'] !== 'reserved') {
            throw new QuotaException('无法 commit：' . $txnId);
        }

        $this->reserved[$txn['bucket']] -= $txn['amount'];
        $this->used[$txn['bucket']] += $txn['amount'];
        $this->txns[$txnId]['state'] = 'committed';
    }

    /** 释放一笔尚未 commit 的预留。（本次要补的一层） */
    public function release(string $txnId): void
    {
        throw new \LogicException('not implemented');
    }

    /** 退回一笔已 commit 的交易。（本次要补的一层） */
    public function refund(string $txnId): void
    {
        throw new \LogicException('not implemented');
    }

    /** 该桶当前已用。 */
    public function used(string $bucket): int
    {
        $this->requireBucket($bucket);

        return $this->used[$bucket];
    }

    /** 该桶当前预留（未 commit）。 */
    public function reserved(string $bucket): int
    {
        $this->requireBucket($bucket);

        return $this->reserved[$bucket];
    }

    /** 该桶当前可用（容量 − 已用 − 预留）。 */
    public function available(string $bucket): int
    {
        $this->requireBucket($bucket);

        return $this->capacities[$bucket] - $this->used[$bucket] - $this->reserved[$bucket];
    }

    private function requireBucket(string $bucket): void
    {
        if (!array_key_exists($bucket, $this->capacities)) {
            throw new QuotaException('未知桶：' . $bucket);
        }
    }
}