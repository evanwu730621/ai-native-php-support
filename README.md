# AI-Native 智慧文檔分析與自動化客服系統

這是一個以 PHP 8.2+ 建立的輕量 AI-Native Web 應用，可在 WampServer 上分析文檔與客服訊息，串接 Google Gemini 並查詢 MySQL 訂單資料。

## 核心特色

- Google Gemini 原生 API 串接與結構化 JSON 回應
- SSE Server-Sent Events 即時串流聊天
- Tool Calling 訂單查詢，將真實資料注入客服 Prompt
- 文檔上傳、PDF/TXT 解析與文件切塊
- MVC + Service + DTO 分層架構
- PDO MySQL 資料存取與 SQL Prepared Statement
- 真人客服轉接、幻覺風控與 LINE Webhook 支援

## 技術架構

- PHP 8.2+
- Slim 4
- PHP-DI
- PDO / MySQL
- Symfony HttpClient
- Google Gemini API
- PSR-12
- Vanilla JavaScript 前端

主要目錄：

```text
config/           PHP-DI 容器設定
database/         MySQL schema
public/           前端頁面、資源與入口程式
src/Controller/   HTTP 輸入與輸出處理
src/Service/      業務邏輯
src/Services/     AI、文件切塊等服務
src/DTO/          結構化資料物件
src/Tools/        LLM 可呼叫工具
tests/            功能與 Gemini 連線測試
```

## 環境需求

- PHP 8.2 或更新版本
- Composer 2
- Apache（建議透過 WampServer）
- MySQL 8 或相容版本
- 可用的 Google Gemini API Key
- 啟用 PHP 擴充：`pdo_mysql`、`curl`、`mbstring`、`fileinfo`

## 安裝與設定

1. 安裝相依套件：

   ```powershell
   composer install
   ```

2. 建立本機環境設定：

   ```powershell
   Copy-Item .env.example .env
   ```

3. 編輯 `.env`，填入 Gemini 金鑰及 MySQL 連線資訊：

   ```env
   GEMINI_API_KEY=你的Gemini金鑰
   GEMINI_MODEL=gemini-2.5-flash
   GEMINI_BASE_URL=https://generativelanguage.googleapis.com/v1beta

   DB_HOST=127.0.0.1
   DB_NAME=ai_native_app
   DB_USER=root
   DB_PASS=
   ```

4. 建立 MySQL 資料庫並匯入 schema：

   ```sql
   CREATE DATABASE ai_native_app CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

   然後匯入：

   ```powershell
   mysql -u root ai_native_app < database/schema.sql
   ```

## 在 WampServer 啟動

將專案放置於 WampServer 網站目錄，例如：

```text
C:\wamp64\www\testai
```

啟動 Apache 與 MySQL 後，開啟：

- 首頁：http://127.0.0.1/testai/
- 健康檢查：http://127.0.0.1/testai/health

專案根目錄與 `public/` 已提供 Apache `.htaccess`，支援以 `/testai` 子目錄部署。

## 測試

執行隔離式功能測試：

```powershell
php tests/functional_test.php
```

執行 Gemini 實體連線測試：

```powershell
php tests/test_gemini_connection.php
```

檢查 PHP 語法：

```powershell
Get-ChildItem -Recurse -Filter *.php |
  Where-Object { $_.FullName -notmatch '[\\]vendor[\\]' } |
  ForEach-Object { php -l $_.FullName }
```

## 安全注意事項

- 絕對不要提交 `.env` 或 API Key。
- 生產環境應啟用正式 TLS 檔案驗證，不應停用 SSL 憑證檢查。
- 不應將 LINE、資料庫或其他服務密鑰提交至版本控制。
