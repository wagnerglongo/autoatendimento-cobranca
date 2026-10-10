<?php /** @var string $csrf @var string $primeiroNome @var int $emAberto @var list<array{contrato: string, produto: string, valor: int, desde: string, em_acordo: bool}> $contratos */ ?>
<section class="cabecalho-area">
  <h1>Olá, <?= e($primeiroNome) ?></h1>
  <?php if ($emAberto > 0): ?>
    <p class="apoio">Você tem <strong><?= moeda($emAberto) ?></strong> em aberto. Escolha um contrato para ver as propostas de pagamento.</p>
  <?php else: ?>
    <p class="apoio">Todos os seus contratos estão em acordo. Os boletos ficam disponíveis em cada um.</p>
  <?php endif; ?>
</section>

<ul class="contratos">
  <?php foreach ($contratos as $c): ?>
    <li class="contrato<?= $c['em_acordo'] ? ' em-acordo' : '' ?>">
      <div class="contrato-dados">
        <span class="produto"><?= e($c['produto']) ?></span>
        <span class="numero">Contrato <?= e($c['contrato']) ?></span>
        <span class="valor"><?= moeda($c['valor']) ?></span>
        <span class="situacao"><?= $c['em_acordo'] ? 'Em acordo' : 'Em aberto desde ' . e(data_br($c['desde'])) ?></span>
      </div>
      <a class="botao<?= $c['em_acordo'] ? ' botao-secundario' : '' ?>" href="/contratos/<?= e(rawurlencode($c['contrato'])) ?>">
        <?= $c['em_acordo'] ? 'Ver acordo e boletos' : 'Ver propostas' ?>
      </a>
    </li>
  <?php endforeach; ?>
</ul>

<section class="cartao" id="contatos">
  <h2>Atualize seus contatos</h2>
  <p class="apoio">Mudou de número ou de e-mail? Informe aqui para receber os boletos e avisos no lugar certo.</p>
  <form method="post" action="/contatos" class="formulario formulario-linha">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <label for="tipo-contato" class="sr-only">Tipo de contato</label>
    <select id="tipo-contato" name="tipo">
      <option value="telefone">Celular</option>
      <option value="email">E-mail</option>
    </select>
    <label for="valor-contato" class="sr-only">Novo contato</label>
    <input id="valor-contato" name="valor" maxlength="120" required placeholder="(11) 91234-5678 ou nome@email.com">
    <button type="submit" class="botao botao-secundario">Enviar</button>
  </form>
</section>
