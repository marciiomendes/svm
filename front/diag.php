<?php
/**
 * Plugin SVM - Diagnóstico de instalação.
 *
 * Mostra se os arquivos em disco, a versão registrada no GLPI, as colunas
 * do banco e o PHP estão coerentes. Útil quando a tela de configuração não
 * reflete o código que está no servidor.
 *
 * Acesse: /plugins/svm/front/diag.php
 * Pode apagar este arquivo depois de resolver.
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
Session::checkRight('plugin_svm_config', READ);

Html::header('SVM - Diagnóstico', $_SERVER['PHP_SELF'], 'admin', 'PluginSvmConfig');

global $DB;

function svm_row(string $label, bool $ok, string $value, string $hint = '') {
    $icon  = $ok ? '✅' : '❌';
    $color = $ok ? '#16a34a' : '#dc2626';
    echo "<tr>";
    echo "<td style='padding:6px 12px'><b>" . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . "</b></td>";
    echo "<td style='padding:6px 12px;color:$color'>$icon " . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . "</td>";
    echo "<td style='padding:6px 12px;color:#64748b;font-size:12px'>"
         . htmlspecialchars($hint, ENT_QUOTES, 'UTF-8') . "</td>";
    echo "</tr>";
}

echo "<div class='card'><div class='card-body'>";
echo "<h2>Diagnóstico do plugin SVM</h2>";
echo "<table class='tab_cadre_fixe' style='width:100%'>";

// ------------------------------------------------------------------
// 1. Versão dos arquivos x versão registrada
// ------------------------------------------------------------------
$file_version = PLUGIN_SVM_VERSION;

$reg = $DB->request([
    'SELECT' => ['version', 'state'],
    'FROM'   => 'glpi_plugins',
    'WHERE'  => ['directory' => 'svm'],
    'LIMIT'  => 1,
])->current();

$db_version = (string)($reg['version'] ?? '(não registrado)');

svm_row(
    'Versão nos arquivos (setup.php)',
    true,
    $file_version
);

svm_row(
    'Versão registrada no GLPI',
    $db_version === $file_version,
    $db_version,
    $db_version === $file_version
        ? 'Coincide: a migração já rodou.'
        : 'DIFERENTE. Vá em Configurar > Plugins e clique em Atualizar no SVM.'
);

// ------------------------------------------------------------------
// 2. Colunas do banco
// ------------------------------------------------------------------
$expected = [
    'icon_image_file'       => 'upload da imagem ativa',
    'icon_image_empty_file' => 'upload da imagem inativa',
    'answers_count'         => 'agregação de CSAT',
    'satisfied_count'       => 'agregação de CSAT',
];

foreach ($expected as $col => $why) {
    $table = in_array($col, ['answers_count', 'satisfied_count'], true)
        ? 'glpi_plugin_svm_surveys'
        : 'glpi_plugin_svm_configs';

    $exists = $DB->tableExists($table) && $DB->fieldExists($table, $col);
    svm_row(
        "Coluna $table.$col",
        $exists,
        $exists ? 'existe' : 'AUSENTE',
        $exists ? $why : "Necessária para: $why. Rode a atualização do plugin."
    );
}

// Largura de icon_char (sequência de emojis precisa de 255)
if ($DB->tableExists('glpi_plugin_svm_configs')) {
    $len = 0;

    // SQL cru precisa de doQuery/query: $DB->request() com string foi
    // proibido no GLPI 11 (lança InvalidArgumentException).
    $run = method_exists($DB, 'doQuery') ? 'doQuery' : 'query';
    $res = $DB->$run("SHOW COLUMNS FROM `glpi_plugin_svm_configs` LIKE 'icon_char'");

    if ($res) {
        while ($c = $DB->fetchAssoc($res)) {
            if (preg_match('/\((\d+)\)/', (string)($c['Type'] ?? ''), $m)) {
                $len = (int)$m[1];
            }
        }
    }
    svm_row(
        'Largura de icon_char',
        $len >= 255,
        $len > 0 ? "varchar($len)" : 'desconhecida',
        $len >= 255
            ? 'Comporta a sequência de carinhas.'
            : 'Precisa de 255 para guardar um emoji por nota.'
    );
}

// ------------------------------------------------------------------
// 3. Arquivos em disco
// ------------------------------------------------------------------
// getPhpDir() pode devolver false; sem o guard, o filemtime() abaixo
// geraria warning.
$plugin_dir = Plugin::getPhpDir('svm');
if (!is_string($plugin_dir) || $plugin_dir === '') {
    $plugin_dir = __DIR__ . '/..';
}

foreach ([
    'front/icon.send.php' => 'entrega das imagens',
    'inc/config.class.php' => 'tela de configuração',
    'js/enforce.js'        => 'modal da pesquisa',
] as $rel => $why) {
    $path = $plugin_dir . '/' . $rel;
    $ok   = is_file($path);
    svm_row(
        "Arquivo $rel",
        $ok,
        $ok ? 'presente (' . date('d/m/Y H:i', filemtime($path)) . ')' : 'AUSENTE',
        $why
    );
}

// A tela de upload existe no código carregado em memória?
$has_upload = method_exists('PluginSvmConfig', 'storeUploadedIcon');
svm_row(
    'Código de upload carregado',
    $has_upload,
    $has_upload ? 'sim' : 'NÃO',
    $has_upload
        ? 'A classe em memória já tem o upload.'
        : 'O PHP está usando uma versão antiga do arquivo. Provável OPcache: reinicie o PHP-FPM/Apache.'
);

$has_presets = method_exists('PluginSvmConfig', 'getIconPresets');
svm_row(
    'Dropdown de ícones carregado',
    $has_presets,
    $has_presets ? 'sim' : 'NÃO',
    $has_presets ? '' : 'Mesmo caso acima: OPcache ou arquivo não substituído.'
);

// ------------------------------------------------------------------
// 3b. APIs do core usadas pelo plugin
// ------------------------------------------------------------------
// Classes e funções do GLPI mudam de nome/namespace entre versões. Aqui
// verificamos cada dependência de uma vez, em vez de descobrir uma a cada
// erro fatal.
echo "<tr><td colspan='3' style='padding:12px 12px 4px'><b>Dependências do core</b></td></tr>";

svm_row('Versão do GLPI', true, defined('GLPI_VERSION') ? GLPI_VERSION : '(desconhecida)');

$classes = [
    'Glpi\\Event'            => 'histórico de alterações (opcional)',
    'Glpi\\Toolbox\\Sanitizer' => 'tratamento de texto do banco (opcional)',
    'Ticket'                 => 'leitura dos chamados',
    'CommonITILActor'        => 'identificar requerente e técnico',
    'ITILCategory'           => 'filtro por categoria',
    'Group'                  => 'filtro por grupo',
    'Entity'                 => 'filtro por entidade',
    'ProfileRight'           => 'direitos por perfil',
    'Migration'              => 'migração do banco',
    'Search'                 => 'visão analítica',
];

foreach ($classes as $class => $why) {
    $exists   = class_exists($class);
    $optional = strpos($why, '(opcional)') !== false;
    svm_row(
        "Classe $class",
        $exists || $optional,
        $exists ? 'existe' : 'AUSENTE',
        $exists ? $why : ($optional ? "Não encontrada — o plugin funciona sem ela. ($why)"
                                   : "OBRIGATÓRIA: $why")
    );
}

$functions = [
    'getSonsOf'                    => 'escopo de subentidades',
    'getAncestorsOf'               => 'herança da configuração',
    'getEntitiesRestrictCriteria'  => 'restrição de entidade',
    'countElementsInTable'         => 'contagens',
    'isIndex'                      => 'migração da chave única',
];

foreach ($functions as $fn => $why) {
    $exists = function_exists($fn);
    svm_row(
        "Função $fn()",
        $exists,
        $exists ? 'existe' : 'AUSENTE',
        $exists ? $why : "OBRIGATÓRIA: $why"
    );
}

$db_methods = [];
foreach (['request', 'tableExists', 'fieldExists', 'update', 'delete', 'insert'] as $m) {
    if (!method_exists($DB, $m)) {
        $db_methods[] = $m;
    }
}
svm_row(
    'Métodos do DB',
    empty($db_methods),
    empty($db_methods) ? 'todos presentes' : 'faltam: ' . implode(', ', $db_methods),
    'request/tableExists/fieldExists/update/delete/insert'
);

svm_row(
    'Execução de SQL bruto',
    method_exists($DB, 'doQuery') || method_exists($DB, 'query'),
    method_exists($DB, 'doQuery') ? 'doQuery() (GLPI 10.0.11+)' : 'query() (legado)',
    'usado só na migração de índice e no DROP TABLE'
);

$profile_matrix = method_exists('Profile', 'displayRightsChoiceMatrix');
svm_row(
    'Matriz de direitos em Perfis',
    $profile_matrix,
    $profile_matrix ? 'disponível' : 'AUSENTE',
    'renderiza a aba de permissões do plugin'
);

// ------------------------------------------------------------------
// 4. Ambiente PHP
// ------------------------------------------------------------------
echo "<tr><td colspan='3' style='padding:12px 12px 4px'><b>Ambiente</b></td></tr>";

svm_row('Versão do PHP', true, PHP_VERSION);

$gd = extension_loaded('gd');
svm_row(
    'Extensão GD',
    $gd,
    $gd ? 'disponível' : 'AUSENTE',
    $gd ? 'Necessária para converter as imagens enviadas.'
        : 'Sem GD o upload de imagem não funciona. Instale php-gd.'
);

if ($gd && function_exists('imagetypes')) {
    $t = imagetypes();
    $fmts = [];
    if ($t & IMG_PNG)  { $fmts[] = 'PNG'; }
    if ($t & IMG_JPG)  { $fmts[] = 'JPG'; }
    if ($t & IMG_GIF)  { $fmts[] = 'GIF'; }
    if (defined('IMG_WEBP') && ($t & IMG_WEBP)) { $fmts[] = 'WEBP'; }
    svm_row('Formatos suportados pelo GD', !empty($fmts), implode(', ', $fmts) ?: 'nenhum');
}

$upload_on = (bool)ini_get('file_uploads');
svm_row(
    'file_uploads no PHP',
    $upload_on,
    $upload_on ? 'ativado' : 'DESATIVADO',
    'upload_max_filesize=' . ini_get('upload_max_filesize')
    . ', post_max_size=' . ini_get('post_max_size')
);

$opcache = function_exists('opcache_get_status');
if ($opcache) {
    $status = @opcache_get_status(false);
    $enabled = is_array($status) && !empty($status['opcache_enabled']);
    svm_row(
        'OPcache',
        true,
        $enabled ? 'ativo' : 'inativo',
        $enabled
            ? 'Se você substituiu arquivos e a tela não mudou, reinicie o PHP-FPM/Apache.'
            : 'Arquivos são lidos do disco a cada requisição.'
    );
}

// ------------------------------------------------------------------
// 5. Diretório dos ícones
// ------------------------------------------------------------------
$dir = PluginSvmConfig::getIconDir();
$dir_ok = is_dir($dir);
svm_row(
    'Diretório dos ícones',
    $dir_ok && is_writable($dir),
    $dir_ok ? ($dir . (is_writable($dir) ? ' (gravável)' : ' (SEM permissão de escrita)')) : 'não existe',
    $dir_ok ? '' : 'Será criado na atualização do plugin, ou crie manualmente.'
);

// ------------------------------------------------------------------
// 6. Configurações cadastradas
// ------------------------------------------------------------------
if ($DB->tableExists('glpi_plugin_svm_configs')) {
    $n = countElementsInTable('glpi_plugin_svm_configs');
    svm_row(
        'Configurações cadastradas',
        $n > 0,
        (string)$n,
        $n > 0 ? 'Abra uma delas para ver os campos de ícone.'
               : 'Nenhuma. Crie uma configuração na tela do plugin.'
    );
}

echo "</table>";

echo "<h3 style='margin-top:20px'>Se algo estiver com ❌</h3>";
echo "<ol style='line-height:1.8'>";
echo "<li><b>Versão diferente ou colunas ausentes:</b> em <i>Configurar &gt; Plugins</i>, "
   . "clique em <b>Atualizar</b> na linha do SVM. É isso que executa a migração do banco.</li>";
echo "<li><b>Código de upload não carregado:</b> os arquivos novos não estão sendo lidos. "
   . "Confirme que substituiu a pasta inteira do plugin e reinicie o PHP "
   . "(<code>systemctl restart php-fpm</code> ou o Apache).</li>";
echo "<li><b>GD ausente:</b> instale <code>php-gd</code> e reinicie o PHP.</li>";
echo "</ol>";

echo "<p style='color:#64748b;font-size:12px'>Pode apagar este arquivo "
   . "(<code>front/diag.php</code>) quando terminar.</p>";

echo "</div></div>";

Html::footer();
