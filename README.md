# SVM 3.1 — Permissionamento, Configuração e Indicadores

> **Para aplicar uma atualização:** substitua a pasta do plugin e vá em
> *Configurar → Plugins → Atualizar* na linha do SVM. É esse botão que roda a
> migração do banco. Se a tela não mudar, reinicie o PHP (OPcache).
> Em caso de dúvida, acesse `/plugins/svm/front/diag.php`.

## 1. Direitos por perfil

Aba **Pesquisa de satisfação** em *Administração > Perfis*. Cinco direitos independentes:

| Direito | Permissões | Para quê |
|---|---|---|
| `plugin_svm_config` | Ler / Criar / Atualizar / Excluir | Escala, ícone, gatilhos, textos |
| `plugin_svm_question` | Ler / Criar / Atualizar / Excluir | Banco de perguntas |
| `plugin_svm_survey` | Ler as próprias / **Ler as de todos** / Atualizar / Excluir | Respostas coletadas |
| `plugin_svm_report` | Ver indicadores / Exportar | CSAT% e NPS agregados |
| `plugin_svm_bypass` | Isentar do bloqueio | Quem não é interrompido pelo modal |

Na instalação: o perfil de quem instala recebe tudo; perfis de autoatendimento recebem leitura das próprias respostas; **todo perfil com direito em `config` recebe `plugin_svm_bypass`** — rede de segurança para que uma configuração equivocada nunca tranque quem precisa entrar para corrigi-la.

`plugin_svm_survey` distingue "ler as próprias" de "ler as de todos" (bit 1024). Sem o segundo, o usuário só vê as suas respostas, e os indicadores são calculados apenas sobre elas.

## 2. Configuração por entidade

Uma configuração por entidade, com herança. A resolução escolhe a **mais específica** subindo a árvore por `level` — a config da própria entidade vale sempre, as das ancestrais só se marcadas como recursivas.

### Métrica

CSAT e NPS medem coisas diferentes e são calculados separadamente:

- **CSAT** (escala configurável, 1-5 por padrão) mede a satisfação com **aquele atendimento**. É o indicador do ticket.
- **NPS** (0-10, pergunta separada) mede **lealdade ao suporte como um todo**. Perguntado a cada 90 dias por padrão, para não gerar fadiga.

Escalas disponíveis: CSAT 1-3, 1-5, 1-7, 1-10, NPS 0-10, binária (👍/👎) e personalizada. O formulário rejeita escalas com mais de 11 pontos.

### Aparência

`icon_type` aceita emoji/caractere, Font Awesome, imagem ou apenas números. `icon_render_mode` define se o preenchimento é cumulativo (estrelas) ou único. A tela tem pré-visualização ao vivo, inclusive da imagem escolhida antes de salvar.

#### Upload de imagens

Cada ícone (ativo e inativo) aceita **upload de arquivo** ou URL externa. Quando os dois estão preenchidos, o arquivo enviado tem prioridade.

- Formatos: PNG, JPG e — se o GD do servidor suportar — GIF e WEBP. Limite de 512 KB.
- **SVG não é aceito de propósito:** é XML e pode conter script, que executaria na mesma origem do GLPI.
- Toda imagem é **reencodada via GD para PNG** e reduzida para 128px. Isso normaliza o arquivo e descarta qualquer conteúdo embutido — o que fica em disco é um PNG gerado pelo próprio plugin, não os bytes originais. Transparência é preservada.
- Dimensões acima de 4000×4000 são rejeitadas antes do decode, para evitar esgotamento de memória por imagem propositalmente grande.
- Os arquivos ficam em `files/_plugins/svm/icons`, **fora da raiz web**, com nome aleatório de 32 hex. São servidos só por `front/icon.send.php`, que exige sessão válida, valida o nome contra travessia de diretório e fixa o `Content-Type`.
- Trocar ou remover a imagem apaga a anterior do disco; excluir a configuração apaga as suas imagens; desinstalar o plugin remove o diretório.

### Justificativa obrigatória

`justify_threshold` define a nota que exige explicação (padrão 3 em escala 1-5) e `justify_min_length` o tamanho mínimo (padrão 15 caracteres). Em perguntas do tipo NPS a regra usada é a faixa de detrator (0-6), não o limiar do CSAT. A validação é feita **no servidor**, não só no navegador.

### Obrigatoriedade

| Modo | Efeito |
|---|---|
| `off` | Pesquisa voluntária, JS não é injetado |
| `reminder` | **Padrão.** Aviso dispensável |
| `block_new_ticket` | Aviso dispensável + bloqueio real na criação do chamado (hook `pre_item_add`) |
| `block_all` | Overlay não dispensável até responder |

O padrão de instalação é `reminder` de propósito: um plugin recém-instalado não deve poder trancar a interface de ninguém. O bloqueio é opt-in.

### Gatilho

`immediate` (todo chamado encerrado), `closed_count` (a cada N encerrados, padrão 5) ou `manual`. Complementos: carência em horas após o encerramento, expiração em dias, status pesquisáveis (Solucionado e/ou Fechado) e limite de adiamentos.

## 3. Boas práticas aplicadas nos defaults

Levantadas em fontes de CX/suporte e refletidas nos valores padrão:

