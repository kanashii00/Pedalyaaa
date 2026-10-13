@extends('layouts.admin')

@section('title', 'Register Customer — Pedalya Admin')

@section('page-header')
    <h1>Register Customer</h1>
@endsection

@section('actions')
    <a href="{{ route('admin.riders.index') }}" class="btn-admin btn-admin--secondary btn-admin--sm">
        <i class="bi bi-arrow-left me-1"></i>Back to List
    </a>
@endsection

@section('content')
<div class="row justify-content-center">
    <div class="col-12 col-lg-10 col-xl-9">

        <form method="POST" action="{{ route('admin.riders.store') }}" class="admin-form register-form" enctype="multipart/form-data" novalidate>
            @csrf

            {{-- ── Customer Information ────────────────────────────── --}}
            <div class="admin-card">
                <div class="admin-card__head">
                    <div class="admin-card__title">
                        <i class="bi bi-person"></i>
                        <span>Customer Information</span>
                    </div>
                </div>
                <div class="admin-card__body">
                    <div class="mb-2">
                        <label class="form-label" for="name">
                            Full Name <span class="form-required">*</span>
                        </label>
                        <input type="text"
                               class="form-control @error('name') is-invalid @enderror"
                               id="name" name="name"
                               value="{{ old('name') }}"
                               required autofocus
                               placeholder="e.g. Juan Dela Cruz">
                        @error('name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    {{-- ── Valid ID Photo Upload ─────────────────────── --}}
                    <div class="mt-2">
                        <span class="form-label" id="idImageLabel">
                            Valid ID Photo
                        </span>
                        <p class="id-upload-hint">
                            Upload a clear photo of the customer's valid identification document
                            (e.g. driver's license, passport, or school ID). JPG, JPEG, PNG or
                            WEBP, up to 5&nbsp;MB. The photo can be reviewed later in the
                            customer verification dialog.
                        </p>
                        <div class="id-upload-dropzone" id="idDropzone" role="button" tabindex="0"
                             aria-labelledby="idImageLabel" aria-describedby="idUploadHelp">
                            <input type="file" id="id_image" name="id_image" class="d-none"
                                   accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                            <div class="id-upload-idle" id="idUploadIdle">
                                <span class="id-upload-icon" aria-hidden="true"><i class="bi bi-cloud-arrow-up"></i></span>
                                <p class="id-upload-text" id="idUploadHelp">
                                    Drag &amp; drop the ID photo here, or
                                    <button type="button" class="id-upload-browse" id="idBrowseBtn">choose a file</button>
                                </p>
                                <small class="id-upload-formats">JPG · JPEG · PNG · WEBP — max 5 MB</small>
                            </div>
                            <div class="id-upload-preview d-none" id="idUploadPreview">
                                <img id="idPreviewImg" src="#" alt="ID photo preview">
                                <div class="id-upload-preview-actions">
                                    <span class="id-upload-filename" id="idFileName"></span>
                                    <button type="button" class="btn-admin btn-admin--secondary btn-admin--sm" id="idReplaceBtn">
                                        <i class="bi bi-arrow-repeat me-1"></i>Replace
                                    </button>
                                    <button type="button" class="btn-admin btn-admin--danger btn-admin--sm" id="idRemoveBtn">
                                        <i class="bi bi-trash me-1"></i>Remove
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="invalid-feedback id-upload-error d-none" id="idUploadError" role="alert"></div>
                        @error('id_image')
                            <div class="invalid-feedback" style="display:block;">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </div>

            {{-- ── Contact Information ─────────────────────────────── --}}
            <div class="admin-card">
                <div class="admin-card__head">
                    <div class="admin-card__title">
                        <i class="bi bi-telephone"></i>
                        <span>Contact Information</span>
                    </div>
                </div>
                <div class="admin-card__body">
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label" for="email">
                                Email Address <span class="form-required">*</span>
                            </label>
                            <input type="email"
                                   class="form-control @error('email') is-invalid @enderror"
                                   id="email" name="email"
                                   value="{{ old('email') }}"
                                   required
                                   placeholder="e.g. juan@example.com">
                            @error('email')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="phoneNumber">
                                Phone Number
                            </label>
                            <input type="text"
                                   class="form-control @error('phoneNumber') is-invalid @enderror"
                                   id="phoneNumber" name="phoneNumber"
                                   value="{{ old('phoneNumber') }}"
                                   placeholder="e.g. 09XX XXX XXXX">
                            @error('phoneNumber')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="address">
                                Address
                            </label>
                            <input type="text"
                                   class="form-control @error('address') is-invalid @enderror"
                                   id="address" name="address"
                                   value="{{ old('address') }}"
                                   placeholder="e.g. Azuela Cove, Davao City">
                            @error('address')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>
            </div>

            {{-- ── Account Security ────────────────────────────────── --}}
            <div class="admin-card">
                <div class="admin-card__head">
                    <div class="admin-card__title">
                        <i class="bi bi-shield-lock"></i>
                        <span>Account Security</span>
                    </div>
                </div>
                <div class="admin-card__body">
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label" for="password">
                                Password <span class="form-required">*</span>
                            </label>
                            <input type="password"
                                   class="form-control @error('password') is-invalid @enderror"
                                   id="password" name="password"
                                   required
                                   placeholder="Min 8 characters">
                            @error('password')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="password_confirmation">
                                Confirm Password <span class="form-required">*</span>
                            </label>
                            <input type="password"
                                   class="form-control"
                                   id="password_confirmation" name="password_confirmation"
                                   required
                                   placeholder="Repeat password">
                        </div>
                    </div>
                </div>
            </div>

            {{-- ── Submit ──────────────────────────────────────────── --}}
            <div class="d-flex align-items-center justify-content-end gap-2 mb-3">
                <a href="{{ route('admin.riders.index') }}" class="btn-admin btn-admin--secondary">
                    Cancel
                </a>
                <button type="submit" class="btn-admin btn-admin--primary">
                    <i class="bi bi-person-plus me-1"></i>Register Customer
                </button>
            </div>
        </form>

    </div>
</div>
@endsection

@section('scripts')
<script>
(function () {
    'use strict';

    var MAX_BYTES = 5 * 1024 * 1024;
    var ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    var ALLOWED_EXTS = ['jpg', 'jpeg', 'png', 'webp'];

    var dropzone = document.getElementById('idDropzone');
    var input = document.getElementById('id_image');
    var idle = document.getElementById('idUploadIdle');
    var preview = document.getElementById('idUploadPreview');
    var previewImg = document.getElementById('idPreviewImg');
    var fileName = document.getElementById('idFileName');
    var errorBox = document.getElementById('idUploadError');
    if (!dropzone || !input) return;

    function showError(message) {
        if (!errorBox) return;
        errorBox.textContent = message;
        errorBox.classList.remove('d-none');
        input.classList.add('is-invalid');
    }

    function clearError() {
        if (!errorBox) return;
        errorBox.textContent = '';
        errorBox.classList.add('d-none');
        input.classList.remove('is-invalid');
    }

    function extOf(name) {
        var parts = String(name || '').toLowerCase().split('.');
        return parts.length > 1 ? parts.pop() : '';
    }

    function validateFile(file) {
        if (!file) return 'Please choose an image file.';
        if (ALLOWED_TYPES.indexOf(file.type) === -1 && ALLOWED_EXTS.indexOf(extOf(file.name)) === -1) {
            return 'Unsupported file type. Please upload a JPG, JPEG, PNG or WEBP image.';
        }
        if (file.size > MAX_BYTES) {
            return 'The ID photo is too large. Maximum file size is 5 MB.';
        }
        if (file.size === 0) {
            return 'The selected file is empty. Please choose a valid image.';
        }
        return null;
    }

    function showPreview(file) {
        var reader = new FileReader();
        reader.onload = function (e) {
            previewImg.src = e.target.result;
            fileName.textContent = file.name;
            idle.classList.add('d-none');
            preview.classList.remove('d-none');
        };
        reader.readAsDataURL(file);
    }

    function handleFile(file) {
        clearError();
        if (!file) return;
        var problem = validateFile(file);
        if (problem) {
            input.value = '';
            showError(problem);
            return;
        }
        showPreview(file);
    }

    function openPicker() { input.click(); }

    dropzone.addEventListener('click', function (e) {
        if (e.target.closest('#idRemoveBtn, #idReplaceBtn, .id-upload-browse')) return;
        if (preview.classList.contains('d-none')) openPicker();
    });
    dropzone.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            if (preview.classList.contains('d-none')) openPicker();
        }
    });

    document.getElementById('idBrowseBtn').addEventListener('click', function (e) {
        e.stopPropagation();
        openPicker();
    });
    document.getElementById('idReplaceBtn').addEventListener('click', function (e) {
        e.stopPropagation();
        openPicker();
    });
    document.getElementById('idRemoveBtn').addEventListener('click', function (e) {
        e.stopPropagation();
        input.value = '';
        previewImg.removeAttribute('src');
        fileName.textContent = '';
        preview.classList.add('d-none');
        idle.classList.remove('d-none');
        clearError();
    });

    input.addEventListener('change', function () {
        handleFile(input.files && input.files[0]);
    });

    ['dragenter', 'dragover'].forEach(function (evt) {
        dropzone.addEventListener(evt, function (e) {
            e.preventDefault();
            dropzone.classList.add('is-dragover');
        });
    });
    ['dragleave', 'drop'].forEach(function (evt) {
        dropzone.addEventListener(evt, function (e) {
            e.preventDefault();
            dropzone.classList.remove('is-dragover');
        });
    });
    dropzone.addEventListener('drop', function (e) {
        var files = e.dataTransfer && e.dataTransfer.files;
        if (!files || !files.length) return;
        try {
            input.files = files;
        } catch (err) {
            /* Some browsers block programmatic assignment; validate the
               dropped file directly and let the user re-pick on submit. */
        }
        handleFile(files[0]);
    });
})();
</script>
@endsection
