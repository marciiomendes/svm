<?php
/**
 * Plugin SVM - Permissionamento por perfil.
 *
 * Direitos criados:
 *  - plugin_svm_config    : configurar o plugin (escala, ícone, perguntas, gatilhos)
 *  - plugin_svm_question  : gerenciar o banco de perguntas
 *  - plugin_svm_survey    : ver/editar/excluir as pesquisas respondidas por terceiros
 *  - plugin_svm_report    : acessar os indicadores (CSAT / NPS)
 *  - plugin_svm_bypass    : não ser bloqueado pelo modal de pesquisa pendente
 */

if (!defined('GLPI_ROOT')) {
    die("Access denied");
}

class PluginSvmProfile extends Profile
{
    public static $rightname = 'profile';

    /** Direitos do plugin e seus tipos de permissão. */
    public static function getAllRights(): array {
        return [
            [
                'itemtype' => 'PluginSvmConfig',
                'label'    => __('Configuração da pesquisa', 'svm'),
                'field'    => 'plugin_svm_config',
                'rights'   => [
                    READ   => __('Ler'),
                    UPDATE => __('Atualizar'),
                    CREATE => __('Criar'),
                    PURGE  => ['short' => __('Excluir'), 'long' => __('Excluir permanentemente')],
                ],
            ],
            [
                'itemtype' => 'PluginSvmQuestion',
                'label'    => __('Perguntas da pesquisa', 'svm'),
                'field'    => 'plugin_svm_question',
                'rights'   => [
                    READ   => __('Ler'),
                    CREATE => __('Criar'),
                    UPDATE => __('Atualizar'),
                    PURGE  => ['short' => __('Excluir'), 'long' => __('Excluir permanentemente')],
                ],
            ],
            [
                'itemtype' => 'PluginSvmSurvey',
                'label'    => __('Respostas das pesquisas', 'svm'),
                'field'    => 'plugin_svm_survey',
                'rights'   => [
                    READ   => __('Ler as próprias respostas', 'svm'),
                    self::RIGHT_READ_ALL   => __('Ler as respostas de todos', 'svm'),
                    UPDATE => __('Atualizar'),
                    PURGE  => ['short' => __('Excluir'), 'long' => __('Excluir permanentemente')],
                ],
            ],
            [
                'itemtype' => 'PluginSvmSurvey',
                'label'    => __('Indicadores CSAT / NPS', 'svm'),
                'field'    => 'plugin_svm_report',
                'rights'   => [
                    READ                  => __('Ver indicadores', 'svm'),
                    self::RIGHT_EXPORT    => __('Exportar dados', 'svm'),
                ],
            ],
            [
                'itemtype' => 'PluginSvmSurvey',
                'label'    => __('Bloqueio da pesquisa pendente', 'svm'),
                'field'    => 'plugin_svm_bypass',
                'rights'   => [
                    READ => __('Isentar do bloqueio obrigatório', 'svm'),
                ],
            ],
        ];
    }

    /**
     * Bits customizados. 128 e 256 já são usados pelo core (UPDATENOTE e
     * UNLOCK); embora os direitos sejam isolados por rightname, usar bits
     * livres evita confusão em telas genéricas.
     */
    const RIGHT_READ_ALL = 1024;
    const RIGHT_EXPORT   = 2048;

    /** Lista simples dos nomes de direito, usada em install/uninstall. */
    public static function getRightNames(): array {
        $names = [];
        foreach (self::getAllRights() as $right) {
            $names[] = $right['field'];
        }
        return array_values(array_unique($names));
    }

    // ------------------------------------------------------------------
    // Aba na tela de Perfis
    // ------------------------------------------------------------------

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
        if ($item instanceof Profile && $item->getField('id') > 0) {
            return __('Pesquisa de satisfação', 'svm');
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
        if ($item instanceof Profile) {
            $profiles_id = (int)$item->getField('id');
            if ($profiles_id <= 0) {
                return false;
            }
            self::addDefaultProfileInfos($profiles_id, self::getDefaultRightsArray());

            $prof = new self();
            $prof->showFormSvm($profiles_id);
            return true;
        }
        return false;
    }

    /**
     * Formulário de direitos do plugin dentro do perfil.
     */
    public function showFormSvm($profiles_id = 0, $openform = true, $closeform = true) {
        echo "<div class='spaced'>";

        $profile = new Profile();
        $profile->getFromDB($profiles_id);

        if ($openform && Profile::canUpdate()) {
            echo "<form method='post' action='" . $profile->getFormURL() . "'>";
        }

        $rights = self::getAllRights();
        $profile->displayRightsChoiceMatrix($rights, [
            'canedit'       => Profile::canUpdate(),
            'default_class' => 'tab_bg_2',
            'title'         => __('Gestão de Valor de Serviços - Pesquisa de satisfação', 'svm'),
        ]);

        if ($closeform && Profile::canUpdate()) {
            echo "<div class='center'>";
            echo Html::hidden('id', ['value' => $profiles_id]);
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary']);
            echo "</div>";
            Html::closeForm();
        }

        echo "</div>";
    }

    // ------------------------------------------------------------------
    // Hooks de perfil
    // ------------------------------------------------------------------

