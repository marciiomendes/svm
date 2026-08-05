<?php
/**
 * Plugin SVM - Endpoint AJAX da pesquisa de satisfação.
 *
 * Ações:
 *  - check : devolve config, perguntas e chamados pendentes (GET, só leitura)
 *  - save  : grava a avaliação (POST)
 *  - skip  : adia a pesquisa, se permitido na configuração (POST)
 *
 * CSRF: usa um token próprio, estável por sessão (svm_token). Os tokens
 * nativos do GLPI são de uso único — reaproveitá-los aqui prenderia o
 * usuário no modal na primeira resposta rejeitada pela validação.
 */

include('../../../inc/includes.php');

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

Session::checkLoginUser();

$users_id = (int)Session::getLoginUserID();
$action   = $_POST['action'] ?? $_GET['action'] ?? 'check';

if ($users_id <= 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('Sessão inválida.', 'svm')]);
    exit;
}

/** Token CSRF do plugin: um por sessão, reutilizável. */
function plugin_svm_token(): string {
    if (empty($_SESSION['plugin_svm_token'])) {
        $_SESSION['plugin_svm_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['plugin_svm_token'];
}

// ----------------------------------------------------------------------
// check — somente leitura, não altera estado
// ----------------------------------------------------------------------
if ($action === 'check') {
    $data = PluginSvmSurvey::getSurveyData($users_id);

    echo json_encode([
        'must_lock'    => (bool)$data['must_lock'],
        'show_prompt'  => (bool)($data['show_prompt'] ?? false),
        'enforce_mode' => $data['enforce_mode'],
        'count'        => (int)$data['count'],
        'tickets'      => $data['tickets'],
        'config'       => $data['config'],
        'questions'    => $data['questions'],
        'nps'          => $data['nps'],
        'svm_token'    => plugin_svm_token(),
    ]);
    exit;
}

// ----------------------------------------------------------------------
// A partir daqui há alteração de estado: exige POST + token do plugin
// ----------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => __('Método não permitido.', 'svm')]);
    exit;
}

$sent = (string)($_POST['svm_token'] ?? '');
if ($sent === '' || !hash_equals(plugin_svm_token(), $sent)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => __('Token de segurança inválido. Recarregue a página.', 'svm'),
    ]);
    exit;
}

// ----------------------------------------------------------------------
// save
// ----------------------------------------------------------------------
if ($action === 'save') {
    // answers[<questions_id>] = valor
    $answers = [];
    if (isset($_POST['answers']) && is_array($_POST['answers'])) {
        foreach ($_POST['answers'] as $qid => $value) {
            $answers[(int)$qid] = is_array($value) ? null : $value;
        }
    }

    $result = PluginSvmSurvey::saveResponse([
        'tickets_id' => (int)($_POST['tickets_id'] ?? 0),
        'users_id'   => $users_id,
        'answers'    => $answers,
        'comment'    => (string)($_POST['comment'] ?? ''),
        'nps_score'  => $_POST['nps_score'] ?? '',
    ]);

    if (!$result['success']) {
        http_response_code(422);
    }

    echo json_encode($result);
    exit;
}

// ----------------------------------------------------------------------
// skip
// ----------------------------------------------------------------------
if ($action === 'skip') {
    $result = PluginSvmSurvey::skipSurvey((int)($_POST['tickets_id'] ?? 0), $users_id);
    if (!$result['success']) {
        http_response_code(422);
    }
    echo json_encode($result);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => __('Ação desconhecida.', 'svm')]);
