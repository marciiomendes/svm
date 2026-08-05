<?php
/**
 * Plugin SVM - Painel de indicadores da pesquisa de satisfação.
 *
 * Visão sintética (KPIs, tendência, distribuição, rankings) e analítica
 * (lista pesquisável) na mesma tela.
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
Session::checkRight('plugin_svm_survey', READ);

Html::header(
    PluginSvmSurvey::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'helpdesk',
    'PluginSvmSurvey'
);

$can_report = Session::haveRight('plugin_svm_report', READ);
$can_export = PluginSvmProfile::canExportReports();

// ----------------------------------------------------------------------
// Helpers de renderização
// ----------------------------------------------------------------------

/**
 * O GLPI grava o texto já com entidades HTML (& → &#38;). Sem desfazer
 * isso antes de reescapar, um "R&M" apareceria como "R&#38;M" na tela.
 */
function svm_e($value): string {
    $s = (string)$value;

    if (class_exists('Glpi\\Toolbox\\Sanitizer')) {
        $s = \Glpi\Toolbox\Sanitizer::unsanitize($s);
    }

    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function svm_num(?float $value, string $suffix = ''): string {
    return $value === null ? '—' : rtrim(rtrim(number_format($value, 1, ',', '.'), '0'), ',') . $suffix;
}

/** Card de KPI. */
function svm_kpi(string $label, string $value, string $class = '', string $hint = '') {
    echo "<div class='svm-kpi $class'>";
    echo "<div class='svm-kpi-label'>" . svm_e($label) . "</div>";
    echo "<div class='svm-kpi-value'>$value</div>";
    if ($hint !== '') {
        echo "<div class='svm-kpi-hint'>" . svm_e($hint) . "</div>";
    }
    echo "</div>";
}

/**
 * Tabela de ranking de uma dimensão.
 * Linhas abaixo da amostra mínima ficam separadas: um percentual apurado
 * sobre 2 respostas não é comparável com um apurado sobre 200.
 */
function svm_ranking(string $title, array $buckets, int $min_sample, string $unit) {
    echo "<div class='svm-panel'>";
    echo "<h3>" . svm_e($title) . "</h3>";

    if (empty($buckets)) {
        echo "<p class='svm-empty'>" . __('Sem dados no período.', 'svm') . "</p></div>";
        return;
    }

    $ranked = [];
    $small  = [];
    foreach ($buckets as $b) {
        if ((int)$b['metrics']['surveys'] >= $min_sample
            && $b['metrics']['csat_percent'] !== null) {
            $ranked[] = $b;
        } else {
            $small[] = $b;
        }
    }

    if (!empty($ranked)) {
        echo "<table class='svm-rank'>";
        echo "<tr>";
        echo "<th class='svm-rank-pos'>#</th>";
        echo "<th>" . svm_e($unit) . "</th>";
        echo "<th class='svm-num'>" . __('Pesquisas', 'svm') . "</th>";
        echo "<th class='svm-bar-col'>CSAT</th>";
        echo "<th class='svm-num'>" . __('Nota', 'svm') . "</th>";
        echo "<th class='svm-num'>NPS</th>";
        echo "<th class='svm-num'>" . __('Detratores', 'svm') . "</th>";
        echo "</tr>";

        $pos = 0;
        $last = count($ranked);
        foreach ($ranked as $b) {
            $pos++;
            $m    = $b['metrics'];
            $csat = (float)$m['csat_percent'];
            $cls  = PluginSvmReport::csatClass($csat);

            $medal = '';
            if ($pos === 1)             { $medal = '🥇'; }
            elseif ($pos === 2)         { $medal = '🥈'; }
            elseif ($pos === 3)         { $medal = '🥉'; }

            $row_cls = ($pos === $last && $last > 3) ? " class='svm-rank-last'" : '';

            echo "<tr$row_cls>";
            echo "<td class='svm-rank-pos'>" . $medal . ' ' . $pos . "</td>";
            echo "<td>" . svm_e($b['label']) . "</td>";
            echo "<td class='svm-num'>" . (int)$m['surveys'] . "</td>";
            echo "<td class='svm-bar-col'>";
            echo "<div class='svm-bar'><span class='$cls' style='width:"
                 . max(0, min(100, $csat)) . "%'></span></div>";
            echo "<span class='svm-bar-val $cls'>" . svm_num($csat, '%') . "</span>";
            echo "</td>";
            echo "<td class='svm-num'>" . svm_num($m['csat_avg']) . "</td>";
            echo "<td class='svm-num " . PluginSvmReport::npsClass($m['nps']) . "'>"
                 . svm_num($m['nps']) . "</td>";
            echo "<td class='svm-num'>" . (int)$m['detractors'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }

    if (!empty($small)) {
        echo "<details class='svm-small-sample'>";
        echo "<summary>" . sprintf(
            __('%1$d fora do ranking (menos de %2$d pesquisas)', 'svm'),
            count($small), $min_sample
        ) . "</summary>";
        echo "<table class='svm-rank'>";
        echo "<tr><th>" . svm_e($unit) . "</th><th class='svm-num'>"
             . __('Pesquisas', 'svm') . "</th><th class='svm-num'>CSAT</th>"
             . "<th class='svm-num'>" . __('Nota', 'svm') . "</th></tr>";
        foreach ($small as $b) {
            $m = $b['metrics'];
            echo "<tr><td>" . svm_e($b['label']) . "</td>";
            echo "<td class='svm-num'>" . (int)$m['surveys'] . "</td>";
            echo "<td class='svm-num'>" . svm_num($m['csat_percent'], '%') . "</td>";
            echo "<td class='svm-num'>" . svm_num($m['csat_avg']) . "</td></tr>";
        }
        echo "</table>";
        echo "<p class='svm-note'>"
             . __('Amostras pequenas oscilam muito: com 2 respostas só existem 0%, 50% e 100%. Ficam de fora do ranking para não gerar comparação enganosa.', 'svm')
             . "</p>";
        echo "</details>";
    }

    echo "</div>";
}

// ----------------------------------------------------------------------
// Sem direito a indicadores: só a lista analítica
// ----------------------------------------------------------------------
if (!$can_report) {
    echo "<div class='alert alert-info mt-2'>"
       . __('Você não tem permissão para ver os indicadores consolidados. Abaixo estão as respostas às quais tem acesso.', 'svm')
       . "</div>";
    Search::show('PluginSvmSurvey');
    Html::footer();
    return;
}

// ----------------------------------------------------------------------
// Coleta
// ----------------------------------------------------------------------
$filters = PluginSvmReport::readFilters($_GET);
$data    = PluginSvmReport::collect($filters);
$totals  = $data['totals'];

// ----------------------------------------------------------------------
// Filtros
// ----------------------------------------------------------------------
echo "<div class='svm-dash'>";

echo "<form method='get' action='" . $_SERVER['PHP_SELF'] . "' class='svm-filters'>";

echo "<div class='svm-filter'><label>" . __('Período', 'svm') . "</label>";
Dropdown::showFromArray('period', PluginSvmReport::getPeriodOptions(), [
    'value' => $filters['period'],
    'width' => '160px',
]);
echo "</div>";

// Entidade usa -1 como "todas": 0 é a entidade raiz, um id válido.
echo "<div class='svm-filter'><label>" . __('Entidade') . "</label>";
Entity::dropdown([
    'name'                => 'svm_entity',
    'value'               => $filters['entities_id'] ?? -1,
    'display_emptychoice' => false,
    'toadd'               => [-1 => __('Todas', 'svm')],
    'width'               => '200px',
]);
echo "</div>";

echo "<div class='svm-filter'><label>" . __('Categoria') . "</label>";
ITILCategory::dropdown([
    'name'                => 'category',
    'value'               => $filters['itilcategories_id'] ?? 0,
    'display_emptychoice' => true,
    'emptylabel'          => __('Todas', 'svm'),
    'width'               => '200px',
]);
echo "</div>";

echo "<div class='svm-filter'><label>" . __('Técnico') . "</label>";
User::dropdown([
    'name'                => 'tech',
    'value'               => $filters['tech_id'] ?? 0,
    'right'               => 'own_ticket',
    'display_emptychoice' => true,
    'emptylabel'          => __('Todos', 'svm'),
    'width'               => '200px',
]);
echo "</div>";

echo "<div class='svm-filter'><label>" . __('Grupo') . "</label>";
Group::dropdown([
    'name'                => 'group',
    'value'               => $filters['group_id'] ?? 0,
    'condition'           => ['is_assign' => 1],
    'display_emptychoice' => true,
    'emptylabel'          => __('Todos', 'svm'),
    'width'               => '200px',
]);
echo "</div>";

echo "<div class='svm-filter'><label>" . __('Amostra mínima', 'svm') . "</label>";
echo "<input type='number' name='min_sample' min='1' max='100' value='"
     . (int)$filters['min_sample'] . "' style='width:80px'>";
echo "</div>";

echo "<div class='svm-filter svm-filter-actions'>";
echo "<button type='submit' class='btn btn-primary'>" . __('Aplicar', 'svm') . "</button>";
echo " <a class='btn btn-outline-secondary' href='" . $_SERVER['PHP_SELF'] . "'>"
     . __('Limpar', 'svm') . "</a>";
if ($can_export) {
    echo " <a class='btn btn-outline-secondary' href='export.php?"
         . svm_e(http_build_query($_GET)) . "'>"
         . "<i class='fas fa-download'></i> CSV</a>";
}
echo "</div>";

echo "</form>";

if ($data['truncated']) {
    echo "<div class='alert alert-warning'>" . sprintf(
        __('O período selecionado excede %d pesquisas. Os números abaixo consideram apenas as mais recentes — reduza o período para uma leitura exata.', 'svm'),
        PluginSvmReport::MAX_ROWS
    ) . "</div>";
}

// ----------------------------------------------------------------------
// KPIs
// ----------------------------------------------------------------------
echo "<div class='svm-kpis'>";

svm_kpi(__('Pesquisas respondidas', 'svm'), (string)(int)$totals['surveys']);

svm_kpi(
    'CSAT',
    svm_num($totals['csat_percent'], '%'),
    PluginSvmReport::csatClass($totals['csat_percent']),
    __('70-85% bom · +85% excepcional', 'svm')
);

svm_kpi(
    __('Nota média', 'svm'),
    svm_num($totals['csat_avg']),
    '',
    sprintf(__('sobre %d respostas', 'svm'), (int)$totals['answers'])
);

svm_kpi(
    'NPS',
    svm_num($totals['nps']),
    PluginSvmReport::npsClass($totals['nps']),
    sprintf(__('%d respostas de NPS', 'svm'), (int)$totals['nps_answers'])
);

svm_kpi(
    __('Detratores', 'svm'),
    (string)(int)$totals['detractors'],
    (int)$totals['detractors'] > 0 ? 'svm-kpi-bad' : '',
    __('contato em até 48h', 'svm')
);

svm_kpi(
    __('Com comentário', 'svm'),
    (string)(int)$totals['comments'],
    '',
    __('feedback qualitativo', 'svm')
);

echo "</div>";

if ((int)$totals['surveys'] === 0) {
    echo "<div class='alert alert-info'>"
       . __('Nenhuma pesquisa respondida com os filtros atuais.', 'svm')
       . "</div>";
    echo "</div>";

    // Mantém a visão analítica: sem ela a tela ficaria sem saída.
    echo "<h2 class='svm-section-title'>" . __('Analítico', 'svm') . "</h2>";
    Search::show('PluginSvmSurvey');

    Html::footer();
    return;
}

// ----------------------------------------------------------------------
// Composição do NPS
// ----------------------------------------------------------------------
if ((int)$totals['nps_answers'] > 0) {
    $n  = (int)$totals['nps_answers'];
    $pp = round(((int)$totals['promoters']  / $n) * 100, 1);
    $ps = round(((int)$totals['passives']   / $n) * 100, 1);
    $pd = round(((int)$totals['detractors'] / $n) * 100, 1);

    echo "<div class='svm-panel'>";
    echo "<h3>" . __('Composição do NPS', 'svm') . "</h3>";
    echo "<div class='svm-stack'>";
    $l_det = svm_e(__('Detratores', 'svm'));
    $l_neu = svm_e(__('Neutros', 'svm'));
    $l_pro = svm_e(__('Promotores', 'svm'));

    if ($pd > 0) { echo "<span class='svm-stack-bad'  style='width:{$pd}%' title='$l_det'>{$pd}%</span>"; }
    if ($ps > 0) { echo "<span class='svm-stack-mid'  style='width:{$ps}%' title='$l_neu'>{$ps}%</span>"; }
    if ($pp > 0) { echo "<span class='svm-stack-good' style='width:{$pp}%' title='$l_pro'>{$pp}%</span>"; }
    echo "</div>";
    echo "<div class='svm-legend'>";
    echo "<span><i class='svm-dot svm-dot-bad'></i>" . sprintf(__('Detratores (0-6): %d', 'svm'), (int)$totals['detractors']) . "</span>";
    echo "<span><i class='svm-dot svm-dot-mid'></i>" . sprintf(__('Neutros (7-8): %d', 'svm'), (int)$totals['passives']) . "</span>";
    echo "<span><i class='svm-dot svm-dot-good'></i>" . sprintf(__('Promotores (9-10): %d', 'svm'), (int)$totals['promoters']) . "</span>";
    echo "</div>";
    echo "</div>";
}

// ----------------------------------------------------------------------
// Tendência mensal
// ----------------------------------------------------------------------
if (count($data['timeline']) > 1) {
    echo "<div class='svm-panel'>";
    echo "<h3>" . __('Tendência mensal', 'svm') . "</h3>";
    echo "<div class='svm-trend'>";

    foreach ($data['timeline'] as $point) {
        $m    = $point['metrics'];
        $csat = $m['csat_percent'];
        $h    = $csat === null ? 0 : max(2, min(100, (float)$csat));
        $cls  = PluginSvmReport::csatClass($csat);

        echo "<div class='svm-trend-col' title='"
             . svm_e($point['label'] . ': ' . svm_num($csat, '%')
                     . ' · ' . (int)$m['surveys'] . ' pesquisas') . "'>";
        echo "<div class='svm-trend-value'>" . svm_num($csat, '%') . "</div>";
        echo "<div class='svm-trend-bar'><span class='$cls' style='height:{$h}%'></span></div>";
        echo "<div class='svm-trend-label'>" . svm_e($point['label']) . "</div>";
        echo "<div class='svm-trend-count'>" . (int)$m['surveys'] . "</div>";
        echo "</div>";
    }

    echo "</div>";
    echo "<p class='svm-note'>" . __('Altura da barra = CSAT do mês. O número abaixo é a quantidade de pesquisas — meses com poucas respostas oscilam mais.', 'svm') . "</p>";
    echo "</div>";
}

// ----------------------------------------------------------------------
// Distribuição das notas
// ----------------------------------------------------------------------
if (!empty($data['distribution'])) {
    $dist  = $data['distribution'];
    $total = array_sum($dist);
    $maxv  = max($dist);

    echo "<div class='svm-panel'>";
    echo "<h3>" . __('Distribuição das notas', 'svm') . "</h3>";
    echo "<table class='svm-dist'>";
    foreach ($dist as $score => $count) {
        $pct   = $total > 0 ? round(($count / $total) * 100, 1) : 0;
        $width = $maxv > 0 ? round(($count / $maxv) * 100, 1) : 0;
        echo "<tr>";
        echo "<td class='svm-dist-score'>" . (int)$score . "</td>";
        echo "<td class='svm-dist-bar'><div class='svm-bar'><span style='width:{$width}%'></span></div></td>";
        echo "<td class='svm-num'>" . (int)$count . "</td>";
        echo "<td class='svm-num svm-dist-pct'>" . svm_num((float)$pct, '%') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    echo "<p class='svm-note'>" . sprintf(
        __('%d notas individuais. Uma distribuição em U (muitas notas extremas) indica experiências inconsistentes, não uma média morna.', 'svm'),
        $total
    ) . "</p>";
    echo "</div>";
}

// ----------------------------------------------------------------------
// Rankings
// ----------------------------------------------------------------------
svm_ranking(__('Ranking por técnico', 'svm'),   $data['by_tech'],     (int)$filters['min_sample'], __('Técnico'));
svm_ranking(__('Ranking por grupo', 'svm'),     $data['by_group'],    (int)$filters['min_sample'], __('Grupo'));
svm_ranking(__('Ranking por categoria', 'svm'), $data['by_category'], (int)$filters['min_sample'], __('Categoria'));

echo "<p class='svm-note'>" . __('Um chamado com vários técnicos atribuídos conta para cada um deles, então a soma das pesquisas por técnico pode exceder o total.', 'svm') . "</p>";

// ----------------------------------------------------------------------
// Fila de follow-up
// ----------------------------------------------------------------------
if (!empty($data['detractors'])) {
    echo "<div class='svm-panel svm-panel-alert'>";
    echo "<h3>" . __('Precisam de contato', 'svm') . "</h3>";
    echo "<p class='svm-note'>" . __('Detratores de NPS ou pesquisas com menos de 50% de respostas satisfeitas. A recomendação é contato individual em até 48h.', 'svm') . "</p>";

    echo "<table class='svm-rank'>";
    echo "<tr>";
    echo "<th>" . __('Chamado') . "</th>";
    echo "<th>" . __('Data') . "</th>";
    echo "<th class='svm-num'>CSAT</th>";
    echo "<th class='svm-num'>NPS</th>";
    echo "<th>" . __('Técnico') . "</th>";
    echo "<th>" . __('Comentário') . "</th>";
    echo "</tr>";

    foreach ($data['detractors'] as $d) {
        echo "<tr>";
        echo "<td><a href='" . Ticket::getFormURLWithID($d['tickets_id']) . "'>#"
             . (int)$d['tickets_id'] . "</a> "
             . svm_e(Toolbox::substr($d['ticket_name'], 0, 40)) . "</td>";
        echo "<td>" . svm_e(Html::convDateTime($d['date'])) . "</td>";
        echo "<td class='svm-num'>" . svm_num($d['csat_percent'], '%') . "</td>";
        echo "<td class='svm-num'>" . ($d['nps_score'] < 0 ? '—' : (int)$d['nps_score']) . "</td>";
        echo "<td>" . svm_e(implode(', ', $d['techs'])) . "</td>";
        echo "<td class='svm-comment'>" . svm_e($d['comment']) . "</td>";
        echo "</tr>";
    }

    echo "</table>";
    echo "</div>";
}

echo "</div>"; // .svm-dash

// ----------------------------------------------------------------------
// Visão analítica
// ----------------------------------------------------------------------
echo "<h2 class='svm-section-title'>" . __('Analítico', 'svm') . "</h2>";
Search::show('PluginSvmSurvey');

Html::footer();
