<?php
/**
 * Plugin SVM - Agregação dos indicadores da pesquisa.
 *
 * A agregação é feita em PHP e não em SQL. Motivo: expressões agregadas no
 * query builder do GLPI exigiriam QueryExpression, que mudou de namespace
 * entre o GLPI 10.0 e o 10.1. Como o volume é de uma linha por chamado
 * avaliado (e sempre filtrado por período), o custo é aceitável e o código
 * fica compatível com as duas versões.
 */

if (!defined('GLPI_ROOT')) {
    die("Access denied");
}

class PluginSvmReport extends CommonGLPI
{
    public static $rightname = 'plugin_svm_report';

    /**
     * Mínimo de respostas para uma linha entrar no ranking. Abaixo disso o
     * percentual é ruído: com 2 respostas só existem os valores 0%, 50% e
     * 100%, e qualquer um deles "lidera" ou "afunda" o ranking sem
     * significado estatístico.
     */
    const MIN_SAMPLE = 5;

    /** Teto de linhas processadas por requisição. */
    const MAX_ROWS = 20000;

    public static function getTypeName($nb = 0) {
        return __('Indicadores da pesquisa', 'svm');
    }

    // ==================================================================
    // Filtros
    // ==================================================================

    public static function getPeriodOptions(): array {
        return [
            30  => __('Últimos 30 dias', 'svm'),
            90  => __('Últimos 90 dias', 'svm'),
            180 => __('Últimos 6 meses', 'svm'),
            365 => __('Últimos 12 meses', 'svm'),
            0   => __('Todo o período', 'svm'),
        ];
    }

    /** Lê os filtros da querystring, com defaults sãos. */
    public static function readFilters(array $request): array {
        $period = isset($request['period']) ? (int)$request['period'] : 90;
        if (!array_key_exists($period, self::getPeriodOptions())) {
            $period = 90;
        }

        // Os dropdowns ajax do GLPI enviam id 0 para a opção vazia
        // ("Todos"). Tratar 0 como filtro real zeraria o painel inteiro,
        // então 0 e '' significam "sem filtro" para categoria/técnico/grupo.
        //
        // Entidade é diferente: 0 é a raiz, um id válido. Por isso o
        // formulário usa -1 como sentinela de "todas".
        $entity = null;
        if (isset($request['svm_entity']) && $request['svm_entity'] !== ''
            && (int)$request['svm_entity'] >= 0) {
            $entity = (int)$request['svm_entity'];
        }

        return [
            'period'           => $period,
            'entities_id'      => $entity,
            'itilcategories_id'=> !empty($request['category']) ? (int)$request['category'] : null,
            'tech_id'          => !empty($request['tech'])     ? (int)$request['tech']     : null,
            'group_id'         => !empty($request['group'])    ? (int)$request['group']    : null,
            'min_sample'       => isset($request['min_sample'])
                                  ? max(1, min(100, (int)$request['min_sample']))
                                  : self::MIN_SAMPLE,
        ];
    }

    // ==================================================================
    // Coleta
    // ==================================================================

