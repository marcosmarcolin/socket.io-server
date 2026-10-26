# Desempenho e limites

[English](performance.md) · **Português (Brasil)**

Quanto um processo aguenta, o que ele precisa para chegar lá, e como medir na sua própria máquina.

## Do que um processo precisa

- **A extensão `event` acima de cerca de 1.000 conexões.** Sem ela, o Workerman usa o `select()` do PHP, que atende no máximo 1.024 descritores por processo: além disso, o Workerman avisa e as conexões novas falham. Com o `event` instalado, o Workerman o escolhe sozinho:

```sh
pecl install event
# o event precisa carregar depois do sockets: o nome do ini o deixa por último
echo "extension=event.so" > "$(php -r 'echo PHP_CONFIG_FILE_SCAN_DIR;')/zz-event.ini"
```

- **Um `memory_limit` que caiba as conexões.** Cada conexão WebSocket ocupa cerca de 24 KB, então os 128 MB padrão do CLI comportam um pouco menos de 5.000. Para 10.000, dê 512 MB ou mais (`php -d memory_limit=512M server.php start`).
- **Descritores de arquivo suficientes:** `ulimit -n` acima do número de conexões, mais alguns para o socket de escuta e arquivos.

## Resultados

Um processo, clientes WebSocket do `socket.io-client` oficial, medidos com `tests/Load/run.sh`:

| Clientes | Event loop | Memória por conexão | RSS total | Conectar todos | Ecos por segundo (p50 / p99) | Entregas de broadcast por segundo |
|---|---|---|---|---|---|---|
| 900 | `select()` | 22 KB | 33 MB | 0,25 s | 56.000 (14 / 44 ms) | 324.000 |
| 1.000 | `event` | 23 KB | 38 MB | 0,25 s | 59.000 (15 / 30 ms) | 346.000 |
| 5.000 | `event` | 24 KB | 129 MB | 0,71 s | 74.000 (61 / 116 ms) | 234.000 |
| 10.000 | `event` | 24 KB | 247 MB | 1,29 s | 67.000 (141 / 215 ms) | 206.000 |

- **A memória** cresce de forma linear: cerca de 24 KB por conexão, tanto no heap do PHP quanto na memória residente do processo. Disso, cerca de 14 KB são objetos deste pacote (a sessão Engine.IO, a requisição, o client e o socket) e o resto é a conexão do Workerman e os seus buffers.
- **Os ecos** são acknowledgements: cada cliente envia 20, um após o outro, todos os clientes ao mesmo tempo. A latência é sob essa carga total, não a de um servidor ocioso.
- **Os broadcasts** são 50 eventos de 100 bytes para todos os clientes. O tempo para emiti-los é quase o tempo até todos serem entregues, então o limite é a CPU de um processo: mais processos, com `ChannelAdapter` ou `RedisAdapter`, multiplicam isso (veja [Vários processos](multiple-processes.pt-BR.md)).

## Comparado ao servidor Node.js da referência

O mesmo teste de carga contra o servidor oficial `socket.io` 4.8.4 (`tests/Load/node`), um processo com os mesmos handlers, na mesma máquina, na mesma sessão:

| Clientes | Servidor | RSS total | Ecos por segundo (p50 / p99) | Entregas de broadcast por segundo |
|---|---|---|---|---|
| 1.000 | Node.js | 100 MB | 62.000 (15 / 39 ms) | 518.000 |
| 1.000 | este servidor | 38 MB | 59.000 (15 / 30 ms) | 346.000 |
| 5.000 | Node.js | 201 MB | 89.000 (50 / 106 ms) | 572.000 |
| 5.000 | este servidor | 129 MB | 74.000 (61 / 116 ms) | 234.000 |
| 10.000 | Node.js | 288 MB | 91.000 (105 / 139 ms) | 520.000 |
| 10.000 | este servidor | 247 MB | 67.000 (141 / 215 ms) | 206.000 |

- **A memória é menor em todos os tamanhos.** Este servidor começa menor (cerca de 13 MB contra os 64 MB do Node) e fica abaixo dele: 14% menos com 10.000 clientes.
- **Os acknowledgements empatam** com 1.000 clientes; o Node responde cerca de 20% mais por segundo com 5.000 e 35% mais com 10.000.
- **No broadcast o Node lidera**, de 1,5 vez com 1.000 clientes até 2,5 vezes com 10.000. O `emit()` do Node retorna assim que os frames entram na fila, e o event loop dele os escreve depois; aqui cada frame é escrito no seu socket durante o emit, uma chamada de sistema por cliente.

As rodadas variam até cerca de 10% de uma sessão para outra, nos dois servidores, então compare números da mesma sessão.

```sh
SERVER=node CLIENTS=10000 tests/Load/run.sh
```

## Para onde vão o tempo e a memória

Perfilado com o mesmo servidor, uma conexão ou um broadcast por vez:

- **Uma entrega de broadcast** custa cerca de 1,2 µs neste pacote (as salas, as verificações por socket e entregar o pacote codificado ao transporte) e cerca de 3,5 µs no `send()` do Workerman e na escrita do kernel. Montar o frame WebSocket leva 0,03 µs: escrever os frames direto, pulando o protocolo do Workerman, foi testado e não ganhou nada.
- **Os listeners de eventos** eram o maior custo repetido por conexão: cada sessão, client e socket tem um emitter, e guardar cada listener no seu próprio array aninhado custava cerca de 1,2 KB por listener. Agora eles ficam em arrays planos por emitter, o que levou a memória por conexão de 30 KB para 24 KB e aumentou os ecos por segundo em cerca de 10%, com os broadcasts iguais.

## Ambiente de teste

Todos os números acima vêm de uma máquina, com o servidor e os clientes no Docker no mesmo host:

| | |
|---|---|
| Máquina | Mac16,7 (MacBook Pro), Apple M4 Pro, 14 núcleos, 24 GB de memória, macOS 27.2 |
| Docker | Docker Desktop, engine 29.9.2; VM Linux com 14 CPUs e 8 GB, kernel 7.0.14 |
| Servidor PHP | PHP 8.1.34 (a imagem do projeto), Workerman 5.2.3, `event` 3.1.4 no `LOOP=event`, `memory_limit=1G` |
| Servidor Node.js | Node.js 24.21.0, `socket.io` 4.8.4 |
| Clientes | Node.js 24.21.0, `socket.io-client` 4.8.4, só WebSocket, num contêiner próprio que compartilha a rede do servidor |

Os seus números vão ser outros; a forma, não.

## Medindo na sua máquina

```sh
tests/Load/run.sh                              # 500 clientes no select()
LOOP=event CLIENTS=10000 tests/Load/run.sh     # a extensão event, 10.000 clientes
```

`CLIENTS`, `ROUNDS` (ecos por cliente), `MESSAGES` (broadcasts) e `SIZE` (bytes por broadcast) ajustam a rodada. Cada rodada imprime uma linha de JSON e um resumo.
