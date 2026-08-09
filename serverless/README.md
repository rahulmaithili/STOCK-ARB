# 📊 StockFlow — Google Sheets & Netlify Serverless Setup Guide

This folder contains the **Serverless Edition** of StockFlow. It runs completely free on **Netlify** (Frontend) and uses **Google Sheets** (Backend Database) via a custom **Google Apps Script API**.

---

## 🛢️ Step 1: Set Up Google Sheets Database

1. Create a new Google Sheet.
2. Rename the Sheet tabs (worksheets) to match the following names exactly, and add the column headers in **Row 1**:

### Tab 1: `products`
| Column A | Column B | Column C | Column D | Column E | Column F | Column G | Column H | Column I | Column J | Column K | Column L |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| **id** | **name** | **sku** | **category** | **unit** | **purchase_price** | **selling_price** | **opening_stock** | **current_stock** | **reorder_level** | **product_type** | **defective_stock** |
| 1 | Regulator Emr | EMR | Regulator | PCS | 150 | 250 | 400 | 400 | 20 | regulator | 36 |
| 2 | Regulator FTL | FTLR | Regulator | PCS | 180 | 280 | 100 | 100 | 10 | ftl_regulator | 12 |

### Tab 2: `customer_replacements`
| Column A | Column B | Column C | Column D | Column E | Column F | Column G | Column H | Column I | Column J | Column K | Column L | Column M |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| **id** | **customer_name** | **product_id** | **quantity** | **swap_type** | **consumer_number** | **mobile_number** | **old_regulator_no** | **new_regulator_no** | **replacement_date** | **notes** | **created_by** | **created_at** |

### Tab 3: `plant_replacements`
| Column A | Column B | Column C | Column D | Column E | Column F | Column G | Column H |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| **id** | **supplier_name** | **product_id** | **quantity** | **return_date** | **notes** | **created_by** | **created_at** |

### Tab 4: `users`
| Column A | Column B | Column C | Column D | Column E |
| :--- | :--- | :--- | :--- | :--- |
| **id** | **name** | **email** | **password** | **role** |
| 1 | System Administrator | admin@stockflow.com | admin123 | admin |

---

## ⚙️ Step 2: Deploy Google Apps Script Web App

1. In your Google Sheet, click **Extensions** (एक्सटेंशन) ➔ **Apps Script** (एप्स स्क्रिप्ट).
2. Delete any default code in the editor, and copy-paste the entire contents of **[`google-apps-script.js`](google-apps-script.js)**.
3. Click the **Save** (💾) icon.
4. Click **Deploy** (डिप्लॉय) ➔ **New Deployment** (नया डिप्लॉयमेंट).
5. In the configuration popup, click the gear icon and select **Web App** (वेब एप):
   - **Description:** `StockFlow API v1`
   - **Execute as:** `Me` (अपना ईमेल सिलेक्ट करें)
   - **Who has access:** `Anyone` (कोई भी) *(ये जरूरी है ताकि आपका नेटलिफाई फ्रंटएंड डेटा भेज सके)*
6. Click **Deploy**. Authorize Google permissions if prompted.
7. **Copy the Web App URL** generated (it will look like `https://script.google.com/macros/s/AKfycb.../exec`).

---

## 🔌 Step 3: Link API to Frontend

1. Open the folder **`js/`** on your computer.
2. Edit **[`js/api.js`](js/api.js)** in a text editor (Notepad or VS Code).
3. On line 5, replace the placeholder URL with your copied Google Apps Script URL:
   ```javascript
   const API_URL = "https://script.google.com/macros/s/AKfycb.../exec";
   ```
4. Save the file.

---

## 🚀 Step 4: Deploy to Netlify (100% Free)

1. Go to **[Netlify.com](https://www.netlify.com/)** and sign up for a free account.
2. Open your Netlify Dashboard and go to **Add New Site** ➔ **Deploy Manually**.
3. **Drag & Drop** the entire **`serverless`** folder from your computer into the upload area on Netlify.
4. In 10 seconds, your site will be live! You can edit the domain name in Netlify settings (e.g. `shivshakti-stock.netlify.app`).
