<?php
/**
 * Plugin SVM - Gestão de Valor de Serviços
 * Pesquisa de satisfação (CSAT / NPS) para chamados do GLPI 10.
 */

define('PLUGIN_SVM_VERSION', '3.1.0');
define('PLUGIN_SVM_MIN_GLPI', '10.0.0');
define('PLUGIN_SVM_MAX_GLPI', '10.1.99');

function plugin_init_svm() {
    global $PLUGIN_HOOKS, $DB;

    $PLUGIN_HOOKS['csrf_compliant']['svm'] = true;

    // ------------------------------------------------------------------
    // Registro de classes
    // ------------------------------------------------------------------
    Plugin::registerClass('PluginSvmConfig');
    Plugin::registerClass('PluginSvmQuestion');
    Plugin::registerClass('PluginSvmSurvey');
    Plugin::registerClass('PluginSvmAnswer');
    Plugin::registerClass('PluginSvmProfile', [
        'addtabon' => ['Profile'],
    ]);

    // Mantém os direitos do plugin sincronizados na troca de perfil
    $PLUGIN_HOOKS['change_profile']['svm'] = ['PluginSvmProfile', 'initProfile'];

    if (!Session::getLoginUserID() || Session::isCron()) {
        return;
    }

    // Durante a instalação/atualização as tabelas ainda não existem.
    if (!isset($DB) || !$DB->connected || !$DB->tableExists('glpi_plugin_svm_configs')) {
        return;
    }

    // ------------------------------------------------------------------
    // Menu e página de configuração (visíveis conforme o direito)
    // ------------------------------------------------------------------
    if (Session::haveRight('plugin_svm_config', READ)) {
        $PLUGIN_HOOKS['config_page']['svm'] = 'front/config.php';

        $PLUGIN_HOOKS['menu_toadd']['svm'] = [
            'admin' => 'PluginSvmConfig',
        ];
    }

    if (Session::haveRight('plugin_svm_survey', READ)) {
        if (!isset($PLUGIN_HOOKS['menu_toadd']['svm'])) {
            $PLUGIN_HOOKS['menu_toadd']['svm'] = [];
        }
        $PLUGIN_HOOKS['menu_toadd']['svm']['helpdesk'] = 'PluginSvmSurvey';
    }

    // ------------------------------------------------------------------
    // CSS
    // ------------------------------------------------------------------
    // Sempre que o usuário tem algum acesso ao plugin: o mesmo arquivo
    // estiliza o modal, a tela de configuração e o painel de indicadores.
    // Condicioná-lo ao modo de obrigatoriedade deixaria o painel sem estilo
    // quando a pesquisa estivesse desativada.
    if (Session::haveRight('plugin_svm_survey', READ)
        || Session::haveRight('plugin_svm_config', READ)
        || Session::haveRight('plugin_svm_report', READ)) {
        $PLUGIN_HOOKS['add_css']['svm'][] = 'css/styles.css';
    }

    // ------------------------------------------------------------------
    // Injeção do modal
    // ------------------------------------------------------------------
    // Nunca condicionar a injeção a um parâmetro de URL: qualquer usuário
    // poderia desativar a pesquisa acrescentando-o ao endereço. O próprio
    // enforce.js já se desativa quando roda dentro de um iframe.
    if (PluginSvmProfile::canBypassEnforcement()) {
        return;
    }

    // Registro incondicional: o chamado pode ser criado numa entidade
    // diferente da entidade ativa da sessão (troca no formulário, API,
    // regras). A própria função decide, já sabendo a entidade do chamado,
    // se deve bloquear — registrar condicionalmente abriria uma brecha.
    $PLUGIN_HOOKS['pre_item_add']['svm'] = [
        'Ticket' => 'plugin_svm_pre_ticket_add',
    ];

    $config = PluginSvmConfig::getForEntity();
    if ($config === null || (int)$config['is_active'] !== 1) {
        return;
    }

    if ((string)$config['enforce_mode'] === 'off') {
        return;
    }

    $PLUGIN_HOOKS['add_javascript']['svm'][] = 'js/enforce.js';

    // Quem responde a pesquisa pode não ter nenhum direito do plugin, mas
    // ainda precisa do CSS do modal.
    if (empty($PLUGIN_HOOKS['add_css']['svm'])) {
        $PLUGIN_HOOKS['add_css']['svm'][] = 'css/styles.css';
    }
}

/**
 * Impede a criação de chamado enquanto houver pesquisa pendente
 * (modo block_new_ticket).
 */
function plugin_svm_pre_ticket_add(Ticket $ticket) {
    $users_id = (int)Session::getLoginUserID();
    if ($users_id <= 0) {
        return $ticket;
    }

    $entities_id = isset($ticket->input['entities_id'])
        ? (int)$ticket->input['entities_id']
        : (int)($_SESSION['glpiactive_entity'] ?? 0);

    if (PluginSvmSurvey::blocksNewTicket($users_id, $entities_id)) {
        Session::addMessageAfterRedirect(
            __('Responda as pesquisas de satisfação pendentes antes de abrir um novo chamado.', 'svm'),
            true,
            ERROR
        );
        $ticket->input = false;
    }

    return $ticket;
}

function plugin_version_svm() {
    return [
        'name'           => 'Gestão de Valor de Serviços (SVM)',
        'version'        => PLUGIN_SVM_VERSION,
        'author'         => 'R&M❤️',
        'license'        => 'GPLv2+',
        'homepage'       => '',
        'requirements'   => [
            'glpi' => [
                'min' => PLUGIN_SVM_MIN_GLPI,
                'max' => PLUGIN_SVM_MAX_GLPI,
            ],
        ],
    ];
}

function plugin_svm_check_prerequisites() {
    if (version_compare(GLPI_VERSION, PLUGIN_SVM_MIN_GLPI, 'lt')) {
        echo "Este plugin requer o GLPI " . PLUGIN_SVM_MIN_GLPI . " ou superior.";
        return false;
    }
    return true;
}

function plugin_svm_check_config($verbose = false) {
    return true;
}
