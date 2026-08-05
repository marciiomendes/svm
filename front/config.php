<?php
/**
 * Plugin SVM - Lista de configurações da pesquisa de satisfação.
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
Session::checkRight('plugin_svm_config', READ);

Html::header(
    PluginSvmConfig::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'admin',
    'PluginSvmConfig'
);

$config = new PluginSvmConfig();

// Atalho: se ainda não existe nenhuma configuração, oferece criar.
if (countElementsInTable(PluginSvmConfig::getTable()) === 0) {
    echo "<div class='alert alert-info mt-3'>";
    echo "<b>" . __('Nenhuma configuração encontrada.', 'svm') . "</b><br>";
    echo __('Crie uma configuração para a entidade raiz para começar a coletar CSAT e NPS.', 'svm');
    echo "</div>";
}

Search::show('PluginSvmConfig');

Html::footer();