    /**
     * Devolve o conjunto completo de dados do painel.
     *
     * @return array
     */
    public static function collect(array $filters): array {
        global $DB;

        $t_survey = 'glpi_plugin_svm_surveys';

        $where = ['s.answer_status' => PluginSvmSurvey::STATUS_ANSWERED];

        if ((int)$filters['period'] > 0) {
            $where[] = [
                's.date_creation' => [
                    '>=',
                    date('Y-m-d 00:00:00', strtotime('-' . (int)$filters['period'] . ' days')),
                ],
            ];
        }

        if ($filters['entities_id'] !== null) {
            // Escolher uma entidade-pai inclui as filhas: é o que o gestor
            // espera ao filtrar por uma diretoria.
            $scope = getSonsOf('glpi_entities', (int)$filters['entities_id']);
            $where['s.entities_id'] = !empty($scope)
                ? array_values(array_map('intval', $scope))
                : [(int)$filters['entities_id']];
        }

        // Restrição de entidade da sessão: nunca mostrar fora do escopo.
        // Só aplica se a sessão tem entidades ativas — com a lista vazia o
        // GLPI devolveria um IN () e o iterador lançaria exceção.
        if (!empty($_SESSION['glpiactiveentities'])) {
            $entity_crit = getEntitiesRestrictCriteria($t_survey, 'entities_id', '', false);
            if (!empty($entity_crit)) {
                // A restrição vem qualificada com o nome real da tabela;
                // aqui usamos alias, então reescrevemos as chaves.
                $where[] = self::rewriteEntityCriteria($entity_crit, $t_survey, 's');
            }
        }

        // Quem não pode ler tudo vê apenas as próprias respostas.
        if (!PluginSvmProfile::canReadAllSurveys()) {
            $where['s.users_id'] = Session::getLoginUserID();
        }

        if ($filters['itilcategories_id'] !== null) {
            $where['t.itilcategories_id'] = (int)$filters['itilcategories_id'];
        }

        // ---- Consulta principal: pesquisa + chamado ----
        $rows = [];
        foreach ($DB->request([
            'SELECT' => [
                's.id', 's.tickets_id', 's.users_id', 's.entities_id',
                's.csat_avg', 's.answers_count', 's.satisfied_count',
                's.nps_score', 's.comment', 's.date_creation',
                's.score_value', 's.score_tech', 's.score_speed',
                's.plugin_svm_configs_id',
                't.itilcategories_id',
                't.name AS ticket_name',
            ],
            'FROM'       => $t_survey . ' AS s',
            'INNER JOIN' => [
                'glpi_tickets AS t' => ['ON' => ['s' => 'tickets_id', 't' => 'id']],
            ],
            'WHERE' => $where,
            'ORDER' => 's.date_creation DESC',
            'LIMIT' => self::MAX_ROWS,
        ]) as $row) {
            $rows[] = $row;
        }

        // Aferido AQUI, antes dos filtros em PHP: medido depois, qualquer
        // filtro de técnico/grupo esconderia o aviso de truncamento.
        $truncated = count($rows) >= self::MAX_ROWS;

        if (empty($rows)) {
            return self::emptyDataset($filters, $truncated);
        }

        // ---- Normaliza contagens (inclui dados legados) ----
        $thresholds = self::loadThresholds($rows);
        foreach ($rows as &$row) {
            self::normalizeCounts($row, $thresholds);
        }
        unset($row);

        $ticket_ids = array_values(array_unique(array_map(
            static fn($r) => (int)$r['tickets_id'],
            $rows
        )));

        // ---- Técnicos e grupos atribuídos ----
        $techs  = self::loadAssignedUsers($ticket_ids);
        $groups = self::loadAssignedGroups($ticket_ids);

        // Filtro por técnico/grupo aplicado depois do join (um chamado pode
        // ter mais de um atribuído).
        if ($filters['tech_id'] !== null) {
            $rows = array_values(array_filter($rows, static function ($r) use ($techs, $filters) {
                $list = $techs[(int)$r['tickets_id']] ?? [];
                return isset($list[(int)$filters['tech_id']]);
            }));
        }

        if ($filters['group_id'] !== null) {
            $rows = array_values(array_filter($rows, static function ($r) use ($groups, $filters) {
                $list = $groups[(int)$r['tickets_id']] ?? [];
                return isset($list[(int)$filters['group_id']]);
            }));
        }

        if (empty($rows)) {
            return self::emptyDataset($filters, $truncated);
        }

        $categories = self::loadCategoryNames($rows);

        return [
            'filters'      => $filters,
            'truncated'    => $truncated,
            'totals'       => self::aggregate($rows),
            'timeline'     => self::buildTimeline($rows),
            'distribution' => self::buildDistribution($rows),
            'by_tech'      => self::groupBy($rows, $techs, 'tickets_id'),
            'by_group'     => self::groupBy($rows, $groups, 'tickets_id'),
            'by_category'  => self::groupByScalar($rows, 'itilcategories_id', $categories),
            'detractors'   => self::listDetractors($rows, $techs),
            'rows'         => $rows,
        ];
    }

