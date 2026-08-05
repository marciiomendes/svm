<?php
/**
 * Plugin SVM - Pesquisa respondida.
 *
 * Toda a lógica de elegibilidade, gatilho e cálculo de indicadores passou
 * a ler a configuração da entidade (PluginSvmConfig) em vez dos valores
 * que antes estavam fixos no código (entidade 1, escala 1-5, 3 perguntas).
 */

if (!defined('GLPI_ROOT')) {
    die("Access denied");
}

class PluginSvmSurvey extends CommonDBTM
{
    public static $rightname = 'plugin_svm_survey';

    public $dohistory = false;

    const STATUS_ANSWERED = 'answered';
    const STATUS_SKIPPED  = 'skipped';
    const STATUS_EXPIRED  = 'expired';

    public static function getTypeName($nb = 0) {
        return _n('Pesquisa de satisfação', 'Pesquisas de satisfação', $nb, 'svm');
    }

    public static function getIcon() {
        return 'ti ti-star';
    }

    // ==================================================================
    // Elegibilidade e gatilho
    // ==================================================================

    /**
     * Monta o payload que o front-end usa para decidir se e como exibir
     * a pesquisa para o usuário logado.
     */
    public static function getSurveyData(int $users_id, ?int $entities_id = null): array {
        global $DB;

        $empty = [
            'must_lock'    => false,
            'show_prompt'  => false,
            'enforce_mode' => 'off',
            'tickets'      => [],
            'count'        => 0,
            'config'       => null,
            'questions'    => [],
            'nps'          => null,
        ];

        if ($users_id <= 0) {
            return $empty;
        }

        $config = PluginSvmConfig::getForEntity($entities_id);
        if ($config === null || (int)$config['is_active'] !== 1) {
            return $empty;
        }

        $enforce = (string)$config['enforce_mode'];

        // Perfis com o direito de isenção nunca são bloqueados.
        if (PluginSvmProfile::canBypassEnforcement()) {
            $enforce = 'off';
        }

        $configs_id   = (int)$config['id'];
        $statuses     = $config['target_statuses_array'];
        $entity_scope = self::getEntityScope($config);

        // ---- Total histórico de chamados encerrados do usuário no escopo ----
        $total_ever = self::countClosedTickets($users_id, $entity_scope, $statuses);

        // ---- Último marco (milestone) ----
        // Só respostas efetivas contam, e o marco é por configuração: um
        // usuário coberto por duas configs tem um contador em cada uma.
        $last = $DB->request([
            'SELECT' => ['total_at_last_survey'],
            'FROM'   => self::getTable(),
            'WHERE'  => [
                'users_id'              => $users_id,
                'plugin_svm_configs_id' => $configs_id,
                'answer_status'         => self::STATUS_ANSWERED,
            ],
            'ORDER'  => 'id DESC',
            'LIMIT'  => 1,
        ])->current();
        $last_milestone = (int)($last['total_at_last_survey'] ?? 0);

        // ---- Chamados pendentes de avaliação ----
        $tickets = self::getPendingTickets($users_id, $config, $entity_scope, $statuses);

        // ---- Decide o gatilho ----
        $triggered = false;
        switch ((string)$config['trigger_type']) {
            case 'immediate':
                $triggered = !empty($tickets);
                break;

            case 'closed_count':
                $step = max(1, (int)$config['trigger_closed_count']);
                if ($last_milestone === 0) {
                    $triggered = $total_ever >= $step;
                } else {
                    $triggered = $total_ever >= ($last_milestone + $step);
                }
                break;

            case 'manual':
            default:
                $triggered = false;
                break;
        }

        if (empty($tickets)) {
            $triggered = false;
        }

        // Sem perguntas ativas não há o que responder — e portanto não se
        // pode bloquear ninguém, sob risco de trancar a interface sem saída.
        $questions = self::exportQuestionsForFront($configs_id);
        if (empty($questions)) {
            $triggered = false;
        }

        // Só bloqueia de fato no modo que bloqueia toda a interface.
        // block_new_ticket é aplicado no servidor, ao criar o chamado.
        $must_lock = $triggered && $enforce === 'block_all';

        return [
            'must_lock'    => $must_lock,
            'show_prompt'  => $triggered,
            'enforce_mode' => $enforce,
            'tickets'      => $tickets,
            'count'        => $total_ever,
            'config'       => self::exportConfigForFront($config),
            'questions'    => $questions,
            'nps'          => self::buildNpsPayload($users_id, $config),
        ];
    }

