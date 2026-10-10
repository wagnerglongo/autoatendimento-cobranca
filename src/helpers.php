<?php

declare(strict_types=1);

/** Escapa texto para HTML. Todo dado dinâmico nos templates passa por aqui. */
function e(mixed $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Centavos inteiros para "R$ 1.234,56". */
function moeda(int $centavos): string
{
    $sinal = $centavos < 0 ? '-' : '';
    $centavos = abs($centavos);
    return $sinal . 'R$ ' . number_format(intdiv($centavos, 100), 0, ',', '.') . ',' . str_pad((string) ($centavos % 100), 2, '0', STR_PAD_LEFT);
}

/** "2026-10-14" para "14/10/2026". */
function data_br(string $iso): string
{
    $data = DateTimeImmutable::createFromFormat('!Y-m-d', substr($iso, 0, 10));
    return $data ? $data->format('d/m/Y') : $iso;
}
