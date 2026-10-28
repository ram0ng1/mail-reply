<?php

/*
 * This file is part of ramon/mail-reply.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Ramon\MailReply\Tests\unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Toda chave de tradução precisa existir em en.yml E em pt-BR.yml — chave
 * esquecida num idioma renderiza a chave crua (ou o fallback em inglês).
 */
final class LocaleParityTest extends TestCase
{
    private const LOCALES = ['en', 'pt-BR'];

    /**
     * @return list<string>
     */
    private function keysOf(string $locale): array
    {
        $path = dirname(__DIR__, 2)."/locale/$locale.yml";
        $keys = $this->flatten((array) Yaml::parseFile($path));
        sort($keys);

        return $keys;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return list<string>
     */
    private function flatten(array $data, string $prefix = ''): array
    {
        $keys = [];
        foreach ($data as $key => $value) {
            $dotted = $prefix === '' ? (string) $key : "$prefix.$key";
            if (is_array($value)) {
                $keys = array_merge($keys, $this->flatten($value, $dotted));
            } else {
                $keys[] = $dotted;
            }
        }

        return $keys;
    }

    public function test_every_locale_file_parses_to_keys_under_the_extension_namespace(): void
    {
        foreach (self::LOCALES as $locale) {
            $keys = $this->keysOf($locale);

            self::assertNotEmpty($keys, "$locale.yml parsed to zero keys");
            foreach ($keys as $key) {
                self::assertStringStartsWith('ramon-mail-reply.', $key, "$locale.yml key outside the extension namespace");
            }
        }
    }

    public function test_pt_br_has_every_key_from_en(): void
    {
        $missing = array_values(array_diff($this->keysOf('en'), $this->keysOf('pt-BR')));

        self::assertSame([], $missing, "pt-BR.yml is missing these keys:\n  - ".implode("\n  - ", $missing));
    }

    public function test_en_has_every_key_from_pt_br(): void
    {
        $extra = array_values(array_diff($this->keysOf('pt-BR'), $this->keysOf('en')));

        self::assertSame([], $extra, "pt-BR.yml has keys not present in en.yml:\n  - ".implode("\n  - ", $extra));
    }
}
