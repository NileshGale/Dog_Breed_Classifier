/**
 * PawDetect Global JavaScript
 * Handles Authentication, Profile Modal, Dropdown, and Toast Notifications
 */

document.addEventListener('DOMContentLoaded', () => {
    // ═══ AUTH CHECK ══════════════════════════════════════════════════════
    const userEmail = sessionStorage.getItem('pawdetect_user_email') || '';
    const userName = sessionStorage.getItem('pawdetect_user_name') || 'User';
    
    // Redirect to login if not authenticated (except for public home page or registration)
    const isPublicPage = window.location.pathname.includes('home_page.html') || window.location.pathname.includes('register.html');
    if (!userEmail && !isPublicPage) {
        window.location.href = 'login_page.html';
        return;
    }

    // Sync Header elements if they exist
    const ddName = document.getElementById('ddName');
    const ddEmail = document.getElementById('ddEmail');
    if (ddName) ddName.textContent = userName;
    if (ddEmail) ddEmail.textContent = userEmail;
    
    const pmDisplayEmail = document.getElementById('currentEmailDisplay');
    if (pmDisplayEmail) pmDisplayEmail.textContent = userEmail;

    // Check for "openProfile" URL parameter
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('openProfile') === '1') {
        openProfile();
    }
});

// ═══ NAVIGATION DROPDOWN ══════════════════════════════════════════════
function toggleDropdown() {
    const dd = document.getElementById('profileDropdown');
    if (dd) dd.classList.toggle('open');
}

window.addEventListener('click', e => {
    if (!e.target.closest('#profileBtn') && !e.target.closest('#profileDropdown')) {
        const dd = document.getElementById('profileDropdown');
        if (dd) dd.classList.remove('open');
    }
});

function doLogout() {
    sessionStorage.clear();
    fetch('../backend/api/logout.php').finally(() => {
        window.location.href = 'home_page.html';
    });
}

// ═══ TOAST NOTIFICATIONS ══════════════════════════════════════════════
function showToast(msg, type = 'info', ms = 3500) {
    let container = document.getElementById('toastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toastContainer';
        document.body.appendChild(container);
    }
    
    const t = document.createElement('div');
    t.className = `toast ${type}`;
    const icons = { 
        success: '<i class="fa-solid fa-circle-check"></i>', 
        error: '<i class="fa-solid fa-circle-exclamation"></i>', 
        info: '<i class="fa-solid fa-circle-info"></i>' 
    };
    t.innerHTML = `<span>${icons[type] || icons.info}</span><span>${msg}</span>`;
    container.appendChild(t);
    
    setTimeout(() => { 
        t.classList.add('hide'); 
        setTimeout(() => t.remove(), 400); 
    }, ms);
}

// ═══ PROFILE MODAL ════════════════════════════════════════════════════
function openProfile() {
    const modal = document.getElementById('profileModal');
    if (modal) {
        modal.classList.add('visible');
        loadProfileData(); // Fetch fresh data from server
    } else {
        console.warn('Profile Modal HTML not found on this page.');
    }
}

function closeProfile() {
    const modal = document.getElementById('profileModal');
    if (modal) modal.classList.remove('visible');
}

function switchTab(tabId, btn) {
    document.querySelectorAll('.pm-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.pm-tab').forEach(t => t.classList.remove('active'));
    
    const targetPanel = document.getElementById(tabId);
    if (targetPanel) targetPanel.classList.add('active');
    if (btn) btn.classList.add('active');
    
    if (tabId === 'tabHistory') renderMyDogHistory();
}

async function loadProfileData() {
    try {
        const res = await fetch('../backend/api/profile.php?action=get');
        const data = await res.json();
        if (data.success && data.user) {
            const u = data.user;
            // Update inputs
            const nameInp = document.getElementById('pmName');
            const mobileInp = document.getElementById('pmMobile');
            const ageInp = document.getElementById('pmAge');
            const genderInp = document.getElementById('pmGender');
            
            if (nameInp) nameInp.value = u.full_name || '';
            if (mobileInp) mobileInp.value = u.mobile || '';
            if (ageInp) ageInp.value = u.age || '';
            if (genderInp) genderInp.value = u.gender || '';
            
            // Sync session
            sessionStorage.setItem('pawdetect_user_name', u.full_name || 'User');
        }
    } catch (e) {
        console.error('Failed to load profile:', e);
    }
}

