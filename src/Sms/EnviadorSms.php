<?php

declare(strict_types=1);

namespace Autoatendimento\Sms;

interface EnviadorSms
{
    /** @throws \RuntimeException se o provedor recusar ou não responder */
    public function enviar(string $telefone, string $mensagem): void;
}
