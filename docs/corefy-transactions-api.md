# Payment gateway API: reading your transactions

This guide shows how to read your card payments from the gateway API with `curl`. It is read-only: you can list and filter payments, nothing can be changed.

- **Base URL:** `https://api.sterling-pay.com`
- **Endpoint:** `GET /payment-invoices`
- **Authentication:** HTTP Basic. The user name is your **commerce account id** (it starts with `coma_`), the password is the **API key** of that account. Each commerce account has its own key. Keys are issued to you by your account manager; keep them secret and never put them in client-side code.
- **Header:** `Accept: application/vnd.api+json`
- **Format:** JSON (JSON:API style): the payments are in `data[]`, the link to the next page is in `links.next`.

Set your credentials once in the shell, so they do not appear in your history or scripts:

```bash
export CMA="coma_XXXXXXXXXXXXXXXX"   # your commerce account id
export KEY="your-api-key"            # the API key of that account
export API="https://api.sterling-pay.com"
```

## 1. First request

The latest 5 payments of the account:

```bash
curl -sS -u "$CMA:$KEY" \
  -H "Accept: application/vnd.api+json" \
  -G "$API/payment-invoices" \
  --data-urlencode "sort=-created" \
  --data-urlencode "page[size]=5"
```

A `401` answer means the id or the key is wrong:
`{"errors":[{"status":"Invalid credentials.","code":"401"}]}`.

## 2. All successful payments of a day

Filter by **processing time**. Times are Unix timestamps in seconds (**UTC**).

```bash
# 10 November 2026, 00:00:00 to 23:59:59 UTC
FROM=$(date -u -d "2026-11-10 00:00:00" +%s)
TO=$(date -u -d "2026-11-11 00:00:00" +%s); TO=$((TO-1))

curl -sS -u "$CMA:$KEY" \
  -H "Accept: application/vnd.api+json" \
  -G "$API/payment-invoices" \
  --data-urlencode "filter[processed][gte]=$FROM" \
  --data-urlencode "filter[processed][lte]=$TO" \
  --data-urlencode "sort=-created" \
  --data-urlencode "page[size]=100"
```

For the day in another time zone, convert its start and end to timestamps, for example for Riga (UTC+2 in winter, UTC+3 in summer):

```bash
FROM=$(TZ=Europe/Riga date -d "2026-11-10 00:00:00" +%s)
TO=$(TZ=Europe/Riga date -d "2026-11-11 00:00:00" +%s); TO=$((TO-1))
```

## 3. Filters

| Parameter                                          | Meaning                                                                        |
| -------------------------------------------------- | ------------------------------------------------------------------------------ |
| `filter[processed][gte]`, `filter[processed][lte]` | Processing time, from / to (Unix seconds, inclusive)                           |
| `filter[created][gte]`, `filter[created][lte]`     | Creation time, from / to (Unix seconds, inclusive)                             |
| `filter[status]`                                   | Status of the payment: `processed` (successful) or `process_failed` (declined) |
| `sort`                                             | `-created` newest first, `created` oldest first                                |
| `page[size]`                                       | Payments per page. `100` works; without it you get 20                          |

Notes:

- A **declined** payment has no processing time. To list declines filter by creation time and status:

```bash
curl -sS -u "$CMA:$KEY" \
  -H "Accept: application/vnd.api+json" \
  -G "$API/payment-invoices" \
  --data-urlencode "filter[status]=process_failed" \
  --data-urlencode "filter[created][gte]=$FROM" \
  --data-urlencode "filter[created][lte]=$TO" \
  --data-urlencode "page[size]=100"
```

- A payment made just before midnight can be processed just after it. If you book payments to the day they were **made**, use `filter[created]` together with `filter[status]=processed`.

## 4. Fields of a payment

Each item of `data[]` has an `id` (`cpi_…`, the payment reference you also see in your reports as `sp_id`) and `attributes`:

| Field                                            | Meaning                                                                  |
| ------------------------------------------------ | ------------------------------------------------------------------------ |
| `attributes.created`                             | Creation time, Unix seconds (UTC)                                        |
| `attributes.processed`                           | Processing time, Unix seconds (UTC); not present on declined payments    |
| `attributes.status`                              | `processed` or `process_failed`                                          |
| `attributes.resolution`                          | Result text, `ok` for a successful payment, otherwise the decline reason |
| `attributes.amount`, `attributes.currency`       | Amount and currency of the payment                                       |
| `attributes.reference_id`                        | Your own reference of the order                                          |
| `attributes.payload.payment_card.mask`           | Masked card number                                                       |
| `attributes.payload.payment_card.issuer_country` | Country of the issuing bank                                              |
| `attributes.payload.payment_card.issuer_name`    | Issuing bank                                                             |

Other attributes may be present; ignore the ones you do not need.

## 5. Pages

At most `page[size]` payments are returned per request. The address of the next page is in `links.next`. **Call it exactly as given**: it already holds your filters and the page number, so do not add parameters to it. When there is no next page, `links.next` is `null`. Stop also when a page brings no payment you have not seen yet.

```bash
# All successful payments of the day as CSV: id, created (UTC), amount, currency, card, resolution
url="$API/payment-invoices?filter%5Bprocessed%5D%5Bgte%5D=$FROM&filter%5Bprocessed%5D%5Blte%5D=$TO&sort=-created&page%5Bsize%5D=100"
: > payments.csv; : > seen.txt

for page in $(seq 1 500); do
  [ -z "$url" ] && break
  curl -sS -u "$CMA:$KEY" -H "Accept: application/vnd.api+json" "$url" > page.json

  jq -r '.data[] | [.id, (.attributes.created | todate), .attributes.amount, .attributes.currency,
        .attributes.payload.payment_card.mask, .attributes.resolution] | @csv' page.json > new.csv
  # keep only the payments not seen before; stop if there are none
  grep -v -F -f seen.txt new.csv > fresh.csv || true
  [ -s fresh.csv ] || break
  cat fresh.csv >> payments.csv
  cut -d, -f1 fresh.csv | tr -d '"' >> seen.txt

  next=$(jq -r '.links.next // empty' page.json)
  case "$next" in /*) url="$API$next" ;; *) url="$next" ;; esac
done
wc -l payments.csv
```

(`jq` is a small JSON tool: `apt install jq` or `brew install jq`.)

## 5. Daily check against your statement

1. Fetch the payments of the day as above and note the count and the total amount of `status=processed`.
2. Compare them with the day's report in your portal. Reporting days follow **Europe/Riga** time; the API works in Unix time, so use the Riga day boundaries from section 2.
3. A payment you find here but not in the report is listed by its `id` in the report's operations file (column `sp_id`).

## Good practice

- Keep the API key in an environment variable or a secret store, not in scripts or tickets. If a key is exposed, ask your account manager to replace it.
- Use `HTTPS` only. Request one day at a time with `page[size]=100`; do not poll more often than you need.
- Times are UTC in every response. Convert them to your own time zone when you show them.
- Amounts in the API are the payment amounts. Fees, reserves and payouts are in your settlement statements, not in the API.
