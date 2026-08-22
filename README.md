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
# Order ID optional hai - agar omit karoge toh random Order ID generate hoga
curl "https://paytm-verifier.<your>.workers.dev/api/generate-qr?upi_id=merchant@paytm&amount=100.50"
```

### Generate QR — POST
```bash
curl -X POST "https://paytm-verifier.<your>.workers.dev/api/generate-qr" \
  -H "Content-Type: application/json" \
  -d '{"upi_id":"merchant@paytm","amount":"100.50","name":"My Store","note":"Payment for order 123"}'
```

---

## 🤖 Telegram Bot Integration Guide

### 1. Python (python-telegram-bot / telebot)

```python
import requests
import time

def send_payment_qr(bot, chat_id, upi_id, amount, mid):
    # 1. Generate QR Code with random order_id
    res = requests.post("https://paytm-verifier.<your>.workers.dev/api/generate-qr", json={
        "upi_id": upi_id,
        "amount": str(amount),
        "note": "Telegram Purchase"
    }).json()

    order_id = res["order_id"]
    qr_url = res["qr_url"]

    # Send QR photo to user
    bot.send_photo(chat_id, photo=qr_url, caption=f"Scan & Pay ₹{amount}\nOrder ID: {order_id}\n\nChecking payment automatically...")

    # 2. Auto-Verify Payment Polling
    verified = False
    for _ in range(30):  # Check every 4 sec for 2 mins
        time.sleep(4)
        check = requests.post("https://paytm-verifier.<your>.workers.dev/api/verify", json={
            "mid": mid,
            "order_id": order_id,
            "env": "prod"
        }).json()

        if check.get("status") == "success" and check.get("verified"):
            verified = True
            bot.send_message(chat_id, f"✅ Payment Received successfully!\nTxn ID: {check['data'].get('TXNID')}")
            break

    if not verified:
        bot.send_message(chat_id, "❌ Payment failed or timed out.")
```

### 2. Node.js (telegraf / node-telegram-bot-api)

```javascript
const axios = require('axios');

async function createPayment(bot, chatId, upiId, amount, mid) {
  // 1. Generate QR
  const qrRes = await axios.post('https://paytm-verifier.<your>.workers.dev/api/generate-qr', {
    upi_id: upiId,
    amount: String(amount)
  });

  const { order_id, qr_url } = qrRes.data;

  await bot.sendPhoto(chatId, qr_url, {
    caption: `Pay ₹${amount}\nOrder ID: ${order_id}\n\nWaiting for payment auto-verification...`
  });

  // 2. Auto-Verify Polling
  const pollInterval = setInterval(async () => {
    try {
      const verifyRes = await axios.post('https://paytm-verifier.<your>.workers.dev/api/verify', {
        mid: mid,
        order_id: order_id,
        env: 'prod'
      });

      if (verifyRes.data.status === 'success' && verifyRes.data.verified) {
        clearInterval(pollInterval);
        bot.sendMessage(chatId, `✅ Payment Verified!\nOrder ID: ${order_id}`);
      }
    } catch (e) {
      console.error("Polling check failed", e);
    }
  }, 4000);
}
```

---

## 🌐 Website Integration Guide

### HTML / JS Frontend Implementation

```html
<div id="payment-box">
  <img id="qr-code-img" src="" style="display:none;" />
  <p id="status-message">Click pay to generate QR</p>
  <button onclick="startPayment()">Pay ₹100</button>
</div>

<script>
async function startPayment() {
  const MID = "YOUR_PAYTM_MID";
  const UPI_ID = "your-upi@paytm";
  const AMOUNT = "100.00";

  document.getElementById('status-message').innerText = "Generating QR...";

  // STEP 1: Generate QR Code (Order ID auto-generated)
  const qrRes = await fetch('https://paytm-verifier.<your>.workers.dev/api/generate-qr', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ upi_id: UPI_ID, amount: AMOUNT })
  });

  const qrData = await qrRes.json();
  if (qrData.status !== 'success') return alert('Error generating QR');

  document.getElementById('qr-code-img').src = qrData.qr_url;
  document.getElementById('qr-code-img').style.display = 'block';
  document.getElementById('status-message').innerText = "Scan QR to pay. Auto-checking payment...";

  const order_id = qrData.order_id;

  // STEP 2: Start Auto Payment Verification Polling
  const interval = setInterval(async () => {
    try {
      const verifyRes = await fetch('https://paytm-verifier.<your>.workers.dev/api/verify', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ mid: MID, order_id: order_id, env: 'prod' })
      });

      const verifyData = await verifyRes.json();
      if (verifyData.status === 'success' && verifyData.verified) {
        clearInterval(interval);
        document.getElementById('status-message').innerText = "✅ Payment Successful!";
        alert("Payment Received!");
      }
    } catch (err) {
      console.error(err);
    }
  }, 4000); // Check every 4 seconds
}
</script>
```

### Hosted Checkout UI `/pay`
Worker me built-in hosted payment page bhi available hai:
```
https://paytm-verifier.<your>.workers.dev/pay?upi_id=merchant@paytm&amount=100&mid=YOUR_PAYTM_MID
```
Aap browser me user ko seedha is URL par redirect bhi kar sakte hain.

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
