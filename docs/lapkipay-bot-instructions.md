# Инструкция для бота LapkiPay: отправка транзакций в Sterling Wallet

После каждого запуска (когда транзакции спарсены и лежат в SQLite) бот отправляет их на наш сервер одним HTTP-запросом. Расчёт комиссий делает наша система, бот только присылает данные.

## 1. Настройки в `.env` бота

```env
WALLET_API_URL=https://<домен нашей системы>/api/v1/transfers/lapkipay
WALLET_API_KEY=<токен, выдаст администратор: BOT_REPORTS_API_KEY>
```

Токен хранится только в `.env`, в логи и Telegram он не попадает.

## 2. Запрос

`POST {WALLET_API_URL}`, заголовки:

```
Authorization: Bearer {WALLET_API_KEY}
Content-Type: application/json
Accept: application/json
```

Тело:

```json
{
    "transactions": [
        {
            "id": "TR1316409407155732480",
            "company": "BASILDON LIMITED",
            "name": "Marina Levencova",
            "type": "Incoming transfer",
            "fromAccount": "LT133250019897585876",
            "bicSwift": "REVOLT21XXX",
            "amount": 5.0,
            "currency": "EUR",
            "status": "Completed",
            "reference": "Sent from Revolut",
            "date": "12/10/2026 17:21:00"
        }
    ],
    "balances": [
        {
            "company": "BASILDON LIMITED",
            "currency": "EUR",
            "available": 5.0,
            "hold": 0
        }
    ]
}
```

| Поле                                           | Обязательно | Комментарий                                                              |
| ---------------------------------------------- | ----------- | ------------------------------------------------------------------------ |
| `id`                                           | да          | номер транзакции LapkiPay (`TR…`), по нему система не создаёт дубликатов |
| `company`                                      | да          | название компании **точно как в кабинете**, по нему ищется MID           |
| `amount`                                       | да          | число, не строка с символом валюты                                       |
| `currency`                                     | да          | три буквы, `EUR`                                                         |
| `status`                                       | да          | как в кабинете: `Completed`, `Pending`                                   |
| `date`                                         | да          | `дд/мм/гггг чч:мм:сс`, время в **UTC**                                   |
| `type`                                         | нет         | по умолчанию считается `Incoming transfer`                               |
| `name`, `fromAccount`, `bicSwift`, `reference` | нет         | сохраняются в операции для справки                                       |
| `rawDetails`                                   | нет         | **не отправляйте**, лишний объём                                         |

`balances` можно не отправлять. Если отправляете: по одной записи на компанию и валюту, `available` — доступный остаток, `hold` — холд.

## 3. Что отправлять

- Отправляйте **все** транзакции, которые бот увидел в таблице, а не только новые: сервер сам пропускает дубликаты и обновляет записи. Так `Pending`, ставшие `Completed`, дойдут сами.
- Не отфильтровывайте `Pending` и другие статусы: сервер учитывает только `Completed` + `Incoming transfer`, остальное игнорирует.
- Максимум **5000** транзакций в запросе. Если больше, режьте на пакеты по 1000.
- Отправку делайте после парсинга, даже если новых транзакций нет: так видно, что бот отработал.

## 4. Ответ сервера

```json
{
    "status": "ok",
    "rows": 12,
    "skipped_rows": 0,
    "unknown_mids": [],
    "warnings": [],
    "balances_updated": 1
}
```

| Код                      | Что значит                                          | Что делать                                    |
| ------------------------ | --------------------------------------------------- | --------------------------------------------- |
| `200`                    | принято                                             | записать в лог `rows`, `warnings`             |
| `401`                    | неверный токен                                      | проверить `WALLET_API_KEY`, не повторять      |
| `422`                    | неверный формат пакета (в теле сказано, какое поле) | исправить данные                              |
| `429`, `5xx`, обрыв сети | временная ошибка                                    | повторить до 3 раз с паузой 5, 30, 120 секунд |

Если в ответе `unknown_mids` не пустой или `warnings` содержит «not linked», это значит, что для `company` не заведён MID. Бот присылает предупреждение в Telegram с этим названием, администратор заводит MID. Повторно отправлять ничего не нужно: данные уже сохранены.

## 5. Пример на Node (TypeScript)

```ts
type Tx = {
    id: string;
    company: string;
    name?: string;
    type?: string;
    fromAccount?: string;
    bicSwift?: string;
    amount: number;
    currency: string;
    status: string;
    reference?: string;
    date: string;
};

async function sendToWallet(transactions: Tx[], balances: object[] = []) {
    const url = process.env.WALLET_API_URL!;
    const headers = {
        Authorization: `Bearer ${process.env.WALLET_API_KEY}`,
        'Content-Type': 'application/json',
        Accept: 'application/json',
    };

    for (let i = 0; i < transactions.length || i === 0; i += 1000) {
        const body = JSON.stringify({
            transactions: transactions.slice(i, i + 1000),
            ...(i === 0 && { balances }),
        });

        for (const waitSeconds of [0, 5, 30, 120]) {
            if (waitSeconds)
                await new Promise((r) => setTimeout(r, waitSeconds * 1000));
            const res = await fetch(url, { method: 'POST', headers, body });

            if (res.ok) {
                const result = await res.json();
                console.log(
                    'wallet:',
                    result.rows,
                    'rows',
                    result.warnings,
                    result.unknown_mids,
                );
                break;
            }
            if (res.status === 401 || res.status === 422)
                throw new Error(`wallet ${res.status}: ${await res.text()}`);
            // 429 / 5xx: try again
        }
    }
}
```

Сопоставление с полями вашей базы: `id, company, name, type, fromAccount, bicSwift, amount, currency, status, reference, date` берутся как есть, `rawDetails`, `isSentToTelegram`, `createdAt`, `updatedAt` не отправляются.

## 6. Проверка вручную

```bash
curl -X POST "$WALLET_API_URL" \
  -H "Authorization: Bearer $WALLET_API_KEY" -H "Content-Type: application/json" \
  -d '{"transactions":[{"id":"TEST1","company":"BASILDON LIMITED","amount":5,"currency":"EUR","status":"Completed","date":"12/10/2026 17:21:00"}]}'
```

Ожидаемый ответ: `{"status":"ok","rows":1,...}`. Тестовую транзакцию потом удалит администратор (она попадёт в отчёт мерчанта), поэтому для проверки используйте мерчанта в статусе test.
