# Contrato da API do credor (ACME, fictício)

O portal conversa com o sistema do credor por uma API REST com token OAuth2. Aqui o credor é a **ACME Financeira**, uma empresa fictícia, atendida pelo simulador em [`simulador/`](../simulador). É o mesmo simulador do [Pactua, o CRM de cobrança](https://github.com/wagnerglongo/crm-cobranca), com uma rota a mais: a lista de contratos do devedor.

O lado do portal está em [`src/Credor/ApiCredor.php`](../src/Credor/ApiCredor.php). Ele é a única classe que conhece o formato do credor (campos em inglês, valores em reais com ponto flutuante, datas com hora) e devolve arrays simples, com dinheiro em centavos inteiros e datas em `Y-m-d`. Para integrar um credor real, é essa classe que muda.

O teste [`tests/ApiCredorTest.php`](../tests/ApiCredorTest.php) roda o cliente de verdade contra o simulador no mesmo processo (por um `Transporte` que chama o simulador em vez da rede). Uma mudança de rota ou de payload feita só de um lado quebra o CI.

## Autenticação

`POST /oauth/token` (form `grant_type=password&username=...&password=...`) devolve `{ "access_token", "token_type", "expires_in" }`.

As demais chamadas usam `Authorization: Bearer <token>`. O portal guarda o token na tabela `cache` até um minuto antes de expirar. Se o credor responder `401`, o token é renovado uma vez e a chamada é repetida.

## Rotas usadas pelo portal

| Método | Rota | Uso |
|---|---|---|
| GET | `/v1/devedores/{cpf}` | Nome e telefones (`RecordsPhones`) para enviar o código. Telefones com status `I` (incorreto) são ignorados. |
| GET | `/v1/devedores/{cpf}/contratos` | Contratos em cobrança, com valor e situação (`Em aberto` / `Em acordo`). |
| GET | `/v1/devedores/{cpf}/contratos/{contrato}/propostas` | Valor atualizado, planos (`ListProposalType`) e datas possíveis para o 1º vencimento (`ListInstallmentDate`). |
| POST | `/v1/acordos` | Fecha o acordo: `DocNumber`, `Contract`, `NegotiationID`, `InstallmentDate`. `409` se já existe acordo ativo. |
| GET | `/v1/devedores/{cpf}/contratos/{contrato}/acordo` | Acordo vigente e parcelas; `404` quando não há. |
| GET | `/v1/parcelas/{id}/boleto` | Linha digitável e PDF em base64. |
| POST | `/v1/contatos/telefones` · `/emails` | Telefone ou e-mail informado pela pessoa, gravado como "não confirmado" (`Status: P`). |

As propostas vêm descritas em texto (`"6x de R$ 1.234,56"`, `"À vista de R$ 900,00"`), como em muitos sistemas de cobrança. O portal lê esse texto em `ApiCredor::lerDescricaoProposta()` e descarta a proposta se não conseguir interpretá-la, em vez de mostrar um valor errado.

## Falhas

| Situação | O que o portal faz |
|---|---|
| Rede fora, timeout, `5xx`, `429` | Tenta de novo até 2 vezes, com espera crescente (respeita `Retry-After`, no máximo 3 s). Se não resolver, mostra "sistema temporariamente indisponível" (HTTP 503) e registra no log. |
| `401` | Renova o token e repete uma vez. |
| `409` ao fechar acordo | Mostra "Já existe um acordo ativo para este contrato." |
| Outros `4xx` | Mostra a mensagem do credor. |

## Simulador

- Credenciais: `demo` / `demo` (variáveis `SIMULADOR_USUARIO` e `SIMULADOR_SENHA`).
- Dados determinísticos: o mesmo CPF sempre gera o mesmo devedor, os mesmos contratos e a mesma dívida. Cerca de 1 em cada 7 CPFs não tem dívida.
- Acordos e contatos gravados ficam num arquivo JSON (`SIMULADOR_ESTADO`), num volume Docker.
- Para a demonstração andar sozinha, parcela vencida há mais de dois dias passa a constar como paga.
