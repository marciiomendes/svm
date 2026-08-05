<?php
/**
 * Plugin SVM - Resposta individual a uma pergunta da pesquisa.
 */

if (!defined('GLPI_ROOT')) {
    die("Access denied");
}

class PluginSvmAnswer extends CommonDBChild
{
    public static $rightname = 'plugin_svm_survey';

    public static $itemtype  = 'PluginSvmSurvey';
    public static $items_id  = 'plugin_svm_surveys_id';

    public static function getTypeName($nb = 0) {
        return _n('Resposta', 'Respostas', $nb, 'svm');
    }

    /**
     * Grava as respostas de uma pesquisa.
     *
     * @param int   $surveys_id
     * @param array $answers  [questions_id => ['int' => int|null, 'text' => string|null]]
     */
    public static function saveBatch(int $surveys_id, array $answers): bool {
        global $DB;

        $ok  = true;
        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');

        // Regravação idempotente: se esta pesquisa já tinha respostas (caso
        // de uma linha antes "adiada"), a chave única survey_question
        // faria o add() falhar silenciosamente.
        $DB->delete(self::getTable(), ['plugin_svm_surveys_id' => $surveys_id]);

        foreach ($answers as $questions_id => $value) {
            $answer = new self();
            $res = $answer->add([
                'plugin_svm_surveys_id'   => $surveys_id,
                'plugin_svm_questions_id' => (int)$questions_id,
                'answer_int'              => isset($value['int']) && $value['int'] !== null
                                             ? (int)$value['int'] : -1,
                'answer_text'             => isset($value['text']) ? (string)$value['text'] : null,
                'date_creation'           => $now,
            ]);
            if ($res === false) {
                $ok = false;
            }
        }

        return $ok;
    }

    /** Respostas de uma pesquisa, já com o enunciado da pergunta. */
    public static function getForSurvey(int $surveys_id): array {
        global $DB;

        $out = [];
        foreach ($DB->request([
            'SELECT' => [
                'glpi_plugin_svm_answers.*',
                'glpi_plugin_svm_questions.name AS question_name',
                'glpi_plugin_svm_questions.question_type',
                'glpi_plugin_svm_questions.weight',
                'glpi_plugin_svm_questions.rank',
            ],
            'FROM' => 'glpi_plugin_svm_answers',
            'LEFT JOIN' => [
                'glpi_plugin_svm_questions' => [
                    'ON' => [
                        'glpi_plugin_svm_answers'   => 'plugin_svm_questions_id',
                        'glpi_plugin_svm_questions' => 'id',
                    ],
                ],
            ],
            'WHERE' => ['plugin_svm_surveys_id' => $surveys_id],
            'ORDER' => ['glpi_plugin_svm_questions.rank ASC'],
        ]) as $row) {
            $out[] = $row;
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Aba dentro da pesquisa
    // ------------------------------------------------------------------

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
        if ($item instanceof PluginSvmSurvey && $item->getID() > 0) {
            $nb = 0;
            if ($_SESSION['glpishow_count_on_tabs'] ?? false) {
                $nb = countElementsInTable(self::getTable(), [
                    'plugin_svm_surveys_id' => $item->getID(),
                ]);
            }
            return self::createTabEntry(self::getTypeName(Session::getPluralNumber()), $nb);
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
        if ($item instanceof PluginSvmSurvey) {
            self::showForSurvey($item);
            return true;
        }
        return false;
    }

    public static function showForSurvey(PluginSvmSurvey $survey) {
        $answers = self::getForSurvey($survey->getID());

        echo "<div class='spaced'>";

        if (empty($answers)) {
            echo "<p class='center'>" . __('Nenhuma resposta detalhada registrada.', 'svm') . "</p>";
            echo "</div>";
            return;
        }

        $types = PluginSvmQuestion::getQuestionTypes();

        echo "<table class='tab_cadre_fixehov'>";
        echo "<tr class='tab_bg_2'>";
        echo "<th>" . __('Pergunta', 'svm') . "</th>";
        echo "<th>" . __('Tipo') . "</th>";
        echo "<th>" . __('Resposta', 'svm') . "</th>";
        echo "</tr>";

        foreach ($answers as $a) {
            $label = (string)($a['question_name'] ?? __('(pergunta excluída)', 'svm'));
            $type  = (string)($a['question_type'] ?? '');

            if ($type === 'text') {
                // Desfaz a sanitização do GLPI antes de reescapar, senão
                // apóstrofos aparecem como "&#39;".
                $raw = class_exists('Glpi\\Toolbox\\Sanitizer')
                    ? \Glpi\Toolbox\Sanitizer::unsanitize((string)$a['answer_text'])
                    : html_entity_decode((string)$a['answer_text'], ENT_QUOTES, 'UTF-8');
                $value = nl2br(htmlspecialchars($raw, ENT_QUOTES, 'UTF-8'));
            } elseif ($type === 'bool') {
                $value = (int)$a['answer_int'] === 1 ? __('Sim') : __('Não');
            } elseif ((int)$a['answer_int'] < 0) {
                $value = "<i class='text-muted'>" . __('Não respondida', 'svm') . "</i>";
            } else {
                $value = "<b>" . (int)$a['answer_int'] . "</b>";
                if ($type === 'nps') {
                    $class = PluginSvmConfig::classifyNps((int)$a['answer_int']);
                    $names = [
                        'promoter'  => __('promotor', 'svm'),
                        'passive'   => __('neutro', 'svm'),
                        'detractor' => __('detrator', 'svm'),
                    ];
                    $value .= " <span class='text-muted'>(" . ($names[$class] ?? '') . ")</span>";
                }
            }

            echo "<tr class='tab_bg_1'>";
            echo "<td>" . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . "</td>";
            echo "<td>" . ($types[$type] ?? $type) . "</td>";
            echo "<td>" . $value . "</td>";
            echo "</tr>";
        }

        echo "</table>";
        echo "</div>";
    }
}
