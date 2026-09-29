<?php

declare(strict_types=1);

/**
 * 极简 PSR-4 自动加载：`Quotaacct\Foo` → `src/Foo.php`。
 * 不引入 composer，只用标准库。
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Quotaacct\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});