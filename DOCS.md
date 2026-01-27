# Documentação dos Novos Scripts de Performance

Este documento detalha os scripts adicionais criados para demonstrar técnicas avançadas de performance ("Performance Extrema").

---

## 1. ETL e Manipulação de Dados

### `src/etl_binario.php` (CSV vs Binário Otimizado)
Demonstra a vantagem de usar formatos binários de largura fixa e tipagem forte.

*   **Estratégia "Low Fat":** Técnicas de compressão lógica:
    *   **Datas:** De `YYYY-MM-DD` (10 bytes) para Timestamp Integer (4 bytes).
    *   **Enums:** De Strings (ex: "Concluido" - ~10 bytes) para Bytes Flags (1 byte).
*   **Trade-off de Tamanho (Padding):**
    *   **O Binário ficou maior?** Sim. Para garantir *Seek O(1)* (pular direto para a linha 1 milhão), cada registro DEVE ter exatamente o mesmo tamanho em bytes.
    *   **O Custo do `A60`:** Definimos `A60` (60 bytes) para o nome do produto. Se o produto for "Mouse" (5 bytes), gastamos 55 bytes com `NULL` (padding).
    *   **A Vantagem:** Em troca de 30-50% a mais de disco (barato), ganhamos **Zero CPU de Parsing** de strings na leitura e acesso instantâneo.
*   **Performance:** Bufferização de leitura (4MB chunks) reduz *System Calls* em 99%.

> O tamanho do binário acaba sendo maior deivdo ao padding de cada campo, o que significa que jamais será menor que o arquivo original. Como cada registro tem exatamente o mesmo tamanho, é o que permite pular diretamente para um registro qualquer. Se colocarmos para que haja de fato uma compactação, os reistros teriam tamanho variável (como no original), mas isso impede a navegação diretamente para um registro X.

### `src/processador_concorrente.php` (Fibers)
Implementa um pipeline de processamento concorrente para tarefas de I/O Bound.

*   **Conceito (Lightweight Threads):**
    Fibers (PHP 8.1+) são "Green Threads" gerenciadas pela VM do PHP, não pelo Sistema Operacional. Isso permite criar milhares de threads de execução dentro de um único processo OS com overhead mínimo de memória.

*   **Cenário de Uso Real (Escala de Consumidores):**
    Imagine um worker consumindo mensagens do RabbitMQ/Redis.
    *   **Abordagem Clássica (Processos):** 1 Worker = 1 Processo OS. Para consumir 100 filas simultaneamente, você precisa de 100 processos (ex: SupervisorD). Se cada processo consome 20MB, são 2GB de RAM.
    *   **Abordagem com Fibers:** 1 Worker = 1 Processo OS com 100 Fibers. Cada Fiber mantém sua própria conexão e estado. O custo de memória é apenas o stack da função (KB), permitindo altíssima densidade.

*   **Comparativo: Fibers vs Swoole vs RoadRunner vs ReactPHP**
    *   **Swoole/RoadRunner:** Substituem o runtime "Request/Response" tradicional. Assumem o controle total do servidor HTTP e Loop de Eventos. Oferecem máxima performance crua, mas exigem mudanças arquiteturais profundas e, no caso do Swoole, extensões C específicas. Há implementações em produção como o Octante (Laravel), o kernel do frameworrk foi reescrito justamente para comportar essas mudanças estruturais que o uso de Swoole ou RoadRunner exigem, ou seja, não é transparente para as aplicações PHP, muito pelo contrário.
    *   **Fibers (Nativo):** É uma primitiva de linguagem. Não traz um Loop de Eventos pronto. Oferece a capacidade de *pausar* a execução de forma elegante. É perfeito para bibliotecas (como ReactPHP ou Amphp) construírem abstrações assíncronas que parecem código síncrono.
        *   *Vantagem:* Roda no PHP Standard (CLI/FPM) sem binários extras.
        *   *Desvantagem:* Exige um Scheduler (como demonstrado neste script) para orquestrar quem roda e quando.