    /**
     * Há pesquisa pendente que impeça a abertura de novo chamado?
     * Usado pelo hook pre_item_add em Ticket (modo block_new_ticket).
     */
    public static function blocksNewTicket(int $users_id, ?int $entities_id = null): bool {
        $config = PluginSvmConfig::getForEntity($entities_id);

        if ($config === null
            || (int)$config['is_active'] !== 1
            || (string)$config['enforce_mode'] !== 'block_new_ticket'
            || PluginSvmProfile::canBypassEnforcement()
        ) {
            return false;
        }

        $data = self::getSurveyData($users_id, $entities_id);
        return (bool)($data['show_prompt'] ?? false);
    }

    /**
     * Entidades cobertas pela config: a própria e, se recursiva, as filhas.
     */
    private static function getEntityScope(array $config): ?array {
        $entities_id = (int)$config['entities_id'];

        if ((int)$config['is_recursive'] === 1) {
            // Config recursiva na raiz = todas as entidades. Devolver null
            // sinaliza "sem filtro", evitando um IN (...) com milhares de ids
            // numa query que roda em toda página.
            if ($entities_id === 0) {
                return null;
            }
            return array_values(array_map('intval', getSonsOf('glpi_entities', $entities_id)));
        }

        return [$entities_id];
    }

    /**
     * Conta os chamados encerrados em que o usuário é requerente.
     * Usa o query builder do GLPI (valores escapados).
     */
    private static function countClosedTickets(int $users_id, ?array $entities, array $statuses): int {
        global $DB;

        $where = [
            'glpi_tickets_users.users_id' => $users_id,
            'glpi_tickets.status'         => $statuses,
            'glpi_tickets.is_deleted'     => 0,
        ];
        if ($entities !== null) {
            $where['glpi_tickets.entities_id'] = $entities;
        }

        $row = $DB->request([
            'COUNT'      => 'cpt',
            'FROM'       => 'glpi_tickets',
            'INNER JOIN' => [
                'glpi_tickets_users' => [
                    'ON' => [
                        'glpi_tickets_users' => 'tickets_id',
                        'glpi_tickets'       => 'id',
                        ['AND' => ['glpi_tickets_users.type' => CommonITILActor::REQUESTER]],
                    ],
                ],
            ],
            'WHERE' => $where,
        ])->current();

        return (int)($row['cpt'] ?? 0);
    }