async function saveProfile() {
    showPMAlert('infoAlert', '');
    const fd = new FormData();
    fd.append('action', 'update');
    fd.append('full_name', document.getElementById('pmName').value.trim());
    fd.append('mobile', document.getElementById('pmMobile').value.trim());
    fd.append('age', document.getElementById('pmAge').value);
    fd.append('gender', document.getElementById('pmGender').value);

    try {
        const res = await fetch('../backend/api/profile.php', { method: 'POST', body: fd });
        const data = await res.json();
        showPMAlert('infoAlert', data.message, data.success ? 'success' : 'error');
        if (data.success) {
            const newName = document.getElementById('pmName').value.trim();
            sessionStorage.setItem('pawdetect_user_name', newName);
            const ddName = document.getElementById('ddName');
            if (ddName) ddName.textContent = newName;
            showToast('Profile updated successfully!', 'success');
        }
    } catch (e) {
        showPMAlert('infoAlert', 'Network error.', 'error');
    }
}

function showPMAlert(id, msg, type = 'error') {
    const el = document.getElementById(id);
    if (!el) return;
    if (!msg) { 
        el.className = 'pm-alert'; 
        el.textContent = ''; 
        return; 
    }
    el.className = `pm-alert show ${type}`; 
    el.textContent = msg;
}

// ═══ EMAIL CHANGE ════════════════════════════════════════════════════
function goEmailStep(n) {
    document.querySelectorAll('.email-step').forEach(s => s.classList.remove('active'));
    const step = document.getElementById('emailStep' + n);
    if (step) step.classList.add('active');
    showPMAlert('emailAlert', '');
}

async function requestEmailChange() {
    showPMAlert('emailAlert', '');
    const fd = new FormData();
    fd.append('action', 'request_email_change');
    try {
        const res = await fetch('../backend/api/profile.php', { method: 'POST', body: fd });
        const d = await res.json();
        if (d.success) {
            buildPMOTPBoxes('ceOTPBoxes1');
            goEmailStep(2);
        } else {
            showPMAlert('emailAlert', d.message, 'error');
        }
    } catch (e) {
        showPMAlert('emailAlert', 'Network error.', 'error');
    }
}

async function verifyCurrentOTP() {
    showPMAlert('emailAlert', '');
    const otp = getPMOTPValue('ceOTPBoxes1');
    if (otp.length !== 6) { 
        showPMAlert('emailAlert', 'Enter the 6-digit OTP.', 'error'); 
        return; 
    }
    const fd = new FormData();
    fd.append('action', 'verify_current_otp');
    fd.append('otp', otp);
    try {
        const res = await fetch('../backend/api/profile.php', { method: 'POST', body: fd });
        const d = await res.json();
        if (d.success) goEmailStep(3); 
        else showPMAlert('emailAlert', d.message, 'error');
    } catch (e) {
        showPMAlert('emailAlert', 'Network error.', 'error');
    }
}

async function sendNewEmailOTP() {
    showPMAlert('emailAlert', '');
    const ne = document.getElementById('newEmailInput').value.trim();
    if (!ne) { 
        showPMAlert('emailAlert', 'Enter a new email address.', 'error'); 
        return; 
    }
    const fd = new FormData();
    fd.append('action', 'send_new_email_otp');
    fd.append('new_email', ne);
    try {
        const res = await fetch('../backend/api/profile.php', { method: 'POST', body: fd });
        const d = await res.json();
        if (d.success) {
            buildPMOTPBoxes('ceOTPBoxes2');
            goEmailStep(4);
        } else {
            showPMAlert('emailAlert', d.message, 'error');
        }
    } catch (e) {
        showPMAlert('emailAlert', 'Network error.', 'error');
    }
}