*   **Limitações Críticas (O Perigo do Bloqueio):**
    *   **Single-Threaded:** O PHP continua rodando em uma única thread de CPU. As Fibers apenas alternam quem está usando essa thread.
    *   **A "Armadilha" do Bloqueio:** Se uma Fiber executar `sleep(5)` ou `PDO::query()` (síncrono), ela **bloqueia todo o processo**. As outras 99 Fibers congelam instantaneamente. Para usar Fibers, **todo** o I/O deve ser não-bloqueante (Async I/O).

*   **Implementação do Exemplo:**
    *   Scheduler Round-Robin customizado.
    *   Simulação de I/O não-bloqueante usando suspensão voluntária.
    *   Controle de Backpressure para limitar concorrência.

---

## 2. Micro-Otimizações de Memória e CPU

### `src/bitwise_flags.php` (Bitwise Operations)
Demonstra como reduzir drasticamente o consumo de memória ao armazenar status e tipos em bits em vez de strings/arrays.

*   **Cenário do Script:** Armazenar `Status` (5 opções) e `Método de Pagamento` (4 opções) para cada venda.
*   **Solução:** Tudo cabe em **1 único Byte** (Int 8).
    *   Bits 0-2: Status (0 a 7).
    *   Bits 3-4: Pagamento (0 a 3, deslocados com `<< 3`).
*   **Resultado (Benchmark Real com `vendas.csv`):**
    *   Abordagem Clássica (Arrays/Strings): ~2.5 MB para 5.500 registros.
    *   Abordagem Bitwise (SplFixedArray): ~0.09 MB para 5.500 registros.
    *   **Economia:** 96.5% (Redução de 28x). Em um dataset de 1 milhão de linhas, cairíamos de ~450MB para ~16MB.

**Exemplos Práticos no Mundo Real (PHP Core):**
O uso de Bitwise é a base de configurações eficientes no PHP.

