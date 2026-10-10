<?php

declare(strict_types=1);

namespace Autoatendimento\Credor;

/**
 * Envio HTTP isolado atrás de uma interface: em produção é cURL; nos testes,
 * o próprio simulador do credor rodando no mesmo processo.
 */
interface Transporte
{
    /**
     * @param array<string, string> $cabecalhos
     * @return array{status: int, cabecalhos: array<string, string>, corpo: string}
     * @throws \RuntimeException quando não há resposta (rede, timeout)
     */
    public function enviar(string $metodo, string $url, array $cabecalhos, string $corpo): array;
}
