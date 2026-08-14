/**
 * Paytm Merchant Payment Status Verifier — Cloudflare Worker
 * -------------------------------------------------------------
 * Ek single Worker file. Deploy on Cloudflare Workers (worker.dev).
 *
 * Endpoints:
 *   GET  /                       -> HTML test page (UI)
 *   GET  /api/verify?mid=&order_id=&env=prod|stage
 *   POST /api/verify  { "mid": "...", "order_id": "...", "env": "prod"|"stage" }
 *   GET  /api/health             -> health check
 *
 * Logic (same as reference code):
 *   sortedQS = "MID=<mid>&ORDERID=<order_id>&"
 *   CHECKSUMHASH = sha256( sortedQS + <order_id> )
 *   POST { CHECKSUMHASH, MID, ORDERID } -> https://securegw.paytm.in/order/status
 *   TXN_SUCCESS => verified
 */

const PAYTM_ENDPOINTS = {
  prod: "https://securegw.paytm.in/order/status",
  stage: "https://securegw-stage.paytm.in/order/status",
};

const CORS_HEADERS = {
  "Access-Control-Allow-Origin": "*",
  "Access-Control-Allow-Methods": "GET, POST, OPTIONS",
  "Access-Control-Allow-Headers": "Content-Type, Authorization",
  "Access-Control-Max-Age": "86400",
};

async function sha256Hex(text) {
  const buf = new TextEncoder().encode(text);
  const hash = await crypto.subtle.digest("SHA-256", buf);
  return [...new Uint8Array(hash)]
    .map((b) => b.toString(16).padStart(2, "0"))
    .join("");
}

async function verifyPaytm({ mid, order_id, env }) {
  const endpoint =
    env === "stage" ? PAYTM_ENDPOINTS.stage : PAYTM_ENDPOINTS.prod;

  // Build sorted query string exactly like the reference code
  const params = { MID: mid, ORDERID: order_id };
  const keys = Object.keys(params).sort();
  let sortedQS = "";
  for (const k of keys) sortedQS += `${k}=${params[k]}&`;

  const checksum = await sha256Hex(sortedQS + order_id);

  const body = {
    CHECKSUMHASH: checksum,
    MID: mid,
    ORDERID: order_id,
  };

  const resp = await fetch(endpoint, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "Cache-Control": "no-cache",
    },
    body: JSON.stringify(body),
  });

  const raw = await resp.text();
  let data;
  try {
    data = JSON.parse(raw);
  } catch {
    return {
      status: "error",
      message: "Invalid response from Paytm",
      raw,
      endpoint,
    };
  }

  const verified = data && data.STATUS === "TXN_SUCCESS";
  return {
    status: verified ? "success" : "failed",
    verified,
    endpoint,
    data,
  };
}

function jsonResponse(obj, statusCode = 200) {
  return new Response(JSON.stringify(obj, null, 2), {
    status: statusCode,
    headers: {
      "Content-Type": "application/json; charset=utf-8",
      ...CORS_HEADERS,
    },
  });
}


async function handleGenerateQR(request) {
  let upi_id, amount, order_id, name, note;

  if (request.method === "GET") {
    const url = new URL(request.url);
    upi_id = url.searchParams.get("upi_id") || url.searchParams.get("pa");
    amount = url.searchParams.get("amount") || url.searchParams.get("am");
    order_id = url.searchParams.get("order_id") || url.searchParams.get("tr");
    name = url.searchParams.get("name") || url.searchParams.get("pn") || "Merchant";
    note = url.searchParams.get("note") || url.searchParams.get("tn") || "";
  } else if (request.method === "POST") {
    try {
      const body = await request.json();
      upi_id = body.upi_id || body.pa;
      amount = body.amount || body.am;
      order_id = body.order_id || body.tr;
      name = body.name || body.pn || "Merchant";
      note = body.note || body.tn || "";
    } catch {
      return jsonResponse(
        { status: "error", message: "Invalid JSON body" },
        400
      );
    }
  } else {
    return jsonResponse({ status: "error", message: "Method not allowed" }, 405);
  }

  if (!upi_id) {
    return jsonResponse(
      {
        status: "error",
        message: "Missing required field: 'upi_id'",
      },
      400
    );
  }

  // Build UPI URI
  // upi://pay?pa=UPI_ID&pn=NAME&am=AMOUNT&tr=ORDER_ID&cu=INR&tn=NOTE
  const params = new URLSearchParams();
  params.append("pa", upi_id);
  params.append("pn", name);
  if (amount) params.append("am", amount);
  if (order_id) params.append("tr", order_id);
  params.append("cu", "INR");
  if (note) params.append("tn", note);

  const upi_uri = "upi://pay?" + params.toString();
  
  // URL encode the URI for the QR server
  const qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" + encodeURIComponent(upi_uri);

  return jsonResponse({
    status: "success",
    upi_uri: upi_uri,
    qr_url: qr_url
  }, 200);
}

