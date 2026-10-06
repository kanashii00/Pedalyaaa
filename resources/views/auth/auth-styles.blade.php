{{-- Shared glassmorphism styles for the auth pages (login + register).

     Both views include this partial inside their own <style> block so the
     sign-in and sign-up screens stay pixel-identical: background, card,
     inputs, divider, Google button and footer text all come from here. --}}
.auth-page {
    background:
        linear-gradient(180deg, rgba(8, 24, 13, 0.55) 0%, rgba(8, 24, 13, 0.72) 100%),
        url('{{ asset('assets/img/bg.png') }}') center / cover no-repeat;
}
.auth-card {
    background: rgba(255, 255, 255, 0.12);
    -webkit-backdrop-filter: blur(16px);
    backdrop-filter: blur(16px);
    border: 1px solid rgba(255, 255, 255, 0.25);
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25), inset 0 1px 0 rgba(255,255,255,0.15);
}
.auth-logo h1 { font-size: 2.2rem; font-weight: 800; letter-spacing: -0.02em; }
.auth-logo p { font-size: 1.05rem; font-weight: 500; }
.form-label-pedalya { color: #fff !important; font-weight: 700; font-size: 0.9rem; margin-bottom: 0.5rem; text-shadow: 0 2px 6px rgba(0,0,0,0.7); }
.form-control-pedalya { background: rgba(255,255,255,0.18) !important; border: 1px solid rgba(255,255,255,0.3) !important; color: #fff !important; font-size: 1rem; font-weight: 500; }
.form-control-pedalya::placeholder { color: rgba(255,255,255,0.6) !important; }
.form-control-pedalya:focus { background: rgba(255,255,255,0.25) !important; border-color: var(--primary) !important; box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.3) !important; }
.input-group-text { background: rgba(255,255,255,0.15) !important; border: 1px solid rgba(255,255,255,0.25) !important; border-right: none !important; color: rgba(255,255,255,0.85) !important; }
.form-control-pedalya + .input-group-text, .input-group-text + .form-control-pedalya { border-left: none !important; }
.btn-outline-secondary { background: transparent; border-color: rgba(255,255,255,0.3) !important; color: rgba(255,255,255,0.85) !important; }
.btn-outline-secondary:hover { background: rgba(255,255,255,0.15) !important; color: #fff !important; border-color: rgba(255,255,255,0.5) !important; }
.form-check-input { background-color: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.4); }
.form-check-input:checked { background-color: var(--primary); border-color: var(--primary); }
.form-check-label { color: rgba(255,255,255,0.95) !important; font-weight: 600; text-shadow: 0 2px 6px rgba(0,0,0,0.7); }
.auth-card a { color: var(--warning) !important; font-weight: 700; text-decoration: none; text-shadow: 0 1px 3px rgba(0,0,0,0.5); }
.auth-card a:hover { color: #fff !important; text-decoration: underline; }
.auth-footer-text { color: rgba(255,255,255,0.9) !important; font-weight: 500; text-shadow: 0 1px 3px rgba(0,0,0,0.5); }
.auth-hint { color: rgba(255,255,255,0.85) !important; font-weight: 500; font-size: 0.82rem; text-shadow: 0 1px 3px rgba(0,0,0,0.5); }
.google-btn { background:#fff !important; color:#333 !important; font-weight:600; border:1px solid rgba(0,0,0,0.12); display:flex; align-items:center; justify-content:center; }
.google-btn:hover { background:#f6f6f6 !important; color:#111 !important; box-shadow:0 4px 14px rgba(0,0,0,0.15); }
.demo-accounts { margin-top: 1.25rem; padding: 1rem; border: 1px solid rgba(255,255,255,0.28); border-radius: 12px; background: rgba(0,0,0,0.2); color: rgba(255,255,255,0.95); font-size: 0.85rem; }
.demo-accounts h2 { margin: 0 0 0.65rem; color: #fff; font-size: 1rem; font-weight: 700; }
.demo-accounts ul { margin: 0.2rem 0 0.65rem; padding-left: 1.2rem; }
.demo-accounts li { margin: 0.15rem 0; overflow-wrap: anywhere; }
.demo-accounts code { color: #fff; font-size: 0.82rem; overflow-wrap: anywhere; }
.demo-accounts-note { margin: 0.65rem 0 0; font-size: 0.78rem; opacity: 0.9; }