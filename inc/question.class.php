<?php
/**
 * Plugin SVM - Perguntas configuráveis da pesquisa.
 *
 * Aparece como aba dentro de uma configuração (PluginSvmConfig).
 * Boa prática de CSAT: manter 1 a 3 perguntas de escala + 1 aberta.
 * Acima de ~5 perguntas a taxa de resposta cai de forma acentuada.
 */

if (!defined('GLPI_ROOT')) {
    die("Access denied");
}

class PluginSvmQuestion extends CommonDBChild
{
    public static $rightname = 'plugin_svm_question';

    public static $itemtype  = 'PluginSvmConfig';
    public static $items_id  = 'plugin_svm_configs_id';

    public $dohistory        = true;

    /** Acima deste número o formulário avisa sobre fadiga de pesquisa. */
    const RECOMMENDED_MAX = 5;

    public static function getTypeName($nb = 0) {
        return _n('Pergunta', 'Perguntas', $nb, 'svm');
    }

    public static function getIcon() {
        return 'ti ti-help-circle';
    }

    public static function getQuestionTypes(): array {
        return [
            'scale' => __('Nota (usa a escala da configuração)', 'svm'),
            'nps'   => __('NPS 0 a 10', 'svm'),
            'text'  => __('Texto livre', 'svm'),
            'bool'  => __('Sim / Não', 'svm'),
        ];
    }

