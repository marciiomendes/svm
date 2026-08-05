<?php
/**
 * Plugin SVM - Gestão de Valor de Serviços
 * Instalação / desinstalação e migração de schema.
 */

/**
 * queryOrDie() está deprecado desde o GLPI 10.0.11, substituído por
 * doQueryOrDie(). Usa o novo quando disponível para não gerar avisos.
 */
function plugin_svm_query(string $sql, string $message) {
    global $DB;
    $method = method_exists($DB, 'doQueryOrDie') ? 'doQueryOrDie' : 'queryOrDie';
    return $DB->$method($sql, $message);
}

function plugin_svm_install() {
    global $DB;

    $migration = new Migration(PLUGIN_SVM_VERSION);
    $charset   = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC";

    // ------------------------------------------------------------------
    // 1. Configurações (por entidade, com herança)
    // ------------------------------------------------------------------
    $t_config = 'glpi_plugin_svm_configs';
    if (!$DB->tableExists($t_config)) {
        plugin_svm_query("CREATE TABLE `$t_config` (
            `id`                        INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name`                      VARCHAR(255) NOT NULL DEFAULT '',
            `entities_id`               INT UNSIGNED NOT NULL DEFAULT 0,
            `is_recursive`              TINYINT NOT NULL DEFAULT 1,
            `is_active`                 TINYINT NOT NULL DEFAULT 1,

            `icon_type`                 VARCHAR(20)  NOT NULL DEFAULT 'emoji',
            -- Um ícone (repetido) ou vários separados por espaço (um por nota).
            `icon_char`                 VARCHAR(255) NOT NULL DEFAULT '🍊',
            `icon_empty_char`           VARCHAR(255) NOT NULL DEFAULT '',
            `icon_image_url`            VARCHAR(255) NOT NULL DEFAULT '',
            `icon_image_empty_url`      VARCHAR(255) NOT NULL DEFAULT '',
            -- Imagens enviadas por upload (têm prioridade sobre a URL).
            `icon_image_file`           VARCHAR(255) NOT NULL DEFAULT '',
            `icon_image_empty_file`     VARCHAR(255) NOT NULL DEFAULT '',
            `icon_render_mode`          VARCHAR(20)  NOT NULL DEFAULT 'cumulative',

            `scale_type`                VARCHAR(20)  NOT NULL DEFAULT 'csat5',
            `scale_min`                 TINYINT NOT NULL DEFAULT 1,
            `scale_max`                 TINYINT NOT NULL DEFAULT 5,
            `scale_label_min`           VARCHAR(100) NOT NULL DEFAULT 'Muito insatisfeito',
            `scale_label_max`           VARCHAR(100) NOT NULL DEFAULT 'Muito satisfeito',
            `csat_satisfied_threshold`  TINYINT NOT NULL DEFAULT 4,

            `nps_enabled`               TINYINT NOT NULL DEFAULT 1,
            `nps_question`              VARCHAR(255) NOT NULL DEFAULT '',
            `nps_interval_days`         SMALLINT UNSIGNED NOT NULL DEFAULT 90,

            `justify_threshold`         TINYINT NOT NULL DEFAULT 3,
            `justify_min_length`        SMALLINT UNSIGNED NOT NULL DEFAULT 15,
            `justify_message`           VARCHAR(255) NOT NULL DEFAULT '',

            -- Default deliberadamente NÃO bloqueante: um plugin recém-instalado
            -- não pode trancar a interface de ninguém. O bloqueio é opt-in.
            `enforce_mode`              VARCHAR(20)  NOT NULL DEFAULT 'reminder',
            `trigger_type`              VARCHAR(20)  NOT NULL DEFAULT 'closed_count',
            `trigger_closed_count`      SMALLINT UNSIGNED NOT NULL DEFAULT 5,
            `pending_max_shown`         TINYINT UNSIGNED NOT NULL DEFAULT 5,
            `pending_grace_hours`       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `survey_expire_days`        SMALLINT UNSIGNED NOT NULL DEFAULT 30,
            `target_statuses`           VARCHAR(50)  NOT NULL DEFAULT '5,6',
            `allow_skip`                TINYINT NOT NULL DEFAULT 0,
            `skip_max_count`            TINYINT UNSIGNED NOT NULL DEFAULT 1,
            `is_anonymous`              TINYINT NOT NULL DEFAULT 0,

            `header_title`              VARCHAR(255) NOT NULL DEFAULT '',
            `header_subtitle`           VARCHAR(255) NOT NULL DEFAULT '',
            `thanks_title`              VARCHAR(255) NOT NULL DEFAULT '',
            `thanks_message`            TEXT COLLATE utf8mb4_unicode_ci,
            `footer_note`               VARCHAR(255) NOT NULL DEFAULT '',
            `show_ticket_preview`       TINYINT NOT NULL DEFAULT 1,

            `comment`                   TEXT COLLATE utf8mb4_unicode_ci,
            `date_creation`             TIMESTAMP NULL DEFAULT NULL,
            `date_mod`                  TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `entities_id` (`entities_id`),
            KEY `is_active` (`is_active`)
        ) $charset;", "SVM: erro ao criar $t_config");
    } else {
        // Colunas acrescentadas depois da 3.0.0
        foreach ([
            'icon_image_file'       => "VARCHAR(255) NOT NULL DEFAULT ''",
            'icon_image_empty_file' => "VARCHAR(255) NOT NULL DEFAULT ''",
        ] as $col => $def) {
            if (!$DB->fieldExists($t_config, $col)) {
                $migration->addField($t_config, $col, $def);
            }
        }

        // icon_char passou a aceitar uma sequência (um ícone por nota).
        // Cinco emojis com seletor de variação não cabem em 50 bytes.
        $migration->changeField($t_config, 'icon_char', 'icon_char',
            "VARCHAR(255) NOT NULL DEFAULT '🍊'");
        $migration->changeField($t_config, 'icon_empty_char', 'icon_empty_char',
            "VARCHAR(255) NOT NULL DEFAULT ''");

        $migration->migrationOneTable($t_config);
    }

    // ------------------------------------------------------------------
    // 2. Perguntas configuráveis
    // ------------------------------------------------------------------
    $t_quest = 'glpi_plugin_svm_questions';
    if (!$DB->tableExists($t_quest)) {
        plugin_svm_query("CREATE TABLE `$t_quest` (
            `id`                        INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `plugin_svm_configs_id`     INT UNSIGNED NOT NULL DEFAULT 0,
            `name`                      VARCHAR(255) NOT NULL DEFAULT '',
            `question_type`             VARCHAR(20)  NOT NULL DEFAULT 'scale',
            `legacy_field`              VARCHAR(50)  NOT NULL DEFAULT '',
            `rank`                      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `is_mandatory`              TINYINT NOT NULL DEFAULT 1,
            `is_active`                 TINYINT NOT NULL DEFAULT 1,
            `require_comment_on_low`    TINYINT NOT NULL DEFAULT 1,
            `weight`                    DECIMAL(5,2) NOT NULL DEFAULT 1.00,
            `helper_text`               VARCHAR(255) NOT NULL DEFAULT '',
            `date_creation`             TIMESTAMP NULL DEFAULT NULL,
            `date_mod`                  TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `plugin_svm_configs_id` (`plugin_svm_configs_id`),
            KEY `rank` (`rank`)
        ) $charset;", "SVM: erro ao criar $t_quest");
    }

    // ------------------------------------------------------------------
    // 3. Pesquisas (tabela legada, migrada preservando dados)
    // ------------------------------------------------------------------
    $t_survey = 'glpi_plugin_svm_surveys';
    if (!$DB->tableExists($t_survey)) {
        plugin_svm_query("CREATE TABLE `$t_survey` (
            `id`                        INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `tickets_id`                INT UNSIGNED NOT NULL,
            `users_id`                  INT UNSIGNED NOT NULL,
            `entities_id`               INT UNSIGNED NOT NULL DEFAULT 0,
            `plugin_svm_configs_id`     INT UNSIGNED NOT NULL DEFAULT 0,
            `score_value`               TINYINT NOT NULL DEFAULT 0,
            `score_tech`                TINYINT NOT NULL DEFAULT 0,
            `score_speed`               TINYINT NOT NULL DEFAULT 0,
            `csat_avg`                  DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            `csat_percent`              DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            `nps_score`                 TINYINT NOT NULL DEFAULT -1,
            `answers_count`             SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `satisfied_count`           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `comment`                   TEXT COLLATE utf8mb4_unicode_ci,
            `answer_status`             VARCHAR(20) NOT NULL DEFAULT 'answered',
            `total_at_last_survey`      INT UNSIGNED NOT NULL DEFAULT 0,
            `date_creation`             TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            -- Um chamado pode ter mais de um requerente; cada um avalia o seu.
            -- Este índice já serve de prefixo para buscas por tickets_id.
            UNIQUE KEY `ticket_user` (`tickets_id`, `users_id`),
            KEY `users_id` (`users_id`),
            KEY `entities_id` (`entities_id`),
            KEY `answer_status` (`answer_status`)
        ) $charset;", "SVM: erro ao criar $t_survey");
    } else {
        $new_cols = [
            'entities_id'           => "INT UNSIGNED NOT NULL DEFAULT 0",
            'plugin_svm_configs_id' => "INT UNSIGNED NOT NULL DEFAULT 0",
            'csat_avg'              => "DECIMAL(5,2) NOT NULL DEFAULT 0.00",
            'csat_percent'          => "DECIMAL(5,2) NOT NULL DEFAULT 0.00",
            'nps_score'             => "TINYINT NOT NULL DEFAULT -1",
            'answer_status'         => "VARCHAR(20) NOT NULL DEFAULT 'answered'",
            'total_at_last_survey'  => "INT UNSIGNED NOT NULL DEFAULT 0",
            'answers_count'         => "SMALLINT UNSIGNED NOT NULL DEFAULT 0",
            'satisfied_count'       => "SMALLINT UNSIGNED NOT NULL DEFAULT 0",
        ];
        foreach ($new_cols as $col => $def) {
            if (!$DB->fieldExists($t_survey, $col)) {
                $migration->addField($t_survey, $col, $def);
            }
        }
        $migration->addKey($t_survey, 'entities_id');
        $migration->addKey($t_survey, 'answer_status');
        $migration->migrationOneTable($t_survey);

        // A chave única antiga era só por tickets_id, o que impedia dois
        // requerentes do mesmo chamado de avaliarem separadamente.
        // isIndex() é o helper do GLPI 10 (DBmysql não tem indexExists).
        if (isIndex($t_survey, 'tickets_id') && !isIndex($t_survey, 'ticket_user')) {
            $run = method_exists($DB, 'doQuery') ? 'doQuery' : 'query';

            // Remove eventuais duplicatas antes de criar a chave única,
            // senão o ALTER falharia e abortaria a atualização.
            $DB->$run(
                "DELETE s1 FROM `$t_survey` s1
                   JOIN `$t_survey` s2
                     ON s1.tickets_id = s2.tickets_id
                    AND s1.users_id  = s2.users_id
                    AND s1.id > s2.id"
            );

            // Sem OrDie: se falhar, o plugin segue funcionando com a chave
            // antiga em vez de deixar a instalação pela metade.
            if (!$DB->$run("ALTER TABLE `$t_survey`
                    DROP INDEX `tickets_id`,
                    ADD UNIQUE KEY `ticket_user` (`tickets_id`, `users_id`)")) {
                $migration->displayWarning(
                    "SVM: não foi possível migrar a chave única de $t_survey. "
                    . "Múltiplos requerentes por chamado continuarão indisponíveis.",
                    true
                );
            }
        }
    }

    // ------------------------------------------------------------------
    // 4. Respostas por pergunta (modelo dinâmico)
    // ------------------------------------------------------------------
    $t_answer = 'glpi_plugin_svm_answers';
    if (!$DB->tableExists($t_answer)) {
        plugin_svm_query("CREATE TABLE `$t_answer` (
            `id`                        INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `plugin_svm_surveys_id`     INT UNSIGNED NOT NULL,
            `plugin_svm_questions_id`   INT UNSIGNED NOT NULL,
            `answer_int`                SMALLINT NOT NULL DEFAULT -1,
            `answer_text`               TEXT COLLATE utf8mb4_unicode_ci,
            `date_creation`             TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `survey_question` (`plugin_svm_surveys_id`, `plugin_svm_questions_id`),
            KEY `plugin_svm_questions_id` (`plugin_svm_questions_id`)
        ) $charset;", "SVM: erro ao criar $t_answer");
    }

    $migration->executeMigration();

    // ------------------------------------------------------------------
    // 5. Diretório dos ícones enviados por upload
    // ------------------------------------------------------------------
    // Fica em files/_plugins/svm/icons: fora da raiz web, servido apenas
    // pelo endpoint front/icon.send.php, que exige sessão válida.
    $icon_dir = PluginSvmConfig::getIconDir();
    if (!is_dir($icon_dir) && !@mkdir($icon_dir, 0755, true)) {
        $migration->displayWarning(
            "SVM: não foi possível criar o diretório de ícones ($icon_dir). "
            . "O upload de imagens ficará indisponível até corrigir as permissões.",
            true
        );
    }

    plugin_svm_seedDefaults();
    PluginSvmProfile::installRights();

    return true;
}

/**
 * Cria a configuração padrão da entidade raiz e migra os enunciados
 * das 3 perguntas que estavam hardcoded no enforce.js.
 */
function plugin_svm_seedDefaults() {
    global $DB;

    $row = $DB->request([
        'COUNT' => 'cpt',
        'FROM'  => 'glpi_plugin_svm_configs',
    ])->current();

    if ((int)($row['cpt'] ?? 0) > 0) {
        return;
    }

    $config     = new PluginSvmConfig();
    $configs_id = $config->add([
        'name'           => 'Configuração padrão',
        'entities_id'    => 0,
        'is_recursive'   => 1,
        'is_active'      => 1,
        'nps_question'   => 'De 0 a 10, o quanto você recomendaria o suporte de T.I. a um colega?',
        'justify_message'=> 'Poxa... queremos melhorar! Descreva abaixo o que podemos aprimorar.',
        'header_title'   => '🚀 Gestão de Valor de Serviços',
        'header_subtitle'=> 'Sua opinião move nossa T.I. e melhora seus processos.',
        'thanks_title'   => 'Avaliação colhida com sucesso!',
        'thanks_message' => 'Obrigado por contribuir com a nossa melhoria contínua. Sua voz faz a diferença!',
        'footer_note'    => 'Indicadores de melhoria contínua baseados em valor.',
    ]);

    if (!$configs_id) {
        return;
    }

    $defaults = [
        ['O quanto a solução entregue agregou valor ao seu trabalho?',          'score_value', 10],
        ['Como avalia a cordialidade e o conhecimento técnico do atendente?',   'score_tech',  20],
        ['O tempo de atendimento atendeu sua expectativa?',                     'score_speed', 30],
    ];

    $question = new PluginSvmQuestion();
    foreach ($defaults as $q) {
        $question->add([
            'plugin_svm_configs_id'  => $configs_id,
            'name'                   => $q[0],
            'question_type'          => 'scale',
            'legacy_field'           => $q[1],
            'rank'                   => $q[2],
            'is_mandatory'           => 1,
            'is_active'              => 1,
            'require_comment_on_low' => 1,
            'weight'                 => 1,
        ]);
    }

    $DB->update('glpi_plugin_svm_surveys',
        ['plugin_svm_configs_id' => $configs_id],
        ['plugin_svm_configs_id' => 0]
    );
}

function plugin_svm_uninstall() {
    global $DB;

    PluginSvmProfile::uninstallRights();

    // doQuery() a partir do 10.0.11; query() cobre versões anteriores.
    $run = method_exists($DB, 'doQuery') ? 'doQuery' : 'query';

    // Remove os ícones enviados por upload.
    $icon_dir = PluginSvmConfig::getIconDir();
    if (is_dir($icon_dir)) {
        foreach (glob($icon_dir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($icon_dir);
        @rmdir(dirname($icon_dir));
    }

    // Limpa os resíduos do plugin nas tabelas do core.
    $itemtypes = ['PluginSvmConfig', 'PluginSvmQuestion', 'PluginSvmSurvey', 'PluginSvmAnswer'];
    foreach (['glpi_displaypreferences', 'glpi_logs', 'glpi_savedsearches'] as $core_table) {
        if ($DB->tableExists($core_table)) {
            $DB->delete($core_table, ['itemtype' => $itemtypes]);
        }
    }

    foreach ([
        'glpi_plugin_svm_answers',
        'glpi_plugin_svm_questions',
        'glpi_plugin_svm_surveys',
        'glpi_plugin_svm_configs',
    ] as $table) {
        $DB->$run("DROP TABLE IF EXISTS `$table`");
    }

    return true;
}
