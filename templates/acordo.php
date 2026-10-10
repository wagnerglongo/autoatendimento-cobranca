<?php
/**
 * @var array{contrato: string, produto: string} $contrato
 * @var array{id: string, situacao: string, total: int, data: string, parcelas: list<array{id: int, descricao: string, valor: int, vencimento: string, situacao: string}>}|null $acordo
 */
?>
<p class="voltar"><a href="/inicio">Meus contratos</a></p>
<section class="cabecalho-area">
  <h1><?= e($contrato['produto']) ?></h1>
  <p class="apoio">Contrato <?= e($contrato['contrato']) ?></p>
</section>

<?php if ($acordo === null): ?>
  <section class="cartao"><p>O acordo deste contrato ainda está sendo processado pelo credor. Volte em alguns minutos.</p></section>
<?php else: ?>
  <section class="cartao">
    <div class="acordo-topo">
      <div>
        <h2>Acordo nº <?= e($acordo['id']) ?></h2>
        <p class="apoio">Fechado em <?= e(data_br($acordo['data'])) ?> · total de <?= moeda($acordo['total']) ?></p>
      </div>
      <span class="selo selo-<?= $acordo['situacao'] === 'Quitado' ? 'ok' : 'ativo' ?>"><?= e($acordo['situacao']) ?></span>
    </div>

    <ol class="parcelas">
      <?php foreach ($acordo['parcelas'] as $parcela): ?>
        <?php $paga = $parcela['situacao'] === 'Pago'; ?>
        <li class="parcela<?= $paga ? ' paga' : '' ?><?= $parcela['situacao'] === 'Vencida' ? ' vencida' : '' ?>">
          <span class="parcela-numero">Parcela <?= e($parcela['descricao']) ?></span>
          <span class="parcela-valor"><?= moeda($parcela['valor']) ?></span>
          <span class="parcela-vencimento"><?= $paga ? 'Paga' : 'Vence em ' . e(data_br($parcela['vencimento'])) ?><?= $parcela['situacao'] === 'Vencida' ? ' · vencida' : '' ?></span>
          <?php if (!$paga): ?>
            <a class="botao botao-pequeno" href="/boletos/<?= (int) $parcela['id'] ?>">Baixar boleto</a>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ol>
  </section>
<?php endif; ?>
