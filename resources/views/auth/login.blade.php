@extends('layouts.app')

@section('title', 'Sign In - Pedalya')

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
            <p class="text-white" style="opacity:0.98;text-shadow:0 2px 6px rgba(0,0,0,0.5);font-weight:500;">Sign in to continue to your dashboard</p>
        </div>

        @if(session('error'))
            <div class="auth-alert-error alert-dismissible fade show" role="alert">
                <span class="auth-alert-icon" aria-hidden="true"><i class="bi bi-info"></i></span>
                <div class="auth-alert-text">{{ session('error') }}</div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        @if($errors->any())
            <div class="auth-alert-error alert-dismissible fade show" role="alert">
                <span class="auth-alert-icon" aria-hidden="true"><i class="bi bi-info"></i></span>
                <div class="auth-alert-text">
                    <ul>
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        <form method="POST" action="{{ route('login') }}">
            @csrf

            <div class="mb-3">
                <label class="form-label-pedalya" for="email">Email Address</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                    <input type="email" class="form-control form-control-pedalya" id="email" name="email"
                           value="{{ old('email', request()->cookie('remember_email')) }}" required autofocus autocomplete="email" placeholder="you@example.com">
                </div>
            </div>

            <div class="mb-3">
                <div class="d-flex justify-content-between align-items-center">
                    <label class="form-label-pedalya" for="password">Password</label>
                </div>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                    <input type="password" class="form-control form-control-pedalya" id="password" name="password"
                           required autocomplete="current-password" placeholder="••••••••">
                    <button class="btn btn-outline-secondary" type="button" id="togglePassword"
                            aria-label="Show password" aria-pressed="false">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center mb-4">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="remember" id="remember" value="1"
                           @checked(old('remember', request()->cookie('remember_email')))>
                    <label class="form-check-label" for="remember">Remember me</label>
                </div>
            </div>

            <button type="submit" class="btn-pedalya w-100 btn-lg">
                <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
            </button>
        </form>

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
            Sign in with Google
        </a>

        <p class="text-center mt-4 mb-0 auth-footer-text" style="font-size: 0.9rem;">
            Don't have an account?
            <a href="{{ route('register') }}">Create one here</a>
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
        const toggle = document.getElementById('togglePassword');
        const password = document.getElementById('password');
        if (toggle && password) {
            toggle.addEventListener('click', function() {
                const isVisible = password.getAttribute('type') === 'password';
                const type = isVisible ? 'text' : 'password';
                password.setAttribute('type', type);
                this.querySelector('i').classList.toggle('bi-eye');
                this.querySelector('i').classList.toggle('bi-eye-slash');
                this.setAttribute('aria-label', `${isVisible ? 'Hide' : 'Show'} password`);
                this.setAttribute('aria-pressed', String(isVisible));
            });
        }
    });
</script>
@endsection
