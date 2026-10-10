<?php

declare(strict_types=1);

namespace Autoatendimento\Tests\Support;

/** CPFs válidos (dígitos verificadores corretos) com comportamento conhecido no simulador. */
final class CpfsDeTeste
{
    /** Tem 3 contratos em cobrança no simulador. */
    public const COM_DIVIDA = '11144477735';
    /** Outra pessoa com dívida, para testar acesso cruzado. */
    public const OUTRA_PESSOA = '10000000108';
    /** Válido, mas sem contratos no simulador. */
    public const SEM_DIVIDA = '10000000604';
}
