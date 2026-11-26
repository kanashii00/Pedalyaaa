@extends('layouts.app')

@section('title', 'Create Account - Pedalya')

@section('styles')
<style>
@include('auth.auth-styles')
</style>
@endsection

@section('body')
<div class="auth-page">
    <div class="auth-card">
        <div class="auth-logo">
            <img src="{{ asset('assets/img/Logo.png') }}" alt="Pedalya" style="width:160px;height:160px;border-radius:32px;object-fit:cover;margin-bottom:24px;box-shadow:0 20px 60px rgba(0,0,0,0.3);background:#fdfdfd;padding:12px;">
            <h1 class="text-white" style="text-shadow:0 3px 10px rgba(0,0,0,0.6);font-weight:800;">Peda<span style="color: var(--warning);">lya</span></h1>
            <p class="text-white" style="opacity:0.98;text-shadow:0 2px 6px rgba(0,0,0,0.5);font-weight:500;">Create your renter account to start riding</p>
        </div>

        @if(session('status'))
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-google me-2"></i>{{ session('status') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        @if($errors->any())
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <div>
                    <ul class="mb-0" style="padding-left: 18px;">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        <form method="POST" action="{{ route('register') }}" id="registerForm" novalidate>
            @csrf

            <div class="mb-3">
                <label class="form-label-pedalya" for="name">Full Name</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-person"></i></span>
                    <input type="text" class="form-control form-control-pedalya @error('name') is-invalid @enderror" id="name" name="name"
                           value="{{ old('name', $pendingOauth['name'] ?? '') }}" {{ $pendingOauth ? 'readonly' : '' }}
                           required autofocus autocomplete="name" autocapitalize="words" spellcheck="false"
                           minlength="2" maxlength="100"
                           aria-describedby="nameError" aria-invalid="{{ $errors->has('name') ? 'true' : 'false' }}" placeholder="Juan Dela Cruz">
                </div>
                <div class="field-error" id="nameError" role="alert" aria-live="polite" @if(!$errors->has('name')) hidden @endif><i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i><span>@error('name'){{ $message }}@enderror</span></div>
            </div>

            <div class="mb-3">
                <label class="form-label-pedalya" for="email">Email Address</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                    <input type="email" class="form-control form-control-pedalya @error('email') is-invalid @enderror" id="email" name="email"
                           value="{{ old('email', $pendingOauth['email'] ?? '') }}" {{ $pendingOauth ? 'readonly' : '' }}
                           required autocomplete="email" inputmode="email" maxlength="255"
                           aria-describedby="emailError" aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}" placeholder="you@example.com">
                </div>
                <div class="field-error" id="emailError" role="alert" aria-live="polite" @if(!$errors->has('email')) hidden @endif><i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i><span>@error('email'){{ $message }}@enderror</span></div>
            </div>

            <div class="mb-3">
                <label class="form-label-pedalya" for="phoneNumber">Phone Number</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                    <input type="tel" class="form-control form-control-pedalya @error('phoneNumber') is-invalid @enderror" id="phoneNumber" name="phoneNumber"
                           value="{{ old('phoneNumber') }}" {{ $pendingOauth ? '' : 'required' }}
                           autocomplete="tel" inputmode="numeric" pattern="[0-9]*"
                           minlength="7" maxlength="15"
                           aria-describedby="phoneError" aria-invalid="{{ $errors->has('phoneNumber') ? 'true' : 'false' }}" placeholder="09171234567">
                </div>
                <div class="field-error" id="phoneError" role="alert" aria-live="polite" @if(!$errors->has('phoneNumber')) hidden @endif><i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i><span>@error('phoneNumber'){{ $message }}@enderror</span></div>
            </div>

            <div class="mb-3">
                <label class="form-label-pedalya" for="password">Create Password</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                    <input type="password" class="form-control form-control-pedalya @error('password') is-invalid @enderror" id="password" name="password"
                           required autocomplete="new-password" minlength="8" maxlength="255"
                           aria-describedby="passwordError" aria-invalid="{{ $errors->has('password') ? 'true' : 'false' }}" placeholder="Min 8 characters">
                    <button class="btn btn-outline-secondary" type="button" data-password-toggle="#password"
                            aria-label="Show password" aria-pressed="false">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
                <div class="field-error" id="passwordError" role="alert" aria-live="polite" @if(!$errors->has('password')) hidden @endif><i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i><span>@error('password'){{ $message }}@enderror</span></div>
            </div>

            <div class="mb-3">
                <label class="form-label-pedalya" for="password_confirmation">Confirm Password</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-shield-lock"></i></span>
                    <input type="password" class="form-control form-control-pedalya" id="password_confirmation"
                           name="password_confirmation" required autocomplete="new-password" minlength="8" maxlength="255"
                           aria-describedby="passwordConfirmationError" aria-invalid="false" placeholder="Repeat password">
                    <button class="btn btn-outline-secondary" type="button" data-password-toggle="#password_confirmation"
                            aria-label="Show password confirmation" aria-pressed="false">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
                <div class="field-error" id="passwordConfirmationError" role="alert" aria-live="polite" hidden><i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i><span></span></div>
            </div>

            <p class="auth-hint mb-4">
                At least 8 characters. You can sign in with this password or with
                {{ $pendingOauth ? 'your Google account' : 'Google' }}.
            </p>

            <button type="submit" class="btn-pedalya w-100 btn-lg" id="registerSubmit">
                <i class="bi bi-person-plus me-2"></i>{{ $pendingOauth ? 'Finish Creating Account' : 'Create Account' }}
            </button>
        </form>

        @if(! $pendingOauth)
        <div class="d-flex align-items-center my-4">
            <div style="flex:1; height:1px; background: rgba(255,255,255,0.25);"></div>
            <span class="px-3 auth-footer-text" style="font-size:0.85rem; font-weight:600;">OR</span>
            <div style="flex:1; height:1px; background: rgba(255,255,255,0.25);"></div>
        </div>

        <a href="{{ route('login.google') }}" class="btn w-100 btn-lg google-btn">
            <svg width="20" height="20" viewBox="0 0 48 48" style="margin-right:10px;" aria-hidden="true">
                <path fill="#FFC107" d="M43.611 20.083H42V20H24v8h11.303c-1.649 4.657-6.08 8-11.303 8-6.627 0-12-5.373-12-12s5.373-12 12-12c3.059 0 5.842 1.154 7.961 3.039l5.657-5.657C34.046 6.053 29.268 4 24 4 12.955 4 4 12.955 4 24s8.955 20 20 20 20-8.955 20-20c0-1.341-.138-2.65-.389-3.917z"/>
                <path fill="#FF3D00" d="M6.306 14.691l6.571 4.819C14.655 15.108 18.961 12 24 12c3.059 0 5.842 1.154 7.961 3.039l5.657-5.657C34.046 6.053 29.268 4 24 4 16.318 4 9.656 8.337 6.306 14.691z"/>
                <path fill="#4CAF50" d="M24 44c5.166 0 9.86-1.977 13.409-5.192l-6.19-5.238A11.91 11.91 0 0 1 24 36c-5.202 0-9.619-3.317-11.283-7.946l-6.522 5.025C9.505 39.556 16.227 44 24 44z"/>
                <path fill="#1976D2" d="M43.611 20.083H42V20H24v8h11.303a12.04 12.04 0 0 1-4.087 5.571l.003-.002 6.19 5.238C36.971 39.205 44 34 44 24c0-1.341-.138-2.65-.389-3.917z"/>
            </svg>
            Sign up with Google
        </a>
        @endif

        <p class="text-center mt-4 mb-0 auth-footer-text" style="font-size: 0.9rem;">
            Already have an account?
            <a href="{{ route('login') }}">Sign in</a>
        </p>

        <p class="text-center mt-3 mb-0 auth-footer-text" style="font-size: 0.85rem;">
            <a href="{{ route('home') }}"><i class="bi bi-arrow-left me-1"></i>Back to Home</a>
        </p>
    </div>
