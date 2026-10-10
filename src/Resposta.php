<?php

declare(strict_types=1);

namespace Autoatendimento;

final class Resposta
{
    /** @param array<string, string> $cabecalhos */
    public function __construct(
        public readonly int $status,
        public readonly string $corpo,
        public readonly array $cabecalhos = [],
    ) {
    }

    public static function html(string $corpo, int $status = 200): self
    {
        return new self($status, $corpo, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function redirecionar(string $destino): self
    {
        return new self(303, '', ['Location' => $destino]);
    }

    public static function pdf(string $conteudo, string $arquivo): self
    {
        return new self(200, $conteudo, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $arquivo . '"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
