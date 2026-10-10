<?php

declare(strict_types=1);

namespace Autoatendimento\Sms;

/**
 * Para desenvolvimento e demonstração: em vez de enviar, grava a mensagem num
 * arquivo (storage/sms.log) e guarda a última em memória.
 */
final class SmsEmArquivo implements EnviadorSms
{
    /** @var list<array{telefone: string, mensagem: string}> */
    public array $enviados = [];

    public function __construct(private readonly ?string $arquivo = null)
    {
    }

    public function enviar(string $telefone, string $mensagem): void
    {
        $this->enviados[] = ['telefone' => $telefone, 'mensagem' => $mensagem];
        if ($this->arquivo !== null) {
            $final = substr($telefone, -4);
            file_put_contents($this->arquivo, date('c') . " | ****{$final} | {$mensagem}\n", FILE_APPEND | LOCK_EX);
        }
    }
}
