/**
 * StockFlow Client-Side API Connector
 */

// Replace this URL with your deployed Google Apps Script Web App URL
const API_URL = "https://script.google.com/macros/s/AKfycbycFBZktVuLe3o8G8jUFEpLPO2SBjgO_t60v16f1kzwro4OLBMoAftON5BuX2XTYP2j1Q/exec";

// Check if user is logged in (Redirect to login if not)
function checkAuth() {
    const token = localStorage.getItem('stockflow_token');
    const currentPage = window.location.pathname.split("/").pop();
    
    if (!token && currentPage !== 'login.html') {
        window.location.href = 'login.html';
    }
}

// Log out user
function logout() {
    localStorage.removeItem('stockflow_token');
    localStorage.removeItem('stockflow_user');
    window.location.href = 'login.html';
}

// Global fetch GET wrapper
async function apiGet(action, params = {}) {
    const url = new URL(API_URL);
    url.searchParams.append('action', action);
    for (const key in params) {
        url.searchParams.append(key, params[key]);
    }
    
    try {
        const response = await fetch(url.toString(), {
            method: 'GET',
            mode: 'cors'
        });
        if (!response.ok) throw new Error("HTTP network error occurred.");
        return await response.json();
    } catch (err) {
        console.error("GET Error:", err);
        return { success: false, error: err.message };
    }
}

// Global fetch POST wrapper
async function apiPost(action, payload = {}) {
    payload.action = action;
    payload.created_by = JSON.parse(localStorage.getItem('stockflow_user') || '{}').name || 'System';
    
    try {
        const response = await fetch(API_URL, {
            method: 'POST',
            mode: 'cors',
            headers: {
                'Content-Type': 'text/plain' // Use text/plain to prevent CORS preflight OPTIONS triggers in GAS
            },
            body: JSON.stringify(payload)
        });
        if (!response.ok) throw new Error("HTTP network error occurred.");
        return await response.json();
    } catch (err) {
        console.error("POST Error:", err);
        return { success: false, error: err.message };
    }
}

// Helper to show/hide loading spinners
function showLoader(show = true) {
    let loader = document.getElementById('api-global-loader');
    if (!loader) {
        loader = document.createElement('div');
        loader.id = 'api-global-loader';
        loader.style.cssText = "position:fixed;top:0;left:0;width:100%;height:3px;background:var(--accent);z-index:9999;transition:all 0.3s;";
        document.body.appendChild(loader);
    }
    loader.style.width = show ? '60%' : '100%';
    loader.style.opacity = show ? '1' : '0';
    if (!show) {
        setTimeout(() => { loader.style.width = '0%'; }, 400);
    }
}

// Helper to display premium top-floating notifications
function notify(type, message) {
    let toast = document.getElementById('api-toast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'api-toast';
        toast.style.cssText = "position:fixed;top:20px;right:20px;padding:12px 24px;border-radius:10px;color:white;font-weight:600;z-index:99999;box-shadow:0 10px 20px rgba(0,0,0,0.15);transition:all 0.4s;transform:translateY(-50px);opacity:0;";
        document.body.appendChild(toast);
    }
    
    toast.style.backgroundColor = type === 'success' ? '#10b981' : '#ef4444';
    toast.textContent = message;
    toast.style.transform = 'translateY(0)';
    toast.style.opacity = '1';
    
    setTimeout(() => {
        toast.style.transform = 'translateY(-50px)';
        toast.style.opacity = '0';
    }, 4000);
}
