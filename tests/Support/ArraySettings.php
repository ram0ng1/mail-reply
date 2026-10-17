<?php

declare(strict_types=1);

namespace Ramon\MailReply\Tests\Support;

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Repositório de settings em memória; `writes` conta as gravações para os
 * testes de migração lazy de segredo.
 */
final class ArraySettings implements SettingsRepositoryInterface
{
    public int $writes = 0;

    /**
     * @param array<string, mixed> $values
     */
    public function __construct(public array $values = [])
    {
    }

    public function all(): array
    {
        return $this->values;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->writes++;
        $this->values[$key] = $value;
    }

    public function delete(string $keyLike): void
    {
        unset($this->values[$keyLike]);
    }
}
