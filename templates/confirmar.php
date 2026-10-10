<?php
/**
 * @var string $csrf @var string $chave @var string $data @var int $valorDivida
 * @var array{contrato: string, produto: string} $contrato
 * @var array{id: int, parcelas: int, valor_parcela: int, total: int, desconto: int} $opcao
 */
$url = '/contratos/' . rawurlencode($contrato['contrato']);
?>
<p class="voltar"><a href="<?= e($url) ?>">Escolher outra proposta</a></p>
<section class="cartao resumo">
  <h1>Confira o acordo</h1>
  <dl>
    <div><dt>Contrato</dt><dd><?= e($contrato['produto']) ?> · <?= e($contrato['contrato']) ?></dd></div>
    <div><dt>Dívida atualizada</dt><dd><?= moeda($valorDivida) ?></dd></div>
    <div><dt>Forma de pagamento</dt><dd><?= $opcao['parcelas'] === 1 ? 'À vista' : $opcao['parcelas'] . ' parcelas mensais' ?></dd></div>
    <div><dt><?= $opcao['parcelas'] === 1 ? 'Valor' : 'Valor de cada parcela' ?></dt><dd class="destaque"><?= moeda($opcao['valor_parcela']) ?></dd></div>
    <div><dt>Primeiro vencimento</dt><dd><?= e(data_br($data)) ?></dd></div>
    <div><dt>Total do acordo</dt><dd><?= moeda($opcao['total']) ?></dd></div>
    <?php if ($opcao['desconto'] > 0): ?>
      <div class="economia"><dt>Desconto</dt><dd><?= moeda($opcao['desconto']) ?></dd></div>
    <?php endif; ?>
  </dl>

  <form method="post" action="<?= e($url) ?>/acordo" data-envio-unico>
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="chave" value="<?= e($chave) ?>">
    <button type="submit" class="botao">Confirmar acordo</button>
  </form>
  <p class="nota">Ao confirmar, o acordo é registrado no sistema de <?= e($empresa) ?> e os boletos ficam disponíveis na hora. Se a primeira parcela não for paga até o vencimento, o acordo pode ser cancelado.</p>
</section>