    /**
     * Perguntas ativas de uma configuração, na ordem definida.
     */
    public static function getActiveForConfig(int $configs_id): array {
        global $DB;

        $out = [];
        foreach ($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['plugin_svm_configs_id' => $configs_id, 'is_active' => 1],
            'ORDER' => ['rank ASC', 'id ASC'],
        ]) as $row) {
            $out[] = $row;
        }
        return $out;
    }

    /** Só as perguntas que produzem nota numérica (entram no CSAT). */
    public static function getScoringForConfig(int $configs_id): array {
        return array_values(array_filter(
            self::getActiveForConfig($configs_id),
            static fn($q) => in_array($q['question_type'], ['scale', 'nps'], true)
        ));
    }

    public function prepareInputForAdd($input) {
        if (empty(trim((string)($input['name'] ?? '')))) {
            Session::addMessageAfterRedirect(__('O enunciado da pergunta é obrigatório.', 'svm'), false, ERROR);
            return false;
        }

        // rank automático no fim da lista
        if (!isset($input['rank']) || (int)$input['rank'] === 0) {
            global $DB;
            $max = $DB->request([
                'SELECT' => ['MAX' => 'rank AS maxrank'],
                'FROM'   => self::getTable(),
                'WHERE'  => ['plugin_svm_configs_id' => (int)$input['plugin_svm_configs_id']],
            ])->current();
            $input['rank'] = (int)($max['maxrank'] ?? 0) + 10;
        }

        $input['date_creation'] = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $input['date_mod']      = $input['date_creation'];

        self::warnIfTooManyQuestions((int)$input['plugin_svm_configs_id']);

        return parent::prepareInputForAdd($input);
    }

    public function prepareInputForUpdate($input) {
        if (isset($input['name']) && empty(trim((string)$input['name']))) {
            Session::addMessageAfterRedirect(__('O enunciado da pergunta é obrigatório.', 'svm'), false, ERROR);
            return false;
        }
        $input['date_mod'] = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        return parent::prepareInputForUpdate($input);
    }

    private static function warnIfTooManyQuestions(int $configs_id) {
        global $DB;
        $cnt = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => self::getTable(),
            'WHERE' => ['plugin_svm_configs_id' => $configs_id, 'is_active' => 1],
        ])->current();

        if ((int)($cnt['cpt'] ?? 0) >= self::RECOMMENDED_MAX) {
            Session::addMessageAfterRedirect(
                sprintf(
                    __('Atenção: %d perguntas ativas. Pesquisas com mais de %d perguntas reduzem bastante a taxa de resposta — o ideal é a pesquisa levar menos de 30 segundos.', 'svm'),
                    (int)$cnt['cpt'] + 1,
                    self::RECOMMENDED_MAX
                ),
                false, WARNING
            );
        }
    }

    // ------------------------------------------------------------------
    // Aba dentro da configuração
    // ------------------------------------------------------------------

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
        if ($item instanceof PluginSvmConfig && $item->getID() > 0) {
            $nb = 0;
            if ($_SESSION['glpishow_count_on_tabs'] ?? false) {
                $nb = countElementsInTable(self::getTable(), [
                    'plugin_svm_configs_id' => $item->getID(),
                ]);
            }
            return self::createTabEntry(self::getTypeName(Session::getPluralNumber()), $nb);
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
        if ($item instanceof PluginSvmConfig) {
            self::showForConfig($item);
            return true;
        }
        return false;
    }

    /**
     * Lista + formulário inline de perguntas de uma configuração.
     */
    public static function showForConfig(PluginSvmConfig $config) {
        global $DB;

        $configs_id = $config->getID();
        $canedit    = self::canUpdate() && $config->can($configs_id, UPDATE);
        $cfg        = PluginSvmConfig::normalize($config->fields);

        $questions = [];
        foreach ($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['plugin_svm_configs_id' => $configs_id],
            'ORDER' => ['rank ASC', 'id ASC'],
        ]) as $row) {
            $questions[] = $row;
        }

        $active = count(array_filter($questions, static fn($q) => (int)$q['is_active'] === 1));

        echo "<div class='spaced'>";

        if ($active > self::RECOMMENDED_MAX) {
            echo "<div class='alert alert-warning'>" . sprintf(
                __('%d perguntas ativas. A recomendação é no máximo %d (1 a 3 de nota + 1 aberta) para não derrubar a taxa de resposta.', 'svm'),
                $active, self::RECOMMENDED_MAX
            ) . "</div>";
        }

        echo "<table class='tab_cadre_fixehov'>";
        echo "<tr><th colspan='8'>" . sprintf(__('%d pergunta(s)', 'svm'), count($questions)) . "</th></tr>";
        echo "<tr class='tab_bg_2'>";
        echo "<th>" . __('Ordem', 'svm') . "</th>";
        echo "<th>" . __('Enunciado', 'svm') . "</th>";
        echo "<th>" . __('Tipo') . "</th>";
        echo "<th>" . __('Peso', 'svm') . "</th>";
        echo "<th>" . __('Obrigatória', 'svm') . "</th>";
        echo "<th>" . __('Exige justificativa em nota baixa', 'svm') . "</th>";
        echo "<th>" . __('Ativa') . "</th>";
        echo "<th></th>";
        echo "</tr>";

        $types = self::getQuestionTypes();

        foreach ($questions as $q) {
            $dim = (int)$q['is_active'] === 1 ? '' : " style='opacity:.5'";
            echo "<tr class='tab_bg_1'$dim>";
            echo "<td class='center'>" . (int)$q['rank'] . "</td>";
            echo "<td>";
            if ($canedit) {
                echo "<a href='" . Plugin::getWebDir('svm') . "/front/question.form.php?id=" . (int)$q['id'] . "'>";
            }
            echo htmlspecialchars((string)$q['name'], ENT_QUOTES, 'UTF-8');
            if ($canedit) {
                echo "</a>";
            }
            if (!empty($q['helper_text'])) {
                echo "<br><span class='svm-hint'>"
                     . htmlspecialchars((string)$q['helper_text'], ENT_QUOTES, 'UTF-8')
                     . "</span>";
            }
            echo "</td>";
            echo "<td>" . ($types[$q['question_type']] ?? $q['question_type']) . "</td>";
            echo "<td class='center'>" . (float)$q['weight'] . "</td>";
            echo "<td class='center'>" . Dropdown::getYesNo($q['is_mandatory']) . "</td>";
            echo "<td class='center'>" . Dropdown::getYesNo($q['require_comment_on_low']) . "</td>";
            echo "<td class='center'>" . Dropdown::getYesNo($q['is_active']) . "</td>";
            echo "<td class='center'>";
            if ($canedit) {
                // A mensagem vai em data-attribute: traduções com apóstrofo
                // quebrariam um onclick inline.
                echo "<a class='btn btn-sm btn-outline-danger svm-purge-question' href='"
                     . Plugin::getWebDir('svm') . "/front/question.form.php?purge=1&id=" . (int)$q['id']
                     . "&" . self::$items_id . "=" . $configs_id
                     . "&_glpi_csrf_token=" . Session::getNewCSRFToken() . "'"
                     . " data-svm-confirm=\""
                     . htmlspecialchars(__('Excluir esta pergunta e suas respostas?', 'svm'), ENT_QUOTES, 'UTF-8')
                     . "\"><i class='fas fa-trash'></i></a>";
            }
            echo "</td>";
            echo "</tr>";
        }

        echo "</table>";

        // ---- Formulário de nova pergunta ----
        echo Html::scriptBlock(
            '$(document).on("click", ".svm-purge-question", function() {' .
            ' return confirm($(this).data("svm-confirm")); });'
        );

        // Html::closeForm() já injeta o _glpi_csrf_token — não duplicar aqui,
        // para não consumir dois tokens do pool por render.
        if ($canedit) {
            echo "<form method='post' action='" . Plugin::getWebDir('svm') . "/front/question.form.php'>";
            echo Html::hidden(self::$items_id, ['value' => $configs_id]);

            echo "<table class='tab_cadre_fixe'>";
            echo "<tr><th colspan='4'>" . __('Adicionar pergunta', 'svm') . "</th></tr>";

            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Enunciado', 'svm') . "</td><td colspan='3'>";
            echo Html::input('name', ['value' => '', 'size' => 90]);
            echo "<br><span class='svm-hint'>" .
                 __('Prefira linguagem neutra. Evite enunciados que sugiram a resposta desejada.', 'svm') .
                 "</span></td></tr>";

            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Texto de apoio', 'svm') . "</td><td colspan='3'>";
            echo Html::input('helper_text', ['value' => '', 'size' => 90]);
            echo "</td></tr>";

            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Tipo') . "</td><td>";
            Dropdown::showFromArray('question_type', $types, ['value' => 'scale']);
            echo "</td>";
            echo "<td>" . __('Ordem', 'svm') . "</td><td>";
            Dropdown::showNumber('rank', ['value' => 0, 'min' => 0, 'max' => 500, 'step' => 10]);
            echo "<br><span class='svm-hint'>" . __('0 = adicionar ao final.', 'svm') . "</span>";
            echo "</td></tr>";

            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Peso no cálculo do CSAT', 'svm') . "</td><td>";
            echo Html::input('weight', ['value' => '1', 'size' => 5, 'type' => 'number',
                                        'step' => '0.25', 'min' => '0']);
            echo "</td>";
            echo "<td>" . __('Obrigatória', 'svm') . "</td><td>";
            Dropdown::showYesNo('is_mandatory', 1);
            echo "</td></tr>";

            echo "<tr class='tab_bg_1'>";
            echo "<td>" . sprintf(__('Exigir justificativa se a nota for ≤ %d', 'svm'),
                                  (int)$cfg['justify_threshold']) . "</td><td>";
            Dropdown::showYesNo('require_comment_on_low', 1);
            echo "</td>";
            echo "<td>" . __('Ativa') . "</td><td>";
            Dropdown::showYesNo('is_active', 1);
            echo "</td></tr>";

            echo "<tr class='tab_bg_2'><td colspan='4' class='center'>";
            echo Html::submit(_sx('button', 'Add'), ['name' => 'add', 'class' => 'btn btn-primary']);
            echo "</td></tr>";
            echo "</table>";
            Html::closeForm();
        }

        echo "</div>";
    }

    /**
     * Formulário de edição de uma pergunta isolada.
     */
    public function showForm($ID, array $options = []) {
        if (!self::canView()) {
            return false;
        }

        $this->initForm($ID, $options);

        $config = new PluginSvmConfig();
        $cfg = $config->getFromDB((int)($this->fields['plugin_svm_configs_id'] ?? 0))
             ? PluginSvmConfig::normalize($config->fields)
             : PluginSvmConfig::normalize([]);

        $this->showFormHeader($options);

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Enunciado', 'svm') . "</td><td colspan='3'>";
        echo Html::input('name', ['value' => $this->fields['name'], 'size' => 90]);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Texto de apoio', 'svm') . "</td><td colspan='3'>";
        echo Html::input('helper_text', ['value' => $this->fields['helper_text'], 'size' => 90]);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Tipo') . "</td><td>";
        Dropdown::showFromArray('question_type', self::getQuestionTypes(), [
            'value' => $this->fields['question_type'],
        ]);
        echo "</td>";
        echo "<td>" . __('Ordem', 'svm') . "</td><td>";
        Dropdown::showNumber('rank', [
            'value' => $this->fields['rank'], 'min' => 0, 'max' => 500, 'step' => 10,
        ]);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Peso no cálculo do CSAT', 'svm') . "</td><td>";
        echo Html::input('weight', ['value' => $this->fields['weight'], 'size' => 5,
                                    'type' => 'number', 'step' => '0.25', 'min' => '0']);
        echo "</td>";
        echo "<td>" . __('Obrigatória', 'svm') . "</td><td>";
        Dropdown::showYesNo('is_mandatory', $this->fields['is_mandatory']);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . sprintf(__('Exigir justificativa se a nota for ≤ %d', 'svm'),
                              (int)$cfg['justify_threshold']) . "</td><td>";
        Dropdown::showYesNo('require_comment_on_low', $this->fields['require_comment_on_low']);
        echo "</td>";
        echo "<td>" . __('Ativa') . "</td><td>";
        Dropdown::showYesNo('is_active', $this->fields['is_active']);
        echo "</td></tr>";

        $this->showFormButtons($options);
        return true;
    }
}
