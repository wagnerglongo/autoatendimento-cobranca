<?php

declare(strict_types=1);

namespace Autoatendimento;

use RuntimeException;

/**
 * Configuração lida das variáveis de ambiente. Nada sensível tem valor padrão
 * em produção: sem APP_SEGREDO o portal não sobe.
 */
final class Config
{
    /** @param array<string, string> $valores */
    private function __construct(private readonly array $valores)
    {
    }

    /** @param array<string, string>|null $ambiente */
    public static function doAmbiente(?array $ambiente = null): self
    {
        $ambiente ??= getenv();
        $padroes = [
            'APP_NOME' => 'Portal de Negociação',
            'APP_EMPRESA' => 'ACME Financeira',
            'APP_SEGREDO' => '',
            'APP_URL' => 'http://localhost:8000',
            'MODO_DEMO' => '0',
            'DB_DSN' => 'sqlite:' . dirname(__DIR__) . '/storage/portal.sqlite',
            'DB_USUARIO' => '',
            'DB_SENHA' => '',
            'CREDOR_URL' => 'http://localhost:8090',
            'CREDOR_USUARIO' => 'demo',
            'CREDOR_SENHA' => 'demo',
            'CREDOR_TIMEOUT' => '8',
            'SMS_DRIVER' => 'log',
            'SMS_WEBHOOK_URL' => '',
            'SMS_WEBHOOK_TOKEN' => '',
            'PAINEL_SENHA_HASH' => '',
        ];
        $valores = [];
        foreach ($padroes as $chave => $padrao) {
            $valor = $ambiente[$chave] ?? false;
            $valores[$chave] = $valor === false || $valor === '' ? $padrao : (string) $valor;
        }
        return new self($valores);
    }

    public function texto(string $chave): string
    {
        return $this->valores[$chave] ?? throw new RuntimeException("Configuração desconhecida: {$chave}");
    }

    public function inteiro(string $chave): int
    {
        return (int) $this->texto($chave);
    }

    public function ligado(string $chave): bool
    {
        return in_array(strtolower($this->texto($chave)), ['1', 'true', 'sim', 'on'], true);
    }

    /** Chave usada para HMAC de CPFs, códigos e links. Exige 32+ caracteres. */
    public function segredo(): string
    {
        $segredo = $this->texto('APP_SEGREDO');
        if (strlen($segredo) < 32) {
            throw new RuntimeException('Defina APP_SEGREDO com pelo menos 32 caracteres (ex.: openssl rand -hex 32).');
        }
        return $segredo;
    }
}
