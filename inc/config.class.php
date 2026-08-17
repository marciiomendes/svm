<?php
/**
 * Plugin SVM - Configuração da pesquisa de satisfação.
 *
 * Uma configuração por entidade, com herança (is_recursive), seguindo o
 * padrão do GLPI. A resolução para um usuário/ticket sobe a árvore de
 * entidades até encontrar a config aplicável mais específica.
 */

if (!defined('GLPI_ROOT')) {
    die("Access denied");
}

class PluginSvmConfig extends CommonDBTM
{
    public static $rightname = 'plugin_svm_config';

    public $dohistory       = true;
    public static $tags     = '[SVM]';

    /** Cache em memória por entidade (evita N queries no mesmo request). */
    private static $resolved = [];

    public static function getTypeName($nb = 0) {
        return _n('Configuração da pesquisa', 'Configurações da pesquisa', $nb, 'svm');
    }

    public static function getIcon() {
        return 'ti ti-mood-smile';
    }

    // ------------------------------------------------------------------
    // Vocabulários
    // ------------------------------------------------------------------

    /**
     * Escalas suportadas. CSAT 1-5 é o padrão de mercado para satisfação
     * com um atendimento pontual; NPS 0-10 mede lealdade e é tratado como
     * pergunta separada, não misturado na média de CSAT.
     */
    public static function getScaleTypes(): array {
        return [
            'csat3'  => __('CSAT 1 a 3 (rápida)', 'svm'),
            'csat5'  => __('CSAT 1 a 5 (recomendado)', 'svm'),
            'csat7'  => __('CSAT 1 a 7 (granular)', 'svm'),
            'csat10' => __('CSAT 1 a 10', 'svm'),
            'nps'    => __('NPS 0 a 10', 'svm'),
            'binary' => __('Binária (👍 / 👎)', 'svm'),
            'custom' => __('Personalizada (definir mín./máx.)', 'svm'),
        ];
    }

    /** Limites min/max implícitos de cada escala. */
    public static function getScaleBounds(string $type): array {
        $map = [
            'csat3'  => [1, 3],
            'csat5'  => [1, 5],
            'csat7'  => [1, 7],
            'csat10' => [1, 10],
            'nps'    => [0, 10],
            'binary' => [1, 2],
        ];
        return $map[$type] ?? [];
    }

    // ------------------------------------------------------------------
    // Ícones enviados por upload
    // ------------------------------------------------------------------

    /** Tamanho máximo aceito no upload (bytes). */
    const ICON_MAX_BYTES = 524288; // 512 KB

    /** Lado máximo da imagem depois do redimensionamento (px). */
    const ICON_MAX_SIDE = 128;

    /**
     * Tipos aceitos. SVG fica deliberadamente de fora: é XML e pode conter
     * script, que executaria na mesma origem do GLPI ao ser servido.
     */
    public static function getAllowedIconTypes(): array {
        $types = [
            IMAGETYPE_PNG  => 'png',
            IMAGETYPE_JPEG => 'jpg',
        ];

        // Só anuncia GIF/WEBP se o GD deste servidor realmente decodifica,
        // senão o arquivo passaria na validação e falharia no decode.
        $support = function_exists('imagetypes') ? imagetypes() : 0;

        if ($support & IMG_GIF) {
            $types[IMAGETYPE_GIF] = 'gif';
        }
        if (defined('IMG_WEBP') && ($support & IMG_WEBP)) {
            $types[IMAGETYPE_WEBP] = 'webp';
        }

        return $types;
    }

    /** Diretório dos ícones, fora da raiz web. */
    public static function getIconDir(): string {
        return GLPI_PLUGIN_DOC_DIR . '/svm/icons';
    }

    /** Caminho absoluto de um ícone a partir do nome gravado no banco. */
    public static function getIconPath(string $filename): ?string {
        $safe = self::sanitizeIconName($filename);
        if ($safe === null) {
            return null;
        }
        return self::getIconDir() . '/' . $safe;
    }

    /**
     * Valida o nome de arquivo vindo do banco ou da URL. Só aceita o
     * formato que nós mesmos geramos — barra a travessia de diretório.
     */
    public static function sanitizeIconName(string $filename): ?string {
        $filename = trim($filename);
        if ($filename === '' || !preg_match('/^svm_[a-f0-9]{32}\.png$/', $filename)) {
            return null;
        }
        return $filename;
    }

    /**
     * URL pública de um ícone. Arquivo enviado tem prioridade sobre a URL
     * externa informada manualmente.
     */
    public static function resolveIconUrl(array $config, bool $empty = false): string {
        $file_field = $empty ? 'icon_image_empty_file' : 'icon_image_file';
        $url_field  = $empty ? 'icon_image_empty_url'  : 'icon_image_url';

        $file = self::sanitizeIconName((string)($config[$file_field] ?? ''));
        if ($file !== null) {
            return Plugin::getWebDir('svm') . '/front/icon.send.php?f=' . rawurlencode($file);
        }

        // Sem unsanitize, um "&" de query string voltaria como "&#38;" e a
        // URL externa chegaria quebrada ao navegador.
        $url = (string)($config[$url_field] ?? '');
        if ($url !== '' && class_exists('Glpi\\Toolbox\\Sanitizer')) {
            $url = \Glpi\Toolbox\Sanitizer::unsanitize($url);
        }

        return $url;
    }

