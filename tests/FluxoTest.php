<?php

declare(strict_types=1);

namespace Autoatendimento\Tests;

use Autoatendimento\Seguranca\Cpf;
use Autoatendimento\Seguranca\LinkAssinado;
use Autoatendimento\Tests\Support\Ambiente;
use Autoatendimento\Tests\Support\CpfsDeTeste;
use PHPUnit\Framework\TestCase;

/** O portal de ponta a ponta, como a pessoa usa: rotas, sessão, SMS, credor e banco. */
final class FluxoTest extends TestCase
{
    private Ambiente $a;

    protected function setUp(): void
    {
        $this->a = new Ambiente();
    }

    public function testPaginaInicialPedeOCpf(): void
    {
        $resposta = $this->a->get('/');
        self::assertSame(200, $resposta->status);
        self::assertStringContainsString('name="cpf"', $resposta->corpo);
        self::assertStringContainsString('name="_csrf"', $resposta->corpo);
    }

    public function testCpfInvalidoNaoConsultaOCredor(): void
    {
        $this->a->get('/');
        $resposta = $this->a->post('/entrar', ['cpf' => '111.444.777-00']);
        self::assertSame(422, $resposta->status);
        self::assertStringContainsString('Confira o CPF', $resposta->corpo);
        self::assertSame([], $this->a->transporte->chamadas);
    }

    public function testPostSemCsrfERecusado(): void
    {
        $this->a->get('/');
        $resposta = $this->a->app->handle('POST', '/entrar', [], ['cpf' => CpfsDeTeste::COM_DIVIDA]);
        self::assertSame(419, $resposta->status);
    }

    public function testCpfSemDividaMostraMensagemNeutra(): void
    {
        $this->a->get('/');
        $resposta = $this->a->post('/entrar', ['cpf' => CpfsDeTeste::SEM_DIVIDA]);
        self::assertSame(200, $resposta->status);
        self::assertStringContainsString('Não encontramos pendências', $resposta->corpo);
        self::assertArrayNotHasKey('pre', $this->a->sessao);
    }

