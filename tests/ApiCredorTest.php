<?php

declare(strict_types=1);

namespace Autoatendimento\Tests;

use Autoatendimento\Credor\ApiCredor;
use Autoatendimento\Credor\ErroCredor;
use Autoatendimento\Tests\Support\Ambiente;
use Autoatendimento\Tests\Support\CpfsDeTeste;
use PHPUnit\Framework\TestCase;

/** O cliente HTTP do portal falando com o simulador do credor, no mesmo processo. */
final class ApiCredorTest extends TestCase
{
    private Ambiente $ambiente;
    private ApiCredor $credor;

    protected function setUp(): void
    {
        $this->ambiente = new Ambiente();
        $this->credor = $this->ambiente->credor;
    }

    public function testDevedorComTelefonesMascarados(): void
    {
        $devedor = $this->credor->devedor(CpfsDeTeste::COM_DIVIDA);

        self::assertNotNull($devedor);
        self::assertNotSame('', $devedor['nome']);
        self::assertNotEmpty($devedor['telefones']);
        foreach ($devedor['telefones'] as $telefone) {
            self::assertMatchesRegularExpression('/^\(\d{2}\) (9\*{4}|\*{4})-\d{4}$/', $telefone['mascara']);
            self::assertStringEndsWith(substr($telefone['numero'], -4), $telefone['mascara']);
        }
    }

    public function testContratosEmCentavos(): void
    {
        $contratos = $this->credor->contratos(CpfsDeTeste::COM_DIVIDA);
        self::assertCount(3, $contratos);
        foreach ($contratos as $contrato) {
            self::assertMatchesRegularExpression('/^ACM\d{8}$/', $contrato['contrato']);
            self::assertIsInt($contrato['valor']);
            self::assertGreaterThan(0, $contrato['valor']);
            self::assertFalse($contrato['em_acordo']);
        }
        self::assertSame([], $this->credor->contratos(CpfsDeTeste::SEM_DIVIDA));
    }

    public function testPropostasViramParcelasEDesconto(): void
    {
        $contrato = $this->credor->contratos(CpfsDeTeste::COM_DIVIDA)[0]['contrato'];
        $propostas = $this->credor->propostas(CpfsDeTeste::COM_DIVIDA, $contrato);

        self::assertSame([1, 3, 6, 10], array_column($propostas['opcoes'], 'parcelas'));
        $aVista = $propostas['opcoes'][0];
        self::assertSame($aVista['valor_parcela'], $aVista['total']);
        self::assertSame($propostas['valor_divida'] - $aVista['total'], $aVista['desconto']);
        self::assertGreaterThan($propostas['opcoes'][3]['desconto'], $aVista['desconto'], 'à vista tem o maior desconto');
        self::assertCount(5, $propostas['datas']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $propostas['datas'][0]);
    }

    public function testFechaAcordoUmaVezSoEGeraBoleto(): void
    {
        $cpf = CpfsDeTeste::COM_DIVIDA;
        $contrato = $this->credor->contratos($cpf)[0]['contrato'];
        $propostas = $this->credor->propostas($cpf, $contrato);
        $opcao = $propostas['opcoes'][1];

        $acordoId = $this->credor->fecharAcordo($cpf, $contrato, $opcao['id'], $propostas['datas'][0]);
        self::assertNotSame('', $acordoId);

        $acordo = $this->credor->acordo($cpf, $contrato);
        self::assertNotNull($acordo);
        self::assertSame($acordoId, $acordo['id']);
        self::assertCount($opcao['parcelas'], $acordo['parcelas']);
        self::assertSame($opcao['valor_parcela'], $acordo['parcelas'][0]['valor']);
        self::assertTrue($this->credor->contratos($cpf)[0]['em_acordo']);

        $boleto = $this->credor->boleto($acordo['parcelas'][0]['id']);
        self::assertNotNull($boleto);
        self::assertStringStartsWith('%PDF', $boleto['pdf']);
        self::assertSame(47, strlen($boleto['linha']));

        try {
            $this->credor->fecharAcordo($cpf, $contrato, $opcao['id'], $propostas['datas'][0]);
            self::fail('o credor deveria recusar o segundo acordo');
        } catch (ErroCredor $erro) {
            self::assertSame(ErroCredor::ACORDO_EXISTENTE, $erro->getCode());
        }
    }

    public function testRenovaOTokenQuandoOCredorRecusa(): void
    {
        $this->credor->contratos(CpfsDeTeste::COM_DIVIDA);
        $this->ambiente->transporte->falhas = [401];
        $this->ambiente->transporte->chamadas = [];

        self::assertCount(3, $this->credor->contratos(CpfsDeTeste::COM_DIVIDA));
        self::assertSame(
            ['/v1/devedores/' . CpfsDeTeste::COM_DIVIDA . '/contratos', '/oauth/token', '/v1/devedores/' . CpfsDeTeste::COM_DIVIDA . '/contratos'],
            array_column($this->ambiente->transporte->chamadas, 'caminho'),
        );
    }

    public function testTentaDeNovoEmFalhaTemporaria(): void
    {
        $this->credor->contratos(CpfsDeTeste::COM_DIVIDA);
        $this->ambiente->transporte->falhas = [503, 0];
        self::assertCount(3, $this->credor->contratos(CpfsDeTeste::COM_DIVIDA));
    }

    public function testDesisteDepoisDeTresFalhas(): void
    {
        $this->ambiente->transporte->falhas = [0, 0, 0, 0, 0, 0];
        $this->expectException(ErroCredor::class);
        $this->expectExceptionCode(ErroCredor::INDISPONIVEL);
        $this->credor->contratos(CpfsDeTeste::COM_DIVIDA);
    }

    public function testLeituraDasDescricoesDeProposta(): void
    {
        self::assertSame([1, 90000], ApiCredor::lerDescricaoProposta('À vista de R$ 900,00'));
        self::assertSame([6, 123456], ApiCredor::lerDescricaoProposta('6x de R$ 1.234,56'));
        self::assertSame([0, 0], ApiCredor::lerDescricaoProposta('Proposta especial'));
        self::assertSame(123457, ApiCredor::centavos(1234.567));
        self::assertSame(10, ApiCredor::centavos('0.1'));
    }
}
