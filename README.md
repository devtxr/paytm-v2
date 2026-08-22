# Paytm Merchant Payment Verifier & UPI QR Generator (PHP & Cloudflare Worker)

Single-file PHP script (`index.php`) aur Cloudflare Worker (`worker.js`) dono ke dwara Paytm `/order/status` API se payment verification aur UPI QR Code generation karein.

- **No merchant key required** — CHECKSUMHASH self-computed hai (`SHA-256`).
- **Auto-generated Order ID** — Order ID blank chodne par unique ID (`ORD_<timestamp>_<random>`) apne aap generate hoti hai.
- **Auto-Payment Verification** — Payment hone par live status polling se automatic verify ho jata hai.

---

## 📁 Files in Repo / Zip

- `index.php` — Single-file PHP code (API endpoints, Telegram Bot Webhook, Website Checkout API & UI).
- `paytm-verifier.zip` — Direct downloadable ZIP for PHP hosting File Manager upload.
- `README.md` — Detailed step-by-step integration documentation for Telegram Bot & Website.

---

## 🚀 Step 1: Upload to PHP Hosting

1. Download `paytm-verifier.zip` or `index.php`.
2. Apne PHP hosting / cPanel File Manager me `public_html` (ya subfolder) me upload karein.
3. Aapka URL: `https://yourdomain.com/index.php`

---

## 🤖 Step 2: Telegram Bot Integration Guide

### Method A: Single-Line Webhook Setup (Easiest)

Aapko koi extra bot code host karne ki zaroorat nahi hai! Bas apne Telegram Bot ka Webhook is URL par set karein:

```http
https://api.telegram.org/bot<YOUR_BOT_TOKEN>/setWebhook?url=https://yourdomain.com/index.php?bot_token=<YOUR_BOT_TOKEN>&mid=<YOUR_PAYTM_MID>&upi_id=<YOUR_UPI_ID>&amount=100.00
```

#### Kaam kaise karta hai?
1. User bot ko `/start` ya `/pay` bhejega.
2. `index.php` random Order ID ke sath UPI QR Code generate karega aur user ko Telegram par photo bhejega.
3. `index.php` background me har 4 second par Paytm API call karke verify karega.
4. Payment successful hote hi user ko **"✅ Payment Received Successfully!"** message mil jayega.

---

### Method B: Custom PHP Telegram Bot Code (`bot.php`)

Agar aap apna standalone Telegram bot PHP script likhna chahte hain:

```php
<?php
// Your Telegram Bot Token & Settings
$botToken = "123456789:ABCdefGHIjklMNOpqrsTUVwxyZ";
$apiServerUrl = "https://yourdomain.com/index.php"; // URL to index.php
$paytmMID = "YOUR_PAYTM_MID";
$upiID = "merchant@paytm";

$update = json_decode(file_get_contents('php://input'), true);

if (isset($update['message'])) {
    $chatId = $update['message']['chat']['id'];
    $text = trim($update['message']['text'] ?? '');

    if ($text === '/start' || $text === '/pay') {
        $amount = "100.00"; // Amount in INR

        // 1. Request QR Code Generation from index.php
        $ch = curl_init($apiServerUrl . "?action=generate_qr");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array(
            "upi_id" => $upiID,
            "amount" => $amount,
            "note" => "Telegram Purchase"
        )));
        $qrResp = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (isset($qrResp['status']) && $qrResp['status'] === 'success') {
            $orderId = $qrResp['order_id'];
            $qrUrl = $qrResp['qr_url'];

            // 2. Send QR Photo to Telegram User
            $caption = "Scan & Pay ₹{$amount}\nOrder ID: {$orderId}\n\nChecking payment status automatically...";
            file_get_contents("https://api.telegram.org/bot{$botToken}/sendPhoto?chat_id={$chatId}&photo=" . urlencode($qrUrl) . "&caption=" . urlencode($caption));

            // 3. Auto-Verify Payment Loop (Polling every 4 seconds)
            $verified = false;
            for ($i = 0; $i < 30; $i++) { // Try 30 times (2 minutes timeout)
                sleep(4);

                $vCh = curl_init($apiServerUrl . "?action=verify");
                curl_setopt($vCh, CURLOPT_POST, true);
                curl_setopt($vCh, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($vCh, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
                curl_setopt($vCh, CURLOPT_POSTFIELDS, json_encode(array(
                    "mid" => $paytmMID,
                    "order_id" => $orderId,
                    "env" => "prod"
                )));
                $vResp = json_decode(curl_exec($vCh), true);
                curl_close($vCh);

                if (isset($vResp['status']) && $vResp['status'] === 'success' && $vResp['verified'] === true) {
                    $verified = true;
                    $txnId = $vResp['data']['TXNID'] ?? 'N/A';
                    $msg = "✅ Payment Received Successfully!\nOrder ID: {$orderId}\nTxn ID: {$txnId}";
                    file_get_contents("https://api.telegram.org/bot{$botToken}/sendMessage?chat_id={$chatId}&text=" . urlencode($msg));
                    break;
                }
            }

            if (!$verified) {
                file_get_contents("https://api.telegram.org/bot{$botToken}/sendMessage?chat_id={$chatId}&text=" . urlencode("❌ Payment verify nahi ho paya ya timeout ho gaya."));
            }
        }
    }
}
?>
```

