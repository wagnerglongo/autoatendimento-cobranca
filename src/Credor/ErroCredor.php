<?php

declare(strict_types=1);

namespace Autoatendimento\Credor;

use RuntimeException;

/**
 * Falhas da integração com o credor, separadas pelo que o portal faz com cada uma:
 * - INDISPONIVEL: rede, timeout, 5xx, 429 persistente. A pessoa vê "tente em alguns minutos".
 * - RECUSADO: o credor recusou a operação (proposta inválida, acordo já existe). A pessoa vê a mensagem.
 */
final class ErroCredor extends RuntimeException
{
    public const INDISPONIVEL = 1;
    public const RECUSADO = 2;
    public const ACORDO_EXISTENTE = 3;

    public static function indisponivel(string $detalhe): self
    {
        return new self('O sistema do credor não respondeu: ' . $detalhe, self::INDISPONIVEL);
    }

    public static function recusado(string $mensagem, int $codigo = self::RECUSADO): self
    {
        return new self($mensagem, $codigo);
    }
}
