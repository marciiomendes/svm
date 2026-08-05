<?php
/**
 * Plugin SVM - Redireciona para a lista de configurações.
 */

include('../../../inc/includes.php');

Session::checkLoginUser();

Html::redirect(Plugin::getWebDir('svm') . '/front/config.php');
