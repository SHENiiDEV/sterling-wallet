# FinBridges (C2B, банковские переводы)

Приём транзакций FinBridges для C2B-мерчантов. Вместо LapkiPay (его парсер и endpoint пока оставлены, чтобы не сломать уже заведённые MID).

## Что готово

- **Парсер `finbridges`:** читает ответ API как есть: `{"count": n, "list": [Transaction …]}`.
- **Какие операции считаются:** только `transactionType = DEPOSIT` со `status = COMPLETED`. `PENDING` и остальные статусы пропускаются (придут снова, когда станут `COMPLETED`). Возвраты, выводы, комиссии и прочие типы пока не считаются.
- **Поля:** MID = `walletId` (кошелёк на каждую валюту), сумма = `amountOrigCcy`, валюта = `origCcy`, ID = `transactionNumber`, время = `date` (Unix-время в секундах или миллисекундах, определяется само). Плательщик, банк, назначение, `fees` и остальное сохраняются в операции.
- **Endpoint:** `POST /api/v1/transfers/finbridges` с токеном бота, тело это ответ API (`list` обязателен). Дубликаты не создаются (ключ `transactionNumber`).

## Настройка в админке

1. **Провайдер** `finbridges`: тип Acquirer (bank), **Product = Bank transfers (C2B)**, формат отчёта `finbridges`, часовой пояс UTC.
2. **Мерчант**: блок Bank transfers (C2B) с тарифом.
3. **MID**: номер = `walletId` (например `CJPU1K100`), валюта кошелька, банк FinBridges, шлюза нет.

## Что ещё нужно для бота «раз в X минут»

Встроенный бот Laravel, который сам ходит в API, ждёт двух вещей из документации FinBridges:

1. как строится подпись (`x-app-access-ts`, `x-app-access-sig`, `x-app-access-token`);
2. метод списка транзакций кошелька за период: «Get transactions by external ID» ищет только по уже известному `externalTransactionId`.

Креды (`apiKey`, `apiSecret`, `clientId`, `walletId`) хранить только в аккаунте бота в Admin → Bots (шифруются), в репозиторий и `.env` их не класть.
