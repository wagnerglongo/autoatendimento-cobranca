<?php

declare(strict_types=1);

namespace Autoatendimento\Tests\Support;

use Autoatendimento\Relogio;

/** Relógio que só anda quando o teste manda. */
final class RelogioFixo extends Relogio
{
    public function __construct(public int $instante)
    {
    }

    public function agora(): int
    {
        return $this->instante;
    }

    public function avancar(int $segundos): void
    {
        $this->instante += $segundos;
    }
}