</div>
@endsection

@section('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('[data-password-toggle]').forEach(function(toggle) {
            const field = document.querySelector(toggle.getAttribute('data-password-toggle'));
            if (!field) {
                return;
            }
            const label = field.id === 'password_confirmation' ? 'password confirmation' : 'password';
            toggle.addEventListener('click', function() {
                const isVisible = field.getAttribute('type') === 'password';
                const type = isVisible ? 'text' : 'password';
                field.setAttribute('type', type);
                this.querySelector('i').classList.toggle('bi-eye');
                this.querySelector('i').classList.toggle('bi-eye-slash');
                this.setAttribute('aria-label', `${isVisible ? 'Hide' : 'Show'} ${label}`);
                this.setAttribute('aria-pressed', String(isVisible));
            });
        });

        var form = document.getElementById('registerForm');
        if (!form) {
            return;
        }
        var nameInput = document.getElementById('name');
        var emailInput = document.getElementById('email');
        var phoneInput = document.getElementById('phoneNumber');
        var passwordInput = document.getElementById('password');
        var confirmInput = document.getElementById('password_confirmation');
        var submitBtn = document.getElementById('registerSubmit');
        var nameError = document.getElementById('nameError');
        var emailError = document.getElementById('emailError');
        var phoneError = document.getElementById('phoneError');
        var passwordError = document.getElementById('passwordError');
        var confirmError = document.getElementById('passwordConfirmationError');

        var NAME_MESSAGE = 'Please enter a valid full name.';
        var EMAIL_MESSAGE = 'Please enter a valid email address.';
        var PHONE_MESSAGE = 'Please enter a valid phone number.';
        var PASSWORD_MESSAGE = 'Password must be at least 8 characters.';
        var CONFIRM_MESSAGE = 'Passwords do not match.';
        var NAME_ALLOWED = /[0-9]/;
        try {
            NAME_ALLOWED = new RegExp('[\\p{L}\\p{M}\\s.\'\\-\u2019]', 'u');
        } catch (e) {
            NAME_ALLOWED = /[A-Za-z\u00C0-\u024F\s.'\-\u2019]/;
        }
        var NAME_FULL;
        try {
            NAME_FULL = new RegExp('^(?=.*\\p{L})[\\p{L}\\p{M}\\s.\'\-\u2019]+$', 'u');
        } catch (e) {
            NAME_FULL = /^(?=.*[A-Za-z\u00C0-\u024F])[A-Za-z\u00C0-\u024F\s.'\-\u2019]+$/;
        }
        var PHONE_FULL = /^[0-9]{7,15}$/;
        var EMAIL_FULL = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
        var nameTouched = false;
        var emailTouched = false;
        var phoneTouched = false;
        var passwordTouched = false;
        var confirmTouched = false;
        var formSubmitted = false;

        function errorTextEl(errorEl) {
            if (!errorEl) {
                return null;
            }
            var span = errorEl.querySelector('span');
            return span || errorEl;
        }

        function showError(input, errorEl, message) {
            if (errorEl) {
                var target = errorTextEl(errorEl);
                if (target) {
                    target.textContent = message;
                }
                errorEl.hidden = false;
            }
            if (input) {
                input.classList.add('is-invalid');
                input.setAttribute('aria-invalid', 'true');
            }
        }

        function hideError(input, errorEl) {
            if (errorEl) {
                var target = errorTextEl(errorEl);
                if (target) {
                    target.textContent = '';
                }
                errorEl.hidden = true;
            }
            if (input) {
                input.classList.remove('is-invalid');
                input.setAttribute('aria-invalid', 'false');
            }
        }

        function syncFilledState(input) {
            if (!input) {
                return;
            }
            input.classList.toggle('has-value', input.value.length > 0);
        }

        function collapseName(value) {
            return value.replace(/\s+/g, ' ').trim();
        }

        function isNameValid(value) {
            var normalized = collapseName(value);
            if (normalized.length < 2 || normalized.length > 100) {
                return false;
            }
            return NAME_FULL.test(normalized);
        }

        function isEmailValid(value) {
            var trimmed = value.trim();
            if (trimmed === '' || trimmed.length > 255) {
                return false;
            }
            return EMAIL_FULL.test(trimmed);
        }

        function isPhoneValid(value) {
            var trimmed = value.trim();
            var required = phoneInput && phoneInput.hasAttribute('required');
            if (trimmed === '') {
                return !required;
            }
            return PHONE_FULL.test(trimmed);
        }

        function isPasswordValid(value) {
            return value.length >= 8;
        }

        function doPasswordsMatch() {
            if (!passwordInput || !confirmInput) {
                return true;
            }
            return confirmInput.value !== '' && confirmInput.value === passwordInput.value;
        }

        function validateName(show) {
            if (!nameInput) {
                return true;
            }
            var value = nameInput.value;
            if (value.trim() === '' && !nameInput.hasAttribute('required')) {
                if (show) {
                    showError(nameInput, nameError, NAME_MESSAGE);
                }
                return false;
            }
            var ok = isNameValid(value);
            if (!ok && (show || nameTouched || formSubmitted || value !== '')) {
                showError(nameInput, nameError, NAME_MESSAGE);
            } else if (ok) {
                hideError(nameInput, nameError);
            } else {
                hideError(nameInput, nameError);
            }
            return ok;
        }

        function validateEmail(show) {
            if (!emailInput) {
                return true;
            }
            if (emailInput.readOnly) {
                var lockedOk = isEmailValid(emailInput.value);
                if (lockedOk) {
                    hideError(emailInput, emailError);
                } else if (show || formSubmitted) {
                    showError(emailInput, emailError, EMAIL_MESSAGE);
                }
                return lockedOk;
            }
            var value = emailInput.value;
            var ok = isEmailValid(value);
            if (!ok && (show || emailTouched || formSubmitted || value !== '')) {
                showError(emailInput, emailError, EMAIL_MESSAGE);
            } else if (ok) {
                hideError(emailInput, emailError);
            } else {
                hideError(emailInput, emailError);
            }
            return ok;
        }

        function validatePhone(show) {
            if (!phoneInput) {
                return true;
            }
            var value = phoneInput.value;
            var trimmed = value.trim();
            var required = phoneInput.hasAttribute('required');
            if (trimmed === '' && !required) {
                hideError(phoneInput, phoneError);
                return true;
            }
            var ok = isPhoneValid(value);
            if (!ok && (show || phoneTouched || formSubmitted || value !== '')) {
                showError(phoneInput, phoneError, PHONE_MESSAGE);
            } else if (ok) {
                hideError(phoneInput, phoneError);
            } else {
                hideError(phoneInput, phoneError);
            }
            return ok;
        }

        function validatePassword(show) {
            if (!passwordInput) {
                return true;
            }
            var ok = isPasswordValid(passwordInput.value);
            if (!ok && (show || passwordTouched || formSubmitted || passwordInput.value !== '')) {
                showError(passwordInput, passwordError, PASSWORD_MESSAGE);
            } else if (ok) {
                hideError(passwordInput, passwordError);
            } else {
                hideError(passwordInput, passwordError);
            }
            return ok;
        }

        function validateConfirm(show) {
            if (!confirmInput || !passwordInput) {
                return true;
            }
            var ok = doPasswordsMatch();
            if (!ok && (show || confirmTouched || formSubmitted || confirmInput.value !== '')) {
                showError(confirmInput, confirmError, CONFIRM_MESSAGE);
            } else if (ok) {
                hideError(confirmInput, confirmError);
            } else {
                hideError(confirmInput, confirmError);
            }
            return ok;
        }

        function updateSubmitState() {
            if (!submitBtn) {
                return;
            }
            var nameOk = nameInput ? isNameValid(nameInput.value) : true;
            var emailOk = emailInput ? isEmailValid(emailInput.value) : true;
            var phoneOk = phoneInput ? isPhoneValid(phoneInput.value) : true;
            var passOk = passwordInput ? isPasswordValid(passwordInput.value) : true;
            var confirmOk = doPasswordsMatch();
            submitBtn.disabled = !(nameOk && emailOk && phoneOk && passOk && confirmOk);
        }

        function isControlKey(e) {
            if (e.ctrlKey || e.metaKey || e.altKey) {
                return true;
            }
            return e.key.length > 1;
        }

        [nameInput, emailInput, phoneInput, passwordInput, confirmInput].forEach(function(input) {
            if (!input) {
                return;
            }
            syncFilledState(input);
            input.addEventListener('input', function() {
                syncFilledState(input);
            });
            input.addEventListener('change', function() {
                syncFilledState(input);
            });
        });
        document.addEventListener('animationstart', function(e) {
            if (e.animationName === 'pedalyaAutofillFix' && e.target.classList) {
                e.target.classList.add('has-value');
            }
        }, true);

        if (nameInput && !nameInput.readOnly) {
            nameInput.addEventListener('keydown', function(e) {
                if (isControlKey(e)) {
                    return;
                }
                if (e.key.length === 1 && !NAME_ALLOWED.test(e.key)) {
                    e.preventDefault();
                    nameTouched = true;
                    showError(nameInput, nameError, NAME_MESSAGE);
                    updateSubmitState();
                }
            });

            nameInput.addEventListener('paste', function(e) {
                var clipboard = e.clipboardData || window.clipboardData;
                var text = clipboard ? clipboard.getData('text') : '';
                if (!text) {
                    return;
                }
                var sanitized = '';
                for (var i = 0; i < text.length; i++) {
                    var ch = text[i];
                    if (NAME_ALLOWED.test(ch) && sanitized.length < 100) {
                        sanitized += ch;
                    }
                }
                if (sanitized !== text) {
                    e.preventDefault();
                    var start = nameInput.selectionStart || nameInput.value.length;
                    var end = nameInput.selectionEnd || nameInput.value.length;
                    var next = nameInput.value.slice(0, start) + sanitized + nameInput.value.slice(end);
                    nameInput.value = next.slice(0, 100);
                    syncFilledState(nameInput);
                    nameTouched = true;
                    showError(nameInput, nameError, NAME_MESSAGE);
                    updateSubmitState();
                }
            });

            nameInput.addEventListener('input', function() {
                if (nameInput.value.length > 100) {
                    nameInput.value = nameInput.value.slice(0, 100);
                }
                validateName(false);
                updateSubmitState();
            });

            nameInput.addEventListener('blur', function() {
                nameTouched = true;
                nameInput.value = collapseName(nameInput.value);
                syncFilledState(nameInput);
                validateName(true);
                updateSubmitState();
            });
        } else if (nameInput) {
            nameInput.addEventListener('blur', function() {
                validateName(true);
                updateSubmitState();
            });
        }

        if (emailInput && !emailInput.readOnly) {
            emailInput.addEventListener('input', function() {
                validateEmail(false);
                updateSubmitState();
            });
            emailInput.addEventListener('blur', function() {
                emailTouched = true;
                emailInput.value = emailInput.value.trim();
                syncFilledState(emailInput);
                validateEmail(true);
                updateSubmitState();
            });
        }

        if (phoneInput) {
            phoneInput.addEventListener('keydown', function(e) {
                if (isControlKey(e)) {
                    return;
                }
                if (e.key.length === 1 && !/[0-9]/.test(e.key)) {
                    e.preventDefault();
                    phoneTouched = true;
                    showError(phoneInput, phoneError, PHONE_MESSAGE);
                    updateSubmitState();
                }
            });

            phoneInput.addEventListener('paste', function(e) {
                var clipboard = e.clipboardData || window.clipboardData;
                var text = clipboard ? clipboard.getData('text') : '';
                if (!text) {
                    return;
                }
                var digits = text.replace(/\D/g, '').slice(0, 15);
                if (digits !== text) {
                    e.preventDefault();
                    phoneTouched = true;
                    var start = phoneInput.selectionStart || phoneInput.value.length;
                    var end = phoneInput.selectionEnd || phoneInput.value.length;
                    var currentDigits = phoneInput.value.replace(/\D/g, '');
                    var next = (currentDigits.slice(0, start) + digits + currentDigits.slice(end)).slice(0, 15);
                    phoneInput.value = next;
                    syncFilledState(phoneInput);
                    showError(phoneInput, phoneError, PHONE_MESSAGE);
                    updateSubmitState();
                }
            });

            phoneInput.addEventListener('input', function() {
                var raw = phoneInput.value;
                if (/[^0-9]/.test(raw)) {
                    phoneInput.value = raw.replace(/\D/g, '').slice(0, 15);
                    phoneTouched = true;
                    showError(phoneInput, phoneError, PHONE_MESSAGE);
                } else {
                    if (phoneInput.value.length > 15) {
                        phoneInput.value = phoneInput.value.slice(0, 15);
                    }
                    validatePhone(false);
                }
                syncFilledState(phoneInput);
                updateSubmitState();
            });

            phoneInput.addEventListener('blur', function() {
                phoneTouched = true;
                phoneInput.value = phoneInput.value.trim();
                syncFilledState(phoneInput);
                validatePhone(true);
                updateSubmitState();
            });
        }

        if (passwordInput) {
            passwordInput.addEventListener('input', function() {
                validatePassword(false);
                validateConfirm(false);
                updateSubmitState();
            });
            passwordInput.addEventListener('blur', function() {
                passwordTouched = true;
                validatePassword(true);
                if (confirmInput && confirmInput.value !== '') {
                    validateConfirm(true);
                }
                updateSubmitState();
            });
        }
        if (confirmInput) {
            confirmInput.addEventListener('input', function() {
                validateConfirm(false);
                updateSubmitState();
            });
            confirmInput.addEventListener('blur', function() {
                confirmTouched = true;
                validateConfirm(true);
                updateSubmitState();
            });
        }

        form.addEventListener('submit', function(e) {
            formSubmitted = true;
            if (nameInput) {
                nameInput.value = collapseName(nameInput.value);
                syncFilledState(nameInput);
            }
            if (emailInput && !emailInput.readOnly) {
                emailInput.value = emailInput.value.trim();
                syncFilledState(emailInput);
            }
            if (phoneInput) {
                phoneInput.value = phoneInput.value.trim();
                syncFilledState(phoneInput);
            }
            var nameOk = validateName(true);
            var emailOk = validateEmail(true);
            var phoneOk = validatePhone(true);
            var passOk = validatePassword(true);
            var confirmOk = validateConfirm(true);
            updateSubmitState();
            if (!nameOk || !emailOk || !phoneOk || !passOk || !confirmOk) {
                e.preventDefault();
                var order = [
                    { ok: nameOk, input: nameInput },
                    { ok: emailOk, input: emailInput },
                    { ok: phoneOk, input: phoneInput },
                    { ok: passOk, input: passwordInput },
                    { ok: confirmOk, input: confirmInput }
                ];
                for (var i = 0; i < order.length; i++) {
                    if (!order[i].ok && order[i].input) {
                        order[i].input.focus({ preventScroll: false });
                        break;
                    }
                }
            }
        });

        validateName(false);
        validateEmail(false);
        validatePhone(false);
        updateSubmitState();
    });
</script>
@endsection