- **CSAT para o ticket, NPS para o relacionamento.** Misturar os dois numa média só produz um número sem significado.
- **1 a 3 perguntas de nota + 1 aberta.** A tela avisa acima de 5 perguntas ativas; a referência é a pesquisa levar menos de 30 segundos.
- **Escala 1-5 e consistência.** Trocar de escala depois quebra a comparabilidade histórica.
- **Pedir em até 24h do encerramento**, enquanto o atendimento está fresco.
- **Uma pergunta aberta para explicar a nota**, e justificativa obrigatória em nota baixa — o número diz *o quê*, o comentário diz *por quê*.
- **Detrator merece contato individual em até 48h.** A tela de indicadores mostra a contagem de detratores justamente para acionar isso.
- **Linguagem neutra.** Enunciados e cores que sugerem a resposta desejada enviesam o resultado.

### Referências de leitura dos indicadores

- CSAT: 70-85% é bom, acima de 85% é excepcional e difícil de sustentar.
- NPS: acima de 50 é excelente, 70+ é classe mundial. Faixas: 0-6 detrator, 7-8 neutro, 9-10 promotor.

O CSAT% agregado é `total de respostas satisfeitas / total de respostas`, não média de percentuais — média de médias daria peso igual a pesquisas com quantidades diferentes de perguntas.

## 4. Painel de indicadores

Em *Assistência → Pesquisas de satisfação*. Exige `plugin_svm_report` READ; sem esse direito o usuário vê apenas a lista analítica das respostas a que tem acesso.

**Filtros:** período (30/90/180/365 dias ou tudo), entidade (inclui subentidades), categoria, técnico, grupo e amostra mínima do ranking.

**Visão sintética**

- Seis KPIs: pesquisas respondidas, CSAT, nota média, NPS, detratores e quantidade com comentário.
- Composição do NPS em barra empilhada (detratores / neutros / promotores).
- Tendência mensal do CSAT, com a contagem de pesquisas de cada mês abaixo da barra — mês com pouca resposta oscila mais, e isso fica visível.
- Distribuição das notas individuais. Uma distribuição em U (muitas notas extremas) revela experiências inconsistentes, algo que a média esconde.

**Rankings** por técnico, grupo e categoria, com pesquisas, CSAT, nota média, NPS e detratores. Três cuidados deliberados:

- Quem tem menos que a amostra mínima (padrão 5) **fica fora do ranking**, listado à parte. Com 2 respostas só existem 0%, 50% e 100% — ranquear isso produz comparação enganosa.
- CSAT agregado é `soma das satisfeitas / soma das respostas`, não média de percentuais.
- Um chamado com vários técnicos atribuídos conta para cada um, então a soma por técnico pode exceder o total. Está anotado na tela.

**Fila de follow-up:** detratores de NPS ou pesquisas com menos de 50% de respostas satisfeitas, com link para o chamado, técnico e comentário — para o contato em até 48h.

**Exportação CSV** (`plugin_svm_report` + direito de exportar) com resumo, consolidados, tendência e analítico. Separador `;` e decimal `,` para o Excel pt-BR; células iniciadas por `=`, `+`, `-` ou `@` são neutralizadas contra injeção de fórmula.

Os gráficos são HTML/CSS puro — sem biblioteca externa, funciona offline.

## 5. Migração da v2.1.0

As respostas já coletadas são preservadas. As três perguntas que estavam fixas no `enforce.js` viram registros editáveis, e as colunas `score_value` / `score_tech` / `score_speed` continuam sendo preenchidas para compatibilidade com relatórios existentes.

A chave única passa de `tickets_id` para `(tickets_id, users_id)`, permitindo que cada requerente de um chamado avalie separadamente. Duplicatas são removidas antes do `ALTER`; se ele falhar, a instalação continua com a chave antiga em vez de parar no meio.

## 6. Onde ficou cada coisa

```
setup.php                  hooks, menu, injeção do JS, bloqueio de novo chamado
hook.php                   schema, migração, seed, direitos
inc/config.class.php       configuração + resolução por entidade + cálculos
inc/question.class.php     perguntas dinâmicas (aba da configuração)
inc/answer.class.php       respostas por pergunta (aba da pesquisa)
inc/survey.class.php       elegibilidade, gatilho, gravação, indicadores
inc/profile.class.php      direitos e aba em Perfis
front/config.php           lista de configurações
front/config.form.php      formulário de configuração
front/question.form.php    formulário de pergunta
front/survey.php           respostas + painel de indicadores
front/icon.send.php        entrega das imagens enviadas por upload
front/export.php           exportação CSV dos indicadores
front/diag.php             diagnóstico de instalação (pode apagar)
inc/report.class.php       agregação dos indicadores do painel
ajax/process.php           check / save / skip
js/enforce.js              modal montado a partir da configuração
css/styles.css             estilos
```

## 7. Próximos passos sugeridos

- Dashboard com séries temporais de CSAT/NPS e recorte por técnico, categoria e grupo.
- Fila de follow-up de detratores com prazo de 48h.
- Notificação por e-mail com link de resposta em um clique (aumenta muito a taxa de resposta).
- Ação massiva em chamados para disparar pesquisa no modo `manual`.
- Anonimização efetiva das respostas quando `is_anonymous` está ligado (hoje o campo existe mas ainda não filtra a exibição para o técnico).
