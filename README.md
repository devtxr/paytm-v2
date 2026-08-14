# Paytm Merchant Payment Verifier — Cloudflare Worker

Ek single-file Cloudflare Worker jo Paytm ke `/order/status` API se
payment verify karta hai — **sirf MID + Order ID** se, bina merchant key ke.

## Files

- `worker.js` — pura worker code (UI + API dono isi me hai)
- `wrangler.toml` — deploy config

## Endpoints

| Method | Path            | Description                            |
| ------ | --------------- | -------------------------------------- |
| GET    | `/`             | HTML test page (MID/OrderID form)      |
| GET    | `/api/verify`   | `?mid=&order_id=&env=prod\|stage`       |
| POST   | `/api/verify`   | JSON `{mid, order_id, env}`            |
| GET    | `/api/generate-qr` | `?upi_id=&amount=&order_id=` |
| POST   | `/api/generate-qr` | JSON `{upi_id, amount, order_id, name, note}` |
| GET    | `/api/health`   | Health check                           |

CORS enabled (`*`) — kisi bhi website ya app se call kar sakte ho.

## Deploy karne ke 2 tareeke

### Method 1 — Cloudflare Dashboard (bina CLI ke, sabse easy)

1. https://dash.cloudflare.com → Workers & Pages → **Create** → **Create Worker**
2. Naam do (e.g. `paytm-verifier`) → **Deploy**
3. **Edit code** → puri file ka default content delete karo
4. `worker.js` ka pura content paste karo → **Save and deploy**
5. Aapka URL: `https://paytm-verifier.<your-subdomain>.workers.dev`

### Method 2 — Wrangler CLI

```bash
npm install -g wrangler
wrangler login
cd /path/to/this/folder
wrangler deploy
```

## Usage

### Browser (test page)
Bas apna Worker URL kholo — form aa jayega.

### cURL — GET
```bash
curl "https://paytm-verifier.<your>.workers.dev/api/verify?mid=YOUR_MID&order_id=YOUR_ORDER&env=prod"
```

### cURL — POST
```bash
curl -X POST "https://paytm-verifier.<your>.workers.dev/api/verify" \
  -H "Content-Type: application/json" \
  -d '{"mid":"YOUR_MID","order_id":"YOUR_ORDER","env":"prod"}'
```

### Generate QR — GET
```bash
curl "https://paytm-verifier.<your>.workers.dev/api/generate-qr?upi_id=merchant@paytm&amount=100.50&order_id=ORDER123"
```

### Generate QR — POST
```bash
curl -X POST "https://paytm-verifier.<your>.workers.dev/api/generate-qr" \
  -H "Content-Type: application/json" \
  -d '{"upi_id":"merchant@paytm","amount":"100.50","order_id":"ORDER123","name":"My Store","note":"Payment for order 123"}'
```


### JavaScript (from any website)
```js
const res = await fetch("https://paytm-verifier.<your>.workers.dev/api/verify", {
  method: "POST",
  headers: { "Content-Type": "application/json" },
  body: JSON.stringify({ mid: "YOUR_MID", order_id: "YOUR_ORDER", env: "prod" })
});
const data = await res.json();
console.log(data.verified, data.data);
```

## Response format

```json
{
  "status": "success",           // "success" | "failed" | "error"
  "verified": true,
  "endpoint": "https://securegw.paytm.in/order/status",
  "data": {
    "TXNID": "...",
    "BANKTXNID": "...",
    "ORDERID": "...",
    "TXNAMOUNT": "100.00",
    "STATUS": "TXN_SUCCESS",
    "RESPCODE": "01",
    "RESPMSG": "Txn Success",
    "MID": "...",
    "...": "..."
  }
}
```

Agar payment nahi hua ya invalid order id ho to `status: "failed"` aa jayega
Paytm ke error message ke saath.

## Notes

- **No database, no storage** — pure stateless Worker.
- Multiple users ek hi endpoint use kar sakte hain, sabke apne MID + Order ID ke saath.
- Environment: `prod` (default) ya `stage` request me pass karo.
- Worker free tier: 100k requests/day.