---

## 🌐 Step 3: Website Integration Guide

Apni website (HTML / JS / PHP) par QR Checkout aur Auto-Verification lagane ke liye is snippet ko copy karein:

```html
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Paytm Payment Checkout</title>
    <style>
        body { font-family: sans-serif; text-align: center; padding: 40px; background: #0f172a; color: #fff; }
        .card { max-width: 380px; margin: 0 auto; background: #1e293b; padding: 24px; border-radius: 12px; }
        img { max-width: 240px; border-radius: 8px; margin: 16px 0; }
        button { background: #00d1b2; border: 0; padding: 12px 24px; color: #000; font-weight: bold; border-radius: 6px; cursor: pointer; }
    </style>
</head>
<body>

<div class="card">
    <h2>Scan & Pay</h2>
    <p id="amount-text">Amount: ₹100.00</p>
    <img id="qr-image" src="" style="display:none;" />
    <div id="status-msg">Click button below to pay</div>
    <br>
    <button id="pay-btn" onclick="startCheckout()">Pay ₹100 Now</button>
</div>

<script>
async function startCheckout() {
    const MID = "YOUR_PAYTM_MID";
    const UPI_ID = "merchant@paytm";
    const AMOUNT = "100.00";
    const API_URL = "index.php"; // Path to index.php on your server

    document.getElementById('status-msg').innerText = "Generating QR Code...";
    document.getElementById('pay-btn').style.display = 'none';

    try {
        // STEP 1: Call index.php to generate QR Code with auto random Order ID
        const res = await fetch(API_URL + '?action=generate_qr', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ upi_id: UPI_ID, amount: AMOUNT })
        });
        const data = await res.json();

        if (data.status === 'success') {
            document.getElementById('qr-image').src = data.qr_url;
            document.getElementById('qr-image').style.display = 'inline-block';
            document.getElementById('status-msg').innerText = "⏳ Scan QR with Paytm/GPay/PhonePe. Checking payment...";

            const orderId = data.order_id;

            // STEP 2: Live Auto-Verification Polling (checks every 4 seconds)
            const pollInterval = setInterval(async () => {
                try {
                    const checkRes = await fetch(API_URL + '?action=verify', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ mid: MID, order_id: orderId, env: 'prod' })
                    });
                    const checkData = await checkRes.json();

                    if (checkData.status === 'success' && checkData.verified) {
                        clearInterval(pollInterval);
                        document.getElementById('status-msg').innerText = "✅ PAYMENT SUCCESSFUL!";
                        document.getElementById('status-msg').style.color = "#3fb950";
                        alert("Payment Received! Order ID: " + orderId);
                    }
                } catch (e) {
                    console.log("Polling check retrying...");
                }
            }, 4000);
        } else {
            document.getElementById('status-msg').innerText = "Error generating QR code";
        }
    } catch (err) {
        document.getElementById('status-msg').innerText = "Network error";
    }
}
</script>

</body>
</html>
```

---

## 📡 API Endpoints Reference

| Endpoint | Method | Params | Description |
| --- | --- | --- | --- |
| `index.php?action=generate_qr` | `GET`/`POST` | `upi_id`, `amount`, `order_id` (optional), `note` | QR code & random order ID generate karta hai. |
| `index.php?action=verify` | `GET`/`POST` | `mid`, `order_id`, `env` (`prod`/`stage`) | Paytm API se payment verify karta hai. |
| `index.php?action=health` | `GET` | — | Health check return karta hai. |