    /**
     * Chamados encerrados ainda sem pesquisa respondida, respeitando
     * a carência (grace) e a expiração configuradas.
     */
    private static function getPendingTickets(
        int $users_id,
        array $config,
        ?array $entities,
        array $statuses
    ): array {
        global $DB;

        $limit  = max(1, (int)$config['pending_max_shown']);
        $grace  = (int)$config['pending_grace_hours'];
        $expire = (int)$config['survey_expire_days'];

        // Tickets que este usuário já resolveu de alguma forma: respondeu,
        // adiou ou expirou. Como o conjunto é por usuário, ele é pequeno —
        // evita subquery e mantém compatibilidade entre versões do GLPI.
        $done = [];
        foreach ($DB->request([
            'SELECT' => ['tickets_id'],
            'FROM'   => self::getTable(),
            'WHERE'  => [
                'users_id'      => $users_id,
                'answer_status' => [
                    self::STATUS_ANSWERED,
                    self::STATUS_SKIPPED,
                    self::STATUS_EXPIRED,
                ],
            ],
        ]) as $row) {
            $done[] = (int)$row['tickets_id'];
        }

        $where = [
            'glpi_tickets_users.users_id' => $users_id,
            'glpi_tickets.status'         => $statuses,
            'glpi_tickets.is_deleted'     => 0,
        ];
        if ($entities !== null) {
            $where['glpi_tickets.entities_id'] = $entities;
        }

        if (!empty($done)) {
            $where['NOT'] = ['glpi_tickets.id' => $done];
        }

        // Carência: não pede antes de N horas do encerramento.
        // Datas calculadas em PHP para não depender de QueryExpression,
        // que mudou de namespace entre o GLPI 10.0 e o 10.1.
        if ($grace > 0) {
            $cutoff = date('Y-m-d H:i:s', time() - ($grace * 3600));
            $where[] = [
                'OR' => [
                    ['glpi_tickets.closedate' => null],
                    ['glpi_tickets.closedate' => ['<=', $cutoff]],
                ],
            ];
        }

        // Expiração: chamados muito antigos não são mais pesquisáveis.
        if ($expire > 0) {
            $cutoff = date('Y-m-d H:i:s', time() - ($expire * 86400));
            $where[] = [
                'OR' => [
                    ['glpi_tickets.closedate' => null],
                    ['glpi_tickets.closedate' => ['>=', $cutoff]],
                ],
            ];
        }

        $tickets = [];
        foreach ($DB->request([
            'SELECT' => [
                'glpi_tickets.id',
                'glpi_tickets.name',
                'glpi_tickets.closedate',
                'glpi_tickets.entities_id',
            ],
            'FROM'       => 'glpi_tickets',
            'INNER JOIN' => [
                'glpi_tickets_users' => [
                    'ON' => [
                        'glpi_tickets_users' => 'tickets_id',
                        'glpi_tickets'       => 'id',
                        ['AND' => ['glpi_tickets_users.type' => CommonITILActor::REQUESTER]],
                    ],
                ],
            ],
            'WHERE' => $where,
            'ORDER' => 'glpi_tickets.id DESC',
            'LIMIT' => $limit,
        ]) as $row) {
            $tickets[] = [
                'id'          => (int)$row['id'],
                'name'        => self::plain($row['name']),
                'closedate'   => $row['closedate'],
                'entities_id' => (int)$row['entities_id'],
            ];
        }

        return $tickets;
    }

    /**
     * NPS mede lealdade, não um atendimento isolado. Só pergunta se está
     * habilitado e se passou o intervalo mínimo desde a última resposta.
     */
    private static function buildNpsPayload(int $users_id, array $config): ?array {
        global $DB;

        if ((int)$config['nps_enabled'] !== 1) {
            return null;
        }

        $interval = (int)$config['nps_interval_days'];
        if ($interval > 0) {
            $last = $DB->request([
                'SELECT' => ['date_creation'],
                'FROM'   => self::getTable(),
                'WHERE'  => [
                    'users_id' => $users_id,
                    ['NOT' => ['nps_score' => -1]],
                ],
                'ORDER'  => 'date_creation DESC',
                'LIMIT'  => 1,
            ])->current();

            if ($last && !empty($last['date_creation'])) {
                $elapsed = (time() - strtotime($last['date_creation'])) / 86400;
                if ($elapsed < $interval) {
                    return null;
                }
            }
        }

        return [
            'question' => self::plain($config['nps_question']),
            'min'      => 0,
            'max'      => 10,
        ];
    }

    /**
     * Desfaz a sanitização do GLPI antes de mandar texto para o JSON.
     * O que está no banco já vem com entidades HTML; sem isto, um
     * apóstrofo digitado pelo admin apareceria como "&#39;" no modal,
     * porque o esc() do JavaScript escaparia o "&" novamente.
     */
    private static function plain($value): string {
        $value = (string)$value;

        if (class_exists('Glpi\\Toolbox\\Sanitizer')) {
            return \Glpi\Toolbox\Sanitizer::unsanitize($value);
        }

        return html_entity_decode($value, ENT_QUOTES, 'UTF-8');
    }