    /**
     * Chamado pelo hook change_profile: garante que os direitos existam
     * na sessão do perfil ativo.
     */
    public static function initProfile() {
        global $DB;

        $profile = new self();

        // Cria a coluna de direito se algum perfil ainda não a possui
        foreach (self::getRightNames() as $right) {
            $count = $DB->request([
                'COUNT' => 'cpt',
                'FROM'  => 'glpi_profilerights',
                'WHERE' => ['name' => $right],
            ])->current();

            if ((int)($count['cpt'] ?? 0) === 0) {
                ProfileRight::addProfileRights([$right]);
            }
        }

        // Sincroniza a sessão
        if (isset($_SESSION['glpiactiveprofile']['id'])) {
            $profiles_id = (int)$_SESSION['glpiactiveprofile']['id'];
            foreach ($DB->request([
                'SELECT' => ['name', 'rights'],
                'FROM'   => 'glpi_profilerights',
                'WHERE'  => [
                    'profiles_id' => $profiles_id,
                    'name'        => self::getRightNames(),
                ],
            ]) as $row) {
                $_SESSION['glpiactiveprofile'][$row['name']] = (int)$row['rights'];
            }
        }

        unset($profile);
    }

    /**
     * Garante que um perfil tenha as entradas de direito (com valor default).
     */
    public static function addDefaultProfileInfos($profiles_id, array $rights) {
        global $DB;

        $profileRight = new ProfileRight();
        foreach ($rights as $right => $value) {
            $exists = $DB->request([
                'COUNT' => 'cpt',
                'FROM'  => 'glpi_profilerights',
                'WHERE' => ['profiles_id' => $profiles_id, 'name' => $right],
            ])->current();

            if ((int)($exists['cpt'] ?? 0) === 0) {
                $profileRight->add([
                    'profiles_id' => $profiles_id,
                    'name'        => $right,
                    'rights'      => $value,
                ]);
                if ((int)($_SESSION['glpiactiveprofile']['id'] ?? 0) === (int)$profiles_id) {
                    $_SESSION['glpiactiveprofile'][$right] = $value;
                }
            }
        }
    }

    /** Valor default de cada direito para perfis já existentes: nenhum. */
    public static function getDefaultRightsArray(): array {
        $defaults = [];
        foreach (self::getRightNames() as $right) {
            $defaults[$right] = 0;
        }
        return $defaults;
    }

    // ------------------------------------------------------------------
    // Instalação / desinstalação
    // ------------------------------------------------------------------

    public static function installRights() {
        global $DB;

        foreach (self::getRightNames() as $right) {
            $count = $DB->request([
                'COUNT' => 'cpt',
                'FROM'  => 'glpi_profilerights',
                'WHERE' => ['name' => $right],
            ])->current();

            if ((int)($count['cpt'] ?? 0) === 0) {
                ProfileRight::addProfileRights([$right]);
            }
        }

        // Concede ao perfil do usuário que instalou (super-admin típico)
        // apenas os bits que cada direito realmente expõe na matriz. Ligar
        // CREATE em plugin_svm_survey, por exemplo, faria o GLPI oferecer um
        // botão "+" no menu apontando para um formulário que não existe:
        // pesquisa é criada pelo usuário respondendo, não pelo gestor.
        $profiles_id = (int)($_SESSION['glpiactiveprofile']['id'] ?? 0);
        if ($profiles_id > 0) {
            foreach (self::getAllRights() as $definition) {
                $bits = 0;
                foreach (array_keys($definition['rights']) as $bit) {
                    $bits |= (int)$bit;
                }

                $DB->update('glpi_profilerights',
                    ['rights' => $bits],
                    ['profiles_id' => $profiles_id, 'name' => $definition['field']]
                );
                $_SESSION['glpiactiveprofile'][$definition['field']] = $bits;
            }
        }

        // Perfis de autoatendimento: apenas leitura das próprias respostas.
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_profiles',
            'WHERE'  => ['interface' => 'helpdesk'],
        ]) as $row) {
            if ((int)$row['id'] === $profiles_id) {
                continue;
            }
            $DB->update('glpi_profilerights',
                ['rights' => READ],
                ['profiles_id' => (int)$row['id'], 'name' => 'plugin_svm_survey']
            );
        }

        // Rede de segurança: os perfis de super-admin ficam isentos do
        // bloqueio, para que uma configuração equivocada nunca possa trancar
        // quem precisa entrar no sistema para corrigi-la.
        foreach ($DB->request([
            'SELECT' => ['profiles_id'],
            'FROM'   => 'glpi_profilerights',
            'WHERE'  => ['name' => 'config', ['NOT' => ['rights' => 0]]],
        ]) as $row) {
            $DB->update('glpi_profilerights',
                ['rights' => READ],
                ['profiles_id' => (int)$row['profiles_id'], 'name' => 'plugin_svm_bypass']
            );
        }

        return true;
    }

    public static function uninstallRights() {
        ProfileRight::deleteProfileRights(self::getRightNames());
        foreach (self::getRightNames() as $right) {
            unset($_SESSION['glpiactiveprofile'][$right]);
        }
        return true;
    }

    // ------------------------------------------------------------------
    // Helpers de checagem usados pelo resto do plugin
    // ------------------------------------------------------------------

    /** O usuário atual pode ver as respostas de todos os usuários? */
    public static function canReadAllSurveys(): bool {
        return (bool)Session::haveRight('plugin_svm_survey', self::RIGHT_READ_ALL);
    }

    /** O usuário atual está isento do bloqueio obrigatório? */
    public static function canBypassEnforcement(): bool {
        return (bool)Session::haveRight('plugin_svm_bypass', READ);
    }

    /** O usuário atual pode exportar os dados brutos? */
    public static function canExportReports(): bool {
        return (bool)Session::haveRight('plugin_svm_report', self::RIGHT_EXPORT);
    }
}