    private static function emptyDataset(array $filters, bool $truncated = false): array {
        return [
            'filters'      => $filters,
            'truncated'    => $truncated,
            'totals'       => self::aggregate([]),
            'timeline'     => [],
            'distribution' => [],
            'by_tech'      => [],
            'by_group'     => [],
            'by_category'  => [],
            'detractors'   => [],
            'rows'         => [],
        ];
    }

    /**
     * A restrição de entidade do GLPI vem com o nome real da tabela; aqui
     * usamos alias "s", então trocamos a chave.
     */
    private static function rewriteEntityCriteria($crit, string $table, string $alias) {
        if (!is_array($crit)) {
            return $crit;
        }

        $out = [];
        foreach ($crit as $key => $value) {
            $new_key = is_string($key)
                ? str_replace($table . '.', $alias . '.', $key)
                : $key;

            $out[$new_key] = is_array($value)
                ? self::rewriteEntityCriteria($value, $table, $alias)
                : $value;
        }

        return $out;
    }

    // ==================================================================
    // Normalização
    // ==================================================================

    /** Limiar de "satisfeito" de cada configuração envolvida. */
    private static function loadThresholds(array $rows): array {
        global $DB;

        $ids = array_values(array_unique(array_filter(array_map(
            static fn($r) => (int)$r['plugin_svm_configs_id'],
            $rows
        ))));

        $out = [];
        if (empty($ids)) {
            return $out;
        }

        foreach ($DB->request([
            'SELECT' => ['id', 'csat_satisfied_threshold', 'scale_min', 'scale_max', 'scale_type'],
            'FROM'   => 'glpi_plugin_svm_configs',
            'WHERE'  => ['id' => $ids],
        ]) as $row) {
            $cfg = PluginSvmConfig::normalize($row);
            $out[(int)$row['id']] = [
                'threshold' => (int)$cfg['csat_satisfied_threshold'],
                'min'       => (int)$cfg['scale_min'],
                'max'       => (int)$cfg['scale_max'],
            ];
        }

        return $out;
    }

    /**
     * Preenche answers_count / satisfied_count quando estão zerados.
     *
     * Registros criados antes da 3.0.0 só têm score_value/tech/speed. Sem
     * este fallback o histórico apareceria como "sem respostas" no painel.
     */
    private static function normalizeCounts(array &$row, array $thresholds): void {
        $row['answers_count']   = (int)$row['answers_count'];
        $row['satisfied_count'] = (int)$row['satisfied_count'];
        $row['is_legacy']       = false;

        if ($row['answers_count'] > 0) {
            return;
        }

        $cfg = $thresholds[(int)$row['plugin_svm_configs_id']] ?? ['threshold' => 4];
        $threshold = (int)$cfg['threshold'];

        $scores = [];
        foreach (['score_value', 'score_tech', 'score_speed'] as $field) {
            $v = (int)($row[$field] ?? 0);
            if ($v > 0) {
                $scores[] = $v;
            }
        }

        if (empty($scores)) {
            return;
        }

        $row['is_legacy']       = true;
        $row['answers_count']   = count($scores);
        $row['satisfied_count'] = count(array_filter(
            $scores,
            static fn($s) => $s >= $threshold
        ));

        if ((float)$row['csat_avg'] <= 0) {
            $row['csat_avg'] = round(array_sum($scores) / count($scores), 2);
        }
    }

    // ==================================================================
    // Consultas auxiliares
    // ==================================================================

