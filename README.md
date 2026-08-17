# SVM — Gestão de Valor de Serviços

Pesquisa de satisfação para GLPI 10, com CSAT e NPS, permissionamento por perfil e painel gerencial.

**Versão:** 3.0.0 · **Requer:** GLPI 10.0+

> **Para aplicar uma atualização:** substitua a pasta do plugin e vá em
> *Configurar → Plugins → Atualizar* na linha do SVM. É esse botão que roda a
> migração do banco. Se a tela não mudar, reinicie o PHP (OPcache).
> Em caso de dúvida, acesse `/plugins/svm/front/diag.php`.

---

## Para que serve

Transformar o encerramento de chamados em dado de satisfação confiável e acionável — e devolver isso à gestão em formato de decisão, não de planilha.

O problema não é "coletar notas". É que, na maioria das operações de suporte, a percepção do usuário só aparece em reclamação escalada — quando já custou caro. O SVM antecipa esse sinal, atribui a um técnico, grupo e categoria, e o coloca na frente de quem pode agir.

## Por que usar, tendo a pesquisa nativa do GLPI

O GLPI já traz uma pesquisa: **uma** pergunta, escala de 0 a 5 estrelas, comentário livre, disparo por percentual de chamados, configuração por entidade. Para operações pequenas, isso pode bastar.

O SVM entra quando a operação precisa de:

| Necessidade | Nativo | SVM |
|---|---|---|
| Perguntas | 1 fixa | configuráveis, com peso e ordem |
| Escala | 0-5 fixa | 1-3, 1-5, 1-7, 1-10, NPS 0-10, binária ou própria |
| Aparência | estrelas | emoji, Font Awesome, imagem própria (upload) ou números |
| Obrigatoriedade | não existe | 4 níveis, de voluntário a bloqueio do sistema |
| Justificativa em nota baixa | não | obrigatória, validada no servidor |
| NPS | não | pergunta separada, com intervalo próprio |
| Ranking por técnico/grupo/categoria | não | painel com amostra mínima |
| Fila de detratores | não | lista com link ao chamado e comentário |
| Permissão granular | não | 5 direitos independentes por perfil |

O ponto central não é a contagem de recursos, é a **taxa de resposta**. Pesquisa voluntária em suporte interno costuma ficar abaixo de 15%, e amostra pequena e enviesada não sustenta decisão. O SVM permite tornar a resposta obrigatória de forma graduada — com o cuidado de não travar ninguém por acidente.

## O que ele faz

**Coleta** — modal em etapas com prévia do chamado; perguntas de escala, NPS, texto ou Sim/Não; escala de carinhas (um emoji por nota) ou ícone repetido; justificativa obrigatória em nota baixa; adiamento opcional com limite.

**Configuração** — uma configuração por entidade, com herança; ícone por upload ou URL; gatilho por chamado encerrado, a cada N encerrados ou manual; carência e expiração; todos os textos editáveis.

**Governança** — cinco direitos independentes por perfil; restrição de entidade respeitada em toda consulta.

**Análise** — KPIs de CSAT, nota média, NPS e detratores; tendência mensal; distribuição das notas; ranking por técnico, grupo e categoria; fila de follow-up; exportação CSV.

## Oportunidades de uso

**Recuperação de detratores.** A fila de follow-up serve a uma prática específica: contato individual em até 48h com quem avaliou mal. É o uso com retorno mais direto — transforma insatisfação registrada em problema resolvido.

**Diagnóstico por categoria.** CSAT baixo concentrado numa categoria raramente é problema de atendimento; costuma ser processo, documentação ou ferramenta. O ranking separa uma coisa da outra.

**Necessidade de treinamento.** Padrão consistente por técnico, com amostra suficiente, indica onde investir em capacitação.

**Reconhecimento.** Satisfação é um dos poucos indicadores de suporte que mede resultado percebido, não esforço. Serve para reconhecer bem.

**Complemento ao SLA.** SLA mede prazo cumprido; CSAT mede se a entrega resolveu. Chamado no prazo com nota baixa é exatamente o caso que o SLA não captura.

**Governança.** Evidência de medição contínua de satisfação, com histórico e responsáveis — útil em ITIL e ISO 20000.

## Decisões que afetam a leitura dos números

**CSAT e NPS não se misturam.** CSAT mede o atendimento pontual; NPS mede lealdade ao suporte. Somar os dois numa média produz um número sem significado. São calculados separadamente.

**CSAT agregado é soma de satisfeitas ÷ soma de respostas**, não média de percentuais. Média de médias daria o mesmo peso a uma pesquisa de uma pergunta e a uma de três.

**Amostra pequena fica fora do ranking.** Com duas respostas só existem 0%, 50% e 100% — alguém "lidera" ou "afunda" por acidente. O mínimo é configurável e os casos abaixo dele são listados à parte.

O padrão de instalação é **não bloqueante**: o bloqueio é opt-in, e perfis com direito de configuração ficam isentos automaticamente, para que um ajuste equivocado nunca tranque quem precisa entrar e corrigir.

## Limites conhecidos

