/**
 * Plugin SVM - Pesquisa de satisfação (motor de exibição)
 *
 * Tudo que antes estava fixo no código (escala 1-5, 3 perguntas, emoji de
 * laranja, limiar de justificativa 3) agora vem da configuração retornada
 * pelo endpoint check.
 */

/* global $, CFG_GLPI */

(function () {
    'use strict';

    var SVM = {
        path: null,
        cfg: null,
        questions: [],
        nps: null,
        token: null,
        mustLock: false,
        isLocked: false,
        successTimer: null
    };

    // ------------------------------------------------------------------
    // Utilidades
    // ------------------------------------------------------------------

    /**
     * Escapa para uso seguro tanto em conteúdo quanto em atributo.
     * .text().html() do jQuery NÃO escapa aspas, e o Sanitizer do GLPI
     * deixa a apóstrofe passar — sem isso, um valor de configuração como
     * `x' onerror='...` viraria execução de script.
     */
    function esc(str) {
        return $('<div>').text(str == null ? '' : String(str)).html()
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function truncate(str, len) {
        str = String(str == null ? '' : str);
        return str.length > len ? str.substring(0, len) + '…' : str;
    }

    /**
     * Renderiza um ponto da escala.
     *
     * @param value  nota deste ponto
     * @param cfg    configuração
     * @param seqChar ícone específico deste ponto, quando a configuração usa
     *                uma sequência (um ícone por nota). null = ícone repetido.
     */
    function renderScalePoint(value, cfg, seqChar) {
        var type = cfg.icon_type || 'emoji';
        var attrs = "class='svm-point" + (seqChar ? " svm-point-seq" : "") +
                    "' data-value='" + value + "' role='radio' " +
                    "tabindex='0' aria-label='" + value + "'";

        // Sequência: cada nota tem o seu próprio ícone, sempre visível.
        if (seqChar) {
            if (type === 'fontawesome') {
                return "<span " + attrs + ">" +
                       "<i class='fas " + esc(seqChar) + " svm-fa-seq'></i>" +
                       "</span>";
            }
            return "<span " + attrs + ">" +
                   "<span class='svm-emoji-seq'>" + esc(seqChar) + "</span>" +
                   "</span>";
        }

        if (type === 'image') {
            var on  = cfg.icon_image_url || '';
            var off = cfg.icon_image_empty_url || on;

            // src vazio resolveria para a própria URL da página: uma
            // requisição extra por ponto da escala e um ícone quebrado.
            // Sem imagem configurada, cai para números.
            if (on === '') {
                return "<span " + attrs + "><span class='svm-num'>" + value + "</span></span>";
            }

            return "<span " + attrs + ">" +
                   "<img class='svm-img-on'  src='" + esc(on)  + "' alt='" + value + "'>" +
                   "<img class='svm-img-off' src='" + esc(off) + "' alt='" + value + "'>" +
                   "</span>";
        }

        if (type === 'fontawesome') {
            var iconOn  = cfg.icon_char_single || 'fa-star';
            var iconOff = cfg.icon_empty_char_single || iconOn;
            return "<span " + attrs + ">" +
                   "<i class='fas " + esc(iconOn)  + " svm-fa-on'></i>" +
                   "<i class='fas " + esc(iconOff) + " svm-fa-off'></i>" +
                   "</span>";
        }

        if (type === 'number') {
            return "<span " + attrs + "><span class='svm-num'>" + value + "</span></span>";
        }

        // emoji / caractere
        var charOn  = cfg.icon_char_single || '⭐';
        var charOff = cfg.icon_empty_char_single || charOn;
        return "<span " + attrs + ">" +
               "<span class='svm-emoji-on'>"  + esc(charOn)  + "</span>" +
               "<span class='svm-emoji-off'>" + esc(charOff) + "</span>" +
               "</span>";
    }

    /**
     * Ícone da tela de agradecimento. Numa sequência, usa o último (o mais
     * positivo); com ícone único, usa ele mesmo. Font Awesome e "número"
     * não têm caractere exibível aqui, então cai no visto.
     */
    function thanksIcon(cfg) {
        if (cfg.icon_type === 'fontawesome' || cfg.icon_type === 'number'
            || cfg.icon_type === 'image') {
            return '✅';
        }
        if (cfg.icon_sequence && cfg.icon_sequence.length > 1) {
            return cfg.icon_sequence[cfg.icon_sequence.length - 1];
        }
        return cfg.icon_char_single || '✅';
    }

    /**
     * A configuração define um ícone por nota para esta faixa?
     * Só vale se a quantidade de ícones casar exatamente com os pontos.
     */
    function sequenceFor(cfg, min, max) {
        var seq = cfg.icon_sequence;
        if (!seq || !seq.length) {
            return null;
        }
        return (seq.length === (max - min + 1)) ? seq : null;
    }

    /** Linha de uma pergunta de escala. */
    function scaleRow(question, min, max, cfg) {
        var seq    = sequenceFor(cfg, min, max);
        var points = '';

        for (var i = min; i <= max; i++) {
            points += renderScalePoint(i, cfg, seq ? seq[i - min] : null);
        }

        var helper = question.helper_text
            ? "<span class='svm-hint'>" + esc(question.helper_text) + "</span>"
            : '';

        var required = question.is_mandatory
            ? "<span class='svm-required' title='Obrigatória'>*</span>"
            : '';

        return "<div class='svm-row' data-qid='" + question.id +
               "' data-type='" + esc(question.question_type) +
               "' data-min='" + min + "' data-max='" + max + "'>" +
               "<label>" + esc(question.name) + required + "</label>" + helper +
               // Numa sequência, acender os anteriores não faz sentido:
               // cada nota é um ícone distinto, não um nível de preenchimento.
               "<div class='svm-scale" + (seq ? " svm-scale-seq" : "") +
               "' role='radiogroup' data-mode='" +
               esc(seq ? 'single' : (cfg.icon_render_mode || 'cumulative')) + "'>" +
               "<input type='hidden' class='svm-score-input' value=''>" +
               points +
               "</div>" +
               "<div class='svm-scale-labels'>" +
               "<span>" + esc(cfg.scale_label_min) + "</span>" +
               "<span>" + esc(cfg.scale_label_max) + "</span>" +
               "</div>" +
               "</div>";
    }

    /** Linha de uma pergunta de texto livre. */
    function textRow(question) {
        var required = question.is_mandatory ? "<span class='svm-required'>*</span>" : '';
        return "<div class='svm-row svm-row-text' data-qid='" + question.id + "' data-type='text'>" +
               "<label>" + esc(question.name) + required + "</label>" +
               (question.helper_text ? "<span class='svm-hint'>" + esc(question.helper_text) + "</span>" : '') +
               "<textarea class='svm-text-input' rows='2'></textarea>" +
               "</div>";
    }

    /** Linha Sim/Não. */
    function boolRow(question) {
        var required = question.is_mandatory ? "<span class='svm-required'>*</span>" : '';
        return "<div class='svm-row' data-qid='" + question.id +
               "' data-type='bool' data-min='0' data-max='1'>" +
               "<label>" + esc(question.name) + required + "</label>" +
               "<div class='svm-scale svm-bool' role='radiogroup' data-mode='single'>" +
               "<input type='hidden' class='svm-score-input' value=''>" +
               "<span class='svm-point svm-bool-no'  data-value='0' role='radio' tabindex='0'>👎</span>" +
               "<span class='svm-point svm-bool-yes' data-value='1' role='radio' tabindex='0'>👍</span>" +
               "</div></div>";
    }

    function buildQuestionsHtml() {
        var cfg = SVM.cfg;
        var html = '';

        SVM.questions.forEach(function (q) {
            if (q.question_type === 'text') {
                html += textRow(q);
            } else if (q.question_type === 'bool') {
                html += boolRow(q);
            } else if (q.question_type === 'nps') {
                html += scaleRow(q, 0, 10, cfg);
            } else {
                html += scaleRow(q, cfg.scale_min, cfg.scale_max, cfg);
            }
        });

        // Pergunta de NPS separada (lealdade), só quando o intervalo permite
        if (SVM.nps) {
            var points = '';
            for (var i = 0; i <= 10; i++) {
                points += "<span class='svm-point svm-nps-point' data-value='" + i +
                          "' role='radio' tabindex='0'><span class='svm-num'>" + i + "</span></span>";
            }
            html += "<div class='svm-row svm-row-nps' data-type='nps-global'>" +
                    "<label>" + esc(SVM.nps.question) + "</label>" +
                    "<div class='svm-scale svm-nps' role='radiogroup' data-mode='single'>" +
                    "<input type='hidden' id='svm-nps-input' value=''>" + points +
                    "</div>" +
                    "<div class='svm-scale-labels'>" +
                    "<span>Não recomendaria</span><span>Recomendaria com certeza</span>" +
                    "</div></div>";
        }

        return html;
    }

    // ------------------------------------------------------------------
    // Modal
    // ------------------------------------------------------------------

    function renderModal(tickets) {
        if ($('#svm-full-overlay').length > 0) {
            return;
        }

        var cfg = SVM.cfg;
        // Só block_all trava a interface. block_new_ticket é aplicado no
        // servidor, na criação do chamado — aqui é apenas um aviso.
        var dismissible = cfg.enforce_mode !== 'block_all';

        var ticketOptions = '';
        tickets.forEach(function (t) {
            ticketOptions +=
                "<div class='svm-ticket-card' data-id='" + t.id + "'>" +
                "<div class='svm-ticket-info'><b>#" + t.id + "</b> - " +
                esc(truncate(t.name, 60)) + "</div>" +
                "<div class='svm-arrow-go'><i class='fas fa-chevron-right'></i></div>" +
                "</div>";
        });

        var closeBtn = dismissible
            ? "<button type='button' id='svm-dismiss' class='svm-dismiss-btn' " +
              "title='Responder depois'><i class='fas fa-times'></i></button>"
            : '';

        var skipBtn = cfg.allow_skip
            ? "<button type='button' id='svm-skip-btn' class='svm-btn-secondary'>Adiar este chamado</button>"
            : '';

        var previewBtn = cfg.show_ticket_preview
            ? "<button type='button' id='svm-toggle-preview' class='svm-icon-btn orange' " +
              "title='Ver detalhes do chamado'><i class='fas fa-eye'></i></button>"
            : "<span></span>";

        var previewPane = cfg.show_ticket_preview
            ? "<div id='svm-quick-view' style='display:none;'>" +
              "<iframe id='svm-preview-frame' src='' frameborder='0'></iframe></div>"
            : '';

        var modal =
        "<div id='svm-full-overlay' class='" + (dismissible ? 'svm-soft' : 'svm-hard') + "'>" +
          "<div class='svm-glass-container'>" +
            closeBtn +
            "<div class='svm-header'>" +
              "<h2>" + esc(cfg.header_title) + "</h2>" +
              "<p id='svm-step-desc'>" + esc(cfg.header_subtitle) + "</p>" +
            "</div>" +

            "<div id='svm-step-1' class='svm-step'>" +
              "<p class='svm-instr'>1. Escolha um dos chamados para avaliar:</p>" +
              "<div class='svm-selector'>" + ticketOptions + "</div>" +
            "</div>" +

            "<div id='svm-step-2' class='svm-step' style='display:none;'>" +
              "<div class='svm-navbar-mini'>" +
                "<button type='button' id='svm-back-btn' class='svm-icon-btn' title='Voltar para a lista'>" +
                  "<i class='fas fa-arrow-left'></i></button>" +
                "<div id='svm-id-display'></div>" +
                previewBtn +
              "</div>" +
              previewPane +
              "<div id='svm-justify-alert' style='display:none;'>" +
                "<div class='svm-justify-icon'>💬</div>" +
                "<p>" + esc(cfg.justify_message) + "</p>" +
              "</div>" +
              "<form id='svm-form'>" +
                "<input type='hidden' id='svm-ticket-id' value=''>" +
                "<div class='svm-questions'>" +
                  "<p class='svm-instr'>2. Como você avalia o atendimento?</p>" +
                  buildQuestionsHtml() +
                "</div>" +
                "<div class='svm-comment-block'>" +
                  "<p class='svm-instr'>3. Algum comentário ou sugestão?</p>" +
                  "<textarea id='svm-comment' placeholder='Sua percepção nos ajuda a evoluir...'></textarea>" +
                  "<div id='svm-comment-counter' class='svm-hint'></div>" +
                "</div>" +
                "<div id='svm-error' class='svm-error' style='display:none;'></div>" +
                "<div class='svm-actions'>" +
                  skipBtn +
                  "<button type='submit' id='svm-btn'>Enviar avaliação</button>" +
                "</div>" +
              "</form>" +
            "</div>" +

            "<div id='svm-step-3' class='svm-step svm-thanks' style='display:none;'>" +
              "<div class='svm-success-icon'>" +
                  esc(thanksIcon(cfg)) + "</div>" +
              "<h3>" + esc(cfg.thanks_title) + "</h3>" +
              "<p>" + esc(cfg.thanks_message) + "</p>" +
              "<div class='svm-loader-bar'></div>" +
              "<button type='button' id='svm-close-final' class='svm-btn-close-manual'>Fechar agora</button>" +
            "</div>" +

            "<p id='svm-footer' class='svm-footer-note'>" + esc(cfg.footer_note) + "</p>" +
          "</div>" +
        "</div>";

        $('body').append(modal);
        if (!dismissible) {
            $('body').addClass('svm-no-scroll');
            // Só arma a vigilância anti-remoção DEPOIS que o modal existe de
            // fato — armar antes poderia gerar recarregamento infinito.
            SVM.isLocked = SVM.mustLock;
        }

        bindEvents(dismissible);
    }

    // ------------------------------------------------------------------
    // Eventos
    // ------------------------------------------------------------------

    function bindEvents(dismissible) {
        var cfg = SVM.cfg;

        // Passo 1 -> 2
        $('#svm-full-overlay').on('click', '.svm-ticket-card', function () {
            var tid = $(this).data('id');
            $('#svm-ticket-id').val(tid);
            $('#svm-id-display').html('Chamado <b>#' + tid + '</b>');

            if (cfg.show_ticket_preview) {
                // O enforce.js se desativa sozinho dentro de iframe, então
                // não há risco de modal dentro de modal.
                $('#svm-preview-frame').attr(
                    'src',
                    CFG_GLPI.root_doc + '/front/ticket.form.php?id=' + encodeURIComponent(tid)
                );
            }

            $('#svm-step-1').fadeOut(180, function () {
                $('#svm-step-2').fadeIn(260);
            });
        });

        // Prévia do chamado
        $('#svm-full-overlay').on('click', '#svm-toggle-preview', function () {
            var visible = $('#svm-quick-view').is(':visible');
            $('#svm-quick-view').slideToggle(280);
            $(this).toggleClass('active')
                   .html(visible ? "<i class='fas fa-eye'></i>" : "<i class='fas fa-times'></i>");
        });

        // Voltar (reset)
        $('#svm-full-overlay').on('click', '#svm-back-btn', function () {
            $('#svm-step-2').fadeOut(180, function () {
                resetForm();
                $('#svm-step-1').fadeIn(260);
            });
        });

        // Seleção de nota (mouse e teclado)
        $('#svm-full-overlay').on('click', '.svm-point', function () {
            selectPoint($(this));
        });
        $('#svm-full-overlay').on('keydown', '.svm-point', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                selectPoint($(this));
            }
        });

        // Contador / limpeza do erro de justificativa
        $('#svm-full-overlay').on('input', '#svm-comment', function () {
            var len = $(this).val().trim().length;
            var min = parseInt(cfg.justify_min_length, 10) || 0;

            if (needsJustification() && min > 0 && len < min) {
                $('#svm-comment-counter').text(
                    'Faltam ' + (min - len) + ' caractere(s) para a justificativa.'
                ).addClass('svm-warn');
            } else {
                $('#svm-comment-counter').text('').removeClass('svm-warn');
            }

            if (len >= min) {
                $(this).removeClass('svm-attention');
            }
        });

        // Adiar
        $('#svm-full-overlay').on('click', '#svm-skip-btn', function () {
            var tid = parseInt($('#svm-ticket-id').val(), 10);
            if (!tid) { return; }

            var btn = $(this).prop('disabled', true);
            $.post(SVM.path, { action: 'skip', tickets_id: tid, svm_token: SVM.token })
                .done(function () { window.location.reload(); })
                .fail(function (xhr) {
                    btn.prop('disabled', false);
                    showError(readMessage(xhr, 'Não foi possível adiar.'));
                });
        });

        // Dispensar (modos off / reminder)
        if (dismissible) {
            $('#svm-full-overlay').on('click', '#svm-dismiss', function () {
                SVM.isLocked = false;
                $('#svm-full-overlay').remove();
                $('body').removeClass('svm-no-scroll');
            });
        }

        // Submissão
        $('#svm-full-overlay').on('submit', '#svm-form', function (e) {
            e.preventDefault();
            submitForm();
        });

        $('#svm-full-overlay').on('click', '#svm-close-final', function () {
            clearTimeout(SVM.successTimer);
            window.location.reload();
        });
    }

    function selectPoint($point) {
        var $scale = $point.closest('.svm-scale');
        var mode   = $scale.data('mode');
        var value  = parseInt($point.data('value'), 10);

        $scale.find('input').val(value);
        $scale.find('.svm-point').removeClass('active exact');
        $point.addClass('active exact');

        if (mode === 'cumulative') {
            $point.prevAll('.svm-point').addClass('active');
        }

        $point.closest('.svm-row').removeClass('svm-attention');
        refreshJustificationHint();
    }

    function resetForm() {
        $('.svm-score-input, #svm-nps-input').val('');
        $('.svm-point').removeClass('active exact');
        $('.svm-text-input').val('');
        $('#svm-comment').val('').removeClass('svm-attention');
        $('#svm-comment-counter').text('').removeClass('svm-warn');
        $('.svm-row').removeClass('svm-attention');
        $('#svm-justify-alert').hide();
        $('#svm-error').hide().text('');
        $('#svm-quick-view').hide();
        $('#svm-toggle-preview').removeClass('active').html("<i class='fas fa-eye'></i>");
    }

    /**
     * Alguma nota está na faixa que exige justificativa?
     * Espelha exatamente a regra do servidor: o limiar da config vale para
     * perguntas de escala; em NPS quem exige ação é o detrator (0 a 6).
     * Perguntas de texto e Sim/Não nunca exigem justificativa.
     */
    function needsJustification() {
        var threshold = parseInt(SVM.cfg.justify_threshold, 10);
        var needs = false;

        SVM.questions.forEach(function (q) {
            if (!q.require_comment_on_low) {
                return;
            }
            if (q.question_type !== 'scale' && q.question_type !== 'nps') {
                return;
            }

            var val = $(".svm-row[data-qid='" + q.id + "'] .svm-score-input").val();
            if (val === '' || val === undefined) {
                return;
            }

            var n = parseInt(val, 10);
            if (q.question_type === 'nps' ? n <= 6 : n <= threshold) {
                needs = true;
            }
        });

        return needs;
    }

    function refreshJustificationHint() {
        if (needsJustification()) {
            $('#svm-justify-alert').slideDown(200);
            $('#svm-comment').trigger('input');
        } else {
            $('#svm-justify-alert').slideUp(200);
            $('#svm-comment-counter').text('').removeClass('svm-warn');
        }
    }

    function showError(msg) {
        $('#svm-error').text(msg).slideDown(200);
    }

    function readMessage(xhr, fallback) {
        try {
            var body = xhr.responseJSON || JSON.parse(xhr.responseText);
            return body && body.message ? body.message : fallback;
        } catch (err) {
            return fallback;
        }
    }

    function submitForm() {
        var cfg     = SVM.cfg;
        var tid     = parseInt($('#svm-ticket-id').val(), 10);
        var comment = $('#svm-comment').val().trim();
        var answers = {};
        var missing = false;

        $('#svm-error').hide();
        $('.svm-row').removeClass('svm-attention');

        SVM.questions.forEach(function (q) {
            var $row = $(".svm-row[data-qid='" + q.id + "']");

            if (q.question_type === 'text') {
                var text = $row.find('.svm-text-input').val().trim();
                if (q.is_mandatory && text === '') {
                    missing = true;
                    $row.addClass('svm-attention');
                }
                answers[q.id] = text;
                return;
            }

            var val = $row.find('.svm-score-input').val();
            if (val === '' || val === undefined) {
                if (q.is_mandatory) {
                    missing = true;
                    $row.addClass('svm-attention');
                }
                answers[q.id] = '';
                return;
            }
            answers[q.id] = val;
        });

        if (missing) {
            showError('Responda todas as perguntas obrigatórias (marcadas com *).');
            var first = $('.svm-attention').get(0);
            if (first && first.scrollIntoView) {
                first.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            return;
        }

        // Justificativa obrigatória (validado também no servidor)
        if (needsJustification()) {
            var min = parseInt(cfg.justify_min_length, 10) || 0;
            if (comment.length < min) {
                $('#svm-justify-alert').slideDown(200);
                $('#svm-comment').addClass('svm-attention').focus();
                showError('Notas baixas exigem uma justificativa de pelo menos ' + min + ' caracteres.');
                return;
            }
        }

        var payload = {
            action: 'save',
            tickets_id: tid,
            comment: comment,
            answers: answers,
            svm_token: SVM.token
        };

        var npsVal = $('#svm-nps-input').val();
        if (npsVal !== '' && npsVal !== undefined) {
            payload.nps_score = npsVal;
        }

        var $btn = $('#svm-btn').prop('disabled', true).text('Enviando...');

        $.post(SVM.path, payload)
            .done(function (res) {
                if (res && res.success) {
                    SVM.isLocked = false;
                    $('#svm-step-2').fadeOut(260, function () {
                        $('.svm-header, #svm-step-desc, #svm-footer').hide();
                        $('#svm-step-3').fadeIn(340);
                        SVM.successTimer = setTimeout(function () {
                            window.location.reload();
                        }, 8000);
                    });
                } else {
                    $btn.prop('disabled', false).text('Enviar avaliação');
                    showError((res && res.message) || 'Não foi possível enviar.');
                }
            })
            .fail(function (xhr) {
                $btn.prop('disabled', false).text('Enviar avaliação');
                showError(readMessage(xhr, 'Erro ao enviar a avaliação.'));
            });
    }

    // ------------------------------------------------------------------
    // Bootstrap
    // ------------------------------------------------------------------

    function check() {
        // GET: leitura pura, e evita gastar tokens CSRF do GLPI a cada página.
        $.get(SVM.path, { action: 'check' })
            .done(function (data) {
                if (!data || !data.config || !data.show_prompt) {
                    return;
                }
                if (!data.tickets || data.tickets.length === 0) {
                    return;
                }
                if (!data.questions || data.questions.length === 0) {
                    return; // nada configurado para perguntar
                }

                SVM.cfg       = data.config;
                // enforce_mode efetivo (já considera o direito de isenção),
                // não o valor cru da configuração.
                SVM.cfg.enforce_mode = data.enforce_mode;

                // Quando icon_char guarda uma sequência ("😡 😕 😐 🙂 😍"),
                // o ícone "repetido" é só o primeiro token — senão os cinco
                // emojis apareceriam juntos em cada ponto da escala.
                SVM.cfg.icon_char_single =
                    (SVM.cfg.icon_sequence && SVM.cfg.icon_sequence.length > 1)
                        ? SVM.cfg.icon_sequence[0]
                        : SVM.cfg.icon_char;

                SVM.cfg.icon_empty_char_single =
                    String(SVM.cfg.icon_empty_char || '').trim().split(/[\s,]+/)[0] || '';
                SVM.questions = data.questions;
                SVM.nps       = data.nps || null;
                SVM.token     = data.svm_token;
                SVM.mustLock  = !!data.must_lock;

                renderModal(data.tickets);
            });
    }

    /**
     * Descobre a URL do endpoint a partir do próprio <script>, para
     * funcionar tanto em /plugins/svm/ quanto em /marketplace/svm/.
     */
    function resolvePath() {
        var src = null;

        $('script[src]').each(function () {
            var s = this.getAttribute('src') || '';
            if (s.indexOf('/svm/js/enforce.js') !== -1) {
                src = s;
                return false;
            }
        });

        if (src) {
            return src.split('?')[0].replace(/\/js\/enforce\.js$/, '/ajax/process.php');
        }

        return CFG_GLPI.root_doc + '/plugins/svm/ajax/process.php';
    }

    $(document).ready(function () {
        // Não injeta dentro de iframe (evita modal dentro de modal)
        if (window.self !== window.top) {
            return;
        }
        if (typeof CFG_GLPI === 'undefined') {
            return;
        }

        SVM.path = resolvePath();

        // Anti-remoção do overlay. isLocked só é ligado dentro de
        // renderModal, depois do overlay existir de fato.
        setInterval(function () {
            if (SVM.isLocked && $('#svm-full-overlay').length === 0) {
                window.location.reload();
            }
        }, 3000);

        check();
    });
})();
