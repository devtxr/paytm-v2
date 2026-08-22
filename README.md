# Paytm Merchant Payment Verifier & UPI QR Generator (Single-File PHP)

**Pura code ek single `index.php` file me hai!** Apne kisi bhi PHP hosting par bas `index.php` file upload karein aur chalayein.

---

## ⚡ Quick Features

1. **UPI QR Code Generator** — UPI ID, Amount aur Note se QR code generate karein.
2. **Auto Random Order ID** — Order ID blank chodne par unique ID (`ORD_<timestamp>_<random>`) auto-generate ho jayegi.
3. **Paytm Payment Status Check** — Paytm ke `/order/status` API se automatic transaction verification (Merchant key ki zaroorat nahi — sha256 checksum auto-compute hota hai).
4. **Auto-Verify Polling** — Frontend / Website pe real-time payment auto-verification polling.
5. **Built-in Telegram Bot Webhook** — Single `index.php` file se hi Telegram Bot handle ho jata hai.

---

## 🚀 Installation & Setup on PHP Hosting

1. Apne PHP hosting / cPanel File Manager me jayein.
2. `index.php` file ko upload karein.
3. Aapka endpoint ready hai: `https://yourdomain.com/index.php`

---

## 🤖 Telegram Bot Integration (Webhook Method)

Aapko extra bot code likhne ki zaroorat nahi hai. Apne Telegram bot ka Webhook is format me set karein:

```http
https://api.telegram.org/bot<YOUR_BOT_TOKEN>/setWebhook?url=https://YOUR_DOMAIN.com/index.php?bot_token=<YOUR_BOT_TOKEN>&mid=<YOUR_PAYTM_MID>&upi_id=<YOUR_UPI_ID>&amount=100.00
```

### Flow:
1. User bot ko `/start` ya `/pay` bhejega.
2. Bot random Order ID ke sath UPI QR Code generate karke user ko send karega.
3. Bot background me automatic payment check karega (polling) aur payment milte hi **"Payment Received Successfully"** msg bhej dega.

---

## 🌐 Website Integration Guide (JavaScript)

Apni website par QR aur auto-verification lagane ke liye is code ko use karein:

```html
<!DOCTYPE html>
<html>
<head>
    <title>Paytm Checkout</title>
</head>
<body>
    <div id="payment-box">
        <h2>Pay via UPI QR</h2>
        <img id="qr-img" src="" style="display:none; max-width: 250px;" />
        <p id="status">Click pay to generate QR</p>
        <button onclick="startPayment()">Pay ₹100 Now</button>
    </div>

<script>
async function startPayment() {
    const MID = "YOUR_PAYTM_MID";
    const UPI_ID = "merchant@paytm";
    const AMOUNT = "100.00";

    document.getElementById('status').innerText = "Generating QR Code...";

    // 1. Generate QR Code
    const res = await fetch('index.php?action=generate_qr', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ upi_id: UPI_ID, amount: AMOUNT })
    });
    const data = await res.json();

    if (data.status === 'success') {
        document.getElementById('qr-img').src = data.qr_url;
        document.getElementById('qr-img').style.display = 'block';
        document.getElementById('status').innerText = "Scan QR to pay. Auto-checking payment...";

        const orderId = data.order_id;

        // 2. Auto-Verification Polling
        const poll = setInterval(async () => {
            try {
                const checkRes = await fetch('index.php?action=verify', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ mid: MID, order_id: orderId, env: 'prod' })
                });
                const checkData = await checkRes.json();

                if (checkData.status === 'success' && checkData.verified) {
                    clearInterval(poll);
                    document.getElementById('status').innerText = "✅ Payment Successful!";
                    alert("Payment Verified!");
                }
            } catch (e) {
                console.log("Polling error, retrying...");
            }
        }, 4000); // Har 4 sec me status verify hoga
    }
}
</script>
</body>
</html>
```

---

## 📡 API Endpoints

### 1. Generate QR Code
- **URL:** `index.php?action=generate_qr`
- **Method:** `POST` or `GET`
- **JSON Body:** `{"upi_id": "merchant@paytm", "amount": "100.00", "note": "Test Payment"}`
- **Response:**
```json
{
  "status": "success",
  "order_id": "ORD_1787413156_1234",
  "upi_uri": "upi://pay?pa=merchant%40paytm&pn=Merchant&tr=ORD_1787413156_1234&cu=INR&am=100.00",
  "qr_url": "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=..."
}
```

### 2. Verify Payment Status
- **URL:** `index.php?action=verify`
- **Method:** `POST` or `GET`
- **JSON Body:** `{"mid": "YOUR_MID", "order_id": "ORD_1787413156_1234", "env": "prod"}`
- **Response:**
```json
{
  "status": "success",
  "verified": true,
  "endpoint": "https://securegw.paytm.in/order/status",
  "data": {
    "STATUS": "TXN_SUCCESS",
    "TXNID": "2026...",
    "ORDERID": "ORD_1787413156_1234"
  }
}
```
