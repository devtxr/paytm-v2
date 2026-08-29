# TelePulse - Telegram Channel, Bot & Uptime Suite (cPanel Edition)

TelePulse is a lightweight, standalone PHP application designed specifically for **cPanel Shared/VPS Hosting**. It provides 24/7 uptime monitoring for Telegram Channels, Bots, Webhooks, and Mini Apps with real-time incident alerts, user join/leave tracking, and an interactive Telegram Bot.

---

## 🌟 Features

- **24/7 Health Monitoring:** Checks Telegram Channels, Bots, Webhook endpoints, and Mini Apps via cURL probes.
- **Modern Glassmorphism Web UI:** Beautiful dark theme dashboard built with Tailwind CSS & FontAwesome.
- **Dynamic Target Management:** Add or delete monitoring targets directly from the Web UI or via Telegram Bot commands.
- **Telegram Alert Engine:** Instant notifications for service downtime, latency spikes, and HTTP errors.
- **Member Activity Tracker:** Instant alerts on Telegram when users join or leave your channel or group.
- **Interactive Bot Commands:** Control the system directly via `/status`, `/stats`, `/problems`, `/check`, `/add`, `/del`, `/members`, `/ping`, `/help`.
- **Zero Database Required:** Stores all data cleanly in lightweight `state.json`.

---

## 📁 Package Structure

- `index.php` — Main web dashboard & AJAX API router.
- `config.php` — Bot token, Chat ID, and default target settings.
- `functions.php` — Core cURL probing engine, state manager, and Telegram webhook handler.
- `cron.php` — Lightweight CLI cron script for scheduled monitoring cycles.
- `webhook.php` — Dedicated Telegram Webhook entry point.
- `.htaccess` — Security rules to protect logs and state file.
- `SETUP_GUIDE.txt` — Plain text setup walkthrough for cPanel.

---

## 🚀 Step-by-Step cPanel Deployment Guide

### Step 1: Upload Files to cPanel
1. Log in to your **cPanel** dashboard.
2. Open **File Manager** -> Navigate to `public_html/`.
3. Create a folder named `telepulse`.
4. Upload all files into `public_html/telepulse/`.

### Step 2: Configure Bot Token & Chat ID
1. Open `config.php` in File Manager Code Editor.
2. Update `'bot_token'` with your Telegram Bot Token from `@BotFather`.
3. Update `'chat_id'` with your Telegram Admin Chat ID.

### Step 3: Set Up cPanel Cron Job (Automatic 24/7 Monitoring)
1. In cPanel, search for **Cron Jobs**.
2. Set schedule to **Once Per Minute (`* * * * *`)** or **Every 5 Minutes (`*/5 * * * *`)**.
3. Add the command:
   ```bash
   /usr/local/bin/php /home/YOUR_CPANEL_USERNAME/public_html/telepulse/index.php --cron >/dev/null 2>&1
   ```
   *(Replace `YOUR_CPANEL_USERNAME` with your actual cPanel username)*

### Step 4: Connect Telegram Bot Webhook
To receive member join/leave alerts and use bot commands:
Open this URL in your web browser:
```
https://api.telegram.org/bot<YOUR_BOT_TOKEN>/setWebhook?url=https://YOUR-DOMAIN.com/telepulse/index.php
```

### Step 5: Add Bot as Admin in Channel / Group
1. Add your bot as an **Administrator** in your Telegram Channel or Group.
2. Grant permissions for *Post Messages / Invite Users*.

---

## 🤖 Telegram Bot Commands

| Command | Description |
| ------- | ----------- |
| `/status` | View live uptime status of all monitored targets |
| `/stats` | View overall system uptime percentage & latency stats |
| `/problems` | View recent downtime & error incident logs |
| `/check @handle` | Run instant diagnostic probe on any handle/URL |
| `/add Name \| type \| target` | Add a new target monitor dynamically |
| `/del <monitor_id>` | Remove a target monitor |
| `/members` | View recent user join/leave activity |
| `/ping` | Trigger an instant monitoring cycle |
| `/help` | Display interactive command menu |

---

## 🔐 Security & Optimization

- **.htaccess Protection:** Blocks public web access to `state.json` and `telepulse.log`.
- **CORS Enabled:** Supports external API calls if integrated into custom apps.

*Created for cPanel Hosting Environments by TelePulse Suite.*
