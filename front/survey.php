<?php
/**
 * Plugin SVM - Painel de indicadores da pesquisa de satisfação.
 *
 * Layout denso: termômetros, tendência, distribuição e rankings cabem numa
 * tela só. O que é volumoso — fila de detratores e visão analítica — fica
 * recolhido, e as tabelas longas rolam por dentro em vez de empurrar a
 * página.
 *
 * Os gráficos são SVG gerados no servidor e as abas são CSS puro: tudo
 * funciona sem JavaScript. O JS acrescenta tooltip, ordenação e cópia.
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
// Helpers
// ----------------------------------------------------------------------

/** Escapa para HTML, desfazendo antes a sanitização do GLPI. */
function svm_e($value): string {
    $s = (string)$value;

    if (class_exists('Glpi\\Toolbox\\Sanitizer')) {
        $s = \Glpi\Toolbox\Sanitizer::unsanitize($s);
    }

    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function svm_num(?float $value, string $suffix = ''): string {
    return $value === null
        ? '—'
        : rtrim(rtrim(number_format($value, 1, ',', '.'), '0'), ',') . $suffix;
}

/**
 * Termômetro compacto: arco de 180° com valor e escala dentro do próprio
 * SVG — sem sobreposição por margem negativa.
 */
function svm_gauge(string $title, ?float $value, float $min, float $max, array $bands, string $hint = '') {
    $w = 200; $h = 118;
    $cx = 100; $cy = 100; $r = 78; $stroke = 14;

    $point = static function (float $ratio) use ($cx, $cy, $r): array {
        $angle = M_PI * (1 - max(0.0, min(1.0, $ratio)));
        return [$cx + $r * cos($angle), $cy - $r * sin($angle)];
    };

    $span  = ($max - $min) > 0 ? ($max - $min) : 1;
    $ratio = $value === null ? 0.0 : max(0.0, min(1.0, ($value - $min) / $span));

    echo "<div class='svm-gauge'>";
    echo "<div class='svm-gauge-title'>" . svm_e($title) . "</div>";
    echo "<svg viewBox='0 0 $w $h' class='svm-gauge-svg' role='img' aria-label='"
       . svm_e($title . ': ' . ($value === null ? __('sem dados', 'svm') : svm_num($value))) . "'>";

    // Faixas de referência
    $from = 0.0;
    foreach ($bands as $band) {
        $upto = max(0.0, min(1.0, (((float)$band[0]) - $min) / $span));
        if ($upto <= $from) {
            continue;
        }
        [$x1, $y1] = $point($from);
        [$x2, $y2] = $point($upto);
        printf(
            "<path d='M %.2f %.2f A %d %d 0 0 1 %.2f %.2f' fill='none' stroke='%s' "
            . "stroke-width='%d' opacity='0.25'/>",
            $x1, $y1, $r, $r, $x2, $y2, $band[1], $stroke
        );
        $from = $upto;
    }

    // Arco do valor
    if ($value !== null && $ratio > 0) {
        $color = '#64748b';
        foreach ($bands as $band) {
            $color = $band[1];
            if ($value <= (float)$band[0]) {
                break;
            }
        }

        [$x1, $y1] = $point(0.0);
        [$x2, $y2] = $point($ratio);
        printf(
            "<path d='M %.2f %.2f A %d %d 0 0 1 %.2f %.2f' fill='none' stroke='%s' "
            . "stroke-width='%d' stroke-linecap='round'/>",
            $x1, $y1, $r, $r, $x2, $y2, $color, $stroke
        );

        [$mx, $my] = $point($ratio);
        printf(
            "<circle cx='%.2f' cy='%.2f' r='5' fill='#fff' stroke='%s' stroke-width='3'/>",
            $mx, $my, $color
        );
    }

    // Valor e escala dentro do SVG
    printf(
        "<text x='%d' y='%d' class='svm-gauge-num' text-anchor='middle'>%s</text>",
        $cx, $cy - 12, svm_e($value === null ? '—' : svm_num($value))
    );
    printf(
        "<text x='%d' y='%d' class='svm-gauge-tick' text-anchor='start'>%s</text>",
        $cx - $r - 4, $h - 2, svm_e(svm_num($min))
    );
    printf(
        "<text x='%d' y='%d' class='svm-gauge-tick' text-anchor='end'>%s</text>",
        $cx + $r + 4, $h - 2, svm_e(svm_num($max))
    );

    echo "</svg>";

    if ($hint !== '') {
        echo "<div class='svm-gauge-hint' title='" . svm_e($hint) . "'>" . svm_e($hint) . "</div>";
    }

    echo "</div>";
}

/** Avatar: foto quando existe, senão iniciais coloridas. */
function svm_avatar(array $avatar, string $label, string $size = 'md'): string {
    $cls = 'svm-avatar svm-avatar-' . $size;

    if (!empty($avatar['url'])) {
        return "<img class='$cls' src='" . svm_e($avatar['url']) . "' alt='"
             . svm_e($label) . "' loading='lazy'>";
    }

    $hue = (int)($avatar['hue'] ?? 210);
    return "<span class='$cls svm-avatar-initials' style='--svm-hue:$hue' aria-hidden='true'>"
         . svm_e($avatar['initials'] ?? '?') . "</span>";
}

/**
 * Nome de um item do ranking, clicável quando dá para detalhar.
 *
 * Os buckets "(sem atribuição)" e "(sem categoria)" têm id 0, que não
 * identifica registro nenhum: filtrar por ele devolveria o conjunto todo.
 * Nesses casos o nome fica sem link, em vez de abrir um detalhamento
 * silenciosamente errado.
 */
function svm_dim_name(array $bucket, string $drill_key, string $avatar_size = 'sm'): string {
    $id     = (int)$bucket['id'];
    $label  = (string)$bucket['label'];
    $avatar = svm_avatar($bucket['avatar'] ?? [], $label, $avatar_size);

    if ($id <= 0) {
        return "<span class='svm-rank-link svm-rank-plain' title='"
             . svm_e(__('Sem item identificado: não há detalhamento.', 'svm')) . "'>"
             . $avatar . "<span>" . svm_e($label) . "</span></span>";
    }

    return "<button type='button' class='svm-rank-link svm-open-detail'"
         . " data-type='" . svm_e($drill_key) . "'"
         . " data-id='" . $id . "'"
         . " data-label='" . svm_e($label) . "'"
         . " title='" . svm_e(__('Ver os chamados e notas', 'svm')) . "'>"
         . $avatar . "<span>" . svm_e($label) . "</span></button>";
}

/** Bloco compacto de número. */
function svm_stat(string $label, string $value, string $class = '', string $hint = '') {
    echo "<div class='svm-stat $class'" . ($hint !== '' ? " title='" . svm_e($hint) . "'" : '') . ">";
    echo "<span class='svm-stat-value'>$value</span>";
    echo "<span class='svm-stat-label'>" . svm_e($label) . "</span>";
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

$filters = PluginSvmReport::readFilters($_GET);
$data    = PluginSvmReport::collect($filters);
$totals  = $data['totals'];

/**
 * Conteúdo de uma aba de ranking: pódio (opcional) + tabela com rolagem
 * interna, para não empurrar a página.
 */
function svm_rank_panel(
    array $buckets,
    int $min_sample,
    string $unit,
    string $drill_key,
    array $filters
) {
    $tid = 'svm-rank-' . $drill_key;

    if (empty($buckets)) {
        echo "<p class='svm-empty'>" . __('Sem dados no período.', 'svm') . "</p>";
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

    echo "<div class='svm-rank-wrap'>";

    // ---- Pódio ----
    // Ordem visual 2º-1º-3º, com o degrau mais alto no centro. Clicar abre
    // o detalhamento; o dado bruto continua na tabela abaixo.
    if (count($ranked) >= 3) {
        echo "<div class='svm-podium'>";

        foreach ([1 => 2, 0 => 1, 2 => 3] as $idx => $place) {
            $b   = $ranked[$idx];
            $m   = $b['metrics'];
            $bid = (int)$b['id'];

            // id 0 = "(sem atribuição)": não há o que detalhar
            if ($bid > 0) {
                echo "<button type='button'"
                   . " class='svm-podium-item svm-place-$place svm-open-detail'"
                   . " data-type='" . svm_e($drill_key) . "'"
                   . " data-id='" . $bid . "'"
                   . " data-label='" . svm_e($b['label']) . "'"
                   . " title='" . svm_e(sprintf(
                         __('Ver os chamados e notas de %s', 'svm'), $b['label']
                     )) . "'>";
            } else {
                echo "<div class='svm-podium-item svm-place-$place svm-podium-plain'>";
            }

            echo "<span class='svm-podium-medal'>"
               . ($place === 1 ? '👑' : ($place === 2 ? '🥈' : '🥉')) . "</span>";

            echo "<span class='svm-podium-photo'>";
            echo svm_avatar($b['avatar'] ?? [], (string)$b['label'], $place === 1 ? 'lg' : 'md');
            echo "<span class='svm-podium-rank'>$place°</span>";
            echo "</span>";

            echo "<span class='svm-podium-name'>" . svm_e($b['label']) . "</span>";
            echo "<span class='svm-podium-csat " . PluginSvmReport::csatClass($m['csat_percent'])
               . "'>" . svm_num($m['csat_percent'], '%') . "</span>";

            echo "<span class='svm-podium-meta'>"
               . sprintf(__('%1$d pesq. · nota %2$s', 'svm'),
                         (int)$m['surveys'], svm_num($m['csat_avg']))
               . "</span>";

            echo "<span class='svm-podium-step'><span class='svm-podium-step-num'>$place</span></span>";
            echo $bid > 0 ? "</button>" : "</div>";
        }

        echo "</div>";
    }

    // ---- Tabela ----
    echo "<div class='svm-table-scroll'>";
    echo "<table class='svm-rank svm-sortable' id='$tid'>";
    echo "<thead><tr>";
    echo "<th class='svm-rank-pos'>#</th>";
    echo "<th data-sort='text'>" . svm_e($unit) . "</th>";
    echo "<th class='svm-num' data-sort='num'>" . __('Pesq.', 'svm') . "</th>";
    echo "<th class='svm-bar-col' data-sort='num'>CSAT</th>";
    echo "<th class='svm-num' data-sort='num'>" . __('Nota', 'svm') . "</th>";
    echo "<th class='svm-num' data-sort='num'>NPS</th>";
    echo "<th class='svm-num' data-sort='num' title='" . svm_e(__('Detratores', 'svm')) . "'>"
       . "<i class='fas fa-thumbs-down'></i></th>";
    echo "<th class='svm-rank-actions'></th>";
    echo "</tr></thead><tbody>";

    $pos = 0;
    foreach ($ranked as $b) {
        $pos++;
        $m    = $b['metrics'];
        $csat = (float)$m['csat_percent'];
        $cls  = PluginSvmReport::csatClass($csat);
        $url  = PluginSvmReport::drillUrl($filters, $drill_key, (int)$b['id']);
        $medal = $pos === 1 ? '🥇' : ($pos === 2 ? '🥈' : ($pos === 3 ? '🥉' : ''));

        echo "<tr>";
        echo "<td class='svm-rank-pos'>" . ($medal !== '' ? $medal : $pos) . "</td>";

        // data-value com o nome puro: as iniciais do avatar entram no
        // textContent e bagunçariam a ordenação alfabética.
        echo "<td data-value='" . svm_e($b['label']) . "'>"
           . svm_dim_name($b, $drill_key) . "</td>";

        echo "<td class='svm-num' data-value='" . (int)$m['surveys'] . "'>"
           . (int)$m['surveys'] . "</td>";

        echo "<td class='svm-bar-col' data-value='" . $csat . "'>";
        echo "<div class='svm-bar'><span class='$cls' style='width:"
           . max(0, min(100, $csat)) . "%'></span></div>";
        echo "<span class='svm-bar-val $cls'>" . svm_num($csat, '%') . "</span></td>";

        echo "<td class='svm-num' data-value='" . (float)$m['csat_avg'] . "'>"
           . svm_num($m['csat_avg']) . "</td>";
        echo "<td class='svm-num " . PluginSvmReport::npsClass($m['nps'])
           . "' data-value='" . ($m['nps'] === null ? -101 : (float)$m['nps']) . "'>"
           . svm_num($m['nps']) . "</td>";
        echo "<td class='svm-num' data-value='" . (int)$m['detractors'] . "'>"
           . (int)$m['detractors'] . "</td>";

        echo "<td class='svm-num svm-rank-actions'><a class='svm-drill' href='" . svm_e($url)
           . "' title='" . svm_e(__('Filtrar o painel por este item', 'svm'))
           . "'><i class='fas fa-filter'></i></a></td>";
        echo "</tr>";
    }

    // Amostra pequena, na mesma tabela mas marcada
    foreach ($small as $b) {
        $m   = $b['metrics'];
        $url = PluginSvmReport::drillUrl($filters, $drill_key, (int)$b['id']);

        echo "<tr class='svm-row-small' title='"
           . svm_e(sprintf(__('Menos de %d pesquisas: fora do ranking', 'svm'), $min_sample)) . "'>";
        echo "<td class='svm-rank-pos'>–</td>";
        echo "<td data-value='" . svm_e($b['label']) . "'>"
           . svm_dim_name($b, $drill_key) . "</td>";
        echo "<td class='svm-num' data-value='" . (int)$m['surveys'] . "'>"
           . (int)$m['surveys'] . "</td>";
        echo "<td class='svm-bar-col' data-value='-1'><span class='svm-bar-val svm-kpi-none'>"
           . svm_num($m['csat_percent'], '%') . "</span></td>";
        echo "<td class='svm-num' data-value='" . (float)$m['csat_avg'] . "'>"
           . svm_num($m['csat_avg']) . "</td>";
        echo "<td class='svm-num' data-value='-101'>" . svm_num($m['nps']) . "</td>";
        echo "<td class='svm-num' data-value='" . (int)$m['detractors'] . "'>"
           . (int)$m['detractors'] . "</td>";
        echo "<td class='svm-num svm-rank-actions'></td>";
        echo "</tr>";
    }

    echo "</tbody></table>";
    echo "</div>";

    if (!empty($small)) {
        echo "<p class='svm-note'>" . sprintf(
            __('%1$d item(ns) em cinza têm menos de %2$d pesquisas e ficam fora do ranking: com amostra pequena o percentual oscila demais para comparar.', 'svm'),
            count($small), $min_sample
        ) . "</p>";
    }

    echo "</div>";
}

// ======================================================================
// Barra de controles
// ======================================================================
echo "<div class='svm-dash'>";

echo "<form method='get' action='" . $_SERVER['PHP_SELF'] . "' class='svm-bar'>";

// Período sempre visível: é o filtro mais usado
echo "<div class='svm-bar-group svm-bar-period'>";
echo "<label class='svm-bar-label'>" . __('Período', 'svm') . "</label>";
Dropdown::showFromArray('period', PluginSvmReport::getPeriodOptions(), [
    'value' => $filters['period'],
    'width' => '100%',
]);
echo "</div>";

// comments/addicon desligados: por padrão o GLPI envolve o select num
// btn-group de largura fixa e acrescenta um botão de info e um "+". Esses
// extras estouram a largura declarada e desmontam a barra de filtros.
// Sem eles os campos ficam estreitos e todos os filtros cabem numa linha,
// sem precisar esconder nada atrás de um "mais filtros".
$dd_common = [
    'comments' => false,
    'addicon'  => false,
    'width'    => '100%',
];

echo "<div class='svm-bar-group'><label class='svm-bar-label'>" . __('Entidade') . "</label>";
Entity::dropdown($dd_common + [
    'name'                => 'svm_entity',
    'value'               => $filters['entities_id'] ?? -1,
    'display_emptychoice' => false,
    'toadd'               => [-1 => __('Todas', 'svm')],
]);
echo "</div>";

echo "<div class='svm-bar-group'><label class='svm-bar-label'>" . __('Categoria') . "</label>";
ITILCategory::dropdown($dd_common + [
    'name'                => 'category',
    'value'               => $filters['itilcategories_id'] ?? 0,
    'display_emptychoice' => true,
    'emptylabel'          => __('Todas', 'svm'),
]);
echo "</div>";

echo "<div class='svm-bar-group'><label class='svm-bar-label'>" . __('Técnico') . "</label>";
User::dropdown($dd_common + [
    'name'                => 'tech',
    'value'               => $filters['tech_id'] ?? 0,
    'right'               => 'own_ticket',
    'display_emptychoice' => true,
    'emptylabel'          => __('Todos', 'svm'),
]);
echo "</div>";

echo "<div class='svm-bar-group'><label class='svm-bar-label'>" . __('Grupo') . "</label>";
Group::dropdown($dd_common + [
    'name'                => 'group',
    'value'               => $filters['group_id'] ?? 0,
    'condition'           => ['is_assign' => 1],
    'display_emptychoice' => true,
    'emptylabel'          => __('Todos', 'svm'),
]);
echo "</div>";

echo "<div class='svm-bar-group svm-bar-narrow'><label class='svm-bar-label' title='"
   . svm_e(__('Mínimo de pesquisas para entrar no ranking', 'svm')) . "'>"
   . __('Amostra mín.', 'svm') . "</label>";
echo "<input type='number' class='form-control form-control-sm' name='min_sample'"
   . " min='1' max='100' value='" . (int)$filters['min_sample'] . "'>";
echo "</div>";

echo "<div class='svm-bar-actions'>";
echo "<button type='submit' class='btn btn-sm btn-primary'>" . __('Aplicar', 'svm') . "</button>";
echo "<a class='btn btn-sm btn-outline-secondary' href='" . $_SERVER['PHP_SELF'] . "' title='"
   . svm_e(__('Limpar filtros', 'svm')) . "'><i class='fas fa-times'></i></a>";

if ($can_export) {
    $qs_params = $_GET;
    unset($qs_params['format'], $qs_params['download']);
    $qs = http_build_query($qs_params);

    echo "<a class='btn btn-sm btn-outline-secondary' href='export.php?" . svm_e($qs)
       . "' title='" . svm_e(__('Planilha para Excel', 'svm')) . "'>"
       . "<i class='fas fa-file-csv'></i></a>";
    echo "<a class='btn btn-sm btn-outline-secondary' href='export.php?format=json&" . svm_e($qs)
       . "' target='_blank' title='" . svm_e(__('JSON para Power BI, Grafana e afins', 'svm'))
       . "'><i class='fas fa-code'></i></a>";
}
echo "</div>";

// Chips de filtro ativo, na mesma faixa
$chips = PluginSvmReport::activeChips($filters);
if (!empty($chips)) {
    echo "<div class='svm-chips'>";
    foreach ($chips as $chip) {
        echo "<a class='svm-chip' href='"
           . svm_e(PluginSvmReport::drillUrl($filters, $chip['key'], null)) . "' title='"
           . svm_e(__('Remover este filtro', 'svm')) . "'>";
        echo svm_e($chip['value']) . " <i class='fas fa-times'></i></a>";
    }
    echo "</div>";
}

echo "</form>";

if ($data['truncated']) {
    echo "<div class='alert alert-warning py-1 px-2 mb-2'>" . sprintf(
        __('Mais de %d pesquisas no período: os números consideram as mais recentes.', 'svm'),
        PluginSvmReport::MAX_ROWS
    ) . "</div>";
}

if ((int)$totals['surveys'] === 0) {
    echo "<div class='alert alert-info'>"
       . __('Nenhuma pesquisa respondida com os filtros atuais.', 'svm') . "</div></div>";

    echo "<details class='svm-analytic'><summary>" . __('Analítico', 'svm') . "</summary>";
    Search::show('PluginSvmSurvey');
    echo "</details>";

    Html::footer();
    return;
}

// ======================================================================
// Faixa 1 — Termômetros + números
// ======================================================================
echo "<div class='svm-grid svm-grid-top'>";

svm_gauge(
    'CSAT',
    $totals['csat_percent'] === null ? null : (float)$totals['csat_percent'],
    0, 100,
    [[70, '#dc2626'], [85, '#f59e0b'], [100, '#16a34a']],
    __('70-85% bom · +85% excepcional', 'svm')
);

svm_gauge(
    'NPS',
    $totals['nps'] === null ? null : (float)$totals['nps'],
    -100, 100,
    [[0, '#dc2626'], [50, '#f59e0b'], [100, '#16a34a']],
    __('+50 excelente · +70 classe mundial', 'svm')
);

svm_gauge(
    __('Nota média', 'svm'),
    $totals['csat_avg'] === null ? null : (float)$totals['csat_avg'],
    0, 5,
    [[3, '#dc2626'], [4, '#f59e0b'], [5, '#16a34a']],
    __('referência para escala 1 a 5', 'svm')
);

// Números + composição do NPS
echo "<div class='svm-panel svm-panel-tight svm-facts'>";
echo "<div class='svm-stats'>";
svm_stat(__('Pesquisas', 'svm'), (string)(int)$totals['surveys']);
svm_stat(__('Respostas', 'svm'), (string)(int)$totals['answers']);
svm_stat(
    __('Detratores', 'svm'),
    (string)(int)$totals['detractors'],
    (int)$totals['detractors'] > 0 ? 'svm-kpi-bad' : '',
    __('contato em até 48h', 'svm')
);
svm_stat(__('Comentários', 'svm'), (string)(int)$totals['comments']);
echo "</div>";

if ((int)$totals['nps_answers'] > 0) {
    $n  = (int)$totals['nps_answers'];
    $pp = round(((int)$totals['promoters']  / $n) * 100, 1);
    $ps = round(((int)$totals['passives']   / $n) * 100, 1);
    $pd = round(((int)$totals['detractors'] / $n) * 100, 1);

    echo "<div class='svm-nps-mix'>";
    echo "<div class='svm-nps-mix-label'>" . sprintf(
        __('Composição do NPS · %d respostas', 'svm'), $n
    ) . "</div>";
    echo "<div class='svm-stack'>";
    if ($pd > 0) {
        echo "<span class='svm-stack-bad' style='width:{$pd}%' title='"
           . svm_e(sprintf(__('Detratores (0-6): %d', 'svm'), (int)$totals['detractors']))
           . "'>" . ($pd >= 12 ? $pd . '%' : '') . "</span>";
    }
    if ($ps > 0) {
        echo "<span class='svm-stack-mid' style='width:{$ps}%' title='"
           . svm_e(sprintf(__('Neutros (7-8): %d', 'svm'), (int)$totals['passives']))
           . "'>" . ($ps >= 12 ? $ps . '%' : '') . "</span>";
    }
    if ($pp > 0) {
        echo "<span class='svm-stack-good' style='width:{$pp}%' title='"
           . svm_e(sprintf(__('Promotores (9-10): %d', 'svm'), (int)$totals['promoters']))
           . "'>" . ($pp >= 12 ? $pp . '%' : '') . "</span>";
    }
    echo "</div>";
    echo "</div>";
}

echo "</div>"; // .svm-facts
echo "</div>"; // .svm-grid-top

// ======================================================================
// Faixa 2 — Tendência | Distribuição
// ======================================================================
echo "<div class='svm-grid svm-grid-charts'>";

// ---- Tendência ----
echo "<div class='svm-panel svm-panel-tight'>";
echo "<div class='svm-panel-head'>";
echo "<h3>" . __('Tendência do CSAT', 'svm') . "</h3>";
echo "<span class='svm-hint-inline'>" . __('passe o mouse nos pontos', 'svm') . "</span>";
echo "</div>";

if (count($data['timeline']) > 1) {
    $points = $data['timeline'];
    $n = count($points);

    $w = 760; $h = 170;
    $pad_l = 34; $pad_r = 12; $pad_t = 12; $pad_b = 34;
    $iw = $w - $pad_l - $pad_r;
    $ih = $h - $pad_t - $pad_b;

    $x = static fn(int $i): float => $n > 1
        ? $pad_l + ($iw * $i / ($n - 1))
        : $pad_l + $iw / 2;
    $y = static fn(float $v): float => $pad_t + $ih - ($ih * max(0, min(100, $v)) / 100);

    echo "<div class='svm-chart-wrap'>";
    echo "<svg viewBox='0 0 $w $h' class='svm-chart' id='svm-trend-chart' role='img'"
       . " aria-label='" . svm_e(__('Evolução mensal do CSAT', 'svm')) . "'>";

    // Faixas de referência
    printf("<rect x='%d' y='%.1f' width='%d' height='%.1f' fill='#16a34a' opacity='0.07'/>",
           $pad_l, $y(100), $iw, $y(85) - $y(100));
    printf("<rect x='%d' y='%.1f' width='%d' height='%.1f' fill='#f59e0b' opacity='0.07'/>",
           $pad_l, $y(85), $iw, $y(70) - $y(85));
    printf("<rect x='%d' y='%.1f' width='%d' height='%.1f' fill='#dc2626' opacity='0.07'/>",
           $pad_l, $y(70), $iw, $y(0) - $y(70));

    foreach ([0, 50, 70, 85, 100] as $tick) {
        printf("<line x1='%d' y1='%.1f' x2='%d' y2='%.1f' stroke='#e2e8f0' stroke-width='1'"
             . " stroke-dasharray='%s'/>",
               $pad_l, $y((float)$tick), $w - $pad_r, $y((float)$tick),
               in_array($tick, [70, 85], true) ? '3 3' : '0');
        printf("<text x='%d' y='%.1f' class='svm-chart-axis' text-anchor='end'>%d</text>",
               $pad_l - 5, $y((float)$tick) + 3, $tick);
    }

    $coords = [];
    foreach ($points as $i => $p) {
        if ($p['metrics']['csat_percent'] !== null) {
            $coords[] = [$i, (float)$p['metrics']['csat_percent']];
        }
    }

    if (count($coords) > 1) {
        $line = ''; $area = '';
        foreach ($coords as $k => [$i, $v]) {
            $seg = sprintf(' %.1f %.1f', $x($i), $y($v));
            $line .= ($k === 0 ? 'M' : ' L') . $seg;
            $area .= ($k === 0 ? 'M' : ' L') . $seg;
        }
        $area .= sprintf(' L %.1f %.1f L %.1f %.1f Z',
            $x($coords[count($coords) - 1][0]), $y(0), $x($coords[0][0]), $y(0));

        echo "<path d='$area' fill='#f97316' opacity='0.10'/>";
        echo "<path d='$line' fill='none' stroke='#ea580c' stroke-width='2'"
           . " stroke-linejoin='round' stroke-linecap='round'/>";
    }

    // Com muitos meses, escreve o rótulo de dois em dois
    $step = $n > 8 ? 2 : 1;

    foreach ($points as $i => $p) {
        $m  = $p['metrics'];
        $v  = $m['csat_percent'];
        $px = $x($i);

        if ($i % $step === 0) {
            printf("<text x='%.1f' y='%d' class='svm-chart-axis' text-anchor='middle'>%s</text>",
                   $px, $h - 18, svm_e($p['label']));
            printf("<text x='%.1f' y='%d' class='svm-chart-axis svm-chart-count'"
                 . " text-anchor='middle'>%d</text>", $px, $h - 6, (int)$m['surveys']);
        }

        if ($v === null) {
            continue;
        }

        $tip = $p['label'] . ' · CSAT ' . svm_num((float)$v, '%')
             . ' · ' . (int)$m['surveys'] . ' ' . __('pesquisas', 'svm')
             . ($m['nps'] === null ? '' : ' · NPS ' . svm_num($m['nps']));

        printf("<circle class='svm-chart-dot %s' cx='%.1f' cy='%.1f' r='4' data-tip='%s'>"
             . "<title>%s</title></circle>",
               PluginSvmReport::csatClass((float)$v), $px, $y((float)$v),
               svm_e($tip), svm_e($tip));
    }

    echo "</svg>";
    echo "<div class='svm-tip' id='svm-chart-tip' hidden></div>";
    echo "</div>";
} else {
    echo "<p class='svm-empty'>"
       . __('É preciso mais de um mês de dados para traçar a tendência.', 'svm') . "</p>";
}
echo "</div>";

// ---- Distribuição ----
echo "<div class='svm-panel svm-panel-tight'>";
echo "<div class='svm-panel-head'><h3>" . __('Distribuição das notas', 'svm') . "</h3></div>";

if (!empty($data['distribution'])) {
    $dist  = $data['distribution'];
    $total = array_sum($dist);
    $maxv  = max($dist);

    echo "<div class='svm-dist-cols'>";
    foreach ($dist as $score => $count) {
        $pct = $total > 0 ? round(($count / $total) * 100, 1) : 0;
        $hgt = $maxv > 0 ? max(3, round(($count / $maxv) * 100)) : 0;

        echo "<div class='svm-dist-col' title='" . svm_e(sprintf(
            __('Nota %1$s: %2$d respostas (%3$s%%)', 'svm'),
            $score, $count, svm_num((float)$pct)
        )) . "'>";
        echo "<span class='svm-dist-pct'>" . svm_num((float)$pct, '%') . "</span>";
        echo "<span class='svm-dist-track'><i style='height:{$hgt}%'></i></span>";
        echo "<span class='svm-dist-label'>" . (int)$score . "</span>";
        echo "</div>";
    }
    echo "</div>";
    echo "<p class='svm-note'>" . sprintf(
        __('%d notas. Distribuição em U indica experiências inconsistentes.', 'svm'), $total
    ) . "</p>";
} else {
    echo "<p class='svm-empty'>" . __('Sem notas no período.', 'svm') . "</p>";
}
echo "</div>";

echo "</div>"; // .svm-grid-charts

// ======================================================================
// Faixa 3 — Rankings em abas (CSS puro, funciona sem JS)
// ======================================================================
echo "<div class='svm-panel svm-panel-tight svm-tabs'>";

echo "<input type='radio' name='svm-rank-tab' id='svm-tab-tech' class='svm-tab-radio' checked>";
echo "<input type='radio' name='svm-rank-tab' id='svm-tab-group' class='svm-tab-radio'>";
echo "<input type='radio' name='svm-rank-tab' id='svm-tab-category' class='svm-tab-radio'>";

echo "<div class='svm-panel-head svm-tabs-head'>";
echo "<div class='svm-tabs-nav'>";
echo "<label for='svm-tab-tech'><i class='fas fa-user-cog'></i> " . __('Técnico') . "</label>";
echo "<label for='svm-tab-group'><i class='fas fa-users'></i> " . __('Grupo') . "</label>";
echo "<label for='svm-tab-category'><i class='fas fa-tags'></i> " . __('Categoria') . "</label>";
echo "</div>";
echo "<div class='svm-tabs-tools'>";
echo "<button type='button' class='svm-copy' data-target='svm-rank-tech'>"
   . "<i class='fas fa-copy'></i> " . __('Copiar', 'svm') . "</button>";
echo "</div>";
echo "</div>";

echo "<div class='svm-tab-panels'>";

echo "<div class='svm-tab-panel' data-tab='tech'>";
svm_rank_panel($data['by_tech'], (int)$filters['min_sample'], __('Técnico'), 'tech', $filters);
echo "</div>";

echo "<div class='svm-tab-panel' data-tab='group'>";
svm_rank_panel($data['by_group'], (int)$filters['min_sample'], __('Grupo'), 'group', $filters);
echo "</div>";

echo "<div class='svm-tab-panel' data-tab='category'>";
svm_rank_panel($data['by_category'], (int)$filters['min_sample'], __('Categoria'), 'category', $filters);
echo "</div>";

echo "</div></div>";

// ======================================================================
// Recolhidos — detratores e analítico
// ======================================================================
if (!empty($data['detractors'])) {
    echo "<details class='svm-panel svm-panel-alert svm-collapsible'>";
    echo "<summary>";
    echo "<span class='svm-summary-title'><i class='fas fa-exclamation-triangle'></i> "
       . __('Precisam de contato', 'svm') . "</span>";
    echo "<span class='svm-badge'>" . count($data['detractors']) . "</span>";
    echo "<span class='svm-summary-hint'>"
       . __('detratores e notas baixas · contato em até 48h', 'svm') . "</span>";
    echo "</summary>";

    echo "<div class='svm-table-scroll'>";
    echo "<table class='svm-rank svm-sortable' id='svm-detractors'>";
    echo "<thead><tr>";
    echo "<th data-sort='text'>" . __('Chamado') . "</th>";
    echo "<th data-sort='text'>" . __('Data') . "</th>";
    echo "<th class='svm-num' data-sort='num'>CSAT</th>";
    echo "<th class='svm-num' data-sort='num'>NPS</th>";
    echo "<th data-sort='text'>" . __('Técnico') . "</th>";
    echo "<th>" . __('Comentário') . "</th>";
    echo "</tr></thead><tbody>";

    foreach ($data['detractors'] as $d) {
        echo "<tr>";
        echo "<td><a href='" . Ticket::getFormURLWithID($d['tickets_id']) . "' target='_blank'>#"
           . (int)$d['tickets_id'] . "</a> "
           . svm_e(Toolbox::substr($d['ticket_name'], 0, 34)) . "</td>";
        echo "<td data-value='" . svm_e($d['date']) . "'>"
           . svm_e(Html::convDateTime($d['date'])) . "</td>";
        echo "<td class='svm-num' data-value='"
           . ($d['csat_percent'] === null ? -1 : (float)$d['csat_percent']) . "'>"
           . svm_num($d['csat_percent'], '%') . "</td>";
        echo "<td class='svm-num' data-value='" . (int)$d['nps_score'] . "'>"
           . ($d['nps_score'] < 0 ? '—' : (int)$d['nps_score']) . "</td>";
        echo "<td>" . svm_e(implode(', ', $d['techs'])) . "</td>";
        echo "<td class='svm-comment'>" . svm_e($d['comment']) . "</td>";
        echo "</tr>";
    }

    echo "</tbody></table></div>";
    echo "</details>";
}

echo "<p class='svm-note'>"
   . __('Um chamado com vários técnicos atribuídos conta para cada um deles, então a soma das pesquisas por técnico pode exceder o total.', 'svm')
   . "</p>";

echo "</div>"; // .svm-dash

echo "<details class='svm-analytic'>";
echo "<summary><i class='fas fa-table'></i> " . __('Analítico — todas as respostas', 'svm')
   . "</summary>";
Search::show('PluginSvmSurvey');
echo "</details>";

// ======================================================================
// Interatividade
// ======================================================================
// URL do endpoint de detalhe + querystring dos filtros vigentes
$detail_base = Plugin::getWebDir('svm') . '/ajax/detail.php';
$detail_qs   = http_build_query([
    'period'     => (int)$filters['period'],
    'min_sample' => (int)$filters['min_sample'],
]
+ ($filters['entities_id'] !== null       ? ['svm_entity' => (int)$filters['entities_id']] : [])
+ ($filters['itilcategories_id'] !== null ? ['category' => (int)$filters['itilcategories_id']] : []));

echo Html::scriptBlock(
    'window.SVM_DETAIL_URL = ' . json_encode($detail_base, JSON_UNESCAPED_SLASHES) . ';'
    . 'window.SVM_DETAIL_QS = ' . json_encode($detail_qs, JSON_UNESCAPED_SLASHES) . ';'
);

echo Html::scriptBlock(<<<'JS'
(function () {
    // ---- Modal de detalhamento ----
    // Modal próprio, sem depender do Bootstrap da página.
    var overlay = null;

    function buildModal() {
        if (overlay) { return overlay; }

        overlay = document.createElement('div');
        overlay.className = 'svm-modal-overlay';
        overlay.hidden = true;
        overlay.innerHTML =
            '<div class="svm-modal" role="dialog" aria-modal="true" aria-label="Detalhamento">'
          + '<button type="button" class="svm-modal-close" aria-label="Fechar">'
          + '<i class="fas fa-times"></i></button>'
          + '<div class="svm-modal-body"></div>'
          + '</div>';

        document.body.appendChild(overlay);

        overlay.addEventListener('click', function (e) {
            if (e.target === overlay || e.target.closest('.svm-modal-close')) {
                closeModal();
            }
        });

        return overlay;
    }

    function closeModal() {
        if (!overlay) { return; }
        overlay.hidden = true;
        document.body.classList.remove('svm-modal-open');
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { closeModal(); }
    });

    function openDetail(type, id, label) {
        var el = buildModal();
        var body = el.querySelector('.svm-modal-body');

        body.innerHTML = '<div class="svm-modal-loading">'
                       + '<i class="fas fa-circle-notch fa-spin"></i> Carregando…</div>';
        el.hidden = false;
        document.body.classList.add('svm-modal-open');

        var url = window.SVM_DETAIL_URL + '?' + window.SVM_DETAIL_QS
                + '&type=' + encodeURIComponent(type)
                + '&id=' + encodeURIComponent(id)
                + '&label=' + encodeURIComponent(label || '');

        $.get(url)
            .done(function (html) {
                body.innerHTML = html;
                bindSorting(body);
            })
            .fail(function () {
                body.innerHTML = '<p class="svm-empty">'
                               + 'Não foi possível carregar o detalhamento.</p>';
            });
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('.svm-open-detail');
        if (!btn) { return; }
        e.preventDefault();
        openDetail(btn.getAttribute('data-type'),
                   btn.getAttribute('data-id'),
                   btn.getAttribute('data-label'));
    });

    // ---- Tooltip do gráfico ----
    var tip = document.getElementById('svm-chart-tip');
    var chart = document.getElementById('svm-trend-chart');

    if (tip && chart) {
        chart.addEventListener('mouseover', function (e) {
            var dot = e.target.closest && e.target.closest('.svm-chart-dot');
            if (!dot) { return; }
            tip.textContent = dot.getAttribute('data-tip') || '';
            tip.hidden = false;
        });
        chart.addEventListener('mousemove', function (e) {
            if (tip.hidden) { return; }
            var box = chart.parentNode.getBoundingClientRect();
            tip.style.left = (e.clientX - box.left) + 'px';
            tip.style.top  = (e.clientY - box.top) + 'px';
        });
        chart.addEventListener('mouseout', function (e) {
            if (e.target.closest && e.target.closest('.svm-chart-dot')) { tip.hidden = true; }
        });
    }

    // ---- O botão Copiar segue a aba visível ----
    var copyBtn = document.querySelector('.svm-tabs-tools .svm-copy');
    var tabMap = { 'svm-tab-tech': 'svm-rank-tech',
                   'svm-tab-group': 'svm-rank-group',
                   'svm-tab-category': 'svm-rank-category' };

    document.querySelectorAll('.svm-tab-radio').forEach(function (radio) {
        radio.addEventListener('change', function () {
            if (copyBtn && tabMap[radio.id]) {
                copyBtn.setAttribute('data-target', tabMap[radio.id]);
            }
        });
    });

    // ---- Ordenação ----
    function cellValue(row, index) {
        var cell = row.cells[index];
        if (!cell) { return ''; }
        var raw = cell.getAttribute('data-value');
        return raw !== null ? raw : cell.textContent.trim();
    }

    // Recebe um escopo para poder ligar também na tabela do modal, que
    // chega depois do carregamento inicial.
    function bindSorting(root) {
    (root || document).querySelectorAll('table.svm-sortable thead th[data-sort]').forEach(function (th) {
        if (th.classList.contains('svm-th-sortable')) { return; }
        th.classList.add('svm-th-sortable');
        th.setAttribute('tabindex', '0');

        function sort() {
            var table = th.closest('table');
            var tbody = table.tBodies[0];
            var index = Array.prototype.indexOf.call(th.parentNode.cells, th);
            var numeric = th.getAttribute('data-sort') === 'num';
            var asc = th.getAttribute('data-dir') !== 'asc';

            table.querySelectorAll('thead th').forEach(function (o) {
                if (o !== th) { o.removeAttribute('data-dir'); }
            });
            th.setAttribute('data-dir', asc ? 'asc' : 'desc');

            var rows = Array.prototype.slice.call(tbody.rows);
            rows.sort(function (a, b) {
                var va = cellValue(a, index), vb = cellValue(b, index);
                if (numeric) {
                    var na = parseFloat(String(va).replace(',', '.')) || 0;
                    var nb = parseFloat(String(vb).replace(',', '.')) || 0;
                    return asc ? na - nb : nb - na;
                }
                return asc ? va.localeCompare(vb, 'pt-BR') : vb.localeCompare(va, 'pt-BR');
            });

            rows.forEach(function (r) { tbody.appendChild(r); });
            table.classList.add('svm-resorted');
        }

        th.addEventListener('click', sort);
        th.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); sort(); }
        });
    });
    }

    bindSorting(document);

    // ---- Copiar como TSV ----
    document.querySelectorAll('.svm-copy').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var table = document.getElementById(btn.getAttribute('data-target'));
            if (!table) { return; }

            var lines = [];
            Array.prototype.forEach.call(table.rows, function (row) {
                var cols = [];
                Array.prototype.forEach.call(row.cells, function (cell) {
                    // Posição e ações não vão para a planilha. O filtro é por
                    // classe (presente no th e no td) para o cabeçalho não
                    // ficar com uma coluna a mais.
                    if (cell.classList.contains('svm-rank-pos')
                        || cell.classList.contains('svm-rank-actions')) {
                        return;
                    }
                    var raw = cell.getAttribute('data-value');
                    var txt = raw !== null ? raw : cell.textContent;
                    cols.push(String(txt).replace(/\s+/g, ' ').trim());
                });
                if (cols.length) { lines.push(cols.join('\t')); }
            });

            var text = lines.join('\n');
            var done = function () {
                var old = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-check"></i> copiado';
                setTimeout(function () { btn.innerHTML = old; }, 1600);
            };

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done, function () {});
            } else {
                var ta = document.createElement('textarea');
                ta.value = text;
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); done(); } catch (err) {}
                document.body.removeChild(ta);
            }
        });
    });
})();
JS
);

Html::footer();
