<?php

declare(strict_types=1);

namespace Autoatendimento\Sms;

use Autoatendimento\Credor\Transporte;
use RuntimeException;

/**
 * Envio por um gateway HTTP genérico: POST {"to": "...", "message": "..."} com
 * Bearer token. Serve para a maioria dos provedores brasileiros com um adaptador
 * fino na frente (ou n8n/Zapier).
 */
final class SmsWebhook implements EnviadorSms
{
    public function __construct(
        private readonly Transporte $transporte,
        private readonly string $url,
        private readonly string $token,
    ) {
    }

    public function enviar(string $telefone, string $mensagem): void
    {
        $resposta = $this->transporte->enviar('POST', $this->url, [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->token,
        ], (string) json_encode(['to' => '+55' . $telefone, 'message' => $mensagem]));

        if ($resposta['status'] < 200 || $resposta['status'] >= 300) {
            throw new RuntimeException('Gateway de SMS respondeu HTTP ' . $resposta['status']);
        }
    }
}
