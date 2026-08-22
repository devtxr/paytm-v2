<?php
/**
 * Paytm Merchant Payment Verifier & UPI QR Generator — Single-File PHP
 * --------------------------------------------------------------------
 * Features:
 *  1. QR Code Generation with auto-generated random Order ID if empty.
 *  2. Paytm Payment Verification via /order/status with self-computed SHA-256 checksum (No Merchant Key needed).
 *  3. Integrated Telegram Bot Webhook Handler (Set webhook to index.php?bot_token=YOUR_TOKEN&mid=YOUR_MID&upi_id=YOUR_UPI).
 *  4. HTML Web UI for live testing & auto-verification polling & step-by-step API integration guides.
 */

// Enable CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Helper to send JSON response
function json_response($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// Paytm Payment Status Verification Function
function verify_paytm($mid, $order_id, $env = 'prod') {
    $endpoint = ($env === 'stage')
        ? "https://securegw-stage.paytm.in/order/status"
        : "https://securegw.paytm.in/order/status";

    $params = array("MID" => $mid, "ORDERID" => $order_id);
    ksort($params);
    $sortedQS = "";
    foreach ($params as $k => $v) {
        $sortedQS .= $k . "=" . $v . "&";
    }

    $checksum = hash("sha256", $sortedQS . $order_id);

    $post_data = array(
        "CHECKSUMHASH" => $checksum,
        "MID" => $mid,
        "ORDERID" => $order_id
    );

    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Cache-Control: no-cache'));
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $resp = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return array("status" => "error", "message" => "cURL Error: " . $err);
    }

    $data = json_decode($resp, true);
    if (!$data) {
        return array("status" => "error", "message" => "Invalid JSON from Paytm", "raw" => $resp);
    }

    $verified = (isset($data['STATUS']) && $data['STATUS'] === 'TXN_SUCCESS');
    return array(
        "status" => $verified ? "success" : "failed",
        "verified" => $verified,
        "endpoint" => $endpoint,
        "data" => $data
    );
}

// Function to generate UPI QR Data & URL
function generate_upi_qr($upi_id, $amount = '', $order_id = '', $name = 'Merchant', $note = '') {
    if (empty($order_id)) {
        $order_id = "ORD_" . time() . "_" . rand(1000, 9999);
    }

    $query = array(
        "pa" => $upi_id,
        "pn" => $name,
        "tr" => $order_id,
        "cu" => "INR"
    );
    if (!empty($amount)) $query["am"] = $amount;
    if (!empty($note)) $query["tn"] = $note;

    $upi_uri = "upi://pay?" . http_build_query($query);
    $qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($upi_uri);

    return array(
        "status" => "success",
        "order_id" => $order_id,
        "upi_uri" => $upi_uri,
        "qr_url" => $qr_url
    );
}

// Route API requests & Webhook logic
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$bot_token = $_GET['bot_token'] ?? $_POST['bot_token'] ?? '';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Parse input payload
$input_data = array();
$raw_input = file_get_contents('php://input');
if (!empty($raw_input)) {
    $json = json_decode($raw_input, true);
    if (is_array($json)) {
        $input_data = $json;
    }
}
$merged_params = array_merge($_GET, $_POST, $input_data);

// Legacy JsonData compatibility
if (isset($_POST['JsonData'])) {
    $legacy = json_decode($_POST['JsonData'], true);
    if (is_array($legacy)) {
        $merged_params = array_merge($merged_params, $legacy);
    }
}

