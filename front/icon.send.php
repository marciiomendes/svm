<?php
/**
 * Plugin SVM - Entrega dos ícones enviados por upload.
 *
 * Os arquivos ficam em files/_plugins/svm/icons, fora da raiz web, e são
 * servidos apenas por aqui. Qualquer usuário autenticado pode ver o ícone
 * (ele aparece no modal de pesquisa para todos), mas o nome do arquivo é
 * validado contra o formato que o plugin gera, o que impede travessia de
 * diretório, e o Content-Type é fixo, o que impede o navegador de
 * interpretar o conteúdo como outra coisa.
 */

include('../../../inc/includes.php');

Session::checkLoginUser();

$requested = (string)($_GET['f'] ?? '');

// Remove a sanitização do GLPI antes de validar o formato.
if (class_exists('Glpi\\Toolbox\\Sanitizer')) {
    $requested = \Glpi\Toolbox\Sanitizer::unsanitize($requested);
}

$path = PluginSvmConfig::getIconPath($requested);

if ($path === null || !is_file($path) || !is_readable($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Not found';
    exit;
}

// Confirma que o caminho resolvido continua dentro do diretório esperado.
$real = realpath($path);
$base = realpath(PluginSvmConfig::getIconDir());

if ($real === false || $base === false || strpos($real, $base . DIRECTORY_SEPARATOR) !== 0) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Forbidden';
    exit;
}

$mtime = filemtime($real);
$etag  = '"' . md5($real . (string)$mtime) . '"';

// Resposta condicional ANTES dos headers de entidade: um 304 não deve
// carregar Content-Type/Content-Length, o que faria clientes com keep-alive
// esperarem por bytes que nunca vêm.
$if_none_match     = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
$if_modified_since = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';

if ($if_none_match === $etag
    || ($if_modified_since !== '' && strtotime($if_modified_since) >= $mtime)) {
    header('ETag: ' . $etag);
    header('Cache-Control: private, max-age=31536000, immutable');
    http_response_code(304);
    exit;
}

// A compressão automática recalcularia o corpo e invalidaria o Content-Length.
@ini_set('zlib.output_compression', 'Off');

// Os arquivos são imutáveis: o nome muda a cada upload.
header('Content-Type: image/png');
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'; sandbox');
header('Content-Disposition: inline; filename="' . basename($real) . '"');
header('Content-Length: ' . filesize($real));
header('Cache-Control: private, max-age=31536000, immutable');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
header('ETag: ' . $etag);

// Descarta buffers do GLPI para não corromper o binário. O @ e o break
// evitam laço infinito quando um buffer não é removível.
while (ob_get_level() > 0) {
    if (!@ob_end_clean()) {
        break;
    }
}

readfile($real);