    /** [tickets_id => [users_id => nome]] dos técnicos atribuídos. */
    private static function loadAssignedUsers(array $ticket_ids): array {
        global $DB;

        $out = [];
        if (empty($ticket_ids)) {
            return $out;
        }

        foreach ($DB->request([
            'SELECT' => [
                'tu.tickets_id',
                'u.id AS users_id',
                'u.name', 'u.realname', 'u.firstname',
            ],
            'FROM'       => 'glpi_tickets_users AS tu',
            'INNER JOIN' => [
                'glpi_users AS u' => ['ON' => ['tu' => 'users_id', 'u' => 'id']],
            ],
            'WHERE' => [
                'tu.tickets_id' => $ticket_ids,
                'tu.type'       => CommonITILActor::ASSIGN,
            ],
        ]) as $row) {
            $label = trim(($row['realname'] ?? '') . ' ' . ($row['firstname'] ?? ''));
            if ($label === '') {
                $label = (string)$row['name'];
            }
            $out[(int)$row['tickets_id']][(int)$row['users_id']] = $label;
        }

        return $out;
    }

    /** [tickets_id => [groups_id => nome]] dos grupos atribuídos. */
    private static function loadAssignedGroups(array $ticket_ids): array {
        global $DB;

        $out = [];
        if (empty($ticket_ids)) {
            return $out;
        }

        foreach ($DB->request([
            'SELECT' => [
                'gt.tickets_id',
                'g.id AS groups_id',
                'g.name',
            ],
            'FROM'       => 'glpi_groups_tickets AS gt',
            'INNER JOIN' => [
                'glpi_groups AS g' => ['ON' => ['gt' => 'groups_id', 'g' => 'id']],
            ],
            'WHERE' => [
                'gt.tickets_id' => $ticket_ids,
                'gt.type'       => CommonITILActor::ASSIGN,
            ],
        ]) as $row) {
            $out[(int)$row['tickets_id']][(int)$row['groups_id']] = (string)$row['name'];
        }

        return $out;
    }

    /** [itilcategories_id => nome completo] */
    private static function loadCategoryNames(array $rows): array {
        global $DB;

        $ids = array_values(array_unique(array_filter(array_map(
            static fn($r) => (int)$r['itilcategories_id'],
            $rows
        ))));

        $out = [0 => __('(sem categoria)', 'svm')];
        if (empty($ids)) {
            return $out;
        }

        foreach ($DB->request([
            'SELECT' => ['id', 'completename'],
            'FROM'   => 'glpi_itilcategories',
            'WHERE'  => ['id' => $ids],
        ]) as $row) {
            $out[(int)$row['id']] = (string)$row['completename'];
        }

        return $out;
    }

    // ==================================================================
    // Agregação
    // ==================================================================

    /**
     * Consolida um conjunto de linhas.
     *
     * CSAT% = soma das respostas satisfeitas / soma das respostas. Fazer
     * média dos percentuais de cada pesquisa daria peso igual a pesquisas
     * com quantidades diferentes de perguntas.
     */
    public static function aggregate(array $rows): array {
        $surveys   = count($rows);
        $answers   = 0;
        $satisfied = 0;
        $avg_sum   = 0.0;
        $avg_count = 0;
        $promoters = 0;
        $passives  = 0;
        $detractors = 0;
        $nps_resp  = 0;
        $comments  = 0;

        foreach ($rows as $row) {
            $answers   += (int)$row['answers_count'];
            $satisfied += (int)$row['satisfied_count'];

            if ((float)$row['csat_avg'] > 0) {
                $avg_sum += (float)$row['csat_avg'];
                $avg_count++;
            }

            $score = (int)$row['nps_score'];
            if ($score >= 0) {
                $nps_resp++;
                switch (PluginSvmConfig::classifyNps($score)) {
                    case 'promoter':  $promoters++;  break;
                    case 'passive':   $passives++;   break;
                    case 'detractor': $detractors++; break;
                }
            }

            if (trim((string)$row['comment']) !== '') {
                $comments++;
            }
        }

        return [
            'surveys'      => $surveys,
            'answers'      => $answers,
            'satisfied'    => $satisfied,
            'csat_percent' => $answers > 0 ? round(($satisfied / $answers) * 100, 1) : null,
            'csat_avg'     => $avg_count > 0 ? round($avg_sum / $avg_count, 2) : null,
            'nps'          => $nps_resp > 0
                              ? round((($promoters - $detractors) / $nps_resp) * 100, 1)
                              : null,
            'nps_answers'  => $nps_resp,
            'promoters'    => $promoters,
            'passives'     => $passives,
            'detractors'   => $detractors,
            'comments'     => $comments,
        ];
    }