1.  **Error Reporting (`php.ini`):**
    É muito comum ver `error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);`.
    *   `E_ALL`: Constante com todos os bits ligados (ex: `111111`).
    *   `~` (NOT): Inverte os bits de `E_NOTICE` (liga todos, menos o do Notice).
    *   `&` (AND): Combina as máscaras, efetivamente "desligando" o bit de Notice do E_ALL.
    *   Veja na doc oficial: [Constantes de Erro](https://www.php.net/manual/pt_BR/errorfunc.constants.php).

2.  **JSON Options:**
    Ao fazer `json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)`, usamos o operador `|` (OR) para combinar múltiplas opções em um único inteiro. O PHP verifica internamente com `&` (AND) se uma opção está ativa.

3.  **Sistemas de Permissão (ACL):**
    Similar ao `chmod` do Linux (755), onde:
    *   `READ` = 4 (`100`)
    *   `WRITE` = 2 (`010`)
    *   `EXECUTE` = 1 (`001`)
    *   Permissão 7 = `4 | 2 | 1` (`111`). Permite verificar acesso com `if ($userPerms & PERM_WRITE)`.

### `src/shm_cache.php` (Memória Compartilhada / IPC)
Demonstra como utilizar a extensão `shmop` (Shared Memory Operations) para compartilhar dados entre diferentes processos PHP sem usar bancos de dados (Redis/Memcached).

*   **Conceito Didático:**
    Imagine que cada processo PHP é um aluno em uma sala de aula fazendo uma prova.
    *   *Sem SHM:* Cada aluno tem suas próprias anotações (Memória RAM isolada). Se 50 alunos precisam consultar a Tabela Periódica, existem 50 cópias dela na sala.
    *   *Com SHM:* O professor escreve a Tabela Periódica no quadro negro (Memória Compartilhada). Todos os alunos consultam a mesma e única cópia. Economiza papel (RAM) e garante que todos vejam a mesma versão.

*   **Casos de Uso Reais:**
    1.  **Configurações Globais:** Carregar um JSON pesado de configurações uma única vez e compartilhar com todos os workers.
    2.  **Catálogo de Produtos (Read-Mostly):** Manter os TOP 1000 produtos na RAM para acesso ultra-rápido (< 0.1ms).
    3.  **Contadores de Altíssima Frequência:** Monitorar requests em tempo real onde o overhead de rede do Redis seria impactante.

*   **Implementação do Exemplo (`src/shm_cache.php`):**
    *   Usa um **Protocolo Binário Simples** para robustez: `[HEADER: 4 bytes Tamanho] + [BODY: Drivers Serializados]`.
        *   Isso resolve o problema de ler "lixo" (null bytes) no final do bloco de memória.
    *   **Demonstração CLI/Daemon:** Inclui um loop interativo para manter a memória viva no Windows/CLI durante o teste (daemon mode).

*   **Limitações e Cuidados:**
    *   **Volatilidade:** Se a máquina reiniciar, os dados somem (é RAM).
    *   **Race Conditions:** O PHP nativo não tem bloqueio de escrita (mutex) automático para SHM. Se dois processos escreverem ao mesmo tempo, os dados corrompem. (Solução: usar `semaphores` ou apenas um processo escritor).
    *   **Tamanho Fixo:** O bloco deve ser alocado com tamanho pré-definido.
    *   **Windows vs Linux:** No Linux, a memória persiste após o fim do script. No Windows, ela é liberada se não houver processos atrelados (por isso o nosso script tem o modo "Daemon").

---

## 3. Tópicos Avançados (Future Topics)

### `src/benchmark_jit.php` (CPU Bound & Fractals)
Demonstra o poder de processamento bruto do **JIT Compiler** (PHP 8.0+), introduzindo cálculos matemáticos pesados onde a compilação nativa brilha.

*   **O Teste (Conjunto de Mandelbrot):**
    O script gera um fractal complexo calculando iterativamente (até 10.000x por pixel) se um ponto pertence ao conjunto matemático.
    *   *Sem JIT:* O PHP VM interpreta cada soma/multiplicação.
    *   *Com JIT:* O código é convertido para Assembly nativo da CPU.
*   **Como Testar:**
    *   **JIT OFF:** `php -d opcache.jit_buffer_size=0 src/benchmark_jit.php` (Lento)
    *   **JIT ON:** `php -d opcache.enable_cli=1 -d opcache.jit_buffer_size=100M src/benchmark_jit.php` (Turbo)

### `src/bloom_filter_memory.php` (Algoritmos Probabilísticos)
Mostra como **economizar 99% de RAM** ao verificar a existência de dados em conjuntos massivos, aceitando uma margem controlada de incerteza.

*   **Conceito (Bloom Filter):**
    Em vez de carregar 1 milhão de IDs na memória (que custaria ~50MB em arrays), usamos um Mapa de Bits de apenas 512KB.
    *   *Mágica:* Usamos funções de Hash (CRC32, Adler32) para acender "luzes" (bits) em posições específicas.
    *   *Garantia:* Se o filtro diz "NÃO", é **100% garantido** que o cliente não existe. Se diz "SIM", existe uma chance ínfima de ser um alarme falso (falso positivo).
*   **Uso:** Excelente para verificar caches (se não está no Bloom, nem vai no Banco) ou validar URLs maliciosas.

### `src/stream_vendas.php` (Stream Wrappers Customizados)
Ensina como estender a linguagem PHP para entender formatos de arquivos que nativamente ela desconhece.

*   **A Mágica `vendas://`:**
    O script registra um protocolo novo. Quando você faz `fopen('vendas://vendas.bin')`, o PHP executa nossa classe invisivelmente.
    *   *Tradução On-The-Fly:* O Wrapper lê o binário ilegível do disco, desempacota (unpack) e entrega para seu código uma linha bonita de texto (CSV simulado).
    *   *Abstração:* Seu código legado que lê CSV pode ler Binários de Alta Performance sem mudar uma vírgula na lógica, apenas mudando o caminho do arquivo.

---

### `src/hyperloglog_uniq.php` (HyperLogLog - Cardinalidade)
Implementa o algoritmo probabilístico **HyperLogLog** para contar clientes únicos (Cardinadade) consumindo memória constante (~2KB), independente do volume de dados.

*   **O Problema do `array_unique()`:**
    Em Big Data, guardar milhões de IDs em um array para contar únicos consome Gigabytes de RAM.
*   **A Solução HLL:**
    Observando a distribuição de "zeros" nos hashes dos elementos, estimamos o total com erro baixo (~0.8% a 2%).
*   **Resultado:** Contar milhões de usuários usando menos memória que uma imagem JPEG pequena.

### `src/trie_autocomplete.php` (Trie - Árvore de Prefixos)
Estrutura de dados especializada para buscas de texto (Autocomplete) com performance superior a Arrays ou Banco de Dados (LIKE).

*   **Busca O(k):** O tempo de busca depende apenas do tamanho da palavra digitada (k), e não do total de produtos (n).
*   **Efeito Prático:** Buscar "mou" em um índice de 10 milhões de produtos demora o mesmo tempo que em um índice de 100 produtos.
*   **Demo:** O script carrega os nomes dos produtos do `vendas.bin` para uma Trie em memória e realiza buscas instantâneas.

---

## 4. Ferramentas de Diagnóstico (Observabilidade)

### `tools/top_memory.php` (Watchdog de Memória)
Uma ferramenta essencial para diagnósticos de **Memory Leaks** em ambientes de desenvolvimento e produção. Ela atua como um "cão de guarda" (Sidecar), monitorando um processo PHP alvo externamente.

*   **O Conceito de Watchdog (Sidecar):**
    Em vez de poluir seu código de produção com chamadas constantes de `memory_get_usage()` (que tem performance cost), você roda um script leve em paralelo. Esse script só faz uma coisa: observa o consumo de RAM do processo principal e grita se algo sair do controle.

*   **Como Usar:**
    1.  **Modo Monitor:** `php tools/top_memory.php <PID> [limite_mb]`
        *   Monitora um PID específico (ex: um worker do Laravel Horizon ou um script de importação).
        *   Exibe uma barra de progresso visual no terminal.
        *   Dispara um alerta vermelho se o consumo cruzar o limite (Default: 128MB).
    
    2.  **Modo Auto-Demo:** `php tools/top_memory.php`
        *   Basta rodar sem argumentos.
        *   O script inicia automaticamente um processo "filho" em background que simula um vazamento de memória (aloca 1MB a cada 0.5s).
        *   Você verá a barra subir em tempo real e o alerta disparar aos 50MB. Ótimo para testar a ferramenta!

*   **Detalhes Técnicos (Cross-Platform):**
    Para garantir precisão máxima em qualquer SO, o script muda de estratégia internamente:
    *   **Linux:** Lê diretamente de `/proc/[PID]/status` (VmRSS). É instantâneo e consome zero CPU.
    *   **Windows:** Usa **PowerShell** e WMI (`Get-Process ... PrivateMemorySize64`) para obter o *Commit Charge* real. Isso evita o problema comum do gerenciador de tarefas mostrar valores "congelados" devido ao gerenciamento de paginação do Windows.

### `tools/opcode_viewer.php` (Introspecção do Opcache)
Ferramenta para verificar se o script está sendo cacheado corretamente pelo Opcache e visualizar suas métricas internas.

*   **Dados:** Mostra memória consumida pelo bytecode, número de hits e timestamps.
*   **Dica:** Inclui instruções para visualizar o Assembly do PHP (Opcodes reais) usando a extensão `VLD`.