    public function testFluxoCompletoAteOBoleto(): void
    {
        $cpf = CpfsDeTeste::COM_DIVIDA;

        // 1. CPF
        $this->a->get('/');
        $resposta = $this->a->post('/entrar', ['cpf' => '111.444.777-35']);
        self::assertSame('/telefone', $resposta->cabecalhos['Location']);

        // 2. Telefone: a tela mostra só a máscara, nunca o número inteiro
        $tela = $this->a->get('/telefone')->corpo;
        $telefone = $this->a->sessao['pre']['telefones'][0];
        self::assertStringContainsString($telefone['mascara'], $tela);
        self::assertStringNotContainsString($telefone['numero'], $tela);
        self::assertStringNotContainsString($cpf, $tela, 'o CPF aparece mascarado');

        $resposta = $this->a->post('/telefone', ['telefone' => $telefone['id']]);
        self::assertSame('/codigo', $resposta->cabecalhos['Location']);
        self::assertCount(1, $this->a->sms->enviados);
        self::assertSame($telefone['numero'], $this->a->sms->enviados[0]['telefone']);

        // 3. Código
        $resposta = $this->a->post('/codigo', ['codigo' => $this->a->ultimoCodigo()]);
        self::assertSame('/inicio', $resposta->cabecalhos['Location']);
        self::assertSame($cpf, $this->a->sessao['cpf']);
        self::assertArrayNotHasKey('pre', $this->a->sessao);

        // 4. Contratos
        $inicio = $this->a->get('/inicio');
        self::assertSame(200, $inicio->status);
        $contrato = $this->a->credor->contratos($cpf)[0]['contrato'];
        self::assertStringContainsString($contrato, $inicio->corpo);

        // 5. Propostas, revisão e confirmação
        $negociar = $this->a->get('/contratos/' . $contrato);
        self::assertStringContainsString('Você economiza', $negociar->corpo);
        $propostas = $this->a->credor->propostas($cpf, $contrato);
        $revisao = $this->a->post("/contratos/{$contrato}/revisar", ['opcao' => $propostas['opcoes'][2]['id'], 'data' => $propostas['datas'][1]]);
        self::assertSame(200, $revisao->status);
        self::assertStringContainsString('Confira o acordo', $revisao->corpo);
        preg_match('/name="chave" value="([a-f0-9]{32})"/', $revisao->corpo, $m);

        $resposta = $this->a->post("/contratos/{$contrato}/acordo", ['chave' => $m[1]]);
        self::assertSame("/contratos/{$contrato}", $resposta->cabecalhos['Location']);

        // 6. Acordo e boletos
        $acordo = $this->a->get("/contratos/{$contrato}");
        self::assertStringContainsString('fechado', $acordo->corpo);
        self::assertSame(6, substr_count($acordo->corpo, 'Baixar boleto'));
        preg_match('#/boletos/(\d+)#', $acordo->corpo, $b);
        $boleto = $this->a->get('/boletos/' . $b[1]);
        self::assertSame(200, $boleto->status);
        self::assertSame('application/pdf', $boleto->cabecalhos['Content-Type']);
        self::assertStringStartsWith('%PDF', $boleto->corpo);

        // 7. Funil registrado sem CPF em claro
        $tipos = $this->a->pdo->query('SELECT tipo FROM eventos ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['cpf_consultado', 'codigo_enviado', 'login', 'proposta_vista', 'acordo_fechado', 'boleto_baixado'], $tipos);
        $hashes = $this->a->pdo->query('SELECT DISTINCT cpf_hash FROM eventos')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame([Cpf::hash($cpf, Ambiente::SEGREDO)], $hashes);
    }

    public function testConfirmacaoEnviadaDuasVezesNaoDuplicaOAcordo(): void
    {
        $cpf = $this->a->entrar();
        $contrato = $this->a->credor->contratos($cpf)[0]['contrato'];
        $propostas = $this->a->credor->propostas($cpf, $contrato);
        $revisao = $this->a->post("/contratos/{$contrato}/revisar", ['opcao' => $propostas['opcoes'][0]['id'], 'data' => $propostas['datas'][0]]);
        preg_match('/name="chave" value="([a-f0-9]{32})"/', $revisao->corpo, $m);

        $this->a->post("/contratos/{$contrato}/acordo", ['chave' => $m[1]]);
        $segunda = $this->a->post("/contratos/{$contrato}/acordo", ['chave' => $m[1]]);

        self::assertSame(303, $segunda->status);
        $posts = array_filter($this->a->transporte->chamadas, static fn (array $c): bool => $c['caminho'] === '/v1/acordos');
        self::assertCount(1, $posts, 'só um POST de acordo chegou ao credor');
    }

    public function testPropostaForaDaListaNaoERevisada(): void
    {
        $cpf = $this->a->entrar();
        $contrato = $this->a->credor->contratos($cpf)[0]['contrato'];
        $resposta = $this->a->post("/contratos/{$contrato}/revisar", ['opcao' => 99, 'data' => '2026-10-15']);
        self::assertSame(303, $resposta->status);
        self::assertArrayNotHasKey('confirmacoes', $this->a->sessao);
    }

    public function testNaoAbreContratoNemBoletoDeOutraPessoa(): void
    {
        // A outra pessoa fecha um acordo direto no credor.
        $outro = CpfsDeTeste::OUTRA_PESSOA;
        $contratoAlheio = $this->a->credor->contratos($outro)[0]['contrato'];
        $propostas = $this->a->credor->propostas($outro, $contratoAlheio);
        $this->a->credor->fecharAcordo($outro, $contratoAlheio, $propostas['opcoes'][0]['id'], $propostas['datas'][0]);
        $parcelaAlheia = $this->a->credor->acordo($outro, $contratoAlheio)['parcelas'][0]['id'];

        $this->a->entrar();
        self::assertSame(404, $this->a->get('/contratos/' . $contratoAlheio)->status);
        self::assertSame(404, $this->a->get('/boletos/' . $parcelaAlheia)->status);
    }

    public function testAreaLogadaExigeCodigo(): void
    {
        $this->a->get('/');
        $this->a->post('/entrar', ['cpf' => CpfsDeTeste::COM_DIVIDA]);
        $resposta = $this->a->get('/inicio');
        self::assertSame('/', $resposta->cabecalhos['Location'], 'CPF sozinho não dá acesso');
    }

    public function testCodigoErradoNaoEntra(): void
    {
        $this->a->get('/');
        $this->a->post('/entrar', ['cpf' => CpfsDeTeste::COM_DIVIDA]);
        $this->a->post('/telefone', ['telefone' => $this->a->sessao['pre']['telefones'][0]['id']]);
        $errado = $this->a->ultimoCodigo() === '000000' ? '111111' : '000000';

        $resposta = $this->a->post('/codigo', ['codigo' => $errado]);
        self::assertSame(422, $resposta->status);
        self::assertStringContainsString('Código incorreto', $resposta->corpo);
        self::assertArrayNotHasKey('cpf', $this->a->sessao);
    }

    public function testTelefoneQueNaoEDaPessoaNaoRecebeCodigo(): void
    {
        $this->a->get('/');
        $this->a->post('/entrar', ['cpf' => CpfsDeTeste::COM_DIVIDA]);
        $resposta = $this->a->post('/telefone', ['telefone' => '999']);
        self::assertSame('/telefone', $resposta->cabecalhos['Location']);
        self::assertSame([], $this->a->sms->enviados);
    }

    public function testSessaoExpiraPorInatividade(): void
    {
        $this->a->entrar();
        $this->a->relogio->avancar(16 * 60);
        $resposta = $this->a->get('/inicio');
        self::assertSame('/', $resposta->cabecalhos['Location']);
        self::assertArrayNotHasKey('cpf', $this->a->sessao);
    }

    public function testLimitaConsultasPorIp(): void
    {
        $this->a->get('/');
        for ($i = 0; $i < 20; $i++) {
            $this->a->post('/entrar', ['cpf' => '000'], '10.9.9.9');
        }
        self::assertSame(429, $this->a->post('/entrar', ['cpf' => CpfsDeTeste::COM_DIVIDA], '10.9.9.9')->status);
        self::assertSame(303, $this->a->post('/entrar', ['cpf' => CpfsDeTeste::COM_DIVIDA], '10.1.1.1')->status, 'outro IP segue normal');
    }

    public function testLinkDeCampanhaPulaODigitarDoCpf(): void
    {
        $token = (new LinkAssinado(Ambiente::SEGREDO))->gerar(CpfsDeTeste::COM_DIVIDA, $this->a->relogio->agora() + 3600);
        $resposta = $this->a->get('/', ['l' => $token]);
        self::assertSame('/telefone', $resposta->cabecalhos['Location']);
        self::assertSame(CpfsDeTeste::COM_DIVIDA, $this->a->sessao['pre']['cpf']);

        $expirado = new Ambiente();
        $tokenVelho = (new LinkAssinado(Ambiente::SEGREDO))->gerar(CpfsDeTeste::COM_DIVIDA, $expirado->relogio->agora() - 1);
        self::assertStringContainsString('Este link expirou', $expirado->get('/', ['l' => $tokenVelho])->corpo);
    }

    public function testCredorForaDoArMostraAvisoEmVezDeErro(): void
    {
        $this->a->get('/');
        $this->a->transporte->falhas = [0, 0, 0, 0, 0, 0, 0, 0, 0];
        $resposta = $this->a->post('/entrar', ['cpf' => CpfsDeTeste::COM_DIVIDA]);
        self::assertSame(503, $resposta->status);
        self::assertStringContainsString('temporariamente indisponível', $resposta->corpo);
    }

    public function testModoDemonstracaoMostraOCodigoNaTela(): void
    {
        $demo = new Ambiente(['MODO_DEMO' => '1']);
        $demo->get('/');
        $demo->post('/entrar', ['cpf' => CpfsDeTeste::COM_DIVIDA]);
        $demo->post('/telefone', ['telefone' => $demo->sessao['pre']['telefones'][0]['id']]);
        self::assertStringContainsString('Use o código ' . $demo->ultimoCodigo(), $demo->get('/codigo')->corpo);
    }

    public function testAtualizacaoDeContatoValidaEEnviaAoCredor(): void
    {
        $this->a->entrar();
        $this->a->post('/contatos', ['tipo' => 'email', 'valor' => 'nao-e-email']);
        self::assertStringContainsString('Confira o e-mail', $this->a->get('/inicio')->corpo);

        $this->a->post('/contatos', ['tipo' => 'telefone', 'valor' => '(11) 98765-4321']);
        self::assertStringContainsString('Recebemos seu telefone', $this->a->get('/inicio')->corpo);
        $telefones = $this->a->credor->devedor(CpfsDeTeste::COM_DIVIDA)['telefones'];
        self::assertContains('11987654321', array_column($telefones, 'numero'));
    }

    public function testSairEncerraASessao(): void
    {
        $this->a->entrar();
        $this->a->post('/sair');
        self::assertArrayNotHasKey('cpf', $this->a->sessao);
        self::assertSame('/', $this->a->get('/inicio')->cabecalhos['Location']);
    }

    public function testPainelExigeSenhaEMostraOFunil(): void
    {
        $painel = new Ambiente(['PAINEL_SENHA_HASH' => password_hash('senha-forte-do-painel', PASSWORD_DEFAULT)]);
        $painel->entrar();
        self::assertStringContainsString('Senha do painel', $painel->get('/painel')->corpo);
        self::assertSame(422, $painel->post('/painel', ['senha' => 'errada'])->status);

        $painel->post('/painel', ['senha' => 'senha-forte-do-painel']);
        $tela = $painel->get('/painel')->corpo;
        self::assertStringContainsString('Funil do autoatendimento', $tela);
        self::assertStringContainsString('Confirmaram o código', $tela);
        self::assertStringNotContainsString(CpfsDeTeste::COM_DIVIDA, $tela);
    }

    public function testRotaDesconhecidaDa404(): void
    {
        self::assertSame(404, $this->a->get('/nao-existe')->status);
    }
}
