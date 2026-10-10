<?php

declare(strict_types=1);

namespace Autoatendimento\Credor;

use RuntimeException;

final class TransporteCurl implements Transporte
{
    public function __construct(private readonly int $timeoutSegundos = 8)
    {
    }

    public function enviar(string $metodo, string $url, array $cabecalhos, string $corpo): array
    {
        $recebidos = [];
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $metodo,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(3, $this->timeoutSegundos),
            CURLOPT_TIMEOUT => $this->timeoutSegundos,
            CURLOPT_HTTPHEADER => array_map(static fn (string $k, string $v): string => "{$k}: {$v}", array_keys($cabecalhos), $cabecalhos),
            CURLOPT_HEADERFUNCTION => static function ($curl, string $linha) use (&$recebidos): int {
                if (str_contains($linha, ':')) {
                    [$nome, $valor] = explode(':', $linha, 2);
                    $recebidos[strtolower(trim($nome))] = trim($valor);
                }
                return strlen($linha);
            },
        ]);
        if ($corpo !== '') {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $corpo);
        }

        $resposta = curl_exec($curl);
        if ($resposta === false) {
            $erro = curl_error($curl);
            curl_close($curl);
            throw new RuntimeException('Falha de rede ao chamar o credor: ' . $erro);
        }
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        return ['status' => $status, 'cabecalhos' => $recebidos, 'corpo' => (string) $resposta];
    }
}
