<?php

declare(strict_types=1);

namespace Quotaacct;

/**
 * 配额记账的统一异常：非法入参、重复交易、配额不足、状态不允许的操作等。
 */
final class QuotaException extends \RuntimeException
{
}