    /** Apenas o que o front precisa saber. */
    private static function exportConfigForFront(array $config): array {
        return [
            'id'                   => (int)$config['id'],
            'icon_type'            => (string)$config['icon_type'],
            'icon_char'            => (string)$config['icon_char'],
            'icon_empty_char'      => (string)$config['icon_empty_char'],
            // Imagem enviada por upload tem prioridade sobre a URL externa.
            'icon_image_url'       => PluginSvmConfig::resolveIconUrl($config, false),
            'icon_image_empty_url' => PluginSvmConfig::resolveIconUrl($config, true),
            'icon_render_mode'     => (string)$config['icon_render_mode'],
            // Sequência = um ícone por nota (escala de carinhas). Vazia
            // quando o ícone é único e apenas repetido.
            'icon_sequence'        => array_map(
                [self::class, 'plain'],
                PluginSvmConfig::parseIconSequence((string)$config['icon_char'])
            ),
            'scale_type'           => (string)$config['scale_type'],
            'scale_min'            => (int)$config['scale_min'],
            'scale_max'            => (int)$config['scale_max'],
            'scale_label_min'      => self::plain($config['scale_label_min']),
            'scale_label_max'      => self::plain($config['scale_label_max']),
            'justify_threshold'    => (int)$config['justify_threshold'],
            'justify_min_length'   => (int)$config['justify_min_length'],
            'justify_message'      => self::plain($config['justify_message']),
            'enforce_mode'         => (string)$config['enforce_mode'],
            'allow_skip'           => (int)$config['allow_skip'],
            'show_ticket_preview'  => (int)$config['show_ticket_preview'],
            'header_title'         => self::plain($config['header_title']),
            'header_subtitle'      => self::plain($config['header_subtitle']),
            'thanks_title'         => self::plain($config['thanks_title']),
            'thanks_message'       => self::plain($config['thanks_message']),
            'footer_note'          => self::plain($config['footer_note']),
        ];
    }

    private static function exportQuestionsForFront(int $configs_id): array {
        $out = [];
        foreach (PluginSvmQuestion::getActiveForConfig($configs_id) as $q) {
            $out[] = [
                'id'                     => (int)$q['id'],
                'name'                   => self::plain($q['name']),
                'question_type'          => (string)$q['question_type'],
                'helper_text'            => self::plain($q['helper_text']),
                'is_mandatory'           => (int)$q['is_mandatory'],
                'require_comment_on_low' => (int)$q['require_comment_on_low'],
            ];
        }
        return $out;
    }

    // ==================================================================
    // Gravação
    // ==================================================================

