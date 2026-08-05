<?php
/**
 * Plugin SVM - Detalhamento de uma dimensão do ranking.
 *
 * Devolve um fragmento HTML com os indicadores e a lista de chamados
 * avaliados de um técnico, grupo ou categoria. É consumido pelo modal do
 * painel.
 *
 * Somente leitura (GET). Reaproveita PluginSvmReport::collect(), então a
 * restrição de entidade e o direito de ler respostas de terceiros valem
 * exatamente como no painel.
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
Session::checkRight('plugin_svm_report', READ);

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

/** Escape com unsanitize (o banco guarda entidades HTML). */
function svm_d_e($value): string {
    $s = (string)$value;
    if (class_exists('Glpi\\Toolbox\\Sanitizer')) {
        $s = \Glpi\Toolbox\Sanitizer::unsanitize($s);
    }
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function svm_d_num(?float $value, string $suffix = ''): string {
    return $value === null
        ? '—'
        : rtrim(rtrim(number_format($value, 1, ',', '.'), '0'), ',') . $suffix;
}

// ----------------------------------------------------------------------
// Parâmetros
// ----------------------------------------------------------------------
$dimensions = PluginSvmReport::getDimensions();
$type       = (string)($_GET['type'] ?? '');
$id         = (int)($_GET['id'] ?? 0);

if (!isset($dimensions[$type])) {
    http_response_code(400);
    echo "<p class='svm-empty'>" . __('Dimensão inválida.', 'svm') . "</p>";
    exit;
}

// id 0 é o agrupamento "sem atribuição / sem categoria". Filtrar por ele
// não restringe nada — devolveria o conjunto inteiro como se fosse dele.
if ($id <= 0) {
    http_response_code(400);
    echo "<p class='svm-empty'>"
       . __('Este agrupamento reúne registros sem item identificado, então não há detalhamento.', 'svm')
       . "</p>";
    exit;
}

$label = (string)($_GET['label'] ?? '');

// Força o filtro da dimensão pedida, preservando os demais do painel
$request = $_GET;
$request[$dimensions[$type]['filter']] = $id;

$filters = PluginSvmReport::readFilters($request);
$data    = PluginSvmReport::collect($filters);
$totals  = $data['totals'];
$rows    = $data['rows'];

// ----------------------------------------------------------------------
// Cabeçalho
// ----------------------------------------------------------------------
echo "<div class='svm-detail'>";

echo "<div class='svm-detail-head'>";
echo "<div class='svm-detail-id'>";
echo "<span class='svm-detail-kind'>" . svm_d_e($dimensions[$type]['label']) . "</span>";
echo "<h3>" . ($label !== '' ? svm_d_e($label) : ('#' . $id)) . "</h3>";
echo "</div>";

echo "<div class='svm-detail-kpis'>";
foreach ([
    ['CSAT', svm_d_num($totals['csat_percent'], '%'),
     PluginSvmReport::csatClass($totals['csat_percent'])],
    [__('Nota', 'svm'), svm_d_num($totals['csat_avg']), ''],
    ['NPS', svm_d_num($totals['nps']), PluginSvmReport::npsClass($totals['nps'])],
    [__('Pesquisas', 'svm'), (string)(int)$totals['surveys'], ''],
    [__('Detratores', 'svm'), (string)(int)$totals['detractors'],
     (int)$totals['detractors'] > 0 ? 'svm-kpi-bad' : ''],
] as $kpi) {
    echo "<div class='svm-detail-kpi'>";
    echo "<span class='svm-detail-kpi-value " . $kpi[2] . "'>" . $kpi[1] . "</span>";
    echo "<span class='svm-detail-kpi-label'>" . svm_d_e($kpi[0]) . "</span>";
    echo "</div>";
}
echo "</div>";
echo "</div>";

// ----------------------------------------------------------------------
// Lista de chamados
// ----------------------------------------------------------------------
if (empty($rows)) {
    echo "<p class='svm-empty'>"
       . __('Nenhuma pesquisa respondida para este item no período.', 'svm') . "</p>";
    echo "</div>";
    exit;
}

// Notas por pergunta
$answers = PluginSvmReport::loadAnswers(array_map(static fn($r) => (int)$r['id'], $rows));

echo "<div class='svm-table-scroll svm-detail-scroll'>";
echo "<table class='svm-rank svm-sortable' id='svm-detail-table'>";
echo "<thead><tr>";
echo "<th data-sort='num'>" . __('Chamado') . "</th>";
echo "<th data-sort='text'>" . __('Título') . "</th>";
echo "<th data-sort='text'>" . __('Data') . "</th>";
echo "<th>" . __('Notas', 'svm') . "</th>";
echo "<th class='svm-num' data-sort='num'>CSAT</th>";
echo "<th class='svm-num' data-sort='num'>NPS</th>";
echo "<th>" . __('Comentário') . "</th>";
echo "</tr></thead><tbody>";

foreach ($rows as $row) {
    $sid     = (int)$row['id'];
    $tid     = (int)$row['tickets_id'];
    $count   = (int)$row['answers_count'];
    $csat    = $count > 0 ? round(((int)$row['satisfied_count'] / $count) * 100, 1) : null;
    $nps     = (int)$row['nps_score'];

    $list = $answers[$sid] ?? [];
    if (empty($list) && !empty($row['is_legacy'])) {
        $list = PluginSvmReport::legacyAnswers($row);
    }

    echo "<tr>";

    echo "<td data-value='$tid'><a href='" . Ticket::getFormURLWithID($tid)
       . "' target='_blank'>#$tid</a></td>";

    echo "<td data-value='" . svm_d_e($row['ticket_name']) . "'>"
       . svm_d_e(Toolbox::substr((string)$row['ticket_name'], 0, 46)) . "</td>";

    echo "<td data-value='" . svm_d_e($row['date_creation']) . "'>"
       . svm_d_e(Html::convDateTime($row['date_creation'])) . "</td>";

    // Notas por pergunta, uma pastilha por resposta
    echo "<td class='svm-notes'>";
    if (empty($list)) {
        echo "<span class='svm-kpi-none'>—</span>";
    } else {
        foreach ($list as $a) {
            if ($a['type'] === 'text') {
                if ($a['text'] !== '') {
                    echo "<span class='svm-note-pill svm-note-text' title='"
                       . svm_d_e($a['name'] . ': ' . $a['text']) . "'>"
                       . "<i class='fas fa-comment-dots'></i></span>";
                }
                continue;
            }

            if ($a['value'] < 0) {
                continue;
            }

            // Cor pela posição na faixa: NPS usa 0-10, escala usa 1-5
            if ($a['type'] === 'nps') {
                $cls = PluginSvmReport::npsClass(
                    $a['value'] >= 9 ? 100 : ($a['value'] >= 7 ? 10 : -10)
                );
            } elseif ($a['type'] === 'bool') {
                $cls = $a['value'] >= 1 ? 'svm-kpi-great' : 'svm-kpi-bad';
            } else {
                $cls = PluginSvmReport::csatClass(($a['value'] / 5) * 100);
            }

            echo "<span class='svm-note-pill $cls' title='"
               . svm_d_e($a['name'] . ': ' . $a['value']) . "'>"
               . ($a['type'] === 'bool' ? ($a['value'] >= 1 ? '👍' : '👎') : (int)$a['value'])
               . "</span>";
        }
    }
    echo "</td>";

    echo "<td class='svm-num " . PluginSvmReport::csatClass($csat)
       . "' data-value='" . ($csat === null ? -1 : $csat) . "'>"
       . svm_d_num($csat, '%') . "</td>";

    echo "<td class='svm-num' data-value='$nps'>"
       . ($nps < 0 ? '—' : $nps) . "</td>";

    echo "<td class='svm-comment' title='" . svm_d_e($row['comment']) . "'>"
       . svm_d_e($row['comment']) . "</td>";

    echo "</tr>";
}

echo "</tbody></table>";
echo "</div>";

echo "<div class='svm-detail-foot'>";
echo "<span class='svm-note'>" . sprintf(
    __('%d chamado(s) avaliado(s) no período selecionado. Passe o mouse nas notas para ver a pergunta.', 'svm'),
    count($rows)
) . "</span>";

// Link para recortar o painel inteiro por este item
$drill = PluginSvmReport::drillUrl($filters, $dimensions[$type]['filter'], $id);
echo "<a class='btn btn-sm btn-outline-secondary' href='"
   . svm_d_e(Plugin::getWebDir('svm') . '/front/' . $drill) . "'>"
   . "<i class='fas fa-filter'></i> " . __('Filtrar todo o painel por este item', 'svm') . "</a>";
echo "</div>";

echo "</div>";
