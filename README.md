# PHP Além das Abstrações

Este repositório faz parte do material de apoio do livro **"PHP Além das Abstrações: Um guia para engenheiros, que precisam do que bibliotecas"**.

> *"Quando o desempenho vale mais do que um código bonito."*

O principal objetivo do livro é demonstrar que o PHP pode ir muito além das abstrações oferecidas por frameworks e bibliotecas. Exploramos técnicas de baixo nível, muitas vezes esquecidas ou ignoradas, que permitem ganhos de performance na ordem de 10x a 100x, abordando:

- Profiling e Debugging profundo
- Operações Bitwise e compactação de dados
- Processamento binário e ETL de alta performance
- Concorrência cooperativa com Fibers
- Memória compartilhada (IPC)
- Estruturas de dados especializadas (SPL - Standard PHP Library)
- Opcodes, JIT e a Zend Engine
- Stream Wrappers customizados
- Algoritmos probabilísticos (Bloom Filter, HyperLogLog), para mineração de vastas quantidades de dados
- FFI e extensões nativas

Este repositório fornece exemplos funcionais dos códigos comentados no livro, permitindo que você execute os testes de forma prática e evite erros de digitação ao replicar os exemplos.

## Adquira o Livro

O livro completo, com a fundamentação teórica e exemplos práticos de cada técnica, **estará disponível para compra em formato digital e físico (em breve)**. A obra é escrita em **Português do Brasil**.

## Estrutura do Repositório

Após clonar o repositório, você encontrará scripts organizados por tema, prontos para execução e experimentação:

```text
.
├── src/                        # Código-fonte dos exemplos do livro
│   ├── gerar_compras.php       # Gerador de massa de dados para benchmarks
│   ├── etl_binario.php         # Conversão CSV → Binário e benchmark
│   ├── bitwise_flags.php       # Compactação com operações bit a bit
│   ├── shm_cache.php           # Cache com memória compartilhada (IPC)
│   ├── processador_concorrente.php  # Scheduler Round-Robin com Fibers
│   ├── opcode.php              # Visualização de Opcodes
│   ├── benchmark_jit.php       # Benchmark com/sem JIT
│   ├── stream_vendas.php       # Stream Wrapper customizado
│   ├── bloom_filter_memory.php # Implementação de Bloom Filter
│   ├── hyperloglog_uniq.php    # Implementação de HyperLogLog
│   └── ...                     # Outros exemplos FFI, SPL, etc.
├── tools/                      # Ferramentas de diagnóstico e observabilidade
│   ├── profiler.php            # Profiler CLI não-intrusivo
│   ├── opcode_viewer.php       # Inspetor de Opcache
│   └── top_memory.php          # Memory Watchdog (Sidecar)
└── vendas.csv / vendas.bin     # Datasets de exemplo

```

## Exemplos de Uso Rápido

### Profiler CLI (Diagnóstico)
```bash
# Mede tempo e memória de qualquer script sem modificá-lo
php tools/profiler.php src/etl_binario.php vendas.csv vendas.bin
```

### Geração de Massa de Dados
```bash
# Gera um arquivo CSV de aproximadamente 1GB
php src/gerar_compras.php --tamanho=1gb --saida=vendas-big.csv
```

### ETL Binário
```bash
# Converte CSV para formato binário otimizado
php src/etl_binario.php vendas.csv vendas.bin
```

### Bitwise Flags (Compactação de Memória)
```bash
php src/bitwise_flags.php vendas.csv
```

### Memória Compartilhada (IPC)
```bash
# Terminal 1 (Escritor)
php src/shm_cache.php write

# Terminal 2 (Leitor)
php src/shm_cache.php read
```

### Processamento Concorrente com Fibers
```bash
php src/processador_concorrente.php vendas.csv
```

### Memory Watchdog
```bash
# Modo DEMO (simula vazamento para teste)
php tools/top_memory.php

# Modo monitoramento real
php tools/top_memory.php <PID_DO_PROCESSO_PHP> [LIMITE_MB]
```

## Conteúdo do Livro

O livro está organizado em 5 níveis de dificuldade progressiva:

| Nível | Tema | Tópicos |
|-------|------|---------|
| **1** | Dificuldade Baixa | Profiling, Debugging, Ferramentas de Monitoramento |
| **2** | Dificuldade Média | Streams, Generators, Bitwise, Processamento Binário |
| **3** | Avançado | Fibers, Memória Compartilhada (IPC), SPL Otimizado |
| **4** | Expert | Opcodes, VLD, JIT, Stream Wrappers, Algoritmos Probabilísticos |
| **5** | Master | FFI, Extensões Nativas |

Cada capítulo inclui:
- Fundamentação teórica com analogias práticas
- Código-fonte completo e comentado
- Benchmarks reais com resultados mensurados
- Referências bibliográficas para aprofundamento

## Conhecimento

O livro não é essencial para compreender este repositório; entretanto, a leitura é altamente recomendada para se familiarizar com os conceitos de baixo nível, a Zend Engine e os motivos pelos quais certas decisões arquiteturais podem resultar em ganhos de performance relevantes. Especialmente se você é um desenvolvedor PHP que sempre dependeu de abstrações de frameworks e nunca precisou ir além do básico para web.

Este material é para engenheiros que precisam do que as bibliotecas não oferecem: **controle total**.
