<?php
/**
 * Plugin SVM - Formulário de pergunta da pesquisa.
 */

include('../../../inc/includes.php');

Session::checkLoginUser();

$question = new PluginSvmQuestion();

if (isset($_POST['add'])) {
    $question->check(-1, CREATE, $_POST);
    $question->add($_POST);
    Html::back();

} elseif (isset($_POST['update'])) {
    $question->check((int)($_POST['id'] ?? 0), UPDATE);
    $question->update($_POST);
    Html::back();

} elseif (isset($_POST['purge']) || isset($_GET['purge'])) {
    Session::checkCSRF($_REQUEST);

    $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    $question->check($id, PURGE);

    // Remove as respostas vinculadas antes de excluir a pergunta.
    global $DB;
    $DB->delete('glpi_plugin_svm_answers', ['plugin_svm_questions_id' => $id]);

    $question->delete(['id' => $id], true);
    Html::back();

} else {
    Session::checkRight('plugin_svm_question', READ);

    $id = (int)($_GET['id'] ?? -1);

    Html::header(
        PluginSvmQuestion::getTypeName(1),
        $_SERVER['PHP_SELF'],
        'admin',
        'PluginSvmConfig'
    );

    $question->display(['id' => $id]);

    Html::footer();
}