- **Em homologação** — não validado em produção com volume real.
- **Obrigatoriedade cobra um preço.** Bloquear aumenta a taxa de resposta e incomoda. Comece em `reminder` e endureça só se necessário.
- **Fadiga de pesquisa.** Acima de 5 perguntas ativas a taxa de resposta cai muito; a tela avisa.
- **Anonimato é escolha excludente** — aumenta a sinceridade e impede o follow-up individual.
- **Chamado com vários técnicos** conta para cada um, então a soma por técnico pode exceder o total.
- **`is_anonymous` ainda não filtra a exibição** para o técnico: o campo existe, o comportamento está pendente.

## Referências de leitura dos indicadores

- **CSAT:** 70-85% é bom; acima de 85% é excepcional e difícil de sustentar.
- **NPS:** acima de 50 é excelente; 70+ é classe mundial. Faixas: 0-6 detrator, 7-8 neutro, 9-10 promotor.

---

# Configuração

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

- **Termômetros** de CSAT, NPS e nota média: arcos SVG com as faixas de referência pintadas ao fundo, para leitura imediata de "onde estamos".
- KPIs de pesquisas, respostas, detratores e comentários.
- Composição do NPS em barra empilhada (detratores / neutros / promotores).
- **Tendência do CSAT** em gráfico de linha com tooltip ao passar o mouse, faixas de 70% e 85% marcadas e a contagem de pesquisas sob cada mês — mês com pouca resposta oscila mais, e isso fica visível.
- Distribuição das notas em colunas. Uma distribuição em U (muitas notas extremas) revela experiências inconsistentes, algo que a média esconde.

**Interatividade**

- **Pódio** nas três abas: 2º-1º-3º com degraus de altura proporcional, coroa no primeiro, foto do GLPI (ou iniciais coloridas) e o CSAT em destaque. Grupos e categorias usam iniciais.
- **Detalhamento em um clique:** clicar na foto ou no nome — no pódio ou na tabela — abre um modal com os chamados avaliados daquele técnico, grupo ou categoria, cada um com a **nota de cada pergunta** em pastilhas coloridas, CSAT, NPS, comentário e link para o chamado. A tabela do modal também é ordenável.
- **Drill-down:** o ícone de filtro na linha recarrega o painel inteiro recortado por aquele item. Os filtros ativos aparecem como chips removíveis.
- **Tabelas ordenáveis** por qualquer coluna (clique no cabeçalho).
- **Copiar tabela** em um clique, no formato TSV — cola direto no Excel ou Sheets.

**Densidade: tudo numa tela**

O painel é organizado para caber numa tela sem rolagem (≈870px de altura em desktop):

| Faixa | Conteúdo |
|---|---|
| 1 | Barra de controles — todos os filtros numa linha, chips e exportação |
| 2 | Três termômetros + números + composição do NPS |
| 3 | Tendência e distribuição lado a lado |
| 4 | Rankings em **abas** (técnico / grupo / categoria) |

Duas decisões que sustentam isso: os três rankings viram abas em vez de empilhar, e as tabelas longas **rolam por dentro** do painel em vez de empurrar a página — nenhum dado fica inacessível. A fila de detratores e a visão analítica ficam recolhidas, com a contagem visível no título.

Tudo em SVG e CSS próprios: sem biblioteca externa, sem CDN, funciona offline. As abas são CSS puro (radio + label) e os gráficos são gerados no servidor, então o painel funciona sem JavaScript — o JS só acrescenta tooltip, ordenação e cópia.

**Rankings** por técnico, grupo e categoria, com pesquisas, CSAT, nota média, NPS e detratores. Três cuidados deliberados:

- Quem tem menos que a amostra mínima (padrão 5) **fica fora do ranking**, listado à parte. Com 2 respostas só existem 0%, 50% e 100% — ranquear isso produz comparação enganosa.
- CSAT agregado é `soma das satisfeitas / soma das respostas`, não média de percentuais.
- Um chamado com vários técnicos atribuídos conta para cada um, então a soma por técnico pode exceder o total. Está anotado na tela.

**Fila de follow-up:** detratores de NPS ou pesquisas com menos de 50% de respostas satisfeitas, com link para o chamado, técnico e comentário — para o contato em até 48h.

**Exportação** (`plugin_svm_report` + direito de exportar):

- **CSV** com resumo, consolidados por técnico/grupo/categoria, tendência e analítico. Separador `;` e decimal `,` para o Excel pt-BR; células iniciadas por `=`, `+`, `-` ou `@` são neutralizadas contra injeção de fórmula.
- **JSON** em `export.php?format=json`, com o consolidado pronto para Power BI, Grafana ou script. A autenticação é por sessão do GLPI — uma ferramenta externa precisa de cookie válido ou do API REST; não há token próprio de propósito, para não manter mais uma superfície de autenticação.
- **Copiar** cada tabela como TSV, para colar em planilha.

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
ajax/detail.php            detalhamento por técnico, grupo ou categoria
js/enforce.js              modal montado a partir da configuração
css/styles.css             estilos
```

## 7. Próximos passos sugeridos

- Dashboard com séries temporais de CSAT/NPS e recorte por técnico, categoria e grupo.
- Fila de follow-up de detratores com prazo de 48h.
- Notificação por e-mail com link de resposta em um clique (aumenta muito a taxa de resposta).
- Ação massiva em chamados para disparar pesquisa no modo `manual`.
- Anonimização efetiva das respostas quando `is_anonymous` está ligado (hoje o campo existe mas ainda não filtra a exibição para o técnico).