    /** Agrupa por dimensão multivalorada (técnico, grupo). */
    private static function groupBy(array $rows, array $map, string $key_field): array {
        $buckets = [];

        foreach ($rows as $row) {
            $items = $map[(int)$row[$key_field]] ?? [];

            if (empty($items)) {
                $items = [0 => __('(sem atribuição)', 'svm')];
            }

            foreach ($items as $id => $label) {
                if (!isset($buckets[$id])) {
                    $buckets[$id] = ['id' => (int)$id, 'label' => $label, 'rows' => []];
                }
                $buckets[$id]['rows'][] = $row;
            }
        }

        return self::finishBuckets($buckets);
    }

    /** Agrupa por coluna escalar (categoria). */
    private static function groupByScalar(array $rows, string $field, array $labels): array {
        $buckets = [];

        foreach ($rows as $row) {
            $id = (int)$row[$field];
            if (!isset($buckets[$id])) {
                $buckets[$id] = [
                    'id'    => $id,
                    'label' => $labels[$id] ?? ('#' . $id),
                    'rows'  => [],
                ];
            }
            $buckets[$id]['rows'][] = $row;
        }

        return self::finishBuckets($buckets);
    }

    /** Calcula os indicadores de cada bucket e ordena por CSAT. */
    private static function finishBuckets(array $buckets): array {
        $out = [];

        foreach ($buckets as $bucket) {
            $metrics = self::aggregate($bucket['rows']);
            $out[] = [
                'id'      => $bucket['id'],
                'label'   => $bucket['label'],
                'metrics' => $metrics,
            ];
        }

        // Mais satisfeito primeiro; sem CSAT vai para o fim.
        usort($out, static function ($a, $b) {
            $ca = $a['metrics']['csat_percent'];
            $cb = $b['metrics']['csat_percent'];

            if ($ca === null && $cb === null) {
                return $b['metrics']['surveys'] <=> $a['metrics']['surveys'];
            }
            if ($ca === null) { return 1; }
            if ($cb === null) { return -1; }

            if ($cb == $ca) {
                return $b['metrics']['surveys'] <=> $a['metrics']['surveys'];
            }
            return $cb <=> $ca;
        });

        return $out;
    }

    /** Série mensal de CSAT% e NPS. */
    private static function buildTimeline(array $rows): array {
        $months = [];

        foreach ($rows as $row) {
            $month = substr((string)$row['date_creation'], 0, 7);
            if ($month === '') {
                continue;
            }
            $months[$month][] = $row;
        }

        ksort($months);

        $out = [];
        foreach ($months as $month => $bucket) {
            $out[] = [
                'month'   => $month,
                'label'   => self::formatMonth($month),
                'metrics' => self::aggregate($bucket),
            ];
        }

        return $out;
    }

    private static function formatMonth(string $ym): string {
        $parts = explode('-', $ym);
        if (count($parts) !== 2) {
            return $ym;
        }
        $names = [1 => 'jan', 'fev', 'mar', 'abr', 'mai', 'jun',
                       'jul', 'ago', 'set', 'out', 'nov', 'dez'];
        $m     = (int)$parts[1];
        $label = $names[$m] ?? $parts[1];

        return $label . '/' . substr($parts[0], 2);
    }