    /**
     * Grava uma pesquisa respondida com suas respostas dinâmicas.
     *
     * @param array $input tickets_id, users_id, answers[], comment, nps_score
     */
    public static function saveResponse(array $input): array {
        global $DB;

        $tickets_id = (int)($input['tickets_id'] ?? 0);
        $users_id   = (int)($input['users_id'] ?? 0);

        if ($tickets_id <= 0 || $users_id <= 0) {
            return ['success' => false, 'message' => __('Dados inválidos.', 'svm'), 'id' => 0];
        }

        // ---- Autorização: o usuário tem de ser requerente do chamado ----
        $ticket = new Ticket();
        if (!$ticket->getFromDB($tickets_id)) {
            return ['success' => false, 'message' => __('Chamado não encontrado.', 'svm'), 'id' => 0];
        }

        if (!self::isRequester($tickets_id, $users_id)) {
            return [
                'success' => false,
                'message' => __('Você não é requerente deste chamado.', 'svm'),
                'id'      => 0,
            ];
        }

        $entities_id = (int)$ticket->fields['entities_id'];
        $config      = PluginSvmConfig::getForEntity($entities_id);

        if ($config === null) {
            return [
                'success' => false,
                'message' => __('Não há configuração de pesquisa para esta entidade.', 'svm'),
                'id'      => 0,
            ];
        }

        // ---- O chamado está num status pesquisável? ----
        if (!in_array((int)$ticket->fields['status'], $config['target_statuses_array'], true)) {
            return [
                'success' => false,
                'message' => __('Este chamado não está em um status pesquisável.', 'svm'),
                'id'      => 0,
            ];
        }

        // ---- Já respondida por ESTE usuário? ----
        // Filtra por users_id: um chamado pode ter vários requerentes e cada
        // um tem a sua própria pesquisa.
        $exists = $DB->request([
            'SELECT' => ['id', 'answer_status'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['tickets_id' => $tickets_id, 'users_id' => $users_id],
            'LIMIT'  => 1,
        ])->current();

        if ($exists && $exists['answer_status'] === self::STATUS_ANSWERED) {
            return [
                'success' => false,
                'message' => __('Este chamado já foi avaliado.', 'svm'),
                'id'      => (int)$exists['id'],
            ];
        }

        $configs_id = (int)$config['id'];
        $questions  = PluginSvmQuestion::getActiveForConfig($configs_id);
        $raw        = is_array($input['answers'] ?? null) ? $input['answers'] : [];
        $comment    = trim((string)($input['comment'] ?? ''));

        // ---- Valida e normaliza as respostas ----
        $validated  = [];
        $csat_pairs = [];   // [nota, peso] apenas das perguntas de escala CSAT
        $csat_only  = [];   // notas CSAT, para o cálculo de satisfeitas
        $nps_inline = [];   // notas de perguntas do tipo NPS
        $has_low    = false;
        $needs_just = false;

        foreach ($questions as $q) {
            $qid  = (int)$q['id'];
            $type = (string)$q['question_type'];
            $val  = $raw[$qid] ?? null;

            if ($type === 'text') {
                $text = trim((string)($val ?? ''));
                if ((int)$q['is_mandatory'] === 1 && $text === '') {
                    return [
                        'success' => false,
                        'message' => sprintf(__('Responda: %s', 'svm'), $q['name']),
                        'id'      => 0,
                    ];
                }
                $validated[$qid] = ['int' => null, 'text' => Toolbox::stripTags($text)];
                continue;
            }

            if ($val === null || $val === '') {
                if ((int)$q['is_mandatory'] === 1) {
                    return [
                        'success' => false,
                        'message' => sprintf(__('Responda: %s', 'svm'), $q['name']),
                        'id'      => 0,
                    ];
                }
                $validated[$qid] = ['int' => null, 'text' => null];
                continue;
            }

            $num = (int)$val;

            // Faixa válida conforme o tipo da pergunta
            if ($type === 'nps') {
                $min = 0;
                $max = 10;
            } elseif ($type === 'bool') {
                $min = 0;
                $max = 1;
            } else {
                $min = (int)$config['scale_min'];
                $max = (int)$config['scale_max'];
            }

            if ($num < $min || $num > $max) {
                return [
                    'success' => false,
                    'message' => sprintf(__('Nota fora da escala em: %s', 'svm'), $q['name']),
                    'id'      => 0,
                ];
            }

            $validated[$qid] = ['int' => $num, 'text' => null];

            // CSAT e NPS medem coisas diferentes e têm escalas diferentes:
            // só as perguntas de escala entram na média/percentual de CSAT.
            if ($type === 'scale') {
                $csat_pairs[] = [$num, (float)$q['weight']];
                $csat_only[]  = $num;

                if ($num <= (int)$config['justify_threshold']) {
                    $has_low = true;
                    if ((int)$q['require_comment_on_low'] === 1) {
                        $needs_just = true;
                    }
                }
            } elseif ($type === 'nps') {
                $nps_inline[] = $num;

                // Em NPS, quem exige ação é o detrator (0 a 6), não o
                // limiar da escala CSAT.
                if (PluginSvmConfig::classifyNps($num) === 'detractor') {
                    $has_low = true;
                    if ((int)$q['require_comment_on_low'] === 1) {
                        $needs_just = true;
                    }
                }
            }
        }

        // ---- Justificativa obrigatória em nota baixa ----
        if ($needs_just) {
            $min_len = (int)$config['justify_min_length'];
            if (mb_strlen($comment) < $min_len) {
                return [
                    'success'      => false,
                    'needs_reason' => true,
                    'message'      => sprintf(
                        __('Notas baixas exigem uma justificativa de pelo menos %d caracteres.', 'svm'),
                        $min_len
                    ),
                    'id'           => 0,
                ];
            }
        }

        // ---- NPS ----
        $nps_score = -1;
        if ((int)$config['nps_enabled'] === 1 && isset($input['nps_score']) && $input['nps_score'] !== '') {
            $n = (int)$input['nps_score'];
            if ($n >= 0 && $n <= 10) {
                $nps_score = $n;
            }
        }
        // Se não houve a pergunta global de NPS mas existe pergunta do tipo
        // NPS no formulário, ela alimenta o indicador.
        if ($nps_score === -1 && !empty($nps_inline)) {
            $nps_score = (int)round(array_sum($nps_inline) / count($nps_inline));
        }

        // ---- Indicadores ----
        $csat_avg = PluginSvmConfig::computeWeightedAvg($csat_pairs);
        $counts   = PluginSvmConfig::countSatisfied($csat_only, $config);

        // ---- Novo marco ----
        $entity_scope  = self::getEntityScope($config);
        $current_total = self::countClosedTickets(
            $users_id, $entity_scope, $config['target_statuses_array']
        );

        // ---- Compatibilidade com as colunas legadas ----
        $legacy = ['score_value' => 0, 'score_tech' => 0, 'score_speed' => 0];
        foreach ($questions as $q) {
            $field = (string)$q['legacy_field'];
            if ($field !== '' && array_key_exists($field, $legacy)) {
                $legacy[$field] = (int)($validated[(int)$q['id']]['int'] ?? 0);
            }
        }

        $row = [
            'tickets_id'            => $tickets_id,
            'users_id'              => $users_id,
            'entities_id'           => $entities_id,
            'plugin_svm_configs_id' => $configs_id,
            'csat_avg'              => $csat_avg,
            'csat_percent'          => $counts['percent'],
            // Contagens cruas: permitem agregar SUM(satisfeitas)/SUM(total)
            // em vez de fazer média de percentuais.
            'answers_count'         => $counts['total'],
            'satisfied_count'       => $counts['satisfied'],
            'nps_score'             => $nps_score,
            'comment'               => $comment,
            'answer_status'         => self::STATUS_ANSWERED,
            'total_at_last_survey'  => $current_total,
        ] + $legacy;

        $survey = new self();

        if ($exists) {
            // Havia um registro de "adiado" — atualiza em vez de inserir.
            $row['id']  = (int)$exists['id'];
            $ok         = $survey->update($row);
            $surveys_id = (int)$exists['id'];
        } else {
            $surveys_id = (int)$survey->add($row);
            $ok         = $surveys_id > 0;
        }

        if (!$ok) {
            return ['success' => false, 'message' => __('Erro ao gravar a avaliação.', 'svm'), 'id' => 0];
        }

        if (!PluginSvmAnswer::saveBatch($surveys_id, $validated)) {
            Toolbox::logInFile(
                'svm',
                "Falha ao gravar respostas detalhadas da pesquisa #$surveys_id\n"
            );
        }

        return [
            'success'      => true,
            'id'           => $surveys_id,
            'csat_avg'     => $csat_avg,
            'csat_percent' => $counts['percent'],
            'is_detractor' => $has_low,
            'message'      => (string)$config['thanks_title'],
        ];
    }

    /**
     * Registra um "adiar" respeitando o limite configurado.
     */
    public static function skipSurvey(int $tickets_id, int $users_id): array {
        global $DB;

        if (!self::isRequester($tickets_id, $users_id)) {
            return ['success' => false, 'message' => __('Operação não permitida.', 'svm')];
        }

        $ticket = new Ticket();
        if (!$ticket->getFromDB($tickets_id)) {
            return ['success' => false, 'message' => __('Chamado não encontrado.', 'svm')];
        }

        $config = PluginSvmConfig::getForEntity((int)$ticket->fields['entities_id']);
        if ($config === null || (int)$config['allow_skip'] !== 1) {
            return ['success' => false, 'message' => __('Adiar não está permitido.', 'svm')];
        }

        $skipped = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => self::getTable(),
            'WHERE' => ['users_id' => $users_id, 'answer_status' => self::STATUS_SKIPPED],
        ])->current();

