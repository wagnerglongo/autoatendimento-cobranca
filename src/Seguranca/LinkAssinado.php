<?php

declare(strict_types=1);

namespace Autoatendimento\Seguranca;

/**
 * Link personalizado para campanhas (SMS, e-mail, WhatsApp): leva a pessoa
 * direto para a escolha do telefone, sem digitar o CPF. O CPF vai cifrado
 * (AES-256-GCM) e com validade; o link sozinho não dá acesso a nada, porque o
 * código por SMS continua obrigatório.
 */
final class LinkAssinado
{
    private const CIFRA = 'aes-256-gcm';

    public function __construct(private readonly string $segredo)
    {
    }

    public function gerar(string $cpf, int $expiraEm): string
    {
        $chave = $this->chave();
        $iv = random_bytes(12);
        $tag = '';
        $dados = Cpf::limpar($cpf) . '|' . $expiraEm;
        $cifrado = openssl_encrypt($dados, self::CIFRA, $chave, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cifrado === false) {
            throw new \RuntimeException('Não foi possível gerar o link.');
        }
        return self::base64url($iv . $tag . $cifrado);
    }

    /** Devolve o CPF se o token for autêntico e estiver no prazo. */
    public function abrir(string $token, int $agora): ?string
    {
        $bruto = self::debase64url($token);
        if ($bruto === null || strlen($bruto) < 29) {
            return null;
        }
        $dados = openssl_decrypt(substr($bruto, 28), self::CIFRA, $this->chave(), OPENSSL_RAW_DATA, substr($bruto, 0, 12), substr($bruto, 12, 16));
        if ($dados === false || !str_contains($dados, '|')) {
            return null;
        }
        [$cpf, $expira] = explode('|', $dados, 2);
        return ((int) $expira >= $agora && Cpf::valido($cpf)) ? $cpf : null;
    }

    private function chave(): string
    {
        return hash_hmac('sha256', 'link-campanha', $this->segredo, true);
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function debase64url(string $texto): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $texto) !== 1) {
            return null;
        }
        $bytes = base64_decode(strtr($texto, '-_', '+/'), true);
        return $bytes === false ? null : $bytes;
    }
}
