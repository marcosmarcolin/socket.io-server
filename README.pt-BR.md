# socket.io-server

[English](README.md) · **Português (Brasil)**

Um servidor [Socket.IO](https://socket.io) v4 para PHP, construído sobre o [Workerman](https://github.com/walkor/workerman).

> **Em desenvolvimento.** Ainda não pode ser usado: a camada Engine.IO está sendo construída e ainda não há servidor para rodar.

## Escopo

- Implementa os protocolos Engine.IO v4 e Socket.IO v5, para o `socket.io-client` v3 e v4.
- Transportes: HTTP long-polling e WebSocket, com o upgrade do polling para WebSocket.
- PHP >= 8.1 e Workerman 5. Não exige framework.

Para o `socket.io-client` v1 e v2, use o [workerman/phpsocket.io](https://github.com/walkor/phpsocket.io).

## Situação

| Etapa | Estado |
|---|---|
| Parser de pacotes Engine.IO | Pronto |
| Sessão Engine.IO: heartbeat, buffer de escrita, transportes polling e WebSocket, upgrade | Pronto |
| Servidor Engine.IO: validação de requests, sessões, CORS | Pronto |
| Integração com o Workerman: uma porta para HTTP e WebSocket, timers, adaptadores de conexão | Pronto |
| A suíte oficial do Engine.IO v4 rodando no CI (25 de 25 passando) | Pronto |
| Parser Socket.IO v5: encoder, decoder, anexos binários | Pronto |
| Servidor Socket.IO: conexão, namespaces, middlewares, eventos, acks, salas | Pronto |
| Broadcast para salas e namespaces | Próxima |
| A suíte oficial do Socket.IO v5 rodando no CI | Planejado |
| Broadcast entre processos com `workerman/channel` | Planejado |

## Desenvolvimento

Tudo roda no Docker; não é preciso ter PHP nem Composer na máquina.

```sh
docker compose run --rm php composer install
docker compose run --rm php vendor/bin/phpunit
docker compose run --rm php composer lint      # phpcs (PSR-12) e PHPStan (nível max)
tests/Compliance/engine-io.sh                  # a suíte oficial do Engine.IO v4
```

### Varredura de segredos

O [gitleaks](https://github.com/gitleaks/gitleaks) roda no Docker e bloqueia commits que adicionam segredos. Ative o hook uma vez por clone:

```sh
git config core.hooksPath .githooks
```

O Docker precisa estar rodando para commitar. O CI também varre o histórico inteiro em todo pull request.

## Licença

MIT
