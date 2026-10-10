<?php

declare(strict_types=1);

namespace Autoatendimento\Tests;

use Autoatendimento\Database;
use Autoatendimento\Seguranca\CodigoVerificacao;
use Autoatendimento\Seguranca\Cpf;
use Autoatendimento\Seguranca\Csrf;
use Autoatendimento\Seguranca\LimiteTentativas;
use Autoatendimento\Seguranca\LinkAssinado;
use Autoatendimento\Tests\Support\RelogioFixo;
use PDO;
use PHPUnit\Framework\TestCase;

final class SegurancaTest extends TestCase
{
    private PDO $pdo;
    private RelogioFixo $relogio;

    protected function setUp(): void
    {
        $this->pdo = Database::conectar('sqlite::memory:');
        Database::migrar($this->pdo);
        $this->relogio = new RelogioFixo(1_800_000_000);
    }

    public function testValidaCpfPelosDigitosVerificadores(): void
    {
        foreach (['111.444.777-35', '52998224725', '10000000604'] as $valido) {
            self::assertTrue(Cpf::valido($valido), $valido);
        }
        foreach (['111.444.777-36', '11111111111', '123', '', '529982247251'] as $invalido) {
            self::assertFalse(Cpf::valido($invalido), $invalido);
        }
    }

    public function testMascaraEHashNaoExpoemOCpf(): void
    {
        self::assertSame('111.***.***-35', Cpf::mascarar('11144477735'));
        $hash = Cpf::hash('111.444.777-35', 'segredo');
        self::assertSame($hash, Cpf::hash('11144477735', 'segredo'), 'pontuação não muda o hash');
        self::assertNotSame($hash, Cpf::hash('11144477735', 'outro-segredo'));
        self::assertStringNotContainsString('11144477735', $hash);
    }

    public function testCsrfExigeOTokenDaSessao(): void
    {
        $sessao = [];
        $token = Csrf::token($sessao);
        self::assertSame($token, Csrf::token($sessao), 'o token é estável na sessão');
        self::assertTrue(Csrf::valido($sessao, $token));
        self::assertFalse(Csrf::valido($sessao, 'outro'));
        self::assertFalse(Csrf::valido($sessao, null));
        self::assertFalse(Csrf::valido([], $token));
    }

    public function testCodigoCorretoEntraUmaVezSo(): void
    {
        $codigos = new CodigoVerificacao($this->pdo, 'segredo', $this->relogio);
        $emissao = $codigos->emitir('pessoa', '1');
        self::assertMatchesRegularExpression('/^\d{6}$/', (string) $emissao['codigo']);

        self::assertSame('ok', $codigos->verificar('pessoa', (string) $emissao['codigo']));
        self::assertSame('inexistente', $codigos->verificar('pessoa', (string) $emissao['codigo']), 'código usado não vale de novo');
    }

    public function testCodigoGuardaSoOHash(): void
    {
        $codigos = new CodigoVerificacao($this->pdo, 'segredo', $this->relogio);
        $codigo = (string) $codigos->emitir('pessoa', '1')['codigo'];
        $guardado = (string) $this->pdo->query('SELECT codigo_hash FROM codigos')->fetchColumn();
        self::assertNotSame($codigo, $guardado);
        self::assertSame(64, strlen($guardado));
    }

    public function testCodigoExpiraEmCincoMinutos(): void
    {
        $codigos = new CodigoVerificacao($this->pdo, 'segredo', $this->relogio);
        $codigo = (string) $codigos->emitir('pessoa', '1')['codigo'];
        $this->relogio->avancar(CodigoVerificacao::VALIDADE + 1);
        self::assertSame('expirado', $codigos->verificar('pessoa', $codigo));
    }

    public function testBloqueiaDepoisDeCincoErros(): void
    {
        $codigos = new CodigoVerificacao($this->pdo, 'segredo', $this->relogio);
        $codigo = (string) $codigos->emitir('pessoa', '1')['codigo'];
        $errado = $codigo === '000000' ? '111111' : '000000';

        for ($i = 1; $i < CodigoVerificacao::MAX_TENTATIVAS; $i++) {
            self::assertSame('invalido', $codigos->verificar('pessoa', $errado));
        }
        self::assertSame('bloqueado', $codigos->verificar('pessoa', $errado));
        self::assertSame('bloqueado', $codigos->verificar('pessoa', $codigo), 'nem o código certo vale depois do bloqueio');
    }

    public function testReenvioRespeitaIntervaloEInvalidaOCodigoAnterior(): void
    {
        $codigos = new CodigoVerificacao($this->pdo, 'segredo', $this->relogio);
        $primeiro = (string) $codigos->emitir('pessoa', '1')['codigo'];

        $cedo = $codigos->emitir('pessoa', '1');
        self::assertNull($cedo['codigo']);
        self::assertSame(CodigoVerificacao::INTERVALO_REENVIO, $cedo['aguardar']);

        $this->relogio->avancar(CodigoVerificacao::INTERVALO_REENVIO);
        $segundo = (string) $codigos->emitir('pessoa', '1')['codigo'];
        self::assertSame(0, $codigos->esperaParaReenvio('outra-pessoa'));
        if ($primeiro !== $segundo) {
            self::assertSame('invalido', $codigos->verificar('pessoa', $primeiro), 'o código antigo deixa de valer');
        }
        self::assertSame('ok', $codigos->verificar('pessoa', $segundo));
    }

    public function testLimiteDeTentativasPorJanela(): void
    {
        $limite = new LimiteTentativas($this->pdo, $this->relogio);
        for ($i = 0; $i < 3; $i++) {
            self::assertTrue($limite->permitir('ip:1', 3, 60));
        }
        self::assertFalse($limite->permitir('ip:1', 3, 60));
        self::assertTrue($limite->permitir('ip:2', 3, 60), 'cada chave tem seu limite');

        $this->relogio->avancar(60);
        self::assertTrue($limite->permitir('ip:1', 3, 60), 'nova janela zera a contagem');
    }

    public function testLinkDeCampanhaAbreSoNoPrazoESemAdulteracao(): void
    {
        $link = new LinkAssinado('segredo-com-mais-de-trinta-e-dois-caracteres');
        $token = $link->gerar('111.444.777-35', 2_000);

        self::assertSame('11144477735', $link->abrir($token, 1_000));
        self::assertNull($link->abrir($token, 2_001), 'expirado');
        self::assertStringNotContainsString('11144477735', $token, 'o CPF vai cifrado');

        $adulterado = substr($token, 0, -2) . (str_ends_with($token, 'AA') ? 'BB' : 'AA');
        self::assertNull($link->abrir($adulterado, 1_000));
        self::assertNull((new LinkAssinado('outro-segredo-com-mais-de-trinta-e-dois-caracteres'))->abrir($token, 1_000));
        self::assertNull($link->abrir('lixo!', 1_000));
    }
}
