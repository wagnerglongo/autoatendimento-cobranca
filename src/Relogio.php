<?php

declare(strict_types=1);

namespace Autoatendimento;

/** Hora atual injetável, para os testes controlarem expiração e janelas de tempo. */
class Relogio
{
    public function agora(): int
    {
        return time();
    }

    public function hoje(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . $this->agora()))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }
}
