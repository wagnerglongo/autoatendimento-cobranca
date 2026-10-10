<?php

declare(strict_types=1);

namespace Autoatendimento\Seguranca;

/** Token sincronizado por sessão, comparado em tempo constante. */
final class Csrf
{
    /** @param array<string, mixed> $sessao */
    public static function token(array &$sessao): string
    {
        if (empty($sessao['_csrf']) || !is_string($sessao['_csrf'])) {
            $sessao['_csrf'] = bin2hex(random_bytes(32));
        }
        return $sessao['_csrf'];
    }

    /** @param array<string, mixed> $sessao */
    public static function valido(array $sessao, mixed $token): bool
    {
        return is_string($token)
            && isset($sessao['_csrf'])
            && is_string($sessao['_csrf'])
            && hash_equals($sessao['_csrf'], $token);
    }
}