    /**
     * Distribuição das notas das perguntas de escala.
     * Lida da tabela de respostas, que guarda o valor de cada pergunta.
     */
    private static function buildDistribution(array $rows): array {
        global $DB;

        $dist    = [];
        $modern  = [];

        // Linhas legadas não têm registro em _answers: as notas vêm das
        // colunas score_*. Somadas sempre, e não só quando não há dado
        // novo — do contrário uma base mista perderia todo o histórico.
        foreach ($rows as $row) {
            if (!empty($row['is_legacy'])) {
                foreach (['score_value', 'score_tech', 'score_speed'] as $f) {
                    $v = (int)($row[$f] ?? 0);
                    if ($v > 0) {
                        $dist[$v] = ($dist[$v] ?? 0) + 1;
                    }
                }
                continue;
            }
            $modern[] = (int)$row['id'];
        }

        // Consulta em blocos: mantém a cobertura de todo o conjunto sem
        // montar um IN gigantesco.
        foreach (array_chunk($modern, 2000) as $chunk) {
            foreach ($DB->request([
                'SELECT'     => ['a.answer_int'],
                'FROM'       => 'glpi_plugin_svm_answers AS a',
                'INNER JOIN' => [
                    'glpi_plugin_svm_questions AS q' => [
                        'ON' => ['a' => 'plugin_svm_questions_id', 'q' => 'id'],
                    ],
                ],
                'WHERE' => [
                    'a.plugin_svm_surveys_id' => $chunk,
                    'q.question_type'         => 'scale',
                    ['NOT' => ['a.answer_int' => -1]],
                ],
            ]) as $row) {
                $v = (int)$row['answer_int'];
                $dist[$v] = ($dist[$v] ?? 0) + 1;
            }
        }

        ksort($dist);
        return $dist;
    }

    /**
     * Pesquisas que pedem contato: detrator de NPS ou comentário com nota
     * baixa. A boa prática é retornar a esses casos em até 48h.
     */
    private static function listDetractors(array $rows, array $techs, int $limit = 25): array {
        $out = [];

        foreach ($rows as $row) {
            $nps      = (int)$row['nps_score'];
            $answers  = (int)$row['answers_count'];
            $csat_pct = $answers > 0
                ? ((int)$row['satisfied_count'] / $answers) * 100
                : null;

            $is_detractor = ($nps >= 0 && PluginSvmConfig::classifyNps($nps) === 'detractor')
                            || ($csat_pct !== null && $csat_pct < 50);

            if (!$is_detractor) {
                continue;
            }

            $out[] = [
                'tickets_id'  => (int)$row['tickets_id'],
                'ticket_name' => (string)$row['ticket_name'],
                'date'        => (string)$row['date_creation'],
                'csat_percent'=> $csat_pct === null ? null : round($csat_pct, 1),
                'csat_avg'    => (float)$row['csat_avg'],
                'nps_score'   => $nps,
                'comment'     => (string)$row['comment'],
                'techs'       => array_values($techs[(int)$row['tickets_id']] ?? []),
            ];
        }

        usort($out, static fn($a, $b) => strcmp($b['date'], $a['date']));

        return array_slice($out, 0, $limit);
    }

    // ==================================================================
    // Leitura dos indicadores
    // ==================================================================

    /** Classe de cor para um CSAT%: 70-85 bom, acima de 85 excepcional. */
    public static function csatClass(?float $value): string {
        if ($value === null) { return 'svm-kpi-none'; }
        if ($value >= 85)    { return 'svm-kpi-great'; }
        if ($value >= 70)    { return 'svm-kpi-ok'; }
        return 'svm-kpi-bad';
    }

    /** Classe de cor para um NPS: acima de 50 excelente, 70+ classe mundial. */
    public static function npsClass(?float $value): string {
        if ($value === null) { return 'svm-kpi-none'; }
        if ($value >= 50)    { return 'svm-kpi-great'; }
        if ($value >= 0)     { return 'svm-kpi-ok'; }
        return 'svm-kpi-bad';
    }
}
