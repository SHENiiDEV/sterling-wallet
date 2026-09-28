# Sterling Wallet

Бэк-офис для мерчантов: отчёты эквайера → операции → дневной отчёт с прибылью → settlement в USDC. Мультивалютные MID (USD / EUR / GBP), встроенные боты для выгрузки отчётов, Document Center.

**Стек:** Laravel 13 · Inertia 3 · React 19 + TypeScript · Tailwind CSS 4 · shadcn/ui · MySQL 8 · Fortify (2FA, passkeys).

## Локальный запуск

Нужны PHP 8.3+, Composer, Node 22+, Docker (для MySQL).

```bash
cp .env.example .env
# задайте DB_PASSWORD и ADMIN_EMAIL / ADMIN_PASSWORD
docker compose up -d          # MySQL 8 на порту 3307
composer install
npm install
php artisan key:generate
php artisan migrate --seed    # создаёт супер-админа и статусы документов
composer run dev              # сервер + очередь + Vite
```

Если `ADMIN_PASSWORD` пустой, сидер сгенерирует пароль и один раз выведет его в консоль.

Публичной регистрации нет: пользователей создаёт администратор.

## Структура

| Где                                         | Что                                                            |
| ------------------------------------------- | -------------------------------------------------------------- |
| `resources/js/pages/welcome.tsx`            | Лендинг                                                        |
| `resources/js/pages/admin/*`                | Админ-панель (`/admin`, доступ только `super_admin` / `admin`) |
| `app/Http/Controllers/Admin`                | Контроллеры админки                                            |
| `app/Enums/UserRole.php`                    | Роли: `super_admin`, `admin`, `merchant`                       |
| `app/Http/Middleware/EnsureUserIsAdmin.php` | Вход в админку только для активных сотрудников                 |

### Document Center

`/admin/documents`: контракты, KYB-пакеты, соглашения и офферы.

- **Статусы** настраиваются в `/admin/document-statuses`: цвет, порядок, «по умолчанию», «финальный». Из коробки: Draft, WIP - work in progress, Passed to merchant, Signing, Signed, Cancelled.
- **Смена статуса** записывается в историю вместе с комментарием.
- **Файлы** хранятся на приватном диске (`storage/app/private/documents/{id}`), скачать их можно только через админку.
- **Просроченный документ** — прошёл срок, а статус ещё не финальный.

### Мерчанты и MID

- **Мерчант** (`merchants`) — клиент: один тариф (Visa/MC × EU/non-EU, фиксы success/decline/refund/CHB), политика резерва, крипто-провайдер, email для отчётов. В URL используется `public_id` (`mer_…`).
- **MID** (`merchant_mids`) — точка приёма: у каждого своя валюта (USD/EUR/GBP), эквайер, шлюз, лимит резерва и дата начала отчётов. Фиксированные комиссии считаются в валюте MID.
- **Резерв** — журнал `reserve_ledger_entries` по каждому MID (удержания +, возвраты/выплаты −), баланс = сумма.
- **Дневные отчёты** (`daily_report_tasks`) — по одному на MID и дату; суммы в валюте MID плюс пересчёт в базовую валюту (`STERLING_BASE_CURRENCY`, по умолчанию EUR) по зафиксированному курсу.
- **Классификация операций** — одно место: `MerchantOperation::classify()`. Коды чарджбэка задаются в `STERLING_CHARGEBACK_TRN_TYPES`.
- **Seed-фразы** кошельков хранятся зашифрованными; видеть и менять их может только `super_admin`, каждый просмотр пишется в `admin_audit_logs`.

## Проверки

```bash
php artisan test
vendor/bin/pint --test
npm run check && npm run types:check
```

## Дорожная карта

1. ~~Каркас, роли, лендинг, админ-панель, Document Center~~
2. ~~Модель данных: компании, мерчанты, MID с валютой, провайдеры, операции, дневные отчёты, курсы валют, журнал резерва~~
3. Ядро расчётов: сплиттер Cardaq, сверка Cardaq ↔ Corefy, генератор дневного отчёта
4. Боты: `cardaq-export`, `corefy-export` (Playwright + очереди), экран «Боты»
5. Settlement (мультивалютный), Report Control Center
6. Merchant Portal и логины компаний
7. Дашборд прибыли, делёжка, месячные отчёты
8. `madfin-export`, `oxen-transfer`
