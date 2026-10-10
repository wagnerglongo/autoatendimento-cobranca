<?php

declare(strict_types=1);

namespace Autoatendimento\Tests\Support;

use Autoatendimento\App;
use Autoatendimento\Config;
use Autoatendimento\Credor\ApiCredor;
use Autoatendimento\Database;
use Autoatendimento\Relogio;
use Autoatendimento\Sms\SmsEmArquivo;
use CredorSimulado\Simulador;
use PDO;

/** Monta o portal inteiro em memória: SQLite, simulador do credor, SMS falso e relógio controlado. */
final class Ambiente
{
    public const SEGREDO = 'segredo-de-teste-com-mais-de-32-caracteres';

    public PDO $pdo;
    public RelogioFixo $relogio;
    public TransporteSimulador $transporte;
    public SmsEmArquivo $sms;
    public ApiCredor $credor;
    public App $app;
    /** @var array<string, mixed> */
    public array $sessao = [];
    public string $estado;

    /** @param array<string, string> $config */
    public function __construct(array $config = [])
    {
        $this->pdo = Database::conectar('sqlite::memory:');
        Database::migrar($this->pdo);
        $this->relogio = new RelogioFixo(strtotime('2026-10-14 10:00:00'));
        $this->estado = tempnam(sys_get_temp_dir(), 'credor-') ?: throw new \RuntimeException('tempnam');
        file_put_contents($this->estado, '{}');
        $this->transporte = new TransporteSimulador(new Simulador($this->estado, 'demo', 'demo', new \DateTimeImmutable('2026-10-14')));
        $this->credor = new ApiCredor($this->transporte, $this->pdo, 'http://credor.test', 'demo', 'demo', $this->relogio, static function (): void {
        });
        $this->sms = new SmsEmArquivo();
        $this->app = new App(
            $this->pdo,
            Config::doAmbiente($config + ['APP_SEGREDO' => self::SEGREDO, 'APP_NOME' => 'Portal Teste']),
            $this->credor,
            $this->sms,
            $this->sessao,
            dirname(__DIR__, 2) . '/templates',
            $this->relogio,
            static function (): void {
            },
        );
    }

    public function __destruct()
    {
        @unlink($this->estado);
    }

    /** @param array<string, mixed> $post */
    public function post(string $caminho, array $post = [], string $ip = '10.0.0.1'): \Autoatendimento\Resposta
    {
        return $this->app->handle('POST', $caminho, [], $post + ['_csrf' => $this->sessao['_csrf'] ?? ''], $ip);
    }

    /** @param array<string, mixed> $query */
    public function get(string $caminho, array $query = []): \Autoatendimento\Resposta
    {
        return $this->app->handle('GET', $caminho, $query);
    }

    /** Faz o fluxo inteiro até a área logada e devolve o CPF usado. */
    public function entrar(string $cpf = CpfsDeTeste::COM_DIVIDA): string
    {
        $this->get('/');
        $this->post('/entrar', ['cpf' => $cpf]);
        $telefone = $this->sessao['pre']['telefones'][0]['id'];
        $this->post('/telefone', ['telefone' => $telefone]);
        $this->post('/codigo', ['codigo' => $this->ultimoCodigo()]);
        return $cpf;
    }

    public function ultimoCodigo(): string
    {
        $ultima = end($this->sms->enviados);
        preg_match('/\b(\d{6})\b/', $ultima['mensagem'] ?? '', $m);
        return $m[1] ?? '';
    }
}