async function verifyNewEmailOTP() {
    showPMAlert('emailAlert', '');
    const otp = getPMOTPValue('ceOTPBoxes2');
    if (otp.length !== 6) { 
        showPMAlert('emailAlert', 'Enter the 6-digit OTP.', 'error'); 
        return; 
    }
    const fd = new FormData();
    fd.append('action', 'verify_new_email_otp');
    fd.append('otp', otp);
    try {
        const res = await fetch('../backend/api/profile.php', { method: 'POST', body: fd });
        const d = await res.json();
        if (d.success) goEmailStep(5); 
        else showPMAlert('emailAlert', d.message, 'error');
    } catch (e) {
        showPMAlert('emailAlert', 'Network error.', 'error');
    }
}

function buildPMOTPBoxes(cid) {
    const c = document.getElementById(cid);
    if (!c) return;
    c.innerHTML = '';
    for (let i = 0; i < 6; i++) {
        const inp = document.createElement('input');
        inp.type = 'text';
        inp.maxLength = 1;
        inp.inputMode = 'numeric';
        inp.addEventListener('input', () => {
            inp.value = inp.value.replace(/\D/g, '');
            if (inp.value && i < 5) c.children[i + 1].focus();
        });
        inp.addEventListener('keydown', e => {
            if (e.key === 'Backspace' && !inp.value && i > 0) c.children[i - 1].focus();
        });
        c.appendChild(inp);
    }
    setTimeout(() => c.children[0].focus(), 50);
}

function getPMOTPValue(cid) {
    const c = document.getElementById(cid);
    if (!c) return '';
    return [...c.children].map(i => i.value).join('');
}

// ═══ HISTORY ═════════════════════════════════════════════════════════
async function renderMyDogHistory() {
    const list = document.getElementById('historyList');
    if (!list) return;
    try {
        const res = await fetch('../backend/api/profile.php?action=get_history');
        const data = await res.json();
        if (data.success && data.history) {
            if (data.history.length === 0) {
                list.innerHTML = `<div style="text-align:center;padding:60px 20px;color:#bbb">
                    <i class="fa-solid fa-paw" style="font-size:2.5rem;margin-bottom:12px;opacity:.3"></i>
                    <p style="font-size:14px">You haven't listed any dogs yet.</p>
                    <a href="list_your_dog.html" style="color:var(--rust);text-decoration:none;font-weight:600;font-size:13px;display:inline-block;margin-top:8px">List your first dog →</a>
                </div>`;
                return;
            }
            let html = '';
            data.history.forEach(d => {
                const date = new Date(d.created_at).toLocaleDateString('en-IN', { day:'numeric', month:'short', year:'numeric' });
                const photo = d.photo_path ? `../${d.photo_path}` : 'https://placehold.co/100x100?text=No+Photo';
                html += `<div class="history-card">
                    <img src="${photo}" class="history-thumb" alt="${d.name}">
                    <div class="history-info">
                        <div class="history-name">${d.name} ${d.is_urgent ? '<span class="history-badge badge-urgent">Urgent</span>' : ''}</div>
                        <div class="history-breed"><i class="fa-solid fa-dog" style="font-size:10px"></i> ${d.breed}</div>
                        <div class="history-date">Listed on ${date}</div>
                    </div>
                    <button class="history-delete" onclick="deleteListing(${d.id}, '${d.name.replace(/'/g, "\\'")}')" title="Delete listing">
                        <i class="fa-solid fa-trash-can"></i>
                    </button>
                </div>`;
            });
            list.innerHTML = html;
        } else {
            list.innerHTML = `<div style="text-align:center;padding:40px;color:#c0392b;font-size:13px">Failed to load history: ${data.message}</div>`;
        }
    } catch (e) {
        list.innerHTML = `<div style="text-align:center;padding:40px;color:#c0392b;font-size:13px">Network error while fetching history.</div>`;
    }
}

async function deleteListing(id, name) {
    if (!confirm(`Are you sure you want to delete the listing for "${name}"?`)) return;
    
    const fd = new FormData();
    fd.append('action', 'delete_listing');
    fd.append('listing_id', id);

    try {
        const res = await fetch('../backend/api/profile.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            showToast('Listing deleted successfully.', 'success');
            renderMyDogHistory();
        } else {
            showToast(data.message || 'Delete failed.', 'error');
        }
    } catch (e) {
        showToast('Network error while deleting.', 'error');
    }
}