    /**
     * Processa um arquivo de $_FILES: valida, reencoda e grava.
     *
     * O reencode via GD é a proteção central: mesmo que alguém envie um
     * arquivo "poliglota" (imagem válida com PHP ou script anexado), o que
     * fica em disco é um PNG gerado por nós, sem os bytes originais.
     *
     * @return string|null nome do arquivo gravado, ou null em caso de erro
     */
    public static function storeUploadedIcon(array $file, ?string &$error = null): ?string {
        $error = null;

        if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error = ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE)
                ? __('A imagem excede o tamanho máximo permitido pelo servidor.', 'svm')
                : __('Falha no envio da imagem.', 'svm');
            return null;
        }

        $tmp = $file['tmp_name'] ?? '';
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            $error = __('Arquivo de envio inválido.', 'svm');
            return null;
        }

        if (filesize($tmp) > self::ICON_MAX_BYTES) {
            $error = sprintf(
                __('A imagem deve ter no máximo %s KB.', 'svm'),
                (int)(self::ICON_MAX_BYTES / 1024)
            );
            return null;
        }

        // Tipo real pelo conteúdo, não pela extensão nem pelo mime do cliente.
        $info = @getimagesize($tmp);
        if ($info === false || !isset($info[2])) {
            $error = __('O arquivo enviado não é uma imagem válida.', 'svm');
            return null;
        }

        $allowed = self::getAllowedIconTypes();
        if (!isset($allowed[$info[2]])) {
            $error = __('Formato não suportado. Use PNG, JPG, GIF ou WEBP.', 'svm');
            return null;
        }

        // Bomba de descompressão: um PNG de 100 KB pode declarar
        // 20000x20000 e exigir ~1,6 GB no decode, derrubando o PHP com um
        // fatal de memória que não é capturável. Barra antes de decodificar.
        $width  = (int)($info[0] ?? 0);
        $height = (int)($info[1] ?? 0);
        if ($width < 1 || $height < 1
            || $width > 4000 || $height > 4000
            || ($width * $height) > 8000000) {
            $error = __('Imagem com dimensões excessivas. Use no máximo 4000x4000 px.', 'svm');
            return null;
        }

        if (!function_exists('imagecreatefromstring') || !function_exists('imagepng')) {
            $error = __('A extensão GD do PHP não está disponível no servidor.', 'svm');
            return null;
        }

        $data = @file_get_contents($tmp);
        if ($data === false) {
            $error = __('Não foi possível ler a imagem enviada.', 'svm');
            return null;
        }

        $img = @imagecreatefromstring($data);
        if ($img === false) {
            $error = __('Não foi possível decodificar a imagem.', 'svm');
            return null;
        }

        $img = self::normalizeIconImage($img);

        // O GD inicializa saveAlphaFlag = 0 e o leitor de PNG não a liga:
        // sem isto, um PNG truecolor com fundo transparente sairia com o
        // alfa descartado (fundo preto) no caminho sem redimensionamento.
        imagealphablending($img, false);
        imagesavealpha($img, true);

        $dir = self::getIconDir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            imagedestroy($img);
            $error = __('Diretório de ícones indisponível. Verifique as permissões.', 'svm');
            return null;
        }

        $name = 'svm_' . bin2hex(random_bytes(16)) . '.png';
        $ok   = @imagepng($img, $dir . '/' . $name, 9);
        imagedestroy($img);

        if (!$ok) {
            $error = __('Não foi possível gravar a imagem no servidor.', 'svm');
            return null;
        }

        @chmod($dir . '/' . $name, 0644);

        return $name;
    }

    /**
     * Redimensiona para caber em ICON_MAX_SIDE preservando transparência.
     *
     * @param \GdImage|resource $img
     * @return \GdImage|resource
     */
    private static function normalizeIconImage($img) {
        $w   = imagesx($img);
        $h   = imagesy($img);
        $max = max($w, $h);

        if ($max <= self::ICON_MAX_SIDE) {
            return $img;
        }

        $ratio = self::ICON_MAX_SIDE / $max;
        $nw    = max(1, (int)round($w * $ratio));
        $nh    = max(1, (int)round($h * $ratio));

        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $nw - 1, $nh - 1, $transparent);

        if (imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h)) {
            imagedestroy($img);
            return $dst;
        }

        imagedestroy($dst);
        return $img;
    }

    /** Apaga um ícone do disco, se o nome for válido. */
    public static function deleteIconFile(?string $filename): void {
        if ($filename === null || $filename === '') {
            return;
        }
        $path = self::getIconPath($filename);
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
    }

    // ------------------------------------------------------------------
    // Presets de ícone (dropdown do formulário)
    // ------------------------------------------------------------------

    /**
     * Conjuntos prontos, agrupados para o dropdown.
     *
     * Dois formatos:
     *  - char único        → o mesmo ícone repetido em todos os pontos
     *  - sequência         → um ícone por nota, separados por espaço
     *                        (escala de carinhas, padrão clássico de CSAT)
     *
     * 'points' indica para quantos pontos a sequência foi feita; o
     * formulário avisa se a escala atual for diferente.
     */
    public static function getIconPresets(): array {
        return [
            __('Repetidos (preenchimento cumulativo)', 'svm') => [
                'star'   => ['label' => '⭐ Estrelas',      'type' => 'emoji', 'char' => '⭐', 'empty' => '☆'],
                'orange' => ['label' => '🍊 Laranjas',      'type' => 'emoji', 'char' => '🍊', 'empty' => ''],
                'heart'  => ['label' => '❤️ Corações',      'type' => 'emoji', 'char' => '❤️', 'empty' => '🤍'],
                'thumb'  => ['label' => '👍 Polegares',     'type' => 'emoji', 'char' => '👍', 'empty' => ''],
                'fire'   => ['label' => '🔥 Fogo',          'type' => 'emoji', 'char' => '🔥', 'empty' => ''],
                'check'  => ['label' => '✅ Confirmações',  'type' => 'emoji', 'char' => '✅', 'empty' => ''],
                'gem'    => ['label' => '💎 Diamantes',     'type' => 'emoji', 'char' => '💎', 'empty' => ''],
                'circle' => ['label' => '🟠 Círculos',      'type' => 'emoji', 'char' => '🟠', 'empty' => '⚪'],
                'rocket' => ['label' => '🚀 Foguetes',      'type' => 'emoji', 'char' => '🚀', 'empty' => ''],
                'clap'   => ['label' => '👏 Palmas',        'type' => 'emoji', 'char' => '👏', 'empty' => ''],
            ],

            __('Carinhas — uma por nota (recomendado para CSAT)', 'svm') => [
                'faces5' => [
                    'label'  => '😡 😕 😐 🙂 😍  (5 pontos)',
                    'type'   => 'emoji',
                    'char'   => '😡 😕 😐 🙂 😍',
                    'empty'  => '',
                    'points' => 5,
                ],
                'faces5b' => [
                    'label'  => '😠 😟 😐 😊 🤩  (5 pontos)',
                    'type'   => 'emoji',
                    'char'   => '😠 😟 😐 😊 🤩',
                    'empty'  => '',
                    'points' => 5,
                ],
                'faces3' => [
                    'label'  => '😞 😐 😊  (3 pontos)',
                    'type'   => 'emoji',
                    'char'   => '😞 😐 😊',
                    'empty'  => '',
                    'points' => 3,
                ],
                'traffic5' => [
                    'label'  => '🔴 🟠 🟡 🟢 💚  (5 pontos)',
                    'type'   => 'emoji',
                    'char'   => '🔴 🟠 🟡 🟢 💚',
                    'empty'  => '',
                    'points' => 5,
                ],
                'hands5' => [
                    'label'  => '👎 🤏 👌 👍 🙌  (5 pontos)',
                    'type'   => 'emoji',
                    'char'   => '👎 🤏 👌 👍 🙌',
                    'empty'  => '',
                    'points' => 5,
                ],
            ],

            __('Ícones Font Awesome', 'svm') => [
                'fa-star'        => ['label' => 'Estrela',   'type' => 'fontawesome', 'char' => 'fa-star',        'empty' => 'fa-star'],
                'fa-heart'       => ['label' => 'Coração',   'type' => 'fontawesome', 'char' => 'fa-heart',       'empty' => 'fa-heart'],
                'fa-thumbs-up'   => ['label' => 'Polegar',   'type' => 'fontawesome', 'char' => 'fa-thumbs-up',   'empty' => 'fa-thumbs-up'],
                'fa-smile'       => ['label' => 'Sorriso',   'type' => 'fontawesome', 'char' => 'fa-smile',       'empty' => 'fa-smile'],
                'fa-circle'      => ['label' => 'Círculo',   'type' => 'fontawesome', 'char' => 'fa-circle',      'empty' => 'fa-circle'],
                'fa-check-circle'=> ['label' => 'Check',     'type' => 'fontawesome', 'char' => 'fa-check-circle','empty' => 'fa-check-circle'],
                'fa-bolt'        => ['label' => 'Raio',      'type' => 'fontawesome', 'char' => 'fa-bolt',        'empty' => 'fa-bolt'],
                'fa-award'       => ['label' => 'Medalha',   'type' => 'fontawesome', 'char' => 'fa-award',       'empty' => 'fa-award'],
                'fa-gem'         => ['label' => 'Diamante',  'type' => 'fontawesome', 'char' => 'fa-gem',         'empty' => 'fa-gem'],
            ],

            __('Sem ícone', 'svm') => [
                'numbers' => ['label' => __('Apenas números', 'svm'), 'type' => 'number', 'char' => '', 'empty' => ''],
            ],
        ];
    }

    /**
     * Interpreta icon_char como sequência (um ícone por nota).
     * Separar por espaço evita ter de quebrar emoji por grafema, que é
     * traiçoeiro em UTF-8 (pares surrogados, ZWJ, seletor de variação).
     *
     * @return array lista de ícones; vazia se não for uma sequência
     */
    public static function parseIconSequence(?string $char): array {
        $char = trim((string)$char);
        if ($char === '') {
            return [];
        }

        $parts = preg_split('/[\s,]+/u', $char, -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false || count($parts) < 2) {
            return [];
        }

        return array_values($parts);
    }

    public static function getIconTypes(): array {
        return [
            'emoji'       => __('Emoji / caractere', 'svm'),
            'fontawesome' => __('Ícone Font Awesome', 'svm'),
            'image'       => __('Imagem (URL)', 'svm'),
            'number'      => __('Apenas números', 'svm'),
        ];
    }

    public static function getIconRenderModes(): array {
        return [
            'cumulative' => __('Cumulativo (estrelas: 1..N acendem)', 'svm'),
            'single'     => __('Único (apenas o ícone escolhido acende)', 'svm'),
        ];
    }

    public static function getEnforceModes(): array {
        return [
            'off'              => __('Não obrigar (pesquisa voluntária)', 'svm'),
            'reminder'         => __('Apenas lembrar (aviso dispensável)', 'svm'),
            'block_new_ticket' => __('Bloquear somente a abertura de novo chamado', 'svm'),
            'block_all'        => __('Bloquear o acesso ao sistema até responder', 'svm'),
        ];
    }

    public static function getTriggerTypes(): array {
        return [
            'immediate'    => __('A cada chamado encerrado', 'svm'),
            'closed_count' => __('A cada N chamados encerrados', 'svm'),
            'manual'       => __('Somente quando disparado manualmente', 'svm'),
        ];
    }

    /** Status de ticket elegíveis para pesquisa. */
    public static function getTargetStatusOptions(): array {
        return [
            Ticket::SOLVED => __('Solucionado'),
            Ticket::CLOSED => __('Fechado'),
        ];
    }

    // ------------------------------------------------------------------
    // Resolução por entidade (com herança)
    // ------------------------------------------------------------------

    /**
     * Retorna a config ativa aplicável à entidade informada, subindo a
     * árvore de entidades. Retorna null se nenhuma config ativa se aplica.
     */
    public static function getForEntity($entities_id = null): ?array {
        global $DB;

        if ($entities_id === null) {
            $entities_id = (int)($_SESSION['glpiactive_entity'] ?? 0);
        }
        $entities_id = (int)$entities_id;

        if (array_key_exists($entities_id, self::$resolved)) {
            return self::$resolved[$entities_id];
        }

        // Candidatas: a própria entidade + todas as ancestrais.
        $ancestors  = array_map('intval', array_values(getAncestorsOf('glpi_entities', $entities_id)));
        $candidates = array_values(array_unique(array_merge([$entities_id], $ancestors)));

        // A mais específica ganha. "Específica" = maior `level` na árvore de
        // entidades — NÃO o maior id, que não tem relação com profundidade.
        // A config da própria entidade vale mesmo sem is_recursive; as das
        // ancestrais só valem se forem recursivas.
        $row = $DB->request([
            'SELECT'     => ['c.*'],
            'FROM'       => self::getTable() . ' AS c',
            'INNER JOIN' => [
                'glpi_entities AS e' => [
                    'ON' => ['c' => 'entities_id', 'e' => 'id'],
                ],
            ],
            'WHERE' => [
                'c.entities_id' => $candidates,
                'c.is_active'   => 1,
                'OR' => [
                    ['c.entities_id'  => $entities_id],
                    ['c.is_recursive' => 1],
                ],
            ],
            'ORDER' => ['e.level DESC', 'c.id ASC'],
            'LIMIT' => 1,
        ])->current();

        self::$resolved[$entities_id] = $row ? self::normalize($row) : null;
        return self::$resolved[$entities_id];
    }

    /**
     * Normaliza a linha do banco: força coerência entre scale_type e
     * min/max, e converte target_statuses em array de inteiros.
     */
    public static function normalize(array $row): array {
        // Tolerante a linhas incompletas (ex.: getFromDB que falhou).
        $row += [
            'scale_type'               => 'csat5',
            'scale_min'                => 1,
            'scale_max'                => 5,
            'target_statuses'          => '6',
            'justify_threshold'        => 3,
            'csat_satisfied_threshold' => 4,
            'enforce_mode'             => 'reminder',
            'trigger_type'             => 'closed_count',
            'trigger_closed_count'     => 5,
            'trigger_required_answers' => 1,
            'pending_max_shown'        => 5,
        ];

        $bounds = self::getScaleBounds((string)$row['scale_type']);
        if (!empty($bounds)) {
            $row['scale_min'] = $bounds[0];
            $row['scale_max'] = $bounds[1];
        }
        $row['scale_min'] = (int)$row['scale_min'];
        $row['scale_max'] = (int)$row['scale_max'];
        if ($row['scale_max'] <= $row['scale_min']) {
            $row['scale_max'] = $row['scale_min'] + 1;
        }

        $statuses = array_filter(array_map('intval', explode(',', (string)$row['target_statuses'])));
        if (empty($statuses)) {
            $statuses = [Ticket::CLOSED];
        }
        $row['target_statuses_array'] = array_values($statuses);

        // justify_threshold precisa estar dentro da escala
        $row['justify_threshold'] = max($row['scale_min'], min((int)$row['justify_threshold'], $row['scale_max']));

        // Limiar de "satisfeito" para o cálculo de CSAT%
        $row['csat_satisfied_threshold'] = max($row['scale_min'], min((int)$row['csat_satisfied_threshold'], $row['scale_max']));

        return $row;
    }

    /** Limpa o cache de resolução (usado após salvar). */
    public static function flushCache() {
        self::$resolved = [];
    }

    // ------------------------------------------------------------------
    // Cálculo de indicadores
    // ------------------------------------------------------------------

    /**
     * Conta respostas satisfeitas x total. Devolve os dois números crus
     * para que a agregação de vários respondentes possa somar
     * satisfeitas / total — e não fazer média de percentuais, que dá peso
     * igual a pesquisas com quantidades diferentes de perguntas.
     *
     * @return array{satisfied:int, total:int, percent:float}
     */
    public static function countSatisfied(array $scores, array $config): array {
        $scores = array_values(array_filter($scores, static fn($s) => $s !== null && $s >= 0));
        $total  = count($scores);

        if ($total === 0) {
            return ['satisfied' => 0, 'total' => 0, 'percent' => 0.0];
        }

        $threshold = (int)$config['csat_satisfied_threshold'];
        $satisfied = count(array_filter($scores, static fn($s) => $s >= $threshold));

        return [
            'satisfied' => $satisfied,
            'total'     => $total,
            'percent'   => round(($satisfied / $total) * 100, 2),
        ];
    }

    /**
     * CSAT% de uma única pesquisa.
     * Referência de mercado: 70-85% é bom, acima de 85% é excepcional.
     */
    public static function computeCsatPercent(array $scores, array $config): float {
        return self::countSatisfied($scores, $config)['percent'];
    }

    /**
     * Média simples ou ponderada. Recebe pares [nota, peso] para que o
     * alinhamento nota/peso não possa se perder na filtragem.
     *
     * @param array $pairs lista de [int $score, float $weight]
     */
    public static function computeWeightedAvg(array $pairs): float {
        $sum     = 0.0;
        $weights = 0.0;
        $count   = 0;
        $plain   = 0;

        foreach ($pairs as $pair) {
            $score  = $pair[0] ?? null;
            $weight = (float)($pair[1] ?? 1);

            if ($score === null || $score < 0) {
                continue;
            }

            $count++;
            $plain += $score;

            if ($weight > 0) {
                $sum     += $score * $weight;
                $weights += $weight;
            }
        }

        if ($count === 0) {
            return 0.0;
        }

        // Se todos os pesos forem zero, cai para a média simples.
        if ($weights <= 0) {
            return round($plain / $count, 2);
        }

        return round($sum / $weights, 2);
    }

    /** Classificação NPS: 0-6 detrator, 7-8 neutro, 9-10 promotor. */
    public static function classifyNps(int $score): string {
        if ($score < 0)  return 'none';
        if ($score <= 6) return 'detractor';
        if ($score <= 8) return 'passive';
        return 'promoter';
    }

    // ------------------------------------------------------------------
    // Persistência
    // ------------------------------------------------------------------

    public function prepareInputForAdd($input) {
        return $this->prepareInput($input, true);
    }

    public function prepareInputForUpdate($input) {
        return $this->prepareInput($input, false);
    }

    private function prepareInput($input, bool $is_add) {
        // target_statuses vem do form como array de checkboxes
        if (isset($input['target_statuses']) && is_array($input['target_statuses'])) {
            $vals = array_filter(array_map('intval', $input['target_statuses']));
            $input['target_statuses'] = implode(',', $vals ?: [Ticket::CLOSED]);
        }

        // O dropdown de presets é só um auxiliar de UI
        unset($input['_icon_preset']);

        // Normaliza a sequência de ícones: espaço simples entre eles.
        if (isset($input['icon_char'])) {
            $seq = self::parseIconSequence((string)$input['icon_char']);
            if (!empty($seq)) {
                $input['icon_char'] = implode(' ', $seq);

                // Avisa (sem bloquear) se a sequência não casa com a escala:
                // nesse caso o ícone volta a ser repetido.
                $min = (int)($input['scale_min'] ?? $this->fields['scale_min'] ?? 1);
                $max = (int)($input['scale_max'] ?? $this->fields['scale_max'] ?? 5);
                $bounds = self::getScaleBounds((string)($input['scale_type'] ?? $this->fields['scale_type'] ?? 'csat5'));
                if (!empty($bounds)) {
                    [$min, $max] = $bounds;
                }

                $points = $max - $min + 1;
                if (count($seq) !== $points) {
                    Session::addMessageAfterRedirect(
                        sprintf(
                            __('A sequência tem %1$d ícones e a escala tem %2$d pontos. Enquanto não coincidirem, o primeiro ícone será repetido.', 'svm'),
                            count($seq),
                            $points
                        ),
                        false,
                        WARNING
                    );
                }
            }
        }

        // Coerência da escala
        if (isset($input['scale_type'])) {
            $bounds = self::getScaleBounds((string)$input['scale_type']);
            if (!empty($bounds)) {
                $input['scale_min'] = $bounds[0];
                $input['scale_max'] = $bounds[1];
            }
        }
        if (isset($input['scale_min'], $input['scale_max'])
            && (int)$input['scale_max'] <= (int)$input['scale_min']) {
            Session::addMessageAfterRedirect(
                __('O valor máximo da escala deve ser maior que o mínimo.', 'svm'), false, ERROR
            );
            return false;
        }

        // Uma escala com muitos pontos prejudica a taxa de resposta
        if (isset($input['scale_max'], $input['scale_min'])
            && ((int)$input['scale_max'] - (int)$input['scale_min']) > 10) {
            Session::addMessageAfterRedirect(
                __('Escalas com mais de 11 pontos reduzem a taxa de resposta. Use no máximo 0-10.', 'svm'),
                false, ERROR
            );
            return false;
        }

        // Evita duas configs ativas para a mesma entidade.
        // Em updates parciais (ex.: ação massiva ligando is_active) os campos
        // ausentes vêm da linha já gravada.
        $entity  = $input['entities_id'] ?? ($this->fields['entities_id'] ?? null);
        $active  = $input['is_active']   ?? ($this->fields['is_active']   ?? 1);

        if ($entity !== null && (int)$active === 1) {
            global $DB;
            $where = ['entities_id' => (int)$entity, 'is_active' => 1];
            $self_id = (int)($input['id'] ?? $this->fields['id'] ?? 0);
            if (!$is_add && $self_id > 0) {
                $where[] = ['NOT' => ['id' => $self_id]];
            }
            $dup = $DB->request(['COUNT' => 'cpt', 'FROM' => self::getTable(), 'WHERE' => $where])->current();
            if ((int)($dup['cpt'] ?? 0) > 0) {
                Session::addMessageAfterRedirect(
                    __('Já existe uma configuração ativa para esta entidade.', 'svm'), false, ERROR
                );
                return false;
            }
        }

        if ($is_add) {
            $input['date_creation'] = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        }
        $input['date_mod'] = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');

        // Por último: só aqui há efeito colateral em disco (gravar e apagar
        // arquivos). Se rodasse antes das validações acima, um formulário
        // rejeitado deixaria imagem órfã no servidor — ou, pior, já teria
        // apagado a imagem atual sem limpar a referência no banco.
        $input = $this->handleIconUploads($input);
        if ($input === false) {
            return false;
        }

        self::flushCache();
        return $input;
    }

    /**
     * Trata os campos de upload e remoção de ícone.
     *
     * Campos do formulário:
     *  _icon_upload / _icon_empty_upload  → arquivo em $_FILES
     *  _icon_remove / _icon_empty_remove  → checkbox de remoção
     */
    private function handleIconUploads($input) {
        $slots = [
            'icon_image_file'       => ['_icon_upload',       '_icon_remove'],
            'icon_image_empty_file' => ['_icon_empty_upload', '_icon_empty_remove'],
        ];

        foreach ($slots as $column => $fields) {
            [$upload_field, $remove_field] = $fields;

            $current = (string)($this->fields[$column] ?? '');

            // Remoção explícita
            if (!empty($input[$remove_field])) {
                self::deleteIconFile($current);
                $input[$column] = '';
                $current = '';
            }

            $file = $_FILES[$upload_field] ?? null;
            if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $error = null;
                $name  = self::storeUploadedIcon($file, $error);

                if ($name === null) {
                    Session::addMessageAfterRedirect(
                        $error ?? __('Falha ao enviar a imagem.', 'svm'),
                        false,
                        ERROR
                    );
                    return false;
                }

                // Substituiu: o arquivo anterior não é mais referenciado.
                if ($current !== '' && $current !== $name) {
                    self::deleteIconFile($current);
                }

                $input[$column] = $name;

                // Não sobrescreve a escolha explícita do usuário: o JS já
                // ajusta o seletor para "image" ao anexar o arquivo.
                if (!isset($input['icon_type'])) {
                    $input['icon_type'] = 'image';
                }
            }

            unset($input[$upload_field], $input[$remove_field]);
        }

        return $input;
    }

    /** Remove os arquivos ao excluir definitivamente a configuração. */
    public function post_purgeItem() {
        self::deleteIconFile((string)($this->fields['icon_image_file'] ?? ''));
        self::deleteIconFile((string)($this->fields['icon_image_empty_file'] ?? ''));
        self::flushCache();
        parent::post_purgeItem();
    }

    // ------------------------------------------------------------------
    // Formulário
    // ------------------------------------------------------------------

    public function showForm($ID, array $options = []) {
        if (!self::canView()) {
            return false;
        }

        $this->initForm($ID, $options);

        // enctype explícito: sem ele o $_FILES chega vazio. Não depende de
        // o showFormHeader do core já emiti-lo.
        if (empty($options['formoptions'])) {
            $options['formoptions'] = 'enctype="multipart/form-data"';
        } elseif (strpos($options['formoptions'], 'enctype') === false) {
            $options['formoptions'] .= ' enctype="multipart/form-data"';
        }

        $this->showFormHeader($options);

        // ============ Identificação ============
        echo "<tr class='tab_bg_1'><th colspan='4'>" . __('Identificação', 'svm') . "</th></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Nome') . "</td><td>";
        echo Html::input('name', ['value' => $this->fields['name'], 'size' => 40]);
        echo "</td>";
        echo "<td>" . __('Ativa') . "</td><td>";
        Dropdown::showYesNo('is_active', $this->fields['is_active']);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Entidade') . "</td><td>";
        Entity::dropdown(['name' => 'entities_id', 'value' => $this->fields['entities_id']]);
        echo "</td>";
        echo "<td>" . __('Aplicar às subentidades', 'svm') . "</td><td>";
        Dropdown::showYesNo('is_recursive', $this->fields['is_recursive']);
        echo "</td></tr>";

        // ============ Métrica ============
        echo "<tr class='tab_bg_1'><th colspan='4'>" . __('Métrica e escala', 'svm') . "</th></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Tipo de escala', 'svm') . "</td><td>";
        Dropdown::showFromArray('scale_type', self::getScaleTypes(), [
            'value' => $this->fields['scale_type'],
        ]);
        echo "<br><span class='svm-hint'>" .
             __('CSAT 1-5 é o padrão de mercado para avaliar um atendimento pontual.', 'svm') .
             "</span></td>";
        echo "<td>" . __('Nota mínima considerada satisfeita (CSAT%)', 'svm') . "</td><td>";
        Dropdown::showNumber('csat_satisfied_threshold', [
            'value' => $this->fields['csat_satisfied_threshold'],
            'min'   => 0,
            'max'   => 10,
        ]);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Mínimo (escala personalizada)', 'svm') . "</td><td>";
        Dropdown::showNumber('scale_min', ['value' => $this->fields['scale_min'], 'min' => 0, 'max' => 9]);
        echo "</td>";
        echo "<td>" . __('Máximo (escala personalizada)', 'svm') . "</td><td>";
        Dropdown::showNumber('scale_max', ['value' => $this->fields['scale_max'], 'min' => 1, 'max' => 10]);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Rótulo da nota mínima', 'svm') . "</td><td>";
        echo Html::input('scale_label_min', ['value' => $this->fields['scale_label_min'], 'size' => 30]);
        echo "</td>";
        echo "<td>" . __('Rótulo da nota máxima', 'svm') . "</td><td>";
        echo Html::input('scale_label_max', ['value' => $this->fields['scale_label_max'], 'size' => 30]);
        echo "</td></tr>";

        // ============ Aparência ============
        echo "<tr class='tab_bg_1'><th colspan='4'>" . __('Aparência do widget de nota', 'svm') . "</th></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Tipo de ícone', 'svm') . "</td><td>";
        Dropdown::showFromArray('icon_type', self::getIconTypes(), ['value' => $this->fields['icon_type']]);
        echo "</td>";
        echo "<td>" . __('Modo de preenchimento', 'svm') . "</td><td>";
        Dropdown::showFromArray('icon_render_mode', self::getIconRenderModes(), [
            'value' => $this->fields['icon_render_mode'],
        ]);
        echo "</td></tr>";

        // ---- Dropdown de presets ----
        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Escolher um conjunto pronto', 'svm') . "</td>";
        echo "<td colspan='3'>";
        $this->showIconPresetDropdown();
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Ícone / emoji ativo', 'svm') . "</td><td>";
        echo Html::input('icon_char', [
            'value' => $this->fields['icon_char'],
            'size'  => 26,
            'id'    => 'svm-icon-char',
        ]);
        echo "<br><span class='svm-hint'>"
             . __('Um ícone (repetido) ou vários separados por espaço (um por nota).', 'svm')
             . "</span>";
        echo "</td>";
        echo "<td>" . __('Ícone / emoji inativo', 'svm') . "</td><td>";
        echo Html::input('icon_empty_char', [
            'value' => $this->fields['icon_empty_char'],
            'size'  => 26,
            'id'    => 'svm-icon-empty-char',
        ]);
        echo "<br><span class='svm-hint'>"
             . __('Opcional. Vazio = mesmo ícone esmaecido.', 'svm')
             . "</span>";
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'><td colspan='4'>";
        echo "<span id='svm-preset-warning' class='svm-hint svm-warn' style='display:none'></span>";
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Imagem do ícone ativo', 'svm') . "</td><td>";
        $this->showIconUploadField('icon_image_file', 'icon_image_url', '_icon_upload', '_icon_remove');
        echo "</td>";
        echo "<td>" . __('Imagem do ícone inativo', 'svm') . "</td><td>";
        $this->showIconUploadField(
            'icon_image_empty_file', 'icon_image_empty_url', '_icon_empty_upload', '_icon_empty_remove'
        );
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'><td colspan='4'>";
        echo "<b>" . __('Pré-visualização', 'svm') . ":</b> ";
        echo "<span id='svm-config-preview' class='svm-config-preview'></span>";
        echo "</td></tr>";

        // ============ Justificativa ============
        echo "<tr class='tab_bg_1'><th colspan='4'>" . __('Justificativa obrigatória', 'svm') . "</th></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Exigir justificativa em notas até', 'svm') . "</td><td>";
        Dropdown::showNumber('justify_threshold', [
            'value' => $this->fields['justify_threshold'], 'min' => 0, 'max' => 10,
        ]);
        echo "<br><span class='svm-hint'>" .
             __('Em CSAT 1-5, notas 1-3 são as que exigem ação. Em NPS, 0-6 são detratores.', 'svm') .
             "</span></td>";
        echo "<td>" . __('Tamanho mínimo da justificativa (caracteres)', 'svm') . "</td><td>";
        Dropdown::showNumber('justify_min_length', [
            'value' => $this->fields['justify_min_length'], 'min' => 0, 'max' => 500, 'step' => 5,
        ]);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Mensagem exibida ao pedir justificativa', 'svm') . "</td>";
        echo "<td colspan='3'>";
        echo Html::input('justify_message', ['value' => $this->fields['justify_message'], 'size' => 90]);
        echo "</td></tr>";

        // ============ NPS ============
        echo "<tr class='tab_bg_1'><th colspan='4'>" . __('NPS (lealdade)', 'svm') . "</th></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Perguntar NPS', 'svm') . "</td><td>";
        Dropdown::showYesNo('nps_enabled', $this->fields['nps_enabled']);
        echo "</td>";
        echo "<td>" . __('Intervalo mínimo entre perguntas de NPS (dias)', 'svm') . "</td><td>";
        Dropdown::showNumber('nps_interval_days', [
            'value' => $this->fields['nps_interval_days'], 'min' => 0, 'max' => 365, 'step' => 15,
        ]);
        echo "<br><span class='svm-hint'>" .
             __('O NPS mede lealdade, não o atendimento isolado. Trimestral (90 dias) evita fadiga.', 'svm') .
             "</span></td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Enunciado da pergunta de NPS', 'svm') . "</td>";
        echo "<td colspan='3'>";
        echo Html::input('nps_question', ['value' => $this->fields['nps_question'], 'size' => 90]);
        echo "</td></tr>";

        // ============ Gatilho e obrigatoriedade ============
        echo "<tr class='tab_bg_1'><th colspan='4'>" . __('Gatilho e obrigatoriedade', 'svm') . "</th></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Nível de obrigatoriedade', 'svm') . "</td><td>";
        Dropdown::showFromArray('enforce_mode', self::getEnforceModes(), [
            'value' => $this->fields['enforce_mode'],
        ]);
        echo "</td>";
        echo "<td>" . __('Tipo de gatilho', 'svm') . "</td><td>";
        Dropdown::showFromArray('trigger_type', self::getTriggerTypes(), [
            'value' => $this->fields['trigger_type'],
        ]);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Disparar a cada N chamados encerrados', 'svm') . "</td><td>";
        Dropdown::showNumber('trigger_closed_count', [
            'value' => $this->fields['trigger_closed_count'], 'min' => 1, 'max' => 50,
        ]);
        echo "</td>";
        echo "<td>" . __('Exigir N respostas ao disparar', 'svm') . "</td><td>";
        Dropdown::showNumber('trigger_required_answers', [
            'value' => $this->fields['trigger_required_answers'], 'min' => 1, 'max' => 20,
        ]);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Máx. de pesquisas pendentes exibidas', 'svm') . "</td><td>";
        Dropdown::showNumber('pending_max_shown', [
            'value' => $this->fields['pending_max_shown'], 'min' => 1, 'max' => 20,
        ]);
        echo "</td>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Carência após o encerramento (horas)', 'svm') . "</td><td>";
        Dropdown::showNumber('pending_grace_hours', [
            'value' => $this->fields['pending_grace_hours'], 'min' => 0, 'max' => 168,
        ]);
        echo "<br><span class='svm-hint'>" .
             __('0 = pede imediatamente. A referência de CSAT é pedir em até 24h do encerramento.', 'svm') .
             "</span></td>";
        echo "<td>" . __('Expirar pesquisa após (dias)', 'svm') . "</td><td>";
        Dropdown::showNumber('survey_expire_days', [
            'value' => $this->fields['survey_expire_days'], 'min' => 0, 'max' => 365,
        ]);
        echo "<br><span class='svm-hint'>" . __('0 = nunca expira.', 'svm') . "</span></td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Status de chamado pesquisáveis', 'svm') . "</td><td>";
        $current = array_filter(array_map('intval', explode(',', (string)$this->fields['target_statuses'])));
        foreach (self::getTargetStatusOptions() as $sid => $slabel) {
            echo "<label class='svm-inline-check'>";
            echo Html::getCheckbox([
                'name'    => 'target_statuses[]',
                'value'   => $sid,
                'checked' => in_array($sid, $current, true),
            ]);
            echo " " . $slabel . "</label> ";
        }
        echo "</td>";
        echo "<td>" . __('Permitir adiar a resposta', 'svm') . "</td><td>";
        Dropdown::showYesNo('allow_skip', $this->fields['allow_skip']);
        echo " " . __('máx.', 'svm') . " ";
        Dropdown::showNumber('skip_max_count', [
            'value' => $this->fields['skip_max_count'], 'min' => 1, 'max' => 10,
        ]);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Respostas anônimas para o técnico', 'svm') . "</td><td>";
        Dropdown::showYesNo('is_anonymous', $this->fields['is_anonymous']);
        echo "<br><span class='svm-hint'>" .
             __('Anonimato aumenta a sinceridade, mas impede o follow-up individual com detratores.', 'svm') .
             "</span></td>";
        echo "<td>" . __('Exibir prévia do chamado no modal', 'svm') . "</td><td>";
        Dropdown::showYesNo('show_ticket_preview', $this->fields['show_ticket_preview']);
        echo "</td></tr>";

        // ============ Textos ============
        echo "<tr class='tab_bg_1'><th colspan='4'>" . __('Textos da interface', 'svm') . "</th></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Título do modal', 'svm') . "</td><td colspan='3'>";
        echo Html::input('header_title', ['value' => $this->fields['header_title'], 'size' => 90]);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Subtítulo do modal', 'svm') . "</td><td colspan='3'>";
        echo Html::input('header_subtitle', ['value' => $this->fields['header_subtitle'], 'size' => 90]);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Título do agradecimento', 'svm') . "</td><td colspan='3'>";
        echo Html::input('thanks_title', ['value' => $this->fields['thanks_title'], 'size' => 90]);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Mensagem de agradecimento', 'svm') . "</td><td colspan='3'>";
        echo "<textarea name='thanks_message' rows='3' style='width:98%'>"
             . htmlspecialchars((string)$this->fields['thanks_message'], ENT_QUOTES, 'UTF-8')
             . "</textarea>";
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Rodapé do modal', 'svm') . "</td><td colspan='3'>";
        echo Html::input('footer_note', ['value' => $this->fields['footer_note'], 'size' => 90]);
        echo "</td></tr>";

        $this->showFormButtons($options);

        // Só as imagens ENVIADAS entram aqui. O campo de URL é lido ao vivo
        // pelo JS, para que digitar a URL atualize a prévia na hora.
        $saved_icons = ['full' => '', 'empty' => ''];
        foreach ([
            'full'  => 'icon_image_file',
            'empty' => 'icon_image_empty_file',
        ] as $slot => $column) {
            $name = self::sanitizeIconName((string)($this->fields[$column] ?? ''));
            if ($name !== null) {
                $saved_icons[$slot] = Plugin::getWebDir('svm')
                    . '/front/icon.send.php?f=' . rawurlencode($name);
            }
        }

        $saved_json = json_encode($saved_icons, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $max_bytes = self::ICON_MAX_BYTES;

        $js = <<<JS
        (function() {
            var saved   = {$saved_json};
            var picked  = { full: null, empty: null };

            // Escapa também as aspas: os valores abaixo vão para dentro de
            // atributos HTML, e o campo de URL aceita texto livre.
            function esc(s) {
                return \$("<div>").text(s == null ? "" : String(s)).html()
                    .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
            }

            // Garante multipart mesmo que o showFormHeader do GLPI não o
            // defina — sem isso o \$_FILES chegaria vazio.
            \$("input.svm-icon-file").closest("form").attr("enctype", "multipart/form-data");

            function currentImage(slot) {
                var removeField = (slot === "full") ? "_icon_remove" : "_icon_empty_remove";
                if (\$("input[name='" + removeField + "']").is(":checked")) {
                    return picked[slot] || "";
                }
                if (picked[slot]) { return picked[slot]; }
                if (saved[slot])  { return saved[slot]; }
                var urlField = (slot === "full") ? "icon_image_url" : "icon_image_empty_url";
                return \$("input[name='" + urlField + "']").val() || "";
            }

            /** Divide o campo em tokens; 2+ tokens = um ícone por nota. */
            function sequence(value) {
                var parts = String(value || "").trim().split(/[\s,]+/).filter(Boolean);
                return parts.length > 1 ? parts : [];
            }

            function render() {
                var type  = \$("select[name='icon_type']").val();
                var raw   = \$("input[name='icon_char']").val();
                var full  = raw || "★";
                var empty = \$("input[name='icon_empty_char']").val();
                var imgF  = currentImage("full");
                var imgE  = currentImage("empty");
                var min   = parseInt(\$("select[name='scale_min']").val() || 1, 10);
                var max   = parseInt(\$("select[name='scale_max']").val() || 5, 10);
                var out   = "";

                var seq    = sequence(raw);
                var points = max - min + 1;
                var useSeq = (type === "emoji" || type === "fontawesome")
                             && seq.length === points;

                // Aviso quando a sequência não casa com a escala
                if (seq.length > 1 && seq.length !== points) {
                    \$("#svm-preset-warning").text(
                        "A sequência tem " + seq.length + " ícones, mas a escala tem " +
                        points + " pontos. Ajuste a escala ou o conjunto para que o " +
                        "ícone por nota funcione."
                    ).show();
                } else {
                    \$("#svm-preset-warning").hide();
                }

                for (var i = min; i <= max; i++) {
                    var on  = (i <= Math.ceil((min + max) / 2));
                    var idx = i - min;

                    if (type === "image") {
                        var src = on ? imgF : (imgE || imgF);
                        if (src) {
                            out += "<img src='" + esc(src) +
                                   "' style='height:26px;margin:0 3px;" +
                                   (on ? "" : "filter:grayscale(1);opacity:.4;") + "'>";
                        } else {
                            out += "<span style='color:#94a3b8;font-size:12px'>(sem imagem)</span>";
                        }
                    } else if (type === "fontawesome") {
                        var fa = useSeq ? seq[idx] : (on ? full : (empty || full));
                        out += "<i class='fas " + esc(fa) +
                               "' style='font-size:22px;margin:0 4px;" +
                               (useSeq || on ? "color:#ea580c;" : "opacity:.3;") + "'></i>";
                    } else if (type === "number") {
                        out += "<span style='display:inline-block;min-width:30px;padding:4px 8px;margin:0 2px;" +
                               "border-radius:6px;border:1px solid #cbd5e1;" +
                               (on ? "background:#ea580c;color:#fff;" : "background:#f8fafc;color:#64748b;") +
                               "'>" + i + "</span>";
                    } else {
                        // Sequência: cada nota tem o seu ícone, todos visíveis.
                        var ch = useSeq ? seq[idx] : (on ? full : (empty || full));
                        var dim = (!useSeq && !on) ? "filter:grayscale(1);opacity:.35;" : "";
                        out += "<span style='font-size:24px;margin:0 3px;" + dim + "'>" +
                               esc(ch) + "</span>";
                    }
                }
                \$("#svm-config-preview").html(out);
            }

            // ---- Aplica um conjunto pronto ----
            \$(document).on("change", "#svm-icon-preset", function() {
                var opt = this.options[this.selectedIndex];
                if (!opt || !opt.value) { return; }

                var type   = \$(opt).data("type");
                var ch     = String(\$(opt).attr("data-char") || "");
                var em     = String(\$(opt).attr("data-empty") || "");
                var points = parseInt(\$(opt).data("points"), 10) || 0;

                \$("#svm-icon-char").val(ch);
                \$("#svm-icon-empty-char").val(em);

                // trigger("change") é necessário: os selects do GLPI usam
                // select2, que não repinta só com .val().
                \$("select[name='icon_type']").val(type).trigger("change");

                // Sequência de carinhas fica melhor com cada nota acesa
                // individualmente; ícone repetido combina com cumulativo.
                \$("select[name='icon_render_mode']")
                    .val(points > 0 ? "single" : "cumulative").trigger("change");

                // Ajusta a escala para casar com a sequência escolhida
                if (points > 0) {
                    var min = parseInt(\$("select[name='scale_min']").val() || 1, 10);
                    var target = min + points - 1;
                    if (\$("select[name='scale_max'] option[value='" + target + "']").length) {
                        \$("select[name='scale_type']").val("custom").trigger("change");
                        \$("select[name='scale_max']").val(target).trigger("change");
                    }
                }

                render();
            });

            // Prévia local do arquivo escolhido, antes de salvar
            \$(document).on("change", "input.svm-icon-file", function() {
                var slot = (this.name === "_icon_upload") ? "full" : "empty";
                var file = this.files && this.files[0];

                if (!file) { picked[slot] = null; render(); return; }

                if (file.size > {$max_bytes}) {
                    alert("A imagem deve ter no máximo " + Math.round({$max_bytes} / 1024) + " KB.");
                    \$(this).val("");
                    picked[slot] = null;
                    render();
                    return;
                }

                var reader = new FileReader();
                reader.onload = function(e) {
                    picked[slot] = e.target.result;
                    \$("select[name='icon_type']").val("image").trigger("change");
                    render();
                };
                reader.readAsDataURL(file);
            });

            \$(document).on("change keyup",
                "select[name='icon_type'], input[name='icon_char'], input[name='icon_empty_char'], " +
                "input[name='icon_image_url'], input[name='icon_image_empty_url'], " +
                "input[name='_icon_remove'], input[name='_icon_empty_remove'], " +
                "select[name='scale_min'], select[name='scale_max'], select[name='scale_type']",
                render);

            render();
        })();
JS;
        echo Html::scriptBlock($js);

        return true;
    }

    /**
     * Dropdown de conjuntos prontos. Não é coluna do banco: apenas
     * preenche os campos de ícone via JS.
     */
    private function showIconPresetDropdown() {
        // Normaliza para comparar com os presets, cujos chars têm espaço simples
        $current_char = (string)($this->fields['icon_char'] ?? '');
        $current_seq  = self::parseIconSequence($current_char);
        $current_char = !empty($current_seq)
            ? implode(' ', $current_seq)
            : trim($current_char);

        echo "<select id='svm-icon-preset' class='form-select' style='max-width:420px'>";
        echo "<option value=''>" . __('— selecione para preencher os campos abaixo —', 'svm') . "</option>";

        foreach (self::getIconPresets() as $group => $presets) {
            echo "<optgroup label='" . htmlspecialchars($group, ENT_QUOTES, 'UTF-8') . "'>";

            foreach ($presets as $key => $preset) {
                $selected = ($preset['char'] !== '' && $preset['char'] === $current_char)
                    ? " selected" : '';

                echo "<option value='" . htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8') . "'"
                     . " data-type='" . htmlspecialchars($preset['type'], ENT_QUOTES, 'UTF-8') . "'"
                     . " data-char='" . htmlspecialchars($preset['char'], ENT_QUOTES, 'UTF-8') . "'"
                     . " data-empty='" . htmlspecialchars($preset['empty'], ENT_QUOTES, 'UTF-8') . "'"
                     . " data-points='" . (int)($preset['points'] ?? 0) . "'"
                     . $selected . ">"
                     . htmlspecialchars($preset['label'], ENT_QUOTES, 'UTF-8')
                     . "</option>";
            }

            echo "</optgroup>";
        }

        echo "</select>";

        echo "<div class='svm-hint'>"
             . __('Os conjuntos de carinhas usam um ícone por nota e ficam melhores no modo "Único".', 'svm')
             . "</div>";
    }

    /**
     * Bloco de imagem: prévia do que já está salvo, upload e remoção,
     * mais o campo de URL como alternativa para imagem já hospedada.
     */
    private function showIconUploadField(
        string $file_column,
        string $url_column,
        string $upload_field,
        string $remove_field
    ) {
        $stored = self::sanitizeIconName((string)($this->fields[$file_column] ?? ''));
        $url    = (string)($this->fields[$url_column] ?? '');

        if ($stored !== null) {
            $src = Plugin::getWebDir('svm') . '/front/icon.send.php?f=' . rawurlencode($stored);
            echo "<div class='svm-icon-current'>";
            echo "<img src='" . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . "' alt=''>";
            echo "<label class='svm-inline-check'>";
            echo Html::getCheckbox(['name' => $remove_field, 'value' => 1]);
            echo " " . __('Remover esta imagem', 'svm');
            echo "</label>";
            echo "</div>";
        }

        echo "<input type='file' name='" . $upload_field
             . "' accept='image/png,image/jpeg,image/gif,image/webp' class='svm-icon-file'>";

        echo "<div class='svm-hint'>" . sprintf(
            __('PNG, JPG, GIF ou WEBP, até %s KB. A imagem é convertida para PNG e reduzida para %spx.', 'svm'),
            (int)(self::ICON_MAX_BYTES / 1024),
            self::ICON_MAX_SIDE
        ) . "</div>";

        echo "<div class='svm-hint'>" . __('Ou informe uma URL:', 'svm') . " ";
        echo Html::input($url_column, ['value' => $url, 'size' => 28]);
        echo "</div>";

        if ($stored !== null && $url !== '') {
            echo "<div class='svm-hint svm-warn'>"
                 . __('A imagem enviada tem prioridade sobre a URL.', 'svm')
                 . "</div>";
        }
    }

    // ------------------------------------------------------------------
    // Abas
    // ------------------------------------------------------------------

    public function defineTabs($options = []) {
        $tabs = [];
        $this->addDefaultFormTab($tabs);
        $this->addStandardTab('PluginSvmQuestion', $tabs, $options);
        $this->addStandardTab('Log', $tabs, $options);
        return $tabs;
    }

    // ------------------------------------------------------------------
    // Busca
    // ------------------------------------------------------------------

    public function rawSearchOptions() {
        $opts = parent::rawSearchOptions();

        // A opção 1 é a coluna clicável / ordenação padrão do Search.
        $opts[] = ['id' => 1, 'table' => self::getTable(), 'field' => 'name',
                   'name' => __('Nome'), 'datatype' => 'itemlink', 'massiveaction' => false];
        $opts[] = ['id' => 2, 'table' => self::getTable(), 'field' => 'id',
                   'name' => __('ID'), 'datatype' => 'number', 'massiveaction' => false];
        $opts[] = ['id' => 3, 'table' => 'glpi_entities', 'field' => 'completename',
                   'name' => __('Entidade'), 'datatype' => 'dropdown'];
        $opts[] = ['id' => 4, 'table' => self::getTable(), 'field' => 'scale_type',
                   'name' => __('Tipo de escala', 'svm'), 'datatype' => 'string'];
        $opts[] = ['id' => 5, 'table' => self::getTable(), 'field' => 'enforce_mode',
                   'name' => __('Obrigatoriedade', 'svm'), 'datatype' => 'string'];
        $opts[] = ['id' => 6, 'table' => self::getTable(), 'field' => 'is_active',
                   'name' => __('Ativa'), 'datatype' => 'bool'];

        return $opts;
    }
}
