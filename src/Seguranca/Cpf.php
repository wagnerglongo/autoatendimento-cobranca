<?php

declare(strict_types=1);

namespace Autoatendimento\Seguranca;

/** Validação, máscara e pseudonimização de CPF. */
final class Cpf
{
    public static function limpar(string $cpf): string
    {
        return preg_replace('/\D+/', '', $cpf) ?? '';
    }

    public static function valido(string $cpf): bool
    {
        $cpf = self::limpar($cpf);
        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf) === 1) {
            return false;
        }
        for ($posicao = 9; $posicao < 11; $posicao++) {
            $soma = 0;
            for ($i = 0; $i < $posicao; $i++) {
                $soma += (int) $cpf[$i] * (($posicao + 1) - $i);
            }
            $digito = ((10 * $soma) % 11) % 10;
            if ((int) $cpf[$posicao] !== $digito) {
                return false;
            }
        }
        return true;
    }

    /** "52998224725" para "529.***.***-25": o suficiente para a pessoa reconhecer o próprio CPF. */
    public static function mascarar(string $cpf): string
    {
        $cpf = self::limpar($cpf);
        return strlen($cpf) === 11 ? substr($cpf, 0, 3) . '.***.***-' . substr($cpf, 9, 2) : '***';
    }

    /** Identificador estável para logs e limites, sem guardar o CPF em claro (LGPD). */
    public static function hash(string $cpf, string $segredo): string
    {
        return hash_hmac('sha256', 'cpf:' . self::limpar($cpf), $segredo);
    }
}