async function handleVerify(request) {
  let mid, order_id, env;

  if (request.method === "GET") {
    const url = new URL(request.url);
    mid = url.searchParams.get("mid");
    order_id = url.searchParams.get("order_id") || url.searchParams.get("orderid");
    env = url.searchParams.get("env") || "prod";
  } else if (request.method === "POST") {
    try {
      const body = await request.json();
      mid = body.mid || body.MID;
      order_id = body.order_id || body.orderid || body.ORDERID;
      env = body.env || "prod";
    } catch {
      return jsonResponse(
        { status: "error", message: "Invalid JSON body" },
        400
      );
    }
  } else {
    return jsonResponse({ status: "error", message: "Method not allowed" }, 405);
  }

  if (!mid || !order_id) {
    return jsonResponse(
      {
        status: "error",
        message: "Missing required fields: 'mid' and 'order_id'",
      },
      400
    );
  }

  if (env !== "prod" && env !== "stage") {
    return jsonResponse(
      { status: "error", message: "env must be 'prod' or 'stage'" },
      400
    );
  }

  try {
    const result = await verifyPaytm({ mid, order_id, env });
    const httpStatus = result.status === "error" ? 502 : 200;
    return jsonResponse(result, httpStatus);
  } catch (err) {
    return jsonResponse(
      { status: "error", message: err.message || String(err) },
      500
    );
  }
}

