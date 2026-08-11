/**
 * StockFlow Client-Side API Connector
 */

// Replace this URL with your deployed Google Apps Script Web App URL
const API_URL = "https://script.google.com/macros/s/AKfycbzs6vhdYXtRcNLrQ54EH5xiXi1n6GoSr-TDIx-Dw4wc_vhduvIuEu_zI0JUkNIwRICOsg/exec";

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
// Global fetch GET wrapper with Stale-While-Revalidate Caching for instant load times (0.01s)
async function apiGet(action, params = {}) {
    const url = new URL(API_URL);
    url.searchParams.append('action', action);
    for (const key in params) {
        url.searchParams.append(key, params[key]);
    }
    
    const isGetData = action === 'get_data' && Object.keys(params).length === 0;
    
    if (isGetData) {
        const cached = localStorage.getItem('stockflow_cache_get_data');
        if (cached) {
            // Background fetch to update cache silently
            fetch(url.toString(), { method: 'GET', mode: 'cors' })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        localStorage.setItem('stockflow_cache_get_data', JSON.stringify(data));
                        renderSidebarLogo();
                        if (typeof window.onDataRefresh === 'function') {
                            window.onDataRefresh(data);
                        }
                    }
                }).catch(err => console.warn("Background fetch failed:", err));
            
            // Return cached data immediately
            return JSON.parse(cached);
        }
    }
    
    try {
        const response = await fetch(url.toString(), {
            method: 'GET',
            mode: 'cors'
        });
        if (!response.ok) throw new Error("HTTP network error occurred.");
        const result = await response.json();
        if (isGetData && result.success) {
            localStorage.setItem('stockflow_cache_get_data', JSON.stringify(result));
            renderSidebarLogo();
        }
        return result;
    } catch (err) {
        console.error("GET Error:", err);
        return { success: false, error: err.message };
    }
}

// Global fetch POST wrapper (invalidates cache on mutation)
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
        const result = await response.json();
        if (result.success) {
            // Invalidate cache on mutations (inserts/updates/deletes)
            localStorage.removeItem('stockflow_cache_get_data');
        }
        return result;
    } catch (err) {
        console.error("POST Error:", err);
        return { success: false, error: err.message };
    }
}

// Client-side image compressor & Base64 encoder utility (converts to compact JPG data URI)
function compressImage(file, maxDim = 250, quality = 0.7) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = function(e) {
            const img = new Image();
            img.onload = function() {
                const canvas = document.createElement('canvas');
                let w = img.width;
                let h = img.height;
                if (w > h) {
                    if (w > maxDim) {
                        h = Math.round(h * maxDim / w);
                        w = maxDim;
                    }
                } else {
                    if (h > maxDim) {
                        w = Math.round(w * maxDim / h);
                        h = maxDim;
                    }
                }
                canvas.width = w;
                canvas.height = h;
                const ctx = canvas.getContext('2d');
                ctx.drawImage(img, 0, 0, w, h);
                resolve(canvas.toDataURL('image/jpeg', quality));
            };
            img.onerror = reject;
            img.src = e.target.result;
        };
        reader.onerror = reject;
        reader.readAsDataURL(file);
    });
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

// Render business logo on sidebars dynamically
function renderSidebarLogo() {
    const cached = localStorage.getItem('stockflow_cache_get_data');
    if (!cached) return;
    try {
        const data = JSON.parse(cached);
        const settings = data.settings;
        if (settings && settings.logo) {
            const brands = document.querySelectorAll('.sidebar-brand');
            brands.forEach(brand => {
                brand.innerHTML = `<img src="${settings.logo}" class="rounded border bg-white me-2 shadow-sm" style="width: 28px; height: 28px; object-fit: contain;"> <span>${escapeHtml(settings.company_name)}</span>`;
            });
        }
    } catch(e) {}
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}

document.addEventListener('DOMContentLoaded', renderSidebarLogo);
