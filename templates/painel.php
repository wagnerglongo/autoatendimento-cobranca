<?php
/**
 * @var list<array{etapa: string, rotulo: string, pessoas: int, taxa: float}> $funil7
 * @var list<array{etapa: string, rotulo: string, pessoas: int, taxa: float}> $funil30
 * @var array<string, int> $porDia
 */
$maximoDia = max(1, ...array_values($porDia));
?>
<section class="cabecalho-area">
  <h1>Funil do autoatendimento</h1>
  <p class="apoio">Pessoas distintas em cada etapa. Nenhum CPF é exibido ou guardado em claro.</p>
</section>

<div class="funis">
  <?php foreach (['Últimos 7 dias' => $funil7, 'Últimos 30 dias' => $funil30] as $periodo => $funil): ?>
    <section class="cartao funil">
      <h2><?= e($periodo) ?></h2>
      <ol>
        <?php foreach ($funil as $etapa): ?>
          <li>
            <span class="funil-rotulo"><?= e($etapa['rotulo']) ?></span>
            <span class="funil-barra"><span class="w-<?= (int) (round($etapa['taxa'] / 5) * 5) ?>"></span></span>
            <span class="funil-numero"><?= (int) $etapa['pessoas'] ?> <small><?= number_format($etapa['taxa'], 1, ',', '') ?>%</small></span>
          </li>
        <?php endforeach; ?>
      </ol>
    </section>
  <?php endforeach; ?>
</div>

<section class="cartao">
  <h2>Acordos fechados por dia</h2>
  <div class="colunas" role="img" aria-label="Acordos fechados por dia nos últimos 14 dias">
    <?php foreach ($porDia as $dia => $quantidade): ?>
      <div class="coluna" title="<?= e(data_br($dia)) ?>: <?= (int) $quantidade ?>">
        <span class="coluna-valor"><?= (int) $quantidade ?></span>
        <span class="coluna-barra h-<?= (int) (round($quantidade / $maximoDia * 20) * 5) ?>"></span>
        <span class="coluna-dia"><?= e(substr(data_br($dia), 0, 5)) ?></span>
      </div>
    <?php endforeach; ?>
  </div>
</section>
