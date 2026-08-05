<?php
/**
 * Plugin SVM - Formulário de configuração da pesquisa.
 */

include('../../../inc/includes.php');

Session::checkLoginUser();

/**
 * Registra no histórico do GLPI.
 *
 * A classe Event fica em Glpi\Event (não no namespace global), e o nome
 * mudou de lugar entre versões — daí a resolução dinâmica com guarda.
 * O log é acessório: se a classe não existir, a operação segue.
 */
function plugin_svm_log_event(int $items_id, string $message): void {
    foreach (['\\Glpi\\Event', '\\Event'] as $class) {
        if (class_exists($class) && method_exists($class, 'log')) {
            $class::log($items_id, 'PluginSvmConfig', 4, 'setup', $message);
            return;
        }
    }
}

$config = new PluginSvmConfig();
$who    = $_SESSION['glpiname'] ?? '';

if (isset($_POST['add'])) {
    $config->check(-1, CREATE, $_POST);
    if ($newID = $config->add($_POST)) {
        plugin_svm_log_event(
            (int)$newID,
            sprintf(__('%s adiciona uma configuração de pesquisa', 'svm'), $who)
        );
        Html::redirect($config->getFormURLWithID($newID));
    }
    Html::back();

} elseif (isset($_POST['update'])) {
    $id = (int)($_POST['id'] ?? 0);
    $config->check($id, UPDATE);
    $config->update($_POST);
    plugin_svm_log_event(
        $id,
        sprintf(__('%s altera a configuração de pesquisa', 'svm'), $who)
    );
    Html::back();

} elseif (isset($_POST['purge']) || isset($_GET['purge'])) {
    // check() valida o direito, não a origem da requisição: sem isto, um
    // simples <img src="...?purge=1&id=1"> apagaria a configuração.
    Session::checkCSRF($_REQUEST);

    $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    $config->check($id, PURGE);
    $config->delete(['id' => $id], true);
    plugin_svm_log_event(
        $id,
        sprintf(__('%s exclui uma configuração de pesquisa', 'svm'), $who)
    );
    $config->redirectToList();

} else {
    Session::checkRight('plugin_svm_config', READ);

    $id = (int)($_GET['id'] ?? -1);

    Html::header(
        PluginSvmConfig::getTypeName(1),
        $_SERVER['PHP_SELF'],
        'admin',
        'PluginSvmConfig'
    );

    $config->display(['id' => $id]);

    Html::footer();
}