function renderTestPage() {
  const html = `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Paytm Payment Verifier · Worker</title>
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
    font-size:34px;letter-spacing:-.5px;margin:6px 0 6px;
    background:linear-gradient(120deg,#fff 30%,var(--accent) 90%);
    -webkit-background-clip:text;background-clip:text;color:transparent;
    font-family:ui-serif,Georgia,serif;font-weight:600;
  }
  .sub{color:var(--muted);margin-bottom:32px;font-size:13.5px;line-height:1.55;}
  .sub code{background:#161b22;padding:2px 6px;border-radius:4px;color:var(--accent-2);}

  .card{
    background:var(--panel);
    border:1px solid var(--border);
    border-radius:14px;
    padding:24px;
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
  .docs pre{margin-top:0;}
  a{color:var(--accent);text-decoration:none;}
  a:hover{text-decoration:underline;}
  footer{color:var(--muted);font-size:11.5px;margin-top:28px;text-align:center;}
</style>
</head>
<body>
<div class="wrap">
  <div class="brand"><div class="dot"></div><span>paytm · order · status</span></div>
  <h1>Merchant Payment Verifier</h1>
  <p class="sub">
    Sirf <code>MID</code> aur <code>ORDER ID</code> se Paytm ka
    <code>/order/status</code> API call karke payment verify karo.
    Merchant key ki zaroorat nahi — CHECKSUMHASH self-computed hai.
  </p>

  <div class="card">
    <div class="row">
      <div class="field">
        <label for="mid">Merchant ID (MID)</label>
        <input id="mid" data-testid="mid-input" placeholder="e.g. ABCDEF12345678" autocomplete="off"/>
      </div>
      <div class="field">
        <label for="orderid">Order ID</label>
        <input id="orderid" data-testid="orderid-input" placeholder="e.g. ORDER123456" autocomplete="off"/>
      </div>
    </div>
    <div class="row">
      <div class="field">
        <label for="env">Environment</label>
        <select id="env" data-testid="env-select">
          <option value="prod">Production (securegw.paytm.in)</option>
          <option value="stage">Staging (securegw-stage.paytm.in)</option>
        </select>
      </div>
      <div class="field" style="display:flex;align-items:flex-end;">
        <div class="actions" style="width:100%;justify-content:flex-end;">
          <button id="verifyBtn" data-testid="verify-btn">Verify Payment</button>
          <button class="ghost" id="clearBtn" data-testid="clear-btn" type="button">Clear</button>
        </div>
      </div>
    </div>

    <div id="result" class="result" style="display:none;">
      <span id="badge" class="badge" data-testid="status-badge"><span class="b"></span><span id="badgeText">Idle</span></span>
      <pre id="output" data-testid="result-output"></pre>
    </div>
  </div>

  <div class="divider"></div>
  
  <h2>QR Code Generator</h2>
  <p class="sub">
    Apna UPI ID aur details dekar payment QR code generate karein.
  </p>

  <div class="card">
    <div class="row">
      <div class="field">
        <label for="upi_id">UPI ID</label>
        <input id="upi_id" placeholder="e.g. merchant@paytm" autocomplete="off"/>
      </div>
      <div class="field">
        <label for="amount">Amount (optional)</label>
        <input id="amount" placeholder="e.g. 100.50" autocomplete="off"/>
      </div>
    </div>
    <div class="row">
      <div class="field">
        <label for="qr_orderid">Order ID (Auto-generated if empty)</label>
        <input id="qr_orderid" placeholder="Leave empty for unique ID" autocomplete="off"/>
      </div>
      <div class="field">
        <label for="qr_note">Note (optional)</label>
        <input id="qr_note" placeholder="e.g. Payment for shoes" autocomplete="off"/>
      </div>
    </div>
    <div class="row">
      <div class="field">
        <label for="qr_mid">Merchant ID (for Auto-Verify)</label>
        <input id="qr_mid" placeholder="Enter MID to start live polling" autocomplete="off"/>
      </div>
      <div class="field" style="display:flex;align-items:flex-end;">
        <div class="actions" style="width:100%;justify-content:flex-end;">
          <button id="generateBtn">Generate QR</button>
          <button class="ghost" id="clearQrBtn" type="button">Clear</button>
        </div>
      </div>
    </div>

    <div id="qr_result" class="result" style="display:none; text-align: center;">
      <img id="qr_image" src="" alt="QR Code" style="max-width: 250px; border-radius: 8px; margin-bottom: 12px; display: none;" />
      <pre id="qr_output" style="text-align: left;"></pre>
    </div>
  </div>

  <div class="divider"></div>

  <div class="docs">
    <h3>// API Usage</h3>
    <pre>GET  /api/verify?mid=YOUR_MID&order_id=YOUR_ORDER&env=prod
POST /api/verify
Content-Type: application/json

{
  "mid": "YOUR_MID",
  "order_id": "YOUR_ORDER",
  "env": "prod"
}

GET  /api/generate-qr?upi_id=merchant@paytm&amount=100.50&order_id=ORDER123
POST /api/generate-qr
Content-Type: application/json

{
  "upi_id": "merchant@paytm",
  "amount": "100.50",
  "order_id": "ORDER123",
  "note": "Payment"
}

Response (Generate QR):
{
  "status": "success",
  "upi_uri": "upi://pay?pa=...",
  "qr_url": "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=..."
}

// Auto-Verify Logic (Frontend Example):
let pollInterval = setInterval(async () => {
  const res = await fetch('/api/verify?mid=YOUR_MID&order_id=ORDER123');
  const json = await res.json();
  if (json.status === 'success' && json.verified) {
    clearInterval(pollInterval);
    alert('Payment Successful!');
  }
}, 4000); // Poll every 4 seconds
</pre>
    <p class="hint">CORS enabled — apni website ya app se seedha fetch kar sakte ho.</p>
  </div>

  <footer>Deployed on Cloudflare Workers · <span id="host"></span></footer>
</div>

<script>
  document.getElementById('host').textContent = location.host;
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

  $('clearQrBtn').addEventListener('click', () => {
    $('upi_id').value = ''; $('amount').value = '';
    $('qr_orderid').value = ''; $('qr_note').value = '';
    qrResultBox.style.display = 'none'; qrOutput.textContent = '';
    qrImage.style.display = 'none'; qrImage.src = '';
  });

  let pollInterval;

  $('generateBtn').addEventListener('click', async () => {
    const upi_id = $('upi_id').value.trim();
    const amount = $('amount').value.trim();
    let order_id = $('qr_orderid').value.trim();
    const note = $('qr_note').value.trim();
    const mid = $('qr_mid').value.trim();
    const env = $('env').value;

    if (!order_id) {
        order_id = 'ORD' + Date.now() + Math.floor(Math.random() * 1000);
        $('qr_orderid').value = order_id; // Auto fill the UI
    }
    
    // Clear old intervals
    if (pollInterval) clearInterval(pollInterval);

    if (!upi_id) {
      qrResultBox.style.display = 'block';
      qrOutput.textContent = 'UPI ID is required.';
      qrImage.style.display = 'none';
      return;
    }

    qrResultBox.style.display = 'block';
    qrOutput.textContent = 'Generating QR...';
    qrImage.style.display = 'none';

    try {
      const res = await fetch('/api/generate-qr', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ upi_id, amount, order_id, note })
      });
      const json = await res.json();
      qrOutput.textContent = JSON.stringify(json, null, 2);
      if (json.status === 'success' && json.qr_url) {
        qrImage.src = json.qr_url;
        qrImage.style.display = 'inline-block';
        
        if (mid) {
           qrOutput.textContent += '\\n\\n[Auto-Verify Active] Waiting for payment...';
           pollInterval = setInterval(async () => {
             try {
                const checkRes = await fetch('/api/verify', {
                  method: 'POST',
                  headers: {'Content-Type':'application/json'},
                  body: JSON.stringify({ mid, order_id, env })
                });
                const checkJson = await checkRes.json();
                if (checkJson.status === 'success' && checkJson.verified) {
                   clearInterval(pollInterval);
                   qrOutput.textContent = '✅ PAYMENT SUCCESSFUL!\\n\\n' + JSON.stringify(checkJson.data, null, 2);
                   qrResultBox.style.backgroundColor = 'rgba(63, 185, 80, 0.1)';
                   qrResultBox.style.border = '1px solid var(--success)';
                }
             } catch(err) {
                // Silently ignore poll errors to keep retrying
             }
           }, 4000); // Check every 4 seconds
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
      output.textContent = 'MID aur ORDER ID dono zaroori hain.';
      return;
    }

    resultBox.style.display = 'block';
    setBadge('', 'Verifying…');
    output.textContent = 'Contacting Paytm ('+env+')...';

    try {
      const res = await fetch('/api/verify', {
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
</html>`;
  return new Response(html, {
    status: 200,
    headers: {
      "Content-Type": "text/html; charset=utf-8",
      "Cache-Control": "public, max-age=300",
    },
  });
}

export default {
  async fetch(request) {
    const url = new URL(request.url);

    // CORS preflight
    if (request.method === "OPTIONS") {
      return new Response(null, { status: 204, headers: CORS_HEADERS });
    }

    if (url.pathname === "/" || url.pathname === "/index.html") {
      return renderTestPage();
    }

    if (url.pathname === "/api/health") {
      return jsonResponse({ status: "ok", ts: Date.now() });
    }

    if (url.pathname === "/api/verify") {
      return handleVerify(request);
    }

    if (url.pathname === "/api/generate-qr") {
      return handleGenerateQR(request);
    }

    return jsonResponse({ status: "error", message: "Not found" }, 404);
  },
};
