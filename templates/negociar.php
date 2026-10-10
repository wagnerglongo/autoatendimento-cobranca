<?php
/**
 * @var string $csrf
 * @var array{contrato: string, produto: string, valor: int, desde: string} $contrato
 * @var array{valor_divida: int, opcoes: list<array{id: int, parcelas: int, valor_parcela: int, total: int, desconto: int}>, datas: list<string>} $propostas
 * @var array{opcao: int, data: string}|null $escolha
 */
$escolhida = $escolha['opcao'] ?? ($propostas['opcoes'][0]['id'] ?? null);
?>
<p class="voltar"><a href="/inicio">Meus contratos</a></p>
<section class="cabecalho-area">
  <h1><?= e($contrato['produto']) ?></h1>
  <p class="apoio">Contrato <?= e($contrato['contrato']) ?> · dívida atualizada de <strong><?= moeda($propostas['valor_divida']) ?></strong></p>
</section>

<?php if ($propostas['opcoes'] === []): ?>
  <section class="cartao">
    <p>Não há propostas disponíveis para este contrato pelo portal. Fale com a central de atendimento de <?= e($empresa) ?>.</p>
  </section>
<?php else: ?>
<form method="post" action="/contratos/<?= e(rawurlencode($contrato['contrato'])) ?>/revisar" class="negociacao">
  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

  <fieldset>
    <legend>Como você quer pagar?</legend>
    <div class="propostas">
      <?php foreach ($propostas['opcoes'] as $opcao): ?>
        <label class="proposta">
          <input type="radio" name="opcao" value="<?= (int) $opcao['id'] ?>"<?= $opcao['id'] === $escolhida ? ' checked' : '' ?> required>
          <span class="proposta-forma"><?= $opcao['parcelas'] === 1 ? 'À vista' : $opcao['parcelas'] . ' parcelas de' ?></span>
          <span class="proposta-valor"><?= moeda($opcao['valor_parcela']) ?></span>
          <?php if ($opcao['parcelas'] > 1): ?><span class="proposta-total">Total <?= moeda($opcao['total']) ?></span><?php endif; ?>
          <?php if ($opcao['desconto'] > 0): ?>
            <span class="proposta-economia">Você economiza <?= moeda($opcao['desconto']) ?></span>
          <?php endif; ?>
        </label>
      <?php endforeach; ?>
    </div>
  </fieldset>

  <fieldset>
    <legend>Quando quer pagar a primeira parcela?</legend>
    <div class="datas">
      <?php foreach ($propostas['datas'] as $i => $data): ?>
        <label class="data">
          <input type="radio" name="data" value="<?= e($data) ?>"<?= ($escolha['data'] ?? $propostas['datas'][0]) === $data ? ' checked' : '' ?> required>
          <span><?= e(data_br($data)) ?></span>
        </label>
      <?php endforeach; ?>
    </div>
  </fieldset>

  <button type="submit" class="botao">Revisar acordo</button>
  <p class="nota">Nada é fechado nesta etapa. Na próxima tela você confere tudo antes de confirmar.</p>
</form>
<?php endif; ?>