// Handle Telegram Bot Webhook
if (!empty($bot_token) || $action === 'telegram_webhook') {
    $update = $input_data;
    if (isset($update['message'])) {
        $chatId = $update['message']['chat']['id'];
        $text = trim($update['message']['text'] ?? '');
        $mid = $merged_params['mid'] ?? '';
        $upi_id = $merged_params['upi_id'] ?? '';
        $amount = $merged_params['amount'] ?? '100.00';

        if (strpos($text, '/start') === 0 || strpos($text, '/pay') === 0) {
            if (empty($upi_id)) {
                $msg = "⚠️ Telegram Bot setup error: 'upi_id' parameter is missing in webhook URL.";
                file_get_contents("https://api.telegram.org/bot{$bot_token}/sendMessage?chat_id={$chatId}&text=" . urlencode($msg));
                exit;
            }

            $qrData = generate_upi_qr($upi_id, $amount, '', 'Merchant', 'Telegram Purchase');
            $orderId = $qrData['order_id'];
            $qrUrl = $qrData['qr_url'];

            // Send QR Photo
            $caption = "Scan & Pay ₹{$amount}\nOrder ID: {$orderId}\n\nPayment status auto-verify ho raha hai...";
            file_get_contents("https://api.telegram.org/bot{$bot_token}/sendPhoto?chat_id={$chatId}&photo=" . urlencode($qrUrl) . "&caption=" . urlencode($caption));

            // Auto-Verify Polling loop in Telegram Handler
            if (!empty($mid)) {
                $verified = false;
                for ($i = 0; $i < 30; $i++) {
                    sleep(4);
                    $check = verify_paytm($mid, $orderId, 'prod');
                    if ($check['status'] === 'success' && $check['verified'] === true) {
                        $verified = true;
                        $txnId = $check['data']['TXNID'] ?? 'N/A';
                        $successMsg = "✅ Payment Received Successfully!\nOrder ID: {$orderId}\nTxn ID: {$txnId}";
                        file_get_contents("https://api.telegram.org/bot{$bot_token}/sendMessage?chat_id={$chatId}&text=" . urlencode($successMsg));
                        break;
                    }
                }
                if (!$verified) {
                    file_get_contents("https://api.telegram.org/bot{$bot_token}/sendMessage?chat_id={$chatId}&text=" . urlencode("❌ Payment verify nahi ho saka ya timeout ho gaya."));
                }
            } else {
                file_get_contents("https://api.telegram.org/bot{$bot_token}/sendMessage?chat_id={$chatId}&text=" . urlencode("ℹ️ MID pass nahi kiya gaya, auto-verification disabled."));
            }
        }
    }
    json_response(array("status" => "ok"));
}

// Handle QR Code Generation API Endpoint
if ($action === 'generate_qr' || strpos($path, '/api/generate-qr') !== false) {
    $upi_id = $merged_params['upi_id'] ?? $merged_params['pa'] ?? '';
    if (empty($upi_id)) {
        json_response(array("status" => "error", "message" => "Missing required field: 'upi_id'"), 400);
    }
    $amount = $merged_params['amount'] ?? $merged_params['am'] ?? '';
    $order_id = $merged_params['order_id'] ?? $merged_params['tr'] ?? '';
    $name = $merged_params['name'] ?? $merged_params['pn'] ?? 'Merchant';
    $note = $merged_params['note'] ?? $merged_params['tn'] ?? '';

    $res = generate_upi_qr($upi_id, $amount, $order_id, $name, $note);
    json_response($res);
}

// Handle Status Verification API Endpoint
if ($action === 'verify' || strpos($path, '/api/verify') !== false || (isset($merged_params['mid']) && (isset($merged_params['order_id']) || isset($merged_params['id'])))) {
    $mid = $merged_params['mid'] ?? $merged_params['MID'] ?? '';
    $order_id = $merged_params['order_id'] ?? $merged_params['orderid'] ?? $merged_params['ORDERID'] ?? $merged_params['id'] ?? '';
    $env = $merged_params['env'] ?? 'prod';

    if (!empty($mid) && !empty($order_id)) {
        $result = verify_paytm($mid, $order_id, $env);
        $httpCode = ($result['status'] === 'error') ? 502 : 200;
        json_response($result, $httpCode);
    }
}

// API Health Check Endpoint
if ($action === 'health' || strpos($path, '/api/health') !== false) {
    json_response(array("status" => "ok", "time" => time()));
}

