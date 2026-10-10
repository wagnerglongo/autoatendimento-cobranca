<?php /** @var string $csrf @var string $primeiroNome @var string $cpf @var list<array{id: string, mascara: string, tipo: string}> $telefones */ ?>
<section class="cartao">
  <h1>Olá, <?= e($primeiroNome) ?></h1>
  <p class="apoio">CPF <?= e($cpf) ?>. Escolha o telefone que está com você agora: vamos mandar um código de 6 números por SMS.</p>

  <form method="post" action="/telefone" class="formulario">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <fieldset class="opcoes-telefone">
      <legend class="sr-only">Telefone para receber o código</legend>
      <?php foreach ($telefones as $i => $telefone): ?>
        <label class="opcao-telefone">
          <input type="radio" name="telefone" value="<?= e($telefone['id']) ?>"<?= $i === 0 ? ' checked' : '' ?> required>
          <span class="numero"><?= e($telefone['mascara']) ?></span>
          <span class="tipo"><?= e($telefone['tipo']) ?></span>
        </label>
      <?php endforeach; ?>
    </fieldset>
    <button type="submit" class="botao">Enviar código por SMS</button>
  </form>
  <p class="nota">Não reconhece nenhum desses números? <a href="/">Volte</a> e confira o CPF, ou fale com a central de atendimento de <?= e($empresa) ?>.</p>
</section>
