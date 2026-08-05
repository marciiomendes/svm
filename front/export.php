<?php
/**
 * Plugin SVM - Exportação das pesquisas em CSV.
 *
 * Respeita os mesmos filtros e as mesmas restrições de entidade e de
 * direito da tela de indicadores.
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
Session::checkRight('plugin_svm_report', READ);

if (!PluginSvmProfile::canExportReports()) {
    Html::displayRightError();
}

$filters = PluginSvmReport::readFilters($_GET);
$data    = PluginSvmReport::collect($filters);

$format = ($_GET['format'] ?? 'csv') === 'json' ? 'json' : 'csv';

while (ob_get_level() > 0) {
    if (!@ob_end_clean()) {
        break;
    }
}

// ----------------------------------------------------------------------
// JSON — para Power BI, Grafana, scripts etc.
// ----------------------------------------------------------------------
// Autenticado por sessão: uma ferramenta externa precisa de um cookie de
// sessão válido, ou do API REST do GLPI. Não há token próprio aqui de
// propósito — seria mais uma superfície de autenticação para manter.
if ($format === 'json') {
    header('Content-Type: application/json; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');

    if (!empty($_GET['download'])) {
        header('Content-Disposition: attachment; filename="svm_indicadores_'
               . date('Ymd_His') . '.json"');
    }

    echo json_encode(
        PluginSvmReport::toArray($data),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

$filename = 'svm_pesquisas_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');
header('Pragma: no-cache');

$out = fopen('php://output', 'w');

// BOM para o Excel reconhecer UTF-8
fwrite($out, "\xEF\xBB\xBF");

$sep = ';'; // ponto e vírgula: padrão do Excel em pt-BR

/**
 * Prepara uma célula de texto:
 *  - desfaz a sanitização do GLPI (senão "&" sai como "&#38;");
 *  - neutraliza injeção de fórmula: uma célula iniciada por = + - @ tab ou
 *    CR é interpretada como fórmula pelo Excel/Calc, e o comentário da
 *    pesquisa é texto livre do usuário.
 */
function svm_csv($value): string {
    $s = (string)$value;

    if (class_exists('Glpi\\Toolbox\\Sanitizer')) {
        $s = \Glpi\Toolbox\Sanitizer::unsanitize($s);
    }

    if ($s !== '' && preg_match('/^[=+\-@\t\r]/', $s)) {
        $s = "'" . $s;
    }

    return $s;
}

/** Número no formato pt-BR, para o Excel reconhecer como número. */
function svm_csv_num($value): string {
    return ($value === null || $value === '')
        ? ''
        : number_format((float)$value, 1, ',', '');
}

// ---- Resumo ----
$t = $data['totals'];
fputcsv($out, ['RESUMO'], $sep);
fputcsv($out, ['Pesquisas respondidas', (int)$t['surveys']], $sep);
fputcsv($out, ['Respostas de nota', (int)$t['answers']], $sep);
fputcsv($out, ['CSAT %', svm_csv_num($t['csat_percent'])], $sep);
fputcsv($out, ['Nota média', svm_csv_num($t['csat_avg'])], $sep);
fputcsv($out, ['NPS', svm_csv_num($t['nps'])], $sep);
fputcsv($out, ['Promotores', (int)$t['promoters']], $sep);
fputcsv($out, ['Neutros', (int)$t['passives']], $sep);
fputcsv($out, ['Detratores', (int)$t['detractors']], $sep);
fputcsv($out, [], $sep);

// ---- Consolidados ----
foreach ([
    'Por técnico'   => 'by_tech',
    'Por grupo'     => 'by_group',
    'Por categoria' => 'by_category',
] as $title => $key) {
    fputcsv($out, [strtoupper($title)], $sep);
    fputcsv($out, ['Nome', 'Pesquisas', 'Respostas', 'CSAT %', 'Nota média', 'NPS', 'Detratores'], $sep);

    foreach ($data[$key] as $bucket) {
        $m = $bucket['metrics'];
        fputcsv($out, [
            svm_csv($bucket['label']),
            (int)$m['surveys'],
            (int)$m['answers'],
            svm_csv_num($m['csat_percent']),
            svm_csv_num($m['csat_avg']),
            svm_csv_num($m['nps']),
            (int)$m['detractors'],
        ], $sep);
    }

    fputcsv($out, [], $sep);
}

// ---- Tendência ----
fputcsv($out, ['TENDÊNCIA MENSAL'], $sep);
fputcsv($out, ['Mês', 'Pesquisas', 'CSAT %', 'Nota média', 'NPS'], $sep);
foreach ($data['timeline'] as $point) {
    $m = $point['metrics'];
    fputcsv($out, [
        $point['month'],
        (int)$m['surveys'],
        svm_csv_num($m['csat_percent']),
        svm_csv_num($m['csat_avg']),
        svm_csv_num($m['nps']),
    ], $sep);
}
fputcsv($out, [], $sep);

// ---- Analítico ----
fputcsv($out, ['ANALÍTICO'], $sep);
fputcsv($out, [
    'Chamado', 'Título', 'Data da resposta', 'Respostas', 'Satisfeitas',
    'CSAT %', 'Nota média', 'NPS', 'Comentário',
], $sep);

foreach ($data['rows'] as $row) {
    $answers = (int)$row['answers_count'];
    fputcsv($out, [
        (int)$row['tickets_id'],
        svm_csv($row['ticket_name']),
        $row['date_creation'],
        $answers,
        (int)$row['satisfied_count'],
        $answers > 0
            ? svm_csv_num(((int)$row['satisfied_count'] / $answers) * 100)
            : '',
        svm_csv_num($row['csat_avg']),
        (int)$row['nps_score'] < 0 ? '' : (int)$row['nps_score'],
        svm_csv(preg_replace('/\s+/u', ' ', (string)$row['comment'])),
    ], $sep);
}

fclose($out);