// Render Single-File Web HTML UI Interface
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Paytm Merchant QR & Payment Verifier (Single File PHP)</title>
<style>
  :root{
    --bg:#0b0d10;
    --panel:#11151a;
    --border:#1e252d;
    --ink:#e6edf3;
    --muted:#7d8590;
    --accent:#00d1b2;
    --accent-2:#ffb020;
    --danger:#ff5c5c;
    --success:#3fb950;
  }
  *{box-sizing:border-box}
  html,body{margin:0;padding:0;background:var(--bg);color:var(--ink);
    font-family:'JetBrains Mono',ui-monospace,SFMono-Regular,Menlo,monospace;}
  body{
    min-height:100vh;
    background:
      radial-gradient(1200px 600px at 90% -10%,rgba(0,209,178,.08),transparent 60%),
      radial-gradient(900px 500px at -10% 110%,rgba(255,176,32,.06),transparent 60%),
      var(--bg);
    padding:48px 24px;
  }
  .wrap{max-width:820px;margin:0 auto;}
  .brand{
    display:flex;align-items:center;gap:12px;margin-bottom:8px;letter-spacing:.4px;
  }
  .brand .dot{width:10px;height:10px;border-radius:50%;background:var(--accent);
    box-shadow:0 0 12px var(--accent);}
  .brand span{color:var(--muted);font-size:12px;text-transform:uppercase;}
  h1{
    font-size:32px;letter-spacing:-.5px;margin:6px 0 6px;
    background:linear-gradient(120deg,#fff 30%,var(--accent) 90%);
    -webkit-background-clip:text;background-clip:text;color:transparent;
    font-family:ui-serif,Georgia,serif;font-weight:600;
  }
  .sub{color:var(--muted);margin-bottom:28px;font-size:13.5px;line-height:1.55;}
  .sub code{background:#161b22;padding:2px 6px;border-radius:4px;color:var(--accent-2);}

  .card{
    background:var(--panel);
    border:1px solid var(--border);
    border-radius:14px;
    padding:24px;
    margin-bottom:24px;
    box-shadow:0 10px 40px rgba(0,0,0,.35);
  }
  .row{display:grid;gap:14px;grid-template-columns:1fr 1fr;}
  @media(max-width:640px){.row{grid-template-columns:1fr}}

  label{display:block;font-size:11px;color:var(--muted);letter-spacing:1px;
    text-transform:uppercase;margin-bottom:6px;}
  input,select{
    width:100%;background:#0a0d11;border:1px solid var(--border);
    color:var(--ink);padding:12px 14px;border-radius:8px;
    font-family:inherit;font-size:14px;outline:none;transition:border .15s;
  }
  input:focus,select:focus{border-color:var(--accent);}
  .field{margin-bottom:16px;}

  .actions{display:flex;gap:12px;margin-top:8px;align-items:center;flex-wrap:wrap;}
  button{
    background:var(--accent);color:#001a16;font-weight:600;
    border:0;padding:12px 22px;border-radius:8px;cursor:pointer;
    font-family:inherit;letter-spacing:.3px;transition:transform .1s,filter .15s;
  }
  button:hover{filter:brightness(1.08);}
  button:active{transform:translateY(1px);}
  button.ghost{background:transparent;color:var(--muted);border:1px solid var(--border);}
  .hint{color:var(--muted);font-size:12px;}

  .result{margin-top:22px;}
  .badge{display:inline-flex;align-items:center;gap:8px;padding:6px 12px;border-radius:999px;
    font-size:12px;letter-spacing:.4px;text-transform:uppercase;
    border:1px solid var(--border);background:#0a0d11;}
  .badge .b{width:8px;height:8px;border-radius:50%;background:var(--muted);}
  .badge.ok .b{background:var(--success);box-shadow:0 0 8px var(--success);}
  .badge.no .b{background:var(--danger);box-shadow:0 0 8px var(--danger);}
  .badge.err .b{background:var(--accent-2);box-shadow:0 0 8px var(--accent-2);}
  pre{
    margin:14px 0 0;background:#08090b;border:1px solid var(--border);
    border-radius:10px;padding:16px;overflow:auto;font-size:12.5px;line-height:1.55;
    color:#c9d1d9;max-height:420px;
  }
  .divider{height:1px;background:var(--border);margin:28px 0;}
  .docs h3{font-size:13px;letter-spacing:1.2px;text-transform:uppercase;color:var(--muted);margin:0 0 10px;}
  .docs pre{margin-top:6px;}
  footer{color:var(--muted);font-size:11.5px;margin-top:28px;text-align:center;}
</style>
</head>
<body>
<div class="wrap">
  <div class="brand"><div class="dot"></div><span>paytm · single-file · php</span></div>
  <h1>Paytm Merchant QR & Status Verifier</h1>
  <p class="sub">
    Single <code>index.php</code> file setup: UPI QR Generator, Paytm Payment Status Check & Telegram Bot Webhook Integration.
  </p>

  <div class="card">
    <h2>1. QR Code Generator & Auto-Verify</h2>
    <div class="row">
      <div class="field">
        <label for="upi_id">UPI ID (VPA)</label>
        <input id="upi_id" placeholder="e.g. merchant@paytm" autocomplete="off"/>
      </div>
      <div class="field">
        <label for="amount">Amount (INR)</label>
        <input id="amount" placeholder="e.g. 100.00" autocomplete="off"/>
      </div>
    </div>
    <div class="row">
      <div class="field">
        <label for="qr_orderid">Order ID (Leave empty for random generation)</label>
        <input id="qr_orderid" placeholder="Auto-generated if left empty" autocomplete="off"/>
      </div>
      <div class="field">
        <label for="qr_note">Note / Remarks (optional)</label>
        <input id="qr_note" placeholder="e.g. Payment for order" autocomplete="off"/>
      </div>
    </div>
    <div class="row">
      <div class="field">
        <label for="qr_mid">Merchant ID (MID) for Auto-Verify</label>
        <input id="qr_mid" placeholder="Enter Paytm MID to enable Auto-Verify" autocomplete="off"/>
      </div>
      <div class="field" style="display:flex;align-items:flex-end;">
        <div class="actions" style="width:100%;justify-content:flex-end;">
          <button id="generateBtn">Generate QR & Auto-Verify</button>
          <button class="ghost" id="clearQrBtn" type="button">Clear</button>
        </div>
      </div>
    </div>

    <div id="qr_result" class="result" style="display:none; text-align: center;">
      <div id="auto_verify_status" style="margin-bottom: 12px; font-weight: bold;"></div>
      <img id="qr_image" src="" alt="QR Code" style="max-width: 250px; border-radius: 8px; margin-bottom: 12px; display: none;" />
      <pre id="qr_output" style="text-align: left;"></pre>
    </div>
  </div>

  <div class="card">
    <h2>2. Direct Payment Verification</h2>
    <div class="row">
      <div class="field">
        <label for="mid">Merchant ID (MID)</label>
        <input id="mid" placeholder="e.g. ABCDEF12345678" autocomplete="off"/>
      </div>
      <div class="field">
        <label for="orderid">Order ID</label>
        <input id="orderid" placeholder="e.g. ORDER123456" autocomplete="off"/>
      </div>
    </div>
    <div class="row">
      <div class="field">
        <label for="env">Environment</label>
        <select id="env">
          <option value="prod">Production (securegw.paytm.in)</option>
          <option value="stage">Staging (securegw-stage.paytm.in)</option>
        </select>
      </div>
      <div class="field" style="display:flex;align-items:flex-end;">
        <div class="actions" style="width:100%;justify-content:flex-end;">
          <button id="verifyBtn">Verify Payment</button>
          <button class="ghost" id="clearBtn" type="button">Clear</button>
        </div>
      </div>
    </div>

    <div id="result" class="result" style="display:none;">
      <span id="badge" class="badge"><span class="b"></span><span id="badgeText">Idle</span></span>
      <pre id="output"></pre>
    </div>
  </div>

  <div class="card docs">
    <h2>3. How Integration Works (Integration Guide)</h2>
    <p class="sub">Aap is API ko apne Telegram Bot aur Website me bina kisi complex setup ke asani se integrate kar sakte hain:</p>

    <h3>🤖 Option A: Telegram Bot Integration (No code required)</h3>
    <p class="hint">Aap apne Bot Father se Bot Token lekar, direct browser me is Webhook URL ko hit karein:</p>
    <pre>
https://api.telegram.org/bot&lt;YOUR_BOT_TOKEN&gt;/setWebhook?url=https://YOUR_DOMAIN/index.php?bot_token=&lt;YOUR_BOT_TOKEN&gt;&mid=&lt;YOUR_PAYTM_MID&gt;&upi_id=&lt;YOUR_UPI_ID&gt;&amount=100.00
</pre>
    <p class="hint">Jab bhi koi user aapke Telegram bot me <code>/start</code> ya <code>/pay</code> kahega, bot usko random Order ID waala QR code bhejega aur background me Paytm status check karke success message bhej dega!</p>

    <div class="divider"></div>

    <h3>🌐 Option B: Website Integration (HTML + JS)</h3>
    <p class="hint">Apni website par user ko QR dikha kar auto-verify karne ke liye bas is JavaScript code ko use karein:</p>
    <pre>
// STEP 1: QR Code Generate Karein
const res = await fetch('https://YOUR_DOMAIN/index.php?action=generate_qr', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ upi_id: 'merchant@paytm', amount: '100.00' })
});
const qr = await res.json();

