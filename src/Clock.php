<?php

declare(strict_types=1);

namespace Quotaacct;

/**
 * 注入时钟：配额记账只认这个接口给出的时间，**不得**读墙钟。
 */
interface Clock
{
    /** 当前时刻（秒，单调不减的整数刻度）。 */
    public function now(): int;
}