        if ((int)($skipped['cpt'] ?? 0) >= (int)$config['skip_max_count']) {
            return [
                'success' => false,
                'message' => __('Você já atingiu o limite de pesquisas adiadas.', 'svm'),
            ];
        }

        // Já existe uma linha para este par chamado/usuário?
        $exists = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['tickets_id' => $tickets_id, 'users_id' => $users_id],
            'LIMIT'  => 1,
        ])->current();

        if ($exists) {
            return [
                'success' => false,
                'message' => __('Esta pesquisa já foi tratada.', 'svm'),
            ];
        }

        // Grava o marco também no adiamento, senão o gatilho por contagem
        // volta a disparar na próxima página.
        $current_total = self::countClosedTickets(
            $users_id,
            self::getEntityScope($config),
            $config['target_statuses_array']
        );

        $survey = new self();
        $ok = $survey->add([
            'tickets_id'            => $tickets_id,
            'users_id'              => $users_id,
            'entities_id'           => (int)$ticket->fields['entities_id'],
            'plugin_svm_configs_id' => (int)$config['id'],
            'answer_status'         => self::STATUS_SKIPPED,
            'total_at_last_survey'  => $current_total,
        ]);

        return ['success' => $ok !== false];
    }

    /** O usuário é requerente do chamado? */
    public static function isRequester(int $tickets_id, int $users_id): bool {
        global $DB;

        $row = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => 'glpi_tickets_users',
            'WHERE' => [
                'tickets_id' => $tickets_id,
                'users_id'   => $users_id,
                'type'       => CommonITILActor::REQUESTER,
            ],
        ])->current();

        return (int)($row['cpt'] ?? 0) > 0;
    }

    // ==================================================================
    // Indicadores agregados
    // ==================================================================

    /**
     * CSAT% e NPS de um período/entidade.
     * Referências de mercado: CSAT 70-85% é bom, acima de 85% é
     * excepcional; NPS acima de 50 é excelente, 70+ é classe mundial.
     */
    public static function getMetrics(array $criteria = []): array {
        global $DB;

        $where = ['answer_status' => self::STATUS_ANSWERED];

        // isset, não empty: a entidade raiz tem id 0.
        if (isset($criteria['entities_id'])) {
            $where['entities_id'] = $criteria['entities_id'];
        }
        if (!empty($criteria['date_start'])) {
            $where[] = ['date_creation' => ['>=', $criteria['date_start']]];
        }
        if (!empty($criteria['date_end'])) {
            $where[] = ['date_creation' => ['<=', $criteria['date_end']]];
        }

        // Restrição de entidade da sessão: ninguém vê indicadores de
        // entidades fora do seu escopo, mesmo com direito de ler tudo.
        // O 4º parâmetro fica FALSE: com true o GLPI referencia uma coluna
        // is_recursive, que esta tabela não possui. E o retorno pode vir
        // vazio (perfil vendo todas as entidades), o que geraria "AND ()".
        $entity_crit = getEntitiesRestrictCriteria(self::getTable(), 'entities_id', '', false);
        if (!empty($entity_crit)) {
            $where[] = $entity_crit;
        }

        // Só quem tem o direito de ler tudo vê os dados de outros usuários.
        if (!PluginSvmProfile::canReadAllSurveys()) {
            $where['users_id'] = Session::getLoginUserID();
        }

        $rows = [];
        foreach ($DB->request([
            'SELECT' => ['csat_avg', 'nps_score', 'answers_count', 'satisfied_count'],
            'FROM'   => self::getTable(),
            'WHERE'  => $where,
        ]) as $row) {
            $rows[] = $row;
        }

        $total = count($rows);
        if ($total === 0) {
            return [
                'total' => 0, 'csat_percent' => null, 'csat_avg' => null, 'nps' => null,
                'nps_answers' => 0, 'promoters' => 0, 'passives' => 0, 'detractors' => 0,
            ];
        }

        // CSAT% agregado = total de respostas satisfeitas / total de
        // respostas. Média de percentuais daria peso igual a pesquisas com
        // números diferentes de perguntas.
        $answers   = 0;
        $satisfied = 0;
        $avg_sum   = 0.0;
        $avg_count = 0;
        $nps       = ['promoter' => 0, 'passive' => 0, 'detractor' => 0, 'none' => 0];
        $nps_resp  = 0;

        foreach ($rows as $row) {
            $answers   += (int)$row['answers_count'];
            $satisfied += (int)$row['satisfied_count'];

            if ((float)$row['csat_avg'] > 0) {
                $avg_sum += (float)$row['csat_avg'];
                $avg_count++;
            }

            $score = (int)$row['nps_score'];
            if ($score >= 0) {
                $nps[PluginSvmConfig::classifyNps($score)]++;
                $nps_resp++;
            }
        }

        $nps_value = null;
        if ($nps_resp > 0) {
            $nps_value = round((($nps['promoter'] - $nps['detractor']) / $nps_resp) * 100, 1);
        }

        return [
            'total'        => $total,
            'answers'      => $answers,
            'csat_percent' => $answers > 0 ? round(($satisfied / $answers) * 100, 2) : null,
            'csat_avg'     => $avg_count > 0 ? round($avg_sum / $avg_count, 2) : null,
            'nps'          => $nps_value,
            'nps_answers'  => $nps_resp,
            'promoters'    => $nps['promoter'],
            'passives'     => $nps['passive'],
            'detractors'   => $nps['detractor'],
        ];
    }

    // ==================================================================
    // Busca
    // ==================================================================

    public function rawSearchOptions() {
        $opts = [];

        $opts[] = ['id' => 'common', 'name' => self::getTypeName(2)];

        // A opção 1 é a coluna/ordenação padrão do Search do GLPI — precisa existir.
        $opts[] = ['id' => 1, 'table' => self::getTable(), 'field' => 'tickets_id',
                   'name' => __('Chamado'), 'datatype' => 'number',
                   'massiveaction' => false];
        $opts[] = ['id' => 2, 'table' => self::getTable(), 'field' => 'id',
                   'name' => __('ID'), 'datatype' => 'number', 'massiveaction' => false];
        $opts[] = ['id' => 3, 'table' => 'glpi_users', 'field' => 'name',
                   'linkfield' => 'users_id',
                   'name' => __('Requerente'), 'datatype' => 'dropdown'];
        $opts[] = ['id' => 4, 'table' => 'glpi_entities', 'field' => 'completename',
                   'linkfield' => 'entities_id',
                   'name' => __('Entidade'), 'datatype' => 'dropdown'];
        $opts[] = ['id' => 5, 'table' => self::getTable(), 'field' => 'csat_avg',
                   'name' => __('Nota média (CSAT)', 'svm'), 'datatype' => 'decimal'];
        $opts[] = ['id' => 6, 'table' => self::getTable(), 'field' => 'csat_percent',
                   'name' => __('CSAT %', 'svm'), 'datatype' => 'decimal'];
        $opts[] = ['id' => 7, 'table' => self::getTable(), 'field' => 'nps_score',
                   'name' => __('Nota NPS', 'svm'), 'datatype' => 'number'];
        $opts[] = ['id' => 8, 'table' => self::getTable(), 'field' => 'comment',
                   'name' => __('Comentário'), 'datatype' => 'text'];
        $opts[] = ['id' => 9, 'table' => self::getTable(), 'field' => 'date_creation',
                   'name' => __('Data da resposta', 'svm'), 'datatype' => 'datetime'];
        $opts[] = ['id' => 10, 'table' => self::getTable(), 'field' => 'answer_status',
                   'name' => __('Situação'), 'datatype' => 'string'];

        return $opts;
    }

    public function defineTabs($options = []) {
        $tabs = [];
        $this->addDefaultFormTab($tabs);
        $this->addStandardTab('PluginSvmAnswer', $tabs, $options);
        return $tabs;
    }
}
