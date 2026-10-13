# LapkiPay (C2B) — приём транзакций от бота

Бот заходит в кабинет LapkiPay Business раз в день (капчу проходят вручную), собирает транзакции и отправляет их в систему одним пакетом. Сам расчёт комиссий делает система по тарифу мерчанта; расчёт бота остаётся контрольным числом.

## Настройка в админке

1. **Провайдер** `lapkipay`: тип Acquirer (bank), **Product = Bank transfers (C2B)**, формат отчёта `lapkipay`, часовой пояс **UTC**, коннектор не нужен. Себестоимость: «Bank transfer (C2B)» % и «Successful sale» фикс. Что провайдер не берёт — **N/A**.
2. **Мерчант**: отдельный блок **Bank transfers (C2B)** со своим тарифом: Processing fee % (N/A, если C2B не предлагается), Success fee, Refund fee, Conversion fee %, Settlement FX markup %. Карточный тариф (Card tariff) на C2B не влияет. Резерв общий для всех продуктов мерчанта; его можно поставить в **N/A** (не держится).
3. **MID** (можно несколько на мерчанта): номер = `КОМПАНИЯ ВАЛЮТА` заглавными, например `BASILDON LIMITED EUR`; банк — LapkiPay, шлюза нет. Если MID не заведён, первый пакет создаст его сам в статусе `review`. Пока у мерчанта нет C2B-тарифа, отчёт по такому MID блокируется с причиной «no Bank transfers (C2B) tariff».

## Endpoint

`POST /api/v1/transfers/{provider code}` (для LapkiPay `/api/v1/transfers/lapkipay`), заголовок `Authorization: Bearer {BOT_REPORTS_API_KEY}`.

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

- Обязательны `id`, `company`, `amount`, `currency`, `status`, `date`; до 5000 транзакций в пакете. `balances` необязательны.
- В расчёт идут только `Completed` и `Incoming transfer`. `Pending` пропускается и придёт снова, когда станет `Completed`.
- Время `date` (`d/m/Y H:i:s`) считается в UTC. Повторная отправка не создаёт дубликатов (ключ — `id`).
- Остальные поля (плательщик, IBAN, BIC, назначение) сохраняются в `raw` операции.
- Ответ: `rows`, `skipped_rows`, `unknown_mids`, `warnings`, `balances_updated`.
- `balances` сохраняются на MID (`reported_balance`, `reported_hold`) для сверки.
