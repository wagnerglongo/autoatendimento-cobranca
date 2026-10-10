<?php

declare(strict_types=1);

namespace Autoatendimento\Tests\Support;

use Autoatendimento\Credor\Transporte;
use CredorSimulado\Simulador;

/**
 * Liga o cliente da API direto no simulador do credor, no mesmo processo.
 * Os testes passam pelo mesmo código HTTP do portal (montagem de URL, token,
 * JSON) sem precisar de rede. Também permite injetar falhas.
 */
final class TransporteSimulador implements Transporte
{
    /** @var list<array{metodo: string, caminho: string}> */
    public array $chamadas = [];
    /** @var list<int> Status HTTP a devolver nas próximas chamadas, antes de chegar ao simulador */
    public array $falhas = [];

    public function __construct(public readonly Simulador $simulador)
    {
    }

    public function enviar(string $metodo, string $url, array $cabecalhos, string $corpo): array
    {
        $caminho = (string) parse_url($url, PHP_URL_PATH);
        $this->chamadas[] = ['metodo' => $metodo, 'caminho' => $caminho];

        if ($this->falhas !== []) {
            $status = array_shift($this->falhas);
            if ($status === 0) {
                throw new \RuntimeException('conexão recusada');
            }
            return ['status' => $status, 'cabecalhos' => [], 'corpo' => '{"Message":"falha simulada"}'];
        }

        [$status, $resposta] = $this->simulador->atender($metodo, $caminho, $corpo, $cabecalhos['Content-Type'] ?? '', $cabecalhos['Authorization'] ?? '');
        return ['status' => $status, 'cabecalhos' => [], 'corpo' => (string) json_encode($resposta, JSON_UNESCAPED_UNICODE)];
    }
}
