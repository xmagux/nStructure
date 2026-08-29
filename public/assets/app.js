(() => {
    'use strict';

    const root = document.documentElement;
    const body = document.body;
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    // A session that expires while a page is left open otherwise just sits
    // there looking normal but silently failing every background request
    // (poll/refresh calls swallow the error) until the user happens to
    // navigate somewhere. Any fetch() call getting a 401 back means the
    // session is gone, so force a real page reload — the server-side auth
    // middleware then does the actual redirect to /login.
    const nativeFetch = window.fetch.bind(window);
    window.fetch = async (...args) => {
        const response = await nativeFetch(...args);
        if (response.status === 401) {
            window.location.reload();
        }
        return response;
    };
    const graphInstances = [];
    let selectedPort = null;
    let selectPanelCanvasPort = null;

    const readJson = (element) => {
        if (!element) return null;
        try {
            return JSON.parse(element.textContent);
        } catch {
            return null;
        }
    };

    const createElement = (tag, className, text) => {
        const element = document.createElement(tag);
        if (className) element.className = className;
        if (text !== undefined) element.textContent = text;
        return element;
    };

    const refreshIcons = () => {
        if (window.lucide?.createIcons) {
            window.lucide.createIcons({ attrs: { 'stroke-width': 1.8 } });
        }
    };

    const themePalette = () => {
        const dark = root.dataset.theme === 'dark';
        return {
            dark,
            canvas: dark ? '#17181b' : '#f7f9fc',
            surface: dark ? '#202124' : '#ffffff',
            surfaceRaised: dark ? '#2b2c30' : '#f1f4f9',
            surfaceMuted: dark ? '#25262a' : '#e9eef6',
            border: dark ? '#3c4043' : '#d8dee9',
            borderStrong: dark ? '#5f6368' : '#aab4c3',
            text: dark ? '#f1f3f4' : '#202124',
            muted: dark ? '#9aa0a6' : '#687386',
            blue: dark ? '#8ab4f8' : '#0b57d0',
            indigo: dark ? '#a8a4ff' : '#5b5bd6',
            cyan: dark ? '#78d9ec' : '#008a9a',
            amber: dark ? '#fdd663' : '#e37400',
            green: dark ? '#81c995' : '#188038',
            red: dark ? '#f28b82' : '#d93025',
            shadow: dark ? '#000000' : '#8793a5',
        };
    };

    const storedTheme = localStorage.getItem('nstructure-theme');
    const preferredTheme = window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
    root.dataset.theme = storedTheme || preferredTheme;

    const showToast = (message, type = 'success') => {
        const toast = document.querySelector('[data-toast]');
        if (!toast) return;
        toast.textContent = message;
        toast.classList.toggle('error', type === 'error');
        toast.classList.add('visible');
        window.clearTimeout(showToast.timeoutId);
        showToast.timeoutId = window.setTimeout(() => toast.classList.remove('visible'), 3200);
    };

    const updateThemeIcon = () => {
        const icon = document.querySelector('[data-theme-toggle] [data-lucide]');
        if (icon) icon.dataset.lucide = root.dataset.theme === 'dark' ? 'sun' : 'moon';
        refreshIcons();
    };

    document.querySelector('[data-theme-toggle]')?.addEventListener('click', () => {
        root.dataset.theme = root.dataset.theme === 'dark' ? 'light' : 'dark';
        localStorage.setItem('nstructure-theme', root.dataset.theme);
        updateThemeIcon();
        window.dispatchEvent(new CustomEvent('nstructure:theme'));
    });

    const sidebarMedia = window.matchMedia('(min-width: 901px)');
    const sidebarStorageKey = 'nstructure-sidebar-collapsed';
    const sidebarToggle = document.querySelector('[data-sidebar-toggle]');
    const storedSidebarState = localStorage.getItem(sidebarStorageKey) === 'true';
    body.classList.toggle('sidebar-collapsed', storedSidebarState);

    const updateSidebarToggle = () => {
        if (!sidebarToggle) return;
        const collapsed = body.classList.contains('sidebar-collapsed');
        const label = collapsed ? sidebarToggle.dataset.expandLabel : sidebarToggle.dataset.collapseLabel;
        sidebarToggle.setAttribute('aria-label', label || 'Toggle navigation');
        sidebarToggle.title = label || '';
        sidebarToggle.setAttribute('aria-expanded', String(!collapsed));
        const icon = sidebarToggle.querySelector('[data-lucide]');
        if (icon) icon.dataset.lucide = collapsed ? 'panel-left-open' : 'panel-left-close';
        refreshIcons();
    };

    document.querySelectorAll('[data-sidebar-open]').forEach((button) => button.addEventListener('click', () => body.classList.add('sidebar-open')));
    document.querySelectorAll('[data-sidebar-close]').forEach((button) => button.addEventListener('click', () => body.classList.remove('sidebar-open')));
    sidebarToggle?.addEventListener('click', () => {
        if (!sidebarMedia.matches) {
            body.classList.remove('sidebar-open');
            return;
        }
        const collapsed = !body.classList.contains('sidebar-collapsed');
        body.classList.toggle('sidebar-collapsed', collapsed);
        localStorage.setItem(sidebarStorageKey, String(collapsed));
        updateSidebarToggle();
    });
    sidebarMedia.addEventListener('change', updateSidebarToggle);
    updateSidebarToggle();

    const formatFileSize = (bytes) => `${(bytes / 1048576).toFixed(1)} MB`;
    document.querySelectorAll('[data-modal-open]').forEach((trigger) => {
        const target = document.getElementById(trigger.dataset.modalOpen);
        if (!target) return;
        trigger.addEventListener('click', () => target.showModal());
    });
    document.querySelectorAll('dialog:has([data-modal-close])').forEach((dialog) => {
        dialog.querySelectorAll('[data-modal-close]').forEach((closeButton) => closeButton.addEventListener('click', () => dialog.close()));
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) dialog.close();
        });
    });

    document.querySelectorAll('[data-asset-gallery-toggle]').forEach((toggle) => {
        const body = toggle.closest('[data-asset-gallery]')?.querySelector('[data-asset-gallery-body]');
        toggle.addEventListener('click', () => {
            const expanded = toggle.getAttribute('aria-expanded') === 'true';
            toggle.setAttribute('aria-expanded', String(!expanded));
            if (body) body.hidden = expanded;
        });
    });

    document.querySelectorAll('[data-asset-image-form]').forEach((form) => {
        const input = form.querySelector('[data-asset-image-input]');
        const dropzone = form.querySelector('[data-asset-dropzone]');
        const selection = form.querySelector('[data-asset-upload-selection]');
        const preview = form.querySelector('[data-asset-upload-preview]');
        const fileName = form.querySelector('[data-asset-upload-name]');
        const fileSize = form.querySelector('[data-asset-upload-size]');
        const submitButton = form.querySelector('[data-asset-upload-submit]');
        const errorElement = form.querySelector('[data-form-error]');
        let selectedFile = null;
        let previewUrl = '';

        const selectImage = (file) => {
            errorElement.hidden = true;
            if (!file || !['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
                selectedFile = null;
                selection.hidden = true;
                errorElement.textContent = body.dataset.imageErrorType;
                errorElement.hidden = false;
                return;
            }
            if (file.size > 8 * 1024 * 1024) {
                selectedFile = null;
                selection.hidden = true;
                errorElement.textContent = body.dataset.imageErrorSize;
                errorElement.hidden = false;
                return;
            }
            selectedFile = file;
            if (previewUrl) URL.revokeObjectURL(previewUrl);
            previewUrl = URL.createObjectURL(file);
            preview.src = previewUrl;
            fileName.textContent = file.name;
            fileSize.textContent = formatFileSize(file.size);
            selection.hidden = false;
        };

        input?.addEventListener('change', () => selectImage(input.files?.[0]));
        ['dragenter', 'dragover'].forEach((eventName) => dropzone?.addEventListener(eventName, (event) => {
            event.preventDefault();
            dropzone.classList.add('is-dragging');
        }));
        ['dragleave', 'drop'].forEach((eventName) => dropzone?.addEventListener(eventName, (event) => {
            event.preventDefault();
            dropzone.classList.remove('is-dragging');
        }));
        dropzone?.addEventListener('drop', (event) => selectImage(event.dataTransfer?.files?.[0]));
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (!selectedFile) return;
            submitButton.disabled = true;
            errorElement.hidden = true;
            const payload = new FormData();
            payload.append('image', selectedFile, selectedFile.name);
            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'X-CSRF-Token': csrfToken },
                    body: payload,
                });
                const result = await response.json().catch(() => ({}));
                if (!response.ok) throw new Error(result.error || body.dataset.toastError);
                showToast(body.dataset.toastImageUploaded || body.dataset.toastSaved);
                window.setTimeout(() => window.location.reload(), 450);
            } catch (error) {
                errorElement.textContent = error.message || body.dataset.toastError;
                errorElement.hidden = false;
                submitButton.disabled = false;
            }
        });
    });

    const lightbox = createElement('dialog', 'asset-lightbox');
    const lightboxFrame = createElement('div', 'asset-lightbox-frame');
    const lightboxImage = createElement('img');
    const lightboxClose = createElement('button', 'icon-button asset-lightbox-close');
    lightboxClose.type = 'button';
    lightboxClose.setAttribute('aria-label', body.dataset.actionClose || 'Close image');
    const lightboxCloseIcon = createElement('i');
    lightboxCloseIcon.dataset.lucide = 'x';
    lightboxClose.append(lightboxCloseIcon);
    lightboxFrame.append(lightboxImage, lightboxClose);
    lightbox.append(lightboxFrame);
    body.append(lightbox);
    document.querySelectorAll('[data-asset-lightbox-open]').forEach((button) => button.addEventListener('click', () => {
        lightboxImage.src = button.dataset.imageSrc || '';
        lightboxImage.alt = button.dataset.imageAlt || '';
        lightbox.showModal();
        refreshIcons();
    }));
    lightboxClose.addEventListener('click', () => lightbox.close());
    lightbox.addEventListener('click', (event) => {
        if (event.target === lightbox) lightbox.close();
    });

    const locationModal = document.querySelector('#location-modal');
    document.querySelectorAll('[data-location-modal-open]').forEach((button) => button.addEventListener('click', () => locationModal?.showModal()));
    document.querySelectorAll('[data-location-modal-close]').forEach((button) => button.addEventListener('click', () => locationModal?.close()));
    locationModal?.addEventListener('click', (event) => {
        if (event.target === locationModal) locationModal.close();
    });

    document.querySelectorAll('[data-icon-picker]').forEach((picker) => {
        const hidden = picker.querySelector('input[type="hidden"]');
        const options = picker.querySelectorAll('[data-icon-value]');
        options.forEach((button) => button.addEventListener('click', () => {
            if (hidden) hidden.value = button.dataset.iconValue;
            options.forEach((candidate) => candidate.classList.toggle('selected', candidate === button));
        }));
    });

    document.querySelectorAll('[data-color-picker]').forEach((picker) => {
        const hidden = picker.querySelector('input[type="hidden"]');
        const options = picker.querySelectorAll('[data-color-value]');
        options.forEach((button) => button.addEventListener('click', () => {
            if (hidden) hidden.value = button.dataset.colorValue || '';
            options.forEach((candidate) => candidate.classList.toggle('selected', candidate === button));
        }));
    });

    const commandModal = document.querySelector('#command-modal');
    const commandInput = commandModal?.querySelector('[data-command-input]');
    const openCommand = () => {
        commandModal?.showModal();
        window.setTimeout(() => commandInput?.focus(), 40);
    };
    document.querySelector('[data-command-open]')?.addEventListener('click', openCommand);
    commandModal?.addEventListener('click', (event) => {
        if (event.target === commandModal) commandModal.close();
    });
    document.addEventListener('keydown', (event) => {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            commandModal?.open ? commandModal.close() : openCommand();
        }
        if (event.key === 'Escape') commandModal?.close();
    });
    const commandDefault = commandModal?.querySelector('[data-command-default]');
    const commandResults = commandModal?.querySelector('[data-command-results]');
    let commandTimer = 0;
    let commandRequest = null;
    const renderCommandMessage = (message) => {
        if (!commandResults) return;
        commandResults.replaceChildren(createElement('div', 'command-message', message));
        commandResults.hidden = false;
    };
    const renderCommandResults = (items) => {
        if (!commandResults) return;
        commandResults.replaceChildren();
        if (items.length === 0) {
            renderCommandMessage(body.dataset.searchEmpty);
            return;
        }
        commandResults.append(createElement('small', '', body.dataset.searchResultsLabel || 'Infrastructure'));
        const icons = { location: 'house', room: 'server-cog', rack: 'server', panel: 'panels-top-left', port: 'circle-dot', cable: 'cable' };
        items.forEach((item, index) => {
            const link = createElement('a');
            link.href = item.href;
            if (index === 0) link.dataset.firstSearchResult = 'true';
            const icon = createElement('i');
            icon.dataset.lucide = icons[item.type] || 'search';
            const copy = createElement('span');
            copy.append(createElement('strong', '', `${item.code} · ${item.name}`), createElement('em', '', item.context));
            link.append(icon, copy, createElement('kbd', '', index === 0 ? '↵' : ''));
            commandResults.append(link);
        });
        commandResults.hidden = false;
        refreshIcons();
    };
    commandInput?.addEventListener('input', () => {
        window.clearTimeout(commandTimer);
        commandRequest?.abort();
        const query = commandInput.value.trim();
        if (query.length < 2) {
            commandDefault.hidden = false;
            commandResults.hidden = true;
            commandResults.replaceChildren();
            return;
        }
        commandDefault.hidden = true;
        renderCommandMessage(body.dataset.searching);
        commandTimer = window.setTimeout(async () => {
            commandRequest = new AbortController();
            try {
                const response = await fetch(`/api/v1/search?q=${encodeURIComponent(query)}`, { headers: { Accept: 'application/json' }, signal: commandRequest.signal });
                const payload = await response.json();
                if (!response.ok) throw new Error(payload.error || body.dataset.toastError);
                renderCommandResults(payload.data || []);
            } catch (error) {
                if (error.name !== 'AbortError') renderCommandMessage(error.message || body.dataset.toastError);
            }
        }, 180);
    });
    commandInput?.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') return;
        const firstResult = commandResults?.querySelector('[data-first-search-result]') || commandDefault?.querySelector('a:not([hidden])');
        if (firstResult) {
            event.preventDefault();
            window.location.href = firstResult.href;
        }
    });

    document.querySelectorAll('[data-coming-soon]').forEach((item) => item.addEventListener('click', (event) => {
        event.preventDefault();
        showToast('This module is ready for the next implementation stage.');
    }));

    document.querySelector('[data-location-form]')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        const submitButton = form.querySelector('button[type="submit"]');
        const errorElement = form.querySelector('[data-form-error]');
        submitButton.disabled = true;
        errorElement.hidden = true;
        try {
            const response = await fetch('/api/v1/locations', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify(Object.fromEntries(new FormData(form).entries())),
            });
            const payload = await response.json();
            if (!response.ok) throw new Error(payload.error || body.dataset.toastError);
            form.reset();
            locationModal?.close();
            showToast(body.dataset.toastCreated);
            if (window.location.pathname === '/locations') window.setTimeout(() => window.location.reload(), 550);
        } catch (error) {
            errorElement.textContent = error.message || body.dataset.toastError;
            errorElement.hidden = false;
        } finally {
            submitButton.disabled = false;
        }
    });

    const bindModal = (modal, openSelector, closeSelector, beforeOpen) => {
        document.querySelectorAll(openSelector).forEach((button) => button.addEventListener('click', () => {
            if (!modal) return;
            if (!modal.open) modal.showModal();
            try {
                beforeOpen?.(button);
            } catch (error) {
                console.error('Modal initialization failed', error);
                showToast(body.dataset.toastError, 'error');
            }
        }));
        document.querySelectorAll(closeSelector).forEach((button) => button.addEventListener('click', () => modal?.close()));
        modal?.addEventListener('click', (event) => {
            if (event.target === modal) modal.close();
        });
    };

    const deleteModal = document.querySelector('#delete-confirm-modal');
    const deleteReasonMessages = readJson(document.querySelector('#delete-reason-messages')) || {};
    const deleteName = deleteModal?.querySelector('[data-delete-name]');
    const deleteError = deleteModal?.querySelector('[data-delete-error]');
    const deleteConfirm = deleteModal?.querySelector('[data-delete-confirm]');
    let pendingDeleteButton = null;
    const openDeleteConfirm = (url, name, redirect) => {
        pendingDeleteButton = { dataset: { deleteUrl: url, deleteName: name, deleteRedirect: redirect } };
        if (deleteName) deleteName.textContent = name || '—';
        if (deleteError) {
            deleteError.textContent = '';
            deleteError.hidden = true;
        }
        if (deleteConfirm) deleteConfirm.disabled = false;
        if (deleteModal && !deleteModal.open) deleteModal.showModal();
    };
    bindModal(deleteModal, '[data-delete-open]', '[data-delete-close]', (button) => {
        openDeleteConfirm(button.dataset.deleteUrl, button.dataset.deleteName, button.dataset.deleteRedirect);
    });
    deleteConfirm?.addEventListener('click', async () => {
        const endpoint = pendingDeleteButton?.dataset.deleteUrl;
        if (!endpoint) return;
        deleteConfirm.disabled = true;
        if (deleteError) deleteError.hidden = true;
        try {
            const response = await fetch(endpoint, {
                method: 'DELETE',
                headers: { Accept: 'application/json', 'X-CSRF-Token': csrfToken },
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) {
                throw new Error(deleteReasonMessages[payload.reason] || payload.error || body.dataset.toastError);
            }
            const redirect = pendingDeleteButton?.dataset.deleteRedirect;
            deleteModal?.close();
            showToast(body.dataset.toastDeleted || body.dataset.toastSaved);
            window.setTimeout(() => {
                if (redirect) {
                    window.location.assign(redirect);
                } else {
                    window.location.reload();
                }
            }, 500);
        } catch (error) {
            if (deleteError) {
                deleteError.textContent = error.message || body.dataset.toastError;
                deleteError.hidden = false;
            }
            deleteConfirm.disabled = false;
        }
    });

    const submitEntityForm = async (form, endpoint, modal) => {
        const submitButton = form.querySelector('button[type="submit"]');
        const errorElement = form.querySelector('[data-form-error]');
        submitButton.disabled = true;
        errorElement.hidden = true;
        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify(Object.fromEntries(new FormData(form).entries())),
            });
            const payload = await response.json();
            if (!response.ok) throw new Error(payload.error || body.dataset.toastError);
            modal?.close();
            showToast(body.dataset.toastSaved || body.dataset.toastCreated);
            window.setTimeout(() => window.location.reload(), 500);
        } catch (error) {
            errorElement.textContent = error.message || body.dataset.toastError;
            errorElement.hidden = false;
        } finally {
            submitButton.disabled = false;
        }
    };

    const profileModal = document.querySelector('#profile-modal');
    bindModal(profileModal, '[data-profile-modal-open]', '[data-profile-modal-close]');
    document.querySelector('[data-profile-form]')?.addEventListener('submit', (event) => {
        event.preventDefault();
        submitEntityForm(event.currentTarget, '/api/v1/account/profile', profileModal);
    });

    const passwordModal = document.querySelector('#password-modal');
    bindModal(passwordModal, '[data-password-modal-open]', '[data-password-modal-close]');
    document.querySelector('[data-password-form]')?.addEventListener('submit', (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        submitEntityForm(form, '/api/v1/account/password', passwordModal);
    });

    const userModal = document.querySelector('#user-modal');
    bindModal(userModal, '[data-user-modal-open]', '[data-user-modal-close]');
    document.querySelector('[data-user-form]')?.addEventListener('submit', (event) => {
        event.preventDefault();
        submitEntityForm(event.currentTarget, '/api/v1/users', userModal);
    });

    const roomModal = document.querySelector('#room-modal');
    bindModal(roomModal, '[data-room-modal-open]', '[data-room-modal-close]');
    document.querySelector('[data-room-form]')?.addEventListener('submit', (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        const locationId = form.querySelector('[data-room-location]')?.value;
        submitEntityForm(form, `/api/v1/locations/${locationId}/server-rooms`, roomModal);
    });

    const rackModal = document.querySelector('#rack-modal');
    bindModal(rackModal, '[data-rack-modal-open]', '[data-rack-modal-close]', (button) => {
        const form = rackModal?.querySelector('[data-rack-form]');
        const roomId = form?.querySelector('[data-rack-room-id]');
        const roomName = form?.querySelector('[data-rack-room-name]');
        if (roomId) roomId.value = button.dataset.roomId || '';
        if (roomName) roomName.textContent = button.dataset.roomName || '';
    });
    document.querySelector('[data-rack-form]')?.addEventListener('submit', (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        const roomId = form.querySelector('[data-rack-room-id]')?.value;
        submitEntityForm(form, `/api/v1/server-rooms/${roomId}/racks`, rackModal);
    });

    const upsModal = document.querySelector('#ups-modal');
    bindModal(upsModal, '[data-ups-modal-open]', '[data-ups-modal-close]', (button) => {
        const form = upsModal?.querySelector('[data-ups-form]');
        if (!form) return;
        form.reset();
        form.elements.server_room_id.value = button.dataset.roomId || '';
        upsModal.querySelector('[data-ups-room-name]').textContent = button.dataset.roomName || '';
    });
    document.querySelector('[data-ups-form]')?.addEventListener('submit', (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        submitEntityForm(form, `/api/v1/server-rooms/${form.elements.server_room_id.value}/ups-devices`, upsModal);
    });

    const upsEditModal = document.querySelector('#ups-edit-modal');
    bindModal(upsEditModal, '[data-ups-edit-open]', '[data-ups-edit-close]', (button) => {
        const form = upsEditModal?.querySelector('[data-ups-edit-form]');
        if (!form) return;
        form.elements.ups_device_id.value = button.dataset.upsId || '';
        form.elements.name.value = button.dataset.upsName || '';
        form.elements.manufacturer.value = button.dataset.upsManufacturer || '';
        form.elements.model.value = button.dataset.upsModel || '';
        form.elements.serial_number.value = button.dataset.upsSerialNumber || '';
        form.elements.rated_power_va.value = button.dataset.upsRatedPowerVa || '';
        form.elements.rated_power_w.value = button.dataset.upsRatedPowerW || '';
        form.elements.ip_address.value = button.dataset.upsIpAddress || '';
        form.elements.management_url.value = button.dataset.upsManagementUrl || '';
        form.elements.battery_replaced_at.value = button.dataset.upsBatteryReplacedAt || '';
        form.elements.battery_replacement_interval_months.value = button.dataset.upsBatteryInterval || '36';
        form.elements.battery_count.value = button.dataset.upsBatteryCount || '';
        form.elements.battery_type.value = button.dataset.upsBatteryType || '';
        form.elements.operational_status.value = button.dataset.upsStatus || 'ACTIVE';
        form.elements.notes.value = button.dataset.upsNotes || '';
        upsEditModal.querySelector('[data-ups-edit-context]').textContent = button.dataset.upsCode || '';
    });
    document.querySelector('[data-ups-edit-form]')?.addEventListener('submit', (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        submitEntityForm(form, `/api/v1/ups-devices/${form.elements.ups_device_id.value}`, upsEditModal);
    });

    const activeDeviceEditModal = document.querySelector('#active-device-edit-modal');
    bindModal(activeDeviceEditModal, '[data-active-device-edit-open]', '[data-active-device-edit-close]', (button) => {
        const form = activeDeviceEditModal?.querySelector('[data-active-device-edit-form]');
        if (!form) return;
        form.elements.active_device_id.value = button.dataset.deviceId || '';
        form.elements.name.value = button.dataset.deviceName || '';
        form.elements.device_type.value = button.dataset.deviceType || 'SWITCH';
        form.elements.vendor.value = button.dataset.deviceVendor || '';
        form.elements.model.value = button.dataset.deviceModel || '';
        form.elements.management_address.value = button.dataset.deviceManagementAddress || '';
        form.elements.notes.value = button.dataset.deviceNotes || '';
        activeDeviceEditModal.querySelector('[data-active-device-edit-context]').textContent = button.dataset.deviceCode || '';
    });
    document.querySelector('[data-active-device-edit-form]')?.addEventListener('submit', (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        submitEntityForm(form, `/api/v1/active-devices/${form.elements.active_device_id.value}`, activeDeviceEditModal);
    });

    document.querySelectorAll('[data-device-interface-disconnect]').forEach((button) => button.addEventListener('click', async () => {
        if (!window.confirm(body.dataset.confirmDisconnectInterface)) return;
        button.disabled = true;
        try {
            const response = await fetch(`/api/v1/active-device-interfaces/${button.dataset.interfaceId}/connection`, {
                method: 'DELETE',
                headers: { Accept: 'application/json', 'X-CSRF-Token': csrfToken },
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(payload.error || body.dataset.toastError);
            showToast(body.dataset.toastSaved);
            window.setTimeout(() => window.location.reload(), 500);
        } catch (error) {
            showToast(error.message || body.dataset.toastError, 'error');
            button.disabled = false;
        }
    }));

    const rackItemModal = document.querySelector('#rack-item-modal');
    bindModal(rackItemModal, '[data-rack-item-modal-open]', '[data-rack-item-modal-close]');
    document.querySelector('[data-rack-item-form]')?.addEventListener('submit', (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        submitEntityForm(form, `/api/v1/racks/${form.dataset.rackId}/rack-items`, rackItemModal);
    });

    const rackItemEditModal = document.querySelector('#rack-item-edit-modal');
    const populateRackItemEditForm = (item) => {
        const form = rackItemEditModal?.querySelector('[data-rack-item-edit-form]');
        if (!form) return;
        form.elements.item_id.value = item.id ?? '';
        form.elements.name.value = item.name ?? '';
        form.elements.kind.value = item.kind || 'OTHER';
        form.elements.rack_unit_start.value = item.start ?? '';
        form.elements.rack_unit_height.value = item.height ?? '1';
        form.elements.notes.value = item.notes ?? '';
    };
    window.openRackItemEditModal = (item) => {
        if (!rackItemEditModal) return;
        populateRackItemEditForm(item);
        if (!rackItemEditModal.open) rackItemEditModal.showModal();
    };
    bindModal(rackItemEditModal, '[data-rack-item-edit-open]', '[data-rack-item-edit-close]', (button) => {
        populateRackItemEditForm({
            id: button.dataset.itemId,
            name: button.dataset.itemName,
            kind: button.dataset.itemKind,
            start: button.dataset.itemStart,
            height: button.dataset.itemHeight,
            notes: button.dataset.itemNotes,
        });
    });
    rackItemEditModal?.querySelector('[data-rack-item-edit-delete]')?.addEventListener('click', () => {
        const form = rackItemEditModal.querySelector('[data-rack-item-edit-form]');
        const itemId = form?.elements.item_id.value;
        if (!itemId) return;
        const itemName = form.elements.name.value;
        rackItemEditModal.close();
        openDeleteConfirm(`/api/v1/rack-items/${itemId}`, itemName);
    });
    document.querySelector('[data-rack-item-edit-form]')?.addEventListener('submit', (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        submitEntityForm(form, `/api/v1/rack-items/${form.elements.item_id.value}`, rackItemEditModal);
    });

    const cableModal = document.querySelector('#cable-modal');
    bindModal(cableModal, '[data-cable-modal-open]', '[data-cable-modal-close]');
    document.querySelector('[data-cable-form]')?.addEventListener('submit', (event) => {
        event.preventDefault();
        submitEntityForm(event.currentTarget, '/api/v1/cables', cableModal);
    });

    const locationEditModal = document.querySelector('#location-edit-modal');
    bindModal(locationEditModal, '[data-location-edit-open]', '[data-location-edit-close]');
    document.querySelector('[data-location-edit-form]')?.addEventListener('submit', (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        submitEntityForm(form, `/api/v1/locations/${form.dataset.locationId}`, locationEditModal);
    });

    const roomEditModal = document.querySelector('#room-edit-modal');
    bindModal(roomEditModal, '[data-room-edit-open]', '[data-room-edit-close]', (button) => {
        const form = roomEditModal?.querySelector('[data-room-edit-form]');
        if (!form) return;
        form.elements.room_id.value = button.dataset.roomId || '';
        form.elements.location_id.value = button.dataset.locationId || '';
        form.elements.name.value = button.dataset.roomName || '';
        form.elements.floor.value = button.dataset.roomFloor || '';
        roomEditModal.querySelector('[data-room-edit-context]').textContent = button.dataset.roomName || '';
    });
    document.querySelector('[data-room-edit-form]')?.addEventListener('submit', (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        submitEntityForm(form, `/api/v1/server-rooms/${form.elements.room_id.value}`, roomEditModal);
    });

    const rackEditModal = document.querySelector('#rack-edit-modal');
    bindModal(rackEditModal, '[data-rack-edit-open]', '[data-rack-edit-close]');
    document.querySelector('[data-rack-edit-form]')?.addEventListener('submit', (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        submitEntityForm(form, `/api/v1/racks/${form.dataset.rackId}`, rackEditModal);
    });

    const panelEditModal = document.querySelector('#panel-edit-modal');
    bindModal(panelEditModal, '[data-panel-edit-open]', '[data-panel-edit-close]');
    document.querySelector('[data-panel-edit-form]')?.addEventListener('submit', (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        submitEntityForm(form, `/api/v1/patch-panels/${form.dataset.panelId}`, panelEditModal);
    });

    const cableEditModal = document.querySelector('#cable-edit-modal');
    bindModal(cableEditModal, '[data-cable-edit-open]', '[data-cable-edit-close]', (button) => {
        const form = cableEditModal?.querySelector('[data-cable-edit-form]');
        if (!form) return;
        form.elements.cable_id.value = button.dataset.cableId || '';
        form.elements.code.value = button.dataset.code || '';
        form.elements.name.value = button.dataset.name || '';
        form.elements.medium.value = button.dataset.medium || 'SM';
        form.elements.fiber_count.value = button.dataset.fiberCount || '';
        form.elements.source_endpoint.value = button.dataset.sourceEndpoint || '';
        form.elements.destination_endpoint.value = button.dataset.destinationEndpoint || '';
        form.elements.length_m.value = button.dataset.lengthM || '0';
        form.elements.operational_status.value = button.dataset.operationalStatus || 'PLANNED';
        cableEditModal.querySelector('[data-cable-edit-context]').textContent = button.dataset.code || '';
    });
    document.querySelector('[data-cable-edit-form]')?.addEventListener('submit', (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        submitEntityForm(form, `/api/v1/cables/${form.elements.cable_id.value}`, cableEditModal);
    });

    const panelModal = document.querySelector('#panel-modal');
    bindModal(panelModal, '[data-panel-modal-open]', '[data-panel-modal-close]', () => {
        const form = panelModal?.querySelector('[data-panel-form]');
        const rack = readJson(document.querySelector('.rack-data'));
        const height = Number(form?.elements.rack_unit_height?.value || 1);
        if (!form || !rack) return;
        const occupiedUnits = new Set();
        rack.devices.forEach((device) => {
            for (let unit = Number(device.start) - Number(device.height) + 1; unit <= Number(device.start); unit += 1) occupiedUnits.add(unit);
        });
        for (let start = Number(rack.total_units); start >= height; start -= 1) {
            let available = true;
            for (let unit = start - height + 1; unit <= start; unit += 1) available = available && !occupiedUnits.has(unit);
            if (available) {
                form.elements.rack_unit_start.value = String(start);
                break;
            }
        }
    });
    document.querySelector('[data-panel-form]')?.addEventListener('submit', (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        submitEntityForm(form, `/api/v1/racks/${form.dataset.rackId}/patch-panels`, panelModal);
    });

    const panelPortData = readJson(document.querySelector('.panel-data'));
    const selectPortForAction = (button) => {
        const requestedPortId = Number(button?.dataset.portId || 0);
        if (requestedPortId > 0) {
            const requestedPort = panelPortData?.port_items?.find((port) => Number(port.id) === requestedPortId);
            if (requestedPort) updatePortInspector(requestedPort);
        }
        return selectedPort;
    };

    const frontTargetRequests = new WeakMap();
    const loadFrontPortTargets = async (form) => {
        const portId = Number(form?.elements.port_id?.value || 0);
        const targetSelect = form?.querySelector('[data-front-target-select]');
        const targetSearch = form?.querySelector('[data-front-target-search]');
        const status = form?.querySelector('[data-front-target-status]');
        if (!portId || !targetSelect || !status) return;
        frontTargetRequests.get(form)?.abort();
        const controller = new AbortController();
        frontTargetRequests.set(form, controller);
        targetSelect.disabled = true;
        status.textContent = body.dataset.searching;
        try {
            const params = new URLSearchParams({ q: targetSearch?.value.trim() || '' });
            const response = await fetch(`/api/v1/patch-panel-ports/${portId}/front-targets?${params}`, { headers: { Accept: 'application/json' }, signal: controller.signal });
            const payload = await response.json();
            if (!response.ok) throw new Error(payload.error || body.dataset.toastError);
            targetSelect.replaceChildren();
            const groups = new Map();
            (payload.data || []).forEach((target) => {
                const groupLabel = `${target.location} › ${target.room} › ${target.rack} › ${target.panel_code}`;
                if (!groups.has(groupLabel)) {
                    const group = document.createElement('optgroup');
                    group.label = groupLabel;
                    groups.set(groupLabel, group);
                    targetSelect.append(group);
                }
                const option = createElement('option', '', `Port ${String(target.port_number).padStart(2, '0')}${target.label ? ` · ${target.label}` : ''} · ${target.connector}`);
                option.value = String(target.id);
                groups.get(groupLabel).append(option);
            });
            targetSelect.disabled = false;
            targetSelect.value = form.dataset.frontTargetId || '';
            status.textContent = payload.data.length ? `${payload.data.length} ${status.dataset.countLabel}` : status.dataset.emptyLabel;
        } catch (error) {
            if (error.name !== 'AbortError') status.textContent = error.message || body.dataset.toastError;
        }
    };

    const syncFrontConnectionFields = (form) => {
        if (!form) return;
        const mode = form.elements.front_connection_mode?.value || 'NONE';
        const deviceFields = form.querySelector('[data-front-device-fields]');
        const portFields = form.querySelector('[data-front-port-fields]');
        const commonFields = form.querySelector('[data-front-common-fields]');
        const newDeviceFields = form.querySelector('[data-new-active-device-fields]');
        const deviceSelect = form.elements.active_device_id;
        const deviceMode = mode === 'DEVICE';
        const portMode = mode === 'PORT';
        const createsDevice = deviceMode && !deviceSelect?.value;
        if (deviceFields) deviceFields.hidden = !deviceMode;
        if (portFields) portFields.hidden = !portMode;
        if (commonFields) commonFields.hidden = !deviceMode && !portMode;
        if (newDeviceFields) newDeviceFields.hidden = !createsDevice;
        ['active_device_rack_id', 'active_device_type', 'active_device_vendor', 'active_device_name'].forEach((name) => {
            if (form.elements[name]) form.elements[name].required = createsDevice;
        });
        if (form.elements.active_interface_name) form.elements.active_interface_name.required = deviceMode;
        if (form.elements.active_interface_type) form.elements.active_interface_type.required = deviceMode;
        if (form.elements.front_destination_port_id) form.elements.front_destination_port_id.required = portMode;
        if (portMode) loadFrontPortTargets(form);
    };

    const setFormValue = (form, name, value) => {
        const field = form?.elements?.namedItem(name);
        if (field && 'value' in field) field.value = value ?? '';
    };

    const syncColorPicker = (form, value) => {
        const picker = form?.querySelector('[data-color-picker]');
        if (!picker) return;
        const hidden = picker.querySelector('input[type="hidden"]');
        if (hidden) hidden.value = value || '';
        picker.querySelectorAll('[data-color-value]').forEach((button) => {
            button.classList.toggle('selected', (button.dataset.colorValue || '') === (value || ''));
        });
    };

    const populatePortEditForm = (form, port) => {
        if (!form || !port) return;
        setFormValue(form, 'port_id', String(port.id));
        setFormValue(form, 'connector_type_id', String(port.connector_type_id || panelPortData?.connector_type_id || ''));
        setFormValue(form, 'label', port.label || '');
        setFormValue(form, 'notes', port.notes || '');
        syncColorPicker(form, port.highlight_color || '');
        form.querySelectorAll('[data-port-form-rear-destination]').forEach((element) => {
            element.textContent = port.rear_destination || '—';
        });
        setFormValue(form, 'rear_connection_mode', 'UNCHANGED');
        const rearDisconnect = form.querySelector('[data-rear-disconnect]');
        if (rearDisconnect) rearDisconnect.hidden = !port.rear_destination;
        setFormValue(form, 'administrative_status', port.administrative_status || (['reserved', 'blocked', 'damaged'].includes(port.status) ? port.status.toUpperCase() : 'AVAILABLE'));
        const front = port.front_connection || null;
        const frontMode = front?.type === 'PORT' ? 'PORT' : (front ? 'DEVICE' : 'NONE');
        setFormValue(form, 'front_connection_mode', frontMode);
        form.dataset.frontTargetId = front?.type === 'PORT' ? String(front.destination_port_id || '') : '';
        setFormValue(form, 'front_destination_port_id', form.dataset.frontTargetId);
        setFormValue(form, 'active_device_id', front?.type === 'DEVICE' ? String(front.device_id || '') : '');
        setFormValue(form, 'active_device_rack_id', front?.type === 'DEVICE' ? String(front.device_rack_id || panelPortData?.rack_id || '') : String(panelPortData?.rack_id || ''));
        setFormValue(form, 'active_device_type', front?.device_type || 'SWITCH');
        setFormValue(form, 'active_device_vendor', front?.device_vendor || '');
        setFormValue(form, 'active_device_name', front?.device_name || '');
        setFormValue(form, 'active_device_model', front?.device_model || '');
        setFormValue(form, 'active_interface_name', front?.interface_name || '');
        setFormValue(form, 'active_interface_type', front?.interface_type || 'SFP_PLUS');
        setFormValue(form, 'active_interface_speed', front?.interface_speed || '');
        setFormValue(form, 'front_patch_cord_label', front?.patch_cord_label || '');
        setFormValue(form, 'front_connection_notes', front?.notes || '');
        syncFrontConnectionFields(form);
    };

    document.querySelectorAll('[data-port-edit-form], [data-port-inline-edit-form]').forEach((form) => {
        form.querySelector('[data-front-connection-mode]')?.addEventListener('change', () => syncFrontConnectionFields(form));
        form.querySelector('[data-active-device-select]')?.addEventListener('change', () => syncFrontConnectionFields(form));
        let frontSearchTimer = 0;
        form.querySelector('[data-front-target-search]')?.addEventListener('input', () => {
            window.clearTimeout(frontSearchTimer);
            frontSearchTimer = window.setTimeout(() => loadFrontPortTargets(form), 180);
        });
        form.querySelector('[data-rear-disconnect]')?.addEventListener('click', () => {
            if (!window.confirm(body.dataset.confirmDisconnectRear)) return;
            setFormValue(form, 'rear_connection_mode', 'NONE');
            form.requestSubmit();
        });
        syncFrontConnectionFields(form);
    });

    const portEditModal = document.querySelector('#port-edit-modal');
    bindModal(portEditModal, '[data-port-edit-open]', '[data-port-edit-close]', (button) => {
        selectPortForAction(button);
        if (!selectedPort) return;
        const form = portEditModal.querySelector('[data-port-edit-form]');
        populatePortEditForm(form, selectedPort);
        portEditModal.querySelector('[data-edit-port-context]').textContent = `Port ${String(selectedPort.number).padStart(2, '0')} · ${selectedPort.connector}`;
    });
    document.querySelector('[data-port-edit-form]')?.addEventListener('submit', (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        submitEntityForm(form, `/api/v1/patch-panel-ports/${form.elements.port_id.value}`, portEditModal);
    });

    const inlinePortEditor = document.querySelector('[data-port-inline-edit-form]');
    document.querySelector('[data-port-inline-edit-open]')?.addEventListener('click', () => {
        if (!selectedPort || !inlinePortEditor) return;
        populatePortEditForm(inlinePortEditor, selectedPort);
        inlinePortEditor.hidden = false;
        inlinePortEditor.querySelector('input[name="label"]')?.focus();
    });
    document.querySelectorAll('[data-port-inline-edit-close]').forEach((button) => button.addEventListener('click', () => {
        if (inlinePortEditor) inlinePortEditor.hidden = true;
    }));
    inlinePortEditor?.addEventListener('submit', (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        submitEntityForm(form, `/api/v1/patch-panel-ports/${form.elements.port_id.value}`, null);
    });
    document.querySelectorAll('[data-port-route-picker]').forEach((button) => button.addEventListener('click', () => {
        portEditModal?.close();
        if (inlinePortEditor) inlinePortEditor.hidden = true;
    }));

    const portConnectModal = document.querySelector('#port-connect-modal');
    const targetSearch = portConnectModal?.querySelector('[data-target-search]');
    const targetSelect = portConnectModal?.querySelector('[data-target-select]');
    const rearRouteSelect = portConnectModal?.querySelector('[data-rear-route-select]');
    const rearRouteStatus = portConnectModal?.querySelector('[data-rear-route-status]');
    const rearRouteSummary = portConnectModal?.querySelector('[data-rear-route-summary]');
    const portConnectSubmit = portConnectModal?.querySelector('[data-port-connect-submit]');
    let targetTimer = 0;
    let targetRequest = null;
    let routeRequest = null;
    let rearRoutes = [];
    const setTargetState = (message, enabled = false) => {
        if (targetSelect) {
            targetSelect.replaceChildren();
            targetSelect.disabled = !enabled;
        }
        if (targetSearch) targetSearch.disabled = !enabled;
        if (portConnectSubmit) portConnectSubmit.disabled = true;
        const status = portConnectModal?.querySelector('[data-target-status]');
        if (status) status.textContent = message;
    };
    const routeAvailabilityLabel = (route) => ({
        full: body.dataset.routeFullLabel,
        planned: body.dataset.routePlannedLabel,
        maintenance: body.dataset.routeMaintenanceLabel,
        damaged: body.dataset.routeDamagedLabel,
        mixed_medium: body.dataset.routeMixedLabel,
    }[route.availability] || route.availability);
    const renderRouteSummary = (route) => {
        if (!rearRouteSummary) return;
        rearRouteSummary.hidden = !route;
        if (!route) return;
        rearRouteSummary.querySelector('[data-rear-route-name]').textContent = `${route.cable_path} → ${route.destination_label || route.destination_location_name}`;
        rearRouteSummary.querySelector('[data-rear-route-detail]').textContent = `${route.medium} · ${(Number(route.length_m) / 1000).toFixed(2)} km · ${route.segment_count}×`;
        rearRouteSummary.querySelector('[data-rear-route-capacity]').textContent = `${route.free_fibers}/${route.fiber_capacity}J ${body.dataset.routeFreeLabel}`;
    };
    const loadConnectionTargets = async () => {
        const routeKey = rearRouteSelect?.value || '';
        if (!selectedPort || !targetSelect || !routeKey) {
            setTargetState(body.dataset.routeSelectLabel, false);
            return;
        }
        targetRequest?.abort();
        targetRequest = new AbortController();
        const status = portConnectModal.querySelector('[data-target-status]');
        status.textContent = body.dataset.searching;
        try {
            const params = new URLSearchParams({ q: targetSearch?.value.trim() || '', route: routeKey });
            const response = await fetch(`/api/v1/patch-panel-ports/${selectedPort.id}/targets?${params}`, { headers: { Accept: 'application/json' }, signal: targetRequest.signal });
            const payload = await response.json();
            if (!response.ok) throw new Error(payload.error || body.dataset.toastError);
            targetSelect.replaceChildren();
            const groups = new Map();
            (payload.data || []).forEach((target) => {
                const groupLabel = `${target.location} › ${target.room} › ${target.rack} › ${target.panel_code}`;
                if (!groups.has(groupLabel)) {
                    const group = document.createElement('optgroup');
                    group.label = groupLabel;
                    groups.set(groupLabel, group);
                    targetSelect.append(group);
                }
                const option = createElement('option', '', `Port ${String(target.port_number).padStart(2, '0')}${target.label ? ` · ${target.label}` : ''} · ${target.connector}`);
                option.value = String(target.id);
                groups.get(groupLabel).append(option);
            });
            status.textContent = payload.data.length ? `${payload.data.length} ${status.dataset.countLabel}` : body.dataset.targetEmpty;
            targetSelect.disabled = false;
            if (portConnectSubmit) portConnectSubmit.disabled = true;
        } catch (error) {
            if (error.name !== 'AbortError') status.textContent = error.message || body.dataset.toastError;
        }
    };
    const loadRearFiberRoutes = async () => {
        if (!selectedPort || !rearRouteSelect) return;
        routeRequest?.abort();
        routeRequest = new AbortController();
        rearRoutes = [];
        rearRouteSelect.disabled = true;
        rearRouteSelect.replaceChildren(createElement('option', '', body.dataset.searching));
        if (rearRouteStatus) rearRouteStatus.textContent = body.dataset.searching;
        renderRouteSummary(null);
        setTargetState(body.dataset.routeSelectLabel, false);
        try {
            const response = await fetch(`/api/v1/patch-panel-ports/${selectedPort.id}/rear-routes`, { headers: { Accept: 'application/json' }, signal: routeRequest.signal });
            const payload = await response.json();
            if (!response.ok) throw new Error(payload.error || body.dataset.toastError);
            rearRoutes = payload.data || [];
            rearRouteSelect.replaceChildren();
            const placeholder = createElement('option', '', body.dataset.routeSelectLabel);
            placeholder.value = '';
            rearRouteSelect.append(placeholder);
            rearRoutes.forEach((route) => {
                const availability = route.selectable
                    ? `${route.free_fibers}/${route.fiber_capacity}J ${body.dataset.routeFreeLabel}`
                    : routeAvailabilityLabel(route);
                const option = createElement('option', '', `${route.cable_path} → ${route.destination_label || route.destination_location_name} · ${availability} · ${route.medium}`);
                option.value = route.key;
                option.disabled = !route.selectable;
                rearRouteSelect.append(option);
            });
            rearRouteSelect.disabled = false;
            const selectableCount = rearRoutes.filter((route) => route.selectable).length;
            if (rearRouteStatus) {
                rearRouteStatus.textContent = rearRoutes.length === 0
                    ? body.dataset.routeEmpty
                    : (selectableCount === 0 ? body.dataset.routeUnavailable : `${selectableCount} · ${body.dataset.routeSelectLabel}`);
            }
        } catch (error) {
            if (error.name !== 'AbortError') {
                rearRouteSelect.replaceChildren(createElement('option', '', body.dataset.routeEmpty));
                if (rearRouteStatus) rearRouteStatus.textContent = error.message || body.dataset.toastError;
            }
        }
    };
    bindModal(portConnectModal, '[data-port-connect-open]', '[data-port-connect-close]', (button) => {
        selectPortForAction(button);
        if (!selectedPort) return;
        const form = portConnectModal.querySelector('[data-port-connect-form]');
        form.reset();
        form.elements.source_port_id.value = String(selectedPort.id);
        portConnectModal.querySelector('[data-connect-source]').textContent = `Port ${String(selectedPort.number).padStart(2, '0')} · ${selectedPort.connector}${selectedPort.label ? ` · ${selectedPort.label}` : ''}`;
        targetSearch.value = '';
        loadRearFiberRoutes();
    });
    rearRouteSelect?.addEventListener('change', () => {
        const route = rearRoutes.find((candidate) => candidate.key === rearRouteSelect.value && candidate.selectable) || null;
        renderRouteSummary(route);
        targetSearch.value = '';
        if (!route) {
            setTargetState(body.dataset.routeSelectLabel, false);
            return;
        }
        setTargetState(body.dataset.searching, true);
        loadConnectionTargets();
    });
    targetSearch?.addEventListener('input', () => {
        window.clearTimeout(targetTimer);
        targetTimer = window.setTimeout(loadConnectionTargets, 180);
    });
    targetSelect?.addEventListener('change', () => {
        if (portConnectSubmit) portConnectSubmit.disabled = !targetSelect.value || !rearRouteSelect?.value;
    });
    document.querySelector('[data-port-connect-form]')?.addEventListener('submit', (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        submitEntityForm(form, `/api/v1/patch-panel-ports/${form.elements.source_port_id.value}/connections`, portConnectModal);
    });

    document.querySelectorAll('[data-port-inspect]').forEach((button) => button.addEventListener('click', () => {
        if (!selectPortForAction(button)) return;
        document.querySelector('[data-port-inspector]')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }));

    const panelPortSearch = document.querySelector('[data-panel-port-search]');
    panelPortSearch?.addEventListener('input', () => {
        const query = panelPortSearch.value.trim().toLowerCase();
        let visibleRows = 0;
        document.querySelectorAll('[data-panel-port-row]').forEach((row) => {
            const visible = !query || row.dataset.searchValue.includes(query);
            row.hidden = !visible;
            if (visible) visibleRows += 1;
        });
        const emptyState = document.querySelector('[data-panel-port-empty]');
        if (emptyState) emptyState.hidden = visibleRows > 0;
    });

    document.querySelectorAll('[data-list-search]').forEach((input) => input.addEventListener('input', () => {
        const query = input.value.trim().toLowerCase();
        const list = input.closest('.page-content')?.querySelector('[data-search-list]');
        list?.querySelectorAll('[data-search-item]').forEach((item) => {
            item.hidden = !item.dataset.searchItem.includes(query);
        });
    }));

    const edgeColor = (tone, palette) => ({ violet: palette.indigo, cyan: palette.cyan, amber: palette.amber, blue: palette.blue }[tone] || palette.blue);

    const locationIconLucideNames = {
        'loc-office': 'building-2', 'loc-datacenter': 'server-cog', 'loc-server-room': 'server',
        'loc-tower': 'radio-tower', 'loc-warehouse': 'warehouse', 'loc-campus': 'landmark',
        'loc-cloud': 'cloud', 'loc-satellite': 'satellite-dish', 'loc-factory': 'factory',
        'loc-globe': 'globe',
    };

    const graphIconName = (type) => ({
        location: 'House',
        splice: 'Waypoints',
        room: 'ServerCog',
        rack: 'Server',
        panel: 'PanelTop',
        panel_group: 'PanelsTopLeft',
    }[type] || 'Network');

    const graphIconCache = new Map();

    const drawPolygon = (context, points, fill, stroke) => {
        context.beginPath();
        context.moveTo(points[0][0], points[0][1]);
        points.slice(1).forEach(([x, y]) => context.lineTo(x, y));
        context.closePath();
        context.fillStyle = fill;
        context.fill();
        if (stroke) {
            context.strokeStyle = stroke;
            context.stroke();
        }
    };

    const drawIsoBox = (context, x, y, width, height, depth, colors) => {
        drawPolygon(context, [[x + depth, y], [x + width + depth, y], [x + width, y + depth], [x, y + depth]], colors.top, colors.stroke);
        drawPolygon(context, [[x, y + depth], [x + width, y + depth], [x + width, y + depth + height], [x, y + depth + height]], colors.front, colors.stroke);
        drawPolygon(context, [[x + width, y + depth], [x + width + depth, y], [x + width + depth, y + height], [x + width, y + depth + height]], colors.side, colors.stroke);
    };

    const locationIconGlyphs = {
        'loc-office': [
            ['path', { d: 'M10 12h4' }],
            ['path', { d: 'M10 8h4' }],
            ['path', { d: 'M14 21v-3a2 2 0 0 0-4 0v3' }],
            ['path', { d: 'M6 10H4a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-2' }],
            ['path', { d: 'M6 21V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16' }],
        ],
        'loc-datacenter': [
            ['path', { d: 'm10.852 14.772-.383.923' }],
            ['path', { d: 'M13.148 14.772a3 3 0 1 0-2.296-5.544l-.383-.923' }],
            ['path', { d: 'm13.148 9.228.383-.923' }],
            ['path', { d: 'm13.53 15.696-.382-.924a3 3 0 1 1-2.296-5.544' }],
            ['path', { d: 'm14.772 10.852.923-.383' }],
            ['path', { d: 'm14.772 13.148.923.383' }],
            ['path', { d: 'M4.5 10H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-.5' }],
            ['path', { d: 'M4.5 14H4a2 2 0 0 0-2 2v4a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-4a2 2 0 0 0-2-2h-.5' }],
            ['line', { x1: '6', y1: '18', x2: '6.01', y2: '18' }],
            ['line', { x1: '6', y1: '6', x2: '6.01', y2: '6' }],
            ['path', { d: 'm9.228 10.852-.923-.383' }],
            ['path', { d: 'm9.228 13.148-.923.383' }],
        ],
        'loc-server-room': [
            ['rect', { x: '2', y: '2', width: '20', height: '8', rx: '2' }],
            ['rect', { x: '2', y: '14', width: '20', height: '8', rx: '2' }],
            ['line', { x1: '6', y1: '6', x2: '6.01', y2: '6' }],
            ['line', { x1: '6', y1: '18', x2: '6.01', y2: '18' }],
        ],
        'loc-tower': [
            ['path', { d: 'M4.9 16.1C1 12.2 1 5.8 4.9 1.9' }],
            ['path', { d: 'M7.8 4.7a6.14 6.14 0 0 0-.8 7.5' }],
            ['path', { d: 'M16.2 4.8c2 2 2.26 5.11.8 7.47' }],
            ['path', { d: 'M19.1 1.9a9.96 9.96 0 0 1 0 14.1' }],
            ['path', { d: 'M9.5 18h5' }],
            ['path', { d: 'm8 22 4-11 4 11' }],
        ],
        'loc-warehouse': [
            ['path', { d: 'M18 21V10a1 1 0 0 0-1-1H7a1 1 0 0 0-1 1v11' }],
            ['path', { d: 'M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V8a2 2 0 0 1 1.132-1.803l7.95-3.974a2 2 0 0 1 1.837 0l7.948 3.974A2 2 0 0 1 22 8z' }],
            ['path', { d: 'M6 13h12' }],
            ['path', { d: 'M6 17h12' }],
        ],
        'loc-campus': [
            ['path', { d: 'M10 18v-7' }],
            ['path', { d: 'M11.119 2.205a2 2 0 0 1 1.762 0l7.84 3.846A.5.5 0 0 1 20.5 7h-17a.5.5 0 0 1-.22-.949z' }],
            ['path', { d: 'M14 18v-7' }],
            ['path', { d: 'M18 18v-7' }],
            ['path', { d: 'M3 22h18' }],
            ['path', { d: 'M6 18v-7' }],
        ],
        'loc-cloud': [
            ['path', { d: 'M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z' }],
        ],
        'loc-satellite': [
            ['path', { d: 'M4 10a7.31 7.31 0 0 0 10 10Z' }],
            ['path', { d: 'm9 15 3-3' }],
            ['path', { d: 'M17 13a6 6 0 0 0-6-6' }],
            ['path', { d: 'M21 13A10 10 0 0 0 11 3' }],
        ],
        'loc-factory': [
            ['path', { d: 'M12 16h.01' }],
            ['path', { d: 'M16 16h.01' }],
            ['path', { d: 'M3 19a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V8.5a.5.5 0 0 0-.769-.422l-4.462 2.844A.5.5 0 0 1 15 10.5v-2a.5.5 0 0 0-.769-.422L9.77 10.922A.5.5 0 0 1 9 10.5V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2z' }],
            ['path', { d: 'M8 16h.01' }],
        ],
        'loc-globe': [
            ['circle', { cx: '12', cy: '12', r: '10' }],
            ['path', { d: 'M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20' }],
            ['path', { d: 'M2 12h20' }],
        ],
    };

    const drawLucideGlyph = (context, elements, cx, cy, size, color) => {
        context.save();
        const scale = size / 24;
        context.translate(cx - 12 * scale, cy - 12 * scale);
        context.scale(scale, scale);
        context.strokeStyle = color;
        context.fillStyle = color;
        context.lineWidth = 2.4;
        context.lineCap = 'round';
        context.lineJoin = 'round';
        elements.forEach(([tag, attrs]) => {
            if (tag === 'path') {
                context.stroke(new Path2D(attrs.d));
            } else if (tag === 'circle') {
                context.beginPath();
                context.arc(Number(attrs.cx), Number(attrs.cy), Number(attrs.r), 0, Math.PI * 2);
                context.stroke();
            } else if (tag === 'rect') {
                const x = Number(attrs.x);
                const y = Number(attrs.y);
                const w = Number(attrs.width);
                const h = Number(attrs.height);
                const rx = Number(attrs.rx || 0);
                context.beginPath();
                if (rx > 0 && context.roundRect) {
                    context.roundRect(x, y, w, h, rx);
                } else {
                    context.rect(x, y, w, h);
                }
                context.stroke();
            } else if (tag === 'line') {
                const x1 = Number(attrs.x1);
                const y1 = Number(attrs.y1);
                const x2 = Number(attrs.x2);
                const y2 = Number(attrs.y2);
                if (Math.abs(x2 - x1) < 0.1 && Math.abs(y2 - y1) < 0.1) {
                    context.beginPath();
                    context.arc(x1, y1, 1.2, 0, Math.PI * 2);
                    context.fill();
                } else {
                    context.beginPath();
                    context.moveTo(x1, y1);
                    context.lineTo(x2, y2);
                    context.stroke();
                }
            }
        });
        context.restore();
    };

    const graphIconUri = (type, iconKey) => {
        const palette = themePalette();
        const cacheKey = `${palette.dark ? 'dark' : 'light'}-${type}-${iconKey || ''}`;
        if (graphIconCache.has(cacheKey)) return graphIconCache.get(cacheKey);
        const canvas = document.createElement('canvas');
        canvas.width = 144;
        canvas.height = 116;
        const context = canvas.getContext('2d');
        if (!context) return '';
        context.lineWidth = 1.8;
        context.lineJoin = 'round';
        context.lineCap = 'round';
        context.shadowColor = palette.dark ? 'rgba(0,0,0,.52)' : 'rgba(22,68,96,.26)';
        context.shadowBlur = 9;
        context.shadowOffsetY = 5;
        const colors = {
            top: palette.dark ? '#55c8f4' : '#58c7ec',
            front: palette.dark ? '#087eae' : '#087eae',
            side: palette.dark ? '#075a84' : '#075d88',
            stroke: palette.dark ? '#9be4ff' : '#045b85',
            detail: palette.dark ? '#d6f5ff' : '#e9faff',
            dark: palette.dark ? '#062f49' : '#064667',
            accent: type === 'splice' ? palette.amber : (type === 'panel' || type === 'panel_group' ? palette.green : palette.cyan),
        };

        if (type === 'location') {
            context.shadowColor = 'transparent';
            const glyph = locationIconGlyphs[iconKey] || locationIconGlyphs['loc-office'];
            drawLucideGlyph(context, glyph, 72, 48, 74, palette.blue);
        } else if (type === 'room' || type === 'rack') {
            drawIsoBox(context, 36, 10, 52, 72, 16, colors);
            context.shadowColor = 'transparent';
            const slotCount = type === 'rack' ? 7 : 5;
            for (let slot = 0; slot < slotCount; slot += 1) {
                const slotY = 31 + slot * (type === 'rack' ? 9 : 12);
                context.fillStyle = colors.dark;
                context.fillRect(42, slotY, 39, 5);
                context.fillStyle = slot % 2 ? colors.accent : colors.detail;
                context.beginPath();
                context.arc(77, slotY + 2.5, 1.6, 0, Math.PI * 2);
                context.fill();
            }
            context.strokeStyle = colors.detail;
            context.strokeRect(39, 27, 45, 61);
        } else if (type === 'panel' || type === 'panel_group') {
            const layers = type === 'panel_group' ? 2 : 1;
            for (let layer = layers - 1; layer >= 0; layer -= 1) {
                drawIsoBox(context, 20 + layer * 5, 42 - layer * 15, 82, 24, 17, colors);
                context.shadowColor = 'transparent';
                for (let port = 0; port < 8; port += 1) {
                    context.fillStyle = port % 3 === 0 ? colors.accent : colors.detail;
                    context.fillRect(28 + layer * 5 + port * 9, 53 - layer * 15, 5, 5);
                }
            }
        } else if (type === 'splice') {
            context.fillStyle = colors.front;
            context.strokeStyle = colors.stroke;
            context.beginPath();
            context.ellipse(70, 30, 30, 13, 0, 0, Math.PI * 2);
            context.fill();
            context.stroke();
            context.fillRect(40, 30, 60, 49);
            context.strokeRect(40, 30, 60, 49);
            context.beginPath();
            context.ellipse(70, 79, 30, 13, 0, 0, Math.PI * 2);
            context.fillStyle = colors.side;
            context.fill();
            context.stroke();
            context.shadowColor = 'transparent';
            context.strokeStyle = colors.detail;
            [48, 60, 72, 84, 96].forEach((x) => {
                context.beginPath();
                context.moveTo(x, 37);
                context.lineTo(x, 72);
                context.stroke();
            });
            context.strokeStyle = colors.accent;
            context.lineWidth = 3;
            context.beginPath();
            context.moveTo(24, 55);
            context.lineTo(40, 55);
            context.moveTo(100, 55);
            context.lineTo(116, 55);
            context.stroke();
        } else {
            drawIsoBox(context, 27, 30, 73, 36, 17, colors);
        }

        const uri = canvas.toDataURL('image/png');
        graphIconCache.set(cacheKey, uri);
        return uri;
    };

    const cytoscapeStyles = () => {
        const palette = themePalette();
        return [
            {
                selector: 'node',
                style: {
                    shape: 'rectangle', width: 174, height: 132,
                    'background-color': palette.surface, 'background-opacity': 0, 'border-width': 0,
                    'background-image': 'data(icon)', 'background-fit': 'none', 'background-repeat': 'no-repeat',
                    'background-width': 112, 'background-height': 90, 'background-position-x': '50%', 'background-position-y': '10%',
                    label: 'data(label)', color: palette.text, 'font-size': 11.5, 'font-weight': 650,
                    'text-wrap': 'wrap', 'text-max-width': 160, 'text-valign': 'bottom', 'text-halign': 'center',
                    'text-margin-y': -8, 'line-height': 1.45,
                    'overlay-opacity': 0, 'transition-property': 'underlay-opacity, color',
                    'transition-duration': '180ms',
                },
            },
            { selector: 'node[type="location"]', style: { width: 188, height: 146, 'background-width': 126, 'background-height': 101 } },
            { selector: 'node[type="splice"]', style: { width: 154, height: 128, 'background-width': 106, 'background-height': 86, 'font-size': 10.5 } },
            { selector: 'node[type="rack"]', style: { width: 166, height: 128, 'background-width': 102, 'background-height': 84 } },
            { selector: 'node[type="panel"], node[type="panel_group"]', style: { width: 164, height: 118, 'background-width': 112, 'background-height': 90 } },
            { selector: 'node[type="panel_group"]', style: { opacity: 0.88 } },
            { selector: 'node[status="attention"], node[status="warning"]', style: { color: palette.amber, 'underlay-color': palette.amber, 'underlay-opacity': 0.1, 'underlay-padding': 7 } },
            { selector: 'node:selected', style: { color: palette.blue, 'underlay-color': palette.blue, 'underlay-opacity': 0.16, 'underlay-padding': 11 } },
            { selector: 'node.hovered', style: { color: palette.cyan, 'underlay-color': palette.cyan, 'underlay-opacity': 0.1, 'underlay-padding': 8 } },
            {
                selector: 'edge.base-edge',
                style: {
                    width: 5, 'curve-style': 'bezier', 'control-point-step-size': 80,
                    'line-color': palette.indigo, 'target-arrow-color': palette.indigo,
                    'target-arrow-shape': 'triangle', 'arrow-scale': 0.8,
                    label: 'data(label)', color: palette.muted, 'font-size': 10, 'font-weight': 700,
                    'text-background-color': palette.canvas, 'text-background-opacity': 0.94,
                    'text-background-padding': 5, 'text-background-shape': 'round-rectangle',
                    'text-rotation': 'autorotate', 'overlay-opacity': 0, 'line-cap': 'round',
                },
            },
            { selector: 'edge.base-edge[tone="violet"]', style: { 'line-color': palette.indigo, 'target-arrow-color': palette.indigo } },
            { selector: 'edge.base-edge[tone="cyan"]', style: { 'line-color': palette.cyan, 'target-arrow-color': palette.cyan } },
            { selector: 'edge.base-edge[tone="amber"]', style: { 'line-color': palette.amber, 'target-arrow-color': palette.amber } },
            { selector: 'edge.base-edge[partial="true"]', style: { 'line-style': 'dashed', 'line-dash-pattern': [10, 7] } },
            {
                selector: 'edge.inventory-edge',
                style: {
                    width: 2.2, 'curve-style': 'taxi', 'taxi-direction': 'downward', 'taxi-turn': 26,
                    'line-color': palette.borderStrong, 'target-arrow-color': palette.borderStrong,
                    'target-arrow-shape': 'triangle', 'arrow-scale': 0.72, 'line-cap': 'round',
                    'overlay-opacity': 0, opacity: 0.82,
                },
            },
            {
                selector: 'edge.flow-edge',
                style: {
                    width: 1.6, 'curve-style': 'bezier', 'line-style': 'dashed', 'line-dash-pattern': [3, 12],
                    'line-color': palette.canvas, opacity: 0.9, 'events': 'no', 'overlay-opacity': 0,
                },
            },
        ];
    };

    const renderRouteInspector = (container, edge) => {
        const inspector = container.closest('.topology-workspace')?.querySelector('[data-route-inspector]');
        if (!inspector) return;
        inspector.replaceChildren();
        const content = createElement('div', 'inspector-content');
        const icon = createElement('span', `cable-icon tone-${edge.tone}`);
        icon.innerHTML = '<i data-lucide="cable" aria-hidden="true"></i>';
        const code = createElement('span', 'code-label', edge.code);
        const title = createElement('h3', '', edge.cable);
        const subtitle = createElement('p', '', `${edge.medium} · ${edge.length}`);
        const capacity = createElement('div', `inspector-capacity tone-${edge.tone}`);
        const capacityHeader = createElement('div');
        capacityHeader.append(createElement('span', '', body.dataset.labelUtilization || 'Utilization'), createElement('strong', '', `${edge.used}/${edge.fibers}J`));
        const progress = createElement('div', 'progress large');
        const progressBar = createElement('i');
        progressBar.style.width = `${Math.round((edge.used / edge.fibers) * 100)}%`;
        progress.append(progressBar);
        capacity.append(capacityHeader, progress);
        const details = createElement('dl');
        [[body.dataset.labelMedium || 'Medium', edge.medium], [body.dataset.labelLength || 'Length', edge.length], [body.dataset.labelAvailable || 'Available', `${edge.fibers - edge.used}J`]].forEach(([label, value]) => {
            const row = createElement('div');
            row.append(createElement('dt', '', label), createElement('dd', '', value));
            details.append(row);
        });
        content.append(icon, code, title, subtitle, capacity, details);
        inspector.append(content);
        refreshIcons();
    };

    const inventoryNodeName = (workspace, node) => node.type === 'panel_group'
        ? workspace?.dataset.labelAggregate || node.name
        : node.name;

    const inventoryNodeSubtitle = (workspace, node) => {
        if (!workspace) return node.subtitle || '';
        if (node.type === 'location') return `${node.rooms} ${workspace.dataset.labelRooms} · ${node.racks} ${workspace.dataset.labelRacks}`;
        if (node.type === 'room') return `${node.rack_count} ${workspace.dataset.labelRacks} · ${workspace.dataset.labelFloor} ${node.floor}`;
        if (node.type === 'rack') return `${node.units_used}/${node.units_total}U · ${node.panels} ${workspace.dataset.labelPanels}`;
        if (node.type === 'panel') return `${node.ports} ${workspace.dataset.labelPorts} · ${node.occupied} ${workspace.dataset.labelOccupied}`;
        if (node.type === 'panel_group') return `${node.panel_count} ${workspace.dataset.labelPanels}`;
        return node.subtitle || '';
    };

    const renderInventoryInspector = (container, node) => {
        const workspace = container.closest('.inventory-workspace');
        const inspector = workspace?.querySelector('[data-inventory-inspector]');
        if (!workspace || !inspector) return;
        inspector.replaceChildren();
        const content = createElement('div', 'inspector-content inventory-inspector-content');
        const icon = createElement('span', `asset-inspector-icon asset-${node.type}`);
        const lucideName = node.type === 'location' ? (locationIconLucideNames[node.icon_key] || 'building-2') : graphIconName(node.type).replace(/[A-Z]/g, (letter, index) => `${index ? '-' : ''}${letter.toLowerCase()}`);
        icon.innerHTML = `<i data-lucide="${lucideName}" aria-hidden="true"></i>`;
        const typeKey = node.type === 'panel_group' ? 'panel' : node.type;
        const typeLabel = workspace.dataset[`label${typeKey.charAt(0).toUpperCase()}${typeKey.slice(1)}`] || typeKey;
        content.append(icon, createElement('span', 'code-label', typeLabel), createElement('h3', '', inventoryNodeName(workspace, node)), createElement('p', '', node.code));
        const details = createElement('dl');
        [[workspace.dataset.labelCode, node.code], [workspace.dataset.labelType, typeLabel], [workspace.dataset.labelDetails, inventoryNodeSubtitle(workspace, node)]].forEach(([label, value]) => {
            const row = createElement('div');
            row.append(createElement('dt', '', label), createElement('dd', '', value || '—'));
            details.append(row);
        });
        content.append(details);
        if (node.href) {
            const link = createElement('a', 'button button-primary button-full', workspace.dataset.labelOpen || 'Open physical view');
            link.href = node.href;
            content.append(link);
        }
        inspector.append(content);
        refreshIcons();
    };

    const initCytoscape = (container) => {
        if (!window.cytoscape) return;
        const scope = container.closest('.topology-workspace, .topology-preview-card, .inventory-workspace');
        const data = readJson(scope?.querySelector('.topology-data'));
        if (!data) return;
        const compact = container.dataset.compact === 'true';
        const graphMode = container.dataset.graphMode || 'topology';
        const inventoryMode = graphMode === 'inventory';
        const viewKind = inventoryMode ? 'inventory' : (compact ? 'dashboard' : 'topology');
        const canDragNodes = !compact;
        const nodePositionsKey = `nstructure-graph-nodes-${viewKind}`;
        const viewStateKey = `nstructure-graph-view-${viewKind}`;
        let savedPositions = {};
        if (canDragNodes) {
            try {
                savedPositions = JSON.parse(localStorage.getItem(nodePositionsKey) || '{}') || {};
            } catch {
                savedPositions = {};
            }
        }
        const elements = [];
        data.nodes.forEach((node) => elements.push({
            group: 'nodes',
            data: {
                ...node,
                icon: graphIconUri(node.type, node.icon_key),
                label: compact || inventoryMode ? `${node.code}\n${inventoryNodeName(scope, node)}` : `${node.name}\n${node.code} · ${node.subtitle}`,
            },
            position: savedPositions[node.id] || (inventoryMode ? undefined : { x: node.x * 10, y: node.y * 7 }),
        }));
        data.edges.forEach((edge) => {
            if (inventoryMode) {
                elements.push({ group: 'edges', data: { ...edge, source: edge.from, target: edge.to }, classes: 'inventory-edge' });
                return;
            }
            const common = { ...edge, source: edge.from, target: edge.to, label: `${edge.code}  ·  ${edge.fibers}J ${edge.medium}`, partial: String(edge.used < edge.fibers) };
            elements.push({ group: 'edges', data: common, classes: 'base-edge' });
            elements.push({ group: 'edges', data: { ...common, id: `flow-${edge.id}`, label: '' }, classes: 'flow-edge' });
        });
        const locationRoots = data.nodes.filter((node) => node.type === 'location').map((node) => `#${node.id}`).join(', ');
        const cy = window.cytoscape({
            container,
            elements,
            style: cytoscapeStyles(),
            layout: inventoryMode
                ? { name: 'breadthfirst', directed: true, roots: locationRoots, spacingFactor: 1.28, padding: 74, fit: true }
                : { name: 'preset', fit: true, padding: compact ? 34 : 90 },
            zoomingEnabled: true,
            panningEnabled: true,
            userZoomingEnabled: true,
            userPanningEnabled: true,
            minZoom: compact ? 0.45 : (inventoryMode ? 0.22 : 0.35),
            maxZoom: compact ? 2.2 : (inventoryMode ? 4.5 : 3.5),
            wheelSensitivity: 0.18,
            boxSelectionEnabled: false,
            autoungrabify: compact,
        });
        graphInstances.push(cy);
        if (inventoryMode && Object.keys(savedPositions).length) {
            cy.nodes().forEach((node) => {
                const saved = savedPositions[node.id()];
                if (saved) node.position(saved);
            });
        }
        let savedView = null;
        try {
            savedView = JSON.parse(localStorage.getItem(viewStateKey) || 'null');
        } catch {
            savedView = null;
        }
        if (savedView && typeof savedView.zoom === 'number' && savedView.pan) {
            cy.zoom(savedView.zoom);
            cy.pan(savedView.pan);
        }
        let saveViewTimer = 0;
        cy.on('zoom pan', () => {
            window.clearTimeout(saveViewTimer);
            saveViewTimer = window.setTimeout(() => {
                localStorage.setItem(viewStateKey, JSON.stringify({ zoom: cy.zoom(), pan: cy.pan() }));
            }, 250);
        });
        if (canDragNodes) {
            cy.on('dragfree', 'node', (event) => {
                const node = event.target;
                savedPositions[node.id()] = node.position();
                localStorage.setItem(nodePositionsKey, JSON.stringify(savedPositions));
            });
        }
        cy.on('mouseover', 'node', (event) => event.target.addClass('hovered'));
        cy.on('mouseout', 'node', (event) => event.target.removeClass('hovered'));
        cy.on('tap', 'node[type="location"]', (event) => {
            if (inventoryMode) {
                renderInventoryInspector(container, event.target.data());
                return;
            }
            if (!event.target.data('entity_id')) return;
            window.location.href = `/locations/${event.target.data('entity_id')}`;
        });
        cy.on('tap', 'node[type="room"], node[type="rack"], node[type="panel"], node[type="panel_group"]', (event) => renderInventoryInspector(container, event.target.data()));
        cy.on('tap', 'edge.base-edge', (event) => renderRouteInspector(container, event.target.data()));
        scope?.querySelector('[data-graph-zoom-in]')?.addEventListener('click', () => cy.animate({ zoom: Math.min(cy.maxZoom(), cy.zoom() * 1.22), duration: 180 }));
        scope?.querySelector('[data-graph-zoom-out]')?.addEventListener('click', () => cy.animate({ zoom: Math.max(cy.minZoom(), cy.zoom() / 1.22), duration: 180 }));
        scope?.querySelector('[data-graph-fit]')?.addEventListener('click', () => cy.animate({ fit: { eles: cy.elements(), padding: compact ? 34 : 90 }, duration: 260 }));
        let dashOffset = 0;
        let previousFrame = 0;
        const animateFlow = (time) => {
            if (time - previousFrame > 65) {
                dashOffset = (dashOffset - 1.4) % 30;
                cy.edges('.flow-edge').style('line-dash-offset', dashOffset);
                previousFrame = time;
            }
            if (container.isConnected) requestAnimationFrame(animateFlow);
        };
        if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) requestAnimationFrame(animateFlow);
        new ResizeObserver(() => cy.resize()).observe(container);
    };

    const fiberColors = {
        blue: '#2563eb', orange: '#f97316', green: '#22c55e', brown: '#92400e',
        slate: '#64748b', white: '#f8fafc', red: '#ef4444', black: '#111827',
        yellow: '#eab308', violet: '#8b5cf6', rose: '#f43f5e', aqua: '#06b6d4',
    };

    const portHighlightColors = {
        red: '#ef4444', orange: '#f97316', amber: '#f59e0b', yellow: '#eab308',
        lime: '#84cc16', green: '#22c55e', teal: '#14b8a6', cyan: '#06b6d4',
        blue: '#3b82f6', indigo: '#6366f1', purple: '#a855f7', pink: '#ec4899',
    };

    const PANEL_ROW_STRIDE = 100;

    const createCadController = (container, worldWidth, worldHeight, renderWorld) => {
        if (!window.Konva) return null;
        const workspace = container.closest('[data-konva-workspace]');
        const stage = new window.Konva.Stage({ container, width: container.clientWidth, height: container.clientHeight, draggable: true });
        const layer = new window.Konva.Layer();
        const world = new window.Konva.Group({ x: worldWidth / 2, y: worldHeight / 2, offsetX: worldWidth / 2, offsetY: worldHeight / 2 });
        layer.add(world);
        stage.add(layer);
        const readout = workspace?.querySelector('[data-zoom-readout]');
        let rotation = 0;
        let wheelZoomActive = false;
        container.addEventListener('mousedown', () => { wheelZoomActive = true; });
        container.addEventListener('mouseleave', () => { wheelZoomActive = false; });

        const updateReadout = () => {
            if (readout) readout.textContent = `${Math.round(stage.scaleX() * 100)}% · ${rotation}°`;
        };
        const fit = () => {
            stage.scale({ x: 1, y: 1 });
            stage.position({ x: 0, y: 0 });
            const rect = world.getClientRect({ skipShadow: true });
            const padding = 44;
            const scale = Math.min((stage.width() - padding * 2) / rect.width, (stage.height() - padding * 2) / rect.height, 1.4);
            stage.scale({ x: scale, y: scale });
            stage.position({ x: stage.width() / 2 - (rect.x + rect.width / 2) * scale, y: stage.height() / 2 - (rect.y + rect.height / 2) * scale });
            stage.batchDraw();
            updateReadout();
        };
        const zoomAt = (point, factor) => {
            const oldScale = stage.scaleX();
            const newScale = Math.max(0.18, Math.min(12, oldScale * factor));
            const modelPoint = { x: (point.x - stage.x()) / oldScale, y: (point.y - stage.y()) / oldScale };
            stage.scale({ x: newScale, y: newScale });
            stage.position({ x: point.x - modelPoint.x * newScale, y: point.y - modelPoint.y * newScale });
            stage.batchDraw();
            updateReadout();
        };
        const rotate = (delta) => {
            rotation = (rotation + delta + 360) % 360;
            world.rotation(rotation);
            layer.batchDraw();
            fit();
        };

        renderWorld(world, layer, stage);
        layer.draw();
        fit();

        const redrawForTheme = () => {
            world.destroyChildren();
            renderWorld(world, layer, stage);
            layer.draw();
            fit();
        };
        window.addEventListener('nstructure:theme', redrawForTheme);

        stage.on('wheel', (event) => {
            if (!wheelZoomActive) return;
            event.evt.preventDefault();
            const direction = event.evt.deltaY > 0 ? 1 / 1.12 : 1.12;
            zoomAt(stage.getPointerPosition(), event.evt.ctrlKey ? 1 / direction : direction);
        });
        workspace?.querySelector('[data-canvas-zoom-in]')?.addEventListener('click', () => zoomAt({ x: stage.width() / 2, y: stage.height() / 2 }, 1.25));
        workspace?.querySelector('[data-canvas-zoom-out]')?.addEventListener('click', () => zoomAt({ x: stage.width() / 2, y: stage.height() / 2 }, 0.8));
        workspace?.querySelector('[data-canvas-fit]')?.addEventListener('click', fit);
        workspace?.querySelector('[data-canvas-rotate-left]')?.addEventListener('click', () => rotate(-90));
        workspace?.querySelector('[data-canvas-rotate-right]')?.addEventListener('click', () => rotate(90));
        stage.on('dragmove', updateReadout);
        new ResizeObserver(() => {
            stage.size({ width: container.clientWidth, height: container.clientHeight });
            fit();
        }).observe(container);
        return { stage, layer, world, fit };
    };

    const addRackDevice = (world, device, rack, geometry, palette, labels) => {
        const { rackX, rackY, rackWidth, unitHeight } = geometry;
        const top = rackY + (rack.total_units - device.start) * unitHeight;
        const height = Math.max(unitHeight * device.height - 3, 12);
        const deviceWidth = rackWidth - 108;
        const colors = { violet: palette.indigo, cyan: palette.cyan, blue: palette.blue, amber: palette.amber, slate: palette.borderStrong };
        const accent = colors[device.tone] || palette.blue;
        const group = new window.Konva.Group({ x: rackX + 54, y: top + 2 });
        group.add(new window.Konva.Rect({ width: deviceWidth, height, cornerRadius: 7, fill: palette.surfaceRaised, stroke: accent, strokeWidth: 1.4, shadowColor: palette.shadow, shadowBlur: 12, shadowOpacity: 0.28, shadowOffsetY: 4 }));
        group.add(new window.Konva.Rect({ width: 7, height, cornerRadius: [7, 0, 0, 7], fill: accent }));
        group.add(new window.Konva.Text({ x: 26, y: Math.max(4, height / 2 - 9), width: 186, ellipsis: true, text: device.code, fontFamily: 'Inter, sans-serif', fontSize: Math.max(11, Math.min(17, height * 0.35)), fontStyle: 'bold', fill: palette.text }));
        if (height > 32) group.add(new window.Konva.Text({ x: 26, y: height / 2 + 6, width: 186, ellipsis: true, text: device.name, fontFamily: 'Inter, sans-serif', fontSize: 10, fill: palette.muted }));
        if (device.ports) {
            group.add(new window.Konva.Text({ x: rackWidth - 300, y: Math.max(4, height / 2 - 8), width: 160, align: 'right', text: `${device.occupied}/${device.ports} PORTS`, fontFamily: 'JetBrains Mono, monospace', fontSize: 11, fontStyle: 'bold', fill: accent }));
        }
        if (device.type === 'patch_panel' && device.ports) {
            const portStates = Array.isArray(device.port_items) && device.port_items.length
                ? device.port_items
                : Array.from({ length: Number(device.ports) }, (_, index) => ({ number: index + 1, status: index < Number(device.occupied) ? 'occupied' : 'available' }));
            const portMap = { x: 230, y: 4, width: 274, height: Math.max(10, height - 8) };
            const maxRowsForHeight = Math.max(1, Math.min(Math.floor(portMap.height / 7), 6));
            const portRows = Math.max(1, Math.min(Number(device.rows || (device.ports > 24 ? 2 : 1)), maxRowsForHeight));
            const portColumns = Math.ceil(portStates.length / portRows);
            const xStep = portMap.width / Math.max(1, portColumns);
            const yStep = portMap.height / portRows;
            const dotsFit = xStep >= 6.5 && yStep >= 6.5;
            const portTooltip = new window.Konva.Label({ visible: false, listening: false, opacity: 0.98 });
            const portTooltipTag = new window.Konva.Tag({ fill: palette.dark ? '#202124' : '#ffffff', stroke: palette.green, strokeWidth: 1.5, cornerRadius: 9, pointerDirection: 'down', pointerWidth: 12, pointerHeight: 7, shadowColor: palette.shadow, shadowBlur: 16, shadowOpacity: 0.3, shadowOffsetY: 6 });
            const portTooltipText = new window.Konva.Text({ width: 360, padding: 11, fontFamily: 'Inter, sans-serif', fontSize: 13, fontStyle: 'bold', lineHeight: 1.35, fill: palette.text });
            const statusLabels = {
                occupied: labels.statusOccupied || 'Occupied',
                available: labels.statusAvailable || 'Available',
                reserved: labels.statusReserved || 'Reserved',
                blocked: labels.statusBlocked || 'Blocked',
                damaged: labels.statusDamaged || 'Damaged',
            };
            portTooltip.add(portTooltipTag);
            portTooltip.add(portTooltipText);
            group.add(new window.Konva.Rect({ ...portMap, cornerRadius: 4, fill: palette.canvas, stroke: palette.border, strokeWidth: 0.8, opacity: 0.72, listening: false }));
            if (dotsFit) {
                const radius = Math.max(1.6, Math.min(4.1, xStep * 0.31, yStep * 0.3));
                portStates.forEach((port, index) => {
                    const row = Math.floor(index / portColumns);
                    const column = index % portColumns;
                    const status = String(port.status || 'available').toLowerCase();
                    const statusColor = status === 'occupied' ? palette.green : (status === 'reserved' ? palette.amber : (['blocked', 'damaged'].includes(status) ? palette.red : palette.borderStrong));
                    const portCircle = new window.Konva.Circle({
                        x: portMap.x + (column * xStep) + (xStep / 2),
                        y: portMap.y + (row * yStep) + (yStep / 2),
                        radius,
                        fill: status === 'available' ? palette.surfaceRaised : statusColor,
                        stroke: statusColor,
                        strokeWidth: Math.max(0.6, radius * 0.3),
                        shadowColor: statusColor,
                        shadowBlur: status === 'available' ? 0 : Math.min(radius * 1.8, xStep * 0.32, yStep * 0.32),
                        shadowOpacity: status === 'available' ? 0 : 0.32,
                    });
                    portCircle.on('mouseenter', () => {
                        const portNumber = String(port.number || index + 1).padStart(2, '0');
                        const portDescription = port.label ? ` · ${port.label}` : '';
                        const routeLines = [];
                        if (port.rear_destination) routeLines.push(`${labels.rearSideLabel || 'Rear'}  →  ${port.rear_destination}`);
                        if (port.front_destination) routeLines.push(`${labels.frontSideLabel || 'Front'}  →  ${port.front_destination}`);
                        const destination = routeLines.length ? routeLines.join('\n') : (port.destination || labels.noDestination || 'No documented destination');
                        portTooltipTag.stroke(statusColor);
                        portTooltipText.text(`${labels.portLabel || 'Port'} ${portNumber}${portDescription} · ${statusLabels[status] || status}\n${destination}`);
                        portTooltip.position({ x: portCircle.x(), y: portMap.y - 5 });
                        portTooltip.show();
                        portTooltip.moveToTop();
                        world.getLayer().batchDraw();
                    });
                    portCircle.on('mouseleave', () => {
                        portTooltip.hide();
                        world.getLayer().batchDraw();
                    });
                    group.add(portCircle);
                });
            } else {
                const counts = portStates.reduce((acc, port) => {
                    const status = String(port.status || 'available').toLowerCase();
                    acc[status] = (acc[status] || 0) + 1;
                    return acc;
                }, {});
                let cursor = portMap.x;
                const barHeight = Math.min(14, portMap.height);
                const barY = portMap.y + (portMap.height - barHeight) / 2;
                ['occupied', 'reserved', 'damaged', 'blocked'].forEach((status) => {
                    const count = counts[status] || 0;
                    if (!count) return;
                    const segmentWidth = (count / portStates.length) * portMap.width;
                    const statusColor = status === 'occupied' ? palette.green : (status === 'reserved' ? palette.amber : (['blocked', 'damaged'].includes(status) ? palette.red : palette.borderStrong));
                    const segment = new window.Konva.Rect({ x: cursor, y: barY, width: Math.max(0, segmentWidth), height: barHeight, cornerRadius: 2, fill: statusColor, opacity: 0.85 });
                    segment.on('mouseenter', () => {
                        portTooltipTag.stroke(statusColor);
                        portTooltipText.text(`${count} × ${statusLabels[status] || status}`);
                        portTooltip.position({ x: segment.x() + segment.width() / 2, y: portMap.y - 5 });
                        portTooltip.show();
                        portTooltip.moveToTop();
                        world.getLayer().batchDraw();
                    });
                    segment.on('mouseleave', () => {
                        portTooltip.hide();
                        world.getLayer().batchDraw();
                    });
                    group.add(segment);
                    cursor += segmentWidth;
                });
            }
            group.add(portTooltip);
        }
        group.on('mouseenter', () => { document.body.style.cursor = 'pointer'; group.scale({ x: 1.006, y: 1.006 }); world.getLayer().batchDraw(); });
        group.on('mouseleave', () => { document.body.style.cursor = 'default'; group.scale({ x: 1, y: 1 }); group.findOne('Label')?.hide(); world.getLayer().batchDraw(); });
        if (device.type === 'patch_panel') {
            group.on('click tap', () => { window.location.href = `/patch-panels/${device.id}`; });
        } else if (device.type === 'rack_item') {
            group.on('click tap', () => { window.openRackItemEditModal?.(device); });
        }
        world.add(group);
    };

    const renderRackWorld = (rack) => (world) => {
        const palette = themePalette();
        const rackCanvasLabels = document.querySelector('[data-rack-canvas]')?.dataset || {};
        const geometry = { rackX: 160, rackY: 70, rackWidth: 820, unitHeight: 25 };
        const { rackX, rackY, rackWidth, unitHeight } = geometry;
        const rackHeight = rack.total_units * unitHeight;
        world.add(new window.Konva.Rect({ x: rackX, y: rackY, width: rackWidth, height: rackHeight, cornerRadius: 10, fill: palette.surfaceMuted, stroke: palette.borderStrong, strokeWidth: 5, shadowColor: palette.shadow, shadowBlur: 30, shadowOpacity: 0.35, shadowOffsetY: 16 }));
        [rackX + 32, rackX + rackWidth - 44].forEach((x) => {
            world.add(new window.Konva.Rect({ x, y: rackY + 12, width: 12, height: rackHeight - 24, cornerRadius: 5, fill: palette.borderStrong, opacity: 0.65 }));
        });
        for (let unit = 1; unit <= rack.total_units; unit += 1) {
            const y = rackY + (rack.total_units - unit) * unitHeight;
            world.add(new window.Konva.Line({ points: [rackX + 48, y, rackX + rackWidth - 48, y], stroke: palette.border, strokeWidth: unit % 5 === 0 ? 1.3 : 0.7, opacity: unit % 5 === 0 ? 0.8 : 0.45 }));
            world.add(new window.Konva.Text({ x: rackX - 48, y: y + 6, width: 36, align: 'right', text: String(unit).padStart(2, '0'), fontFamily: 'JetBrains Mono, monospace', fontSize: 10, fill: unit % 5 === 0 ? palette.text : palette.muted }));
            [rackX + 38, rackX + rackWidth - 38].forEach((x) => world.add(new window.Konva.Circle({ x, y: y + unitHeight / 2, radius: 2.7, fill: palette.canvas, stroke: palette.borderStrong, strokeWidth: 1 })));
        }
        world.add(new window.Konva.Text({ x: rackX, y: 20, width: rackWidth, align: 'center', text: `${rack.code}  ·  ${rack.name}  ·  ${rack.total_units}U`, fontFamily: 'Inter, sans-serif', fontSize: 18, fontStyle: 'bold', fill: palette.text }));
        rack.devices.forEach((device) => addRackDevice(world, device, rack, geometry, palette, rackCanvasLabels));
    };

    const updatePortInspector = (port) => {
        const inspector = document.querySelector('[data-port-inspector]');
        if (!inspector) return;
        selectedPort = port;
        selectPanelCanvasPort?.(Number(port.id));
        const inlineEditor = inspector.querySelector('[data-port-inline-edit-form]');
        if (inlineEditor) inlineEditor.hidden = true;
        inspector.querySelector('[data-port-empty]').hidden = true;
        inspector.querySelector('[data-port-details]').hidden = false;
        const setInspectorText = (selector, value) => {
            const element = inspector.querySelector(selector);
            if (element) element.textContent = value;
        };
        setInspectorText('[data-port-title]', `Port ${String(port.number).padStart(2, '0')}`);
        setInspectorText('[data-port-number]', String(port.number).padStart(2, '0'));
        setInspectorText('[data-port-connector]', port.connector || '—');
        setInspectorText('[data-port-label]', port.label || '—');
        setInspectorText('[data-port-notes]', port.notes || '—');
        setInspectorText('[data-port-fiber]', port.fiber || '—');
        setInspectorText('[data-port-loss]', port.loss || '—');
        setInspectorText('[data-port-rear-destination]', port.rear_destination || port.destination || '—');
        setInspectorText('[data-port-front-destination]', port.front_destination || '—');
        const status = inspector.querySelector('[data-port-status]');
        const panelCanvasStatusLabels = document.querySelector('[data-panel-canvas]')?.dataset || {};
        const statusLabelKey = `status${port.status.charAt(0).toUpperCase()}${port.status.slice(1)}`;
        status.textContent = panelCanvasStatusLabels[statusLabelKey] || port.status;
        status.className = `status-badge status-${port.status}`;
        inspector.querySelector('[data-port-color]').className = `color-${port.color}`;
        const traceButton = inspector.querySelector('[data-trace-button]');
        traceButton.disabled = port.status !== 'occupied';
        traceButton.dataset.portId = String(port.id);
        const connectButton = document.querySelector('[data-port-connect-open]');
        if (connectButton) connectButton.disabled = Boolean(port.has_patch_cord) || ['blocked', 'damaged'].includes(port.status);
        const result = inspector.querySelector('[data-trace-result]');
        result.hidden = true;
        result.replaceChildren();
    };

    const renderPanelWorld = (panel, worldWidth, worldHeight) => (world, layer, stage) => {
        const palette = themePalette();
        const panelCanvasLabels = document.querySelector('[data-panel-canvas]')?.dataset || {};
        const rows = Math.max(1, Number(panel.layout_rows || (panel.ports > 24 ? 2 : 1)));
        const columns = Math.max(1, Number(panel.layout_columns || Math.ceil(panel.ports / rows)));
        const portAreaHeight = rows * PANEL_ROW_STRIDE;
        const chassis = { x: 100, y: 175, width: worldWidth - 200, height: Math.max(330, 132 + portAreaHeight + 50) };
        world.add(new window.Konva.Rect({ ...chassis, cornerRadius: 22, fillLinearGradientStartPoint: { x: 0, y: 0 }, fillLinearGradientEndPoint: { x: 0, y: chassis.height }, fillLinearGradientColorStops: [0, palette.surfaceRaised, 0.5, palette.surface, 1, palette.surfaceMuted], stroke: palette.borderStrong, strokeWidth: 3, shadowColor: palette.shadow, shadowBlur: 34, shadowOpacity: 0.4, shadowOffsetY: 18 }));
        [chassis.x + 34, chassis.x + chassis.width - 34].forEach((x) => {
            world.add(new window.Konva.Rect({ x: x - 18, y: chassis.y + 24, width: 36, height: chassis.height - 48, cornerRadius: 8, fill: palette.surfaceMuted, stroke: palette.border, strokeWidth: 1 }));
            [chassis.y + 74, chassis.y + chassis.height - 74].forEach((y) => world.add(new window.Konva.Circle({ x, y, radius: 8, fill: palette.canvas, stroke: palette.borderStrong, strokeWidth: 2 })));
        });
        world.add(new window.Konva.Text({ x: chassis.x + 90, y: chassis.y + 28, text: panel.code, fontFamily: 'Inter, sans-serif', fontSize: 30, fontStyle: 'bold', fill: palette.text }));
        world.add(new window.Konva.Text({ x: chassis.x + 90, y: chassis.y + 70, text: `${panel.connector}  ·  ${panel.ports} PORT  ·  ${panel.incoming}`, fontFamily: 'JetBrains Mono, monospace', fontSize: 18, fill: palette.muted }));
        const portArea = { x: chassis.x + 90, y: chassis.y + 132, width: chassis.width - 180, height: portAreaHeight };
        const xStep = portArea.width / columns;
        const yStep = portArea.height / rows;
        let selectedGroup = null;
        const portGroups = new Map();
        const routeTooltip = new window.Konva.Label({ visible: false, listening: false, opacity: 0.98 });
        const routeTooltipTag = new window.Konva.Tag({ fill: palette.dark ? '#202124' : '#ffffff', stroke: palette.blue, strokeWidth: 2, cornerRadius: 14, pointerDirection: 'down', pointerWidth: 20, pointerHeight: 12, shadowColor: palette.shadow, shadowBlur: 22, shadowOpacity: 0.3, shadowOffsetY: 8 });
        const routeTooltipText = new window.Konva.Text({ width: 480, padding: 18, fontFamily: 'Inter, sans-serif', fontSize: 22, fontStyle: 'bold', lineHeight: 1.35, fill: palette.text });
        routeTooltip.add(routeTooltipTag);
        routeTooltip.add(routeTooltipText);
        const showRouteTooltip = (port, x, y, row) => {
            const rearDestination = port.rear_destination || port.fiber;
            const frontDestination = port.front_destination;
            const portStatus = String(port.status || 'available').toLowerCase();
            if (!rearDestination && !frontDestination && !['damaged', 'blocked', 'reserved'].includes(portStatus)) {
                routeTooltip.hide();
                return;
            }
            const lines = [`P${String(port.number).padStart(2, '0')}  ·  ${port.connector}  ·  ${portStatus.toUpperCase()}`];
            if (rearDestination) lines.push(`${panelCanvasLabels.rearSideLabel || 'Rear'}  →  ${rearDestination}`);
            if (frontDestination) lines.push(`${panelCanvasLabels.frontSideLabel || 'Front'}  →  ${frontDestination}`);
            routeTooltipText.text(lines.join('\n'));
            routeTooltipTag.stroke(portStatus === 'damaged' ? palette.red : (portStatus === 'blocked' ? palette.amber : palette.blue));
            routeTooltipTag.pointerDirection(row === 0 ? 'down' : 'up');
            routeTooltip.position({ x, y: row === 0 ? y - 42 : y + 42 });
            routeTooltip.show();
            routeTooltip.moveToTop();
        };

        panel.port_items.forEach((port, index) => {
            const row = Math.floor(index / columns);
            const column = index % columns;
            const x = portArea.x + xStep * column + xStep / 2;
            const y = portArea.y + yStep * row + yStep / 2;
            const group = new window.Konva.Group({ x, y });
            const portStatus = String(port.status || 'available').toLowerCase();
            const occupied = portStatus === 'occupied';
            const damaged = portStatus === 'damaged';
            const blocked = portStatus === 'blocked';
            const reserved = portStatus === 'reserved';
            const stateAccent = damaged ? palette.red : (blocked || reserved ? palette.amber : palette.borderStrong);
            const accent = occupied ? (fiberColors[port.color] || palette.blue) : stateAccent;
            const selection = new window.Konva.Circle({ name: 'selection-ring', radius: 31, stroke: palette.green, strokeWidth: 3, visible: false, shadowColor: palette.green, shadowBlur: 14, shadowOpacity: 0.62 });
            group.add(selection);
            group.add(new window.Konva.Circle({ radius: 24, fill: palette.canvas, stroke: damaged ? palette.red : (blocked || reserved ? palette.amber : (occupied ? palette.borderStrong : palette.border)), strokeWidth: damaged ? 5 : 3, shadowColor: damaged ? palette.red : palette.shadow, shadowBlur: damaged ? 16 : 6, shadowOpacity: damaged ? 0.58 : 0.3 }));
            if (port.highlight_color && portHighlightColors[port.highlight_color]) {
                group.add(new window.Konva.Circle({ radius: 28, stroke: portHighlightColors[port.highlight_color], strokeWidth: 3, dash: [4, 3] }));
            }
            group.add(new window.Konva.Circle({ radius: 15, fill: occupied || damaged || blocked || reserved ? accent : palette.surfaceMuted, stroke: occupied && port.color === 'white' ? palette.borderStrong : accent, strokeWidth: 2 }));
            group.add(new window.Konva.Circle({ radius: 6, fill: occupied || damaged || blocked || reserved ? palette.canvas : palette.border, opacity: 0.9 }));
            const portLabelSize = Math.max(14, Math.min(18, xStep * 0.24, yStep * 0.24));
            const portLabelY = -56;
            const portLabel = new window.Konva.Group({ x: -27, y: portLabelY, listening: false });
            portLabel.add(new window.Konva.Rect({ width: 54, height: 22, cornerRadius: 7, fill: palette.surfaceRaised, stroke: palette.border, strokeWidth: 1, opacity: 0.96 }));
            portLabel.add(new window.Konva.Text({ y: 2, width: 54, height: 18, align: 'center', verticalAlign: 'middle', text: String(port.number).padStart(2, '0'), fontFamily: 'JetBrains Mono, monospace', fontSize: portLabelSize, fontStyle: 'bold', fill: palette.muted }));
            group.add(portLabel);
            if (port.has_patch_cord) {
                group.add(new window.Konva.Circle({ x: 20, y: -20, radius: 11, fill: palette.blue, stroke: palette.surfaceRaised, strokeWidth: 2, shadowColor: palette.blue, shadowBlur: 9, shadowOpacity: 0.45 }));
                group.add(new window.Konva.Text({ x: 13, y: -28, width: 14, align: 'center', text: '↗', fontFamily: 'Inter, sans-serif', fontSize: 14, fontStyle: 'bold', fill: '#ffffff' }));
            } else if (port.has_front_connection) {
                group.add(new window.Konva.Rect({ x: 11, y: -31, width: 22, height: 22, cornerRadius: 7, fill: palette.green, stroke: palette.surfaceRaised, strokeWidth: 2, shadowColor: palette.green, shadowBlur: 9, shadowOpacity: 0.45 }));
                group.add(new window.Konva.Text({ x: 13, y: -29, width: 18, align: 'center', text: 'D', fontFamily: 'Inter, sans-serif', fontSize: 13, fontStyle: 'bold', fill: '#ffffff' }));
            }
            group.on('mouseenter', () => { document.body.style.cursor = 'crosshair'; group.scale({ x: 1.12, y: 1.12 }); showRouteTooltip(port, x, y, row); layer.batchDraw(); });
            group.on('mouseleave', () => { document.body.style.cursor = 'default'; group.scale({ x: 1, y: 1 }); routeTooltip.hide(); layer.batchDraw(); });
            group.on('click tap', () => {
                showRouteTooltip(port, x, y, row);
                updatePortInspector(port);
            });
            portGroups.set(Number(port.id), { group, selection });
            world.add(group);
        });
        selectPanelCanvasPort = (portId) => {
            const requested = portGroups.get(Number(portId));
            if (!requested) return;
            selectedGroup?.findOne('.selection-ring')?.hide();
            requested.selection.show();
            selectedGroup = requested.group;
            layer.batchDraw();
        };
        if (selectedPort) selectPanelCanvasPort(Number(selectedPort.id));
        world.add(routeTooltip);
        world.add(new window.Konva.Text({ x: chassis.x + 90, y: chassis.y + chassis.height - 18, width: chassis.width - 180, align: 'right', text: `${panel.occupied} ACTIVE  ·  ${panel.available} AVAILABLE  ·  ${panel.unterminated} UNTERMINATED`, fontFamily: 'JetBrains Mono, monospace', fontSize: 13, fill: panel.unterminated ? palette.amber : palette.muted }));
        stage.on('dblclick dbltap', () => {
            const pointer = stage.getPointerPosition();
            if (pointer) stage.fire('wheel', { evt: { preventDefault() {}, deltaY: -1, ctrlKey: false } });
        });
        stage.on('click tap', (event) => {
            if (event.target === stage) {
                routeTooltip.hide();
                layer.batchDraw();
            }
        });
    };

    const renderTrace = async (button) => {
        const inspector = document.querySelector('[data-port-inspector]');
        const result = inspector?.querySelector('[data-trace-result]');
        const portId = Number(button?.dataset.portId || 0);
        if (!portId || !result) return;
        button.disabled = true;
        try {
            const response = await fetch(`/api/v1/fiber-paths/from-port/${portId}`, { headers: { Accept: 'application/json' } });
            const payload = await response.json();
            if (!response.ok) throw new Error(payload.error || body.dataset.toastError);
            result.replaceChildren();
            const heading = createElement('strong', '', body.dataset.labelPhysicalPath || 'Physical path');
            const timeline = createElement('ol', 'fiber-trace-timeline');
            payload.data.steps.forEach((step) => {
                const item = createElement('li', `trace-step type-${step.type} status-${step.status}`);
                const marker = createElement('i', '', step.type === 'port' ? 'P' : (step.type === 'splice' ? 'S' : 'F'));
                const copy = createElement('span');
                copy.append(createElement('strong', '', step.label), createElement('small', '', step.detail));
                item.append(marker, copy);
                timeline.append(item);
            });
            result.append(heading, timeline);
            result.hidden = false;
        } catch (error) {
            showToast(error.message || body.dataset.toastError, 'error');
        } finally {
            button.disabled = false;
        }
    };

    const initializeVisualizations = () => {
        document.querySelectorAll('[data-cytoscape]').forEach(initCytoscape);
        const rackContainer = document.querySelector('[data-rack-canvas]');
        if (rackContainer) {
            const rack = readJson(rackContainer.closest('[data-konva-workspace]')?.querySelector('.rack-data'));
            if (rack) createCadController(rackContainer, 1140, 1190, renderRackWorld(rack));
        }
        const panelContainer = document.querySelector('[data-panel-canvas]');
        if (panelContainer) {
            const panel = readJson(panelContainer.closest('[data-konva-workspace]')?.querySelector('.panel-data'));
            if (panel) {
                const panelRows = Math.max(1, Number(panel.layout_rows || (panel.ports > 24 ? 2 : 1)));
                const panelColumns = Math.max(1, Number(panel.layout_columns || Math.ceil(panel.ports / panelRows)));
                const panelWorldWidth = Math.max(1800, 380 + (panelColumns * 78));
                const panelWorldHeight = 680 + Math.max(0, panelRows - 2) * PANEL_ROW_STRIDE;
                createCadController(panelContainer, panelWorldWidth, panelWorldHeight, renderPanelWorld(panel, panelWorldWidth, panelWorldHeight));
            }
        }
    };

    document.querySelector('[data-trace-button]')?.addEventListener('click', (event) => renderTrace(event.currentTarget));
    document.querySelector('[data-trace-selected]')?.addEventListener('click', () => document.querySelector('[data-trace-button]:not(:disabled)')?.click());
    window.addEventListener('nstructure:theme', () => {
        graphInstances.forEach((cy) => {
            cy.nodes().forEach((node) => node.data('icon', graphIconUri(node.data('type'), node.data('icon_key'))));
            cy.style(cytoscapeStyles()).update();
        });
    });

    const sensorGrid = document.querySelector('[data-sensor-grid]');
    if (sensorGrid) {
        const sensorLabels = sensorGrid.dataset;
        const ALARM_REASON_LABELS = {
            ping: 'alarmPingLabel',
            temperature: 'alarmTemperatureLabel',
            humidity: 'alarmHumidityLabel',
            inputs: 'alarmInputsLabel',
        };
        const applySensorReadings = (sensor) => {
            const card = sensorGrid.querySelector(`[data-sensor-card][data-sensor-id="${sensor.id}"]`);
            if (!card) return;
            const disabled = sensor.monitoring_enabled === false;
            const reasons = sensor.alarm?.reasons || [];
            const inputs = sensor.inputs || [];
            const alarmActive = !disabled && sensor.alarm?.active === true;
            const pingDown = !disabled && sensor.ping != null && !sensor.ping.ok;
            const hasInputData = inputs.some((input) => input.last_alarm_state != null);
            const noData = !disabled && !sensor.temperature?.ok && !sensor.humidity?.ok && !hasInputData;

            card.classList.toggle('sensor-tile-disabled', disabled);
            card.classList.toggle('alarm', alarmActive);
            card.classList.toggle('sensor-tile-down', pingDown);
            card.classList.toggle('sensor-tile-no-data', !disabled && noData);
            card.title = alarmActive
                ? `${sensor.name} — ${reasons.map((reason) => sensorLabels[ALARM_REASON_LABELS[reason]] || reason).join(' · ')}`
                : `${sensor.name} · ${sensor.model || '—'} · ${sensor.host}`;

            const badge = card.querySelector('[data-sensor-ping-badge]');
            if (badge) {
                badge.classList.toggle('down', pingDown);
                badge.textContent = disabled ? '' : pingDown
                    ? (sensorLabels.pingDownLabel || '')
                    : (sensor.ping?.ok && sensor.ping.latency_ms != null ? `${Math.round(sensor.ping.latency_ms)}ms` : '');
            }

            const protocol = card.querySelector('[data-sensor-protocol]');
            if (protocol) protocol.classList.toggle('down', pingDown);

            const tempValue = card.querySelector('[data-sensor-value-temperature]');
            if (tempValue) {
                tempValue.textContent = !disabled && sensor.temperature?.ok ? `${Number(sensor.temperature.value).toFixed(1)}°C` : '';
                tempValue.classList.toggle('value-alarm', reasons.includes('temperature'));
            }
            const humidityValue = card.querySelector('[data-sensor-value-humidity]');
            if (humidityValue) {
                humidityValue.textContent = !disabled && sensor.humidity?.ok ? `${Number(sensor.humidity.value).toFixed(1)}%` : '';
                humidityValue.classList.toggle('value-alarm', reasons.includes('humidity'));
            }

            const groupAlarm = (groupName) => !disabled && inputs.some((input) => input.group === groupName && input.last_alarm_state === 2);
            const miastoBadge = card.querySelector('[data-input-group="miasto"]');
            if (miastoBadge) miastoBadge.classList.toggle('alarm', groupAlarm('miasto'));
            const agregatBadge = card.querySelector('[data-input-group="agregat"]');
            if (agregatBadge) agregatBadge.classList.toggle('agregat-alarm', groupAlarm('agregat'));
        };
        const refreshSensors = async (silent = false) => {
            try {
                const response = await fetch('/api/v1/sensors/poll', { headers: { Accept: 'application/json' } });
                const payload = await response.json();
                (payload.data || []).forEach(applySensorReadings);
            } catch (error) {
                if (!silent) showToast(body.dataset.toastError, 'error');
            }
        };
        refreshSensors();
        document.querySelector('[data-sensors-refresh]')?.addEventListener('click', () => refreshSensors());

        // The Lista tab otherwise only ever showed a live reading right
        // after a full page load or a manual refresh click — everything in
        // between was frozen. Poll quietly in the background the same way
        // the Wykresy tab already does, pausing while the tab/window isn't
        // visible so it doesn't run forever in a background browser tab.
        // Skipped entirely while a different tab is open: a full sensor
        // poll walks every configured sensor's SNMP/ping and takes a few
        // seconds, and running it every 15s in the background was starving
        // the PHP-FPM worker pool the Wykresy tab's own chart requests
        // needed, which is what made charts appear to go blank.
        const LIST_REFRESH_INTERVAL_MS = 15000;
        let listRefreshTimer = null;
        const startListRefresh = () => {
            if (listRefreshTimer) return;
            listRefreshTimer = setInterval(() => {
                if (sensorGrid.hidden) return;
                refreshSensors(true);
            }, LIST_REFRESH_INTERVAL_MS);
        };
        const stopListRefresh = () => {
            if (listRefreshTimer) clearInterval(listRefreshTimer);
            listRefreshTimer = null;
        };
        startListRefresh();
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) stopListRefresh(); else { if (!sensorGrid.hidden) refreshSensors(true); startListRefresh(); }
        });

        const SENSOR_MODEL_PRESETS = {
            HWG_STE: { model: 'HWg-STE', temperatureOid: '1.3.6.1.4.1.21796.4.1.3.1.4.1', humidityOid: '1.3.6.1.4.1.21796.4.1.3.1.4.2' },
            // Confirmed live against a real STE2 Lite unit — unlike HWg-STE,
            // this model's channel order is temperature=.4.2, humidity=.4.1
            // (the opposite way round; they're different MIB branches, so
            // assuming they'd match was the bug, not a safe inference).
            STE2_LITE: { model: 'STE2 Lite', temperatureOid: '1.3.6.1.4.1.21796.4.9.3.1.4.2', humidityOid: '1.3.6.1.4.1.21796.4.9.3.1.4.1' },
            // Same generic multi-probe sensor table as STE2 Lite, but a full
            // STE2 can carry more than the 2 probes the form has dedicated
            // fields for — confirmed live against a real 4-probe unit
            // (humidity at index 1, temperature at indices 2-4). Only the
            // first temperature probe fits the primary field; the rest seed
            // extra channel rows below.
            STE2: {
                model: 'STE2',
                temperatureOid: '1.3.6.1.4.1.21796.4.9.3.1.4.2',
                humidityOid: '1.3.6.1.4.1.21796.4.9.3.1.4.1',
                extraChannels: [
                    { channel_type: 'temperature', label: 'Temp #2', value_oid: '1.3.6.1.4.1.21796.4.9.3.1.4.3', value_divisor: '1' },
                    { channel_type: 'temperature', label: 'Temp #3', value_oid: '1.3.6.1.4.1.21796.4.9.3.1.4.4', value_divisor: '1' },
                ],
            },
            // HWg-PWR reports dry-contact inputs (grid/generator presence,
            // etc.), not temperature/humidity — clears those OID fields
            // rather than leaving a stale preset from the previous
            // selection. Its inputs aren't configurable from this form yet.
            HWG_PWR: { model: 'HWg-PWR', temperatureOid: '', humidityOid: '' },
        };
        const sensorModelKeyForName = (name) => Object.keys(SENSOR_MODEL_PRESETS).find((key) => SENSOR_MODEL_PRESETS[key].model === name) || 'OTHER';
        const syncSensorModelField = (form) => {
            const select = form?.querySelector('[data-sensor-model-select]');
            if (!select) return;
            const customField = form.querySelector('[data-sensor-model-custom-field]');
            const customInput = form.querySelector('[data-sensor-model-custom]');
            const isOther = select.value === 'OTHER';
            if (customField) customField.hidden = !isOther;
            form.elements.model.value = isOther ? (customInput?.value || '') : (SENSOR_MODEL_PRESETS[select.value]?.model || '');
        };
        // Extra analog channels (a device like STE2 that carries more probes
        // than the form's single temperature/humidity pair): each row is a
        // clone of the shared <template>, kept in sync with a hidden JSON
        // field since the generic submit helper can only carry flat string
        // values, not a nested array.
        const syncChannelsJson = (form) => {
            const jsonField = form?.querySelector('[data-sensor-channels-json]');
            if (!jsonField) return;
            const channels = Array.from(form.querySelectorAll('[data-sensor-channel-row]')).map((row) => ({
                channel_type: row.querySelector('[data-channel-type]').value,
                label: row.querySelector('[data-channel-label]').value.trim(),
                value_oid: row.querySelector('[data-channel-oid]').value.trim(),
                value_divisor: row.querySelector('[data-channel-divisor]').value || '1',
            })).filter((channel) => channel.label && channel.value_oid);
            jsonField.value = JSON.stringify(channels);
        };
        const addChannelRow = (form, values = {}) => {
            const template = document.querySelector('[data-sensor-channel-row-template]');
            const container = form?.querySelector('[data-sensor-channel-rows]');
            if (!template || !container) return;
            const row = template.content.firstElementChild.cloneNode(true);
            row.querySelector('[data-channel-type]').value = values.channel_type || 'temperature';
            row.querySelector('[data-channel-label]').value = values.label || '';
            row.querySelector('[data-channel-oid]').value = values.value_oid || '';
            row.querySelector('[data-channel-divisor]').value = values.value_divisor ?? '1';
            row.querySelector('[data-sensor-channel-remove]').addEventListener('click', () => {
                row.remove();
                syncChannelsJson(form);
            });
            row.querySelectorAll('input, select').forEach((field) => field.addEventListener('input', () => syncChannelsJson(form)));
            container.appendChild(row);
            syncChannelsJson(form);
        };
        const clearChannelRows = (form) => {
            form?.querySelector('[data-sensor-channel-rows]')?.replaceChildren();
            syncChannelsJson(form);
        };
        document.querySelectorAll('[data-sensor-channel-add]').forEach((button) => {
            button.addEventListener('click', () => addChannelRow(button.closest('form')));
        });

        document.querySelectorAll('[data-sensor-model-select]').forEach((select) => {
            const form = select.closest('form');
            select.addEventListener('change', () => {
                const preset = SENSOR_MODEL_PRESETS[select.value];
                if (preset && form) {
                    form.elements.temperature_oid.value = preset.temperatureOid;
                    form.elements.humidity_oid.value = preset.humidityOid;
                    form.elements.temperature_divisor.value = '1';
                    form.elements.humidity_divisor.value = '1';
                    clearChannelRows(form);
                    (preset.extraChannels || []).forEach((channel) => addChannelRow(form, channel));
                }
                syncSensorModelField(form);
            });
        });
        document.querySelectorAll('[data-sensor-model-custom]').forEach((input) => {
            input.addEventListener('input', () => syncSensorModelField(input.closest('form')));
        });

        const setSensorIconPicker = (form, value) => {
            const picker = form?.querySelector('[data-icon-picker]');
            if (!picker) return;
            const hidden = picker.querySelector('input[type="hidden"]');
            if (hidden) hidden.value = value;
            picker.querySelectorAll('[data-icon-value]').forEach((button) => button.classList.toggle('selected', button.dataset.iconValue === value));
        };

        const sensorModal = document.querySelector('#sensor-modal');
        bindModal(sensorModal, '[data-sensor-modal-open]', '[data-sensor-modal-close]', () => {
            const form = sensorModal?.querySelector('[data-sensor-form]');
            if (!form) return;
            form.reset();
            const preset = SENSOR_MODEL_PRESETS.HWG_STE;
            form.elements.temperature_oid.value = preset.temperatureOid;
            form.elements.humidity_oid.value = preset.humidityOid;
            form.elements.temperature_divisor.value = '1';
            form.elements.humidity_divisor.value = '1';
            clearChannelRows(form);
            setSensorIconPicker(form, 'sensor-server');
            syncSensorModelField(form);
        });
        document.querySelector('[data-sensor-form]')?.addEventListener('submit', (event) => {
            event.preventDefault();
            syncChannelsJson(event.currentTarget);
            submitEntityForm(event.currentTarget, '/api/v1/sensors', sensorModal);
        });

        const sensorEditModal = document.querySelector('#sensor-edit-modal');
        bindModal(sensorEditModal, '[data-sensor-edit-open]', '[data-sensor-edit-close]', (button) => {
            const form = sensorEditModal?.querySelector('[data-sensor-edit-form]');
            if (!form) return;
            form.elements.sensor_id.value = button.dataset.sensorId || '';
            form.elements.name.value = button.dataset.sensorName || '';
            const modelName = button.dataset.sensorModel || '';
            const modelKey = sensorModelKeyForName(modelName);
            const modelSelect = form.querySelector('[data-sensor-model-select]');
            if (modelSelect) modelSelect.value = modelKey;
            const modelCustomInput = form.querySelector('[data-sensor-model-custom]');
            if (modelCustomInput) modelCustomInput.value = modelKey === 'OTHER' ? modelName : '';
            syncSensorModelField(form);
            setSensorIconPicker(form, button.dataset.sensorIcon || 'sensor-server');
            form.elements.host.value = button.dataset.sensorHost || '';
            form.elements.snmp_port.value = button.dataset.sensorPort || '161';
            form.elements.snmp_community.value = button.dataset.sensorCommunity || 'public';
            form.elements.temperature_oid.value = button.dataset.sensorTemperatureOid || '';
            form.elements.temperature_divisor.value = button.dataset.sensorTemperatureDivisor || '10';
            form.elements.humidity_oid.value = button.dataset.sensorHumidityOid || '';
            form.elements.humidity_divisor.value = button.dataset.sensorHumidityDivisor || '10';
            form.elements.temperature_min.value = button.dataset.sensorTemperatureMin || '';
            form.elements.temperature_max.value = button.dataset.sensorTemperatureMax || '';
            form.elements.humidity_min.value = button.dataset.sensorHumidityMin || '';
            form.elements.humidity_max.value = button.dataset.sensorHumidityMax || '';
            form.elements.ping_enabled.checked = button.dataset.sensorPingEnabled === '1';
            form.elements.monitoring_enabled.checked = button.dataset.sensorMonitoringEnabled === '1';
            form.elements.notes.value = button.dataset.sensorNotes || '';
            clearChannelRows(form);
            try {
                const channels = JSON.parse(button.dataset.sensorChannels || '[]');
                channels.forEach((channel) => addChannelRow(form, channel));
            } catch (error) {
                // malformed/missing channel data — leave the editor empty
            }
        });
        document.querySelector('[data-sensor-edit-form]')?.addEventListener('submit', (event) => {
            event.preventDefault();
            const form = event.currentTarget;
            syncChannelsJson(form);
            submitEntityForm(form, `/api/v1/sensors/${form.elements.sensor_id.value}`, sensorEditModal);
        });

        const sensorCharts = document.querySelector('[data-sensor-charts]');
        let chartsController = null;
        const SENSOR_TAB_STORAGE_KEY = 'nstructure-sensor-tab';
        const activateSensorTab = (target) => {
            const tabButton = document.querySelector(`[data-sensor-tab="${target}"]`);
            if (!tabButton) return;
            document.querySelectorAll('[data-sensor-tab]').forEach((btn) => btn.classList.toggle('active', btn === tabButton));
            document.querySelectorAll('[data-sensor-panel]').forEach((panel) => {
                panel.hidden = panel.dataset.sensorPanel !== target;
            });
            if (target === 'charts') {
                if (!chartsController) chartsController = initSensorCharts(sensorCharts);
                chartsController?.resume();
            } else {
                chartsController?.pause();
            }
            try {
                localStorage.setItem(SENSOR_TAB_STORAGE_KEY, target);
            } catch (error) {
                // storage unavailable (private browsing, quota) — tab just won't persist
            }
        };
        document.querySelectorAll('[data-sensor-tab]').forEach((tabButton) => {
            tabButton.addEventListener('click', () => activateSensorTab(tabButton.dataset.sensorTab));
        });
        // Every save in this page reloads the whole page (the shared submit
        // helper's pattern), which used to always land back on "Lista" —
        // restore whichever tab was active before the reload instead.
        let savedSensorTab = null;
        try {
            savedSensorTab = localStorage.getItem(SENSOR_TAB_STORAGE_KEY);
        } catch (error) {
            savedSensorTab = null;
        }
        if (savedSensorTab && document.querySelector(`[data-sensor-tab="${savedSensorTab}"]`)) {
            activateSensorTab(savedSensorTab);
        }
        const sensorInputsModal = document.querySelector('#sensor-inputs-modal');
        document.querySelectorAll('[data-sensor-inputs-close]').forEach((button) => button.addEventListener('click', () => sensorInputsModal?.close()));
        sensorInputsModal?.addEventListener('click', (event) => {
            if (event.target === sensorInputsModal) sensorInputsModal.close();
        });
        const renderSensorInputs = (inputs) => {
            const body = sensorInputsModal?.querySelector('[data-sensor-inputs-body]');
            if (!body) return;
            const labels = sensorInputsModal.dataset;
            body.replaceChildren();
            inputs.forEach((input) => {
                const row = document.createElement('div');
                row.className = 'sensor-inputs-row';
                const label = document.createElement('span');
                label.className = 'sensor-inputs-row-label';
                label.textContent = input.label;
                const status = document.createElement('span');
                const state = input.last_alarm_state;
                status.className = 'sensor-inputs-row-status ' + (state === 2 ? 'alarm' : state === 1 ? 'ok' : 'unknown');
                status.textContent = state === 2 ? labels.inputAlarmLabel : state === 1 ? labels.inputOkLabel : labels.inputUnknownLabel;
                row.append(label, status);
                body.appendChild(row);
            });
        };
        const openSensorInputsModal = async (sensorId, sensorName) => {
            if (!sensorInputsModal) return;
            const nameEl = sensorInputsModal.querySelector('[data-sensor-inputs-name]');
            if (nameEl) nameEl.textContent = sensorName;
            renderSensorInputs([]);
            sensorInputsModal.showModal();
            try {
                const response = await fetch(`/api/v1/sensors/${sensorId}/poll`, { headers: { Accept: 'application/json' } });
                const payload = await response.json();
                renderSensorInputs(payload.data?.inputs || []);
                applySensorReadings(payload.data);
            } catch (error) {
                // modal stays open with an empty list; nothing more useful to show
            }
        };

        document.querySelectorAll('[data-sensor-card]').forEach((card) => {
            card.addEventListener('click', (event) => {
                if (event.target.closest('.sensor-tile-actions')) return;
                if (card.dataset.sensorHasInputs === '1') {
                    openSensorInputsModal(card.dataset.sensorId, card.querySelector('.sensor-tile-name')?.textContent || '');
                    return;
                }
                document.querySelector('[data-sensor-tab="charts"]')?.click();
                if (chartsController) chartsController.selectSensor(card.dataset.sensorId);
            });
        });

        // Per-user tile layout: drag to reorder, a resize button to cycle
        // small/medium/large. Persisted server-side (not localStorage) so
        // each account keeps its own arrangement across devices.
        const SENSOR_TILE_SIZES = ['small', 'medium', 'large'];
        const persistSensorLayout = () => {
            const cards = Array.from(sensorGrid.querySelectorAll('[data-sensor-card]'));
            const order = cards.map((card) => card.dataset.sensorId);
            const sizes = {};
            cards.forEach((card) => { sizes[card.dataset.sensorId] = card.dataset.size || 'medium'; });
            fetch('/api/v1/sensors/layout', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify({ order, sizes }),
            }).catch(() => {});
        };

        let draggedSensorId = null;
        document.querySelectorAll('[data-sensor-card]').forEach((card) => {
            card.addEventListener('dragstart', () => {
                draggedSensorId = card.dataset.sensorId;
                card.classList.add('dragging');
            });
            card.addEventListener('dragend', () => {
                card.classList.remove('dragging');
                sensorGrid.querySelectorAll('.drag-over').forEach((el) => el.classList.remove('drag-over'));
                draggedSensorId = null;
            });
            card.addEventListener('dragover', (event) => {
                if (!draggedSensorId || card.dataset.sensorId === draggedSensorId) return;
                event.preventDefault();
                card.classList.add('drag-over');
            });
            card.addEventListener('dragleave', () => card.classList.remove('drag-over'));
            card.addEventListener('drop', (event) => {
                event.preventDefault();
                card.classList.remove('drag-over');
                if (!draggedSensorId || card.dataset.sensorId === draggedSensorId) return;
                const draggedCard = sensorGrid.querySelector(`[data-sensor-card][data-sensor-id="${draggedSensorId}"]`);
                if (!draggedCard) return;
                card.before(draggedCard);
                persistSensorLayout();
            });
        });

        document.querySelectorAll('[data-sensor-resize]').forEach((button) => {
            button.addEventListener('click', () => {
                const card = button.closest('[data-sensor-card]');
                if (!card) return;
                const current = card.dataset.size || 'medium';
                const next = SENSOR_TILE_SIZES[(SENSOR_TILE_SIZES.indexOf(current) + 1) % SENSOR_TILE_SIZES.length];
                card.dataset.size = next;
                SENSOR_TILE_SIZES.forEach((size) => card.classList.toggle(`size-${size}`, size === next));
                persistSensorLayout();
            });
        });

        // Alerty tab: recipients/groups/settings all reuse the same
        // submit-and-reload helper as the rest of the app; only the
        // checkbox-picker "Save" buttons (group membership, per-sensor
        // targets) need a bespoke handler since they're not <form> submits.
        document.querySelector('[data-alert-settings-form]')?.addEventListener('submit', (event) => {
            event.preventDefault();
            submitEntityForm(event.currentTarget, '/api/v1/alerts/settings', null);
        });
        document.querySelector('[data-alert-recipient-form]')?.addEventListener('submit', (event) => {
            event.preventDefault();
            submitEntityForm(event.currentTarget, '/api/v1/alerts/recipients', null);
        });
        document.querySelector('[data-alert-group-form]')?.addEventListener('submit', (event) => {
            event.preventDefault();
            submitEntityForm(event.currentTarget, '/api/v1/alerts/groups', null);
        });
        document.querySelector('[data-alert-test-form]')?.addEventListener('submit', async (event) => {
            event.preventDefault();
            const form = event.currentTarget;
            const result = document.querySelector('[data-alert-test-result]');
            const button = form.querySelector('button[type="submit"]');
            button.disabled = true;
            try {
                const response = await fetch('/api/v1/alerts/test-email', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify(Object.fromEntries(new FormData(form).entries())),
                });
                const payload = await response.json();
                if (result) {
                    result.hidden = false;
                    result.textContent = response.ok ? (body.dataset.toastSaved || 'OK') : (payload.error || body.dataset.toastError);
                }
            } catch (error) {
                if (result) { result.hidden = false; result.textContent = body.dataset.toastError; }
            } finally {
                button.disabled = false;
            }
        });
        document.querySelectorAll('[data-alert-group-save-members]').forEach((button) => {
            button.addEventListener('click', async () => {
                const row = button.closest('[data-group-id]');
                if (!row) return;
                const recipientIds = Array.from(row.querySelectorAll('[data-group-member-checkbox]:checked')).map((input) => input.value);
                button.disabled = true;
                try {
                    const response = await fetch(`/api/v1/alerts/groups/${row.dataset.groupId}/members`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrfToken },
                        body: JSON.stringify({ recipient_ids: recipientIds }),
                    });
                    if (!response.ok) throw new Error();
                    showToast(body.dataset.toastSaved);
                } catch (error) {
                    showToast(body.dataset.toastError, 'error');
                } finally {
                    button.disabled = false;
                }
            });
        });
        document.querySelectorAll('[data-alert-sensor-save]').forEach((button) => {
            button.addEventListener('click', async () => {
                const row = button.closest('[data-sensor-id]');
                if (!row) return;
                const recipientIds = Array.from(row.querySelectorAll('[data-target-recipient]:checked')).map((input) => input.value);
                const groupIds = Array.from(row.querySelectorAll('[data-target-group]:checked')).map((input) => input.value);
                button.disabled = true;
                try {
                    const response = await fetch(`/api/v1/sensors/${row.dataset.sensorId}/alert-targets`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrfToken },
                        body: JSON.stringify({ recipient_ids: recipientIds, group_ids: groupIds }),
                    });
                    if (!response.ok) throw new Error();
                    showToast(body.dataset.toastSaved);
                } catch (error) {
                    showToast(body.dataset.toastError, 'error');
                } finally {
                    button.disabled = false;
                }
            });
        });

        window.addEventListener('beforeunload', () => chartsController?.pause());
    }

    function initSensorCharts(container) {
        if (!window.echarts) return null;
        const sensorSelect = container.querySelector('[data-chart-sensor-select]');
        const rangeButtons = container.querySelectorAll('[data-chart-range]');
        const warning = container.querySelector('[data-vm-warning]');
        // channelType links a chart to the extra analog channels (see
        // SENSOR_MODEL_PRESETS.STE2) that share its unit — a sensor with
        // several temperature probes gets one extra line per probe on the
        // temperature chart instead of a separate chart each. ping_latency
        // has no channelType since only temperature/humidity channels exist.
        const chartConfigs = [
            { key: 'temperature', metric: 'temperature', unit: ' °C', color: '#3b82f6', channelType: 'temperature', label: container.dataset.chartTemperatureLabel || 'Temperature' },
            { key: 'humidity', metric: 'humidity', unit: ' %', color: '#14b8a6', channelType: 'humidity', label: container.dataset.chartHumidityLabel || 'Humidity' },
            { key: 'ping_latency', metric: 'ping_latency', unit: ' ms', color: '#a855f7', channelType: null, label: container.dataset.chartPingLatencyLabel || 'Ping latency' },
        ];
        const CHANNEL_COLORS = ['#f97316', '#22c55e', '#eab308', '#ec4899', '#06b6d4'];
        // Chart chrome (axes, toolbox, dataZoom/slider) is set up exactly
        // once per instance. Every later update only touches series data via
        // updateInstanceData() — repeating the full option on every refresh
        // was resetting the toolbox's zoom-select toggle and any in-progress
        // drag before it could be used, which is why the toolbox icons
        // looked like they did nothing.
        const instances = chartConfigs.map((config) => {
            const element = container.querySelector(`[data-chart="${config.key}"]`);
            if (!element) return null;
            const chart = window.echarts.init(element);
            chart.setOption({
                grid: { left: 48, right: 16, top: 16, bottom: 56 },
                tooltip: { trigger: 'axis', valueFormatter: (value) => `${Number(value).toFixed(1)}${config.unit}` },
                legend: { show: false },
                xAxis: { type: 'time' },
                yAxis: { type: 'value', axisLabel: { formatter: `{value}${config.unit}` } },
                dataZoom: [
                    { type: 'inside', xAxisIndex: 0 },
                    { type: 'slider', xAxisIndex: 0, height: 18, bottom: 4 },
                ],
                series: [],
            });
            return { config, chart, series: [], state: {} };
        }).filter(Boolean);
        if (!instances.length) return null;

        // Builds the list of lines a chart instance should show for the
        // currently selected sensor: its own primary reading plus one line
        // per extra channel of the matching type (embedded as JSON on the
        // selected <option> — see sensors.twig).
        const buildSeriesList = (instance) => {
            const list = [{ key: 'primary', metric: instance.config.metric, label: instance.config.label, color: instance.config.color }];
            const sensorOption = sensorSelect?.selectedOptions?.[0];
            if (instance.config.channelType && sensorOption) {
                try {
                    const channels = JSON.parse(sensorOption.dataset.channels || '[]');
                    channels
                        .filter((channel) => channel.channel_type === instance.config.channelType)
                        .forEach((channel, index) => {
                            list.push({ key: `channel:${channel.id}`, metric: 'channel', channelId: channel.id, label: channel.label, color: CHANNEL_COLORS[index % CHANNEL_COLORS.length] });
                        });
                } catch (error) {
                    // malformed/missing channel data — fall back to just the primary line
                }
            }
            return list;
        };

        // `replace` fully swaps the series list (needed when the count itself
        // changes, e.g. switching to a sensor with a different number of
        // channels) rather than merging by index. Every *routine* refresh
        // tick must use a plain merge instead — replaceMerge removes and
        // re-adds each series, which made the whole chart visibly flash on
        // every 1-2s poll even though only the data had changed.
        const updateInstanceData = (instance, { replace = false } = {}) => {
            const seriesOption = instance.series.map((s) => ({
                type: 'line',
                name: s.label,
                showSymbol: false,
                smooth: true,
                areaStyle: instance.series.length === 1 ? { opacity: 0.08 } : undefined,
                lineStyle: { color: s.color, width: 2 },
                itemStyle: { color: s.color },
                data: instance.state[s.key]?.data || [],
            }));
            instance.chart.setOption({ series: seriesOption }, replace ? { replaceMerge: ['series'] } : undefined);
        };

        // Rebuilds the series list (and resets its pending data) for the
        // currently selected sensor — called on every sensor change so a
        // switch from a multi-probe sensor to a single-probe one doesn't
        // leave stale extra lines behind. Deliberately does NOT touch the
        // chart's visible series yet: doing that here cleared the chart to
        // empty immediately, then left it empty for however long the
        // history fetch that follows took — normally instant, but visibly
        // "the chart just disappeared" whenever the server was briefly busy
        // (e.g. a full sensor poll from the refresh button). The old data
        // now stays on screen until the new data actually arrives.
        const rebuildSeries = (instance) => {
            const list = buildSeriesList(instance);
            instance.series = list;
            instance.state = {};
            list.forEach((s) => { instance.state[s.key] = { data: [], lastTimestampMs: 0 }; });
            instance.chart.setOption({
                grid: { top: list.length > 1 ? 34 : 16 },
                legend: list.length > 1 ? { show: true, top: 4, itemWidth: 14, itemHeight: 8, textStyle: { fontSize: 11 } } : { show: false },
            });
        };

        const resizeObserver = new ResizeObserver(() => instances.forEach(({ chart }) => chart.resize()));
        resizeObserver.observe(container);

        // A plain button instead of ECharts' own toolbox dataZoom/restore
        // icons — those turned out unreliable here (the rectangle-select
        // zoom tool and its "back" arrow track their own history separate
        // from the slider/wheel zoom, so clicking them often did nothing
        // visible). This always resets whatever zoom state is active,
        // regardless of how it was applied.
        container.querySelectorAll('[data-chart-reset]').forEach((button) => {
            const instance = instances.find((candidate) => candidate.config.key === button.dataset.chartReset);
            if (!instance) return;
            button.addEventListener('click', () => {
                instance.chart.dispatchAction({ type: 'dataZoom', start: 0, end: 100 });
            });
        });

        let currentRange = '24h';
        let heartbeatTimer = null;
        let refreshTimer = null;
        const HEARTBEAT_INTERVAL_MS = 5000;
        const LIVE_RANGE = '5m';
        const LIVE_REFRESH_INTERVAL_MS = 1000;
        const REFRESH_INTERVAL_MS = 2000;
        const currentRefreshIntervalMs = () => (currentRange === LIVE_RANGE ? LIVE_REFRESH_INTERVAL_MS : REFRESH_INTERVAL_MS);

        const historyUrl = (sensorId, s, extra = '') =>
            s.metric === 'channel'
                ? `/api/v1/sensors/${sensorId}/history?metric=channel&channel_id=${s.channelId}&range=${currentRange}${extra}`
                : `/api/v1/sensors/${sensorId}/history?metric=${s.metric}&range=${currentRange}${extra}`;

        const loadFull = async () => {
            const sensorId = sensorSelect?.value;
            if (!sensorId) return;
            instances.forEach((instance) => rebuildSeries(instance));
            let anyFailed = false;
            await Promise.all(instances.flatMap((instance) => instance.series.map(async (s) => {
                try {
                    const response = await fetch(historyUrl(sensorId, s), { headers: { Accept: 'application/json' } });
                    const payload = await response.json();
                    const points = payload.data || [];
                    instance.state[s.key].data = points.map((point) => [point.timestampMs, point.value]);
                    instance.state[s.key].lastTimestampMs = points.length ? points[points.length - 1].timestampMs : 0;
                } catch (error) {
                    anyFailed = true;
                }
            })));
            instances.forEach((instance) => {
                // replace: true here is what actually swaps the chart over
                // to the new sensor/series — the single point where the
                // previously-displayed data gets replaced, now that it's
                // backed by real (already-fetched) data instead of an
                // empty placeholder.
                updateInstanceData(instance, { replace: true });
                // A real navigation (new sensor/range) should reset any
                // leftover zoom from before — dispatchAction here so the
                // dataZoom/toolbox component definitions never get
                // re-specified outside chart setup.
                instance.chart.dispatchAction({ type: 'dataZoom', start: 0, end: 100 });
            });
            if (warning) warning.hidden = !anyFailed;
        };

        const rangeWindowMs = () => {
            const seconds = { '5m': 300, '1h': 3600, '6h': 21600, '24h': 86400, '7d': 604800, '30d': 2592000 }[currentRange] || 86400;
            return seconds * 1000;
        };

        const loadIncremental = async () => {
            const sensorId = sensorSelect?.value;
            if (!sensorId) return;
            const cutoff = Date.now() - rangeWindowMs();
            await Promise.all(instances.flatMap((instance) => instance.series.map(async (s) => {
                const seriesState = instance.state[s.key];
                // No prior data (e.g. this series had nothing yet on the last full
                // load) — re-fetch the whole range instead of skipping forever, so
                // a chart that starts empty self-heals once data shows up.
                const hasBaseline = Boolean(seriesState.lastTimestampMs);
                const url = historyUrl(sensorId, s, hasBaseline ? `&since=${seriesState.lastTimestampMs}` : '');
                try {
                    const response = await fetch(url, { headers: { Accept: 'application/json' } });
                    const payload = await response.json();
                    const points = hasBaseline
                        ? (payload.data || []).filter((point) => point.timestampMs > seriesState.lastTimestampMs)
                        : (payload.data || []);
                    if (!points.length) return;
                    seriesState.data = (hasBaseline ? seriesState.data.concat(points.map((point) => [point.timestampMs, point.value])) : points.map((point) => [point.timestampMs, point.value]))
                        .filter((pair) => pair[0] >= cutoff);
                    seriesState.lastTimestampMs = points[points.length - 1].timestampMs;
                } catch (error) {
                    // keep showing the last known data; the warning banner covers VM outages
                }
            })));
            instances.forEach((instance) => updateInstanceData(instance));
        };

        const sendHeartbeat = () => {
            const sensorId = sensorSelect?.value;
            if (!sensorId) return;
            fetch('/api/v1/sensors/heartbeat', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify({ sensor_id: sensorId }),
            }).catch(() => {});
        };

        const stopTimers = () => {
            if (heartbeatTimer) clearInterval(heartbeatTimer);
            if (refreshTimer) clearInterval(refreshTimer);
            heartbeatTimer = null;
            refreshTimer = null;
        };
        const startTimers = () => {
            stopTimers();
            sendHeartbeat();
            heartbeatTimer = setInterval(sendHeartbeat, HEARTBEAT_INTERVAL_MS);
            refreshTimer = setInterval(loadIncremental, currentRefreshIntervalMs());
        };

        sensorSelect?.addEventListener('change', () => { loadFull(); startTimers(); });
        rangeButtons.forEach((button) => button.addEventListener('click', () => {
            rangeButtons.forEach((btn) => btn.classList.toggle('active', btn === button));
            currentRange = button.dataset.chartRange;
            loadFull();
            startTimers();
        }));
        document.addEventListener('visibilitychange', () => {
            if (container.hidden) return;
            if (document.hidden) stopTimers(); else startTimers();
        });

        const refreshVmStatus = () => {
            fetch('/api/v1/sensors/metrics-status', { headers: { Accept: 'application/json' } })
                .then((response) => response.json())
                .then((payload) => { if (warning) warning.hidden = payload?.data?.reachable !== false; })
                .catch(() => {});
        };

        return {
            resume() {
                refreshVmStatus();
                if (!instances[0].state.primary?.data.length) loadFull();
                startTimers();
            },
            pause() {
                stopTimers();
            },
            selectSensor(sensorId) {
                if (!sensorSelect || sensorSelect.value === sensorId) return;
                sensorSelect.value = sensorId;
                loadFull();
                startTimers();
            },
        };
    }

    updateThemeIcon();
    refreshIcons();
    initializeVisualizations();
    window.HSStaticMethods?.autoInit?.();
})();