// Show QR Code image to user
document.getElementById('my-qr-img').src = qr.qr_url;
const orderId = qr.order_id; // Random generated Order ID

// STEP 2: Payment Auto-Verify Loop (Har 4 second par check)
const interval = setInterval(async () => {
  const vRes = await fetch('https://YOUR_DOMAIN/index.php?action=verify', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ mid: 'YOUR_PAYTM_MID', order_id: orderId })
  });
  const vData = await vRes.json();

  if (vData.status === 'success' && vData.verified) {
    clearInterval(interval);
    alert('✅ Payment Received Successfully!');
  }
}, 4000);
</pre>
  </div>

  <footer>Single-File PHP Solution</footer>
</div>

<script>
  const $ = (id) => document.getElementById(id);
  const badge = $('badge');
  const badgeText = $('badgeText');
  const output = $('output');
  const resultBox = $('result');

  function setBadge(state, text){
    badge.className = 'badge ' + state;
    badgeText.textContent = text;
  }

  $('clearBtn').addEventListener('click', () => {
    $('mid').value = ''; $('orderid').value = '';
    resultBox.style.display = 'none'; output.textContent='';
  });

  const qrResultBox = $('qr_result');
  const qrOutput = $('qr_output');
  const qrImage = $('qr_image');
  const autoVerifyStatus = $('auto_verify_status');

  $('clearQrBtn').addEventListener('click', () => {
    $('upi_id').value = ''; $('amount').value = '';
    $('qr_orderid').value = ''; $('qr_note').value = ''; $('qr_mid').value = '';
    qrResultBox.style.display = 'none'; qrOutput.textContent = '';
    qrImage.style.display = 'none'; qrImage.src = '';
    autoVerifyStatus.textContent = '';
    if (pollInterval) clearInterval(pollInterval);
  });

  let pollInterval;

  $('generateBtn').addEventListener('click', async () => {
    const upi_id = $('upi_id').value.trim();
    const amount = $('amount').value.trim();
    let order_id = $('qr_orderid').value.trim();
    const note = $('qr_note').value.trim();
    const mid = $('qr_mid').value.trim();
    const env = $('env').value;

    if (pollInterval) clearInterval(pollInterval);

    if (!upi_id) {
      qrResultBox.style.display = 'block';
      autoVerifyStatus.textContent = '';
      qrOutput.textContent = 'UPI ID is required.';
      qrImage.style.display = 'none';
      return;
    }

    qrResultBox.style.display = 'block';
    autoVerifyStatus.textContent = '';
    qrOutput.textContent = 'Generating QR...';
    qrImage.style.display = 'none';

    try {
      const res = await fetch('index.php?action=generate_qr', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ upi_id, amount, order_id: order_id || undefined, note })
      });
      const json = await res.json();
      qrOutput.textContent = JSON.stringify(json, null, 2);

      if (json.status === 'success' && json.qr_url) {
        qrImage.src = json.qr_url;
        qrImage.style.display = 'inline-block';
        if (json.order_id) {
          $('qr_orderid').value = json.order_id;
          order_id = json.order_id;
        }

        if (mid) {
           autoVerifyStatus.style.color = 'var(--accent-2)';
           autoVerifyStatus.textContent = '⏳ [Auto-Verify Active] Checking payment status every 4 seconds...';

           pollInterval = setInterval(async () => {
             try {
                const checkRes = await fetch('index.php?action=verify', {
                  method: 'POST',
                  headers: {'Content-Type':'application/json'},
                  body: JSON.stringify({ mid, order_id, env })
                });
                const checkJson = await checkRes.json();
                if (checkJson.status === 'success' && checkJson.verified) {
                   clearInterval(pollInterval);
                   autoVerifyStatus.style.color = 'var(--success)';
                   autoVerifyStatus.textContent = '✅ PAYMENT VERIFIED SUCCESSFULLY!';
                   qrOutput.textContent = '✅ PAYMENT SUCCESSFUL!\n\n' + JSON.stringify(checkJson.data, null, 2);
                }
             } catch(err) {
                // Keep polling
             }
           }, 4000);
        } else {
           autoVerifyStatus.style.color = 'var(--muted)';
           autoVerifyStatus.textContent = 'ℹ️ Enter Merchant ID (MID) above to start Auto-Verification polling.';
        }
      }
    } catch (e) {
      qrOutput.textContent = String(e);
    }
  });

  $('verifyBtn').addEventListener('click', async () => {
    const mid = $('mid').value.trim();
    const order_id = $('orderid').value.trim();
    const env = $('env').value;

    if (!mid || !order_id) {
      resultBox.style.display = 'block';
      setBadge('err', 'Missing input');
      output.textContent = 'MID and ORDER ID are required.';
      return;
    }

    resultBox.style.display = 'block';
    setBadge('', 'Verifying…');
    output.textContent = 'Checking status with Paytm ('+env+')...';

    try {
      const res = await fetch('index.php?action=verify', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ mid, order_id, env })
      });
      const json = await res.json();
      output.textContent = JSON.stringify(json, null, 2);
      if (json.status === 'success' && json.verified) {
        setBadge('ok', 'TXN Success · Verified');
      } else if (json.status === 'failed') {
        setBadge('no', 'Not Verified');
      } else {
        setBadge('err', json.status || 'Error');
      }
    } catch (e) {
      setBadge('err', 'Network error');
      output.textContent = String(e);
    }
  });
</script>
</body>
</html>
